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
