# Plan de implementación — Módulo de Operaciones y control de vueltas

> Estado al 4 de octubre de 2026 (incluye turnos por rangos horarios). Plan acordado con Jhamner en una sesión de planificación.
> **Revisado contra el código el 4 de octubre de 2026**: se corrigieron la evaluación en vivo
> (ahora como las alertas, con estado en BD), permisos, registro de reportes, tipos de la base de
> datos, la pestaña En vivo (sin socket) y se añadió el paso 0 y la compatibilidad con el legacy.
> **Estado al 7-oct-2026 (rama `operaciones`):** fase 1 y fase 2 implementadas y probadas con
> datos reales del vehículo 219. Decisiones tomadas en la implementación: el borrado de geocercas
> y grupos en uso se bloquea; la primera versión de un punto vale para todo el pasado (así el
> recálculo sirve sobre días anteriores a crear la operación); los motivos con «autoriza» marcan
> la parada como autorizada y «Otro» solo la explica; la alerta «Parada en operación» usa
> `operation_stop_seconds` (segundos exactos) y se evalúa cada minuto.
> **11-oct-2026:** fase 3 implementada (descuentos en la operación, reportes 912 «Horas trabajadas
> netas» con consumo por hora y 913 «Abastecimiento»); el descuento «pala inactiva» queda en 0 hasta
> la fase 4. Además, tipo de operación «ruta» (inicio/fin) con las mismas reglas. Pendientes: fase 4,
> `round`→`floor` en la alerta de duración de parada.
>
> Cómo retomar: leer este archivo y `CLAUDE.md`, y empezar por «Orden dentro de la fase 1».
> Trabajar en una rama nueva: al momento de escribir esto la rama activa era `nuevo-tema` y tenía
> muchos archivos modificados sin confirmar.

---

## 1. Resumen

Un cliente necesita controlar volquetes y excavadoras en una operación de acarreo:

- Paradas no autorizadas, con alerta en tiempo real cuando superan 1 minuto.
- Cantidad de vueltas entre un punto de carga (A) y uno de descarga (B), con historial de llegadas
  y salidas, tiempos en cada punto y tiempos de ida y de vuelta.
- Paradas dentro de la ida o la vuelta, con duración, motor encendido o apagado y sustento.
- Ralentí entre A y B.
- Horas trabajadas descontando abastecimiento y, en excavadoras, el tiempo con la pala quieta.
- Galones por hora por equipo.
- Sensor de proximidad.

Se construye un módulo de **Operaciones** en la UI v2. Se activa por grupo de vehículos, así que
el resto de la flota no cambia.

| Fase | Qué entrega | Necesita código |
| --- | --- | --- |
| 0 · Piloto | Alertas de parada y ralentí y reportes que ya existen, configurados para el cliente | No |
| 1 · Vueltas | Categorías de geocerca, operaciones, turnos, conteo en vivo, historial, recálculo, tablero y reporte | Sí |
| 2 · Paradas | Paradas no autorizadas con motivo, alerta con contexto de tramo | Sí |
| 3 · Horas y combustible | Horas trabajadas netas, abastecimiento, galones por hora | Sí |
| 4 · Sensores | Movimiento de pala en excavadora, sensor de proximidad | Sí, más instalación |

Fuera del plan: pantallas del módulo en la interfaz anterior (legacy), la app móvil y la compra e
instalación de equipos. El legacy solo recibe la compatibilidad necesaria para no romperse (campo
de categoría en la geocerca y aviso al tocar un punto de operación).

---

## 2. Lo que ya existe en el código (contrastado con el código el 4-oct-2026)

- **Alerta de duración de parada**: `Tobuli/Helpers/Alerts/Check/StopDurationAlertCheck.php`.
  Mínimo 1 minuto (`stop_duration < 1` no dispara). La duración sale de `Device::getStopDuration()`
  (`traccar.moved_at`) y se **redondea** a minutos (`round($stopDuration / 60)`, línea 37): una parada
  de 30 s ya cuenta como 1 minuto. Dispara una sola vez por parada (`alert_device.fired_at < moved_at`).
  Filtro por zonas en `AlertCheck::checkZones()`: `alert.zone = 1` dentro de las geocercas elegidas,
  `2` fuera.
- **Alerta de ralentí**: `IdleDurationAlertCheck.php`, con `Device::getIdleDuration()`. Depende del
  sensor de motor: sin él, el motor se deduce de `speed > 0` y el ralentí casi nunca se da.
- **Frecuencia**: `alerts:check` (`app/Console/Commands/CheckAlertsCommand.php`) corre cada minuto
  desde `app/Console/Kernel.php` y atiende los tipos de `Alert::scopeCheckByTime`. Los tipos de
  `scopeCheckByPosition` se evalúan por trama en `PositionsWriter`.
- **`app/Console/PositionsWriter.php`** (2355 líneas) es la ruta caliente.
  - Se crea **una instancia nueva por IMEI y por lote** (`InsertCommand.php:148`). `insert:run` lanza
    hasta 30 procesos de unos 60 s; el lote siguiente del mismo vehículo puede caer en otro proceso.
    **Nada que viva en memoria sobrevive al lote.** Hay un bloqueo por IMEI de 60 s.
  - Un lote llega en orden de llegada, no por `time`.
  - En `proccess()`: filtros de calidad (`detectGnssGlitch` 596, `correctGhostSpeed` 639,
    `detectJumpFromStop` 642) → `setSensors()` 660 → **alertas** (662-664, solo si
    `checkableAlerts()`) → `addPosition` y `setHistoryRecalculation` para tramas atrasadas (666-672)
    → estado de `traccar` (675-689, solo si `!isHistory()`; `taskStatusChanger->process` en 688) →
    `forwards->process` (693) → integraciones peruanas (700-736, un `create()` por trama).
  - **Cómo evalúan las alertas**: una consulta por lote (`alerts()`, 1176-1215) y las relaciones
    pesadas en `Cache::store('array')`, invalidadas por `updated_at` (`cacheAlerts`, 1147-1174).
    `checkableAlerts()` (1217-1229) solo deja pasar tramas con `time >=` la hora guardada de
    `traccar`. `GeofenceAlertCheck` descarta `!isValid()` y toma como posición anterior
    `device->positionTraccar()` (última posición válida de `traccar`), no una variable en memoria.
    El estado que debe durar entre lotes va a BD (`alert_device`) o a Redis (`geofence_overspeed_start_*`).
  - `TaskStatusChanger` sigue el mismo patrón: una consulta por lote, estado en BD (`*_ac_started_at`),
    escribe solo en transiciones y descarta tramas con `time <=` su marca.
  - Con `overwrite_invalid` (activo por defecto) una trama inválida lleva las coordenadas de la
    última válida (599-606). Con `sat < 4` la posición se congela sin invalidarse (607-620).
  - En el punto de enganche la posición **aún no tiene `id`** (se inserta en `write()`); la velocidad
    ya está en km/h.
  - `history_recalculations` + `device_history:recalculate`: el comando **borra la fila** y solo
    recalcula `distance` y el odómetro virtual. No se puede compartir con otro consumidor.
  - Error existente: línea 721 usa `Log::info` sin `use` dentro de `App\Console`; revienta si el
    vehículo tiene Comsatel y el protocolo no es teltonika.
- **Geocercas**: `Tobuli/Entities/Geofence.php`. `type` está ocupado por la forma
  (`TYPE_CIRCLE`, `TYPE_POLYGON`, `TYPE_LINE`). `additional` es `TEXT` con cast json y se reemplaza
  entero al guardar (no sirve para la categoría). No usa `SoftDeletes`;
  `GeofenceService::delete()` hace `$geofence->delete()`. `Geofence::pointIn()` + `PolygonHelper`
  resuelven punto dentro de círculo, polígono o corredor en memoria. No existe un helper para «el
  segmento entre dos tramas cruza la geocerca».
  - FK en cascada hacia `geofences`: `alert_geofence`, `alert_zone`, `position_geofence`,
    `report_geofence_pivot`, `geofence_vehicle_speed_limits`, `route_checkpoints`.
    `events.geofence_id` es `set null`.
  - `geofences.user_id` es cascade: al borrar un usuario sus geocercas se borran en la BD sin
    eventos PHP.
  - `GeofenceMoveListener` mueve y guarda las geocercas con `device_id` en cada trama, sin pasar
    por el service.
  - `GeofencesExportController::$attributes` es `id, group_id, name, coordinates, polygon_color,
    type, radius, center, device_id` (no exporta `speed_limit`, pese a lo que dice `CLAUDE.md`).
  - `GeofenceImporter` llama a `store()` directo: se salta `normalize()` y la validación del service.
    Además exige `type in:polygon,circle` (un corredor aborta el import).
  - Error existente: el modal de metadatos de v2 convierte los corredores en polígonos
    (`live-zones.js:541-543`).
- **Grupos de vehículos**: son por usuario. `DeviceGroup::devices()` pasa por
  `user_device_pivot` (`group_id`, filtrado por `user_id`). `group_id` no tiene FK y vale 0 cuando
  no hay grupo; al borrar un grupo se pone 0 en el pivot.
- **Rutas con vueltas por horario**: `Tobuli/Services/RouteAdherenceService.php`, `RouteCheckpoint`
  (geocerca + dirección `outbound`/`inbound`), `RouteSchedule`, reporte 909. Calcula bajo demanda,
  no guarda nada y no toca `PositionsWriter`. Su `match()` (230-276) recorre posiciones y exige
  salir de la geocerca anterior antes de emparejar la siguiente: sirve de referencia para el recálculo.
- **Pipeline de historial** (`Tobuli/History`): `DeviceHistory` con `setConfig`, `setGeofences`,
  `registerActions`, `setRange`, `get()`.
  - Umbral de parada: `stop_seconds` y `stop_speed` (los reportes pasan `min_moving_speed`).
    «En marcha» exige **motor encendido y** velocidad sobre el umbral (`AppendMoveState.php:64`).
    Una parada más corta que `stop_seconds` queda dentro del tramo en marcha.
  - `GroupStop` da un grupo por parada con posición inicial y final. Cada grupo acumula las
    estadísticas registradas: `duration`, `distance` (km), `engine_idle` (`EngineHours`),
    `engine_off_duration` (`EngineOnOff`), `geofences_in` (`GeofencesIn`).
  - No hay acción que detecte huecos sin datos: `DriveStop` y `Duration` suman el hueco como
    parada. Lo más cercano es `AppendDuration` (`$position->duration`).
  - Combustible: `Fuel` + `AppendFuelConsumptions` (GPS, hora con `devices.fuel_per_h`, sensor de
    consumo, sensor de nivel).
- **Sensores**: `Tobuli/Sensors/Types/` tiene 35 tipos, entre ellos `FuelTank`, `FuelConsumption`,
  `EngineHours`, `Ignition`, `Engine`, `Acc`, `Logical`, `Counter`, `Load`. El sensor de motor del
  vehículo sale de `Device::getEngineDetect()` / `getEngineSensor()`.
- **Unidad galones**: existe. `Formatter/Unit/Capacity.php:22` trata la letra `g` como galón US
  (ratio 0.264172053); el usuario elige `lt` o `gl` (`unit_of_capacity`).
- **Reportes de turno actuales**: `GeofencesShiftReport` y similares piden `shift_start` y
  `shift_finish` como parámetros cada vez que se generan.
- **UI v2**: páginas en `resources/views/ui/v2/pages/*.blade.php` (extienden `ui.v2.layout`),
  controladores en `app/Http/Controllers/Ui/V2/`, JS y CSS en `resources/assets/ui-v2/{js,css}/`,
  compilados con `gulp ui-v2` (globs; la salida en `public/assets/ui/v2/` está versionada en git).
  Menú lateral en `resources/views/ui/v2/partials/rail.blade.php` (array `$items`).
  Rutas en el grupo `ui/v2` de `routes/web.php` (línea 1158, solo middleware `auth`).
  **La página modelo es Programaciones de ruta**: `pages/route-schedules.blade.php`,
  `RouteSchedulesController`, `tp-route-schedules.js`, `route-schedules.css`.
  - Todo se carga por AJAX con `tpModal.fetchJSON`; los modales se arman en JS con `tpModal.open`.
  - Cada usuario ve solo lo suyo (`where('user_id', $this->user->id)`); los ids que llegan del
    navegador se vuelven a cruzar con los del usuario.
  - **El socket no lleva posiciones** (solo `notice`, `message`, `device_camera_create`). El mapa
    en vivo sondea `devices/updates` cada 15 s.
  - El Historial solo acepta `?device=` y `?range=today|yesterday|week|month` (`history.js:1381`).
  - Geocercas v2: `ZonesController` + `live-zones.js` + el panel de `live.blade.php`.
- **Permisos**: `config/permissions.php` declara el módulo, pero `User::perm()` lee de
  `user_permissions` (o `billing_plan_permissions` si el usuario tiene plan) y devuelve `false` si
  no hay fila, también para el admin. El formulario de usuario del admin arma las casillas desde
  `permissions.list` (`PermissionService::getByUser`) y guarda las filas.
  `PermissionService::addToAll($name)` lo da a todos. Los permisos de un subusuario se cruzan con
  los de su gestor.
- **Reportes**: el último ID usado es 909; 910–913 están libres. El mapa ID → clase está en
  `Tobuli/Reports/ReportManager.php` (`$types`). La vista es `Reports/partials/type_<ID>.blade.php`.
  `ReportTypesConfig` solo guarda el interruptor global. `Report::isUserEnabled()` oculta un tipo
  nuevo a los usuarios que tienen lista propia en `user_report_type_pivot`.
- **Alertas**: un tipo nuevo se resuelve por nombre (`Checker.php:95-101` →
  `<Camel>AlertCheck`). Hay que añadirlo a `scopeCheckByPosition` o `scopeCheckByTime` de `Alert`,
  a `Event` (constante, título, icono), a `AlertModalHelper::getTypes()` /
  `getTypesWithAttributes()` y a `AlertFormValidator`. La UI v2 de alertas se adapta sola.
- **Base de datos**: las PK de `users`, `devices`, `geofences`, `device_groups`, `alerts` y `events`
  son `INT UNSIGNED` (`increments`). Las tablas nuevas usan `$table->id()` (BIGINT). Las horas GPS
  son `DATETIME` en UTC. Las posiciones están en otra conexión, una tabla por vehículo: no admiten FK.
- **Pruebas**: no hay `phpunit.xml`. `tests/Unit/GnssGlitchTest.php` pasa (14 pruebas) con
  `vendor/bin/phpunit --bootstrap vendor/autoload.php`; `GeofenceOverspeedDurationTest.php` falla
  en 2 de 5.

---

## 3. Cómo funciona

Una vuelta es un ciclo de cuatro estados. Cada cambio se guarda una sola vez, al entrar o salir de un punto.

```
En carga --sale--> Ida --llega--> En descarga --sale--> Vuelta --llega: vuelta +1--> En carga
```

1. El usuario marca sus geocercas con una categoría (carga, descarga, grifo, taller, descanso).
2. Crea una operación: nombre, grupo de vehículos, puntos de carga, puntos de descarga y turnos.
3. Cada trama pasa por `PositionsWriter`. Junto a las alertas corre un **`OperationChecker`**, que
   funciona igual que ellas: se carga una vez por lote, no guarda nada en memoria entre lotes y lee
   su estado de la tabla `operation_device_state`. Si el vehículo no tiene fila ahí, no se evalúa.
4. El `OperationChecker` compara la trama con los puntos de la operación. Al confirmarse una
   entrada o una salida escribe una visita. Al salir de un punto abre un tramo; al llegar al otro
   lo cierra.
5. Al cerrar el tramo de vuelta en un punto de carga, la vuelta queda completa y suma al contador.
6. Un comando por minuto (`operations:process`) toma los tramos **recién cerrados** y calcula, una
   sola vez por tramo, paradas, ralentí, motor apagado, distancia y tiempo sin datos con el
   pipeline de historial. Un tramo abierto nunca pasa por el pipeline.
7. Las tramas atrasadas no mueven el estado en vivo: marcan al vehículo en una cola propia para
   rehacer sus vueltas desde esa hora.
8. El tablero, el historial y los reportes leen las tablas ya calculadas; no recorren posiciones.

### El `OperationChecker`, paso a paso

- **Carga por lote.** La primera trama evaluable del lote hace una consulta por `device_id` a
  `operation_device_state` (índice único). Sin fila, el checker queda en `false` para el resto del
  lote, como `alertChecker`. Con fila, toma la configuración de la operación (puntos vigentes,
  umbrales) de `Cache::store('array')`, que se invalida comparando `operations.updated_at`, igual
  que `cacheAlerts`.
- **Qué tramas evalúa.** Solo las que pasan `checkableAlerts()` y `!isHistory()`, con
  `isValid()` y `sat >= 4`. Una trama con `valid = 0` no hace nada, como en las alertas de
  geocerca. Las de `sat < 4` se ignoran porque llevan coordenadas congeladas.
- **Tramas desordenadas.** Es el mismo criterio que usan las alertas: una trama con hora anterior
  a la última de `traccar` no se evalúa en vivo. En vez de eso alimenta la cola de recálculo.
- **Posición anterior.** `device->positionTraccar()` (última válida guardada en `traccar`), como
  `GeofenceAlertCheck`. No depende de `getPrevPosition()`.
- **Detección.** `pointIn()` contra la geometría guardada en cada punto. Si la trama anterior y la
  actual quedan fuera pero el segmento entre ambas cruza un punto, se registra una visita de paso
  (helper nuevo; no existe en el código).
- **Permanencia mínima.** La primera trama dentro (o fuera) guarda `candidate_point_id` y
  `pending_since` en la fila de estado. La transición se confirma cuando una trama posterior sigue
  igual y han pasado `min_dwell_seconds`. Si rebota, se limpia el candidato.
- **Escrituras.** Solo al abrir o limpiar un candidato y al confirmar una transición: unas ocho
  por vuelta. Cada una es un `UPDATE … WHERE state = <esperado>` para que repetir un lote no
  duplique nada. Sin escrituras por trama.
- **Sin `position_id`.** Las visitas guardan hora y coordenadas, no el id de la posición.
- **Quién mantiene `operation_device_state`.** `OperationService`, al guardar o activar una
  operación, crea una fila por vehículo del grupo. `operations:process` la sincroniza cada minuto
  con el grupo (altas y bajas en `user_device_pivot`), así que un vehículo que entra al grupo
  empieza a contar como mucho un minuto después.
- **Interruptor.** `config('tobuli.operations_enabled')` deja la llamada sin efecto.

### Lo que hace `operations:process` cada minuto

1. Sincroniza los vehículos de cada operación activa con su grupo.
2. Calcula los tramos cerrados que aún no tienen cálculo (una pasada del pipeline por tramo).
3. Marca «Sin datos» a los vehículos en tramo cuya última trama tiene más de 90 s (lee
   `traccar.time`; `PositionsWriter` no puede detectarlo porque solo corre cuando llega una trama).
4. Cierra como incompletos los tramos que superan `max_leg_hours`.
5. Atiende la cola de recálculos, con un máximo de vehículos por pasada.

Corre con `withoutOverlapping()`.

---

## 4. Reglas de negocio

Los valores por defecto se pueden cambiar en cada operación.

| Concepto | Definición | Por defecto |
| --- | --- | --- |
| Vuelta completa | Salida de carga, llegada a descarga, salida de descarga y llegada a carga. Se cuenta al llegar de regreso a carga | — |
| Viaje | Cada llegada válida a un punto de descarga. Se cuenta aparte | — |
| Llegada válida | Permanencia mínima dentro del punto. Evita contar doble por el rebote del GPS | 30 s |
| Salida válida | Permanencia mínima fuera del punto | 30 s |
| Tramo de ida | De la salida de carga a la llegada a descarga | — |
| Tramo de vuelta | De la salida de descarga a la llegada a carga | — |
| Parada | Motor apagado, o velocidad bajo `min_moving_speed` del equipo, durante más del umbral (así lo define el pipeline de historial) | 60 s |
| Parada autorizada | Dentro de una geocerca de categoría grifo, taller o descanso | Las tres |
| Parada no autorizada | Cualquier otra parada dentro de un tramo | — |
| Ralentí | Tiempo de parada con motor encendido | — |
| Motor apagado | Tiempo de parada con motor apagado | — |
| Tramo sin completar | No llega al otro punto en el tiempo máximo, o vuelve al mismo punto. No suma vuelta | 6 h |
| Día de la vuelta | Día calendario de la salida de carga, en la zona horaria del usuario | — |

- Una operación puede tener varios puntos de carga y varios de descarga. Cada vuelta registra cuáles usó.
- Un vehículo cuenta en una sola operación activa a la vez.
- Ralentí y motor apagado solo se distinguen si el equipo tiene elegido su sensor de ignición
  (`Device::getEngineDetect()`). Sin eso, la parada se guarda sin ese desglose.
- Una geocerca móvil (con vehículo asignado) no puede ser punto de una operación.
- El tiempo dentro de un punto no es parada: se reporta como tiempo en carga o en descarga.
- Los equipos del cliente transmitirán cada 30 s.

### Turnos

Cada operación tiene su lista de turnos. Cada turno es un nombre y un rango horario, y puede
cruzar la medianoche.

- **Configuración:** en el modal de la operación se agregan los turnos que haga falta. Ejemplo
  con dos: Día de 07:00 a 19:00 y Noche de 19:00 a 07:00. También sirven tres turnos de 8 horas.
- **Cruce de medianoche:** un turno cuya hora de fin es menor o igual a la de inicio termina al
  día siguiente. Noche de 19:00 a 07:00 es un solo turno.
- **Sin solapes:** dos turnos no pueden cubrir la misma hora. Sí puede haber huecos; una vuelta
  que sale en un hueco queda como «Fuera de turno».
- **Turno de la vuelta:** el del momento en que sale de carga. Si la vuelta cruza el cambio de
  turno, cuenta en el turno donde empezó.
- **Dos fechas por vuelta:** el día calendario (`work_date`; desde las 00:00 es otro día) y la
  fecha del turno (`shift_date`), que es el día en que ese turno empezó. Una vuelta que sale a las
  02:30 del 5 de octubre es del día 5 y del turno Noche del 4.
- **Reportes y pantallas:** se filtra por turno y se totaliza por día calendario o por turno
  completo. El total del turno Noche del 4 reúne de 19:00 del 4 a 07:00 del 5.
- **Cambios de turnos:** cada vuelta guarda el nombre de su turno (`shift_name`). Cambiar los
  rangos después no altera las vueltas cerradas; el recálculo las vuelve a asignar.
- Una operación sin turnos solo totaliza por día calendario.

Los reportes de turno actuales (`GeofencesShiftReport` y similares) piden la hora de inicio y fin
cada vez que se generan. Aquí los turnos quedan guardados en la operación.

### Pérdida de señal y GPS congelado

Hay cobertura en la ruta, pero el módulo no debe confundir una falla del equipo con una parada.

- **Sin datos**: si un vehículo en tramo deja de reportar más de 90 s, pasa a «Sin datos». Lo
  detecta `operations:process`, que corre cada minuto, así que el aviso aparece entre 90 y 150 s
  después de la última trama. Ese tiempo se guarda aparte (`gap_seconds`) y no cuenta como parada
  ni como ralentí.
- **GPS congelado**: si una parada termina con un salto de posición, se marca como «dato dudoso» y
  no cuenta como no autorizada hasta que alguien la revise. Reutiliza la marca `jump_from_stop`
  que `PositionsWriter` ya deja en la trama.
- La alerta existente de «sin conexión» se configura para el grupo de la operación.
- Al recuperar señal, el recálculo automático rehace el tramo con los datos completos.

### Justificación de paradas (fase 2)

- Justifica el **usuario de la plataforma** con permiso de edición en Operaciones. Queda guardado
  quién y cuándo.
- Elige un motivo de un **catálogo** por usuario, con lista inicial ampliable.
- **«Otro»** siempre está disponible y obliga a escribir el comentario. Con los demás motivos el
  comentario es opcional.
- Propuesta por confirmar: cada motivo indica si autoriza la parada; «Otro» la explica pero no la
  autoriza. Sin plazo para justificar; cada cambio guarda quién lo hizo.
- Lista inicial propuesta: tráfico o vía bloqueada, espera en cola, falla mecánica, neumático,
  indicación de supervisión o seguridad, alimentación, clima y otro.

---

## 5. Base de datos

Una columna en `geofences` y diez tablas nuevas. Todas las migraciones son aditivas. Estilo
Laravel 8 con clase anónima y guardas `Schema::hasTable` / `hasColumn`, como las de 2026.

| Tabla | Fase | Para qué | Columnas principales |
| --- | --- | --- | --- |
| `geofences.category` | 1 | Uso de la geocerca | `string(20)`, opcional, con índice |
| `operations` | 1 | La operación y su configuración | `user_id`, `name`, `device_group_id` (opcional), `active`, `min_dwell_seconds`, `stop_threshold_seconds`, `max_leg_hours`, `authorized_categories` (texto JSON), `timestamps`, `deleted_at` |
| `operation_shifts` | 1 | Turnos de la operación | `operation_id`, `name`, `start_time`, `end_time` (`time`) |
| `operation_points` | 1 | Puntos, una fila por versión de su ubicación | `operation_id`, `geofence_id` (opcional), `role` (carga o descarga), `name`, `geometry` (texto JSON: tipo, coordenadas, centro, radio), `active_from`, `active_to` |
| `operation_device_state` | 1 | Estado en vivo de cada vehículo | `device_id` (único), `operation_id`, `state`, `current_point_id`, `candidate_point_id`, `pending_since`, `open_visit_id`, `open_leg_id`, `open_cycle_id`, `no_data_since` |
| `operation_visits` | 1 | Cada entrada y salida de un punto | `operation_id`, `operation_point_id`, `device_id`, `role`, `entered_at`, `exited_at`, `passing` (visita de paso) |
| `operation_cycles` | 1 | Cada vuelta | `operation_id`, `device_id`, `work_date`, `shift_id`, `shift_name`, `shift_date`, `number`, `started_at`, `ended_at`, `status`, `load_seconds`, `unload_seconds`, `duration_seconds` |
| `operation_legs` | 1 | Cada tramo | `cycle_id`, `device_id`, `direction`, `from_point_id`, `to_point_id`, `started_at`, `ended_at`, `status`, `calculated_at`, `duration_seconds`, `distance_meters`, `stop_seconds`, `idle_seconds`, `engine_off_seconds`, `gap_seconds`, `stops_count`, `unauthorized_stops_count` |
| `operation_stops` | 1 | Cada parada de un tramo | `leg_id`, `device_id`, `started_at`, `ended_at`, `duration_seconds`, `latitude`, `longitude`, `address`, `engine_on_seconds`, `engine_off_seconds`, `geofence_name`, `authorized`, `doubtful` |
| `operation_recalculations` | 1 | Cola de recálculos | `operation_id`, `device_id`, `from_time`, `use_current_points`, `status`, `requested_by` |
| `operation_stop_reasons` | 2 | Catálogo de motivos | `user_id`, `name`, `authorizes` |

- Fase 2: `operation_stops` gana `reason_id`, `note`, `justified_by`, `justified_at`.
- Fase 3: `operations` gana `worked_hours_deductions` (descuentos que activa el usuario).

### Tipos y claves

- PK de las tablas nuevas: `$table->id()`.
- FK hacia tablas antiguas (`user_id`, `device_id`, `geofence_id`, `device_group_id`,
  `requested_by`, `justified_by`): **`unsignedInteger`**. No usar `foreignId()`: crea BIGINT y la
  FK falla.
- FK entre tablas `operation_*`: `unsignedBigInteger`.
- Horas (`entered_at`, `started_at`, `pending_since`, `from_time`, `active_from`…): **`dateTime`
  en UTC**, nunca `timestamp` (ya hubo un `ON UPDATE CURRENT_TIMESTAMP` accidental en
  `device_expenses`). `work_date` y `shift_date`: `date`.
- `status`, `state`, `role`, `direction`: `string(20)`.
- `latitude` / `longitude`: `double`, como `events`.
- `geometry` es texto con el mismo formato que `geofences.coordinates`; el cálculo es en PHP con
  `PolygonHelper`. Sin columnas ni índices espaciales: producción es MariaDB 10.5 y desarrollo es MySQL 8, y las
  migraciones deben correr igual en los dos.
- Nombres de índice explícitos cuando el automático pase de 64 caracteres.

### Qué pasa al borrar

| Se borra | Efecto en las tablas nuevas |
| --- | --- |
| Vehículo (borrado físico) | `device_id` en cascade en todas las tablas `operation_*` |
| Usuario | `operations.user_id` y `operation_stop_reasons.user_id` en cascade; `requested_by` y `justified_by` en `set null` |
| Grupo de vehículos | **Bloqueado**: no se puede borrar un grupo que usa una operación; primero hay que quitarlo de la operación o eliminarla. La FK queda en `set null` solo como red de seguridad (borrado del usuario dueño) |
| Geocerca | **Bloqueado**: no se puede borrar una geocerca que es punto vigente de una operación; primero hay que quitarla de la operación. `GeofenceService::delete()` lanza un error de validación con el nombre de la operación, y eso cubre v2, legacy (también el borrado masivo) y API. La FK queda en `set null` para el único camino que no pasa por PHP: borrar al usuario dueño |
| Punto de operación | `from_point_id`, `to_point_id`, `current_point_id`, `candidate_point_id` en `set null` |
| Turno | `operation_cycles.shift_id` en `set null`; quedan `shift_name` y `shift_date` |
| Operación | Borrado lógico (`deleted_at`). Las FK no se disparan: todo lo que lee operaciones activas filtra `whereNull('deleted_at')` |

### Índices

| Tabla | Índices |
| --- | --- |
| `operation_device_state` | único `(device_id)`; `(operation_id)` |
| `operation_cycles` | `(operation_id, work_date)`, `(operation_id, shift_date, shift_id)`, `(device_id, started_at)` |
| `operation_legs` | `(cycle_id)`, `(device_id, started_at)`, `(status, calculated_at)` para la cola de cálculo |
| `operation_stops` | `(leg_id)`, `(device_id, started_at)` |
| `operation_visits` | `(device_id, entered_at)`, `(operation_id, entered_at)` |
| `operation_points` | `(operation_id, active_to)`, `(geofence_id)` |
| `operation_recalculations` | único `(operation_id, device_id, status)` para acumular con `LEAST(from_time)`; `(status)` |

### Tres decisiones protegen el historial

- **Los puntos guardan su propia copia de la ubicación.** El checker compara contra
  `operation_points.geometry`, no contra la geocerca viva. Al mover una geocerca se cierra la
  versión vigente (`active_to`) y se abre una nueva.
- **Una geocerca en uso no se puede borrar.** Hay que quitarla antes de la operación; el punto
  retirado conserva su nombre y ubicación para las vueltas pasadas. Nada se borra en cascada desde
  `geofences`: si la geocerca desaparece por el borrado de su usuario (que ocurre en la BD sin
  pasar por PHP), el punto queda con `geofence_id` vacío.
- **Las operaciones usan borrado lógico** (`deleted_at`). Sus vueltas siguen en los reportes.

Una vuelta genera 1 fila de ciclo, 2 de tramo, 2 de visita y unas pocas de parada. No se guarda
nada por trama.

`work_date` y `shift_date` se calculan con el `Formatter` del dueño de la operación (desfase de
`timezones` más horario de verano), no con `date()`.

---

## 6. Cambios en el código

### Paso 0: correcciones previas (errores que ya existen y chocan con el módulo)

| Archivo | Qué se corrige |
| --- | --- |
| `app/Console/PositionsWriter.php:721` | `Log::info` sin importar; usar `\Log::info` |
| `resources/assets/ui-v2/js/live-zones.js:541-543` | El modal de metadatos convierte los corredores en polígonos; conservar `type = line` |
| `Tobuli/Importers/Geofence/GeofenceImporter.php:156` | Aceptar `type = line` |
| `Tobuli/Helpers/Alerts/Check/StopDurationAlertCheck.php:37` | `round()` por `floor()`: hoy una alerta de 1 minuto puede saltar a los 30 s. Cambia a todos los clientes que usan esta alerta: avisará hasta 30 s más tarde que ahora (pendiente de confirmar, ver §14) |
| `app/Http/Controllers/Frontend/DevicesGroupsController.php` (`destroy`) y su equivalente v2 | Rechazar el borrado de un grupo que usa una operación |
| `phpunit.xml` (nuevo) y `tests/Unit/GeofenceOverspeedDurationTest.php` | Solo si se decide tener pruebas automáticas (ver §14) |

### Archivos que se modifican

| Archivo | Qué cambia | Fase |
| --- | --- | --- |
| `app/Console/PositionsWriter.php` | Una llamada a `operations()` junto a las alertas (después de la línea 664), con la misma estructura que `alerts()`. En 669-671, las tramas atrasadas de un vehículo en operación acumulan un recálculo que se escribe en `write()` | 1 |
| `Tobuli/Entities/Geofence.php` | `category` en `$fillable` y `$filterables`, constantes `CATEGORY_*` y lista `CATEGORIES`, `scopeCategory()` | 1 |
| `Tobuli/Services/GeofenceService.php` | `normalize()`: `category` vacía pasa a null. `update()` (no `edit()`, para cubrir v2, legacy, API y `DiemRateService`): versiona los puntos de operación que usan la geocerca. `delete()`: rechaza el borrado si la geocerca es punto vigente de una operación. Método de consulta «¿en qué operaciones se usa?» | 1 |
| `Tobuli/Validation/GeofenceFormValidator.php` | Regla `nullable\|in:` para `category` en `create` y `update` | 1 |
| `Tobuli/Importers/Geofence/GeofenceImporter.php` | Misma regla en `getValidationBaseRules()` y vacío a null en su `normalize()` (el importador se salta el service) | 1 |
| `app/Http/Controllers/Ui/V2/ZonesController.php` | `category` en las listas explícitas de `data()` y `presentZone()`; catálogo de categorías en el payload; confirmación al mover un punto de operación activa; muestra el motivo cuando el borrado se rechaza | 1 |
| `resources/assets/ui-v2/js/live-zones.js` | Selector en `metaBody()`, envío en `readMeta()` (siempre, para poder vaciarla), filtro en `S.filtered` y `wire()` | 1 |
| `resources/views/ui/v2/pages/live.blade.php` | Filtro de categoría junto a `#tp-zones-type-filter` (338-353) | 1 |
| `app/Http/Controllers/Frontend/GeofencesExportController.php` | `category` en `$attributes` | 1 |
| `Tobuli/Views/Frontend/Geofences/create.blade.php`, `edit.blade.php` | **Compatibilidad legacy**: campo «Categoría» | 1 |
| `app/Http/Controllers/Frontend/GeofencesController.php` | **Compatibilidad legacy**: aviso al mover una geocerca que es punto de una operación activa; muestra el motivo cuando el borrado se rechaza (en el borrado masivo, las que están en uso se saltan y se listan) | 1 |
| `routes/web.php` | Rutas del módulo en el grupo `ui/v2` (línea 1158): página `operations` y API `api/operations/*` | 1 |
| `resources/views/ui/v2/partials/rail.blade.php` | Entrada «Operaciones» en `$items`, con `can => perm('operations','view')` | 1 |
| `config/permissions.php` | Módulo `operations` (ver, editar, eliminar) en `list` | 1 |
| `app/Console/Kernel.php` | `operations:process` cada minuto, `withoutOverlapping()` | 1 |
| `Tobuli/Reports/ReportManager.php` | Registro de los reportes nuevos en `$types` | 1 a 3 |
| `resources/assets/ui-v2/js/history.js` | `init()` (1381) acepta `from` y `to` con hora, para «Ver en mapa» | 1 |
| `resources/assets/ui-v2/js/reports.js` | Extraer `postDescarga()` a un helper compartido para el botón «Exportar» | 1 |
| `resources/lang/{es,en}/ui.php`, `front.php`, `validation.php` | Textos nuevos (`front.operations` para la casilla del permiso, `ui.category`, etiquetas de las categorías, `validation.attributes.category`) | 1 a 3 |
| `resources/views/ui/v2/partials/i18n.blade.php` | Claves de `front`/`global` que use el JS nuevo | 1 |
| `Tobuli/Entities/Alert.php`, `Event.php`, `ModalHelpers/AlertModalHelper.php`, `Tobuli/Validation/AlertFormValidator.php` | Tipo de alerta «Parada en operación» (en `scopeCheckByTime`) | 2 |
| `public/assets/ui/v2/{js,css}/` | Salida de `gulp ui-v2`; va en el mismo commit que las fuentes | 1 a 3 |

### Archivos nuevos

| Archivo | Qué hace | Fase |
| --- | --- | --- |
| `database/migrations/2026_10_*_operations_*.php` | Columna y tablas de la sección 5 | 1 y 2 |
| `Tobuli/Entities/Operation*.php` | Operación, punto, turno, estado, visita, vuelta, tramo, parada, motivo | 1 y 2 |
| `Tobuli/Services/Operations/OperationChecker.php` | Evaluación por trama, con la forma de `Checker` de alertas: se construye por lote con el vehículo y su fila de estado, `check($position)` aplica las reglas de la sección 3 | 1 |
| `Tobuli/Services/Operations/OperationCycleWriter.php` | Registra visitas, tramos y vueltas a partir de las transiciones. Lo usan el checker (en vivo) y el recálculo (historial), para que ambos den el mismo resultado | 1 |
| `Tobuli/Services/Operations/OperationLegCalculator.php` | Paradas, ralentí, motor apagado, distancia y tiempo sin datos de un tramo cerrado, con el pipeline de historial | 1 |
| `Tobuli/History/Actions/NoDataDuration.php` | Acción nueva: suma los huecos de más de 90 s entre tramas | 1 |
| `Tobuli/Services/Operations/OperationRebuildService.php` | Rehace las vueltas de un vehículo desde una fecha recorriendo sus posiciones | 1 |
| `Tobuli/Services/Operations/OperationService.php` | Alta, edición y baja (extiende `ModelService`); crea y sincroniza `operation_device_state` | 1 |
| `app/Console/Commands/OperationsProcessCommand.php` | `operations:process` (ver sección 3) | 1 |
| `app/Http/Controllers/Ui/V2/OperationsController.php` | Página y API | 1 |
| `resources/views/ui/v2/pages/operations.blade.php` | Página con tres pestañas | 1 |
| `resources/assets/ui-v2/js/tp-operations.js`, `css/operations.css` | Comportamiento y estilos | 1 |
| `Tobuli/Reports/Reports/OperationCyclesReport.php` + `Reports/partials/type_910.blade.php` | «Control de vueltas» (ID 910) | 1 |
| `Tobuli/Reports/Reports/OperationStopsReport.php` + `type_911.blade.php` | «Paradas en operación» (ID 911) | 2 |
| `Tobuli/Helpers/Alerts/Check/OperationStopAlertCheck.php` | Alerta de parada con contexto | 2 |
| `Tobuli/Reports/Reports/OperationWorkHoursReport.php`, `RefuelingVisitsReport.php` + vistas | Horas netas y abastecimiento (IDs 912 y 913) | 3 |
| `tests/Unit/OperationCheckerTest.php` y otros | Pruebas del checker y del recálculo | 1 |

### Cálculo de un tramo cerrado (`OperationLegCalculator`)

```
setConfig(['stop_seconds' => operación.stop_threshold_seconds, 'stop_speed' => device.min_moving_speed])
setGeofences(geocercas de categorías autorizadas del dueño)
registerActions([DriveStop, Duration, Distance, EngineHours, EngineOnOff, GeofencesIn, NoDataDuration, GroupStop])
setRange(tramo.started_at, tramo.ended_at)
```

- Por cada grupo `stop`: inicio, fin y coordenadas de `getStartPosition()` / `getEndPosition()`;
  `duration`, `engine_idle` (ralentí) y `engine_off_duration` (motor apagado) de sus estadísticas;
  `geofences_in` decide si es autorizada.
- `distance` del total va a `distance_meters` (el pipeline la da en km).
- El pipeline exige motor encendido para considerar que el vehículo se mueve. Por eso el vehículo
  debe tener bien elegido el sensor de ignición. Si `Device::getEngineDetect()` es null, la parada
  se guarda sin desglose de ralentí y motor apagado.
- Una parada que termina con `jump_from_stop` en la trama siguiente se marca `doubtful`.

### Cuánto cuesta en `PositionsWriter`

- Vehículo fuera de operación: una consulta indexada por lote (no por trama), igual que la de
  alertas.
- Vehículo en operación: esa consulta, más comprobaciones de punto en geocerca en memoria por
  trama, más una escritura solo cuando cambia el candidato o se confirma una transición.
- Con el interruptor apagado no hay ninguna consulta.
- Los cálculos pesados corren fuera, en `operations:process`.

### Cómo se evita recalcular de más

- El pipeline corre **una vez por tramo, al cerrarse**. El tramo abierto no se calcula.
- En la pestaña En vivo, «Detenido» y «Ralentí» del tramo abierto salen de `traccar`
  (`getStopDuration()`, `getIdleDuration()`), que `PositionsWriter` ya mantiene. Los totales del
  día suman los tramos cerrados más ese valor en curso.
- Un tramo dura como mucho `max_leg_hours`; a una trama cada 30 s son unas 720 posiciones por
  cada 6 horas.
- El recálculo va por cola, con tope de vehículos por pasada, y solo rehace desde `from_time`.
- Las tramas atrasadas del mismo vehículo se acumulan en una sola fila (`LEAST(from_time)`).

### Permisos y visibilidad

- **Módulo.** Con `operations` en `config/permissions.php` y la etiqueta `front.operations`, la
  casilla aparece sola en el formulario de usuario del admin y en los planes. No hay migración de
  datos en el piloto: nadie lo tiene hasta que el admin lo marca en los usuarios del piloto (y en
  su gestor, si son subusuarios). Para abrirlo a todos más adelante:
  `PermissionService::addToAll('operations')` en una migración.
- **Reportes 910–913.** Sobrescriben `isUserEnabled($user)` para devolver
  `isEnabled() && $user->perm('operations', 'view')`. Así el reporte aparece a quien tiene el
  módulo, tenga o no lista propia en `user_report_type_pivot`, y no aparece a los demás.
- **Visibilidad.** Cada usuario ve solo sus operaciones (`where('user_id', …)`), como en
  Programaciones de ruta. El admin entra como el usuario para verlas.
- El controlador usa un `gate($action)` con `abort(403)`; la página pasa `data-can-edit` y
  `data-can-remove` por separado.

### Categoría de geocerca: qué no se toca y por qué

- **API v1** (`Api\V1\GeofencesController`): devuelve el modelo completo, así que expone
  `category` sola y acepta `?category=` por `$filterables`. Cambio aditivo.
- **Legacy**: el formulario gana el campo (compatibilidad). Las listas con `select` explícito
  siguen igual.
- **GeoJSON y GEXP**: `category` pasa sola una vez que está en `$fillable` y en la regla del
  importador. **KML**: la categoría se pierde (solo lee nombre, estilo y coordenadas).
- `GeofenceMapTransformer` y la API ClientLite tienen listas explícitas y no la exponen; no hace falta.

### Versionado de puntos al editar geocercas

- La geometría se compara **numéricamente** antes y después del `update`. En círculos solo
  cuentan `center` y `radius` (el legacy reescribe `coordinates` en cada guardado).
- Una geocerca con `device_id` (geocerca móvil) **no puede ser punto de operación**: se mueve en
  cada trama.
- Activar o desactivar una geocerca no cambia los puntos: el punto tiene su propia copia.

---

## 7. Pantallas (solo UI v2)

Página nueva **Operaciones** en `/ui/v2/operations`, con tres pestañas. Sigue el patrón de
Programaciones de ruta.

Patrón a seguir (el de `route-schedules`):

- Vista `@extends('ui.v2.layout')` con `rail-active = operations`, `workspace-class =
  tp-workspace--full`, `services.css` + `operations.css` en `@push('head')` y los scripts con
  `defer` en `@push('scripts')`.
- Raíz `div.tp-maint#tp-ops-page` con las URLs en `data-*`, y `data-can-edit` / `data-can-remove`.
- Pestañas con `role="tablist"`, paneles `<section data-ops-panel>`, tablas `tp-svc-table`.
- Todo por AJAX con `tpModal.fetchJSON`: primero `meta`, luego los listados
  (`{ok, items, page, pages, total}`). Modales armados en JS con `tpModal.open`; errores de
  validación (422) con `tpModal.showFieldErrors`.
- Vehículos con `tpChipsSelect`; selects con `tp-select` (`tpSelect.refresh` tras cambiar opciones).
- Fechas: el JS envía `Y-m-d` en hora del usuario; el servidor convierte con
  `Formatter::time()->reverse()`. «Hoy» se calcula en hora local, no con `toISOString()`.
- Textos en `resources/lang/{es,en}/ui.php`, leídos con `tpT()`. Sin textos fijos en el JS.
- Accesibilidad: todo control con `<label>` o `aria-label`; botones de solo icono con `aria-label`.

| Pantalla | Para qué | Fase |
| --- | --- | --- |
| Formulario de geocerca (existente) | Campo «Categoría» y filtro por categoría | 1 |
| Operaciones · En vivo | Dónde está cada vehículo del ciclo y cuántas vueltas lleva hoy | 1 |
| Operaciones · Vueltas | Historial de vueltas por fecha, con tramos y paradas | 1 |
| Operaciones · Operaciones | Lista; crear, editar, activar y desactivar | 1 |
| Modal de operación | Nombre, grupo, puntos, turnos, umbrales | 1 |
| Modal «Recalcular vueltas» | Rehacer vueltas desde una fecha | 1 |
| Modal «Justificar parada» | Motivo y comentario | 2 |
| Catálogo de motivos | Administrar motivos | 2 |

### En vivo

- Barra: selector de operación, fecha (hoy), indicador con la hora de la última actualización.
- Cuatro tarjetas: Vueltas hoy · Ciclo promedio · Vehículos en ciclo · Paradas no autorizadas.
- Tabla, una fila por vehículo del grupo: Vehículo · Estado · Vueltas · Último ciclo · Detenido ·
  Ralentí · No autorizadas.
- El estado es una píldora de color por etapa: En carga, Ida, En descarga, Vuelta, Detenido en ida
  o vuelta (rojo si está fuera de zona autorizada), Sin datos. Se actualiza sondeando
  `api/operations/live` cada 15 a 30 s, solo con la pestaña visible, como hace el mapa en vivo. El
  socket no transporta posiciones.
- Esa petición no calcula vueltas: lee el estado ya guardado (`operation_device_state`), cuenta
  las vueltas del día en `operation_cycles` y trae el estado de `traccar` de esos vehículos. Las
  vueltas se detectan en `PositionsWriter`, haya o no alguien mirando la pantalla.
- «Detenido» y «Ralentí» del tramo en curso salen del estado de `traccar`; el resto, de los tramos
  ya calculados.
- Un clic en la fila abre sus vueltas del día en la pestaña Vueltas.

### Vueltas

- Filtros: rango de fechas, operación, vehículo, turno, y casilla «Solo vueltas con paradas no
  autorizadas». Botón «Exportar»: genera el reporte Control de vueltas (910) con esos filtros,
  enviando el formulario a `reports/update` como hace la página de Reportes.
- Tabla, una fila por vuelta: N.º · Vehículo · Salida · Ciclo · Ida · Vuelta · Detenido · Ralentí ·
  No autorizadas.
- Al desplegar una fila se ven sus dos tramos (origen a destino, horas, duración, distancia) y las
  paradas de cada uno: hora, duración, motor encendido o apagado, geocerca si la hay, autorizada o no.
- Por parada: «Ver en mapa» abre el recorrido en la página de Historial con el rango cargado
  (`?device=&from=&to=`; hay que ampliar `history.js`, que hoy solo acepta rangos fijos).
  «Justificar» llega en la fase 2.

### Modal de operación

- Campos: Nombre · Grupo de vehículos (muestra cuántos tiene y avisa si alguno ya está en otra
  operación activa) · Puntos de carga y Puntos de descarga (chips, «+ Agregar punto», geocercas de
  categoría carga y descarga primero) · Turnos (lista con nombre, inicio y fin) · Opciones avanzadas (parada 60 s,
  permanencia 30 s, tramo máximo 6 h) · Operación activa.
- Al guardar un cambio de puntos pregunta si recalcular las vueltas pasadas o aplicar solo hacia adelante.
- Fase 3: bloque «Horas trabajadas» con cuatro casillas de descuento: abastecimiento, ralentí,
  paradas no autorizadas y pala inactiva.

### Permisos

Permiso propio `operations` con ver, editar y eliminar. El menú y cada botón se muestran según ese
permiso. Lo asigna el admin en el formulario del usuario; mientras no lo marque, nadie ve el
módulo. Cada usuario ve solo sus operaciones; el admin entra como el usuario para verlas.

---

## 8. Alertas y reportes

| Alerta | Estado | Cómo funciona |
| --- | --- | --- |
| Duración de parada | Ya existe; es la que pide el cliente (aviso y evento al detenerse 1 minuto) | Mínimo 1 minuto, filtro «fuera de geocercas». Genera un evento por parada. Se evalúa cada minuto. Hoy redondea y puede avisar desde los 30 s; con la corrección del paso 0 (`floor`) avisa cuando la parada ya cumplió 1 minuto, es decir entre 60 y 120 s después de la última trama en movimiento |
| Duración de ralentí | Ya existe | Motor encendido sin movimiento. Necesita el sensor de ignición bien elegido en el vehículo |
| Parada en operación | Fase 2 | Tipo nuevo en `scopeCheckByTime` (cada minuto, como `stop_duration`). Lee `operation_device_state`: solo vehículos con tramo abierto, fuera de zona autorizada. Umbral en segundos, sin redondeo. El mensaje incluye operación, tramo y número de vuelta |

| Reporte | Fase | Qué muestra |
| --- | --- | --- |
| Control de vueltas (910) | 1 | Por vehículo y día: número de vuelta, horas de salida y llegada en cada punto, tiempo en carga, ida, tiempo en descarga, vuelta, ciclo total, detenido, ralentí, motor apagado y paradas no autorizadas. Totales por día calendario o por turno completo: vueltas, viajes y ciclo promedio |
| Paradas en operación (911) | 2 | Una fila por parada: vehículo, vuelta, tramo, inicio, duración, ubicación, motor, autorizada o no, motivo, quién la justificó |
| Abastecimiento (913) | 3 | Visitas a geocercas de categoría grifo: entrada, salida y duración. Solo con la visita, sin medir galones |
| Horas trabajadas netas (912) | 3 | Horas de motor menos los descuentos que el usuario active en la operación |
| Consumo por hora | 3 | Galones por hora de motor, por equipo, en todo vehículo con sensor de combustible |

Cada reporte se registra en `ReportManager::$types`, tiene su vista `type_<ID>.blade.php` y se
muestra según el permiso `operations` (ver sección 6). La página de reportes v2 lo lista sola.

La fase 3 se implementa para cualquier equipo y se activa según los sensores del vehículo:

- Con **sensor de combustible** (consumo o nivel de tanque) calcula galones y galones por hora con
  la estadística `Fuel` del pipeline. Sin él, esas columnas salen vacías.
- Con **sensor de horas de motor** usa ese dato. Sin él, calcula por tiempo con ignición encendida
  (`EngineHours`).
- Los galones salen de `Formatter::capacity()` con la unidad del usuario (`gl` = galón US).
  Por comprobar con un equipo real: el cálculo asume que el sensor entrega el valor en la unidad
  de capacidad del usuario.
- El consumo por nivel de tanque no descuenta robos: una caída por robo cuenta como consumo.

---

## 9. Historial y recálculo

Las vueltas cerradas no cambian al mover, crear o eliminar puntos. Solo cambian con un recálculo.

| Acción | Vueltas anteriores | Hacia adelante |
| --- | --- | --- |
| Mover un punto | Se conservan con la ubicación que tenían | Se cuenta con la ubicación nueva |
| Agregar un punto | Se conservan | Empieza a contar desde ese momento |
| Quitar un punto | Se conservan, con el nombre del punto | Deja de contar |
| Eliminar la geocerca | Se conservan | No se permite mientras sea punto de una operación; primero hay que quitarla |
| Sacar un vehículo del grupo | Se conservan | Deja de contar; una vuelta abierta queda incompleta |
| Desactivar o eliminar la operación | Se conservan y siguen en reportes | Deja de contar |

### Recálculo manual (fase 1)

1. El administrador elige operación, vehículos y fecha desde la que recalcular.
2. Elige la ubicación de los puntos: **la vigente en cada momento** (por defecto) o **la actual**
   (cuando el punto estaba mal dibujado).
3. La pantalla muestra cuántas vueltas se reemplazan y pide confirmación.
4. Entra a la cola `operation_recalculations`, que procesa `operations:process` fuera de
   `PositionsWriter`. No se reutiliza `history_recalculations`: su comando borra la fila y solo
   recalcula distancias.
5. Se reemplazan vueltas, tramos, visitas y paradas del periodo. Las justificaciones se vuelven a
   enlazar por vehículo y hora; las que no encuentren su parada se listan para revisión.

El recálculo automático por tramas atrasadas usa el mismo mecanismo, con la ubicación vigente.
El recálculo y el conteo en vivo comparten `OperationCycleWriter`, para dar el mismo resultado.

Límite: solo se recalcula hasta donde existan posiciones. La conservación se configura en los
ajustes de la plataforma (`db_clear`, mínimo 30 días) y la limpieza es diaria. Las vueltas guardadas no se borran con esa
limpieza. El modal no deja elegir una fecha anterior a ese rango.

---

## 10. Control de la pala en excavadoras (fase 4)

El GPS solo no ve si la pala se mueve. Los volquetes tienen FTC927 (1 entrada digital, 1 analógica,
1 salida, Bluetooth, sin CAN). Falta saber qué GPS llevan las excavadoras.

| Opción | Instalación | Entrega | A favor | En contra |
| --- | --- | --- | --- | --- |
| EYE Sensor por Bluetooth | Fijado al brazo, cerca del cucharón, enlazado al FTC927 | Indicador y contador de movimiento | Sin cables ni intervención hidráulica; IP67 | La vibración del motor en ralentí puede marcar movimiento; hay que probarlo |
| Presostato en la línea piloto | En la línea hidráulica de mando, a la entrada analógica | Encendido o apagado al accionar los mandos | Mide el accionamiento real | Intervenir el circuito hidráulico; la entrada digital suele usarse para ignición |

En la plataforma: sensor «Pala en movimiento» en el vehículo. Pala inactiva = motor encendido y
sensor sin movimiento más de un umbral (propuesto 60 s). Se descuenta de las horas netas si el
usuario activó ese descuento.

Piloto: una excavadora, dos días, contra observación en campo. El EYE permite ajustar los segundos
sin movimiento antes de marcar «detenido» (0 a 300 s). Si hay falsos movimientos, usar el cambio de
ángulo del brazo o pasar al presostato. Por confirmar con Teltonika si el FTC927 transmite el
ángulo del EYE; su documentación lista temperatura, humedad, imán y movimiento.

Fuentes: <https://wiki.teltonika-gps.com/view/FTC927_Bluetooth>,
<https://wiki.teltonika-gps.com/images/e/e3/DS-FTC927.pdf>,
<https://wiki.teltonika-gps.com/view/EYE_SENSOR_/_BTSMP1>.

---

## 11. Fases y orden de trabajo

El esfuerzo es una estimación en días de un desarrollador, sin contar pruebas con el cliente.

| Fase | Entregables | Depende de | Esfuerzo | Terminada cuando |
| --- | --- | --- | --- | --- |
| 0 · Piloto | Geocercas de carga, descarga y grifo; sensor de ignición elegido en cada vehículo; alerta de parada de 1 minuto fuera de zonas; alerta de ralentí; reportes actuales | Nada | 1 a 2 días de configuración | El cliente recibe alertas, los equipos reportan cada 30 s y el ralentí se distingue del motor apagado |
| 1 · Vueltas | Todo lo marcado como fase 1, más el paso 0 | Fase 0 para tener datos reales | 14 a 18 días | El conteo de una semana coincide con el control manual del cliente |
| 2 · Paradas | Motivos, justificación, alerta «Parada en operación», reporte de paradas | Fase 1 | 6 a 8 días | Un usuario justifica paradas y el reporte las muestra |
| 3 · Horas y combustible | Abastecimiento, horas netas, consumo por hora | Fase 1; sensores configurados en el vehículo | 6 a 8 días | Los galones por hora de un equipo coinciden con su control de combustible |
| 4 · Sensores | Sensor de pala en una excavadora piloto; sensor de proximidad | Definir y comprar hardware | 3 a 5 días de software, más instalación | Las horas de pala inactiva se descuentan en horas netas |

La fase 1 sube de 12–15 a 14–18 días por lo que añadió la revisión: paso 0, compatibilidad
legacy, acción de huecos, sincronización de vehículos y los cambios en Historial y Reportes.

### Orden dentro de la fase 1

0. Paso 0: correcciones previas (sección 6).
1. Migraciones y entidades.
2. Categoría de geocerca: formulario v2 y legacy, filtro, exportación e importación.
3. `OperationCycleWriter`, `OperationRebuildService` y cálculo de tramos (con `NoDataDuration`).
   Va primero porque permite probar con datos reales ya guardados, sin tocar `PositionsWriter`.
4. Pruebas de la lógica de vueltas contra días reales de vehículos del cliente.
5. `OperationChecker` y enganche en `PositionsWriter`, detrás del interruptor de configuración.
6. Comando `operations:process`: sincronización, cálculo de tramos, «Sin datos», tramos vencidos
   y cola de recálculos.
7. Permiso, rutas, API y página: pestaña Operaciones y modal, luego Vueltas, luego En vivo.
8. Reporte Control de vueltas y botón «Exportar».
9. Versionado de puntos al mover o eliminar geocercas (v2, legacy y API), y recálculo manual.
10. Piloto con una operación y pocos vehículos.

### Cruce con la salida de la UI v2

Según `plan de mejoras.md`: consolidación del 5 al 9 de octubre, pruebas sistemáticas del 12 al 23,
piloto en producción del 26 de octubre al 6 de noviembre y salida propuesta el 10 de noviembre de
2026 (por confirmar). Este módulo agrega una página a esa interfaz y toca `live-zones.js`,
`history.js` y `reports.js`, que estarán en pruebas. Desarrollar en rama aparte; está por evaluarse
si entra en esa salida.

---

## 12. Pruebas y despliegue

| Tipo | Qué se comprueba |
| --- | --- |
| Unitarias del checker | Vuelta completa; rebote en el borde no cuenta doble; regreso al mismo punto no suma; tramo vencido queda incompleto; vehículo ya dentro de un punto al crear la operación; varios puntos de carga; trama con `valid = 0` o `sat < 4` no cambia nada; el estado se conserva entre dos lotes (dos instancias seguidas); repetir un lote no duplica visitas; turno que cruza la medianoche, fecha de turno, vuelta fuera de turno, turnos solapados rechazados |
| Unitarias del cálculo de tramo | Parada bajo el umbral no se guarda; parada en grifo sale autorizada; ralentí más motor apagado suman la parada; equipo sin sensor de motor; tiempo sin datos va a `gap_seconds` y no a parada |
| Tramas atrasadas | Un bloque que llega tarde genera recálculo y el resultado es igual al de recibirlas en orden |
| Historial | Mover un punto no cambia vueltas cerradas; eliminar la geocerca o al usuario dueño de la geocerca no borra vueltas; guardar un círculo desde el legacy sin moverlo no crea versión; recálculo con ubicación vigente y con ubicación actual |
| Contraste | Entradas y salidas del módulo coinciden con el reporte «Entradas y salidas de geocercas» |
| Rendimiento | Tiempo de `PositionsWriter` por lote con el módulo apagado, con vehículo fuera de operación y con vehículo en operación. Una consulta por lote y ninguna por trama |
| Permisos | Sin el permiso no se ve el menú, no responde la API ni aparece el reporte 910; un usuario no ve operaciones de otro |
| Legacy | Editar una geocerca desde el legacy conserva su categoría y versiona el punto si la movió |
| Piloto | Una operación, pocos vehículos, una semana, contra el conteo manual del cliente |

Las filas «unitarias» son casos a comprobar. Si se decide no escribir pruebas automáticas (ver
§14), se validan a mano: el recálculo sobre días reales cubre la lógica de vueltas sin tocar
`PositionsWriter`.

Despliegue:

1. Respaldo de la base de datos.
2. Subir el código con `tobuli.operations_enabled` apagado.
3. `php artisan migrate`.
4. `gulp ui-v2` (o subir `public/assets/ui/v2/` ya compilado).
5. `queue:restart`. Los procesos de inserción toman el código nuevo solos en el minuto siguiente.
6. Marcar el permiso `operations` en los usuarios del piloto desde el admin.
7. Crear la operación piloto y correr un recálculo de los últimos días para validar con datos reales.
8. Encender `tobuli.operations_enabled` y vigilar el tiempo de inserción el primer día.

Vuelta atrás: apagar `tobuli.operations_enabled`. Las tablas nuevas pueden quedarse.

---

## 13. Riesgos

| Riesgo | Efecto | Cómo se reduce |
| --- | --- | --- |
| Estado del conteo perdido entre lotes | Vueltas perdidas o dobles | El estado vive en `operation_device_state`, no en memoria; escrituras condicionadas al estado esperado |
| Tramas inválidas o congeladas | Llegadas falsas | Se ignoran `valid = 0` y `sat < 4`, como en las alertas de geocerca |
| Tramas atrasadas o desordenadas | Vueltas mal armadas | No mueven el estado en vivo; recálculo automático desde la trama atrasada |
| Trama con hora futura (hasta 24 h se aceptan) | El vehículo deja de evaluarse en vivo hasta alcanzar esa hora; lo mismo pasa hoy con las alertas | Poco frecuente. Se acepta; el recálculo manual corrige el periodo |
| Equipo reporta cada más de 30 s, o sin cobertura | La alerta de 1 minuto llega tarde | Verificar la configuración en la fase 0 |
| Geocerca pequeña frente al intervalo de reporte | La visita no se registra | Puntos amplios; revisar el segmento entre tramas |
| Rebote del GPS en el borde | Vueltas dobles | Permanencia mínima de entrada y salida |
| Carga extra en `PositionsWriter` | Inserción más lenta para toda la plataforma | Una consulta por lote, escritura solo en transiciones, interruptor, medición antes y después |
| Sensor de ignición mal elegido o mal cableado | Ni paradas ni ralentí son fiables (el pipeline exige motor encendido para «en marcha») | Elegir el sensor exacto por equipo en la fase 0; el modal avisa si un vehículo del grupo no tiene sensor de motor |
| Edición de geocercas desde el legacy o la API | Versiones falsas de puntos, o cambios sin versionar | Ganchos en `GeofenceService::update()`/`delete()`, comparación numérica, campo y aviso también en el legacy |
| Producción en MariaDB 10.5.29 y desarrollo en MySQL 8 | Una migración que funciona en desarrollo puede fallar o comportarse distinto en producción (espacial, `timestamp`, JSON) | Sin columnas espaciales, horas en `dateTime`, JSON guardado como texto; probar las migraciones en una copia MariaDB antes de desplegar |
| Vehículo sin sensor de combustible | Consumo por hora vacío | El FTC927 no lee CAN: hace falta FMC150 (en evaluación) o un sensor de nivel |
| Grupos de vehículos por usuario | Un subusuario con otra agrupación ve otra cosa | La operación usa el grupo de su dueño y solo la ve su dueño |
| Limpieza de posiciones antiguas (`db_clear`, mínimo 30 días) | No se recalcula más atrás de lo conservado | Las vueltas guardadas no dependen de las posiciones |
| Cambios en `live-zones.js`, `history.js` y `reports.js` durante las pruebas de la UI v2 | Regresiones en pantallas ya probadas | Cambios pequeños y aditivos; rama aparte |

### Anotado para después (fuera de este plan)

- Las integraciones peruanas (SUTRAN, OSINERGMIN, MININTER, Comsatel, WiseTrack, Sidersales, EPS
  Center) hacen un `create()` por trama dentro de `PositionsWriter` (700-736). Se puede aligerar
  acumulando las filas y escribiéndolas en `write()` con un solo insert por lote, o pasándolas a
  una cola.
- Cuando el búfer del lote está vacío, `getPrevPosition()` consulta la BD por trama. La opción
  `prev_position_device_object` (hoy apagada) la evita usando `traccar`.
- `sensor_value` y `device_alarm` no están en ningún scope de `Alert` y parecen no evaluarse
  (análisis estático, sin probar).
- El importador GeoJSON acepta `user_id` desde el archivo.

---

## 14. Decisiones

### Tomadas

| Tema | Decisión |
| --- | --- |
| Cuándo se cuenta la vuelta | Al regresar al punto de carga |
| Día de la vuelta | Día calendario; desde las 00:00 es otro día, también en turno noche |
| Turnos | Lista de turnos por operación, cada uno con nombre y rango horario; un turno puede cruzar la medianoche y se totaliza completo |
| Descuentos de horas trabajadas | Configurables por el usuario en cada operación |
| Umbrales | Parada 60 s, permanencia 30 s. Los equipos transmiten cada 30 s |
| Interfaz | UI v2. El legacy recibe solo la compatibilidad necesaria para no romperse: campo de categoría y aviso al tocar un punto de operación |
| Alcance por vehículos | Por grupo de vehículos, no para toda la flota |
| Evaluación en vivo | Una clase que evalúa por trama igual que las alertas, con el estado en BD. Sin estado en memoria |
| Tramas inválidas | `valid = 0` no hace nada, como en las alertas |
| Visibilidad | Cada usuario ve solo sus operaciones. El admin entra como el usuario |
| Pestaña En vivo | Una petición periódica a un endpoint propio, como el mapa en vivo. Solo lee lo ya guardado |
| Borrado de geocercas y grupos en uso | Bloqueado: solo se pueden borrar después de quitarlos de la operación |
| Alerta de parada | El cliente necesita aviso y evento al detenerse 1 minuto: se usa la alerta existente de duración de parada |
| Base de datos | Producción es MariaDB 10.5.29; las migraciones deben correr también ahí |
| Permiso | Módulo `operations`, asignado por el admin a los usuarios del piloto. Los reportes siguen ese permiso |
| Sensor de ignición | Se elige con exactitud en cada vehículo; de él depende el ralentí |
| Cálculo de tramos | Una vez, al cerrar el tramo. El tramo abierto se muestra con el estado de `traccar` |
| Rendimiento de la inserción | Se mide en el piloto y se optimiza después, junto con las integraciones peruanas |
| Recálculo manual | Entra en la fase 1 |
| Motivos de parada | Catálogo seleccionable con opción «Otro» que permite escribir la justificación |
| Quién justifica | El usuario de la plataforma |
| Fase 3 | Se implementa completa; calcula según los sensores del vehículo |
| Abastecimiento | Solo por visita a la geocerca de grifo |
| Conservación de posiciones | Se configura en los ajustes; limpieza diaria |
| Equipo en volquetes | FTC927 hoy |
| Sensor de la pala | Piloto con EYE Sensor enlazado al FTC927 |

### Pendientes

- [ ] ¿Entra en la salida del 10 de noviembre o en una posterior?
- [ ] Pruebas automáticas (PHPUnit): ¿se escriben para el módulo, o se valida a mano con el recálculo sobre días reales (propuesto)? El repositorio no tiene `phpunit.xml` y una de las pruebas existentes falla.
- [ ] Alerta de parada: ¿se cambia `round` por `floor` para que 1 minuto sea 1 minuto cumplido? Afecta a todos los clientes que usan esa alerta.
- [ ] ¿Los volquetes pasan de FTC927 a FMC150? De eso depende el odómetro por CAN y el consumo real.
- [ ] Sensor de proximidad: lo más probable es entre vehículos; falta confirmarlo y elegir el equipo.
- [ ] Marca, modelo y GPS de las excavadoras.
- [ ] Confirmar con Teltonika si el FTC927 transmite el ángulo del EYE Sensor.
- [ ] Confirmar si cada motivo del catálogo autoriza o solo explica la parada.
- [ ] Cómo cuenta hoy el cliente las vueltas, para validar el piloto.
