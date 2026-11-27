# Inventario · clasificación de vehículo (tipo y estado) y límites por geocerca

Fecha: 19-oct-2026. Base para el plan `docs/superpowers/plans/2026-10-19-clasificacion-vehiculo-por-usuario.md`.

Contexto de usuario en cada punto: **U** = usuario autenticado que actúa · **OwnerGF** = dueño de la geocerca · **AlertOwner** = dueño de la alerta · **ReportUser** = usuario del reporte (también cron) · **cron** = sin request.

## Modelo actual

- `devices.vehicle_type_id`, `devices.vehicle_state_id` (globales, en `$fillable` de `Device`, mutadores vacío→null, `vehicleType()`/`vehicleState()` hasOne).
- `vehicle_types` (user_id, name) · `vehicle_states` (vehicle_type_id, name, label, color).
- `geofence_vehicle_speed_limits` (geofence_id, vehicle_type_id, vehicle_state_id nullable, speed_limit), unique por los tres.
- `Geofence::speedLimitFor(?VehicleType, ?VehicleState)`: exacto tipo+estado → tipo con estado null → `speed_limit` general. Compara ids; el dueño implícito es OwnerGF.

## Dónde se escribe

| Punto | Qué | Usuario |
| --- | --- | --- |
| `ModalHelpers/DeviceModalHelper.php` create/edit | escritura implícita por `$fillable` vía `DeviceService` (legacy, v2 y API v1 `add_device`/`edit_device`) | U |
| `DeviceModalHelper::changeVehicleState` + `Ui/V2/DevicesController::changeVehicleState` | escriben `vehicle_state_id`; validan estado ∈ tipo, no el usuario | U |
| `Tobuli/Services/GeofenceService::syncVehicleSpeedLimits` | límites por tipo/estado, validados contra OwnerGF (19-oct) | OwnerGF |
| `Frontend/VehicleTypesController`, `Ui/V2/VehicleTypesController` | CRUD de tipos y estados por U; borrar un tipo deja a NULL el tipo en devices de cualquier usuario (FK set null) | U |
| `Tobuli/Importers/Geofence/GeofenceImporter::resolveVehicleSpeedLimits` | crea tipos/estados por nombre para el usuario destino | usuario destino |

## Dónde se lee para resolver el límite

| Punto | Línea | Contexto |
| --- | --- | --- |
| `Tobuli/Helpers/Alerts/Check/GeofenceAlertCheck.php` | 305 | geocercas de la alerta; tipo global del device | AlertOwner |
| `Tobuli/History/Actions/AppendSpeedLimitGeofence.php` | 55 | lo usan `RagGeofencesReport` y `AppendOverspeeding` | ReportUser |
| `Tobuli/History/Actions/AppendSpeedLimitGeofenceByPriority.php` | 34, 44 | clave de caché con los ids globales; lo usan 905/906 y `AppendSpeedLimitGeofenceOrStatic` (Seguridad) | ReportUser / usuario del cálculo |
| `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php` | 72-81, 146-177 | `resolveGeofenceEvents($point,$speed,$vehicleType,$vehicleState)` | ReportUser |
| `Tobuli/Services/Security/SecurityDailyCalculator.php` | 41, 65 | geocercas del usuario con límites; acción OrStatic | usuario del cálculo |
| JS v2 `live-zones.js` 1371-1400, `tp-map-layers.js` 275-300 | | réplica de `speedLimitFor` en el navegador con `d.vehicle_type_id`/`vehicle_state_id` del transformer | U |

## Dónde se muestra

| Punto | Qué | Usuario |
| --- | --- | --- |
| `app/Transformers/Device/DeviceMapTransformer.php` 22, 114-127 | `vehicle_state{name,label,color}`, `vehicle_type_id`, `vehicle_state_id` | U, o dueño del sharing (`setActingUser`) |
| `Frontend/DevicesSidebarController` 36 + `Objects/item.blade.php` 44-47, 110-115 | badge y acción «Cambiar estado» | U |
| `resources/views/ui/v2/pages/live.blade.php` 723-727, `partials/device-actions.blade.php` 80-83, `live.js` 421, 1014-1336, `monitor.js` 161 | badge, cambio rápido, `limitFor` del marcador | U |
| `Ui/V2/SecurityController` 60-61, 77, 170, 212, 230-232 | filtro Tipo, eager-load `device.vehicleType` | U |
| `Tobuli/Services/Security/SecurityLiveStatus` 50, `SecuritySummary` 84, 121, `Exports/Security/Sheets/DataSheet` 52, `SecurityWorkbookExport` 66 | columna Tipo | U |
| `Tobuli/Views/Frontend/Devices/partials/advanced.blade.php` 22-41, `resources/views/ui/v2/modals/devices/partials/informacion.blade.php` 76-97 | selects tipo/estado con `$vehicle_types` del ModalHelper (U + parche «más el tipo actual») | U |
| `Frontend/GeofencesController` 84-123, `Ui/V2/ZonesController` 45-92, 501-530, `Geofences/partials/vehicle_speed_limits.blade.php`, `live-zones.js` 396-445 | matriz de límites por tipo del usuario | U |
| `Frontend/GeofencesExportController` 45, `Exporters/.../GexpExporter` 45-56 | export por nombre | U |

## Sin uso

API v1 (fuera de `add/edit_device`), Operaciones, JS legacy, PositionsWriter, seeders.

## Puntos críticos

1. Un vehículo compartido solo puede tener el tipo de una cuenta; para las demás ningún límite coincide y se cae al general.
2. `DeviceFormValidator` y los dos `changeVehicleState` no comprueban el usuario: se puede asignar un tipo de otra cuenta.
3. La escritura es implícita por `$fillable` en los tres canales (legacy, v2, API).
4. Borrar un tipo afecta a los devices de otras cuentas.
5. Todas las lecturas tienen un usuario claro: U en pantallas, dueño de la geocerca en alertas/historial/reportes/Seguridad, dueño del sharing en el enlace público.
6. El JS no cambia si el transformer manda los ids de la clasificación del usuario actual.
7. La clave de caché de `AppendSpeedLimitGeofenceByPriority` debe pasar a depender del dueño de la geocerca.
8. El parche `orWhere('id', $item->vehicle_type_id)` de `DeviceModalHelper::editData` desaparece con la tabla nueva.
