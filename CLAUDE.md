# CLAUDE.md — Talentus Pro Tracking

Guía para Claude Code sobre la arquitectura, convenciones y flujos de trabajo de este proyecto.

---

## Stack Tecnológico

- **Laravel 8** (PHP 8.x)
- **MySQL** — base de datos principal
- **Redis** — caché, colas y pub/sub para tiempo real
- **Socket.io + Node.js** — servidor WebSocket en `socket/socket.js`
- **Gulp** — compilación de assets (NO usa Laravel Mix)
- **Fractal** — transformers para respuestas API

---

## Estructura de Namespaces (DUAL)

| Namespace        | Ubicación  | Propósito                                                         |
| ---------------- | ---------- | ----------------------------------------------------------------- |
| `App\`           | `app/`     | Capa Laravel estándar (Controllers, Middleware, Events, Jobs)     |
| `Tobuli\`        | `Tobuli/`  | Dominio de negocio (Entities, Repositories, Services, Validation) |
| `CustomFacades\` | `Facades/` | Facades custom (Repos, ModalHelpers, Validators)                  |

---

## Comandos de Desarrollo

```bash
# Servidor Laravel
php artisan serve

# Servidor de sockets (tiempo real GPS)
cd socket && node socket.js

# Assets (SCSS + JS)
gulp
gulp watch

# Base de datos
php artisan migrate
php artisan db:seed
php artisan devices:update-config
```

---

## Patrones Críticos

### 1. Custom Facades — NO son facades estándar de Laravel

```php
use CustomFacades\Repositories\DeviceRepo;
use CustomFacades\ModalHelpers\DeviceModalHelper;
use CustomFacades\Validators\GeofenceFormValidator;
```

Registradas en `config/app.php` bajo `aliases` con prefijo `CustomFacades\`.  
**Nunca** instanciar repositorios directamente.

### 2. ModalHelpers — manejo de requests de formularios

Los ModalHelpers (en `ModalHelpers/`) reemplazan a los FormRequests de Laravel:

```php
DeviceModalHelper::setUser($user);
DeviceModalHelper::setData($request->all());
// checkException valida permisos antes de ejecutar
$this->checkException('geofences', 'store');
```

### 3. Repositories — inyección vía interface

Vinculados en `Tobuli/Repositories/RepositoriesServiceProvider.php`.  
Nunca instanciar `EloquentXxxRepository` directamente; usar la interfaz o la facade.

### 4. Entity Traits

Antes de agregar funcionalidad a una entidad, revisar sus traits:

- `Filterable`, `Searchable` — helpers de query
- `ChangeLogs` — auditoría automática
- `Filterable` — scopes de filtrado
- `DisplayTrait` — interfaz `DisplayInterface`

### 5. Transformers API

Siempre usar `FractalTransformer` para respuestas API, no arrays directos:

```php
use FractalTransformer;
return FractalTransformer::item($geofence, GeofenceMapTransformer::class)->toArray();
return FractalTransformer::paginate($items, GeofenceMapTransformer::class)->toArray();
```

### 6. Exportación de Entidades

El `GexpExporter` serializa a JSON los atributos explícitamente listados en el controlador.  
Si se agrega un campo nuevo a la entidad, **siempre** añadirlo al array `$attributes` en el controlador de exportación correspondiente.

**Ejemplo — GeofencesExportController:**

```php
$attributes = ['id', 'group_id', 'name', 'coordinates', 'polygon_color',
               'type', 'radius', 'center', 'device_id', 'speed_limit'];
```

### 7. Importación de Entidades

El `GeofenceImportManager` soporta: **KML**, **GeoJSON**, **JSON**, **GEXP**.  
El `GeofenceImporter` llama a `GeofenceService::store()` → `Geofence::create($data)`.  
Los campos llegan tal cual desde el reader; el `normalize()` en `GeofenceService` convierte unidades (ej. `speed_limit` usa `Formatter::speed()->reverse()`).

### 8. Services (`ModelService`) — orquestación de escritura

Muchos servicios CRUD extienden `Tobuli\Services\ModelService`. El flujo público es **`create()` / `edit()` / `remove()`** (NO `store()/update()` directamente):

```
create($data) → mergeDefaults() → normalize() → validateStore() → store()
edit($model, $data) →            normalize() → validateUpdate() → update()
```

- `store()/update()/delete()` son `abstract` y los implementa cada servicio (ej. `GeofenceService`).
- `normalize()` convierte unidades / formatos antes de validar (ej. `speed_limit` y `vehicle_speed_limits` con `Formatter::speed()->reverse()`).
- Las reglas de validación se inyectan desde el `FormValidator` correspondiente en el constructor (`setValidationRulesStore/Update`).

### 9. Validators (`Tobuli\Validation\Validator`)

Validadores custom (en `Tobuli/Validation/`, expuestos como `CustomFacades\Validators\*`) definen `public $rules` por escenario:

```php
class VehicleTypeFormValidator extends Validator {
    public $rules = ['create' => [...], 'update' => [...]];
}
VehicleTypeFormValidator::validate('create', $data); // lanza ValidationException si falla
```

---

## Núcleo de Rastreo (Device ↔ Traccar ↔ Posiciones)

El corazón del sistema es la entidad `Tobuli\Entities\Device` y su relación con `TraccarDevice`.

- **`Device` ↔ `TraccarDevice`** (relación `traccar`, FK `traccar_device_id`). Los hooks `boot()` del `Device` sincronizan el `TraccarDevice` al crear / renombrar / cambiar IMEI / borrar. **No** crear `TraccarDevice` a mano.
- **Posiciones GPS en tablas POR dispositivo**, creadas dinámicamente (`Device::createPositionsTable()`) y accedidas con `$device->positions()`. **No existe** una única tabla `positions`.
- `TraccarDevice` mantiene la **última posición denormalizada**: `lastValidLatitude/Longitude`, `speed`, `course`, `altitude`, `time`, `device_time`, `server_time`, `ack_time`, `moved_at`, `stoped_at`, `engine_on_at/engine_off_at`, `other`, `latest_positions` (cola del tail).
- Muchos atributos del `Device` se **delegan a `traccar`** (`lat`, `lng`, `speed`, `status`, `time`, `protocol`…) vía accessors. El array `$attributesRelations` declara qué relación cargar para cada atributo.
- **Estado en vivo** → `Device::getStatus()`: deriva de timeouts (`default_object_online_timeout`), velocidad (`min_moving_speed`, `>=90` ⇒ `excessive_speed`), motor y sensor `blocked`. Constantes `STATUS_*` (online, ack/stop, engine/idle, offline, blocked, excessive_speed, expired). Hay scopes equivalentes (`move`, `stop`, `idle`, `park`, `engineOn/Off`, `online/offline`, `expired/unexpired`…) que hacen `traccarJoin()`.
- **Parámetros del dispositivo**: el campo `other` es **XML**; usar `getParameter()/setParameter()` (parsean XML), no acceder crudo.
- **Sensores**: `DeviceSensor` + `Tobuli/Sensors/` calculan valores (fuel_tank, odometer, engine, rfid, speed_ecm, blocked, anonymizer…). Acceso vía `getSensorByType()/getSensorsByType()`.
- Eventos de dominio: `DeviceCreated/Deleted`, `Device\DeviceEnabled/Disabled`, `Device\DeviceSubscription*`.

---

## Pipeline de Historial (`Tobuli/History`)

Procesa las posiciones de un dispositivo **una por una** para construir el historial y alimentar la mayoría de reportes.

- **`Tobuli/History/Actions/`** (~100 clases). Tres familias:
  - **`Append*` (extienden `ActionAppend`)** — enriquecen cada posición vía `proccess(&$position)` (ej. `AppendGeofences`, `AppendDistance`, `AppendOdometers`, `AppendSpeedLimitGeofence`).
  - **`Group*` / `ActionGroup*`** — agrupan posiciones en tramos (drives, stops, shifts, geofence in/out).
  - **`ActionStat`** — acumulan totales.
- **Dependencias entre acciones**: método estático `required()` devuelve las acciones que deben correr antes. Ej. `AppendSpeedLimitGeofence::required()` ⇒ `[AppendGeofences::class]` (necesita `$position->geofences` ya poblado).
- Dentro de una acción: `$position->geofences` (ids de geocercas que contienen el punto), `$this->history->getGeofences()` (entidades), `$this->getDevice()`.
- Resultado modelado por `Group`, `GroupContainer`, `MetaContainer`, `StatContainer`.

---

## Sistema de Reportes (`Tobuli/Reports`)

~130 reportes. Jerarquía: `Report` → `DeviceReport` / `DeviceHistoryReport` → reporte concreto en `Tobuli/Reports/Reports/`.

- Cada reporte declara **`const TYPE_ID`** y `title()`, y genera por dispositivo con `generateDevice($device)` (itera posiciones con `chunk()` o usa el pipeline de History).
- **`getInputParameters()`** define el formulario del reporte con la facade `\Field` (ej. `\Field::checkbox('only_violations', '...', true)`).
- Registro de tipos: `Tobuli/Reports/ReportTypesConfig.php` y `ReportTypesUserConfig.php`. Las tablas `reports` / `report_logs` guardan `type` como **SMALLINT** (ampliado recientemente — los IDs custom pueden ser >127, ej. overspeed-geofence usa 904).
- **Familia overspeed-en-geocerca** (trabajo reciente): `OverspeedsInGeofenceCustomReport` (904) y variantes `…V2`, `…Buenaventura`. Resuelven el límite por geocerca con `Geofence::speedLimitFor()` y eligen la geocerca más específica (menor área) cuando solapan.

---

## Sistema de Alertas (`Tobuli/Helpers/Alerts`)

- **`Check/`** — una clase `*AlertCheck` por tipo de alerta (`OverspeedAlertCheck`, `GeofenceAlertCheck`, `IdleDurationAlertCheck`, `OfflineDurationAlertCheck`, `SosAlertCheck`, `DriverAlertCheck`…). Base `AlertCheck`/`BaseAlertCheck`; `Checker` orquesta. El método clave es `checkEvents($position, $prevPosition)` → devuelve `Event[]`.
- **`Notification/`** — canales: `Email`, `Sms`, `Push`, `Popup`, `Sound`, `Color`, `Webhook`, `Command`, `Sharing(Email|Sms)`, `Silent`. Coordinados por `NotificationProvider`.
- **`Filter/`** — filtros previos (`EnabledFilter`).
- **`GeofenceAlertCheck`** cubre `geofence_in/out/inout/overspeed`. El **overspeed de geocerca** usa `Geofence::speedLimitFor($device->vehicleType, $device->vehicleState)`, suma `getSpeedTolerance()`, exige `getMinOverspeedDuration()` (tracking en `Cache` por alerta+device+geofence) y descarta posiciones con `sat <= 10`. Tipos de evento: `Event::TYPE_ZONE_IN/OUT/OVERSPEED`.

---

## Tipos/Estados de Vehículo y Límites de Velocidad por Geocerca

> Funcionalidad en desarrollo activo (migraciones `2026_06_19_*`). Permite límites de velocidad por geocerca diferenciados por **tipo** y **estado** de vehículo (ej. "Cisterna / cargado" vs "Cisterna / vacío").

**Entidades nuevas:**

| Entidad                     | Tabla                            | Notas                                                              |
| --------------------------- | -------------------------------- | ------------------------------------------------------------------ |
| `VehicleType`               | `vehicle_types`                  | Pertenece a un `User`; `hasMany` estados.                          |
| `VehicleState`              | `vehicle_states`                 | Pertenece a un `VehicleType`.                                      |
| `GeofenceVehicleSpeedLimit` | `geofence_vehicle_speed_limits`  | `unique(geofence_id, vehicle_type_id, vehicle_state_id)`; `state` nullable. |

- `devices` gana `vehicle_type_id` + `vehicle_state_id` (FK `set null`). `Device::vehicleType()` / `vehicleState()`.
- **CRUD de tipos**: `VehicleTypesController` (`Route::resource('vehicle_types')` + `vehicle_types/table`). Los estados se editan anidados en el form del tipo (`add_state[]`, `edit_state[id]`); validación con `VehicleTypeFormValidator`.
- **Asignación rápida de estado a un objeto**: `DevicesController::vehicleStateForm()` + `changeVehicleState()` → `DeviceModalHelper::changeVehicleState()` (valida que el estado pertenezca al tipo del device).
- **En la geocerca**: partial `Geofences/partials/vehicle_speed_limits.blade.php`. El form envía `vehicle_speed_limits[<type_id>][<state_id>] = velocidad` (clave de estado `0` = límite por defecto del tipo, `vehicle_state_id` null). `GeofenceService::syncVehicleSpeedLimits()` borra y recrea los límites; `normalize()` convierte unidades.
- **Resolución del límite → `Geofence::speedLimitFor($vehicleType, $vehicleState)`** con fallback en 3 niveles:
  1. límite exacto (tipo + estado),
  2. límite por defecto del tipo (estado `null`),
  3. columna legacy `geofences.speed_limit`.
- **Consumidores** de `speedLimitFor()`: `GeofenceAlertCheck` (alertas), reportes overspeed-en-geocerca, y acciones de History `AppendSpeedLimitGeofence`, `AppendSpeedLimitGeofenceByPriority`, `AppendSpeedLimitGeofenceMulti`.
- **Prioridad con geocercas solapadas**: se elige la de **menor área** (`Geofence::approxAreaSqMeters()`), vía `Geofence::pickBySpeedLimitPriority()` o la acción `…ByPriority`.
- ⚠️ **Pendiente**: el export/import GEXP de geocercas (sección 6/7) **aún no** serializa la relación `vehicleSpeedLimits` (solo el `speed_limit` legacy). Tenerlo en cuenta al exportar/importar.

---

## Roles y Permisos

| group_id | Rol              | Descripción              |
| -------- | ---------------- | ------------------------ |
| 1        | Admin            | Acceso total             |
| 2        | User             | Usuario estándar         |
| 3        | Reseller/Manager | Gestor de clientes       |
| 4        | Demo             | Cuenta demo              |
| 5        | Operator         | Operador de dispositivos |
| 6        | Supervisor       | Supervisor de operadores |

**Métodos de comprobación en `User`:**

```php
isAdmin() / isReseller() / isOperator() / isSupervisor() / isManager() / isDemo() / isGod()
```

**Verificación en controladores:**

```php
$this->checkException('geofences', 'view');   // lanza PermissionException si no tiene permiso
$this->user->perm('geofences', 'edit')        // retorna bool
```

**Middleware de rutas:**

- `auth.admin` → solo `isAdmin()`
- `auth.manager` → `isAdmin() || isManager()`
- `active_subscription` → requerido en la mayoría de rutas autenticadas

---

## Estructura de Rutas

| Archivo          | Propósito                                               | Namespace de Controllers |
| ---------------- | ------------------------------------------------------- | ------------------------ |
| `routes/web.php` | Rutas frontend (+1000 líneas, agrupadas por middleware) | `Frontend\`, `Admin\`    |
| `routes/api.php` | API v1 con middleware `auth.api`                        | `Api\V1\`                |
| `routes/app.php` | Rutas adicionales de aplicación                         | —                        |

---

## Gotchas Comunes

- **Contexto API vs Web:** Muchas clases revisan `$this->api` o `Config::get('tobuli.api')` para alterar comportamiento. Setear con `Config::set('tobuli.api', 1)` o `ModalHelper::setApi(true)`.
- **Transacciones en importadores:** Siempre usar `beginTransaction()` / `commitTransaction()` / `rollbackTransaction()` (helpers globales, no `DB::beginTransaction()`).
- **Formateo de unidades:** `speed_limit` se almacena en unidad base; convertir con `Formatter::speed()->reverse()` al guardar y `Formatter::speed()->format()` al mostrar.
- **Geofence `polygon` oculto:** El campo `polygon` está en `$hidden` en la entidad, por eso los scopes/queries deben seleccionarlo explícitamente si se necesita.
- **Cache de grupos en importación:** `GeofenceImporter` usa `Cache::store('array')->rememberForever()` para evitar duplicar grupos dentro de un mismo proceso de importación.
- **Migraciones:** estilo Laravel 8 con **clase anónima** (`return new class extends Migration { … };`). Seguir ese patrón en migraciones nuevas.
- **Posiciones por dispositivo:** no consultar una tabla `positions` global; usar `$device->positions()` (tabla dinámica por device).
- **`speedLimitFor()` sobre colecciones cargadas:** si `vehicleSpeedLimits` ya está eager-loaded, `Geofence::speedLimitFor()` filtra en memoria; si no, consulta a BD. En reportes/pipeline, hacer `->with('vehicleSpeedLimits')` para evitar N+1.

---

## Directorios Clave

```
app/Http/Controllers/Frontend/   — Controllers de usuario final
app/Http/Controllers/Admin/      — Panel de administración
app/Http/Controllers/Api/V1/     — API REST v1
Tobuli/Entities/                 — Modelos Eloquent (lógica de dominio)
Tobuli/Services/                 — Servicios de negocio
Tobuli/Repositories/             — Acceso a datos (patrón repositorio)
Tobuli/Exporters/                — Lógica de exportación (GEXP, Excel, etc.)
Tobuli/Importers/                — Lógica de importación (KML, GeoJSON, GEXP)
Tobuli/Validation/               — Validadores de formulario
Tobuli/Views/                    — Blade views del dominio
Tobuli/History/                  — Pipeline de procesamiento de posiciones (Actions)
Tobuli/Reports/                  — Reportes (DeviceReport, ~130 tipos)
Tobuli/Helpers/Alerts/           — Alertas: Check (tipos), Notification (canales), Filter
Tobuli/Sensors/                  — Tipos de sensores de dispositivo
ModalHelpers/                    — Procesamiento de requests de formularios
Facades/                         — Custom facades (Repos, ModalHelpers, Validators)
resources/assets/js/controller/  — Controladores JS del frontend
socket/                          — Servidor Node.js de WebSocket
```

---

## Integraciones Externas

| Servicio           | Propósito                           | Config                                   |
| ------------------ | ----------------------------------- | ---------------------------------------- |
| Traccar GPSServer  | Backend de rastreo GPS              | `TraccarDevice` entity                   |
| Redis              | Caché, colas, pub/sub tiempo real   | `.env` REDIS\_\*                         |
| Socket.io          | WebSocket para actualizaciones live | `.env` SOCKET_PORT                       |
| SendGrid           | Correo transaccional                | `config/mail.php`                        |
| Plivo              | SMS                                 | `config/sms.php`                         |
| Azure AD           | SSO                                 | `config/azure.php`                       |
| FCM                | Push notifications                  | `config/fcm.php`                         |
| Stripe / Braintree | Pagos                               | `config/payments.php`                    |
| Google Roads API   | Velocidad máxima de vía             | `config/services.php` → `speedlimit.key` |

### Integraciones regulatorias (Perú)

El sistema reporta datos a entes/proveedores peruanos. Cada uno tiene su entidad y una relación `hasMany` desde `Device`, además de columnas flag en `devices` (`osinergmin`, `mininter`, `consatel`, `wisetrack`, `sidersales`, `epscenter`, `mtc`) que activan el envío:

| Integración        | Entidad / relación en `Device`        |
| ------------------ | ------------------------------------- |
| OSINERGMIN         | `Osinergmin` / `osinergminData()`     |
| MININTER (SUTRAN)  | `Mininter` / `dataMininter()`, `Sutran` / `sutran()` |
| Comsatel           | `Comsatel` / `dataComsatel()`         |
| WiseTrack          | `WiseTrack` / `dataWisetrack()`       |
| Sidersales         | `Sidersales` / `dataSidersales()`     |
| EPS Center         | `EpsCenter` / `dataEpsCenter()`       |

Al añadir un campo/flag de integración, recordar incluirlo en `Device::$fillable` (ya contiene estos flags).
