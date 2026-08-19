# Plan de migración — Legacy → UI v2

> **Reescrito el 6 de agosto de 2026** tras la auditoría exhaustiva legacy↔v2 (6 agentes, dominios:
> Devices/Sensores · Capas de mapa · Comandos/Sharing/Tareas/Chat · Historial/Reportes/Eventos/Dashboard ·
> Cuenta/Plataforma · Core transversal de contratos de datos). Reemplaza la versión anterior, que
> estaba desactualizada (prefijo `/talentus/*`, módulos marcados como faltantes que ya están migrados).
> Estado detallado por dominio: [docs/TALENTUS_MIGRATION_STATUS.md](docs/TALENTUS_MIGRATION_STATUS.md) §3
> y la actualización "AUDITORÍA EXHAUSTIVA · 6 ago 2026".

---

## 0 · Resumen ejecutivo

La v2 ya cubre la operación diaria (Live, Fleet, modal device 8 tabs, geocercas/POIs/rutas, alertas,
eventos, historial, reportes, comandos+schedules+plantillas, sharing, conductores, tareas, chat,
call-actions, mantenimiento/servicios, gastos, reenvíos, cuenta). **PERO la auditoría encontró
defectos de paridad que afectan el funcionamiento** — corrupción de datos por unidades/zona horaria,
huecos de seguridad (IDOR, gates ausentes) y features que se rompen con addons activos. **La Ola 0
(correcciones) va ANTES que cualquier módulo nuevo.**

> **Estado 6-ago (tarde):** aplicados S1 (IDOR chat), S2 (colisión expenses), S3 (gates vehicle
> types), S6 (gates gastos), D1 (expiración device), D2 (speed_limit geocerca), D4 (FTP reportes),
> D5 (salvaguarda alertas), D6 (detach horarios), D13/D14 (eventos TZ+alcance). S4 resultó **falso
> positivo** (la policy de geocercas SÍ corre vía `ModelUserServiceTrait`).
>
> **Segundo lote (6-ago noche):** S5+D8 (CallActions: user_id forzado, whitelist, fecha ISO),
> D3 (Sharing TZ), D9+F4 (plantillas: no desasociar devices, sin 500 para view-only) y **D14 en
> Dashboard** (`scopeEvents` = propios + alertas compartidas en las 6 queries, + `convert()` en
> notificaciones).
>
> **Tercer lote (7-ago):** D7 (visibilidad de geocercas/POIs + no reactivar al editar) y F2
> (import/export nativos de geocercas y POIs).
>
> **Cuarto lote (7-ago):** F1 (snap_to_road real + gate por API key) y F5 (orden del data-log +
> km por odómetro). D10/D11 quedan aplazados a propósito: `custom_fields_task` está OFF y
> `custom_fields` no tiene ningún campo definido (0 filas) → sin impacto hoy.
>
> **Quinto lote (7-ago):** F6 parcial (borrado de eventos + rango de fechas absoluto).
>
> **Sexto lote (7-ago):** F3 (export: 500 de grupos en rutas + filtro por ids en activas/inactivas).
>
> **Séptimo lote (7-ago):** D16 (password del SMS gateway se borraba en cada guardado) y D15 2ª
> parte (opciones `period` por bloque del Tablero). D15 1ª parte = falso positivo, documentado.
>
> **Quedan abiertos:** S7–S9 · D10–D12 · F5 (borrar posiciones) · F6 (plugins título/dirección) ·
> F7 · F16 y resto de la lista F.

---

## 1 · OLA 0 — Correcciones de paridad (obligatorias, salen de la auditoría)

### 1.1 Seguridad (primero)

| # | Defecto | Dónde | Sev |
|---|---------|-------|-----|
| S1 | ✅ **CORREGIDO 6-ago** — IDOR en Chat: `messages`/`send` ahora hacen `checkException('chats','show'/'update',$chat)` → `ChatPolicy::ownership` (paridad legacy) | `Ui/V2/ChatController.php` | CRÍTICO |
| S2 | ✅ **CORREGIDO 6-ago** — Colisión de rutas `expenses/{id}`: el modal device pasó a URI propia `devices/expenses/{id}` (`api.devices.expenses.update/destroy`); la página Gastos vuelve a `ExpensesController`. Verificado con `route:getByName` | `routes/web.php`, `gastos.blade.php` | CRÍTICO |
| S3 | ✅ **CORREGIDO 6-ago** — `VehicleTypesController` gateado con el módulo `devices` (view para data, edit para store/update/destroy). NO se creó un módulo `vehicle_types` nuevo: `User::perm()` devuelve `false` para módulos no sembrados en `user_permissions` → habría apagado la feature para todos los usuarios existentes | `Ui/V2/VehicleTypesController.php` | CRÍTICO |
| S4 | ❎ **FALSO POSITIVO** (verificado 6-ago): `GeofenceUserService` usa `ModelUserServiceTrait`, cuyos `create/edit/remove` SÍ aplican `Manager::check('geofences',…,$model)` (los métodos de trait tienen precedencia sobre los heredados) → la policy read-only/demo se aplica en legacy Y v2 (mismo service). El `can('view')` del controller es solo un pre-check redundante. **Sin cambios** (decisión: paridad legacy) | — | — |
| S5 | ✅ **CORREGIDO 6-ago (2)** — `user_id` forzado al usuario autenticado + whitelist `Arr::only` en `prepare()` (antes el request crudo iba a create/update = mass-assignment) | `Ui/V2/CallActionsController.php` | MEDIO |
| S6 | ✅ **CORREGIDO 6-ago (gates)** — gastos del modal device ahora exigen `perm('device_expenses', edit/remove/view)` además del acceso al device. Pendiente MENOR: unificar con `DeviceExpensesFormValidator` | `Ui/V2/DevicesController.php` | MEDIO |
| S7 | Alta de device sin el gate `custom_device_add` (legacy PROHÍBE el alta estándar a esos usuarios) | `Ui/V2/DevicesController.php:284-296` | MEDIO |
| S8 | `AdminController@data` expone `api_hash`/webhooks sin validar admin; `ShellController` preferences sin `isDemoUser()` | `Ui/V2/AdminController.php:19-28`, `ShellController.php:196-284` | MENOR |
| S9 | Grupos de vehículos: gate `devices:edit` en vez del flujo legacy + sin `DeviceGroupFormValidator` | `Ui/V2/DevicesController.php:442-512` | MEDIO |

### 1.2 Corrupción / pérdida de datos

| # | Defecto | Dónde | Sev |
|---|---------|-------|-----|
| D1 | ✅ **CORREGIDO 6-ago** — expiración del device: el blade ahora pinta `Formatter::time()->convert()` (la BD guarda **UTC**, verificado `app.timezone='UTC'`; el helper hace `reverse()` TZ usuario→UTC al guardar — regla espejo). Y al CREAR el checkbox va **desmarcado** (la fecha precargada queda como sugerencia), paridad legacy `main.blade`. El bug venía del fix "expiración con hora" del 29-jul (commit 08dc9dc) | `principal.blade.php` | CRÍTICO |
| D2 | ✅ **CORREGIDO 6-ago** — `speed_limit` de geocerca: `data()` y `presentZone()` ahora devuelven `round(Formatter::speed()->convert(...))` (base→unidad usuario), igual que `vslMap` y el legacy `edit()`. El guardado ya hacía `reverse()` — con el espejo completo deja de multiplicarse | `Ui/V2/ZonesController.php` | CRÍTICO |
| D3 | ✅ **CORREGIDO 6-ago (2)** — `reverse()` al guardar y `convert()` en el meta (regla espejo); los links dejan de caducar desfasados. Pendiente aparte: canal SMS (F11) | `Ui/V2/SharingController.php` | CRÍTICO |
| D4 | ✅ **CORREGIDO 6-ago** — `send_to_ftp` vuelve a ser un **input de URL** (`ftp://usuario:contraseña@host:puerto`, prefill desde `report.ftp`); el payload envía el string y `ReportsController` expone `ftp` como string (no bool). reports.js ?v=10 | `reports.js`, `ReportsController.php` | CRÍTICO |
| D5 | ✅ **SALVAGUARDA 6-ago** — `AlertsController@update`: si el request no trae la clave `devices`, se inyecta la asignación actual antes de `AlertModalHelper::edit()` (evita el `sync([])` destructivo del modo api). En legacy web no ocurría (loader = no-op sin secuencia); la rama api es de la API móvil | `Ui/V2/AlertsController.php` | CRÍTICO |
| D6 | ✅ **CORREGIDO 6-ago** — `mergeSelectedDevices` antepone `"s;0;"` (deselect-all) → la selección es exacta y `syncLoader` ya desasigna los vehículos quitados de un horario | `Ui/V2/CommandsController.php` | CRÍTICO |
| D7 | ✅ **CORREGIDO 7-ago** — el update ya no manda `active:1` (solo el create) y hay **checkbox de visibilidad** por fila en geocercas y POIs → `POST zones/{id}/toggle` · `POST pois/{id}/toggle` (paridad `change_active`). El feed de POIs expone `active`/`description` y el mapa omite los inactivos | `live-zones.js`, `live-pois.js`, `ZonesController`, `MapLayersController` | CRÍTICO |
| D8 | ✅ **CORREGIDO 6-ago (2)** — el meta devuelve `called_at` con `convert()` en formato ISO (`Y-m-d H:i:s`), que el JS sí parsea; editar ya no sobrescribe la fecha | `Ui/V2/CallActionsController.php` | MEDIO |
| D9 | ✅ **CORREGIDO 6-ago (2)** — al editar con `adapted ≠ devices` el controller preserva la asignación actual (el modo api hacía `sync([])`) | `Ui/V2/CommandsController.php` | MEDIO |
| D10 | Tareas: `custom_fields_task` no se guardan ni se muestran (falta `CustomValuesService::saveCustomValues` + panel) | `Ui/V2/TasksController.php:166-191` | CRÍTICO (con addon) |
| D11 | Device: tab **Custom Fields ausente** → campos personalizados no editables desde v2 | `modals/devices/form.blade.php` | CRÍTICO (con addon) |
| D12 | Geocercas con addon `reports_geofleet_in`: falta `additional[intervals]` (required) → **todo guardado devuelve 422** | `live-zones.js` | CRÍTICO (con addon) |
| D13 | ✅ **CORREGIDO 6-ago (página Eventos)** — `data()`/`mapData()` devuelven `time` con `Formatter::time()->convert()` (UTC→TZ usuario): lista, popup y el salto a Historial quedan en la hora del usuario. Dashboard: `convert()` en notificaciones ✅ (6-ago noche) | `EventsController` + `DashboardController` | CRÍTICO |
| D14 | ✅ **CORREGIDO 6-ago (página Eventos)** — `data()`/`mapData()` usan `Event::userAccessible($user)` (propios + alertas compartidas vía `alert_user`), como el legacy; `index()` ya lo usaba. Dashboard ✅ (6-ago noche): `scopeEvents()` aplica el mismo alcance a sus 6 queries | `EventsController` + `DashboardController` | CRÍTICO |
| D15 | ⚠️ **REVISADO 7-ago** — la 1ª parte (toggles descartados) es **FALSO POSITIVO**: `getSettings(key, merge=true)` ya mezcla el catálogo de `config('tobuli.dashboard.blocks')`, así que un usuario sin ajustes recibe los 6 bloques (verificado con un user cuyo JSON no tiene la clave). ✅ La 2ª parte sí se corrigió: `options.period` (Día/Semana/Mes) ahora es editable y se mezcla sin pisar colores/devices | `Ui/V2/AccountController.php`, `tp-account.js` | MEDIO |
| D16 | ✅ **CORREGIDO 7-ago** — era peor de lo reportado: el `?? actual` NO cubría la cadena vacía, así que **cada guardado del tab SMS borraba la contraseña** del gateway. Ahora vacío = conservar. Verificado sobre BD: sin fix quedaba `""`, con fix se conserva | `Ui/V2/AccountController.php` | MEDIO |

### 1.3 Funcionalidad rota o inalcanzable

| # | Defecto | Sev |
|---|---------|-----|
| F1 | ✅ **CORREGIDO 7-ago** — `getApi()` aplica `snapToRoad()` a las posiciones de cada tramo (igual que `get()`), y el checkbox se oculta si no hay `services.snaptoroad.key` (paridad legacy) | — |
| F2 | ✅ **CORREGIDO 7-ago** — modales v2 nativos: export por form-POST a los endpoints legacy de descarga (`geofences.export_create` / `pois.export.store`) con tipo + selección; import por FormData (`api.zones.import` y el nuevo `api.pois.import`, que exige `map_icon_id` como el legacy). Botones de importar gateados por `perm(...,'edit')` | — |
| F3 | ✅ **CORREGIDO 7-ago** — `export_groups` fuera del selector de rutas (el `ExportManager` no lo mapea → `LogicException`; el legacy tampoco lo ofrece) y los tipos activas/inactivas ya envían los **ids** que exige el filtro `Singles*` (verificado: con ids 1 fila vs 4 sin ids). Geocercas/POIs, que sí soportan grupos, ganan selector de grupos | — |
| F4 | ✅ **CORREGIDO 6-ago (2)** — `templates()` gatea por `perm(modulo,'view')` y solo llama `createData()` con `edit` | — |
| F5 | ⚠️ **PARCIAL 7-ago** — ✅ orden del data-log (`sorting` con la forma que el helper entiende + botón asc/desc) y ✅ km por odómetro (clave aditiva `distance_sum_odometer`). **Pendiente**: borrar posiciones (`history.remove`) sin superficie | MEDIO |
| F6 | ⚠️ **PARCIAL 7-ago** — ✅ borrado individual (popup) y masivo (pie de lista) con `perm('events','remove')` + alcance `userControllable`; ✅ filtro por **rango de fechas absoluto** (`date_from`/`date_to` con `reverse()`). **Pendiente**: columna título (plugin `event_section_alert`) y gate `event_section_address` | MEDIO |
| F7 | Geocercas: `device_id` (plugin `moving_geofence`) no configurable desde v2 (meta muerto); confirmación con contraseña (`confirmed_action`) ausente en DELETEs | MEDIO |
| F8 | Iconos del device: los selects de color no viajan si el tipo ≠ arrow (v2 los `disabled`; legacy solo los oculta) | MEDIO |
| F9 | Sensores: tab **solo admin** en v2 (legacy lo muestra a todos); icono por fila de mapping no seleccionable; import de calibraciones ausente | MEDIO |
| F10 | Beacons: el modal v2 abre el form estándar y el guardado revienta con 422 "Device is kind of beacon" | MEDIO |
| F11 | Sharing: canal **SMS** ausente; submódulo sharing_device (por-vehículo del link) ausente | MEDIO |
| F12 | Widget drawer: `template_webhook` (addon `widget_template`) sin implementar; `listsTranslations()` no corre en `widgets()` (riesgo de drawer vacío) | MEDIO |
| F13 | Dashboard: block `latest_tasks` ausente; `device_overview` pierde "inactivo"/"nunca conectado" y colores de usuario; `device_distance` muestrea top-15 por `updated_at` (falso en flotas grandes) | MEDIO |
| F14 | ObjectsList (listview con columnas configurables + rangos de color por sensor) sin equivalente — `fleet` tiene columnas fijas | MEDIO |
| F15 | Respuestas de error inconsistentes: dos formas de 403 conviven; varios destroy/store devuelven 500 donde el legacy da 422/403 (`ServicesController` convierte PermissionException en 500) | MEDIO |
| F16 | Cuenta: tabs **Credenciales secundarias** y **Categorías de medios** ausentes; SMS de prueba / vaciar cola ausentes; grupos sin asignación de devices/forwards en el tab | MEDIO |
| F17 | Device modal: tab Historial de mantenimientos/suspensiones ausente; `configure_device`+APN en el alta ausente | MEDIO |
| F18 | LockStatus v2: sin validadores legacy (`LockStatusTableValidator`/`UnlockValidator`), historial sin paginar | MENOR |
| F19 | Popups del admin (addon `notification_popops`) nunca se muestran en v2 (layout no incluye los partials) | MEDIO |

---

## 2 · Contratos de datos obligatorios (reglas transversales)

Toda migración o fix DEBE respetar estos contratos (violarlos causó la mayoría de los bugs de arriba):

1. **EntityLoaders (`selected_<key>`)** — multiselects grandes viajan como secuencia `"campo;status;valor"`:
   `"s;1;"` = select-all (→ `hasSelectAll`, p.ej. `for_all_user_devices`) · `"s;0;"` = deselect-all y
   **resetea** la secuencia · `"id;1;<id>"`/`"id;0;<id>"` = attach/detach. **Campos válidos = `Filter::key()`
   del loader** (`id`, `group_id`, `s`) — un campo desconocido se descarta en silencio. Contrato clave:
   **sin secuencia → `syncLoader` no toca nada** (asignaciones intactas); para una selección exacta SIEMPRE
   anteponer `"s;0;"`. Consumidores: alerts, drivers, sharing, reports, commands, gprs/sms templates,
   device users (`selected_user_id`).
2. **Flag `api` (`setApi`)** — cambia el comportamiento de los ModalHelpers: api ⇒ `sync(data['devices'])`
   (¡la clave ausente = desasignar todo!), catálogos `apiArray`, `status:1`; web ⇒ `syncLoader` +
   `status:2` en reports. Documentar en cada controller v2 qué modo usa y POR QUÉ; si se usa api,
   **garantizar las claves de sync siempre presentes**.
3. **`Formatter` (unidades y zona horaria)** — campos almacenados en unidad/hora base que el server
   `reverse()` al guardar: `speed_limit` (geocerca y reportes), `vehicle_speed_limits`, `overspeed`,
   `speed_tolerance`, `distance` (alerta), `expiration_date` (device y sharing), `called_at`,
   fechas de historial/eventos. **Regla de espejo: lo que se `reverse()` al guardar se `convert()` al
   pintar.** Pintar crudo de BD ⇒ deriva acumulativa en cada guardado (bugs D1/D2/D3).
4. **Checkboxes** — patrón legacy `Form::hidden(x,0)+checkbox`: en JSON enviar SIEMPRE 0/1 explícito…
   **excepto** los campos leídos con `isset()` donde la ausencia es semántica (`renew_after_expiration`;
   no enviar 0). `enable_expiration_date` sí necesita el 0.
5. **Respuestas** — legacy: `status:1` ok · `status:2` ok+refresh · 422 `{errors:{campo:[msgs]}}` ·
   403 `{perm:0}`. En v2: capturar `ValidationException` → 422 `{ok:false,errors}` y `PermissionException`
   → 403 (NUNCA convertir en 500 con `catch (\Throwable)`).
6. **Permisos** — cada endpoint mutante pasa por `checkException($modulo,$accion,$model)` o el `check()`
   del `*UserService`, usando **el mismo módulo que el legacy** (chat→`chats`, gastos→`device_expenses`).
   `perm()` solo no aplica policies por entidad (ownership).
7. **Escrituras** — prohibido Eloquent directo si existe ModalHelper/Service/FormValidator legacy;
   la UI v2 **solo orquesta**. Si el legacy no tiene service (p.ej. CommandSchedule), replicar su
   validación y policy.
8. **Gates de addon/plugin (regla de oro vigente)** — copiar EXACTO todo `config('addon.*')` /
   `settings('plugins.*.status')` del legacy.

---

## 3 · Cobertura actual (post-auditoría)

✅ = paridad razonable · ⚠️ = migrado con defectos de la Ola 0 · ❌ = sin migrar

| Dominio | Estado | Notas |
|---|---|---|
| Live shell + mapa + widgets + kebab | ✅/⚠️ | F8, F12 |
| Fleet + modal device (8 tabs) | ⚠️ | ~~D1~~✅, D11, F9, F10, F17, ~~S6~~✅, S7 |
| Geocercas | ⚠️ | ~~S4~~ falso positivo, ~~D2, D7, F2~~✅; D12, F7 (grupos solo lectura) |
| POIs | ✅/⚠️ | ~~D7, F2~~✅; grupos solo lectura |
| Rutas | ✅ | ~~F3~~✅; CRUD+grupos+tabla ✅ |
| Alertas (reglas + modal 6 tabs) | ✅/⚠️ | ~~D5~~✅ salvaguarda |
| Eventos (página) | ✅/⚠️ | ~~D13, D14~~✅; F6 parcial (falta plugin título/dirección) |
| Historial | ✅/⚠️ | ~~F1~~✅; F5 parcial (falta borrar posiciones) |
| Reportes | ✅/⚠️ | ~~D4~~✅; schedules ✅ |
| Comandos (modal + hub) | ✅ | ~~D6, D9, F4~~✅ |
| Sharing | ⚠️ | ~~D3~~✅; F11 (canal SMS) |
| Conductores | ✅ | falta activity log (menor) |
| Tareas + conjuntos | ⚠️ | D10; filtros de fecha (menor) |
| Chat | ✅/⚠️ | ~~S1~~✅; user↔user y paginación (menor) |
| CallActions | ✅ | ~~S5, D8~~✅ |
| Mantenimiento/Servicios | ✅/⚠️ | F15 (500s) |
| Gastos | ✅/⚠️ | ~~S2, S6~~✅; validator legacy pendiente (menor) |
| Reenvíos | ✅/⚠️ | TYPE_FIELDS hardcodeado (deriva futura) |
| Cuenta (modal ajustes) | ✅/⚠️ | ~~D15, D16~~✅; F16 (tabs credenciales/categorías, SMS test) |
| Dashboard | ⚠️ | ~~D14~~✅; F13 (blocks faltantes) |
| Tipos/estados de vehículo | ✅/⚠️ | ~~S3~~✅ (gate módulo `devices`) |
| LockStatus (widget bloqueo) | ✅/⚠️ | F18 |
| **Sin migrar** | ❌ | DeviceConfig · DeviceMedia/Video/MediaCategories · Checklist/Templates · DeviceAlerts · Popups (F19) · ObjectsList (F14) · CustomFields (D11) · Import masivo devices · Beacons · Subscriptions/DevicePlans/DeviceSubscription · SecondaryCredentials · SmsGateway (test/cola) · DeviceRoutesType · Lookup · Docs · LoginAs · Addresses (vista) · Auth público (Login/Registro/…) |

---

## 4 · Olas de ejecución (re-priorizadas)

### Ola 0 — Correcciones de paridad (ver §1) — ANTES de módulos nuevos
Orden sugerido: **S1→S4** (seguridad) → **D1–D7, D13–D14** (corrupción activa) → **F1–F4** →
resto de D/F por severidad. Muchas son fixes de horas, no días.

### Ola 1 — Cerrar el modal device y capas
1.1 Tab Custom Fields (D11) + panel en Tareas (D10) — mismo `CustomValuesService`.
1.2 Grupos de geocercas y POIs con CRUD (paridad Rutas) + toggle `active` + tablas dedicadas.
1.3 Import/Export nativo de geocercas y POIs (F2) + fix export de rutas (F3).
1.4 Tab Historial de mantenimientos + `configure_device`/APN en alta (F17).
1.5 Beacons (edit_beacon) (F10).

### Ola 2 — Módulos operativos faltantes
2.1 DeviceAlerts (periodo de vigencia por pivote — form pequeño). S/M
2.2 DeviceConfig (marca/APN/comandos de configuración por SMS). M
2.3 ObjectsList: columnas configurables + rangos de color por sensor en Fleet (F14). M
2.4 Popups del admin en el layout v2 (F19). M
2.5 DeviceRoutesType. S
2.6 Import masivo de devices. M

### Ola 3 — Media y checklists
3.1 DeviceMedia + MediaCategories (galería, ZIP, categorías). L
3.2 DeviceVideo/Video (live + playback Howen/JT808). L
3.3 Checklist + ChecklistTemplates (addon; QR ya enlazado). L

### Ola 4 — Cuenta/plataforma restante
4.1 Tabs Credenciales secundarias + Categorías de medios + SMS test/cola (F16). S/M
4.2 Opciones por block del Tablero + blocks faltantes (D15, F13). M
4.3 Selector de idioma completo. S
4.4 SmsGateway helpers. S

### Ola 5 — Comercial y utilidades
5.1 Subscriptions/DeviceSubscription/DevicePlans (evaluar alcance). L
5.2 Lookup/Docs/LoginAs/Addresses-vista. S c/u

### Ola 6 — Público (fuera del shell)
Login/Registro/CustomRegistration/PasswordReminder/EmailConfirmation + página pública de sharing
(hoy reutiliza la legacy, funciona).

---

## 5 · Convenciones vigentes (actualizadas a ui/v2)

- Rutas `Route::group(['prefix' => 'ui/v2', 'as' => 'ui.v2.'])`; API JSON `ui.v2.api.<dominio>.*`.
- Controllers `App\Http\Controllers\Ui\V2\*` extendiendo `BaseController`.
- Vistas `resources/views/ui/v2/pages|modals|partials/`; fuentes `resources/assets/ui-v2/{js,css}`
  → compilados con `npx gulp ui-v2` a `public/assets/ui/v2/` (SIEMPRE bump `?v=N`).
- Modales/toasts vía `window.tpModal`; selects `tpSelect` (recordar `refresh()` tras re-enable);
  fechas `tp-datepicker`.
- **Reutilizar ModalHelpers/Services/Validators legacy — la UI solo orquesta** (§2.7).
- Respetar los 8 contratos de datos de §2 en todo endpoint nuevo.
- La integración ERP "Talentus" NO se toca.
