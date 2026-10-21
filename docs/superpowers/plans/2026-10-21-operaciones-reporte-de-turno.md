# Operaciones · Reporte de turno (pedido del cliente, 21-oct-2026)

Análisis del Excel `TALENTUS (1).xlsx` y de las tres capturas (tablas por turno, detalle de
estados por equipo, gráficas por hora) contra lo que ya calcula el módulo de Operaciones, y plan
para cubrirlo.

---

## 1. Qué pide el cliente

El Excel tiene **11 elementos**: 5 bloques de celdas (el selector de turno y 4 tablas) y 6
imágenes pegadas de un tablero (Power BI). Todo va por **turno** (Día / Noche) y por
**vehículo** (volquete). Numerados como aparecen en la hoja, de izquierda a derecha y de arriba
abajo:

| # | Elemento | Tipo en el Excel | Contenido | Fórmula del Excel |
| - | -------- | ---------------- | --------- | ----------------- |
| 1 | Turno | selector (B2:C2) | Día / Noche | — |
| 2 | Horómetro | tabla (B4:H9) | inicial · antes del descanso · después del descanso · final · VAR · horas ineficientes (ralentí) | `VAR = final − inicial` |
| 3 | Kilometraje | tabla (J4:M9) | inicial · final · VAR | `VAR = final − inicial` |
| 4 | Abastecimiento de combustible | tabla (B12:E18) | hora inicial · hora final · VAR | `VAR = final − inicial` |
| 5 | Horas del turno | tabla (J12:O17) | hora inicial · antes del descanso · después del descanso · final · VAR | `VAR = (final − inicial) − (después − antes)` |
| 6 | Distancia | imagen 6 | Día · Noche · Promedio (2,8 / 2,9 / 2,8) | — |
| 7 | Detalle de estados por equipo (horas) | imagen 5 | barra apilada por vehículo: DELAY · MAINT · READY · STANDBY, suma 24 h | — |
| 8 | Cantidad de volquetes rodando por hora | imagen 3 | línea de 7 a 6 · tarjetas Turno Día / Noche / Total del día | — |
| 9 | Velocidad promedio vacío por hora | imagen 1 | línea de 7 a 6 · tarjetas Turno Día / Noche / Total | — |
| 10 | Velocidad promedio cargado por hora | imagen 2 | línea de 7 a 6 · tarjetas Turno Día / Noche / Total | — |
| 11 | Velocidad promedio cargado y vacío por hora | imagen 4 | las dos líneas juntas · tarjetas Día / Noche / Promedio por sentido | — |

Añadido el 21-oct, fuera del Excel:

| # | Elemento | Contenido |
| - | -------- | --------- |
| 12 | Velocidad de la flota por hora | una gráfica con la velocidad **máxima**, **media** y **mínima** de la flota en cada hora; cada punto guarda **qué unidad** la alcanzó y **a qué hora** exacta |

**Condición añadida el 21-oct:** los 12 elementos deben verse **en una sola pantalla** dentro del
módulo de Operaciones (un tablero, sin pestañas internas ni páginas aparte). El selector de turno
(elemento 1) es el filtro de esa pantalla.

En el resto del documento se usa esta numeración; las secciones 3.x la citan como «bloque N».

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

### 3.1 Descansos: apartado propio en el modal de la operación (afecta a 2 y 5)

**Respuesta del cliente:** Día 12:00–13:00 (almuerzo), Noche 02:00–03:00, y puede haber más de
un descanso por fatiga y somnolencia. **Decisión (21-oct):** nada de horarios en el código; los
turnos siguen siendo los que se configuren en la operación y los descansos se configuran en un
apartado nuevo **«Descansos»** del mismo modal, debajo de Turnos.

- **Tabla `operation_shift_breaks`:** `shift_id`, `name`, `kind` (`scheduled` | `fatigue`),
  `start_time`, `end_time` (solo para los programados), `position`.
- **Descanso programado** (almuerzo, refrigerio): nombre + hora de inicio y fin dentro del
  turno. El primero de la lista es el que define las columnas «antes» y «después del descanso»
  de las tablas 2 y 5; si hay más de uno programado, se usa el más largo y los demás solo
  descuentan tiempo.
- **Descanso por fatiga y somnolencia:** se agrega con una casilla «Permitir descanso por
  fatiga y somnolencia» en el apartado, sin horario. Cuenta como ese descanso toda parada con
  **motor apagado** fuera de los descansos programados; si quieren verlo con nombre en las
  tablas, el operador justifica la parada con el motivo «Fatiga y somnolencia» (el catálogo de
  motivos ya existe y se puede seedear ese motivo). Todos los descansos cuentan como STANDBY.
- **Modal:** mismo patrón de filas que Turnos (nombre, turno al que pertenece, tipo, desde,
  hasta, botón quitar) con «+ Agregar descanso». Validación: dentro del rango del turno, sin
  solaparse entre sí.

### 3.1b Día operativo y turnos con margen de 30 minutos

**Respuesta del cliente:** el reporte va de **06:30 a 06:30** del día siguiente. Los turnos
normales son Día 07:00–18:00 y Noche 19:00–06:00, pero algunos días empiezan o terminan después,
por eso quieren un **margen de 30 minutos**.

- Los turnos se configuran ya con el margen: **Día 06:30–18:30** y **Noche 18:30–06:30**. Así
  no hay huecos, toda vuelta cae en un turno y el día operativo (06:30 → 06:30) es la suma de los
  dos turnos. Esto ya lo soporta `operation_shifts` sin cambios.
- Campo nuevo en la operación (Opciones avanzadas): `day_start`, hora a la que empieza el día
  operativo; por defecto, el inicio del primer turno configurado. Ningún horario queda fijo en
  el código. La pantalla y el Excel del «día X» cubren de las 06:30
  del X a las 06:30 del X+1; el turno Noche del X cuenta para el día X.
- Las tarjetas «Turno Día / Turno Noche / Total del día» usan esos cortes.

### 3.2 Horómetro inicial / antes / después / final + horas ineficientes (bloque 2)

- **Fuente (decidido el 21-oct):** el sensor de horas de motor del vehículo (`engine_hours`).
  Se configurará ese sensor en todos los vehículos de este usuario; el que no lo tenga sale con
  la celda vacía. No se calcula por ignición. Se toma el valor en la primera trama
  del turno, en la última antes del descanso, en la primera después y en la última del turno.
- **VAR:** final − inicial (igual que el Excel).
- **Horas ineficientes (ralentí):** suma de `idle_seconds` de los tramos del turno más el
  ralentí de las paradas (`operation_stops.engine_on_seconds`) fuera de los puntos de carga y
  descarga. Ya es uno de los descuentos del reporte 912; aquí se muestra como columna.

### 3.3 Kilometraje inicial / final (bloque 3)

- **Fuente (decidido el 21-oct):** el sensor de odómetro del vehículo, igual que el horómetro:
  valor en la primera y en la última trama del turno; sin sensor, celda vacía (no se usa el
  odómetro virtual por GPS). VAR = diferencia. Se cruza con la suma de
  `distance_meters` de los tramos para detectar saltos de GPS.

### 3.4 Abastecimiento de combustible (bloque 4)

**Respuesta del cliente:** todo el abastecimiento es con **cisterna en campo** y el lugar es
dinámico, así que no hay geocerca de grifo. Los equipos solo leen el **% del tanque**. Regla: el
equipo para y apaga motor; si al volver a encender el nivel subió, esa parada fue un
abastecimiento.

- **Se reutiliza lo que ya usan los reportes de combustible** (`FuelFillingsReport`,
  `FuelTheftsReport`): las acciones del pipeline `AppendFuelChange` → `AppendFuelFillingChange` /
  `AppendFuelTheftChange` → `GroupFuelFilling` ya entregan, por cada subida de nivel, el nivel
  **antes**, el nivel **después**, la diferencia y la hora. El módulo solo las acota a la parada
  con motor apagado y las guarda por turno; no se escribe una detección nueva.
- **Umbrales desde el vehículo, no fijos (decisión 21-oct):** el salto mínimo de recarga y de
  robo ya se configuran en el dispositivo (pestaña Precisión: `min_fuel_fillings`,
  `min_fuel_thefts`, y `fuel_detect_sec_after_stop` para el tiempo tras la parada). Las mismas
  acciones del pipeline los leen de ahí (`$this->history->config('min_fuel_fillings')`), así que
  el módulo hereda esa configuración por vehículo. No se agrega ningún umbral a la operación.
- **Columnas:** hora inicial = apagado de motor, hora final = encendido, VAR = duración, **% al
  llegar**, **% al salir** y **recarga en puntos de %**. Fila por cada abastecimiento del turno.
  Una subida con motor encendido también se registra, marcada «sin apagar motor».
- El tiempo de abastecimiento se descuenta de las horas efectivas (ya es el descuento
  «abastecimiento» del reporte 912) y en los estados del equipo va a STANDBY (motor apagado).

### 3.5 Hora inicial / final del turno por vehículo (bloque 5)

- **Hora inicial:** primera trama con motor encendido (o primer movimiento) dentro del turno.
- **Hora final:** última trama con motor encendido dentro del turno.
- **Antes / después del descanso:** de 3.1.
- **VAR:** (final − inicial) − (después − antes), como en el Excel. Es la misma idea que las
  "horas netas" del reporte 912, pero por turno y con el descanso como descuento.

### 3.6 Distancia Día / Noche / Promedio (bloque 6)

**Decidido el 21-oct: son kilómetros totales del turno.** Día = suma de los kilómetros
recorridos por la flota en el turno Día, Noche = lo mismo en el turno Noche, Promedio = media de
los dos. Fuente: la diferencia de odómetro de cada vehículo en el turno (3.3) sumada por flota;
para un vehículo sin sensor de odómetro se usa la suma de `distance_meters` de sus tramos y se
marca como estimado.

### 3.7 Detalle de estados por equipo (bloque 7)

**Respuesta del cliente:** DELAY = **ralentí** (motor encendido sin moverse); STANDBY =
descanso o **motor apagado sin movimiento**; MAINT = paso por una geocerca de taller (los talleres
están fuera de mina) y, si se puede, registro manual desde la laptop; el resto en gris.

| Estado | Cómo se calcula, por vehículo y día operativo (24 h) |
| ------ | --------------------------------------------------- |
| **READY** | tiempo en movimiento (tramos: `duration − stop − idle − gap`) más el tiempo en carga y descarga en movimiento |
| **DELAY** | **ralentí**: motor encendido sin movimiento, dentro o fuera de los puntos (`idle_seconds` de los tramos y `engine_on_seconds` de las paradas), con el mismo criterio que la alerta y el reporte de ralentí |
| **STANDBY** | descansos configurados (programados y por fatiga) y cualquier parada con **motor apagado sin movimiento** dentro del turno (incluye abastecimiento) |
| **MAINT** | tiempo dentro de una geocerca de categoría **taller** (desde que entra hasta que sale) y paradas justificadas con un motivo marcado como mantenimiento |
| **Fuera de operación** (gris) | motor apagado fuera de turno y «sin datos» (`gap_seconds`), para que la barra cierre en 24 h |

**Mantenimiento manual:** se aprovecha la justificación de paradas que ya existe. Al motivo de
parada se le agrega la marca «cuenta como mantenimiento» (columna `state` en
`operation_stop_reasons`: `null | maint | standby`); una parada justificada con ese motivo pasa a
MAINT aunque no haya geocerca. Se registra desde la pantalla de Vueltas, en la laptop, como hoy
se justifican las paradas. Para los talleres fuera de mina el cliente crea las geocercas con
categoría taller y no hace falta nada más.

Se presenta como barra apilada horizontal por vehículo con las horas en cada tramo, con los
colores del tablero del cliente (naranja DELAY, rojo MAINT, verde READY, amarillo STANDBY, gris
fuera de operación).

### 3.8 Volquetes rodando por hora (bloque 8)

- **Respuesta del cliente:** cuenta **desde que se mueve**. Por cada hora del día, cantidad de
  vehículos distintos con al menos una trama en movimiento (velocidad ≥ 1 km/h) en esa hora. Fuente: `operation_legs` cruzados con la
  hora.
- Tarjetas: promedio de las horas del turno Día, del turno Noche y del día completo.

### 3.9 Velocidad promedio vacío / cargado por hora (bloques 9, 10 y 11)

- Confirmado por el cliente: **cargado = tramo `outbound`** (carga → descarga) y
  **vacío = tramo `inbound`** (descarga → carga).
- Velocidad promedio por hora = distancia recorrida en esa hora ÷ tiempo en movimiento en esa
  hora, separada por sentido. Para repartir un tramo entre horas se usan las posiciones (no el
  promedio del tramo), así una hora con medio tramo no se distorsiona.
- Tarjetas: promedio del turno Día, Noche y del día, por sentido, y la gráfica combinada.

### 3.10 Velocidad máxima, media y mínima de la flota por hora (bloque 12)

- Por cada hora del día, sobre las posiciones **en movimiento** de todos los vehículos de la
  operación: la velocidad máxima (y qué unidad y a qué hora:minuto:segundo la alcanzó), la
  mínima en movimiento (unidad y momento) y la media de la flota. **Decidido el 21-oct:** la
  media se calcula solo con las tramas **en movimiento** de las unidades (sin paradas), ponderada
  por el tiempo en movimiento para que un vehículo con más tramas no pese de más.
- Cada punto de la gráfica guarda `device_id`, placa y `at` (fecha-hora exacta). Al pasar el
  ratón o tocar el punto se muestra «CFW-813 · 14:37:12 · 42 km/h». **Decidido el 21-oct:** al
  hacer clic se abre el historial de ese vehículo en ese minuto, indicando qué unidad es (ya
  existe `?device=&from=&to=`).
- La mínima excluye tramas detenidas; si no, siempre sería 0. **Decidido el 21-oct:** el
  módulo está pensado para equipos que leen la velocidad del OBD del vehículo (FMC150), con
  velocidad mínima de movimiento de **1 km/h**. Para otros equipos se usa la velocidad mínima de
  movimiento configurada en el sistema (`min_moving_speed`).
- Se guarda en `operation_hour_stats` por **flota** (fila con `device_id` nulo) además de las
  filas por vehículo: `max_speed, max_speed_device_id, max_speed_at, min_speed,
  min_speed_device_id, min_speed_at, avg_speed, moving_seconds`.

### 3.11 Estado de vehículo (vacío / cargado) automático para Seguridad (añadido el 21-oct)

Los tipos y estados de vehículo del módulo de Seguridad (por ejemplo CAMIONES: VACIO / CARGADO,
con límite de velocidad por geocerca según el estado) hoy se cambian a mano. Operaciones ya sabe
en cada momento si el volquete va cargado o vacío, así que lo actualiza solo:

- En la operación (Opciones avanzadas) se elige el **tipo de vehículo** del catálogo del usuario
  y qué estado corresponde a **cargado** y cuál a **vacío** (`operations.vehicle_type_id`,
  `loaded_state_id`, `empty_state_id`, los tres opcionales). Si no se configura, no hace nada.
- `OperationChecker`, al abrir un tramo `outbound` pone el estado «cargado» y al abrir uno
  `inbound` pone «vacío», siempre con `DeviceClassificationService::setState()` (regla del
  módulo de clasificación: nunca escribir la tabla a mano) y para el **dueño de la operación**.
- Efecto: los límites de velocidad por estado de las geocercas y las alertas de exceso se
  aplican solos según el sentido del viaje, sin que nadie cambie el estado.

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

**`operation_refuelings`** — una fila por abastecimiento detectado (parada con motor apagado y
subida del % de tanque, o subida con motor encendido):

```
operation_id, device_id, shift_summary_id, stop_id (nullable), sensor_id, started_at, ended_at,
level_before_pct, level_after_pct, refuel_pct, engine_off (bool)
```

**Cambios en tablas existentes:** `operation_shift_breaks` (descansos por turno, programados y
por fatiga), `operations.day_start` (hora de inicio del día operativo),
`operations.vehicle_type_id / loaded_state_id / empty_state_id` (estado automático para
Seguridad) y `operation_stop_reasons.state` (`null | maint | standby`). Los umbrales de combustible no se
duplican: se leen del dispositivo.

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
2. **Gráficas por hora (bloques 8 a 12):** volquetes rodando; velocidad vacío; velocidad
   cargado; cargado y vacío juntas; **máxima / media / mínima de la flota** con la unidad y la
   hora exacta en cada punto (tooltip y clic al historial).
3. **Estados por equipo (horas), bloque 7:** barras apiladas horizontales, un vehículo por fila.
4. **Tabla «Horas y horómetro»** (bloques 2 y 5): hora y horómetro inicial, antes y después del
   descanso, final, horas efectivas y ralentí.
5. **Tabla «Kilometraje»** (bloque 3) y **tabla «Abastecimientos»** (bloque 4).

Las gráficas se dibujan con el mismo canvas de la gráfica del historial (sin librería nueva) y
comparten un selector de hora: al pasar por una hora se resalta esa hora en todas.
**Decidido el 21-oct:** la pantalla es de **consulta bajo demanda**: se carga al elegir
operación, fecha y turno, y se actualiza con el botón Refrescar; sin refresco automático ni
modo sala.

Botón **Exportar Excel**. **Respuesta del cliente:** quieren las gráficas tanto en pantalla
como en el Excel, y que las del Excel sean **iguales** a las de la pantalla. Por eso:

- La hoja principal reproduce el formato de `TALENTUS (1).xlsx`: selector de turno, las cuatro
  tablas con las mismas cabeceras y fórmulas (`VAR`), y las gráficas colocadas en las mismas
  zonas.
- Las gráficas se generan como **gráficos nativos de Excel** con `PhpSpreadsheet` (líneas con
  etiquetas de valor para las de por hora, barra apilada horizontal para los estados), con los
  mismos colores y títulos que la pantalla; los datos de cada gráfica van en una hoja «Datos»
  de la que lee el gráfico, así el cliente puede ajustarlas en Excel.
- Para que pantalla y Excel coincidan, ambas leen las mismas tablas agregadas y la misma
  función de armado de series; la pantalla dibuja con canvas y el Excel con gráficos nativos.

### 4.4 Reportes

**Decidido el 21-oct:** el resumen de turno se consulta **bajo demanda desde la pantalla**
(y se exporta a Excel desde ahí). El reporte 913 en Reportes queda como opcional para una fase
posterior si el cliente quisiera programarlo o recibirlo por correo; reutilizaría las tablas
agregadas sin volver a leer posiciones.

### 4.5 Legacy

No se toca: el módulo de Operaciones es solo UI v2 (decisión del 6-oct-2026).

---

## 5. Plan por fases

| Fase | Entregables | Esfuerzo | Terminada cuando |
| ---- | ----------- | -------- | ---------------- |
| A · Configuración | apartado «Descansos» en el modal (programados y por fatiga), `day_start` y tipo/estados de vehículo (cargado / vacío) en Opciones avanzadas, marca de estado en los motivos de parada (migraciones, modal, validación); el cliente configura sus turnos y descansos; sensores de horas de motor, odómetro y nivel de combustible con sus umbrales de recarga/robo en cada vehículo; geocercas de taller fuera de mina | 2 días | El modal guarda los descansos y los vehículos del piloto tienen los sensores y umbrales asignados |
| B · Cálculo | Migraciones de las 3 tablas, `OperationShiftSummaryService` (horómetro y odómetro por sensor, estados READY/DELAY/STANDBY/MAINT, abastecimiento reutilizando las acciones de combustible del pipeline acotadas a la parada con motor apagado, estadísticas por hora y de flota), cambio automático del estado de vehículo al abrir cada tramo, comando `operations:clean` enganchado a `server:autoclean`, enganche en `operations:process` y en el recálculo | 7 a 8 días | El recálculo de una semana del piloto llena las tablas; horómetro, kilometraje y abastecimientos coinciden con el control manual del cliente |
| C · Pantalla «Turno» | Pestaña nueva con **todo en una vista**: tarjetas, cinco gráficas por hora (incluida máx/media/mín de flota con unidad y hora por punto), barra apilada de estados y las tres tablas | 5 a 6 días | El cliente ve en una sola pantalla lo mismo que en su tablero de Power BI |
| D · Exportación | Excel con el formato del cliente y **gráficos nativos iguales a la pantalla** (PhpSpreadsheet) | 3 a 4 días | El Excel descargado se abre con las mismas tablas y gráficas que la pantalla |
| E · Piloto y ajustes | Comparar un turno Día y uno Noche completos contra el control del cliente; ajustar umbrales (minutos para "rodando", descanso) | 2 días | Diferencias explicadas y aceptadas |

**Total estimado: 19 a 22 días** de un desarrollador. Orden: A → B → C → D → E. B puede
probarse con el recálculo sobre días reales antes de que exista la pantalla.

---

## 6. Respuestas del cliente (21-oct-2026)

Todas las preguntas quedaron respondidas; las decisiones están incorporadas en las secciones 3 y
4. Resumen:

| Tema | Decisión |
| ---- | -------- |
| Descanso | se configura en un apartado «Descansos» de la operación (programados con horario, y por fatiga y somnolencia sin horario); el cliente cargará Día 12:00–13:00 y Noche 02:00–03:00 |
| Día operativo | según los turnos que configure la operación (el cliente cargará Día 06:30–18:30 y Noche 18:30–06:30 para su margen de 30 min); inicio del día operativo configurable, sin horarios en el código |
| Hora inicial / final del volquete | primera y última vez que se mueve en el turno |
| DELAY | ralentí (motor encendido sin moverse) |
| STANDBY | descanso o motor apagado sin movimiento |
| MAINT | paso por geocerca de taller (fuera de mina) y registro manual desde la laptop (motivo de parada marcado como mantenimiento) |
| Motor apagado fuera de turno | gris «fuera de operación» para cerrar las 24 h |
| Volquete rodando | cuenta desde que se mueve |
| Cargado / vacío | cargado = carga → descarga, vacío = regreso |
| Abastecimiento | cisterna en campo, lugar dinámico: parada con motor apagado + subida del % del tanque al encender, con las mismas acciones que usan los reportes de combustible; umbrales de recarga y robo desde la configuración del vehículo; se muestra % al llegar, % al salir y recarga en % |
| Horómetro / kilometraje | sensores del vehículo; celda vacía si no lo tiene |
| Distancia | kilómetros totales del turno |
| Velocidad | OBD (FMC150), mínimo de movimiento 1 km/h; media solo con unidades en movimiento |
| Gráfica de flota | clic en un punto abre el historial de esa unidad |
| Pantalla | los 12 elementos en una sola vista, consulta bajo demanda sin refresco automático |
| Excel | con gráficas, iguales a las de la pantalla |
| Estado vacío / cargado de Seguridad | lo actualiza Operaciones al abrir cada tramo (añadido 21-oct) |
| Purga | con la Limpieza automática del historial de Admin → Herramientas, vía `server:autoclean` |

No queda nada pendiente de definir. Los umbrales de recarga y robo y el tiempo tras la parada
se toman del dispositivo (pestaña Precisión), igual que en los reportes de combustible.

---

## 7. Riesgos

- **Sin sensor de horas de motor ni odómetro** en el equipo, el horómetro y el kilometraje salen
  calculados (ignición y GPS); el cliente puede ver diferencias con el tablero del vehículo.
- **Tramas cada 30 s:** la velocidad por hora y el reparto de un tramo entre horas dependen de
  esa frecuencia; con reportes más espaciados la curva se aplana.
- **Volumen:** las tablas por hora crecen 24 filas por vehículo y día; con 50 vehículos son
  440 mil filas al año. Índice por `(operation_id, hour_at)`. **La purga no se configura aparte
  (decisión 21-oct):** se engancha a la «Limpieza automática del historial» de Admin →
  Herramientas (`settings('db_clear')`, días para guardar). El comando `server:autoclean` ya
  llama a `devices:clean`, `events:clean` y `server:reportlogclean`; se agrega
  `operations:clean {date}` que borra de las tablas de Operaciones (resúmenes de turno,
  estadísticas por hora, abastecimientos, y también vueltas, tramos, paradas y visitas) lo
  anterior a esa fecha, respetando los mismos días que las posiciones.
