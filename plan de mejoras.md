# Plan de mejoras — puntos 8 a 15

> Estado: **propuesta, sin código escrito**. Todo lo que sigue está contrastado
> contra la base de datos y el código a 20-ago-2026.
>
> Descartados por decisión del cliente: **9** (equipos sin registrar → se queda
> en el admin legacy) y **13** (plantillas de alta de vehículo → no viable, la
> copia de sensores ya cubre la necesidad).

---

## Resumen y orden propuesto

| # | Mejora | Esfuerzo | Bloqueos | Orden |
| - | ------ | -------- | -------- | ----- |
| 14 | Plantillas de alerta y aplicación masiva | Medio | — | **1º** |
| 11 | Panel de cumplimiento regulatorio | Medio | — | **2º** |
| 15 | Cabecera y pie de los reportes | Bajo | — | **3º** |
| 8 | Ampliar el registro de cambios | Bajo | — | **4º** |
| 12 | Puntuación de conducción | Medio | Ver §12 | **5º** |
| 10 | Costo por kilómetro | Medio | 1 pregunta abierta | **6º** |

Antes de todo eso va el **arreglo de WiseTrack** (índice + purga de fallidos),
aprobado y descrito en §11: es un incidente vivo, no una mejora.

El orden va por relación valor/riesgo, no por número.

---

## 14 · Plantillas de alerta y aplicación masiva

### Lo que hay hoy

| Dato | Valor |
| ---- | ----- |
| Alertas en la base | 8.614 |
| Usuarios con alertas | 773 |
| Alertas por usuario (media) | ~11 |

Los seis nombres más repetidos:

| Nombre | Veces |
| ------ | ----- |
| Vehículo apagado | 807 |
| Exceso de velocidad | 796 |
| Vehículo encendido | 785 |
| Giro Brusco | 772 |
| GNSS Jamming | 770 |
| Jamming | 769 |

Son ~4.700 alertas que repiten media docena de plantillas, creadas a mano
entrando como cada cliente. Es el punto con más retorno de los seis.

### Qué se construye

1. **Catálogo de plantillas de alerta** (tabla nueva `alert_templates`): mismo
   contenido que una alerta —tipo, parámetros, canales, horarios— pero sin
   dueño ni vehículos.
2. **Aplicar plantilla a**: un usuario, varios usuarios, o todos los de un plan.
   Crea la alerta en cada destino y la asigna a sus vehículos según la regla
   elegida (todos / grupo / ninguno).
3. **Alertas por defecto del plan**: al crear un cliente nuevo, se le siembran
   las plantillas marcadas como predeterminadas de su plan.
4. **Pantalla de administración**: qué plantillas existen, a cuántos usuarios se
   han aplicado y cuáles están desactualizadas respecto a la plantilla.

### "Todos los vehículos" ya existe — y funciona

Lo has pedido y resulta que la pieza está: `alerts.for_all_user_devices`.

No es una marca que se mire al evaluar, sino un **sincronizador**: cuando se
asigna o se quita un vehículo a un usuario, `UserDevicesAlertsDevicesSync`
inserta o borra las filas de `alert_device` de todas sus alertas marcadas con esa
bandera. O sea: **un vehículo nuevo entra solo en esas alertas**, que es
exactamente lo que necesitas.

Hoy la usan **44 alertas de 8.614**. Las plantillas deberían crear las alertas
con esta bandera activada por defecto.

### Eventos personalizados en las plantillas

Una alerta de tipo `custom` apunta a filas de `events_custom`, que son **por
usuario y por protocolo**. Al aplicar la plantilla a un cliente hay que resolver
a qué evento personalizado suyo se engancha. Tres casos:

1. **Ya existe en ese usuario** (mismo protocolo y condiciones): se reutiliza.
2. **No existe**: se crea a partir del que trae la plantilla, **una variante por
   cada protocolo que los vehículos de ese usuario estén reportando** (ver el
   análisis del protocolo en §12).
3. **Existe con otro nombre**: se reutiliza y se avisa, sin renombrárselo.

Y para el caso que te preocupaba —un cliente añade mañana un equipo de un
protocolo que no tenía—: al reaplicar la plantilla, o mediante un trabajo
periódico que compare protocolos presentes contra variantes creadas, se le añade
la que falte.

Esto se apoya en lo del punto 12: si los eventos de conducción brusca pasan a ser
**de sistema y sembrados a todos**, la mayoría de plantillas caen en el caso 1 y
el problema casi desaparece.

### Decisiones abiertas

- **¿Vínculo vivo o copia?** Si el cliente edita "su" alerta, ¿se desliga de la
  plantilla o se sobreescribe al reaplicar? Propongo **copia con marca de
  origen**: se sabe de qué plantilla salió y se avisa si difiere, pero el cliente
  manda sobre lo suyo.
- **¿Qué pasa al reaplicar?** Propongo no duplicar: si ya existe una alerta de
  esa plantilla, se actualiza; si el cliente la borró, no se recrea salvo que se
  pida.

### Riesgos

Aplicar a 773 usuarios crea miles de filas en `alerts` y `alert_device`: va en
cola por lotes, no en la petición web. Y necesita vista previa —"esto va a crear
N alertas en M usuarios y tocar K vehículos"— antes de ejecutar.

---

## 11 · Panel de cumplimiento regulatorio

### Lo que hay hoy

Las tablas de integración **son colas de salida, no historiales**: no tienen
campo de estado y las filas se borran al enviarse.

| Tabla | Filas | Primer id | Estado |
| ----- | ----- | --------- | ------ |
| `wisetrack` | 694.191 | 1 | **no drena** |
| `sidersales` | 33.335 | 312.408 | drena |
| `sutran` | 1.112 | 2.040.203 | drena |
| `osinergmin` | 76 | — | drena |
| `comsatel`, `mininter` | 0 | — | sin uso |

**Hallazgo operativo, independiente del panel:** de los 3.984 trabajos fallidos,
**3.813 son `SendDataWisetrackJob`**, entre el 11-may y el 22-jun. Por eso la
cola de WiseTrack arranca en el id 1 y ha crecido a 694.191 filas: los envíos
fallan y nadie se enteró en seis semanas.

**Aprobado, va primero:**

1. **Migración** que añade el índice de `device_id` a `wisetrack`. Ojo: crear un
   índice sobre 694k filas bloquea la tabla un momento; conviene hacerlo en hora
   valle y avisar. En producción se aplica con `php artisan migrate --force`.
2. **Purga de los trabajos fallidos** ya resueltos. Se hace con un comando, no a
   mano, para poder repetirlo y dejar constancia de cuántos se borraron.

Antes de purgar hay que mirar **por qué** fallan: si la causa sigue viva, la cola
volverá a crecer. Borrar los fallidos sin diagnosticar solo esconde el problema.

### Qué se construye

Como no hay campo de estado, la señal de cumplimiento se deriva de:

- **Atraso de cola** por vehículo: filas pendientes y antigüedad de la más vieja.
- **Última fila enviada** por vehículo e integración.
- **Fallos recientes** por integración, leídos de `jobs_failed`.

La pantalla: una fila por vehículo obligado, una columna por integración
activa, semáforo verde/ámbar/rojo, y filtro "solo los que fallan".

### Sobre el rendimiento — comprobado

Sí se puede sin tocar el envío, **con dos condiciones**.

**1. Falta un índice, y justo en la tabla grande.** Comprobado tabla por tabla:

| Tabla | Índice en `device_id` |
| ----- | --------------------- |
| `wisetrack` (694k filas) | **NO — solo PRIMARY(id)** |
| `sidersales` | sí |
| `sutran` | sí |
| `osinergmin` | sí |
| `comsatel` | sí |
| `mininter` | sí (y además índice en `status`) |

Un `COUNT(*) ... WHERE device_id = ?` sobre `wisetrack` hoy recorre 694.191
filas. Crear ese índice es el primer paso, y probablemente también alivie al
propio job de envío.

Dato aparte: **`mininter` sí tiene columna `status`**, al contrario que las
demás. Para esa integración se puede saber el estado real de cada envío, no solo
inferirlo del atraso de la cola.

**2. Nada de consultar en vivo.** Las agregaciones no se hacen al abrir la
pantalla: un comando cada 5–10 minutos las calcula y guarda un resumen, y el
panel lee el resumen. El panel solo lee: no toca la cola ni compite por bloqueos
con los jobs de envío.

---

## 15 · Cabecera y pie de los reportes

### Lo que hay hoy

`Reports/partials/layout.blade.php`:

- **HTML**: cabecera con logo y una curva decorativa.
- **PDF y demás**: `<img class="logo">` a pelo. Sin nombre de empresa, sin título
  del reporte, sin periodo, sin numeración de página, sin fecha de generación.

El logo sale de `Appearance` (marca blanca), así que ya es por cliente.

### Qué se construye

Una banda de cabecera común a todos los formatos no-Excel: logo + nombre de
empresa + título del reporte + periodo + vehículo(s). Y un pie con número de
página y fecha de generación.

### Riesgo a tener en cuenta

La hoja de estilos es `public/assets/css/report.css`, compilada desde el SCSS
**del tema legacy**. La comparten las dos UIs: mejorarla mejora los dos lados,
pero hay que recompilar con `gulp templates` y revisar que no se rompa ningún
reporte antiguo. Los ~111 blades de reporte heredan de este layout, así que el
cambio es en un sitio pero se ve en todos.

---

## 8 · Ampliar el registro de cambios

### Lo que hay hoy

256.971 registros en `model_change_logs`, con índice compuesto por
`(subject_id, subject_type)` — consultar el historial de una entidad concreta es
inmediato.

| Entidad | Registros |
| ------- | --------- |
| user | 242.430 |
| device | 7.600 |
| Alert | 5.886 |
| Geofence | 1.197 |
| DeviceService | 97 |
| Report | 76 |
| Poi / Route / Task | 32 |

**El 94 % es ruido**: casi todo son guardados de preferencias del usuario
(widgets, ajustes de mapa), no cambios que a nadie le interese auditar.

### Respuesta a tu pregunta

**Sí, se puede llevar a la v2 sin problemas.** El índice compuesto por entidad ya
está, así que pedir "el historial de ESTE vehículo" es una consulta directa, no
un barrido de las 256.971 filas.

Se hacen las dos cosas: **botón "Historial de cambios"** en el vehículo (kebab
del mapa y pestaña del modal) **y** ampliar lo que se registra.

### Entidades que HOY no registran nada

`DeviceSensor`, `UserDriver`, `DeviceMaintenance`, `Sharing`,
`UserGprsTemplate`, `CallAction`, `ChecklistTemplate`, `DeviceGroup`,
`VehicleType`, `Client`.

La más importante es **`DeviceSensor`**: acabamos de dar la copia masiva entre
vehículos, que puede cambiar la configuración de decenas de equipos de una vez,
y no queda rastro de quién lo hizo.

### Qué se construye

1. **Botón "Historial de cambios" en el vehículo**: quién tocó qué y cuándo, con
   el antes y el después de cada campo. Lee `model_change_logs` filtrado por ese
   vehículo (y por sus sensores y servicios, ver punto 2). Solo lectura, y tras
   el permiso que corresponda.
2. **Ampliar la cobertura**: añadir el trait a `DeviceSensor`,
   `DeviceMaintenance`, `Sharing` y `DeviceGroup`, que hoy no dejan rastro.
3. **Recortar el ruido de `user`**: dejar de registrar los guardados de
   preferencias, que son el 94 % del volumen. Sin esto, el historial sigue siendo
   inutilizable aunque se amplíe.
4. Registrar la copia de sensores como **una** entrada con su alcance ("13
   sensores copiados a 8 vehículos"), no 104 entradas sueltas.

### Decisión abierta

**Qué entra en el historial del vehículo.** Lo natural es que incluya lo que le
pasa a sus partes —sensores, servicios, mantenimientos—, no solo los cambios de
la ficha. Eso obliga a resolver los ids de esas entidades por vehículo; es
sencillo, pero conviene decidirlo antes de empezar.

---

## 12 · Puntuación de conducción

### Cómo se registran hoy — y por qué conviene cambiarlo

Tenías razón: **hoy es por el nombre del evento**. No hay un tipo propio; son
eventos `custom` que se distinguen por su texto:

| Mensaje | Eventos |
| ------- | ------- |
| Giro Brusco | 64.495 |
| Aceleración Brusca | 7.221 |
| Frenado Brusco | 6.770 |
| Detección de choque | 678 |
| Exceso de velocidad Sutran | 39.308 |
| Botón de Pánico Activo | 23.085 |

Pero puntuar por texto es frágil: son **29 mensajes distintos definidos por 23
usuarios**, cada uno con su redacción.

### Tu propuesta encaja con cómo funciona el motor

Revisado `EventCustomAlertCheck`: un evento personalizado compara
`position.parameters[tag]` contra un valor. La etiqueta **`alarm` ya se usa** en
5 eventos personalizados existentes. Así que los valores que manda el decoder
—`hardAcceleration`, `hardBraking`, `hardCornering`, `highRpm`— se pueden
detectar directamente, sin depender de cómo lo haya llamado cada cliente.

**Con una salvedad importante:** el motor exige que coincida el protocolo
(`$eventCustom->protocol != $position->protocol` → descarta). Los eventos
personalizados de hoy solo cubren tres protocolos (teltonika, gt06, gps103) y la
flota usa cinco.

### El problema del protocolo — analizado

Propusiste definir las plantillas **por modelo de equipo** en vez de por
protocolo. Miré si el modelo permite deducir el protocolo, y la respuesta corta
es que **casi, pero no de forma fiable**:

- 28 modelos distintos; 6 aparecen con más de un protocolo. Casi siempre es un
  error de captura (`FMC920` sale 801 veces como teltonika y una vez suelta como
  tk103, gps103 y vacío).
- El campo `devices.device_model` es **texto libre**, y se nota: `FMC920`,
  `FMC-920`, `FMB920`, `FMB-920`, `F,B920` son la misma familia escrita de cinco
  formas. 26 valores distintos para ~6 familias reales.
- `device_type_id` sí es un catálogo de verdad (12 tipos, 1.459 de 1.530 equipos
  asignados), pero **un tipo abarca varios protocolos**: el tipo *FMX920* cubre
  teltonika, tk103, gps103, android y gt06.

O sea: ni el modelo en texto libre ni el tipo dan un protocolo fiable.

**Y hay una tabla que ya existe para esto y está vacía:**

```
device_models: id, active, title, protocol, model      -- 0 filas
```

Es exactamente el catálogo modelo → protocolo que hace falta, y está sin
estrenar.

### Las alternativas, y cuál recomiendo

Tienes razón en que `device_type` no pide protocolo: su formulario solo tiene
activo, título, grupo de sensor y logo. Y el grupo de sensor tampoco sirve —
`sensor_groups` es solo `id, title, count`; el protocolo está únicamente dentro
del texto del título ("TELTONIKA - FMC920 - CORTE"). Adivinar el protocolo
parseando un nombre es justo el tipo de cosa que se rompe sola.

Cuatro caminos posibles:

| | Alternativa | Coste | Problema |
| - | ----------- | ----- | -------- |
| A | Añadir `protocol` a `device_type` | Bajo | Sería un protocolo *declarado*: hoy el tipo FMX920 agrupa equipos de 5 protocolos distintos |
| B | Poblar `device_models` | Medio | Catálogo nuevo que mantener, y enlazarlo con un `device_model` de texto libre es frágil |
| C | Resolver por lo que reportan los equipos del usuario | Medio | Correcto siempre, pero crea N filas por evento y hay que re-sincronizar |
| **D** | **Comodín "cualquier protocolo" en el evento** | **Bajo** | **Ninguno detectado — ver abajo** |

**Recomiendo la D**, y lo comprobé antes de proponerla: de los **108 eventos
personalizados existentes, 0 tienen el protocolo vacío**. Así que dar al valor
vacío el significado "cualquier protocolo" **no cambia el comportamiento de nada
de lo que ya existe**. Es un `if` en `EventCustomAlertCheck` y una opción más en
el formulario del admin.

Con eso:

- **Un solo evento por defecto** cubre aceleración, frenado y giro para toda la
  flota, sea cual sea el equipo.
- **Los protocolos futuros entran solos**, sin tocar catálogos ni reaplicar nada.
  Que era exactamente tu preocupación.
- Y el que quiera afinar por protocolo lo sigue pudiendo hacer: el campo se
  queda como está, solo gana el valor "cualquiera".

La A sigue teniendo sentido por separado si quieres saber qué protocolo *debería*
hablar cada tipo de equipo, pero no hace falta para esto.

### Qué se construye

1. **Eventos de sistema no borrables**: aceleración, frenado, giro y RPM altas,
   por valor de `alarm`, marcados como propios del sistema para que el cliente no
   los borre ni los renombre. Se siembran a todos los usuarios.
2. **Normalizador**: una tabla que dice qué mensajes cuentan como cada tipo de
   conducción brusca, para que los 64.495 "Giro Brusco" que ya existen sigan
   sumando y no se pierda el histórico.
3. **Reporte de conducción**: por vehículo, eventos por cada 100 km usando el
   kilometraje de `DeviceMileageService`, desglosado por tipo, exportable.
4. **Widget en el Dashboard**: top 5 mejor y peor del mes.

Primero el reporte, que es donde está el valor; el widget después.

### Conductores: cómo funcionan hoy — respuesta a tu pregunta

**Cada conductor vive dentro de un usuario, no son globales.** La tabla
`user_drivers` tiene `user_id`, y se asignan a los vehículos de ESE usuario
(`user_drivers.device_id` para el principal y el pivote `user_driver_devices`
para varios).

Por eso, entrando como admin, no ves conductores: los tienen los usuarios de tus
clientes. En toda la base hay **9 conductores, 10 asignaciones y 14 lecturas de
RFID** — o sea, prácticamente nadie los usa todavía.

De ahí que el ranking arranque **por vehículo**, como dices, y activable. El
motor es el mismo: cuando un cliente empiece a usar RFID, atribuir el evento al
conductor en vez de al vehículo es un cambio pequeño.

### Decisión abierta

**Cómo se pondera.** Un giro brusco no pesa lo mismo que un choque. Propongo
pesos configurables con un valor por defecto, en vez de una fórmula fija.

---

## 10 · Costo por kilómetro

### Corrección: Gastos ya está construido, y con datos sembrados

Preguntaste si sería mejor usar Gastos. **Sí, y me equivoqué al restarle
importancia.** Miré la tabla de tipos y no está vacía: trae **20 tipos ya
sembrados**, y son justo categorías de mantenimiento.

| | |
| - | - |
| Combustible · Peaje · Lavado | gastos de operación |
| Mantenimiento Preventivo · Cambio de Aceite · Cambio de Llantas | mantenimiento |
| Alineación y Balanceo · Cambio de Frenos · Cambio de Filtros | mantenimiento |
| Reparación Motor · Transmisión · Eléctrica | correctivo |

`device_expenses` guarda `type_id`, `quantity`, `unit_cost`, `date`, `supplier` y
`buyer`. La pestaña **Gastos** del modal del vehículo ya existe en la v2. Lo
único que falta es que alguien empiece a registrar.

### El problema real: dos sitios para lo mismo

Hay dos registros paralelos del mismo hecho:

| | `device_maintenances` | `device_expenses` |
| - | --------------------- | ----------------- |
| Filas | 91 | 0 |
| Campos | tipo, fecha, motivo, notas | tipo, cantidad, **costo**, fecha, proveedor |
| Dónde | pestaña Historial | pestaña Gastos |
| Dinero | **no** | sí |

Se registra el mantenimiento en un sitio y el dinero en otro que nadie usa. Ahí
está el nudo, no en que falte un campo.

### Propuesta

**Gastos es la fuente de costos reales.**

```
costo/km del periodo = suma de gastos del periodo ÷ km del periodo
```

Desglosado por tipo, que ya viene clasificado. Comparativa entre vehículos del
mismo grupo y evolución mensual.

**Y el campo por kilómetro que decidiste, como respaldo.** Confirmaste que va por
kilómetro: entra en Información junto a `fuel_price`, y sirve para los vehículos
que aún no tienen gastos registrados. Así el informe da un número desde el primer
día, y va ganando precisión conforme se capturen gastos reales.

En el informe se distingue de dónde sale cada cifra: **real** (de gastos) o
**estimada** (de la tarifa). Sin esa distinción, un costo estimado acaba
tomándose por contabilidad.

### Lo que se construye

1. Campo de costo por km en Información, junto a `fuel_price`, con su migración.
2. Enlazar el alta de mantenimiento con un gasto, para no seguir registrando el
   hecho en un sitio y el dinero en otro.
3. Informe de costo por km: real, estimado, o mezcla, siempre indicando cuál.

## Fuera de plan, pero urgente

**3.813 envíos a WiseTrack fallidos** entre el 11-may y el 22-jun, con la cola
crecida a 694.191 filas. Es un incidente en producción, no una mejora. Conviene
mirarlo antes que cualquiera de los seis puntos.
