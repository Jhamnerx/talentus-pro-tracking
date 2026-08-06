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

---

## 1 · OLA 0 — Correcciones de paridad (obligatorias, salen de la auditoría)

### 1.1 Seguridad (primero)

| # | Defecto | Dónde | Sev |
|---|---------|-------|-----|
| S1 | **IDOR en Chat**: `messages`/`send` hacen `Chat::find($id)` sin comprobar pertenencia (legacy usa `checkException('chats','show',$chat)` → `ChatPolicy`). Cualquier usuario lee/escribe cualquier chat | `Ui/V2/ChatController.php:108,133` | CRÍTICO |
| S2 | **Colisión de rutas `expenses/{id}`**: PUT/DELETE registrados 2 veces (`ExpensesController` y `DevicesController`); gana el último → la página Gastos golpea el handler con gate `devices:edit` en vez de `device_expenses:*` | `routes/web.php:1156-1157` vs `:1250-1251` | CRÍTICO |
| S3 | `VehicleTypesController` v2 **sin ningún gate** en data/store/update/destroy | `Ui/V2/VehicleTypesController.php:24-116` | CRÍTICO |
| S4 | Geocercas v2 sin policy: `GeofenceUserService` no tiene `check()` (a diferencia de Poi/Route) → **read-only/demo pueden crear/editar/borrar**; además update/destroy validan `can('view')` en vez de `edit`/`remove` | `Ui/V2/ZonesController.php:130-216` | CRÍTICO |
| S5 | CallActions: `store` acepta `user_id` del cliente (`$data['user_id'] ?? ...`) → suplantación de propietario; legacy lo fuerza | `Ui/V2/CallActionsController.php:204` | MEDIO |
| S6 | Gastos del modal device sin permiso `device_expenses` (usa `devices:edit`) y sin `DeviceExpensesFormValidator` | `Ui/V2/DevicesController.php:997-1069` | MEDIO |
| S7 | Alta de device sin el gate `custom_device_add` (legacy PROHÍBE el alta estándar a esos usuarios) | `Ui/V2/DevicesController.php:284-296` | MEDIO |
| S8 | `AdminController@data` expone `api_hash`/webhooks sin validar admin; `ShellController` preferences sin `isDemoUser()` | `Ui/V2/AdminController.php:19-28`, `ShellController.php:196-284` | MENOR |
| S9 | Grupos de vehículos: gate `devices:edit` en vez del flujo legacy + sin `DeviceGroupFormValidator` | `Ui/V2/DevicesController.php:442-512` | MEDIO |

### 1.2 Corrupción / pérdida de datos

| # | Defecto | Dónde | Sev |
|---|---------|-------|-----|
| D1 | **`expiration_date` del device deriva de TZ**: el helper hace `reverse()` al guardar pero la v2 pinta el valor crudo de BD (legacy pinta `convert()`) → **cada edición resta el offset (−5h en Perú)**. Además en CREAR el checkbox sale marcado (createData precarga fecha) → los vehículos nacen con caducidad y se desactivan solos | `modals/devices/partials/principal.blade.php:9-19`, `device-modal.js` | CRÍTICO |
| D2 | **`speed_limit` de geocerca se multiplica en cada guardado** si la unidad del usuario ≠ km: v2 devuelve el valor crudo (base) y el service vuelve a hacer `reverse()`. `vslMap` sí convierte — incoherencia en el mismo payload | `Ui/V2/ZonesController.php:59,304` vs `:319` | CRÍTICO |
| D3 | **Sharing `expiration_date` sin `Formatter::time()->reverse()`** → links caducan desfasados el offset del usuario; el meta tampoco hace `convert` | `Ui/V2/SharingController.php:242-256` | CRÍTICO |
| D4 | **Reportes `send_to_ftp` degradado a checkbox**: legacy es una URL `ftp://user:pass@host` → editar un reporte con FTP desde v2 **destruye la URL** y guarda `"1"` | `reports.js:619,1059` | CRÍTICO |
| D5 | **Alertas (modo api)**: si un update no incluye la clave `devices`, `sync([])` **desasigna todos los vehículos** (el path web dejaría todo intacto). Garantizar la clave siempre | `AlertModalHelper.php:171` + `Ui/V2/AlertsController.php` update | CRÍTICO |
| D6 | **Horarios de comandos: quitar un vehículo nunca lo desasigna** — `mergeSelectedDevices` genera solo attaches sin `"s;0;"` inicial, y `syncLoader` sin detach no toca nada | `Ui/V2/CommandsController.php:344-352` | CRÍTICO |
| D7 | Editar una geocerca **desactivada la reactiva** (`active: 1` forzado en cada guardado); no existe toggle de `active` para geocercas/POIs | `live-zones.js:350` | CRÍTICO |
| D8 | CallActions `called_at`: el JS parsea `converted_called_at` (formato humano del usuario); con `date_format` ≠ ISO cae al default "ahora" → **editar sobrescribe la fecha silenciosamente** | `tp-call-actions.js:53-59` | MEDIO |
| D9 | Plantillas GPRS/SMS: editar con `adapted ≠ devices` envía `devices: []` → **desasocia** los devices de una plantilla antes adaptada | `commands-page.js:472` | MEDIO |
| D10 | Tareas: `custom_fields_task` no se guardan ni se muestran (falta `CustomValuesService::saveCustomValues` + panel) | `Ui/V2/TasksController.php:166-191` | CRÍTICO (con addon) |
| D11 | Device: tab **Custom Fields ausente** → campos personalizados no editables desde v2 | `modals/devices/form.blade.php` | CRÍTICO (con addon) |
| D12 | Geocercas con addon `reports_geofleet_in`: falta `additional[intervals]` (required) → **todo guardado devuelve 422** | `live-zones.js` | CRÍTICO (con addon) |
| D13 | Eventos sin TZ del usuario: `time` crudo de BD en lista/popup/notificaciones y el **salto a Historial usa hora de servidor** → abre el tramo equivocado | `Ui/V2/EventsController.php:167,252`, `events.js:643` | CRÍTICO |
| D14 | Eventos con alcance reducido: `user->events()` en vez de `Event::userAccessible()` → **sub-usuarios con alertas compartidas ven vacío** (también Dashboard y widget recent_events) | `EventsController.php:100`, `DashboardController`, `DevicesController:~1247` | CRÍTICO |
| D15 | Cuenta: toggles de blocks del Tablero se **descartan** para usuarios sin settings previos; `options` por block no editables | `Ui/V2/AccountController.php:355-361` | MEDIO |
| D16 | SMS gateway: el password del gateway viaja vacío en cada guardado y sobrevive solo por el merge-fallback | `tp-account.js:91` | MEDIO |

### 1.3 Funcionalidad rota o inalcanzable

| # | Defecto | Sev |
|---|---------|-----|
| F1 | `snap_to_road` es **no-op** en Historial v2 (`getApi()` no lee la clave; solo `get()` la implementa) → mapa y export divergen | CRÍTICO |
| F2 | Import/Export de **geocercas y POIs** desde v2 abren fragmentos legacy sin layout → botones muertos. Export de geocercas nativo inexistente | CRÍTICO |
| F3 | Rutas: `export_groups` → **500** (tipo no mapeado); `export_active/inactive` exportan TODO (no viajan ids) | MEDIO |
| F4 | Hub de comandos: usuario con `view` sin `edit` de plantillas recibe **500** (el listado llama `createData()` que exige `create`) | MEDIO |
| F5 | Historial: data-log **siempre ascendente** (formato de `sorting` que el helper no reconoce); `distance_sum` ≠ legacy (no usa el recálculo por odómetro); borrar posiciones (`history.remove`) sin superficie | MEDIO |
| F6 | Eventos: borrado individual/masivo (`events.clean`) sin superficie; sin filtro por rango de fechas absoluto; columna título (plugin `event_section_alert`) y gate `event_section_address` ignorados | MEDIO |
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
| Fleet + modal device (8 tabs) | ⚠️ | D1, D11, F9, F10, F17, S6, S7 |
| Geocercas | ⚠️ | S4, D2, D7, D12, F2, F7 (grupos solo lectura) |
| POIs | ⚠️ | D7 (active), F2; grupos solo lectura |
| Rutas | ⚠️ | F3; CRUD+grupos+tabla ✅ |
| Alertas (reglas + modal 6 tabs) | ⚠️ | D5 |
| Eventos (página) | ⚠️ | D13, D14, F6 |
| Historial | ⚠️ | F1, F5 |
| Reportes | ⚠️ | D4; schedules ✅ |
| Comandos (modal + hub) | ⚠️ | D6, D9, F4 |
| Sharing | ⚠️ | D3, F11 |
| Conductores | ✅ | falta activity log (menor) |
| Tareas + conjuntos | ⚠️ | D10; filtros de fecha (menor) |
| Chat | ⚠️ | S1; user↔user y paginación (menor) |
| CallActions | ⚠️ | S5, D8 |
| Mantenimiento/Servicios | ✅/⚠️ | F15 (500s) |
| Gastos | ⚠️ | S2, S6 |
| Reenvíos | ✅/⚠️ | TYPE_FIELDS hardcodeado (deriva futura) |
| Cuenta (modal ajustes) | ⚠️ | D15, D16, F16 |
| Dashboard | ⚠️ | D14, F13 |
| Tipos/estados de vehículo | ⚠️ | S3 |
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
