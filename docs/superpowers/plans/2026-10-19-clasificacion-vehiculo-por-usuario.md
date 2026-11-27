# Clasificación de vehículo (tipo y estado) por usuario — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking. Sin tests automatizados (decisión del proyecto); cada tarea termina con una verificación manual descrita.

**Estado (19-oct-2026):** Tasks 1 a 7 hechas en la rama `clasificacion-por-usuario` (commits `5147c972`..`81d44ec5` + documentación). Queda solo la migración de borrado de las columnas viejas, que se incluirá en una versión posterior cuando pruebas confirme.

**Goal:** que cada cuenta clasifique un vehículo compartido con SUS propios tipos y estados, y que los límites de velocidad por geocerca se resuelvan siempre con la clasificación del dueño de la geocerca. Hoy `devices.vehicle_type_id` / `vehicle_state_id` son globales: un vehículo compartido entre dos clientes solo puede llevar el tipo de uno de ellos, y para el otro ningún límite por tipo coincide (cae en silencio al límite general).

**Decisión del cliente (19-oct-2026):** hay vehículos compartidos y cada cliente puede considerar otro tipo y estado para la velocidad; hacerlo «como debe ser».

**Inventario de uso:** ver `docs/qa/inventario-clasificacion-vehiculo.md` (secciones 1 a 20). Resumen: 2 columnas en `devices`, 1 tabla de catálogo por usuario, 1 tabla de límites por geocerca; escriben el ModalHelper (legacy, v2 y API v1) y dos `changeVehicleState`; leen el transformer de mapa, el sidebar legacy, Seguridad, 3 acciones de historial, 4 reportes y la alerta de geocerca.

---

## Diseño

### Tabla nueva `device_vehicle_classifications`

| columna | tipo | notas |
| --- | --- | --- |
| id | unsignedBigInteger PK | |
| device_id | unsignedInteger FK devices (cascade) | |
| user_id | unsignedInteger FK users (cascade) | la cuenta que clasifica |
| vehicle_type_id | unsignedBigInteger FK vehicle_types (cascade) | del catálogo de `user_id` |
| vehicle_state_id | unsignedBigInteger nullable FK vehicle_states (set null) | debe pertenecer al tipo |
| created_at / updated_at | dateTime | MariaDB 10.5: `dateTime`, no `timestamp` |
| unique (device_id, user_id) | | una clasificación por cuenta y vehículo |

Borrar un tipo borra solo las clasificaciones de esa cuenta (cascade) y ya no deja a NULL el tipo de otros usuarios.

### Regla única de resolución

`Geofence::speedLimitForDevice(Device $device)` → `speedLimitFor($device->vehicleTypeFor($this->user_id), $device->vehicleStateFor($this->user_id))`. Toda geocerca tiene dueño, así que alertas (geocercas de la alerta), historial y reportes (geocercas del usuario del reporte) y Seguridad (geocercas del usuario del cálculo) resuelven sin pasar ningún usuario extra. Para mostrar (listas, ficha, Seguridad, Excel) se usa el usuario actual: `$device->classificationFor($user->id)`.

### Columnas viejas

`devices.vehicle_type_id` y `vehicle_state_id` dejan de escribirse y de leerse. Se mantienen una versión (la migración copia su valor a la tabla nueva para el dueño del tipo) y se eliminan en una migración posterior, cuando pruebas confirme.

### Compatibilidad del JS

`live-zones.js` y `tp-map-layers.js` replican `speedLimitFor` con `d.vehicle_type_id` / `d.vehicle_state_id` del transformer. Si el transformer manda los ids de la clasificación del usuario actual, el JS no cambia.

---

## Estructura de archivos

- Create: `database/migrations/2026_10_20_000001_create_device_vehicle_classifications_table.php`
- Create: `Tobuli/Entities/DeviceVehicleClassification.php`
- Create: `Tobuli/Services/DeviceClassificationService.php` (validar y escribir la clasificación de un usuario)
- Modify: `Tobuli/Entities/Device.php` (relación `classifications`, `classificationFor`, `vehicleTypeFor`, `vehicleStateFor`, scope `withClassificationFor`)
- Modify: `Tobuli/Entities/Geofence.php` (`speedLimitForDevice`)
- Modify: `ModalHelpers/DeviceModalHelper.php` (createData/editData/create/edit/changeVehicleState)
- Modify: `Tobuli/Validation/DeviceFormValidator.php` (quitar `exists` globales; la pertenencia la valida el service)
- Modify: `app/Http/Controllers/Frontend/DevicesController.php` (`vehicleStateForm`), `app/Http/Controllers/Ui/V2/DevicesController.php` (`vehicleStates`, `changeVehicleState`, flag `vt`)
- Modify: `app/Transformers/Device/DeviceMapTransformer.php`
- Modify: `app/Http/Controllers/Frontend/DevicesSidebarController.php`, `Tobuli/Views/Frontend/Objects/item.blade.php`
- Modify: `Tobuli/Helpers/Alerts/Check/GeofenceAlertCheck.php`
- Modify: `Tobuli/History/Actions/AppendSpeedLimitGeofence.php`, `AppendSpeedLimitGeofenceByPriority.php`
- Modify: `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php`
- Modify: `app/Http/Controllers/Ui/V2/SecurityController.php`, `Tobuli/Services/Security/{SecurityLiveStatus,SecuritySummary}.php`, `Tobuli/Exports/Security/Sheets/DataSheet.php`, `SecurityWorkbookExport.php`
- Modify: `Tobuli/Views/Frontend/Devices/partials/advanced.blade.php`, `resources/views/ui/v2/modals/devices/partials/informacion.blade.php` (solo lo que cambie el ModalHelper)
- Modify: `docs/guia-actualizacion-operaciones.md` (sección nueva), `CLAUDE.md` (sección Tipos/Estados)

---

## Fase 1 · Datos

### Task 1: Migración y entidad

**Files:** migración nueva, `Tobuli/Entities/DeviceVehicleClassification.php`, `Tobuli/Entities/Device.php`

- [x] Migración con la tabla de arriba (clase anónima, `dateTime`, `unsignedInteger` para `device_id` y `user_id` como en el resto del esquema, `unsignedBigInteger` para tipo y estado como en `vehicle_types`).
- [x] En la misma migración, copiar los datos: por cada device con `vehicle_type_id`, insertar (device_id, user_id = `vehicle_types.user_id` del tipo, vehicle_type_id, vehicle_state_id) con `insertUsing` sobre un join. Un vehículo con estado de otro tipo (dato sucio) se copia con estado null.
- [x] Entidad `DeviceVehicleClassification` (`AbstractEntity`, fillable, casts int, relaciones `device`, `user`, `vehicleType`, `vehicleState`).
- [x] `Device`: `classifications()` hasMany; `classificationFor(int $userId)` que usa la relación si está cargada (`relationLoaded`) y si no consulta; `vehicleTypeFor($userId)` / `vehicleStateFor($userId)`; scope `withClassificationFor($userId)` = `with(['classifications' => fn($q) => $q->where('user_id', $userId)->with('vehicleType', 'vehicleState')])`.
- [x] Marcar `vehicleType()` y `vehicleState()` como `@deprecated` y dejar de usarlos en el código nuevo (quedan hasta la migración de borrado).
- [x] Verificación: `php artisan migrate --force` en dev; `select count(*) from device_vehicle_classifications` = número de devices con tipo.

### Task 2: Servicio de clasificación y resolución del límite

**Files:** `Tobuli/Services/DeviceClassificationService.php`, `Tobuli/Entities/Geofence.php`

- [x] `DeviceClassificationService::set(User $user, Device $device, ?int $typeId, ?int $stateId)`: valida que el tipo sea de `$user` y el estado del tipo (lanza `ValidationException` con claves `vehicle_type_id` / `vehicle_state_id`); con tipo null borra la fila; si no, `updateOrCreate` por (device_id, user_id).
- [x] `DeviceClassificationService::setState(User $user, Device $device, ?int $stateId)`: igual, solo el estado sobre la clasificación existente (error si no hay tipo).
- [x] `Geofence::speedLimitForDevice(Device $device): ?float` como en el diseño. `speedLimitFor()` se mantiene (lo usa el JS-espejo y el importador no).
- [x] Verificación: script de bootstrap en el scratchpad que asigne un tipo a un device para dos usuarios distintos y compruebe que `speedLimitForDevice` devuelve el límite correcto para una geocerca de cada uno.

## Fase 2 · Escritura

### Task 3: Formularios de vehículo (legacy, v2, API v1) y cambio rápido de estado

**Files:** `ModalHelpers/DeviceModalHelper.php`, `Tobuli/Validation/DeviceFormValidator.php`, `app/Http/Controllers/Frontend/DevicesController.php`, `app/Http/Controllers/Ui/V2/DevicesController.php`

- [x] `createData`/`editData`: `$vehicle_types` = SOLO los del usuario que edita (se quita el `orWhere` del parche); `$item` lleva `vehicle_type_id`/`vehicle_state_id` **de la clasificación del usuario** para que los selects se pinten (sin tocar las columnas viejas: calcular dos variables y pasarlas a la vista, o setear atributos no persistidos).
- [x] `create()`/`edit()`: sacar `vehicle_type_id` y `vehicle_state_id` de los datos antes del `deviceService->create/update` y llamar a `DeviceClassificationService::set($this->user, $device, …)` después. Solo si las claves vienen en el request (API v1 que no las manda no borra nada).
- [x] `changeVehicleState()` (helper) y el v2 `changeVehicleState`: pasar por `setState` del service. `vehicleStateForm` y el v2 `vehicleStates`: estados del tipo **de la clasificación del usuario**.
- [x] `DeviceFormValidator`: `vehicle_type_id` / `vehicle_state_id` → `nullable|integer` (la pertenencia la valida el service).
- [x] v2 flag `vt` (acción «Cambiar estado» en el menú): `(bool) $device->vehicleTypeFor($this->user->id)`.
- [x] Verificación en dev con dos usuarios sobre el device 219: cada uno guarda un tipo distinto y el otro no se pierde (`device_vehicle_classifications` con dos filas).

## Fase 3 · Lectura

### Task 4: Mapa, listas y ficha

**Files:** `app/Transformers/Device/DeviceMapTransformer.php`, `app/Http/Controllers/Ui/V2/DevicesController.php` (`liveData`), `app/Http/Controllers/SharingController.php`, `app/Http/Controllers/Frontend/DevicesSidebarController.php`, `Tobuli/Views/Frontend/Objects/item.blade.php`

- [x] Transformer: eager-load `classifications` filtradas por el usuario actuante (`$this->user` o el dueño del sharing: hay `setActingUser`) en vez de `vehicleState`; exponer `vehicle_type_id`, `vehicle_state_id` y `vehicle_state{name,label,color}` desde `classificationFor($user->id)`.
- [x] Sidebar legacy: `withClassificationFor($this->user->id)`; `item.blade`: badge desde `classificationFor(Auth::id())`; acción «Cambiar estado» visible si hay tipo para el usuario.
- [x] Verificación: mapa v2 y lista legacy muestran el estado del usuario logueado; enlace público de Compartir muestra el del dueño del enlace.

### Task 5: Seguridad

**Files:** `app/Http/Controllers/Ui/V2/SecurityController.php`, `Tobuli/Services/Security/SecurityLiveStatus.php`, `SecuritySummary.php`, `Tobuli/Exports/Security/Sheets/DataSheet.php`, `SecurityWorkbookExport.php`, `tp-security.js` si cambia el payload

- [x] Filtro «Tipo»: `whereHas('classifications', fn($q) => $q->where('user_id', U)->where('vehicle_type_id', $typeId))` en `filtered()` y en `filteredDevices()`.
- [x] Carga: `with(['device' => fn($q) => $q->withClassificationFor(U)])` en los tres `get()`; `SecuritySummary`, `SecurityLiveStatus` y `DataSheet` leen `optional($device->classificationFor($userId))->vehicleType->name` (pasar `$userId` al constructor o a los métodos).
- [x] Verificación: página Seguridad con un tipo asignado por el usuario 864 muestra la columna Tipo y el filtro funciona.

### Task 6: Alertas, historial y reportes

**Files:** `GeofenceAlertCheck.php`, `AppendSpeedLimitGeofence.php`, `AppendSpeedLimitGeofenceByPriority.php`, `OverspeedsInGeofenceCustomReport.php`

- [x] `GeofenceAlertCheck::resolveSpeedLimit`: `$geofence->speedLimitForDevice($this->device)`.
- [x] `AppendSpeedLimitGeofence`: ídem. `AppendSpeedLimitGeofenceByPriority`: ídem y clave de caché por `geofence->user_id` en vez de los ids globales del device.
- [x] `OverspeedsInGeofenceCustomReport`: `resolveGeofenceEvents` recibe el device y usa `speedLimitForDevice` (quitar los parámetros `$vehicleType/$vehicleState`).
- [x] Pre-cargar `classifications` del device para el usuario del reporte/alerta antes del bucle (una consulta, no una por posición): `$device->load(['classifications' => …])`.
- [x] Verificación: reporte 904 del device 219 en dev da los mismos excesos que antes; `security:aggregate` de un día termina sin avisos.

## Fase 4 · Cierre

### Task 7: Documentación y limpieza

- [x] `CLAUDE.md`: actualizar la sección «Tipos/Estados de Vehículo» (tabla nueva, regla de resolución, columnas viejas en desuso).
- [x] `docs/guia-actualizacion-operaciones.md`: sección «Clasificación por usuario»: `php artisan migrate --force`, nada de config, verificación (`select` de la tabla), y aviso de que las columnas viejas se borran en una versión posterior.
- [ ] Migración de borrado de `devices.vehicle_type_id` / `vehicle_state_id` **preparada pero no incluida** en este merge (archivo en `docs/` o rama aparte) hasta que pruebas confirme.
- [x] Memoria del proyecto: anotar la decisión y la regla «el dueño de la geocerca resuelve».

---

## Riesgos

| Riesgo | Mitigación |
| --- | --- |
| Algún sitio sigue leyendo `$device->vehicleType` global | grep final de `vehicleType\b|vehicleState\b|vehicle_type_id|vehicle_state_id` fuera de la entidad y la migración; el inventario lista todos los puntos |
| Reportes por cron con muchas posiciones | la clasificación se pre-carga una vez por device; `speedLimitForDevice` no consulta dentro del bucle |
| API móvil que manda `vehicle_type_id` | pasa por el mismo ModalHelper: queda clasificada para el usuario de la API |
| Datos sucios (estado de otro tipo) | la migración los copia con estado null y lo registra en el log |
