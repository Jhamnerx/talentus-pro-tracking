# TalentusPro · Estado de migración

> Snapshot al **31 de mayo de 2026** · Rama `nuevo-tema`
> Convenciones y plan macro: ver [plan de migracion.md](plan%20de%20migracion.md)

Documento vivo que resume **qué está implementado** en el shell nuevo y **qué falta** por migrar desde el legacy. Lo organizamos por dominio.

---

## 🔍 Actualización · 6 ago 2026 — AUDITORÍA EXHAUSTIVA legacy↔v2 (contratos de datos)

Revisión completa de los ~70 módulos legacy vs la v2 con foco en **cómo cada modal envía datos al
controlador** (EntityLoaders `selected_*`, flag `api`, `Formatter reverse/convert`, checkboxes,
permisos, escrituras directas). **El detalle completo y priorizado vive en
[plan de migracion.md](../plan%20de%20migracion.md)** (reescrito): §1 = Ola 0 de correcciones
(S=seguridad, D=datos, F=funcionalidad), §2 = los 8 contratos de datos transversales obligatorios.

> **Fixes aplicados el mismo 6-ago** (por decisión del usuario, punto por punto): S1 IDOR chat ✅ ·
> S2 colisión expenses ✅ (URI propia `devices/expenses/{id}`) · S3 gates vehicle-types ✅ (módulo
> `devices`; no se creó módulo nuevo porque `User::perm` devuelve false para módulos no sembrados) ·
> S6 gates gastos ✅ · D1 expiración device ✅ (convert() + checkbox desmarcado al crear; la BD guarda
> UTC — `app.timezone='UTC'` — y el helper hace reverse() al guardar) · D2 speed_limit geocerca ✅ ·
> D4 FTP reportes ✅ (input URL, reports.js ?v=10) · D5 salvaguarda alertas ✅ · D6 detach horarios ✅
> (`"s;0;"`) · D13/D14 eventos TZ+alcance ✅ (userAccessible + convert; Dashboard pendiente).
> **S4 = falso positivo**: la policy de geocercas SÍ corre vía `ModelUserServiceTrait` (paridad legacy).
> Quedan abiertos: S5, D3, D7–D12, D15–D16 y la lista F.

**Los 10 hallazgos más graves** (fix antes de seguir migrando):

1. **IDOR en Chat v2** — `messages`/`send` sin comprobar pertenencia (`ChatPolicy`); cualquier usuario lee/escribe cualquier chat. (S1)
2. **Colisión de rutas `expenses/{id}`** — PUT/DELETE registrados dos veces; gana el handler con gate equivocado (`devices:edit` en vez de `device_expenses`). (S2)
3. **`expiration_date` del device deriva de TZ** — la v2 pinta crudo de BD y el helper hace `reverse()` al guardar → −5h por edición; y en CREAR el checkbox sale marcado → devices nacen con caducidad. (D1)
4. **`speed_limit` de geocerca se corrompe por unidades** — v2 devuelve el valor base sin `convert()` → ×1.609 por guardado si la unidad ≠ km. Y editar una geocerca desactivada la reactiva (`active:1` forzado). (D2/D7)
5. **Geocercas sin policy read-only/demo** (GeofenceUserService sin `check()`) + `can('view')` donde va `edit`/`remove`. (S4)
6. **Alertas modo api**: update sin la clave `devices` ⇒ `sync([])` desasigna TODOS los vehículos. (D5)
7. **Horarios de comandos: quitar un vehículo nunca lo desasigna** — falta `"s;0;"` en la secuencia del loader. (D6)
8. **Reportes `send_to_ftp` degradado a checkbox** — editar destruye la URL FTP guardada. (D4)
9. **Eventos**: alcance `user->events()` en vez de `Event::userAccessible()` (sub-usuarios ven vacío) + horas sin TZ del usuario (el salto a Historial abre el tramo equivocado). (D13/D14)
10. **Sharing `expiration_date` sin `reverse()`** — links caducan desfasados; canal SMS ausente. (D3/F11)

Otros: `snap_to_road` no-op en Historial, import/export de geocercas/POIs roto desde v2, tab
CustomFields ausente (device y tareas, addon), `VehicleTypesController` sin gates,
`called_at` de CallActions se sobrescribe al editar, plantillas GPRS/SMS desasocian devices al
editar, Popups del admin nunca se muestran, ObjectsList sin migrar. Ver plan §1 completo.

---

## 🔄 Actualización · 29 jul 2026 — Módulo Alertas: modal completo de 6 tabs (paridad legacy)

El modal de reglas de alerta en v2 estaba incompleto y mal diseñado. Reconstruido con paridad
legacy (`Frontend/Alerts/create`):

- **Devices tab** (paridad legacy exacta): lista **agrupada por grupo + paginada** con buscador y
  **Seleccionar todos / Quitar todos**. "Seleccionar todos" **no marca casillas**: envía
  `selected_devices=["s;1;"]` y el `UserDevicesGroupLoader` marca `for_all_user_devices=1` (la alerta
  cubre los vehículos actuales **y los que se agreguen después**). Selección específica →
  `["s;0;","devices;1;<id>"…]` ⇒ `for_all=0`. `meta` expone `group_id` por device + lista `groups`.
- **6 tabs** (`alerts.js`): **Vehículos** (ver arriba), **Tipo**
  (disparador + campos por tipo de `AlertModalHelper::getTypesWithAttributes`), **Geocercas**
  (sentido entrada/salida + picklist de geocercas → `zones`/`geofences`), **Horario** (grilla
  arrastrable 7 días × 96 franjas de 15 min con días-hábiles/fin-de-semana/siempre/limpiar),
  **Notificaciones** (canales reales con su input: color/select/entero/texto + descripción, desde
  `NotificationProvider::getInputMeta`), **Comando** (perm `send_command`: activo + tipo + atributos
  dinámicos por comando GPRS).
- **Backend** (`Ui\V2\AlertsController`): nuevo endpoint `POST alerts/commands` (comandos GPRS de los
  vehículos seleccionados, reshape con `reshapeCommands`); `meta`/`editData` exponen `can_command`;
  el item de edición incluye `schedule` y `command`. **Fix**: la lista de reglas solo muestra
  canales **activos** (`activeChannels` — antes listaba todos → "muro de badges") y ahora trae
  `devices_count`.
- **Diseño de la lista de reglas**: chip de tipo + alcance ("Todos" / "N vehículos") + canales
  activos localizados (cap 4 + "+N"), en vez del volcado de claves en mayúscula/inglés.
- Guardado/edición reutilizan `AlertModalHelper::create()/edit()` (schedules en formato franja-plana,
  `notifications[name][active|input]`, `command.active`).
- **Página = tabla índice de reglas** (estilo `.tp-svc-table`, como call-actions/maintenance): columnas
  Estado (toggle) · Nombre · Tipo · Alcance · Notificaciones · Horario + **kebab ⋮** por fila
  (Editar / Activar-Desactivar / Eliminar). Se **quitó el feed de eventos** (ya vive en `/ui/v2/events`);
  `data()` ahora solo devuelve reglas (+`schedule`/`devices_count`). Assets: alerts.js ?v=3, alerts.css ?v=3.

---

## 🔄 Actualización · 29 jul 2026 — Estados de vehículo con label + color + cambiador (rama `nuevo-tema`)

- **Schema**: migración `2026_07_29_000001` agrega `label` (24) + `color` (9) a `vehicle_states`. `VehicleState::$fillable` += label, color.
- **Tipos de vehículo** (Ajustes de cuenta → tp-account.js): cada estado ahora tiene **nombre + label corto (badge) + color** (input `type=color`). La tabla muestra chips de colores. `Ui\V2\VehicleTypesController::syncStates` procesa `edit_state[id]`/`add_state[]` como objetos `{name,label,color}` (retro-compatible con string plano); `data()` devuelve label/color.
- **Payload**: `DeviceMapTransformer` expone `vehicle_state` = `{name, label, color}` (fallback label→name, color→gris). requireLoads += `vehicleState`.
- **Badge del estado** en 3 lugares (live.js `vehicleStateBadge`): **fila del rail** (junto al nombre, como candado/motor), **etiqueta del marcador** en el mapa, y **arriba del drawer** (fuera del widget Objeto).
- **Cambiador rápido**: botón "Cambiar estado" en el drawer → modal con los estados del tipo → `POST /ui/v2/api/devices/{id}/vehicle-state` (valida que el estado pertenezca al tipo). Al cambiar, se re-pintan badge/fila/marcador al instante. Endpoint auxiliar `GET .../vehicle-states` (estados del tipo + actual). Gate: `perm('devices','edit')`.
- Asignar tipo/estado en **Información** del device ya existía. Assets: live.js ?v=40, live-ops.css ?v=54, tp-account.js ?v=4.

---

## 🔄 Actualización · 27 jul 2026 (5) — Página de Eventos ampliada (rama `nuevo-tema`)

- **Eventos al rail** (encima de Alertas, icono de pulso, gate `perm('events','view')`). Icono `events` nuevo en `icons.blade`.
- **Popup del evento**: al **cerrar** se quita el marcador y se deselecciona la fila (antes quedaba el icono suelto). Botones **"Historial"** (→ `/ui/v2/history?device=N&range=<derivado por antigüedad>`) y **"Atender"** (registra acción de llamada; solo si `perm(call_actions+events)` y el evento tiene `alert_id`; POST a `api.call_actions.store`).
- **Indicador "Atendido"**: badge verde en filas de eventos que ya tienen `CallAction` (cruce por `event_id`, 1 query/página).
- **Filtros**: por **tipo** (dropdown poblado desde `by_type` acumulado, labels ES en `TYPE_LABELS`) y por **vehículo** (tp-select). El backend `data()` acepta `type` + `device_id`.
- **Tiempo real**: `pollLive()` cada 30 s inserta eventos nuevos arriba (si estás al tope), **beep WebAudio** en críticos + toast.
- **Exportar CSV** de los eventos cargados.
- **Mapa de riesgo** (#7): toggle que pinta TODOS los eventos del filtro como circle markers por severidad (`EventsController@mapData`, ruta `api.events.map`, capado a 1500) + **leyenda flotante** (Crítico/Advertencia/Informativo + conteo).
- Assets: events.js ?v=15, events.css ?v=9.

---

## 🔄 Actualización · 27 jul 2026 (4) — Topbar finalizado (rama `nuevo-tema`)

- **Estado de flota removido del topbar**: era redundante en Live (el mapa ya lo muestra) y en el resto de páginas mostraba "0·0·0" fijo (solo `live.js` alimenta los conteos reales vía `tpUpdateLiveStatus`). `shell.js`/`live.js` siguen llamando al updater — no-op sin errores.
- **Brand → marca blanca**: el topbar dejaba "TalentusPro" hardcodeado; ahora usa `Appearance::getSetting('server_name') ?: 'TalentusPro'` (mismo ajuste que el `<title>`). Logo = inicial del nombre. Link → `/ui/v2/live`.
- **Switcher "Mi Empresa" (WORKSPACES) removido**: era un stub estático (`$workspaces` nunca lo pasa ningún controlador). El dominio no tiene multi-workspace por usuario (un user = una `Company`, ver abajo).
- **Buscador (command palette) corregido**: `ShellController@search` enlazaba a `/ui/v2/preview#...` (mockup viejo). Ahora: device → `/ui/v2/live?device=N` (live.js `focusInitialDevice()` lo selecciona+centra tras el 1er load), geocerca → `?panel=zones`, POI → `?panel=pois`. Placeholder ajustado a lo que realmente busca (vehículo/geocerca/POI; **no** conductor/comando). Assets: live.js ?v=38.

> **Nota dominio (usuario/cliente/compañía)**: `User` tiene `client_id` (belongsTo `Client`: persona — nombre/apellido/DNI/dirección) y `company_id` (belongsTo `Company`: organización — RUC/`registration_code`/vat/dirección, con `owner` = reseller). Se edita en el form legacy de usuarios (Admin/Clients, tab "Cliente"): sección Cliente = datos `client[...]`; sección Compañía = asignar `company_id` (`Company::userAccessible`) **o** crear una nueva inline (`company[...]`). CRUD de compañías: rutas `companies.*` → `CompaniesController`. Un usuario pertenece a **una** compañía (no es un switcher). **Aún sin migrar a v2.**

---

## 🔄 Actualización · 27 jul 2026 (3) — Asignar Reenvíos a Grupos de objetos (rama `nuevo-tema`)

- **Gap cerrado**: el modal **Grupos de objetos** v2 (`live.js` `openDeviceGroupModal`) ahora tiene el campo **Reenvíos** (paridad legacy `DevicesGroups/edit.blade` select `forwards[]`). Asigna qué conexiones de reenvío aplican a los vehículos del grupo (pivote `device_group_forward`).
  - **UI**: lista de checkboxes de los `Forward` accesibles (tp-select no soporta `<select multiple>`), gated por `config('addon.forwards') && perm('forwards','view')`. Opciones inyectadas en `#tp-live-forwards` (blade) → `FWD_CFG` en live.js. Estado vacío enlaza a Herramientas → Reenvíos.
  - **Backend** (`DevicesController`): `deviceGroupItems` devuelve `forward_ids` (preselección en editar); `storeDeviceGroup`/`updateDeviceGroup` → nuevo `syncGroupForwards()` = `$group->forwards()->sync($ids)` **solo si** `perm('forwards','view')`, restringido a `Forward::userAccessible` (seguridad, no confía en ids del cliente). Paridad exacta con `DevicesGroupsController` legacy.
- Assets: live.js ?v=34. Sin rutas nuevas (reusa `device-groups` store/update/items).

---

## 🔄 Actualización · 27 jul 2026 (2) — Gastos (rail) + Reenvíos (Herramientas) migrados (rama `nuevo-tema`)

- **Gastos** (fleet-wide, `device_expenses`) → **rail** `/ui/v2/expenses`. `Ui\V2\ExpensesController` (data con filtros búsqueda/vehículo/rango fechas, meta devices+tipos, store/update/destroy sobre `DeviceExpense`, `total=qty*cost`, `scopeUserAccessible`). Página `expenses.blade` (tabla tp-svc + toolbar) + `tp-expenses.js` (modal: vehículo/nombre/fecha/tipo/cantidad/costo/proveedor/comprador/notas). Icono `money`. Gated `perm('device_expenses',...)`.
- **Reenvíos** (`forwards`) → **Herramientas** (topbar). `Ui\V2\ForwardsController` (data = lista + tipos habilitados con sus campos; store/update/destroy reutilizando `ForwardService`/`Forward`). Modal `tp-forwards.js` (lista + form con **campos dinámicos por tipo**). Gated `config('addon.forwards') && perm('forwards',...)`.
  - **Tipos** (Custom/Pegasus/MacroPoint) filtrados por addon `forward_custom`/`forward_pegasus`/`forward_macropoint` — por eso el `<select> Tipo` sale **vacío** si ninguno está activo. Campos: Custom(ip,port) · Pegasus(domain,token) · MacroPoint(username,password,mpid).
- Rutas `ui.v2.expenses`, `ui.v2.api.expenses(.meta/.store/.update/.destroy)`, `ui.v2.api.forwards(.store/.update/.destroy)`. Assets: tp-expenses.js ?v=1, tp-forwards.js ?v=1 (cargado en Live). Doc §3: `Forwards` y `DeviceExpenses` dejan de ser deeplink legacy.

---

## 🔄 Actualización · 27 jul 2026 — Menú Herramientas de mapa en la v2 (rama `nuevo-tema`)

Portadas las **utilidades de mapa** del menú "Herramientas" legacy que faltaban en la v2 (las demás ya están en el rail):
- **Menú "Herramientas"** en el topbar (icono llave), visible solo en la página Live (`rail-active` live/zones/pois). Lo gestiona `live.js`.
- **Regla** (`app.ruler` legacy): medición por clics en el mapa con chips de km (regla custom, misma que Historial). Toggle desde el menú.
- **Mostrar punto** (`app.showPoint`): modal lat/lng → coloca marker + centra.
- **Buscar dirección** (`app.showAddress`): modal → `address.search` (geocoding) → marker + centra.
- `window.tpLiveTools = { ruler, showPoint, searchAddress }`. Assets: live.js ?v=33, live-ops.css ?v=50.
- **Fix widget Vista de calle**: usaba la Static API (proxy `streetview.jpg`) que devuelve "no imagery" en puntos sin panorámica exacta (zonas rurales). Ahora, si `addon.street_view_frame` + `streetview_api` configurado, usa el **embed interactivo** (`maps/embed?pb=…`, paridad `convertStreetViewUrl` legacy) que encuentra la panorámica más cercana; la imagen estática queda como fallback. (`DevicesController@widgets` + `device-widgets.blade`, server-rendered.)
- **Pendiente en Herramientas**: `Gastos` (device_expenses) y `Reenvíos` (forwards) — son módulos CRUD legacy (no utilidades de mapa); requieren migración v2 propia (controller + modal + JS), como el resto de módulos.

Con esto el "botón de herramientas superior" queda con paridad para las 3 utilidades de mapa (el resto de entradas del menú legacy —Alertas, Geocercas, Rutas, Reportes, POI, Enviar comando, Cámara, Tareas, Mantenimiento, Compartir, Acciones de llamada, Tablero— ya viven en el rail).

---

## 🔄 Actualización · 26 jul 2026 — Bloqueo: candado en lista + comandos por plantilla GPRS (rama `nuevo-tema`)

- **Candado rojo/verde en la lista de Live**: si el device tiene el sensor `blocked`, cada fila muestra un candado junto al nombre (rojo=bloqueado, verde=libre). `DeviceMapTransformer` expone `blocked` (null/true/false) leyendo el sensor por device.
- **Acento de color de estado** en el costado de cada fila (barra izquierda) con el `icon_color` real (`getStatusColor`).
- **Altura uniforme de widgets del bottombar** (paridad legacy): antes se estiraban al más alto (`align-items:stretch` implícito con contenido variable); ahora **altura fija 200px** por card + cuerpo scrolleable.
- **Comandos Cortar/Restablecer desde Plantillas de GPRS por TIPO de dispositivo** (no protocolo — marcas distintas comparten protocolo):
  - Migración `command_templates.lock_action` (`block`/`unblock`) + `CommandTemplate::$fillable`.
  - Selector "Uso en bloqueo de motor" en el form de plantilla GPRS **v2** (`commands-page.js`) y **legacy** (`UserGprsTemplates/create|edit`). Persistido por `UserGprsTemplateModalHelper` (create/edit) y expuesto en el meta v2.
  - Widget: `lockCommand`/`lockTemplates` (endpoint `ui.v2.api.devices.lock_templates`) devuelve las plantillas **adaptadas al device** (por `isAdaptedFromDevice` → device_type); Cortar auto-elige la marcada `block`, Restablecer la `unblock` (con fallback a todas / texto libre). Se quitaron los campos de comando del plugin `locking_widget`.
  - El estado del widget/candado se toma del **sensor `blocked`** (fuente única); el `parameter/value_on/off` del plugin queda solo como fallback.
- Assets: live.js ?v=31, live-ops.css ?v=48, commands-page.js ?v=8. Migración: `php artisan migrate --force`.

---

## 🔄 Actualización · 25 jul 2026 (5) — Widget de bloqueo de motor + bottombar (rama `nuevo-tema`)

- **Traducciones**: `locking_widget` 'Cierre' → **'Bloqueo de motor'**; `blocked` 'Obstruido' → **'Bloqueado'** (confundían al usuario).
- **Widget de bloqueo/desbloqueo migrado a la v2** (plugin `locking_widget`, antes solo legacy `LockStatus`): card en el bottombar (gateada por el plugin) con **badge de estado** (Bloqueado/Desbloqueado/Desconocido leído del parámetro de la última posición), botones **Cortar motor** / **Restablecer** y **Historial** (modal con periodos por Hoy/Ayer/Semana). Backend nuevo en `DevicesController`: `widgets()` expone `$data['locking']`; `lockCommand()` (POST, envía por GPRS/SMS vía `SendCommandService`) y `lockHistory()` (periodos con las acciones History `AppendLockStatus`/`GroupLockOffStatus`/`Duration`, paridad `LockStatusController` legacy). Rutas `ui.v2.api.devices.lock_command|lock_history`.
  - **Mejora sobre el legacy**: los comandos ahora se **preconfiguran** en el plugin (`options.command_on`/`command_off`, ej. Teltonika `setdigout 1`/`setdigout 0`) → un clic sin teclear. El modal precarga el comando (editable) + GPRS/SMS. Admin: nuevos campos en `Admin/Plugins/Partials/locking_widget.blade`.
- **Bottombar**: fix de **scroll horizontal** cuando hay muchos widgets (el contenedor heredaba `pointer-events:none`; ahora `pointer-events:auto` + `flex:0 1 auto` + rueda→scroll horizontal respetando el scroll vertical de cada card).
- **Widget Conductor** movido del bottombar al **drawer lateral** (`.tp-dw--side`).
- Assets: live.js ?v=29, live-ops.css ?v=46. `config/tobuli.php` (nuevas options del plugin) → requiere `php artisan config:clear` en prod.

---

## 🔄 Actualización · 25 jul 2026 (4) — Borrado de device + filtro avanzado en v2 (rama `nuevo-tema`)

Dos features legacy portadas a la v2 (implementadas "como el legacy"):

**Borrado de vehículo (paridad `Devices/destroy.blade` + `DevicesController@destroy`):**
- El modal de device (editar) ahora tiene botón **Eliminar** (danger, izquierda) gateado por `perm('devices','remove')`. Abre confirmación; si `addon.object_delete_pass` está activo (+admin) exige la **contraseña** (campo password), igual que el legacy.
- `DevicesController@destroy` v2 revalida server-side (error `front.login_failed`). **Divergencia intencional del legacy:** el legacy compara contra la clave FIJA del `.env` (`addon.object_delete_pass`, compartida por todos); la v2 valida la **contraseña de login del propio usuario** (`Hash::check` contra su hash bcrypt) y usa `addon.object_delete_pass` solo como **interruptor on/off** del candado. Config en `$tpDeviceModalConfig`: `destroyUrl`, `canRemove`, `objectDeletePass`. Tras borrar refresca la lista (`onSaved → fetchDevices`). device-modal.js ?v=10.

**Filtro avanzado del sidebar (paridad `addon.object_filter` + `Objects/filters` + `DevicesSidebarController`):**
- Botón de filtro en el toolbar de Flota (`#tp-live-filter`), **gateado por `config('addon.object_filter')`**. Abre modal con: **estado[]** (mismas opciones i18n que `showFilters`, +Blocked si el sensor está activo), **última conexión** online/offline (min, con swap online≥offline como el legacy), y toggles **filter_sidebar / filter_map**.
- El filtrado es **server-side fiel**: nuevo endpoint `DevicesController@filterIds` (`ui.v2.api.devices.filter_ids`) resuelve el set de IDs con los MISMOS scopes legacy (`->filter()` → `filterStatus/filterOnline/filterOffline`). El cliente aplica ese set a **lista** (`state.filtered`) y/o **mapa** (`state.mapFiltered`, decoplado de la lista para respetar los toggles independientes). Botón marca estado activo (`.is-active`). live.js ?v=28, live-ops.css ?v=45.

Con esto `addon.object_filter` deja de estar "no migrado": el toggle del rail/objetos y su modal quedan con paridad completa. Actualizada §3 y la nota de la actualización (3).

---

## 🔄 Actualización · 25 jul 2026 (3) — Cierre de gaps de gating en v2 (rama `nuevo-tema`)

Tras la auditoría cruzada legacy↔v2 de gates de addon/plugin, se corrigieron los que faltaban en features **ya migradas**:

- **`addon.object_delete_pass`** (candado anti-borrado): [DevicesController@destroy](../app/Http/Controllers/Ui/V2/DevicesController.php) v2 ahora valida la contraseña cuando el flag está seteado + `isAdmin()`, replicando `Frontend\DevicesController@destroy` (error `front.login_failed`). *Nota:* la v2 aún no tiene UI de borrado de device; el endpoint ya queda blindado y, cuando se cablee la UI, debe enviar `password`.
- **`plugins.event_section_address`**: Eventos v2 solo geocodifica/renderiza dirección si el plugin está ON (`data-addr-on` en events.blade → `ADDR_ON` en events.js; gatea `geocodeEvent`, la fila y el popup). events.js ?v=12.
- **`plugins.history_section_address`**: Historial v2 solo muestra la fila "Dirección" del popup de punto y su `fetchAddr` si el plugin está ON (`data-addr-on` → `CFG.addrOn`). history.js ?v=24.
- **`plugins.device_widget_full_address`**: la dirección del popup de device en Live usa `tp-addr--full` (wrap) u `tp-addr--clip` (elipsis) según el plugin (`data-widget-full-address` → `CFG.fullAddress`). live.js ?v=27, live-ops.css ?v=44.
- **`plugins.object_listview`**: el botón "Ver lista de vehículos" de Live (`#tp-live-listview`) ahora va gateado por `settings('plugins.object_listview.status') && perm('devices','view')`, igual que `Objects/tabs/objects.blade`.

**`addon.object_filter`**: es el **modal de filtro avanzado** legacy (`device_sidebar_filter`, botón en `Objects/tabs/objects.blade:20`). **✅ Migrado el 25-jul (ver actualización 4)**: botón `#tp-live-filter` gateado por `config('addon.object_filter')` + endpoint `filterIds` que reusa los scopes legacy.

Estado de gates: todos los del legacy en features migradas quedan replicados. Pendientes = solo los de features aún no migradas (external_url, custom_fields(_task), reports_geofleet_in en geocerca, business_private_drive, checklists, beacons, media_categories — ver §3).

---

## 🔄 Actualización · 25 jul 2026 (2) — Auditoría de addons + gating en v2 (rama `nuevo-tema`)

**Revisión de los 48 flags de `config/addon.php`** (qué habilita cada uno y dónde). Dos consecuencias para la migración:

- **Principio confirmado (regla de oro):** *toda validación de addon (`config('addon.*')`) o plugin (`settings('plugins.*.status')`) que exista en el legacy debe replicarse EXACTA en la v2 — no se cambia ni se omite.* Añadido a §1 Convenciones.
- **Fix aplicado:** el botón **"Mi ubicación"** de Live (`#tp-live-geolocate` en [live.blade.php](../resources/views/ui/v2/pages/live.blade.php)) estaba **siempre visible**; ahora va gateado por `@if (config('addon.html_geolocation'))`, igual que el legacy (`loged.blade.php`). El listener en `live.js` ya tolera la ausencia del botón (`if (geo)`), así que no requiere recompilar JS.
- **Popups (sistema del admin) — PENDIENTE en v2:** el módulo `Popups/` (banners superiores + modales condicionales por reglas: FirstLogin, DemoUser, SubscriptionEnding, UserCreatedBefore, BillingPlan; posiciones top/esquinas/center; `show_every_days`) **NO está portado** al shell v2. En el legacy se inyecta desde `loged.blade.php` (`Frontend.Popups.index_banners_top` + `Frontend.Popups.index`); el `layout.blade.php` de la v2 no incluye esos partials. **Ojo:** es un sistema distinto de `tp-notify.js` (toasts de eventos en vivo, que sí está ✅). Ver fila `Popups/` en §3 y el ítem en pendientes user-facing.

> Hallazgos de la auditoría (referencia rápida): addons OFF-por-defecto salvo `custom_fields`, `custom_fields_task`, `alert_move_start_filtered`, `engine_hours_current_report`, `beacons`, `fuel_strategy_peaks`, `street_view_frame`. Flags **muertos/vestigiales** en este fork: `distance_point` (declarado, nunca leído — la regla de medición no depende de él) y `time_text` (leído en `Device.php` pero no existe la clave → siempre null). `google_styled` solo añade `region:'MA'` a Google Maps (dejar OFF en Perú). `custom_overlays_map` añade la capa base id 97 (requiere subir tiles a `public/assets/custom/map-overlays/` + habilitar el id en `available_maps`). `call_actions`/`beacons` son **plugins** (`settings('plugins.*.status')`), no addons.

---

## 🔄 Actualización · 22 jul 2026 (5) — Reportes rediseñado (rama `nuevo-tema`)

**Módulo Reportes reescrito** (deja de ser el "shim" de generación rápida) con el visual de referencia, 100% sobre la lógica legacy:

- **Página** con tabs **Reportes generados** (tabla `reports` = definiciones guardadas/programadas: Título|Tipo|Formato|Devices|Geocercas|Programación; click en fila o kebab "Abrir" = generar vía `reports/update?id&generate=1(&view=1 html)`; Editar/Eliminar) y **Logs de programados** (`report_logs`: Nombre|Tipo|Formato|Tamaño|Enviado; click/descarga por `reports.log_download`; eliminar). Toolbar: buscador + filtro por tipo + "+". El rail entra con el **modal abierto** (`?create=1`).
- **Modal Generar/Guardar**: Título · **Periodo** con picker de rango propio (2 meses, rangos rápidos Hoy/Ayer/…, Desde/Hasta con hora) · **"Controlar días"** (pills Lun–Dom → `period_weekdays[]`, solo se envía si desmarcas alguno) · Tipo · Formato (restringido a los del tipo) · Devices (chips agrupados) · Geocercas/Metas (chips) · límite de velocidad · paradas · checkboxes · **Enviar a email** (chips con "+", `send_to_email` con `;`) · programación Diario/Semanal/Mensual con hora.
- **Campos dinámicos por tipo**: `reports/types/{type}` legacy entrega `fields` (muestra/oculta devices/geofences/metas/speed_limit/stops/zones/addresses) y `parameters` (\Field) — port de `buildReportAttributes`: integer/string/text/checkbox/select/multiselect/multiselect-group.
- **Backend** `Ui\V2\ReportsController` nuevo: `meta` (tipos habilitados por usuario, formatos, geocercas, stops, metas del createData legacy, devices agrupados), `saved`/`logs` (paginados con búsqueda+filtro), `item` (edición con from/to_formated), `store` → `ReportModalHelper::create()` (devices como `devices[]` + secuencia `selected_devices[]`; el cron `reports:daily` procesa los schedules), `destroy`, `logDestroy`.
- **Extensión del modelo de schedules** (23-jul): migración additiva a `reports` con `weekly_day` (ISO 1-7) / `monthly_day` (1-31, tope al último día del mes) — el cron `reports:daily` gana un gate por día concreto (a partir de la hora programada, una vez por ciclo; null = ventana clásica intacta) — y con `period_weekdays`/`period_time_from`/`period_time_to` **persistidos**: el filtro "Controlar días y horas" ahora se guarda con la definición y el cron lo aplica (`fromEntity → toArray → from()`). El modal ganó los selects de día en Semanal/Mensual y precarga pills/franja al editar. También se corrigió el **bug legacy** `Report::setPeriodTimeTo()` (asignaba `period_time_from`).

---

## 🔄 Actualización · 22 jul 2026 (4) — Notificaciones de eventos EN VIVO (rama `nuevo-tema`)

**Popup + sonido + Notification API portados** (paridad con `EventSys.notice()/sound()` + `Notifications()` legacy). Hallazgo clave: en el legacy los eventos en vivo van por **POLLING** (no socket) al `objects.items_json`, con los flags popup/sound/color derivados de `alert.notifications` en cada poll por el `EventLatestTransformer`.

- **Backend**: `Ui\V2\EventsController@live` (`ui.v2.api.events.live?time=`) replica el bloque de eventos del `DeviceModalHelper::itemsJson()`: ventana máx 5 min, `higherTime` (excluye silenciados), filtro `popup.active`, y el mismo `EventLatestTransformer` (sound=URL mp3 vía `AlertSoundService`, color, delay 0|5|10). `ShellController@notifications` deja los datos demo y alimenta el **tray con los últimos 30 eventos reales** (`title`, device·geocerca, `variant` = `eventSeverity()`).
- **`tp-notify.js`** (global en el layout → funciona en TODAS las páginas v2): poll 15s; por evento → **toast rico apilado** (borde del color de la alerta, mensaje, device·geocerca·velocidad, auto-cierre según `delay`, 0 = fijo; click → página Eventos), **sonido** (`new Audio(ev.sound)`, uno por lote) y **Notification API** (permiso al cargar, como el legacy). El "visto" = timestamp en localStorage (igual que el legacy avanza `checkTimestamp`), compartido entre páginas → sin duplicados al navegar.
- **Tray** (`notifications.js`): estado "leído" client-side por last-seen en localStorage (la tabla `events` no tiene marcador); "marcar todo" fija el last-seen; tp-notify refresca el tray al llegar eventos.

---

## 🔄 Actualización · 22 jul 2026 (3) — Conductores (UserDrivers) nativo (rama `nuevo-tema`)

**Módulo Conductores migrado** (`/ui/v2/drivers`, rail activo), delegando 100% en `UserDriverModalHelper` legacy (validación `UserDriverFormValidator`, pivot `user_driver_devices` vía `syncLoader`, vehículo actual con `current`+`device_id` → `Device::changeDriver`/`UserDriver::changeDevice`):

- **Backend** `Ui\V2\DriversController`: página + API `ui.v2.api.drivers{,meta,store,update,destroy}`. Lista con búsqueda (`Searchable`: name/rfid/phone/email) y filtro **Con/Sin dispositivos** (whereHas/whereDoesntHave del pivot). El payload manda `devices[]` (ints, regla del validador) **y** la secuencia `selected_devices[]` (`"s;0;"`+`"id;1;<id>"`) que consume el `UserDevicesGroupLoader`.
- **`tp-drivers.js`**: tabla Nombre|Dispositivo actual|RFID|Teléfono|Email|Descripción|Dispositivos + kebab Editar/Eliminar; modal estilo referencia con **multiselect de chips** (panel portal con buscador, patrón tp-select) para la asignación N:M y **"Establecer como actual"** (checkbox habilitador + select con `tpSelect.refresh`).
- El modelo de dominio: `user_drivers.device_id` + `devices.current_driver_id` = conductor ACTUAL (también lo setea el RFID de posición en `PositionsWriter::setCurrentDriver`); pivot `user_driver_devices` = asignados. El widget Conductor del drawer ya leía `$device->driver`.
- Pendiente menor: **registro de actividad** del conductor (modal `activityLog` sobre `user_driver_position_pivot`) y edición inline del RFID en la tabla (paridad `do_update`).

---

## 🔄 Actualización · 22 jul 2026 (2) — Compartir (Sharing) nativo (rama `nuevo-tema`)

**Módulo Compartir migrado** (links públicos por vehículo), reutilizando la lógica legacy end-to-end (`SharingService` create/update/remove/syncDevices/sendEmail + `SharingFormValidator` escenario `send` + entidad `Sharing` con hash/link):

- **Backend** `Ui\V2\SharingController`: página `/ui/v2/sharing` + API `ui.v2.api.sharing{,meta,store,update,destroy}`. La selección de devices viaja como **secuencia EntityLoader** `selected_devices[]` (`"s;0;"` + `"id;1;<id>"…` = selección exacta) que consume `UserDevicesGroupLoader` como el legacy. `expiration_by` none|duration|date se normaliza igual que el legacy (date → fin del día; duration → `addMinutes`). Duraciones con fallback propio porque `config('tobuli.object_online_timeouts')` está vacío por defecto. **Migración additiva**: columna `description` en `sharing` (la referencia la tiene; el schema no la tenía).
- **`tp-sharing.js`** (global): `tpSharing.openForm(id?, {deviceId, onSaved})` — modal estilo referencia (Activo, Expira por con campos condicionales, Nombre, Descripción, Eliminar al expirar, caja de **devices agrupada** con buscador + Seleccionar/Deseleccionar todo + checkbox por grupo con indeterminate, Email con checkbox habilitador y hint de `;`). Página con tabla Activo|Nombre|Link|Expirado|Expiración + búsqueda + **filtro por device** (tp-select) + kebab **Editar / Vista previa / Eliminar** (vista previa abre el link público).
- **Cableado**: rail "Compartir" → página (deja de estar pending); kebab del device en Live: "Compartir" abre el modal con el vehículo preseleccionado y "Compartiendo" navega a la página filtrada por ese device.
- **Página pública**: se reutiliza la legacy tal cual (`route('sharing', hash)` → layout standalone con el motor de mapa legacy + polling `items/latest`); expirado → 404 y `sharing:clean` borra los `delete_after_expiration`.
- Pendiente menor: envío por SMS desde el modal (el service lo soporta; solo falta el campo gated por `canSendSms()`).

---

## 🔄 Actualización · 22 jul 2026 — Modal device: tabs Sensores / Usuarios / Servicios Web (rama `nuevo-tema`)

Los 3 tabs placeholder del modal de vehículo quedan **nativos**, delegando 100% en los helpers legacy:

- **Servicios Web**: partial con los 7 checkboxes de integraciones Perú (`mtc`=Sutran, `osinergmin`, `mininter`, `consatel`, `wisetrack`, `sidersales`, `epscenter`) alimentado por `$webservices` (`DeviceModalHelper::getWebservices()`, ya filtrado por `perm('webservice.<x>','edit')`). Patrón hidden 0 + checkbox 1; son columnas fillable del device — el `update()` normal los persiste.
- **Usuarios** (admin): lista con buscador + paginación ("Cargar más") sobre los endpoints legacy `devices.users.get/{id}` / `devices.users.index` (UsersLoader: búsqueda `s`, cada fila trae `selected`). La UI acumula **deltas** en hidden inputs `selected_user_id[]` con el formato del EntityLoader (`"id;1;<uid>"` asignar / `"id;0;<uid>"` quitar) que `DeviceModalHelper::setAbleUsers()` resuelve contra `device->users()`. Sin cambios → no viaja nada → asignaciones intactas (misma semántica legacy). `readForm()` ganó soporte de arrays `name="x[]"`.
- **Sensores**: tabla server-rendered (`$sensors_list` en `form()`) + CRUD completo en modal **anidado** (tpModal apila `<dialog>`). Endpoints v2 nuevos en `DevicesController` (`sensorsList/sensorFormCreate/sensorFormEdit/sensorStore/sensorUpdate/sensorDestroy/sensorPreview`, rutas `ui.v2.api.devices.sensors*` + `ui.v2.api.sensors.*`) que delegan en **`SensorModalHelper`** (misma validación/formatInput/permisos). El form (`ui.v2.modals.devices.sensor-form`) replica los `name=` legacy y la lógica condicional por tipo (bloques `[data-sensor-input]`, `shown_value_by` dinámico, habilitadores `data-enable`, paneles de **calibración** y **mapping** con filas add/remove, **vista previa** vía `sensors.preview`). `$types` viaja en `<script type="application/json">` (innerHTML no ejecuta scripts). Gotcha: el hidden de edición es `name="id"` (no `sensor_id`) porque `preview()` lo usa para excluir el sensor de la unicidad por tipo. Pendiente menor: selector de icono por mapping (las filas existentes conservan su icono vía hidden).

---

## 🔄 Actualización · 21 jul 2026 — Hitos kilométricos del Perú (rama `nuevo-tema`)

**Feature nueva transversal**: capa de hitos KM de la red vial (dataset Provías, 31,723 puntos importados) en TODAS las páginas con mapa + referencia vial en popups. Detalle completo del diseño y los ajustes (sin SQL espacial por MariaDB 10.5, import artisan por versión) en `docs/implementacion_hitos_km.md`. Piezas: tabla `hitos_km` + `HitoKm` + `hitos:import` · endpoints `api.hitos` (bbox/zoom con densidad 1-10-100 y cache 24h) y `api.hitos.nearest` (≤2 km) · **`tp-hitos.js`** compartido (capa con debounce por viewport) · toggle "Mostrar hitos KM" en Live (persistido `m_hitos`), Historial y Eventos · popup de vehículo (Live) y de punto (Historial) muestran "PE-1N · km 557" con el toggle activo.

---

## 🔄 Actualización · 20 jul 2026 — Fix overlays en carga + kebab sin AJAX (rama `nuevo-tema`)

- **Overlays (geocercas/POIs/rutas) no se dibujaban en la 1ª carga de Live** (había que apagar/prender el toggle). Causa: el seed de toggles corre en `setTimeout(0)` y añade los vectores, pero `commit()` hace `fitToFleet()` (fitBounds a la flota) *después* — ese pan/zoom deja los SVG recién añadidos sin pintar hasta interactuar. Fix en `live-zones.js` / `live-pois.js` / `live-routes.js`: tras el `renderLayers()`/`renderMarkers()` del `load()` inicial, un `settleRedraw()` que re-dibuja UNA vez cuando el mapa se asienta (`map.once("moveend zoomend", …)` + `setTimeout(…, 500)` de respaldo). Events no se ve afectado (sus overlays cargan por clic del usuario, con el mapa ya asentado).
- **Kebab del device sin AJAX**: ver detalle en la entrega del módulo Enviar comando (template estático `tp-row-menu-tpl` + flags `data-need` en `liveData`).

---

## 🔄 Actualización · 19 jul 2026 — Enviar comando NATIVO (rama `nuevo-tema`)

**Módulo Enviar comando (GPRS/SMS) migrado a modal nativo v2**, disponible en TODA la app (rail) y por vehículo (kebab de Live):

- **Backend** `app/Http/Controllers/Ui/V2/CommandsController.php` sobre la lógica legacy: `meta` (gateway SMS + devices con flag `sms` por sim_number), `commands` (catálogo por **intersección** de los devices elegidos vía `CommandService` — comandos de protocolo Traccar + plantillas GPRS/SMS del usuario + custom/serial solo con perm `send_command` edit), `send` (delega en `SendCommandModalHelper::create()/gprsCreate()` → validación `EntityLoaderLimit` con límites de config + `SendCommandService`; mapea el contrato legacy status=0 → ok/message/warnings/results). Rutas `ui.v2.api.commands.{meta,list,send}`.
- **`tp-commands.js`** (global en el layout, gateado por perm en el config): `tpCommands.open(deviceId?)` — modal con tabs **GPRS/SMS** (pills Tailwind), selección de devices con buscador + select/deselect all (SMS filtra los que tienen SIM y avisa si el gateway está apagado), **select de tipo** (tp-select, se recarga por intersección al cambiar la selección con guard de respuestas obsoletas) y **atributos dinámicos** con paridad `formBuilder` legacy (string/integer→input, text→textarea span-2, datetime→datetime-local convertido a "Y-m-d H:i:s", select/multiselect→opciones). Confirmación (`are_you_sure`) antes de enviar; resultado en alert del modal (éxito verde / warnings por device en ámbar / errores 422 en rojo).
- **Cableado**: ítem del rail "Enviar comando" deja de ser "próximamente" (dispara el modal vía `[data-tp-commands]`); kebab del device en Live → `data-act="send_command"` abre el modal preseleccionando el vehículo.
- **Hub `/ui/v2/commands`** (2ª entrega, mismo día): página con tabs **Horario | Registros | Plantillas GPRS | Plantillas SMS** (paridad Ubimak) + botón "Enviar comando" (abre el modal compartido). `CommandsController` ampliado: **schedules** CRUD (reusa `SchedulesValidator` + `Scheduler` + `CommandSchedule`; extracción de parámetros GPRS copiada documentadamente del legacy por ser private), **logs** (`SentCommand::userAccessible` paginado en servidor con búsqueda), **plantillas** GPRS/SMS vía `UserGprs/SmsTemplateModalHelper` con `setApi(true)` (adapties/protocols/device_types del helper; sync de devices/deviceTypes). Modal de horario con 2 columnas: comando (conexión/devices/tipo/atributos, mismo catálogo por intersección) + programación (**Tiempo exacto / Cada hora / Diario / Semanal / Mensual**; weekly manda `days{weekday:{checked,time}}` con claves inglesas que `strtotime` entiende). Modal de plantilla: título, **adaptado** (Todos/Protocolo/Dispositivos/Tipos de dispositivo) con campos condicionales, mensaje + hint RAW/variables (`front.raw_command_supports`/`gprs_template_variables`). Rail → página; kebab → modal directo. `commands-page.js` + `pages/commands.blade.php`.
- **Cierre de Registros/Schedules (3ª entrega)**: kebab del horario gana **"Registros"** (modal paginado con las ejecuciones de ESE schedule, paridad `command_schedules.logs`); tab Registros gana **filtro por conexión** (Todos/GPRS/SMS, param `connection` en `logs()`) y **columna Usuario** para admin/manager (`show_user` dinámico en la cabecera). Gotcha tp-select: para ocultar un select mejorado hay que ocultar su **wrapper** (`sel._tpdd.wrap`), no el select original, y re-sincronizar en DOMContentLoaded (el enhance corre después de los scripts defer).
- **Auto-send when online (feature NUEVA, 4ª entrega)**: checkbox en el tab GPRS del modal. Los devices seleccionados que NO están conectados (mismo criterio `Device::isConnected()` que usa `SendCommandService`) se **encolan** en `pending_commands` (migración + entidad `Tobuli\Entities\PendingCommand`, status pending/sent/failed/expired) en vez de fallar; los conectados se envían al momento. `commands:dispatch-pending` (cada minuto en el Kernel, `withoutOverlapping`) los envía al reconectar — el `SendCommandService` registra el `SentCommand` como un envío normal, expiran a los 7 días. Se valida ANTES de encolar (`SendCommandFormValidator` gprs + loader).
- **Visor de resultados por dispositivo (4ª entrega)**: tras enviar, el modal muestra un `<details>` "Resultados (N)" con una fila por device: nombre + badge **OK** (verde) / **Error** (rojo, con el texto del tracker) / **En cola** (ámbar, auto-send). También se corrigió el render de las descripciones RAW de atributos (los `<br>` legacy llegaban escapados y se veían literales — ahora se escapan y se re-permiten SOLO los saltos). +4 claves i18n en 59 idiomas (`auto_send_when_online`, `queued_for_online`, `queued`, `results`).
- **5ª entrega — cola visible + auto-send en schedules + pulido del modal de horario**: tab **"En cola"** en el hub (lista `pending_commands` pendientes: device, comando, parámetros, intentos, último error, fecha; cancelar con confirmación; se recarga al entrar); **auto-send en schedules**: checkbox en el modal de horario (el flag viaja en `parameters.auto_online`) y `RunCommandScheduleJob` encola los devices desconectados al ejecutarse en vez de fallar; **selectores de programación** estilo referencia (minuto 0-59 y día 1-31 como selects con buscador vía tp-select, horas en pasos de 5 min con inyección del valor legacy si no calza, **semanal con hora POR DÍA** — paridad real con el Scheduler que lee `options.time` por weekday — cada fila se habilita con su checkbox); **Tipo + atributos ocultos hasta seleccionar dispositivos** en ambos modales (envío y horario).
- **6ª entrega — kebab sin AJAX + componente de checks + modal de envío estilo referencia**: el menú de acciones del device ya **no hace petición**: es un `<template id="tp-row-menu-tpl">` renderizado UNA vez (permisos de sesión) que live.js clona por device filtrando `data-need="vt|chat|sim|plans|app_uuid|general|video"` con flags que ahora viajan en `liveData`, y sustituyendo `__ID__`/`__IMEI__` en las data-url; endpoint `devices/{id}/actions` eliminado. Nuevo componente **`.tp-choice`/`.tp-choice-input`/`.tp-choice-row`** en components.css (radios/checks con gap y accent de marca garantizados — la causa raíz del modal "amontonado" era `UI.radio` sin definir en tp-commands → `class="undefined"`) y **`.tp-cmd-form`** (gap uniforme de secciones, hijos vacíos sin gap). Modal de envío: Conexión radios, devices como dropdown-chips con panel **portal fixed** (no lo recorta el overflow del modal), Tipo oculto sin devices, botón "Enviar comando".
- **Backlog restante del dominio**: ③ Widget "Comando GPRS" del drawer (paridad de widgets, el kebab ya cubre el caso). ④ Hints SMS (`add_sim_number_info`/`add_sms_template_info`) bajo los campos.

---

## 🔄 Actualización · 6 jul 2026 (3) — Rutas: grupos, lista agrupable, tabla, export/import (rama `nuevo-tema`)

**Módulo de Rutas completado con paridad Ubimak** (sobre el CRUD base de la entrega anterior):

- **Fix crítico**: `Route::boot()` usaba `GeomFromText`, **eliminada en MySQL 8** → `ST_GeomFromText` (entidad compartida, arregla también el legacy). Sin esto, **guardar cualquier ruta lanzaba** `SQLSTATE[42000] … FUNCTION …GeomFromText does not exist`.
- **Grupos de rutas** (`RouteGroupService`): `storeRouteGroup`/`updateRouteGroup`/`destroyRouteGroup` (título + asignación de rutas vía `syncItems`; al borrar el grupo las rutas quedan sin grupo). Modal de grupo con **buscador + Seleccionar todo / Deseleccionar todo** y casillas por ruta.
- **Lista agrupable** en el panel (estilo Objetos): botón **folder** que alterna agrupado/plano, grupos colapsables con chevron + contador + kebab (editar/eliminar grupo), grupo sintético "Sin grupo", y **casilla de visibilidad** por ruta (toggle `active` → `api.routes.toggle`, el mapa solo dibuja las activas).
- **Formulario de crear/editar ACOPLADO al sidebar** (no modal): nombre, **campo de color** (swatch + hex + limpiar) y **grupo con botón "+"** (abre el modal de grupo inline y auto-selecciona el nuevo). Al estar acoplado, la **geometría se edita en el mapa a la vez** — crear dibuja con `L.Draw.Polyline` y luego deja los vértices arrastrables; editar abre la polilínea con vértices/punto-medio editables. La capa estática se oculta mientras el form está abierto para editar solo el borrador. (Un modal centrado tapaba el mapa e impedía mover los puntos.)
- **Export / Import nativos** (reusan endpoints legacy): Export (Formato CSV/GEXP/KML · Tipo single/activas/inactivas/grupos · selección de rutas) vía form-POST que descarga el archivo; Import (CSV/GEXP/KML) vía `FormData`.
- **Página tabla** `/ui/v2/routes/table` (botón ☰ del panel): Visible|Nombre|Color con buscador, orden, paginación, badge de visibilidad toggleable, kebab editar (metadatos)/eliminar, y botones crear (→ panel de dibujo)/exportar/importar. `routes-table.js` + `routes-table.css`.
- **`tp-select.js` — componente de select PROPIO, 100% Tailwind, global**: reemplaza todos los `<select>` nativos de la v2 (`select.tp-input`/`.tp-select`, incl. inyectados por AJAX vía MutationObserver) por botón+panel estilados con utilidades Tailwind (el config escanea `resources/assets/ui-v2/js`). Mantiene el `<select>` original oculto y sincronizado (`.value`/`change` siguen funcionando), buscador si >8 opciones, teclado (↑/↓/Enter/Esc), optgroups, abre hacia arriba si no cabe, `dark:` según `data-theme`. API: `tpSelect.enhance/init/refresh`; opt-out `data-no-ts="1"`; `<select multiple>` no se toca. Se probó Tom Select (CDN y self-hosted) pero duplicaba el control al copiar las clases `tp-input` al wrapper → descartado y sin dependencias externas.

---

## 🔄 Actualización · 6 jul 2026 (2) — Rutas CRUD nativo (rama `nuevo-tema`)

**Rutas migradas a CRUD nativo** — cierra el último overlay del mapa que seguía siendo de solo lectura:

- **Backend** en `MapLayersController`: `storeRoute`/`updateRoute`/`destroyRoute` reutilizando **`RouteUserService`** (create/edit/remove + permisos vía Manager). El GET `routes()` se enriqueció (additivo, sin romper el formato `[lng,lat]` que consumen live.js y events.js): añade `groups`, `can_edit` y por-ítem `group_id`/`active`. Se reproduce el contrato legacy: el validador exige `polyline`, así que `routeInput()` lo deriva de las coordenadas dibujadas (`coordinates` = `[{lat,lng}]`). Rutas `api.routes.{store,update,destroy}`.
- **`live-routes.js`** (espejo de `live-zones.js` pero con **polilíneas**): panel con lista (buscador + filtro por grupo + contador), botón "+", capa Leaflet clicable (popup Editar / **Editar forma** / Eliminar), **dibujo con `L.Draw.Polyline`** + edición de vértices/punto medio, modal inline (nombre, color, grupo) reusando `tpModal`. Expone `window.tpLiveRoutes.activate()/setVisible()`. El toggle de rutas del control de mapa ahora **delega** en este módulo (como geocercas/POIs), con fallback de solo lectura.
- **UI**: panel `#tp-panel-routes` en `live.blade.php` (gateado por `perm('routes','edit')` para crear/editar, export/import legacy en el footer); rail "Rutas" → `?panel=routes` (deja de estar "pendiente"); `switchPanel`/`setRailActive`/rail-intercept extendidos a `routes`. Los dos handlers de `L.Draw.Event.CREATED` (zones y routes) coexisten filtrando por `layerType`.

---

## 🔄 Actualización · 6 jul 2026 — Servicios/Mantenimiento NATIVO (rama `nuevo-tema`)

**Módulo Servicios/Mantenimiento migrado a v2 con modales COMPARTIDOS** (reutilizables desde cualquier módulo que llame a servicios):

- **Backend** `app/Http/Controllers/Ui/V2/ServicesController.php`: reusa `ServiceModalHelper` legacy (create/edit/destroy + createData/editData) y los cálculos de la entidad `DeviceService` (`getLeft/getPercentage/left_formated/expiration`). Endpoints: `ui.v2.api.services` (feed global), `.device/{id}` (lista por vehículo), `.meta` (datos del form), `.store/.update/.destroy`. Página `ui.v2.maintenance` (perm `maintenance` view). El flag `expired`/renovación lo calcula el server (`prepareServiceData`); `renew_after_expiration` viaja **solo si está marcado** (el backend usa `isset`).
- **`tp-services.js`** (global `window.tpServices`): `openForm(deviceId, id?, onSaved)` — modal crear/editar con los campos del legacy (name, expira por odómetro/horas motor/días, intervalo, último servicio — input date si "días", numérico si no —, generar evento cuando queden, renovar al expirar, odómetro/horas actuales readonly, descripción, email máx 5 con ";"; errores 422 inline vía `tpModal.showFieldErrors`, el error `id`="ya expirado" → toast); `openList(deviceId)` — modal lista con búsqueda, "+ Añadir", tabla Nombre|Fecha de expiración (coloreada expirando/expirado), kebab ⋮ Editar/Eliminar y paginación filas-por-página; `remove(id)` — confirmación danger. Config/i18n desde `#tp-services-config` (partial `ui.v2.partials.services-config`, claves trans legacy `validation.attributes.*`).
- **Página `/ui/v2/maintenance`** (`pages/maintenance.blade.php` + `maintenance.js` + `services.css`): tabla global Device|Name|Distance(+left)|Engine hours(+left)|Days(+left) con **barra de progreso** por tipo (umbral legacy <20 rojo/<50 ámbar) y "left" en rojo si expirado, búsqueda, orden por columnas, per-page y kebab con los mismos modales.
- **Cableado**: widget Servicios de Live (☰ → lista, "+ Añadir servicio" → form, refresca el drawer al guardar) y rail "Mantenimiento" → página nueva (deja de estar "pendiente"). El widget ahora muestra `expiration()` real por servicio.

---

## 🔄 Actualización · 3 jul 2026 — Historial TERMINADO (rama `nuevo-tema`)

**Historial/Replay queda COMPLETO y con paridad+ respecto al legacy**: colores del recorrido como **propiedad del vehículo** (trips/single/speed/sensor, editor de rangos en ambos modales device), i18n completa vía archivos de traducción (27+2 claves nuevas en 59 idiomas; meses/días vía `Intl`), timeline con tarjetas ricas colapsables (••• y riel con número), **focus por evento** (drive→solo ese tramo + eventos internos con triángulo de advertencia; parking→solo el punto; trip start/end→popup resumen; fix viajes de 1 punto), popup por punto compacto/completo (paridad `popupPointContent` + endpoint `history.position`), flechas rojas legacy, gráficos con **sensores add_to_graph** + zoom/pan/rueda + eje X de horas, **reproducción por puntos** (x1=1 pos/s) con **HUD** (círculo velocidad por estado + brújula), registro de datos con todas las columnas + per-page, export GSR/KML/GPX/CSV vía endpoint legacy, controles de mapa en grupos (vista completa "limpia", tipo de mapa, **regla de medición** con panel ON/OFF/Limpiar y chips de distancia, capas, zoom), footer flotante con pin/colapso.

---

## 🔄 Actualización · 1 jul 2026 — AUDITORÍA COMPLETA (rama `nuevo-tema`)

### Migración del motor de mapas: MapLibre → Leaflet (✅ COMPLETA)
Todo el shell v2 corre ahora en **Leaflet 1.9.4** (mismo motor que el legacy). MapLibre fue **eliminado por completo** (páginas, JS y helpers muertos borrados). Piezas:
- **`tp-basemaps.js`**: catálogo COMPLETO de proveedores (OSM/Google×6/Here/Mapbox/MapTiler/OpenMapTiles/TomTom/Azure/Carto/LocationIQ) desde `MAP_CFG.available`+`keys`. Selector anclado al botón; persiste `user->map_id`.
- **Live (`live.js`)**: marcadores reales 3 tipos a tamaño legacy (sin escalado), **selección con glow + halo pulsante**, cluster custom (`iconCreateFunction`, 3 tamaños), tails, nombres, tráfico, geolocate con feedback, fullscreen/fit/autofollow. **Toggles persisten en `user->map_controls`** (mismo store que el legacy, vía `change_map_settings`) y se siembran de la DB al cargar.
- **Geocercas (`live-zones.js`)**: panel sobre el MISMO mapa. CRUD + **dibujo leaflet-draw** (polígono con "Terminar", círculo) + **edición de vértices/punto medio** + matriz de límites por tipo/estado de vehículo (VSL) + enlaces import/export. Página standalone `/ui/v2/zones` deprecada (redirect al panel).
- **POIs (`live-pois.js`)**: panel, CRUD nativo (`PoiUserService`), icono real, **drag & drop** (PUT coords), lista agrupada colapsable.
- **Eventos (`events.js`)**: Leaflet + marcador real del device + overlays nativos + filtro **"Todos"** por defecto (hours=0, sin límite temporal) + scroll infinito + geocoding perezoso.

### Historial / Replay (✅ REDISEÑO INTEGRAL, estilo integrado)
- **Backend reusa el pipeline legacy** (`HistoryModalHelper::getApi()`/`getMessages()`): timeline **drives/stops/events** con combustible GPS, horas motor, v.máx/prom, conductor + resumen; registro de datos paginado.
- **Sidebar flotante** sobre el mapa (estilo referencia): device **searcheable** (combo con ✓), **date-picker modal** (2 meses, rangos rápidos, Desde/A con hora), **Avanzado** (duración de paradas, ajustar a carretera=snapToRoad legacy, coords no válidas), acciones Mostrar/Descargar/Limpiar, **timeline con tarjetas ricas** (▶ reproduce un viaje).
- Mapa: **3 modos de color** (Simple / Por velocidad con leyenda / Por viajes), flechas (`polylinedecorator`), popup por punto (dirección perezosa), cursor = icono real rotado.
- **Cards resumen flotantes** (TailwindCSS) sobre el mapa.
- **Footer**: tabs **Gráfico** (canvas sync con playback, scrubber con marcas, 1×/8×/32×/120×, Velocidad/Altitud) y **Registro de datos** (tabla paginada con **columnas dinámicas = TODOS los parámetros del XML `other`**, como el legacy).
- Deep-link `?device=&range=` + botón "Ver historial" del drawer de Live.

### Rail / Sidebar de navegación (✅ REDISEÑADO)
16 ítems (Dashboard, Objetos, Alertas, Historial, Reportes, Enviar comando, Cámara/Medios, Compartir, Mantenimiento, Tareas, Conductores, Chat, Acciones de llamada, Geocercas, Rutas, POI) + footer (Idioma es⇄en, Versión anterior). **Gating por permisos = paridad con el menú Tools del legacy** (`perm(módulo,'view')` por ítem; Acciones de llamada exige además `events`; Dashboard/Objetos/Historial sin condición, como el legacy). Los addons de `config/addon.php` gatean features puntuales (checklists, external_url, custom_fields_task, media_categories…) y se respetarán al migrar cada una. **Dos modos** (completo icono+nombre 236px / compacto icono+tooltip 56px) con toggle ☰ persistido. Iconos **SVG a medida** (estilo línea fina, según referencia). Ítems legacy sin migrar = `data-rail-pending` (toast "próximamente"). El rail intercepta Objetos/Geocercas/POIs para cambiar panel **sin recargar** y sincroniza el ítem activo. Los tabs del panel de Live se eliminaron (redundantes).

### Live · lista y widgets (✅ mejoras)
- **Grupos como caja con borde** (header con fondo + devices contenidos), colapso/expand del panel cableado con handle.
- **Widgets del bottombar** = cards con **header fijo (icono+título+acción) y cuerpo scrolleable**, 320px, sombra flotante. Widget **Servicios**: icono "3 rayitas" (lista) + botón "+ Añadir servicio" (slate `#334155`, texto blanco garantizado) — ✅ ya cableados a los modales nativos de `tp-services.js` (ver actualización 6-jul).
- `.tp-btn--accent` garantiza icono+texto blancos (fix del patrón "fondo oscuro/texto invisible").

### Pendiente inmediato (ver tabla §3 actualizada)
~~Modales de Servicios~~ (✅ 6-jul, `tp-services.js` + `/ui/v2/maintenance`). Migrar **Rutas CRUD** (dibujo), **Enviar comando**, **Compartir**, tabs faltantes del modal device (**Sensores/Usuarios/Servicios Web**), acciones del kebab (12 stubs) y popup/sonido de eventos en vivo. (La regla de medición ya existe en Historial; portarla a Live es menor.)

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
**Rutas CRUD** (dibujo de polilínea), **Enviar comando** (UI compleja: catálogo GPRS/SMS dinámico, schedules), **Compartir**. ~~Mantenimientos/Servicios~~ → ✅ hecho 6-jul. Afinar: drawer (servicios/streetview/video), modal device (tabs Sensores/Usuarios/Servicios Web), reportes (Schedules), topbar Herramientas + idioma. Cosmético/debrand `.tp-*`/`window.Talentus`/cookies/marca: fuera de alcance hasta pedirlo.

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
| Servicios | ✅ `expiration()` real + modales nativos lista/form/eliminar (`tp-services.js`, 6-jul) |
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
- **Gates de addon/plugin (regla de oro):** toda condición `config('addon.*')` o `settings('plugins.*.status')` presente en una vista/flujo legacy **debe existir idéntica** en su equivalente v2. Al portar un botón/menú/campo, copiar su `@if (...)` tal cual (ej. geolocalización → `config('addon.html_geolocation')`). No relajar ni asumir "siempre activo".

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

> **Tabla actualizada · 25 jul 2026** (auditoría completa de los 68 módulos de `Tobuli/Views/Frontend`)
>
> **Pendientes reales user-facing** (prioridad sugerida):
> 1. **Cámara / Medios** (`DeviceMedia`·`DeviceVideo`·`Video`·`MediaCategories`) — página galería + live/playback (hoy solo widget de lectura).
> 2. **DeviceAlerts** — asignación de alertas por dispositivo.
> 3. **Chat** · **CallAction** (Acciones de llamada) — hoy stubs en el rail.
> 4. **DeviceConfig** · **LockStatus** — configuración remota / bloqueo del dispositivo.
> 5. **Import** masivo de dispositivos (CSV/XLS) — hoy solo import de geocercas/POIs.
> 6. **Checklist** · **ChecklistTemplates** — prestart/servicio + QR (addon `checklists`).
> 7. **CustomFields** — panel de campos personalizados en los forms de device/tarea/usuario (addon).
> 8. Menores: **DeviceRoutesType**, icon-picker por sensor (`IconsModal`), **Beacons**, **Forwards**, **SecondaryCredentials** (hoy deeplink a legacy).
> 9. **Comercial** (`DevicePlans`·`DeviceSubscription`·`Subscriptions`) — evaluar si entra en el alcance del tema.
> 10. **Popups** (`Popups/` — banners/modales condicionales del admin por reglas: FirstLogin/DemoUser/SubscriptionEnding/UserCreatedBefore/BillingPlan, posiciones top/esquinas/center, `show_every_days`, shortcodes). Gated por addon `notification_popops` (`SHOW_POPUPS`). Requiere incluir en `ui.v2.layout` los equivalentes de `Frontend.Popups.index_banners_top` (banners) y `Frontend.Popups.index` (modales auto-abiertos). NO confundir con `tp-notify.js` (toasts de eventos en vivo, ya ✅).
>
> Auth (`Login`/`Registration`/…) y admin (`Emails`/`Docs`/`Lookup`/`LoginAs`/`Schedules`/`SmsGateway` admin) quedan **fuera** del shell v2 de usuario.

| Dominio legacy | UI v2 | Estado |
|---|---|---|
| `Layouts/loged.blade.php` (live shell + mapa) | `live` (Leaflet) | ✅ Falta: **regla de medición** (leaflet.ruler), tools showPoint/showAddress, chat flotante |
| `Objects/` (sidebar tabs objects/events/history/alerts/geofencing/routes/pois) | live (paneles) + páginas propias | ⚠️ objects/geofencing/pois = paneles ✅; events/history = páginas ✅; **routes tab sin CRUD**; alerts tab → página Alertas |
| `Dashboard/` (24 widgets) | `dashboard` | ⚠️ KPIs básicos; faltan los widgets configurables del legacy |
| `Devices/` (CRUD 8 tabs) | `fleet` + modal tabbed | ⚠️ 8 tabs nativos PERO auditoría 6-ago: expiración deriva TZ + checkbox marcado al crear (D1), falta tab CustomFields (D11) e Historial de mantenimientos (F17), sensores solo-admin (F9), beacons 422 (F10), gate `custom_device_add` (S7) |
| `DevicesGroups/` | ✅ kebab en cabeceras de grupo de Live (Agregar/Editar/Eliminar) + modal "Grupos de objetos" (título + vehículos agrupados con Seleccionar todo); endpoints `api.device_groups.*` con UPDATE del pivot `user_device_pivot.group_id` por usuario (0 = sin grupo, paridad legacy) | ✅ (23-jul) |
| `Geofences/` · `GeofencesGroups/` | panel zones (draw + vértices + VSL) | ⚠️ auditoría 6-ago: sin policy read-only/demo + `can('view')` en update/destroy (S4), `speed_limit` se corrompe por unidades (D2), edit reactiva geocercas desactivadas (D7), import/export roto (F2), `device_id`/intervals (F7/D12); grupos solo lectura |
| `Pois/` · `PoisGroups/` | panel pois (CRUD + drag) | ⚠️ sin toggle `active` (D7), import/export roto (F2); grupos solo lectura |
| `Routes/` · `RoutesGroups/` | panel routes (draw + CRUD + grupos + lista agrupable) `live-routes.js` + página `/ui/v2/routes/table` + export/import nativos | ✅ |
| `Alerts/` · `Events/` | `alerts` (tabla índice + modal 6 tabs) · `events` (página) | ⚠️ alertas: riesgo `sync([])` en modo api (D5) · eventos: alcance sin `userAccessible` + TZ (D13/D14), sin borrado (F6) |
| `DeviceAlerts/` · `CustomEvents/` | — | ❌ |
| `History/` (+ historyPlayer/historyGraph) | `history` (rediseño integrado, pipeline legacy) | ⚠️ auditoría 6-ago: `snap_to_road` no-op (F1), data-log siempre asc + `distance_sum` ≠ legacy + borrar posiciones sin superficie (F5). Export GSR/KML/GPX/CSV sí funciona (endpoint legacy) |
| `Reports/` (~130 tipos) | página tabs guardados/logs + modal Generar/Guardar (campos por tipo, schedules, range picker propio) | ⚠️ auditoría 6-ago: `send_to_ftp` degradado a checkbox — **destruye la URL FTP al editar** (D4); `period` preset no viaja |
| `SendCommand/` · `CommandSchedules/` · `UserGprsTemplates/` · `UserSmsTemplates/` | modal `tp-commands.js` + hub `/ui/v2/commands` | ⚠️ auditoría 6-ago: quitar vehículo de un horario nunca desasigna (D6), editar plantilla adaptada desasocia devices (D9), listado de plantillas da 500 a usuarios view-sin-edit (F4) |
| `Sharing/` (crear + lista + página pública) | `/ui/v2/sharing` + `tp-sharing.js`; página pública = legacy reutilizada | ⚠️ auditoría 6-ago: `expiration_date` sin `reverse()` — caduca desfasado (D3); canal SMS y sharing_device ausentes (F11); escenario validador `send` en update |
| `Services/` · `Maintenance/` · `DeviceExpenses/` | `tp-services.js` + `/ui/v2/maintenance` + tab Gastos del modal + página `/ui/v2/expenses` | ⚠️ auditoría 6-ago: **colisión de rutas `expenses/{id}`** (S2), gastos sin permiso `device_expenses` ni validator legacy (S6), `ServicesController` convierte PermissionException en 500 (F15) |
| `Tasks/` · `TaskSets/` · `Checklist/` · `ChecklistTemplates/` | `/ui/v2/tasks` + `tp-tasks.js` (tabla + modal + import CSV/XLS + Conjuntos) | ⚠️ auditoría 6-ago: `custom_fields_task` no se guardan ni muestran (D10); `assign` salta el service (Ola 0). Checklists/ChecklistTemplates sin migrar |
| `UserDrivers/` (conductores) | ✅ `/ui/v2/drivers` + `tp-drivers.js` (CRUD + pivot devices + vehículo actual) | ✅ (22-jul; falta activity log + RFID inline) |
| `Chat/` | `/ui/v2/chat` + `tp-chat.js` (lista + hilo con polling) | ⚠️ **auditoría 6-ago: IDOR — messages/send sin comprobar pertenencia (`ChatPolicy`); módulo `chat` vs `chats` legacy (S1)**; sin chat user↔user ni paginación hacia atrás |
| `CallAction/` | `/ui/v2/call-actions` + `tp-call-actions.js` | ⚠️ auditoría 6-ago: `user_id` falsificable en store (S5); `called_at` se sobrescribe al editar con date_format ≠ ISO (D8); mass-assignment del request crudo |
| `DeviceMedia/` · `DeviceVideo/` · `Video/` · `MediaCategories/` | widget Cámara (lectura) + links video legacy | ❌ galería/live/playback nativos |
| `Sensors/` (modal) | ✅ migrado como tab **Sensores** del modal device (CRUD vía `SensorModalHelper`, buscador + grupo + preview) | ✅ |
| `DeviceConfig/` · `LockStatus/` | `LockStatus` ✅ (widget de bloqueo v2: estado + Cortar/Restablecer + historial, comandos preconfigurables) · `DeviceConfig` stub | ⚠️ Falta solo **DeviceConfig** (config remota del device) |
| `DevicePlans/` · `DeviceSubscription/` · `Subscriptions/` | stub | ❌ |
| `MyAccount/` · `MyAccountSettings/` · `Languages/` | ✅ **modal Ajustes de cuenta** (`tp-account.js`, engranaje/menú de usuario del topbar) con **paridad total de tabs** (títulos exactos legacy): Principal (unidades, formatos, inicio de semana, TZ, horario de verano) · Grupos de vehículos · Conductores · Tipos de vehículo · Eventos · SMS gateway · Plantillas de SMS · Plantillas de GPRS · Widgets · Tablero · Cliente · **Mapa** (2 toggles: velocidad en marcador + color de etiqueta por estado, per-usuario en map_controls, aplicados en Live). Tabs de formulario guardan contra `AccountController`; tabs CRUD reutilizan/crean endpoints (device-groups, drivers, vehicle-types, custom-events, commands/templates). **Contraseña** salió del modal → item "Cambiar contraseña" del menú de usuario (modal dedicado). Reutiliza validadores/servicios legacy | ✅ (23-jul; opciones avanzadas de bloques del Tablero y Membresía/Planes fuera de alcance) |
| `VehicleTypes/` (tipos/estados p/ límites por geocerca) | matriz VSL + CRUD de tipos/estados (tab del modal de cuenta) + label/color/badge/cambiador (29-jul) | ⚠️ auditoría 6-ago: `VehicleTypesController` v2 **sin ningún gate** en data/store/update/destroy (S3) |
| `CustomEvents/` (events_custom) | ✅ tab "Eventos" del modal de cuenta (`CustomEventsController` ui/v2 sobre CustomEventModalHelper; protocolo + mensaje + condiciones tag/tipo/valor) | ✅ (23-jul) |
| notifications.js (popup + sonido + desktop Notification de eventos) | ✅ `tp-notify.js` global (toast+sonido+Notification API, feed `events/live`) + tray con eventos reales | ✅ (22-jul) |
| `Popups/` (banners/modales condicionales del admin, por reglas) | ❌ no incluido en `layout.blade.php` v2 | ❌ **pendiente** (sistema distinto de `tp-notify`; ver ítem 10 de pendientes) |
| `ObjectsList/` (listview) | `fleet` | ⚠️ NO equivalente: faltan columnas configurables por usuario + rangos de color por sensor (`listview_fields`/`numeric_sensors`) (F14) |
| `Import/` (masivo devices) | solo geofences/pois import links | ❌ |
| `Forwards/` · `SecondaryCredentials/` · `SmsGateway/` · `Beacons/` · `Lookup/` · `Addresses/` · `Docs` · `LoginAs/` · `Schedules/` | `admin` (deeplinks) | ⚠️ Solo enlace |
| `Login/` · `Registration/` · `PasswordReminder/` · `EmailConfirmation/` · `CustomRegistration/` · `Captcha/` | — | ❌ Solo legacy (fuera del shell) |
| `CustomFields/` (campos personalizados device/task/user — addon) | — | ❌ panel `CustomFields.panel` embebido en forms; falta en modal device/tareas v2 (gated por addon `custom_fields`/`custom_fields_task`) |
| `DeviceRoutesType/` (tipo de ruta por device) | — | ❌ CRUD por device (`device/{id}/route_type`) sin migrar |
| `IconsModal/` (selector de iconos del device) | ⚠️ galería de iconos en el tab **Iconos** del modal device | ⚠️ falta icon-picker por mapping de sensor |
| `Emails/` (`template.blade`) · `EmailSettings` | `admin` (deeplink) | ⚠️ admin/sistema — no user |

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

**Última actualización:** 6 agosto 2026 (auditoría exhaustiva legacy↔v2) · rama `nuevo-tema`
