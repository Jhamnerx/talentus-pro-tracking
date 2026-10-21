# Operaciones · Reporte de turno (pedido del cliente, 21-oct-2026)

Análisis del Excel `TALENTUS (1).xlsx` y de las tres capturas (tablas por turno, detalle de
estados por equipo, gráficas por hora) contra lo que ya calcula el módulo de Operaciones, y plan
para cubrirlo.

---

## 1. Qué pide el cliente

Todo gira alrededor del **turno** (Día / Noche) y del **vehículo** (volquete). Son ocho bloques:

| # | Bloque | Columnas / contenido | Fórmula del Excel |
| - | ------ | -------------------- | ----------------- |
| 1 | Horómetro | inicial · antes del descanso · después del descanso · final · VAR · horas ineficientes (ralentí) | `VAR = final − inicial` |
| 2 | Kilometraje | inicial · final · VAR | `VAR = final − inicial` |
| 3 | Abastecimiento de combustible | hora inicial · hora final · VAR | `VAR = final − inicial` (duración del abastecimiento) |
| 4 | Horas del turno | hora inicial · antes del descanso · después del descanso · final · VAR | `VAR = (final − inicial) − (después − antes)` = horas efectivas sin el descanso |
| 5 | Distancia | Día · Noche · Promedio (2.8 / 2.9 / 2.8) | — (parece km por vuelta) |
| 6 | Detalle de estados por equipo (horas) | barra apilada por vehículo: DELAY · MAINT · READY · STANDBY, suma 24 h | — |
| 7 | Volquetes rodando por hora | línea de 7 a 6 con la cantidad por hora · tarjetas Turno Día / Noche / Total del día | — |
| 8 | Velocidad promedio por hora | vacío por hora · cargado por hora · ambas en una · tarjetas Día / Noche / Promedio | — |
| 9 | Velocidad de la flota por hora (añadido el 21-oct) | una gráfica con la velocidad **máxima**, **media** y **mínima** de la flota en cada hora; cada punto guarda **qué unidad** la alcanzó y **a qué hora** exacta | — |

El Excel solo trae las tablas 1 a 4 con un vehículo de ejemplo; los bloques 5 a 8 están en
capturas de un tablero (parecen Power BI) y hay que reproducirlos.

**Condición añadida el 21-oct:** todas las gráficas deben verse **en una sola pantalla** dentro del
módulo de Operaciones (un tablero, sin pestañas internas ni páginas aparte).

---

## 2. Qué ya tiene el módulo que sirve para esto

| Dato que pide | Ya existe | Dónde |
| ------------- | --------- | ----- |
| Turnos Día / Noche con cruce de medianoche, `shift_date` por vuelta | Sí | `operation_shifts`, `Operation::shiftAt()`, `operation_cycles.shift_id/shift_date` |
| Vueltas, tramos cargado (`outbound`) y vacío (`inbound`), con duración, distancia, paradas, ralentí, motor apagado, sin datos | Sí | `operation_cycles`, `operation_legs` (`distance_meters`, `duration_seconds`, `stop_seconds`, `idle_seconds`, `engine_off_seconds`, `gap_seconds`) |
| Paradas autorizadas / no autorizadas con motivo | Sí | `operation_stops` (`authorized`, `reason_id`) |
| Visitas a puntos (carga, descarga) con hora de entrada y salida | Sí | `operation_visits` |
| Categorías de geocerca grifo, taller y descanso | Sí | `Geofence::CATEGORY_FUEL / WORKSHOP / REST` |
| Horas de motor (sensor o ignición), consumo y descuentos (abastecimiento, ralentí, paradas) | Sí, por día | Reporte 912 `OperationWorkHoursReport`, `Operation::worked_hours_deductions` |
| Horómetro y odómetro al inicio y fin de un rango | Sí (pipeline) | `Tobuli/History/Actions`: `EngineHours`, `OdometerStart`, `OdometerEnd`, `OdometersDiff` |
| Detección de abastecimiento por sensor de nivel | Sí (pipeline) | `AppendFuelFilling*`, `GroupFuelFilling`, reporte `FuelFillingsReport` |
| Pantalla En vivo, Vueltas y Operaciones; recálculo por rango | Sí | `tp-operations.js`, `OperationsController`, `OperationRebuildService` |

Conclusión: **los datos base están**. Lo que falta es (a) el corte por turno con descanso,
(b) una tabla agregada por vehículo y turno, (c) estadísticas por hora, y (d) la pantalla y el
Excel con el formato del cliente.

---

## 3. Brechas y cómo cubrir cada bloque

### 3.1 Descanso dentro del turno (afecta a 1 y 4)

Hoy el turno es solo inicio y fin. El cliente necesita el momento "antes" y "después" del
descanso.

- **Configuración:** dos campos nuevos en `operation_shifts`: `break_start` y `break_end`
  (hora local, opcionales, pueden cruzar medianoche como el turno). Se editan en el modal de la
  operación junto al turno.
- **Alternativa automática:** si el turno no tiene descanso fijo, usar la primera visita del
  vehículo a una geocerca de categoría **descanso** dentro del turno (ya existe la categoría y el
  piloto la usa en «Cola de descarga»). Se guarda cuál de las dos se usó.
- **Decisión pendiente con el cliente:** si el descanso es fijo (refrigerio a la misma hora para
  todos) o por vehículo (cuando entra a la zona de descanso).

### 3.2 Horómetro inicial / antes / después / final + horas ineficientes (bloque 1)

- **Fuente (decidido el 21-oct):** el sensor de horas de motor del vehículo (`engine_hours`).
  Un vehículo sin ese sensor sale con el horómetro en blanco y un aviso en la fila; no se
  calcula por ignición. Se toma el valor en la primera trama
  del turno, en la última antes del descanso, en la primera después y en la última del turno.
- **VAR:** final − inicial (igual que el Excel).
- **Horas ineficientes (ralentí):** suma de `idle_seconds` de los tramos del turno más el
  ralentí de las paradas (`operation_stops.engine_on_seconds`) fuera de los puntos de carga y
  descarga. Ya es uno de los descuentos del reporte 912; aquí se muestra como columna.

### 3.3 Kilometraje inicial / final (bloque 2)

- **Fuente:** odómetro del vehículo (`OdometerStart` / `OdometerEnd` del pipeline, que respetan
  el sensor de odómetro o el virtual por GPS). VAR = diferencia. Se cruza con la suma de
  `distance_meters` de los tramos para detectar saltos de GPS.

### 3.4 Abastecimiento de combustible (bloque 3)

- **Hora inicial / final:** entrada y salida a una geocerca de categoría **grifo** dentro del
  turno (el reporte 912 ya las usa para descontar). Si el vehículo tiene sensor de nivel, se
  muestra además los litros/galones cargados (`GroupFuelFilling`).
- Si hay más de un abastecimiento en el turno, una fila por cada uno.

### 3.5 Hora inicial / final del turno por vehículo (bloque 4)

- **Hora inicial:** primera trama con motor encendido (o primer movimiento) dentro del turno.
- **Hora final:** última trama con motor encendido dentro del turno.
- **Antes / después del descanso:** de 3.1.
- **VAR:** (final − inicial) − (después − antes), como en el Excel. Es la misma idea que las
  "horas netas" del reporte 912, pero por turno y con el descanso como descuento.

### 3.6 Distancia Día / Noche / Promedio (bloque 5)

Los valores de ejemplo (2.8 y 2.9) encajan con **kilómetros por vuelta**. Propuesta: distancia
media por vuelta completada, por turno y total del día (suma de `distance_meters` de los dos
tramos ÷ vueltas). Confirmar con el cliente; si quisieran kilómetros totales por turno, sale de
3.3 sin trabajo adicional.

### 3.7 Detalle de estados por equipo (bloque 6)

No existe un "estado del equipo" con esas cuatro categorías. Propuesta de equivalencia, por
vehículo y día (24 h), construida con lo que ya se guarda:

| Estado del cliente | Cómo se calcula |
| ------------------ | --------------- |
| **READY** (productivo) | tiempo en tramo moviéndose: `duration − stop − idle − gap` de los tramos, más el tiempo en carga y descarga (`load_seconds`, `unload_seconds`) |
| **DELAY** (demora) | propuesta: tiempo en tramo por encima del ciclo esperado (vuelta lenta) y «sin datos» (`gap_seconds`); si el cliente prefiere, las paradas no autorizadas se mueven aquí y STANDBY se queda con las autorizadas |
| **STANDBY** (parado, decidido el 21-oct) | todo el tiempo con el vehículo **detenido** dentro del turno: paradas autorizadas y no autorizadas, ralentí, descanso y espera en cola. Queda por confirmar con el cliente qué deja eso en DELAY (ver preguntas) |
| **MAINT** (mantenimiento) | tiempo dentro de geocercas de categoría **taller** |
| Resto hasta 24 h | motor apagado fuera de todo lo anterior (se muestra como "Fuera de operación", en gris, para que la barra cierre en 24 h) |

Se presenta como barra apilada horizontal por vehículo con las horas dentro de cada tramo,
igual que la captura, y con los mismos colores (naranja, rojo, verde, amarillo).

### 3.8 Volquetes rodando por hora (bloque 7)

- Por cada hora del día, cantidad de vehículos distintos que tuvieron al menos X minutos de
  movimiento en tramo (propuesta: 5 min, configurable). Fuente: `operation_legs` cruzados con la
  hora.
- Tarjetas: promedio de las horas del turno Día, del turno Noche y del día completo.

### 3.9 Velocidad promedio vacío / cargado por hora (bloque 8)

- En una operación de acarreo, **cargado = tramo `outbound`** (carga → descarga) y
  **vacío = tramo `inbound`** (descarga → carga).
- Velocidad promedio por hora = distancia recorrida en esa hora ÷ tiempo en movimiento en esa
  hora, separada por sentido. Para repartir un tramo entre horas se usan las posiciones (no el
  promedio del tramo), así una hora con medio tramo no se distorsiona.
- Tarjetas: promedio del turno Día, Noche y del día, por sentido, y la gráfica combinada.

### 3.10 Velocidad máxima, media y mínima de la flota por hora (bloque 9)

- Por cada hora del día, sobre las posiciones **en movimiento** de todos los vehículos de la
  operación: la velocidad máxima (y qué unidad y a qué hora:minuto:segundo la alcanzó), la
  mínima en movimiento (unidad y momento) y la media de la flota (promedio ponderado por
  tiempo en movimiento, para que un vehículo con más tramas no pese de más).
- Cada punto de la gráfica guarda `device_id`, placa y `at` (fecha-hora exacta). Al pasar el
  ratón o tocar el punto se muestra «CFW-813 · 14:37:12 · 42 km/h»; al hacer clic se abre el
  historial de ese vehículo en ese minuto (ya existe `?device=&from=&to=`).
- La mínima excluye tramas detenidas (velocidad 0); si no, siempre sería 0. Umbral: la
  velocidad mínima de movimiento del sistema (`min_moving_speed`).
- Se guarda en `operation_hour_stats` por **flota** (fila con `device_id` nulo) además de las
  filas por vehículo: `max_speed, max_speed_device_id, max_speed_at, min_speed,
  min_speed_device_id, min_speed_at, avg_speed, moving_seconds`.

---

## 4. Diseño técnico

### 4.1 Dos tablas agregadas nuevas

Calcular esto sobre las posiciones cada vez que se abre la pantalla es inviable (varios
vehículos × 24 h de tramas). Se agrega una vez y se consulta barato.

**`operation_shift_summaries`** — una fila por operación, vehículo y turno-fecha:

```
operation_id, device_id, shift_id, shift_name, shift_date,
first_time, break_start_at, break_end_at, last_time, break_source (fixed|geofence|none),
engine_hours_start, engine_hours_before_break, engine_hours_after_break, engine_hours_end,
odometer_start, odometer_end,
moving_seconds, idle_seconds, stop_auth_seconds, stop_unauth_seconds, gap_seconds,
workshop_seconds, rest_seconds, load_seconds, unload_seconds,
cycles_count, distance_meters, loaded_distance_m, loaded_moving_s, empty_distance_m, empty_moving_s,
calculated_at
```

**`operation_hour_stats`** — una fila por operación, vehículo y hora (fecha + hora 0-23):

```
operation_id, device_id (nulo = toda la flota), hour_at (dateTime, minuto 0),
moving_seconds, loaded_distance_m, loaded_moving_s, empty_distance_m, empty_moving_s,
max_speed, max_speed_device_id, max_speed_at, min_speed, min_speed_device_id, min_speed_at, avg_speed
```

**`operation_refuelings`** — una fila por abastecimiento detectado (geocerca grifo o sensor):

```
operation_id, device_id, shift_summary_id, started_at, ended_at, liters (nullable), source
```

Las tres se recalculan por rango con el mismo mecanismo de `OperationRebuildService` (botón
Recalcular ya existente), y `operations:process` cierra el turno anterior cada vez que cambia
el turno (una pasada por vehículo al cerrar, no por trama).

### 4.2 Servicios

- `OperationShiftSummaryService::build(Operation, Device, OperationShift, Carbon $shiftDate)`:
  recorre las posiciones del turno una vez (chunk), apoyándose en las acciones del pipeline que ya
  existen (`EngineHours`, `OdometerStart/End`, `GroupGeofenceIn` para grifo/taller/descanso,
  `GroupFuelFilling`) y en los tramos, paradas y visitas ya calculados. Escribe las tres tablas.
- `OperationHourStatsService` queda dentro del anterior (misma pasada).
- Enganche en `operations:process` (cierre de turno) y en `OperationRebuildService` (recálculo).

### 4.3 Pantalla: pestaña «Turno» en Operaciones (UI v2), todo en una sola vista

Es una cuarta pestaña junto a En vivo, Vueltas y Operaciones. **Todas las gráficas y tablas van
en la misma pantalla**, en una sola columna desplazable (sin sub-pestañas), con los filtros fijos
arriba: operación, fecha y turno (Día / Noche / Todos). En pantallas anchas las gráficas por hora
van a dos columnas; en móvil, a una.

Orden de arriba a abajo:

1. **Tarjetas:** volquetes rodando (Día / Noche / Total), velocidad vacío y cargado (Día / Noche
   / Promedio), distancia por vuelta (Día / Noche / Promedio).
2. **Gráficas por hora (bloques 7, 8 y 9):** volquetes rodando; velocidad vacío; velocidad
   cargado; cargado y vacío juntas; **máxima / media / mínima de la flota** con la unidad y la
   hora exacta en cada punto (tooltip y clic al historial).
3. **Estados por equipo (horas), bloque 6:** barras apiladas horizontales, un vehículo por fila.
4. **Tabla «Horas y horómetro»** (bloques 1 y 4): hora y horómetro inicial, antes y después del
   descanso, final, horas efectivas y ralentí.
5. **Tabla «Kilometraje»** (bloque 2) y **tabla «Abastecimientos»** (bloque 3).

Las gráficas se dibujan con el mismo canvas de la gráfica del historial (sin librería nueva) y
comparten un selector de hora: al pasar por una hora se resalta esa hora en todas. Un botón
«Pantalla completa» muestra solo el tablero (sin menú lateral) para proyectarlo en sala.

Botón **Exportar Excel** con el formato del cliente (hoja de tablas con las mismas cabeceras del
Excel recibido y una hoja por gráfica con sus datos; los gráficos del Excel se generan con
`PhpSpreadsheet`, ya presente en el proyecto para los reportes XLSX).

### 4.4 Reportes

Nuevo reporte **913 · Operación · resumen de turno** (XLSX, HTML, PDF) con las mismas secciones,
para quien prefiera programarlo o recibirlo por correo con los reportes existentes. Reutiliza las
tablas agregadas; no vuelve a leer posiciones.

### 4.5 Legacy

No se toca: el módulo de Operaciones es solo UI v2 (decisión del 6-oct-2026).

---

## 5. Plan por fases

| Fase | Entregables | Esfuerzo | Terminada cuando |
| ---- | ----------- | -------- | ---------------- |
| A · Configuración | `break_start/break_end` en turnos (migración, modal, validación); revisar que los vehículos del piloto tengan sensor de horas de motor, odómetro y, si lo hay, nivel de combustible; geocercas grifo y taller del cliente | 1,5 días | El modal guarda el descanso y los vehículos del piloto tienen los sensores asignados |
| B · Cálculo | Migraciones de las 3 tablas, `OperationShiftSummaryService`, enganche en `operations:process` y en el recálculo | 5 a 6 días | El recálculo de una semana del piloto llena las tablas y el horómetro/kilometraje coincide con el control manual del cliente |
| C · Pantalla «Turno» | Pestaña nueva con **todo en una vista**: tarjetas, cinco gráficas por hora (incluida máx/media/mín de flota con unidad y hora por punto), barra apilada de estados y las tres tablas | 5 a 6 días | El cliente ve en una sola pantalla lo mismo que en su tablero de Power BI |
| D · Exportación y reporte | Excel con el formato del cliente, reporte 913 en Reportes | 2 a 3 días | El Excel descargado se abre con las mismas tablas que `TALENTUS (1).xlsx` y las gráficas |
| E · Piloto y ajustes | Comparar un turno Día y uno Noche completos contra el control del cliente; ajustar umbrales (minutos para "rodando", descanso) | 2 días | Diferencias explicadas y aceptadas |

**Total estimado: 16 a 19 días** de un desarrollador. Orden: A → B → C → D → E. B puede
probarse con el recálculo sobre días reales antes de que exista la pantalla.

---

## 6. Preguntas para el cliente antes de empezar

Ya decidido (21-oct): el horómetro sale del sensor de horas del vehículo, STANDBY es el tiempo
parado y todas las gráficas van en una sola pantalla del módulo de Operaciones.

**Turno y descanso**

1. ¿El descanso es un horario fijo por turno (refrigerio a la misma hora para todos) o empieza
   cuando cada volquete entra a la zona de descanso? ¿Puede haber más de un descanso por turno?
2. El reporte del «día X», ¿va de 07:00 del día X a 07:00 del día siguiente (el turno Noche del
   X cuenta para el X) o es el día calendario de 00:00 a 24:00?
3. La hora inicial y final de cada volquete en el turno, ¿es la primera y la última vez que
   encendió el motor, o la primera y la última vez que se movió?

**Horómetro y kilometraje**

4. ¿Todos los volquetes reportan el horómetro del equipo al GPS? Los que no lo tengan saldrán en
   blanco en esa tabla. ¿Quieren que para esos se muestre el calculado por ignición, marcado
   como estimado, o que quede vacío?
5. El kilometraje inicial y final, ¿del odómetro del vehículo o del recorrido GPS?

**Estados del equipo**

6. Si STANDBY es todo el tiempo parado, ¿qué queda en DELAY? Opciones: (a) vueltas más lentas que
   el ciclo esperado y pérdida de señal; (b) las paradas no autorizadas pasan a DELAY y STANDBY
   se queda solo con las autorizadas y el descanso.
7. MAINT: ¿basta con el tiempo dentro de la zona de taller, o hay mantenimientos en campo que se
   deben registrar a mano?
8. ¿Qué pasa con el tiempo con motor apagado fuera de turno? Propuesta: barra gris «fuera de
   operación» para que cada vehículo cierre en 24 h.

**Gráficas por hora**

9. «Volquete rodando»: ¿cuántos minutos de movimiento dentro de la hora hacen que cuente?
   Propuesta 5.
10. Velocidad vacío y cargado: confirmar que cargado es el viaje de carga a descarga y vacío el
    regreso (no hay sensor de peso).
11. Velocidad mínima de la flota por hora: ¿se toma solo con el vehículo en movimiento (lo
    propuesto; si no, siempre sería 0)? ¿Desde qué velocidad se considera «en movimiento»?
12. Velocidad media de la flota: ¿promedio simple de los vehículos que se movieron en esa hora, o
    ponderado por el tiempo que cada uno estuvo en movimiento?
13. Al hacer clic en un punto de la gráfica (unidad y hora), ¿quieren abrir el historial de ese
    vehículo en ese momento?
14. La pantalla única: ¿se va a proyectar en una sala (hace falta modo pantalla completa y
    refresco automático) o se consulta desde el escritorio?

**Distancia y combustible**

15. Los valores de distancia 2,8 / 2,9 del tablero, ¿son kilómetros por vuelta o kilómetros
    totales del turno?
16. El abastecimiento, ¿siempre en el grifo (zona) o también con cisterna en campo? En campo
    solo se detecta con sensor de nivel de combustible. ¿Quieren los galones cargados o solo las
    horas?

**Entrega**

17. ¿Necesitan el Excel con el mismo formato que `TALENTUS (1).xlsx` o les sirve la pantalla y un
    Excel con tablas simples?
18. ¿Quién recibe el reporte y con qué frecuencia (al cierre de cada turno por correo, o bajo
    demanda desde la pantalla)?

---

## 7. Riesgos

- **Sin sensor de horas de motor ni odómetro** en el equipo, el horómetro y el kilometraje salen
  calculados (ignición y GPS); el cliente puede ver diferencias con el tablero del vehículo.
- **Tramas cada 30 s:** la velocidad por hora y el reparto de un tramo entre horas dependen de
  esa frecuencia; con reportes más espaciados la curva se aplana.
- **Volumen:** las tablas por hora crecen 24 filas por vehículo y día; con 50 vehículos son
  440 mil filas al año. Índice por `(operation_id, hour_at)` y purga configurable (por ejemplo
  13 meses), como ya se hace con `kept_days` en otros módulos.
