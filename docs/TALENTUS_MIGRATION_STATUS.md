# TalentusPro · Estado de migración

> Snapshot al **31 de mayo de 2026** · Rama `nuevo-tema`
> Convenciones y plan macro: ver [plan de migracion.md](plan%20de%20migracion.md)

Documento vivo que resume **qué está implementado** en el shell nuevo y **qué falta** por migrar desde el legacy. Lo organizamos por dominio.

---

## 🔄 Actualización · 27 jun 2026 (rama `nuevo-tema`)

### Página Eventos (✅ hecha)
Rail → `/ui/v2/events` (`Ui\V2\EventsController`). Lista con **scroll infinito** (paginación por offset) + mapa MapLibre; al seleccionar un evento centra el mapa y muestra el **marcador real del device** con popup (geocoding perezoso solo de filas visibles vía `address.get`, respeta `geofence_over_address`). Tipo de mapa, geocercas/POIs/rutas compartidos con Live (`tp-map-shared`, estado en backend `user->map_controls` / `user->map_id`).

### Lista de dispositivos en Live (✅ varias mejoras)
- **Agrupada por group name** como el legacy (`liveData` devuelve `deviceGroups()`; `group_id` por pivot). Cabeceras colapsables + **toggle para desactivar la vista por grupos**. Virtualización de altura variable.
- **Botón menú (3 rayas) → lista de vehículos** (`/ui/v2/fleet`).
- **Filtro de estado** en rejilla **2×2** (Todos / Movimiento / Ralentí / Desconectado) con conteos; usa el estado **autoritativo** `Device::getStatus()` (no recalcula por timestamps). Labels en español + overlay del mapa + pill del topbar traducidos.
- **Tarjetas enriquecidas**: toggle "active" (mostrar en mapa, persiste vía `/devices/change_active`), velocidad con unidad del usuario (`speed_text`), color por estado en nombre/velocidad/fecha, y **menú kebab** server-rendered (`ui.v2.partials.device-actions`) que **espeja el dropdown legacy con el mismo gating de permisos** e iconos por ítem. Acciones nativas: historial/seguir/editar; el resto (comando, compartir, alertas, cámara, tipos de ruta, llamada, chat, qr, config, planes, estado vehículo, video) quedan como stubs (toast) hasta portarlas.
- **Conteo total real**: `liveData` usa `paginate()` (no cursor) → `pagination.total`; `fetchDevices` carga **todas las páginas** (arregla el cap de 1000/500). Sin `wasConnected()` → cuenta todas las unidades del usuario (`devices()->count()`).

### Marcadores de mapa (✅ Live + Eventos)
Marcador **real del device** según `DeviceIconTransformer` (icono incluido en `DeviceMapTransformer`), replicando `device.js updateLayer` para los 3 tipos: **arrow** (PNG por color de estado en `images/device_icons/estados/<color>.png`, rota por course), **rotating** (imagen propia, rota), **icon** (imagen propia, no rota, ancla abajo). Popup conciso en Live (estado, velocidad, hora, dirección perezosa, "Ver detalles" → drawer).

### UI
- **Botón crear (+)** = variante teal `.tp-btn--accent` (`--brand-accent: #1b99bd`), icono blanco, en Live/Fleet/Alerts (el azul del tema chocaba con el acento de marca).

### POIs · CRUD nativo (✅ nuevo)
`Ui\V2\MapLayersController`: `poiForm` / `storePoi` / `updatePoi` / `destroyPoi` reutilizando **`PoiUserService`** (create/edit/remove + permisos). Rutas `/ui/v2/api/pois{,/form,/{id}/form,/{id}}` (GET/POST/PUT/DELETE). Modal nativo `ui.v2.modals.poi-form` (nombre, descripción, grupo, grid de iconos). En `live-ops.js`: botón **"Agregar POI"** → modo colocar → clic en mapa → modal → guardar; clic en POI existente → popup **Editar/Eliminar**. Botón gateado por `perm('poi','edit')`.

### Pendiente inmediato
**Rutas CRUD** (dibujo de polilínea), **Enviar comando** (UI compleja: catálogo GPRS/SMS dinámico, schedules), **Compartir**, **Mantenimientos/Servicios**. Afinar: drawer (servicios/streetview/video), modal device (tabs Sensores/Usuarios/Servicios Web), reportes (Schedules), topbar Herramientas + idioma. Cosmético/debrand `.tp-*`/`window.Talentus`/cookies/marca: fuera de alcance hasta pedirlo.

---

## 🔄 Actualización · 26 jun 2026 (rama `nuevo-tema`)

> El shell se **debrandeó de `talentus` → `ui/v2`**: rutas `/ui/v2/*` (nombres `ui.v2.*`), controladores `App\Http\Controllers\Ui\V2\*` (shell = `Ui\V2\ShellController`), vistas `resources/views/ui/v2/`, assets fuente `resources/assets/ui-v2/` → compilados a `public/assets/ui/v2/`. La integración ERP **"Talentus" NO se tocó**. (Las rutas antiguas que se citan abajo como `/talentus/*` ya son `/ui/v2/*`.)

**Build**: TailwindCSS integrado en Gulp (`gulp ui-v2`); CSS minificado con clean-css y JS con terser; `gulp-sass` migrado a dart-sass. Fuentes legibles en `resources/`, salida minificada en `public/`.

**Botón "Nueva UI"** en el header legacy (`/objects`) → `ui.v2.live`.

**Widgets del dispositivo en Live · 1ª iteración** — `Ui\V2\DevicesController::widgets($id)` (`GET /ui/v2/api/devices/{id}/widgets`) renderiza `ui.v2.partials.device-widgets` reutilizando datos legacy (GeoLocation, eventos, cámaras, servicios, conductor, sensores) y **honra los widgets activos del usuario** (`getSettings('widgets')`). Se inyectan en el drawer de Live (`#tp-drawer-widgets`) al seleccionar un vehículo.

| Widget | Estado |
|---|---|
| Objeto + Telemetría (nativo en drawer) | ✅ |
| Ubicación (GeoLocation) | ✅ |
| Imagen | ✅ |
| Eventos recientes | ✅ |
| Cámara | ✅ |
| Conductor | ✅ |
| Servicios | ⚠️ lista básica (faltan campos/fechas exactas) |
| Sensores | ⚠️ nombre + tipo (falta valor actual de la última posición) |
| Vista de calle | ⚠️ requiere `streetview_key`/`google_maps_key` |
| Video en vivo | ⚠️ enlaza al modal legacy (stopgap) |

**Pendiente inmediato**: ~~página **Eventos**~~ ✅ hecha · ~~POIs CRUD~~ ✅ hecho (ver actualización 27 jun). Quedan: Rutas CRUD, comandos, compartir, mantenimientos, topbar Herramientas + idioma.

---

## 1 · Convenciones vigentes

- Rutas: `Route::group(['prefix' => 'talentus', 'as' => 'talentus.'])` en [routes/web.php](routes/web.php#L1105-L1162).
- Controllers: `App\Http\Controllers\Talentus\*Controller` extendiendo `BaseController`. Cross-cutting en [app/Http/Controllers/Frontend/TalentusController.php](app/Http/Controllers/Frontend/TalentusController.php).
- API JSON: `talentus.api.<dominio>.*` (CRUD `meta|form|store|edit|update|destroy`).
- Vistas: `resources/views/talentus/pages/<dominio>.blade.php` extendiendo `talentus.layout`.
- Partials de modales: `resources/views/talentus/modals/<dominio>/*`.
- Assets: `public/assets/talentus/{css,js}/<dominio>.*` con `?v=N`.
- Modales/drawers/toasts: `window.tpModal.{open,close,confirm,toast,tabs,fetchJSON,...}`.
- Permisos: `$this->user->perm('<dominio>', 'view|edit|remove')` (los únicos modos válidos según `config/permissions.php`).
- Reutilizar `ModalHelpers/`, `Repositories/`, `Validators/` — la UI nueva solo orquesta.

---

## 2 · Lo implementado en esta rama

### 2.1 Reorganización del controller monolítico

Se eliminó la concentración en `Frontend\TalentusController` (1 949 líneas, 42 métodos). Ahora hay 9 controllers por dominio bajo `App\Http\Controllers\Talentus\*`:

| Controller | Rol |
|---|---|
| [BaseController](app/Http/Controllers/Talentus/BaseController.php) | Helpers compartidos (`buildMapConfig`, `groupStatus`, `eventSeverity`, `haversine`) y constantes de preferencia (`PREF_THEME`, `PREF_DENSITY`, `PREF_MAP_ID`). |
| [DevicesController](app/Http/Controllers/Talentus/DevicesController.php) | Live + Fleet + CRUD modal de vehículos. |
| [DashboardController](app/Http/Controllers/Talentus/DashboardController.php) | Página Dashboard + KPIs. |
| [ZonesController](app/Http/Controllers/Talentus/ZonesController.php) | Geocercas (CRUD + import/export). |
| [AlertsController](app/Http/Controllers/Talentus/AlertsController.php) | Reglas + feed de eventos. |
| [HistoryController](app/Http/Controllers/Talentus/HistoryController.php) | Historial + tramos. |
| [ReportsController](app/Http/Controllers/Talentus/ReportsController.php) | Generación de reportes. |
| [AdminController](app/Http/Controllers/Talentus/AdminController.php) | Vistas administrativas (deeplinks a legacy). |
| [CopilotController](app/Http/Controllers/Talentus/CopilotController.php) | Asistente IA del shell. |

[Frontend\TalentusController](app/Http/Controllers/Frontend/TalentusController.php) queda como shell delgado (~220 líneas) con: `preview`, `legacy`, `search`, `notifications`, `preferences`.

> Total de rutas `talentus.*` actuales: **42**.

### 2.2 Bug fixes runtime

- Permiso CRUD: `perm('devices','create')` no existe en `config/permissions.php` (modos válidos: `view|edit|remove`) → reemplazado por `'edit'` en `live`, `fleet`, `store` ([DevicesController.php](app/Http/Controllers/Talentus/DevicesController.php)).
- Blade `@json` con array inline que contenía `{id}` rompía el parser → ahora se construye el array en bloque `@php ... @endphp` y se pasa por variable.
- `DeviceIcon::orderBy('order_no')` no existe → `orderBy('id','asc')`.
- `route()` rechaza `__ID__` por regex `[0-9]+` → se usa `url('/talentus/api/devices') . '/{id}/edit'`.

### 2.3 Modal de vehículo (Live + Fleet + Admin → Fleet)

Arquitectura **HTML-first** (server-side rendering):

| Capa | Archivo |
|---|---|
| Endpoint que renderiza Blade | [DevicesController::form()](app/Http/Controllers/Talentus/DevicesController.php) → `GET /talentus/api/devices/form` y `GET /talentus/api/devices/{id}/form` |
| Vista contenedora con tabs | [resources/views/talentus/modals/devices/form.blade.php](resources/views/talentus/modals/devices/form.blade.php) |
| Partial: Principal | [partials/principal.blade.php](resources/views/talentus/modals/devices/partials/principal.blade.php) |
| Partial: Iconos (galería + colores por estado) | [partials/iconos.blade.php](resources/views/talentus/modals/devices/partials/iconos.blade.php) |
| Partial: Información (SIM, VIN, placa, propietario, notas, etc.) | [partials/informacion.blade.php](resources/views/talentus/modals/devices/partials/informacion.blade.php) |
| Partial: Exactitud (motor, velocidad, combustible, LBS) | [partials/exactitud.blade.php](resources/views/talentus/modals/devices/partials/exactitud.blade.php) |
| Partial: Cola (color + longitud) | [partials/cola.blade.php](resources/views/talentus/modals/devices/partials/cola.blade.php) |
| Placeholders condicionales | `partials/usuarios.blade.php`, `partials/sensores.blade.php`, `partials/webservices.blade.php` |
| JS minimal (fetch HTML, wire tabs/submit/galería) | [public/assets/talentus/js/device-modal.js](public/assets/talentus/js/device-modal.js) |
| Estilos | [public/assets/talentus/css/device-modal.css](public/assets/talentus/css/device-modal.css) |

El JS reduce sus responsabilidades a:

1. `tpModal.open()` con cuerpo "Cargando…".
2. `fetch` del HTML al endpoint Blade e inyectarlo.
3. Wire de pestañas, galería de iconos y mirror del `enable_expiration_date`.
4. Submit POST/PUT con `FormData` → objeto (soporta `icon_colors[moving]` → `data.icon_colors.moving`).

Reutiliza **íntegro** `DeviceModalHelper::createData()/editData()/create()/edit()` legacy: mismos catálogos, misma validación, mismos `name=`.

Modal **tabbed** con paridad legacy:

| Pestaña | Estado |
|---|---|
| Principal | ✅ |
| Usuarios *(admin)* | ⚠️ Placeholder → deeplink a legacy |
| Iconos | ✅ |
| Información | ✅ |
| Sensores *(admin)* | ⚠️ Placeholder |
| Exactitud | ✅ |
| Cola | ✅ |
| Servicios Web *(perm)* | ⚠️ Placeholder |

### 2.4 Botón "+ Nuevo" en Live y Fleet

Se cableó `#tp-live-create-device` y el botón equivalente en Fleet a `window.tpDeviceModal.openCreate(...)`. Comparten la misma instancia.

### 2.5 Endpoint `meta()` enriquecido

[DevicesController::meta()](app/Http/Controllers/Talentus/DevicesController.php) ahora expone también `icon_types`, `icon_colors`, `fuel_detect_stops`, `operator_sim`, `icons_grouped`, `caps` y `defaults` extendidos (colores de estado, mín. movimiento, mín. fillings/thefts, etc.).

> Nota: con el rediseño HTML-first, el front del modal ya **no consume `/meta`** (Blade los lee directo del `DeviceModalHelper`). Se mantiene útil para otros consumidores (filtros, dropdowns en otras pantallas).

### 2.6 Modal base · `window.tpModal`

Añadido tamaño `xl` (980 px) en [modal.js](public/assets/talentus/js/modal.js) + [modal.css](public/assets/talentus/css/modal.css) para acomodar el form tabbed.

---

## 3 · Cobertura por dominio (estado actual)

Leyenda: ✅ migrado · ⚠️ parcial · ❌ falta

| Dominio legacy | TalentusPro | Estado |
|---|---|---|
| `Layouts/loged.blade.php` (live shell) | `live` | ✅ |
| `Dashboard/` | `dashboard` | ✅ |
| `Devices/` (lista, create, edit, destroy) | `fleet` + modal CRUD | ⚠️ Falta: sensores, asignación masiva de usuarios, servicios web integrados, beacons, follow_map, positions_backups |
| `DevicesGroups/` | — | ❌ |
| `Geofences/` · `GeofencesGroups/` | `zones` | ✅ |
| `Routes/` · `RoutesGroups/` | sub-zones | ⚠️ Sin CRUD propio |
| `Alerts/` · `Events/` | `alerts` | ✅ reglas y feed |
| `DeviceAlerts/` · `CustomEvents/` | — | ❌ |
| `History/` | `history` | ✅ |
| `Reports/` + `Reports/partials` | `reports` | ⚠️ Falta `Schedules/` |
| `MyAccount/` · `MyAccountSettings/` · `Languages/` | — | ❌ |
| `Pois/` · `PoisGroups/` | toggle informativo en live | ❌ Falta CRUD + endpoint |
| `Chat/` | — | ❌ |
| `UserDrivers/` · `UserGprsTemplates/` · `UserSmsTemplates/` · `SmsGateway/` | — | ❌ |
| `Sensors/` · `DeviceConfig/` · `LockStatus/` · `CallAction/` · `SendCommand/` | sólo legacy modal | ❌ UI nativa |
| `Services/` · `DeviceExpenses/` · `Maintenance/` · `Tasks/` · `TaskSets/` · `Checklist/` · `ChecklistTemplates/` | — | ❌ |
| `DeviceMedia/` · `DeviceVideo/` · `Video/` · `MediaCategories/` | — | ❌ |
| `Forwards/` · `Sharing/` · `SecondaryCredentials/` · `Subscriptions/` · `DeviceSubscription/` · `DevicePlans/` · `Beacons/` · `Lookup/` · `Addresses/` · `Docs` · `IconsModal/` · `Popups/` · `Objects/` · `ObjectsList/` · `Widgets/` · `Import/` · `DeviceRoutesType/` · `LoginAs/` | `admin` (deeplinks) | ⚠️ Sólo enlace |
| `Login/` · `Registration/` · `PasswordReminder/` · `EmailConfirmation/` · `CustomRegistration/` · `Captcha/` | — | ❌ Solo legacy |

---

## 4 · Roadmap por olas (extracto · prioridad)

### Ola 1 — Esenciales (parcialmente en curso)

| # | Pendiente | Notas | Esfuerzo |
|---|---|---|---|
| 1.1 | Modal device CRUD | ✅ Hecho (esta rama) | — |
| 1.2 | Drawer detalle del vehículo (Fleet) + acceso nativo a sensores / config / iconos | Reutilizar `Sensors/`, `DeviceConfig/`, `IconsModal/`; ampliar `api.devices` con `sensors` | L |
| 1.3 | Send command desde drawer Live | `talentus.api.commands.{list,send}` apoyado en `SendCommand/` + `CallAction/` | S |
| 1.4 | POIs nativos (CRUD + toggle real en mapa) | `Pois/`, `PoisGroups/` → `talentus.api.pois.*` | M |
| 1.5 | Routes nativos (subsección de zones o página propia) | `Routes`, `RoutesGroups/` → `talentus.api.routes.*` | M |

### Ola 2 — Operación diaria

| # | Pendiente | Esfuerzo |
|---|---|---|
| 2.1 | `DevicesGroups` filtrable desde Fleet | S |
| 2.2 | Drivers (`UserDrivers`) y asignación a vehículos | S |
| 2.3 | `DeviceAlerts` por vehículo (drawer en fleet/live) | S |
| 2.4 | `CustomEvents` dentro de Alerts | S |
| 2.5 | `Schedules` dentro de Reports (programar envío) | M |
| 2.6 | `Sharing` (links públicos por vehículo) | M |

### Ola 3 — Mantenimiento y operaciones

| # | Pendiente | Esfuerzo |
|---|---|---|
| 3.1 | `/talentus/maintenance` con tabs (Servicios · Gastos · Mantenimientos · Tareas · Checklists) | L |
| 3.2 | `/talentus/media` (galería de fotos/videos por vehículo) | M |
| 3.3 | `/talentus/chat` (mensajería interna) | M |

### Ola 4 — Cuenta + integraciones

| # | Pendiente | Esfuerzo |
|---|---|---|
| 4.1 | `/talentus/account` (perfil, idioma, zona horaria, plantillas SMS/GPRS personales) | M |
| 4.2 | `/talentus/admin/forwards` nativo | S |
| 4.3 | `/talentus/admin/sms-gateways` nativo | S |
| 4.4 | `/talentus/admin/secondary-credentials` nativo | S |
| 4.5 | Importar/Exportar masivo (vehículos, POIs, geocercas, drivers) | M |

### Ola 5 — Suscripciones, billing y onboarding

| # | Pendiente | Esfuerzo |
|---|---|---|
| 5.1 | `/talentus/billing` (planes, suscripción, facturación) | L |
| 5.2 | `/talentus/objects` (catálogo de objetos rastreables / beacons) | M |

### Ola 6 — Públicas (fuera del shell)

| # | Pendiente | Esfuerzo |
|---|---|---|
| 6.1 | Login moderno | S |
| 6.2 | Registro + custom registration multitenant | M |
| 6.3 | Recuperar contraseña + confirmación de email | S |
| 6.4 | Página pública de tracking compartido | M |

### Ola 7 — Páginas residuales / utilidades

| # | Pendiente | Esfuerzo |
|---|---|---|
| 7.1 | Selector de iconos nativo (drawer) | S |
| 7.2 | Lookup global de direcciones | S |
| 7.3 | Documentación API en `/talentus/docs` | S |
| 7.4 | LoginAs (suplantación de usuario) | S |
| 7.5 | Centro de widgets embebibles | M |
| 7.6 | Bandeja de popups del operador | S |

---

## 5 · Próximas tareas concretas para la siguiente sesión

Prioridad sugerida — todas son trabajo "feature" pequeño/mediano:

1. **Drawer de Fleet** → reusar el endpoint `devices/{id}/form` con flag de "read-only" o `details=1` que renderice un Blade resumen (no formulario). Permite ver datos sin el costo de cargar el modal.
2. **POIs nativos** (`talentus.api.pois.*`) reutilizando `Pois/` repos + activar el toggle del mapa que hoy es informativo.
3. **Pestaña Sensores del modal device** — convertir la fila dinámica de `Tobuli/Views/Frontend/Devices/partials/sensors.blade.php` en un partial nuevo `resources/views/talentus/modals/devices/partials/sensores.blade.php` con add/remove rows. Reutilizar `SensorModalHelper`.
4. **Pestaña Servicios Web** — el legacy tiene un partial por proveedor (`consatel`, `mininter`, `sutran`, `osinergmin`, `wisetrack`, `sidersales`, `epscenter`). Cada uno con sus campos. Migrarlos 1 a 1 a partials Blade, gateados por `perm('webservice.<x>','view')`.
5. **Schedules** (envío programado de reports) — completar el dominio de reportes.
6. **DevicesGroups CRUD** — paso previo para filtrar Fleet por grupo.
7. **Send command desde drawer Live** — endpoint corto, valor alto.

---

## 6 · Cómo seguir

```powershell
git checkout nuevo-tema
git log --oneline -5
```

- Los partials Blade comparten variables con `DeviceModalHelper::createData()/editData()` → si se necesita un dato nuevo en el form, primero **expónlo en el ModalHelper** y luego úsalo en Blade.
- El JS del modal **no debe crecer** salvo para nuevos comportamientos (no para templating). Si necesitas más HTML, agrega un partial Blade.
- Cuando agregues una nueva pestaña, basta crear `resources/views/talentus/modals/devices/partials/<key>.blade.php` y registrarla en el array `$tabs` de [form.blade.php](resources/views/talentus/modals/devices/form.blade.php) (con su `caps` condicional si aplica).

---

**Última actualización:** 31 mayo 2026 · rama `nuevo-tema`
