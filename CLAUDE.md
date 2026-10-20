# CLAUDE.md — Talentus Pro Tracking

Guía para Claude Code sobre la arquitectura, convenciones y flujos de trabajo de este proyecto.

> **Nota sobre la migración de estilos en curso:** se está migrando la UI a un estilo más
> profesional. La sección [**Frontend / UI**](#frontend--ui-vistas-estilos-mapas-modales-js) es el
> mapa completo de vistas, SCSS/temas, mapas, modales, permisos y archivos JS por módulo. Léela
> antes de tocar cualquier estilo.

---

## Stack Tecnológico

### Backend
- **Laravel 8** (PHP 8.x)
- **MySQL** — base de datos principal
- **Redis** — caché, colas y pub/sub para tiempo real
- **Fractal** — transformers para respuestas API

### Frontend
- **Blade** — templating (vistas en `Tobuli/Views/`, NO en `resources/views/`)
- **jQuery 3.2.1 + Bootstrap 3** — base de UI (NO React/Vue; JS imperativo "vanilla + jQuery")
- **Leaflet 1.0.3** — motor de mapas (con plugins: draw, editable, markercluster, googleMutant, yandex, bing…)
- **SCSS (node-sass)** compilado con **Gulp** — sistema de temas multi-color (NO usa Laravel Mix/Vite)
- **Socket.io + Node.js** — servidor WebSocket en `socket/socket.js` (tiempo real GPS)
- **Flot** — gráficas (reportes, historial, dashboard)

---

## Estructura de Namespaces (DUAL)

| Namespace        | Ubicación  | Propósito                                                         |
| ---------------- | ---------- | ----------------------------------------------------------------- |
| `App\`           | `app/`     | Capa Laravel estándar (Controllers, Middleware, Events, Jobs)     |
| `Tobuli\`        | `Tobuli/`  | Dominio de negocio (Entities, Repositories, Services, Validation) |
| `CustomFacades\` | `Facades/` | Facades custom (Repos, ModalHelpers, Validators)                  |

> ⚠️ Las **vistas Blade NO están en `resources/views/`** sino en **`Tobuli/Views/`**
> (`Frontend/` y `Admin/`). `resources/views/` solo tiene cascarones (`app.blade.php`, auth, errors).

---

## Comandos de Desarrollo

```bash
# Servidor Laravel
php artisan serve

# Servidor de sockets (tiempo real GPS)
cd socket && node socket.js

# ── Assets (Gulp, NO Laravel Mix) ───────────────────────────────
gulp                # sass (tema light-blue) + scripts (3 bundles) + watch con browser-sync
gulp watch          # observa scss/ y js/ y recompila
gulp sass           # compila SOLO el tema activo de dev (templates/light-blue.scss)
gulp sass-all       # compila TODOS los temas (templates/*.scss → public/assets/css/*.css)
gulp scripts        # reconstruye los 3 bundles JS (core.js, app.js, report.js)
gulp templates      # sass-all + minify-css  (regenerar todos los temas para producción)
gulp assets         # sass-all + minify-css + scripts + minify-js (build completo de producción)

# Base de datos
php artisan migrate
php artisan db:seed
php artisan devices:update-config
```

> Tras editar SCSS o JS hay que **recompilar con Gulp**: el navegador carga los archivos ya
> compilados de `public/assets/` (`*.css` y `core.js`/`app.js`), nunca los fuentes de `resources/`.

---

## Frontend / UI (vistas, estilos, mapas, modales, JS)

> Esta es la sección central para la migración de estilos. Cubre el pipeline de assets, el sistema
> de temas SCSS, el layout maestro, mapas, modales, permisos en la vista, y qué JS gobierna cada módulo.

### A. Pipeline de assets (Gulp → `public/assets/`)

Definido en `gulpfile.js`. **Tres bundles JS** (se concatenan, en este orden):

| Bundle (`public/assets/js/`) | Contenido | Dónde se usa |
| ---------------------------- | --------- | ------------ |
| `core.js`  | jQuery, jQuery-UI, Bootstrap, bootstrap-select, datepicker/datetimepicker, colorpicker, **modalmanager**, toastr, dataTables, intl-tel-input, flot, + `helpers/` + **todos los `plugins/`** | Toda página logueada (base de UI) |
| `app.js`   | moment, **Leaflet + todos sus plugins**, **todos los `controller/`**, **todos los `model/`**, socket.io, `plugins/chat.js` | Mapa principal / app en vivo |
| `report.js`| jQuery, moment, flot, Leaflet + polylineDecorator | Vistas de reportes generados |

El layout maestro carga `core.js` y luego `app.js` (ver `loged.blade.php`).
`-debug.js` son las versiones sin minificar.

**SCSS → CSS:** `gulp sass-all` compila cada `resources/assets/scss/templates/*.scss` a
`public/assets/css/<nombre>.css`. El `<nombre>` del template = nombre del tema (ej. `light-blue.css`).

### B. Estructura de carpetas Frontend

```
resources/assets/
├── js/
│   ├── controller/   ← un "controlador" JS por módulo del mapa (ver tabla §G)
│   ├── model/        ← modelos JS por entidad (Device, Geofence, Poi, Route, Alert, Event, MapTiles)
│   ├── plugins/      ← widgets/utilidades reutilizables (modals, tables, loader, actions, chat…)
│   ├── helpers/      ← helper.js (utilidades globales), fileread.js
│   └── lib/          ← librerías de terceros (jquery, bootstrap, leaflet/*, flot/*, moment…)
├── scss/
│   ├── _index.scss       ← orquestador: variables → mixins → libraries → layout
│   ├── _variables (en layout/) ← TODA la paleta y tipografía base (tokens de diseño)
│   ├── colouring/    ← temas: _main-<color> (define $brand-primary) + _base-ligth/_base-dark
│   ├── templates/    ← ENTRYPOINTS por tema (light-blue.scss, dark-red.scss, facelift.scss…)
│   ├── layout/       ← componentes propios (header, sidebar, map, modals, forms, buttons, dashboard…)
│   ├── libraries/    ← override de estilos de terceros (bootstrap, leaflet, font-awesome, select…)
│   └── mixins/
└── lang/             ← (i18n PHP, no estilos)

Tobuli/Views/Frontend/   ← vistas Blade de usuario final (~70 módulos, ver §F)
Tobuli/Views/Admin/      ← panel de administración
public/assets/           ← SALIDA compilada (css/, js/, fonts/, icons/, images/, plugins/)
```

### C. Sistema de Temas y Estilos (SCSS) — clave para la migración

**Cascada de un tema** (ej. `templates/light-blue.scss`):

```scss
@import '../colouring/main-blue';   // 1. acento de marca: define $brand-primary (#1b99bd)
@import '../colouring/base-ligth';  // 2. modo claro u oscuro (fondos, textos)
@import '../index';                 // 3. todo el resto del sistema
```

`_index.scss` carga en orden: **`layout/variables` → `mixins/index` → `libraries/index` → `layout/index`.**

- **`layout/_variables.scss`** = los **design tokens**. Paleta semántica con escalas
  (`$color-bg-000…300`, `$color-text-000…900`, `$color-border-*`, `$color-shadow`), tipografía
  (`$font-family-sans-serif: "Open Sans"`, `$font-size-base: 12px` + escalas), z-index, y mapeos
  Bootstrap (`$body-bg`, `$text-color`, `$component-active-bg: $brand-primary`…). **Editar aquí
  cambia todo el sistema** — es el primer lugar a tocar para un restyle.
- **`colouring/_main-<color>.scss`** — cada uno define **solo `$brand-primary`** (el color de acento).
  Hay 13 colores (blue, cyan, golden, gray, green, indigo, ocean-blue, orange, pink, purple, red,
  black, win10-blue).
- **`colouring/_base-ligth.scss` / `_base-dark.scss`** — variante clara/oscura (sobreescriben
  fondos/textos antes de cargar `_index`).
- **`templates/*.scss`** — 26 combinaciones (13 colores × light/dark) **+** especiales:
  `facelift.scss`, `report.scss`, `report-map.scss`, `rtl.scss`. Cada uno produce un `.css` en
  `public/assets/css/`.

**Orden de componentes en `layout/_index.scss`** (cada uno es un parcial `layout/_<nombre>.scss`):
`base, icons, header, scrollbar, sidebar, navs, navbars, buttons, input-groups, list-group,
dropdown, forms, modals, tables, map, pagination, widgets, groups, panels, loader, sign-in, dialog,
plans, bottombar, chat, dashboard, autocomplete-select, thumbnails, banner, datatables`.

**Overrides de terceros en `libraries/_index.scss`:** bootstrap (carpeta completa), jquery-ui,
animate, font-awesome, **font-custom** (los iconos `.icon` del UI), bootstrap-checkbox/datepicker/
datetimepicker/select/colorpicker/toastr, **leaflet** (leaflet, controls-layer, markers-cluster,
draw, popup), autocomplete, intl-tel-input.

**¿Qué tema se sirve?** En `Layouts/partials/head.blade.php`:
```blade
<link rel="stylesheet"
  href="{{ asset_resource('assets/css/' . Appearance::getSetting('template_color') . '.css') }}">
```
`Appearance::getSetting('template_color')` (ej. `light-blue`) decide el CSS. Además se inyecta CSS/JS
custom por marca blanca si existe (`Appearance::assetFileExists('css'|'js')`), y `rtl.css` si el
idioma es RTL.

> **Recetas de migración:**
> 1. Restyle global de tokens → editar `layout/_variables.scss` (paleta/tipografía) y/o el
>    `colouring/_main-*` del acento; `gulp sass` para previsualizar el tema de dev.
> 2. Restyle de un componente → editar su parcial en `layout/` (ej. `_modals.scss`, `_map.scss`,
>    `_buttons.scss`, `_header.scss`, `_sidebar.scss`).
> 3. Tema nuevo desde cero → crear `templates/mi-tema.scss` (copiando el patrón de 3 imports),
>    `gulp sass-all`, y poner `template_color = mi-tema` en Appearance.
> 4. Recompilar producción → `gulp templates` (CSS) y/o `gulp assets` (todo + minify).

### D. Layout maestro y partials

**`Tobuli/Views/Frontend/Layouts/loged.blade.php`** — esqueleto de TODA la app logueada:

- `@include('Frontend.Layouts.partials.head')` → `<head>` (CSS del tema, meta, csrf).
- `partials.header` → barra superior (logo, menú "Tools", cuenta) — ver §E permisos.
- `#sidebar.sidebar.left` → panel lateral con **tabs**: `objects_tab`, `events_tab`, `history_tab`,
  y tabs ocultos para alerts/geofencing/routes/pois. El contenido lo inyecta `@yield('items')`
  (en la home es `Objects/index.blade.php`).
- `#mapWrap > #map` → contenedor Leaflet + `#map-controls` (zoom, capas, cluster, fit, mostrar
  objetos/geocercas/rutas/pois/nombres/tail/tráfico, controles de historial).
- `#bottombar` → History.bottom + Widgets.
- Carga `core.js` + `app.js`, luego `partials.trans` (cadenas i18n a JS), `partials.app`
  (inyecta `getJsConfig()` en `app`), y arranca con `app.init()` en `window.load`.
- Define el modal genérico de confirmación `#js-confirm-link`.

**Layouts de modal** (los usan las vistas que se cargan por AJAX dentro de un modal):
- `Layouts/modal.blade.php` — modal estándar: `@yield('title')`, `@yield('body')`/`@section('content')`,
  y footer con botones por defecto **Guardar** (`data-submit="modal"`) / **Cancelar** (`data-dismiss`).
- `Layouts/modal_full.blade.php` — modal a pantalla completa (`.modal-full`).

`Layouts/partials/`: `head`, `header` (18 KB, el menú principal), `app` (config JS), `trans`
(i18n→JS), `loading`, `modal_warning`, `confirmed_delete/`.

### E. Permisos en el Frontend

Mismo motor que el backend (`User::perm($module, $action)`), pero usado en Blade para **mostrar/ocultar
UI** y en controladores para autorizar. En las vistas:

```blade
@if (Auth::user()->perm('geofences', 'view'))   ... @endif
@if (isAdmin())                                  ... @endif   {{-- helper global --}}
```

Ejemplos reales (`partials/header.blade.php`): el menú **Tools** muestra cada entrada
(alerts, geofencing, routes, reports, poi, send_command, camera, tasks, maintenance…) solo si
`Auth::user()->perm('<module>', 'view')`. El tab **Events** del sidebar y secciones del mapa
también se condicionan por permiso. Roles/acciones completos en [Roles y Permisos](#roles-y-permisos).

> Al reestilizar el header/sidebar, conservar los `@if (...perm...)` y los hooks de comportamiento
> (`data-modal`, `data-url`, `onclick="app.X.list()"`, `data-toggle="tab"`); cambiar solo marcado/clases.

### F. Módulos de vista (Blade) — `Tobuli/Views/Frontend/`

~70 módulos (un folder por feature). Los más grandes / relevantes para la UI:

| Módulo | Nº blades | Rol en la UI |
| ------ | --------- | ------------ |
| `Objects/` | 20 | **Mapa principal**: `index` (tabs), `item` (tarjeta de objeto), `tabs/` (objects, events, history, alerts, geofencing, routes, pois), `partials/delete*`, `tools/` (showPoint, showAddress) |
| `Reports/` | 111 | Formularios y plantillas de los ~130 reportes |
| `Dashboard/` | 24 | Widgets y bloques del dashboard |
| `Devices/` | 22 | CRUD de dispositivos (modales create/edit, tabs de config) |
| `Widgets/` | 15 | Tarjetas/paneles del bottombar |
| `Geofences/`, `Pois/`, `Routes/` | 10–11 c/u | CRUD de capas del mapa (create/edit/item/items/modal/table/export/import) |
| `Sensors/`, `Alerts/`, `Sharing/`, `SendCommand/`, `Checklist/` | 7–11 | Modales de gestión |
| `VehicleTypes/` | 4 | CRUD de tipos/estados de vehículo (ver sección dedicada) |
| `Layouts/` | 13 | Esqueletos (loged, modal, modal_full, sharing) y partials |

Convención CRUD por módulo: `index/items` (listado), `item` (fila/tarjeta), `create`/`edit`
(formularios que se sirven dentro de un modal vía `@extends('Frontend.Layouts.modal')`), `table`,
`groups`, y `export/import` cuando aplica.

### G. Modelos, Controllers y Plugins JS

**Patrón.** No hay framework reactivo. Cada módulo del mapa es una **función-constructor** (clase) en
`controller/`, instanciada **una sola vez** dentro del objeto global `app` (`controller/app.js`):

```js
// controller/app.js
var app = {
  maps:    new MapTiles(),  history: new History(),  devices:  new Devices(),
  geofences: new Geofences(), pois: new Pois(), routes: new Routes(),
  alerts:  new Alerts(),    events: new DeviceEvents(), deviceMedia: new DeviceMedia(),
  chat:    new Chat(),      dashboard: new Dashboard(), ...
};
```

Un **controller** (ej. `Geofences`) gestiona una colección de **models** (ej. `Geofence`); el model
encapsula `options` (datos) + su `layer`/`popup` de Leaflet. Patrón interno: `var _this = this;`,
métodos públicos `_this.metodo = function(){}`, y `_this.events()` para enlazar eventos jQuery.

| `controller/*.js` | `model/*.js` | Responsabilidad | Vistas Blade asociadas |
| ----------------- | ------------ | --------------- | ---------------------- |
| `app.js` | — | Núcleo: `app.init()`, mapa, settings, `initComponents()`, tabs, ruler, fullscreen | `Layouts/loged`, `Objects/index` |
| `devices.js` | `device.js` | Objetos/vehículos en el mapa (marcadores, lista, follow, sensores, widgets) | `Objects/item`, `Devices/*` |
| `geofences.js` | `geofence.js` | Geocercas (dibujo, edición, colores) | `Geofences/*`, `Objects/tabs/geofencing` |
| `pois.js` | `poi.js` | Puntos de interés | `Pois/*`, `Objects/tabs/pois` |
| `routes.js` | `route.js` | Rutas | `Routes/*`, `Objects/tabs/routes` |
| `alerts.js` | `alert.js` | Alertas | `Alerts/*`, `Objects/tabs/alerts` |
| `events.js` | `event.js` | Eventos en vivo | `Events/*`, `Objects/tabs/events` |
| `history.js`, `historyPlayer.js`, `historyGraph.js` | — | Reproducción de historial + gráfica Flot | `History/*`, `Objects/tabs/history` |
| `dashboard.js` | — | Dashboard de widgets | `Dashboard/*` |
| `listview.js` | — | Vista de lista de objetos (alternativa al mapa) | `ObjectsList/*` |
| `notifications.js` | — | Notificaciones popup/sonido | `Popups/*` |
| `commands.js` | — | Envío de comandos GPRS | `SendCommand/*` |
| `deviceMedia.js` | — | Cámaras/fotos del dispositivo | `DeviceMedia/*` |
| — | `MapTiles.js` | Catálogo de capas base (Google×4, OSM, Yandex, Bing, Here, Mapbox, TomTom, Azure, MapTiler…) | controles de capa en `loged` |

**Plugins (`js/plugins/`, van en `core.js`):**

| Plugin | Función |
| ------ | ------- |
| `modals.js` | **Sistema de modales AJAX** (ver §H) |
| `tables.js` | Tablas/listados dinámicos |
| `loader.js` | Spinner (`loader.add($el)` / `loader.remove($el)`) |
| `actions.js` | Acciones masivas sobre selección (`actions.execute()`) |
| `checklists.js`, `multi-checkbox.js`, `jquery.element-disabler.js` | Inputs/selección avanzada |
| `dragger.js`, `outer-html.js`, `jquery.databox.js` | Utilidades DOM |
| `chat.js` | Chat (va en `app.js`) |

### H. Sistema de Modales (AJAX) — `plugins/modals.js`

No hay modales pre-renderizados: se **cargan por AJAX** y se inyectan en el DOM. Flujo:

1. **Abrir:** un elemento con `data-modal="<id>"` (+ opcional `data-url`, `data-method`). El click
   crea `<div class="modal fade" id="<id>"><div class="contents"></div></div>` si no existe, hace GET
   a `data-url`, mete el HTML en `.contents`, muestra el modal y ejecuta **`initComponents(modal)`**
   (inicializa selectpicker, datepickers, colorpickers, etc.).
2. **Enviar:** un botón `data-submit="modal"` (o `.update`/`.update_hidden`/`.update_with_files`)
   serializa el `<form>` del modal y hace POST (convierte PUT/DELETE a POST + `_method`).
   Soporta `FormData` cuando hay `input[type=file]`.
3. **Respuesta JSON:** `res.status` → `1` éxito (cierra modal), `0`/otro mantiene abierto; `res.trigger`
   dispara un evento global; errores de validación se pintan inline por nombre de campo
   (`.help-block.error`, `.has-error`), warnings en panel `.alert-warning`.
4. **Callbacks por convención** (definir en la vista, ver `Objects/index.blade.php`):
   `function <modal_id>_modal_callback(res){}` y `<modal_id>_modal_error_callback(res){}`.
5. **Eventos custom** por modal: `modal-<id>-content-success`, `-post-success`, `-post-error`,
   `-post-warning`.

> Para reestilizar modales: el marcado vive en `Layouts/modal.blade.php` (+ cada `create`/`edit`),
> los estilos en `layout/_modals.scss`. **No** renombrar `.modal`, `.modal-dialog`, `.modal-content`,
> `.contents`, `.modal-body`, `.modal-footer`, `data-submit="modal"`, `data-dismiss`, ni los IDs de
> modal: el JS depende de ellos. Cambiar clases cosméticas y estructura visual interna, sí.

### I. Mapas (Leaflet) — `model/MapTiles.js` + `controller/app.js`

- **Motor:** Leaflet 1.0.3 (`lib/leaflet/`). Plugins activos: `markerCluster`, `draw`, `editable`,
  `ruler`, `polylineDecorator` (flechas de ruta), `rotatedMarker`/`marker.rotate` (rumbo del vehículo),
  `Marker.SlideTo` (animación de movimiento), `GoogleMutant`, `Yandex`, `bing`, `circle.topolygon`.
- **Capas base** en `MapTiles.js`: catálogo numerado (`1` Google Normal, `2` OSM, `3` Google Hybrid,
  `4` Satellite, `5` Terrain, `6` Yandex, … + Here/Mapbox/TomTom/Azure/MapTiler). Cada entrada tiene
  `name`, `maxZoom`, `traffic` y un `tile()` que construye la `TileLayer`. Las disponibles por usuario
  vienen de `availableMaps`/`map_id` (en `getJsConfig`), y las **API keys** de `settings.keys`.
- **Controles** (marcado en `loged.blade.php` `#map-controls`, comportamiento en `app.js`):
  zoom in/out, fullscreen (`app.mapFull()`), selector de capa, y toggles `app.changeSetting(...)`
  para cluster, fit-bounds, objetos, geocercas, rutas, pois, nombres, tail, tráfico, y el grupo
  `#history-control-layers` para la reproducción.
- **Estilos del mapa:** `layout/_map.scss` (contenedor, controles, popups del marcador) +
  `libraries/leaflet/*` (overrides de la librería).

### J. Config y datos inyectados a JS

- **`getJsConfig()`** (`Tobuli/Helpers/Helper.php`) se serializa a JSON y se mezcla en `app` desde
  `Layouts/partials/app.blade.php`. Incluye: `user_id`, `version`, `lang`, `offlineTimeout`,
  frecuencias de polling, `channels` (socket), **`settings`** (units speed/distance/altitude/capacity,
  formatos de fecha/hora, `mapCenter`/`mapZoom`/`map_id`/`availableMaps`, toggles de capa, `keys` de
  cada proveedor de mapa, flags de plugins) y **`urls`** (decenas de endpoints AJAX por módulo:
  devices, geofences, routes, pois, alerts, history, dashboard, chat, lock_status, checklists…).
- **i18n a JS:** `Layouts/partials/trans.blade.php` (+ variables sueltas en `loged.blade.php` como
  `titleReports`, `titleAlerts`…). En Blade siempre `trans('...')`.
- **`initComponents(container)`** (`controller/app.js`): re-inicializa plugins de UI sobre un
  contenedor recién insertado (datepicker, selectpicker, lazy-load de `img/iframe[data-src]`, etc.).
  Llamar SIEMPRE tras inyectar HTML por AJAX.

---

## Patrones Críticos (backend)

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
- **Vistas:** los formularios y plantillas HTML de los reportes viven en `Tobuli/Views/Frontend/Reports/` (111 blades).

---

## Sistema de Alertas (`Tobuli/Helpers/Alerts`)

- **`Check/`** — una clase `*AlertCheck` por tipo de alerta (`OverspeedAlertCheck`, `GeofenceAlertCheck`, `IdleDurationAlertCheck`, `OfflineDurationAlertCheck`, `SosAlertCheck`, `DriverAlertCheck`…). Base `AlertCheck`/`BaseAlertCheck`; `Checker` orquesta. El método clave es `checkEvents($position, $prevPosition)` → devuelve `Event[]`.
- **`Notification/`** — canales: `Email`, `Sms`, `Push`, `Popup`, `Sound`, `Color`, `Webhook`, `Command`, `Sharing(Email|Sms)`, `Silent`. Coordinados por `NotificationProvider`.
- **`Filter/`** — filtros previos (`EnabledFilter`).
- **`GeofenceAlertCheck`** cubre `geofence_in/out/inout/overspeed`. El **overspeed de geocerca** usa `Geofence::speedLimitFor($device->vehicleType, $device->vehicleState)`, suma `getSpeedTolerance()`, exige `getMinOverspeedDuration()` (tracking en `Cache` por alerta+device+geofence) y descarta posiciones con `sat <= 10`. Tipos de evento: `Event::TYPE_ZONE_IN/OUT/OVERSPEED`.

---

## Tipos/Estados de Vehículo y Límites de Velocidad por Geocerca

> Permite límites de velocidad por geocerca diferenciados por **tipo** y **estado** de vehículo
> (ej. "Cisterna / cargado" vs "Cisterna / vacío"). Desde oct-2026 la clasificación de cada
> vehículo es **por usuario** (rama `clasificacion-por-usuario`, migración `2026_10_20_000001`):
> un vehículo compartido entre cuentas puede llevar un tipo/estado distinto para cada una.

**Entidades:**

| Entidad                       | Tabla                             | Notas                                                              |
| ----------------------------- | --------------------------------- | ------------------------------------------------------------------ |
| `VehicleType`                 | `vehicle_types`                   | Pertenece a un `User`; `hasMany` estados.                          |
| `VehicleState`                | `vehicle_states`                  | Pertenece a un `VehicleType`.                                      |
| `GeofenceVehicleSpeedLimit`   | `geofence_vehicle_speed_limits`   | `unique(geofence_id, vehicle_type_id, vehicle_state_id)`; `state` nullable. |
| `DeviceVehicleClassification` | `device_vehicle_classifications`  | `(device_id, user_id, vehicle_type_id, vehicle_state_id)`; `unique(device_id, user_id)`; tipo y estado son del catálogo de `user_id`. FK cascade en device/user/tipo, `set null` en estado. |

### Clasificación por usuario (modelo actual)

- **Lectura en `Device`:** `classifications()` (hasMany), `classificationFor(int $userId)`,
  `vehicleTypeFor($userId)`, `vehicleStateFor($userId)`. El resultado se memoriza por usuario en la
  instancia (incluido el `null`) porque alertas e historial recorren geocercas de varios dueños
  sobre el mismo device; `forgetClassificationCache()` lo limpia tras escribir.
- **Eager load (evitar N+1):** scope `Device::withClassificationFor($userId)`. Carga una **relación
  dinámica por usuario** `classificationForUser{N}` (registrada con `resolveRelationUsing`, hasOne
  filtrada por `user_id` con `vehicleType`/`vehicleState` anidados; nombre vía
  `Device::classificationRelationFor($userId)`). Una vez cargada es autoritativa: `null` = sin
  clasificación, 0 consultas extra. La relación genérica `classifications` solo sirve de caché para
  aciertos; si no contiene al usuario, `classificationFor()` consulta la BD.
  ⚠️ **Serialización:** al estar cargada, `classificationForUser{N}` aparece como clave en
  `$device->toArray()` / `toJson()`. No volcar el device entero a JSON tras `withClassificationFor`;
  exponer los campos desde un transformer (`DeviceMapTransformer` usa `requireUserLoads()` de
  `BaseTransformer` para cargarla con el usuario actuante).
- **Escritura: SIEMPRE por `Tobuli\Services\DeviceClassificationService`** (nunca escribir la tabla
  a mano): `validate($user, $typeId, $stateId)` (resuelve sin escribir: tipo del usuario, estado del
  tipo; lanza `ValidationException` con claves `vehicle_type_id`/`vehicle_state_id`),
  `set($user, $device, $typeId, $stateId)` (`updateOrCreate`; tipo vacío = `clear`),
  `setState($user, $device, $stateId)` (exige clasificación previa; sin tipo y estado vacío es no-op),
  `clear($user, $device)`. Escritores: `DeviceModalHelper` (formularios legacy, v2 y API v1: valida
  **antes** de guardar el device y clasifica **después**, solo si las claves vienen en el request) y
  los dos «Cambiar estado» (`DevicesController::changeVehicleState()` legacy y `Ui\V2\DevicesController`).
- **Regla de resolución del límite: "el dueño de la geocerca resuelve".**
  `Geofence::speedLimitForDevice(Device $device)` = `speedLimitFor($device->vehicleTypeFor($this->user_id),
  $device->vehicleStateFor($this->user_id))`. Toda geocerca tiene dueño, así que alertas, historial,
  reportes y Seguridad no necesitan pasar ningún usuario extra. Geocerca sin `user_id` ⇒ solo el legacy.
- **Regla de visualización: "usuario actual".** Listas, ficha, mapa (`DeviceMapTransformer`, incluido
  el enlace público de Compartir con el dueño del enlace como usuario actuante), sidebar legacy
  (`Objects/item.blade.php`), Seguridad (filtro «Tipo», tablas, Excel) muestran
  `$device->classificationFor($user->id)`. Los selects de los formularios solo ofrecen los tipos del
  usuario que edita.
- **Columnas deprecadas:** `devices.vehicle_type_id` / `vehicle_state_id` y los accessors
  `Device::vehicleType()` / `vehicleState()` (marcados `@deprecated`) **ya no se escriben ni se leen**.
  La migración copió su valor a la tabla nueva para el **dueño del tipo** (estado de otro tipo ⇒ `null`).
  Se eliminarán en una migración posterior cuando pruebas confirme; no usarlos en código nuevo.
- **JS espejo:** `live-zones.js` y `tp-map-layers.js` replican `speedLimitFor` con los
  `vehicle_type_id`/`vehicle_state_id` que manda el transformer (ya son los de la clasificación del
  usuario actuante), por eso no cambiaron.

### Catálogo y límites por geocerca

- **CRUD de tipos**: `VehicleTypesController` (`Route::resource('vehicle_types')` + `vehicle_types/table`). Los estados se editan anidados en el form del tipo (`add_state[]`, `edit_state[id]`); validación con `VehicleTypeFormValidator`. Vistas en `Tobuli/Views/Frontend/VehicleTypes/`. Borrar un tipo borra (cascade) solo las clasificaciones de esa cuenta.
- **Asignación rápida de estado a un objeto**: `DevicesController::vehicleStateForm()` + `changeVehicleState()` → `DeviceModalHelper::changeVehicleState()` → `DeviceClassificationService::setState()`. El formulario ofrece los estados del tipo **de la clasificación del usuario**.
- **En la geocerca**: partial `Geofences/partials/vehicle_speed_limits.blade.php`. El form envía `vehicle_speed_limits[<type_id>][<state_id>] = velocidad` (clave de estado `0` = límite por defecto del tipo, `vehicle_state_id` null). `GeofenceService::syncVehicleSpeedLimits()` borra y recrea los límites; `normalize()` convierte unidades.
- **`Geofence::speedLimitFor($vehicleType, $vehicleState)`** (lo llama `speedLimitForDevice`) con fallback en 3 niveles:
  1. límite exacto (tipo + estado),
  2. límite por defecto del tipo (estado `null`),
  3. columna legacy `geofences.speed_limit`.
- **Consumidores** de `speedLimitForDevice()`: `GeofenceAlertCheck` (alertas), reportes overspeed-en-geocerca (904-906, RAG), y acciones de History `AppendSpeedLimitGeofence`, `AppendSpeedLimitGeofenceByPriority` (clave de caché por dueño+geocerca, no por ids del device), `AppendSpeedLimitGeofenceMulti`. En reportes/pipeline pre-cargar la clasificación del usuario del reporte antes del bucle (una consulta por device, no una por posición).
- **Prioridad con geocercas solapadas**: se elige la de **menor área** (`Geofence::approxAreaSqMeters()`), vía `Geofence::pickBySpeedLimitPriority()` o la acción `…ByPriority`.
- **Export/import GEXP de geocercas** (sección 6/7): el `.gexp` lleva `speed_limit`, `additional` y los límites por tipo/estado **por nombre**; el importador los resuelve contra el catálogo del usuario o crea los tipos (19-oct-2026). La clasificación por usuario de los devices **no** viaja en el GEXP de geocercas.

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

**Verificación en vistas Blade (controla qué UI se muestra):**

```blade
@if (Auth::user()->perm('reports', 'view'))  ... @endif
@if (isAdmin()) ... @endif
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

> Las URLs que consume el JS NO se hardcodean: se exponen vía `getJsConfig()['urls']` y se leen como
> `app.urls.<clave>` en los controllers JS.

---

## Gotchas Comunes

- **Vistas en `Tobuli/Views/`**, no en `resources/views/`. Referenciar como `Frontend.Modulo.vista`.
- **Recompilar assets:** editar `resources/assets/{scss,js}` no surte efecto hasta correr Gulp; el
  navegador sirve `public/assets/`. Para JS, el cambio debe ir al bundle correcto (`core`/`app`/`report`).
- **No renombrar hooks de JS en el marcado** al reestilizar: clases/atributos como `.modal`,
  `.contents`, `data-modal`, `data-submit="modal"`, `data-toggle="tab"`, IDs (`#map`, `#sidebar`,
  `#bottombar`), y `onclick="app...."` son contratos con el JS.
- **Contexto API vs Web:** Muchas clases revisan `$this->api` o `Config::get('tobuli.api')` para alterar comportamiento. Setear con `Config::set('tobuli.api', 1)` o `ModalHelper::setApi(true)`.
- **Transacciones en importadores:** Siempre usar `beginTransaction()` / `commitTransaction()` / `rollbackTransaction()` (helpers globales, no `DB::beginTransaction()`).
- **Formateo de unidades:** `speed_limit` se almacena en unidad base; convertir con `Formatter::speed()->reverse()` al guardar y `Formatter::speed()->format()` al mostrar.
- **Geofence `polygon` oculto:** El campo `polygon` está en `$hidden` en la entidad, por eso los scopes/queries deben seleccionarlo explícitamente si se necesita.
- **Cache de grupos en importación:** `GeofenceImporter` usa `Cache::store('array')->rememberForever()` para evitar duplicar grupos dentro de un mismo proceso de importación.
- **Migraciones:** estilo Laravel 8 con **clase anónima** (`return new class extends Migration { … };`). Seguir ese patrón en migraciones nuevas.
- **Posiciones por dispositivo:** no consultar una tabla `positions` global; usar `$device->positions()` (tabla dinámica por device).
- **`speedLimitFor()` sobre colecciones cargadas:** si `vehicleSpeedLimits` ya está eager-loaded, `Geofence::speedLimitFor()` filtra en memoria; si no, consulta a BD. En reportes/pipeline, hacer `->with('vehicleSpeedLimits')` para evitar N+1.
- **Clasificación de vehículo por usuario:** nunca leer `devices.vehicle_type_id`/`vehicle_state_id` ni `$device->vehicleType`; usar `classificationFor($userId)` (mostrar = usuario actual, resolver límite = `Geofence::speedLimitForDevice()`, es decir, el dueño de la geocerca). En listados, `withClassificationFor($userId)`; escribir solo con `DeviceClassificationService`.

---

## Directorios Clave

```
# Backend
app/Http/Controllers/Frontend/   — Controllers de usuario final
app/Http/Controllers/Admin/      — Panel de administración
app/Http/Controllers/Api/V1/     — API REST v1
Tobuli/Entities/                 — Modelos Eloquent (lógica de dominio)
Tobuli/Services/                 — Servicios de negocio
Tobuli/Repositories/             — Acceso a datos (patrón repositorio)
Tobuli/Exporters/                — Lógica de exportación (GEXP, Excel, etc.)
Tobuli/Importers/                — Lógica de importación (KML, GeoJSON, GEXP)
Tobuli/Validation/               — Validadores de formulario
Tobuli/History/                  — Pipeline de procesamiento de posiciones (Actions)
Tobuli/Reports/                  — Reportes (DeviceReport, ~130 tipos)
Tobuli/Helpers/Alerts/           — Alertas: Check (tipos), Notification (canales), Filter
Tobuli/Sensors/                  — Tipos de sensores de dispositivo
ModalHelpers/                    — Procesamiento de requests de formularios
Facades/                         — Custom facades (Repos, ModalHelpers, Validators)
socket/                          — Servidor Node.js de WebSocket

# Frontend / UI
Tobuli/Views/Frontend/           — Vistas Blade de usuario final (~70 módulos)
Tobuli/Views/Admin/              — Vistas Blade del panel admin
Tobuli/Views/Frontend/Layouts/   — Layout maestro (loged) + layouts de modal + partials
resources/assets/js/controller/  — Controladores JS por módulo del mapa
resources/assets/js/model/       — Modelos JS por entidad (+ MapTiles)
resources/assets/js/plugins/     — Widgets/utilidades JS (modals, tables, loader…)
resources/assets/js/lib/         — Librerías de terceros (leaflet/*, flot/*, jquery, bootstrap…)
resources/assets/scss/           — Fuentes de estilos (variables, colouring, templates, layout, libraries)
public/assets/                   — SALIDA compilada (css/, js/ bundles, fonts, icons, images)
gulpfile.js                      — Pipeline de build (sass + 3 bundles JS)
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
| Google / Yandex / Bing / Here / Mapbox / TomTom / Azure / MapTiler | Capas de mapa (tiles) | keys en `main_settings.*` → `getJsConfig()['settings']['keys']` |

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
