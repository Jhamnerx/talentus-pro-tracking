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
| 10 | Costo por kilómetro | Medio | **Faltan datos** | **6º** |

El orden va por relación valor/riesgo, no por número. El 10 va al final porque
hoy **no hay datos de costo que mostrar** (ver §10).

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
usuario**. Al aplicar la plantilla a un cliente hay que resolver a qué evento
personalizado suyo se engancha. Tres casos:

1. **El evento ya existe en ese usuario** (mismo protocolo y condiciones): se
   reutiliza.
2. **No existe**: se crea a partir del que trae la plantilla.
3. **Existe con otro nombre**: se reutiliza y se avisa, sin renombrárselo.

Esto se apoya en lo del punto 12: si los eventos de conducción brusca pasan a
ser **de sistema y sembrados a todos**, la mayoría de plantillas caen en el caso
1 y el problema casi desaparece.

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
fallan y nadie se enteró en seis semanas. Esto hay que mirarlo aunque el panel
no se haga.

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
flota usa cinco. Un evento por defecto necesita **una fila por protocolo**, o
bien permitir un comodín "cualquier protocolo" en la comprobación. Recomiendo el
comodín: una fila y no cinco, y cubre protocolos futuros sin tocar nada.

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

### Lo que hay hoy — y por qué va el último

| Tabla | Filas | Problema |
| ----- | ----- | -------- |
| `device_expenses` | **0** | existe y tiene `quantity`/`unit_cost`, pero nadie la usa |
| `device_maintenances` | 91 | **no tiene columna de costo** |

El kilometraje sí está resuelto (`DeviceMileageService`, ya verificado contra
datos reales de mayo).

Dicho claro: **hoy el panel mostraría ceros**. La cañería es construible, pero
sin datos que meter no sirve de nada.

### Qué se construye

1. **Añadir costo al mantenimiento** (`device_maintenances.costo`, o registrar el
   gasto asociado). Es el cambio que desbloquea todo lo demás.
2. **Poner en uso la pestaña Gastos**, que ya existe en el modal del vehículo.
3. **Cálculo**: costo del periodo ÷ km del periodo, por vehículo, desglosado por
   tipo de gasto (combustible, mantenimiento, otros).
4. **Comparativa** entre vehículos del mismo grupo y evolución mensual.

### Decisión abierta

**¿De dónde sale el costo del mantenimiento?** Dos caminos:

- Columna nueva en `device_maintenances` — simple, pero duplica el concepto de
  gasto.
- Cada mantenimiento genera un `device_expense` — más limpio y unifica el
  informe, pero toca el flujo actual de mantenimientos.

Recomiendo el segundo, pero es tu decisión: afecta a cómo tu equipo registra el
día a día.

---

## Fuera de plan, pero urgente

**3.813 envíos a WiseTrack fallidos** entre el 11-may y el 22-jun, con la cola
crecida a 694.191 filas. Es un incidente en producción, no una mejora. Conviene
mirarlo antes que cualquiera de los seis puntos.
