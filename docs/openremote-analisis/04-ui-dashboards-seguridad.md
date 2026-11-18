# 04 — OpenRemote: capa de UI, dashboards, seguridad y servicios transversales

Análisis del clon en `/home/claude/openremote/core` (versión `1.28.0-snapshot`, Keycloak 26.6, Lit 3, MapLibre 6.8, ECharts 6.1, GridStack 14) orientado a decidir qué portar a **Talentus Pro Tracking** (Laravel 8 / MySQL / Redis / jQuery 3 + Bootstrap 3 / Leaflet 1.0.3 / Flot / Socket.io).

Convención: rutas relativas a `/home/claude/openremote/core`. Cada sección termina con una **propuesta de porte** al stack actual de Talentus o un camino incremental.

---

## 0. Resumen ejecutivo (qué vale la pena y en qué orden)

| Prioridad | Hallazgo | Esfuerzo | Valor para Talentus |
|---|---|---|---|
| (a) | **Dashboard builder** tipo Insights: JSON `Dashboard → template → widgets[] → gridItem + widgetConfig`, GridStack, presets por ancho de pantalla, acceso `PUBLIC/SHARED/PRIVATE`, refresh interval | Medio | Muy alto: reemplaza el "dashboard con bloques básicos" y habilita dashboards públicos para clientes |
| (b) | **Widgets**: linechart (ECharts, LTTB server-side), barchart (time-bucket con fórmulas), gauge (gaugeJS con umbrales), KPI (valor + delta por periodo), tabla (asset type/ids + columnas), mapa, imagen con marcadores, attribute input | Medio | Alto: reemplaza Flot; cada widget trae su modelo de config reutilizable |
| (c) | **Scheduler RFC 5545**: `CalendarEvent {start,end,recurrence:"RRULE:..."}` + `or-scheduler` (rrule.js) + `getNextOrActiveFromTo()` con ical4j | Bajo-medio | Alto: horarios de alertas, geocercas por turno, validez de reglas, mantenimiento |
| (d) | **Seguridad**: realm = tenant (Keycloak), roles cliente granulares + compuestos (`ClientRole.READ/WRITE`), rol de realm `restricted_user` + `USER_ASSET_LINK`, meta `ACCESS_RESTRICTED_READ/WRITE` por atributo, service accounts con client-credentials, atributos de usuario | Alto (Keycloak) / Medio (ideas) | Alto; portar el **modelo**, no Keycloak |
| (e) | **OpenAPI**: anotaciones Swagger en interfaces JAX-RS + `openapi-base.yaml` + Swagger UI en `/swagger`; cliente TS generado (`typescript-generator`) | Bajo | Alto: `l5-swagger` en Laravel reproduce esto en días |
| (f) | **MCP/IA**: **no existe en core**. Lo más cercano: `ExternalServiceRegistryService` (registro de microservicios con lease/heartbeat embebidos como iframe en "Services") | — | Medio: patrón de "servicios externos" y, aparte, construir un MCP propio sobre la API REST de Talentus |
| (g) | **Observabilidad**: Micrometer + `PrometheusMeterRegistry` en puerto `OR_METRICS_PORT=8405`, métricas `or.attributes`, `or.rules`, `or.provisioning`, `or.camel.route.queue.size`; OTel javaagent opcional traces-only; `/api/master/health` y `/info` por `HealthStatusProvider` (ServiceLoader) | Bajo | Medio-alto |
| (h) | **White-label por realm**: `manager_config.json` → `realms.{realm}.{appTitle, styles(CSS vars), logo, logoMobile, favicon, language, headers[], notifications}`, editable desde UI "Appearance", subida de imágenes validada (SVG/raster) | Bajo | Alto: mejora directa del white-label actual |
| (i) | **Consola móvil + geofences**: `ConsoleRegistration.providers{push, geofence, storage}`, `GeofenceDefinition{lat,lng,radius,httpMethod,url}`, push silencioso `GEOFENCE_REFRESH` (FCM), `GET rules/geofences/{assetId}` | Medio | Alto para la app móvil de Talentus (geocercas evaluadas en el teléfono) |
| — | Export CSV (ZIP vía `COPY TO STDOUT`, formatos CSV/CROSSTAB/CROSSTAB_MINUTE, límite configurable) | Bajo | Medio: Talentus ya exporta; interesante el crosstab y el streaming |
| — | OTA/hawkBit: **no existe** en core | — | — |
| — | i18n: i18next + `shared/locales/{lng}/{or,app}.json`, 13 idiomas, idioma por realm, locale por usuario (`User.LOCALE_ATTRIBUTE`) | Bajo | Bajo-medio (Talentus ya tiene multi-idioma) |

---

## 1. Arquitectura de la UI (contexto)

- **Monorepo yarn workspaces** `ui/component/*` (web components Lit 3) + `ui/app/*` (apps: `manager`, `insights`, `swagger`, `storybook`).
- Librerías clave (de los `package.json`):
  - `or-dashboard-builder`: `gridstack ~14.0.0`, `lit`, `moment`, `@lit/task`.
  - `or-chart` y `or-attribute-history`, `or-attribute-barchart`: `echarts ~6.1.0`.
  - `or-attribute-card` (KPI): aún `chart.js ^3.6` + `chartjs-adapter-moment` (sparkline).
  - `or-gauge`: `gaugeJS 1.3.9`.
  - `or-map`: `maplibre-gl ~6.8.0`.
  - `or-scheduler`: `rrule ^2.6.4`.
  - `or-json-forms`: `@jsonforms/core ^3.5`, `ajv ^8`.
  - `or-log-viewer`: `axios`, `http-link-header` (paginación por cabecera `Link`).
  - `or-rive-renderer`: `@rive-app/webgl2` (animaciones .riv, decorativo).
  - `or-asset-tree`: `@mdi/js`, `qs`.
  - Kit de componentes: migrando de `@material/*` 9 (`or-mwc-components`) a `@vaadin/*` 25.3 (`or-vaadin-components`).
  - `or-app`: `navigo` (router), `@reduxjs/toolkit` (store), `pwa-helpers`.
  - `core`: `keycloak-js ^25`, `i18next ^26` + `i18next-http-backend`, `axios`.
  - `rest`: cliente TS generado desde las interfaces JAX-RS (`ui/component/rest/build.gradle` → `cz.habarta.typescript-generator`, salida `src/restclient.ts`, `manager.rest.api.<Resource>.<method>()`).
- **App Manager** (`ui/app/manager/src/pages/`): `page-map`, `page-assets`, `page-rules`, `page-services`, `page-insights`, `page-alarms`, `page-users`, `page-roles`, `page-realms`, `page-provisioning`, `page-gateway`, `page-gateway-tunnel`, `page-logs`, `page-export`, `page-configuration` (Appearance), `page-notifications`, `page-account`. Los ítems de menú se declaran en `ui/app/manager/src/headers.ts` con `roles: [ClientRole.READ_ADMIN, ...]` para ocultarlos por rol (p. ej. `headerItemUsers`), y `manager_config.json → realms.X.headers[]` permite quitar páginas por realm.
- La UI resuelve el **realm** por cabecera HTTP `Realm` (`Constants.REALM_PARAM_NAME = "Realm"`, leído en `WebResource.getRequestRealmName()`), inyectada por `RealmInjectorFilter`.

**Lectura para Talentus:** no conviene adoptar Lit/web components dentro de jQuery+Bootstrap 3 salvo encapsulados; lo que sí se porta es el **modelo de datos y la lógica** de cada módulo. Camino incremental sugerido: islas Vue 3 (o web components puros) montadas en vistas Blade, compiladas con Vite (coexiste con Gulp).

---

## 2. (a) Dashboard builder "Insights"

### 2.1 Qué hace
- Constructor drag & drop con panel izquierdo (árbol de dashboards `or-dashboard-tree`, navegador de widgets `or-dashboard-browser`), lienzo GridStack (`or-dashboard-preview`), panel derecho de ajustes (`or-dashboard-widgetsettings`, `or-dashboard-boardsettings`), atajos de teclado (`or-dashboard-keyhandler`), controles de auto-refresh (`controls/dashboard-refresh-controls.ts`).
- Archivos: `ui/component/or-dashboard-builder/src/index.ts` (registro de widgets y shell), `or-dashboard-preview.ts` (grid), `or-dashboard-engine.ts` (override de `GridStackEngine.moveNode` + parche a `_sortDom` para que GridStack no reordene el DOM), `service/dashboard-service.ts`, `service/widget-service.ts`.
- App pública **Insights** (`ui/app/insights`): no requiere login, rutas `view`, `view/:id`, `view/:id/:showMenu`; carga `DashboardResource.getAllRealmDashboards(realm)` y muestra solo dashboards `PUBLIC`. Compartir = copiar URL (`shareUrl()` en `index.ts:576`).
- En el Manager: `page-insights.ts` rutas `insights`, `insights/:editMode`, `insights/:editMode/:id`.

### 2.2 Modelo de datos (Java → JSON)
`model/src/main/java/org/openremote/model/dashboard/*.java`:

```java
@Entity @Table(name="DASHBOARD")
class Dashboard { String id(char22); Instant createdOn; String realm; Long version(@Version);
  String ownerId; DashboardAccess access; String displayName;
  @JdbcTypeCode(SqlTypes.JSON) DashboardTemplate template; }
enum DashboardAccess { PUBLIC, SHARED, PRIVATE }              // ordinal 0,1,2 en BD
class DashboardTemplate { String id; int columns; int maxScreenWidth;
  DashboardRefreshInterval refreshInterval; DashboardScreenPreset[] screenPresets; DashboardWidget[] widgets; }
enum DashboardRefreshInterval { OFF, ONE_MIN, FIVE_MIN, QUARTER, ONE_HOUR }
class DashboardScreenPreset { String id; String displayName; int breakpoint; DashboardScalingPreset scalingPreset; String redirectDashboardId; }
enum DashboardScalingPreset { WRAP_TO_SINGLE_COLUMN, KEEP_LAYOUT, REDIRECT, BLOCK_DEVICE }
class DashboardWidget { String id; String displayName; DashboardGridItem gridItem; String widgetTypeId; Object widgetConfig; }
class DashboardGridItem { String id; int x,y,w,h; int minH,minW,minPixelH,minPixelW; boolean noResize,noMove,locked; }
```

Ejemplo completo de un dashboard con tres widgets (compuesto a partir de `OpenApiExamples.DASHBOARD_UPDATE` y de los `getDefaultConfig()` de cada widget):

```json
{
  "id": "operations-overview", "version": 2, "realm": "talentus",
  "ownerId": "2f1c17e5-...", "access": "SHARED", "displayName": "Flota Norte",
  "template": {
    "columns": 12, "maxScreenWidth": 1920, "refreshInterval": "FIVE_MIN",
    "screenPresets": [
      { "id": "desktop", "displayName": "Desktop", "breakpoint": 1024, "scalingPreset": "KEEP_LAYOUT" },
      { "id": "mobile",  "displayName": "Mobile",  "breakpoint": 640,  "scalingPreset": "WRAP_TO_SINGLE_COLUMN" }
    ],
    "widgets": [
      { "id": "w1", "displayName": "Velocidad", "widgetTypeId": "linechart",
        "gridItem": { "id": "w1", "x": 0, "y": 0, "w": 6, "h": 3, "minW": 2, "minH": 2, "noResize": false, "noMove": false, "locked": false },
        "widgetConfig": {
          "attributeRefs": [ { "id": "7A6p4AnLTkKxJUCQAAABAA", "name": "speed" } ],
          "attributeColors": [ [ { "id": "7A6p...", "name": "speed" }, "4d9d2a" ] ],
          "datapointQuery": { "type": "lttb", "fromTimestamp": 1730000000000, "toTimestamp": 1730086400000, "amountOfPoints": 300 },
          "attributeConfig": { "rightAxisAttributes": [], "smoothAttributes": [], "steppedAttributes": [], "areaAttributes": [], "faintAttributes": [], "extendedAttributes": [] },
          "chartOptions": { "options": { "scales": { "y": { "min": null, "max": null }, "y1": { "min": null, "max": null } } } },
          "showTimestampControls": false, "defaultTimePresetKey": "last24hours", "showLegend": true, "showZoomBar": false, "stacked": false } },
      { "id": "w2", "displayName": "Combustible", "widgetTypeId": "gauge",
        "gridItem": { "id": "w2", "x": 6, "y": 0, "w": 3, "h": 3 },
        "widgetConfig": { "attributeRefs": [ { "id": "7A6p...", "name": "fuelLevel" } ],
          "thresholds": [ [0, "#4caf50"], [75, "#ff9800"], [90, "#ef5350"] ], "decimals": 0, "min": 0, "max": 100, "valueType": "number" } },
      { "id": "w3", "displayName": "Km hoy", "widgetTypeId": "kpi",
        "gridItem": { "id": "w3", "x": 9, "y": 0, "w": 3, "h": 3 },
        "widgetConfig": { "attributeRefs": [ { "id": "7A6p...", "name": "odometer" } ],
          "period": "day", "decimals": 0, "deltaFormat": "absolute", "showTimestampControls": false } }
    ]
  }
}
```

Observaciones: `widgetConfig` es `Object` (JSON libre por tipo), lo que permite añadir widgets sin migrar BD; `WidgetService.correctToConfigSpec()` fusiona la config guardada con `getDefaultConfig()` para tolerar versiones viejas. `version` es optimistic locking (Hibernate `@Version`).

### 2.3 Registro de widgets (plugin pattern)
`index.ts:248-260`:
```ts
export const widgetTypes: Map<string, WidgetManifest> = new Map();
export function registerWidgetTypes() {
  widgetTypes.set("attributeinput", AttributeInputWidget.getManifest());
  widgetTypes.set("barchart", BarChartWidget.getManifest());
  widgetTypes.set("gateway", GatewayWidget.getManifest());
  widgetTypes.set("gauge", GaugeWidget.getManifest());
  widgetTypes.set("image", ImageWidget.getManifest());
  widgetTypes.set("kpi", KpiWidget.getManifest());
  widgetTypes.set("linechart", ChartWidget.getManifest());
  widgetTypes.set("map", MapWidget.getManifest());
  widgetTypes.set("table", TableWidget.getManifest());
}
```
`util/or-widget.ts`:
```ts
export interface WidgetManifest {
  displayName: string; displayIcon: string;
  minColumnWidth?: number; minColumnHeight?: number; minPixelWidth?: number; minPixelHeight?: number;
  getContentHtml(config): OrWidget;      // vista
  getSettingsHtml(config): WidgetSettings; // panel de ajustes
  getDefaultConfig(): WidgetConfig;
}
```
Cada widget implementa `refreshContent(force)`; el dashboard dispara refresco por `refreshInterval` (`intervalToMillis`). `WidgetService.placeNew()` crea el `DashboardWidget` con `w:2,h:2` y un UUID.

### 2.4 Grid responsive
`or-dashboard-preview.ts:446-470`: `GridStack.init({ column: template.columns, cellHeight: ..., columnOpts: { breakpoints:[{w: preset.breakpoint, c:1}] } si WRAP_TO_SINGLE_COLUMN, staticGrid: !editMode, mode:"float", margin:5, resizable:{handles:"all"}, acceptWidgets: editMode })`. `getActivePreset(gridWidth, presets)` (`index.ts:233`) elige el preset con mayor `breakpoint >= ancho`; `REDIRECT` redirige a otro dashboard (`redirectDashboardId`) y `BLOCK_DEVICE` muestra aviso. `maxScreenWidth` limita el ancho del lienzo.

### 2.5 Persistencia y control de acceso
`manager/src/main/java/org/openremote/manager/dashboard/DashboardStorageService.java` construye SQL nativo (PostgreSQL + JSONB):
- Filtro por acceso: `(access = 2 AND owner_id = :userId) OR access IN (:access)`.
- **Dashboards para usuarios restringidos**: solo visibles si el usuario está enlazado a los assets de sus widgets. Usa `jsonb_array_elements(template->'widgets') ... widget->'widgetConfig'->'attributeRefs' ... a->>'id' IN (:assetIds)` con dos modos `AT_LEAST_ONE` / `ALL` (`appendSqlAssetConditionsFilter`).
- `DashboardResourceImpl.sanitizeDashboardQuery()`: anónimo → solo `PUBLIC`; autenticado sin `read:insights` → pierde `SHARED/PRIVATE`; `restricted_user` → `AssetAccess.RESTRICTED`; cross-realm prohibido salvo superusuario. `create` fuerza `ownerId=usuario` y `access=SHARED`. `get()` devuelve 404 (no 403) para no revelar existencia.
- REST (`DashboardResource`): `GET dashboard/all/{realm}`, `GET dashboard/{realm}/{id}`, `POST dashboard/query`, `POST dashboard` (`write:insights`), `PUT dashboard`, `DELETE dashboard/{realm}/{id}`.

### 2.6 Propuesta de porte a Talentus (Laravel)
1. **BD MySQL**: tabla `dashboards(id char(22)/uuid, account_id, owner_user_id, access enum('public','shared','private'), display_name, template JSON, version int, created_at, updated_at)`. MySQL 5.7+/8 soporta `JSON_TABLE` (8.0) para replicar el filtro JSONB; si no, materializar `dashboard_devices(dashboard_id, device_id)` al guardar (extraer `attributeRefs[].id`) — más simple y rápido.
2. **Eloquent**: `Dashboard` con cast `template => array`, scopes `visibleTo(User)` que reproduzcan `sanitizeDashboardQuery` con los roles actuales de Talentus (`admin/reseller/user/operator/supervisor/demo`) y las sub-cuentas (`account_id` = realm).
3. **API**: `GET /api/dashboards`, `GET /api/dashboards/{id}`, `POST`, `PUT` (con `If-Match: version` → 409 si cambió), `DELETE`; endpoint público sin auth `GET /public/dashboards/{token|id}` para `access=public` (token aleatorio en vez del id si se quiere no enumerable).
4. **Frontend**: GridStack **v14 funciona sin framework** y convive con jQuery/Bootstrap 3 (es vanilla JS; incluir `gridstack-all.js` + css). El mapa `widgetTypeId → {render(el, config), settings(el, config), defaultConfig()}` es un objeto JS plano; cada widget renderiza en su `<div class="grid-stack-item-content">`. Copiar la lógica de `getActivePreset` + `columnOpts.breakpoints` para móvil.
5. **Refresco**: `setInterval` según `refreshInterval` + los sockets ya existentes (Socket.io) para empujar valores en vivo a KPI/gauge/tabla.
6. **Compartir público**: página Blade liviana (sin menú) que monta el mismo bundle en modo `staticGrid:true`, equivalente a la app `insights`.

---

## 3. (b) Widgets y gráficas

### 3.1 Line chart (`or-chart`, ECharts)
- Props relevantes (`or-chart/src/index.ts:409-520`): `assets`, `assetAttributes`, `attributeColors: [AttributeRef,string][]`, `attributeConfig: ChartAttributeConfig {rightAxisAttributes, smoothAttributes, steppedAttributes, areaAttributes, faintAttributes, extendedAttributes}`, `datapointQuery`, `chartOptions` (ECharts override), `timePrefixOptions` ("this"/"last"), `timeWindowOptions: Map<key,[unit,n]>` (p. ej. `['6hours',['hours',6]]`), `stacked`, `showLegend`, `denseLegend`, `showZoomBar`, `dataProvider` (inyección de datos externa).
- Soporta series "predichas" (`AssetPredictedDatapointResource`) dibujadas con línea discontinua y segundo eje Y (`y1`).
- **Downsampling en servidor**: `AssetDatapointLTTBQuery` (`model/.../datapoint/query/AssetDatapointLTTBQuery.java:61`) usa la función SQL `public.lttb(timestamp, value, amountOfPoints)` (extensión TimescaleDB toolkit) → el navegador recibe N puntos independientemente del rango. `AssetDatapointIntervalQuery` usa `time_bucket`/`time_bucket_gapfill` + `locf` con `Formula {MIN, AVG, MAX, DIFFERENCE, COUNT, SUM, MODE, MEDIAN}` y `gapFill`.
- JSON del query: `{"type":"lttb","fromTimestamp":..,"toTimestamp":..,"amountOfPoints":300}` o `{"type":"interval","interval":"5 minute","formula":"AVG","gapFill":true,...}`; si `interval` viene vacío el cliente lo deduce del rango (`_getInterval(diffInHours)`, `index.ts:1703`).

### 3.2 Bar chart (`or-attribute-barchart`)
`BarChartInterval {AUTO, NONE, 1Minute, 5Minutes, 15Minutes, 30Minutes, hour, day, week, month, year}`; config `defaultTimeWindowKey`, `defaultTimePrefixKey`, `defaultInterval`, `stacked`, `decimals`, `attributeSettings`. Ideal para "km por día", "horas de motor por semana", "eventos por hora".

### 3.3 Gauge (`or-gauge`, gaugeJS)
Props `value, min, max, decimals, unit, thresholds: [number,string][]` → `staticZones`. Default `[[0,"#4caf50"],[75,"#ff9800"],[90,"#ef5350"]]`. El panel de umbrales es reutilizable (`panels/thresholds-panel.ts`) y lo comparten gauge y mapa.

### 3.4 KPI (`or-attribute-card` + `kpi-widget`)
Config `{period: year|month|week|day|hour, decimals, deltaFormat: absolute|percentage, showTimestampControls}`. Calcula `delta = last - first` sobre los datapoints del periodo (`getFormattedDelta(getFirstKnownMeasurement, getLastKnownMeasurement)`, `or-attribute-card/src/index.ts:363`) y muestra sparkline con Chart.js 3. Fácil de portar a SQL (`MIN(ts)`/`MAX(ts)` del periodo).

### 3.5 Tabla (`table-widget`)
Config `{assetType, allOfType, assetIds[], attributeNames[], tableSize, tableOptions:[10,25,100]}`; carga con `AssetQuery {types|ids, select:{attributes}}` (`table-widget.ts:104-123`). Equivalente Talentus: "todos los dispositivos del grupo X con columnas velocidad/ignición/última conexión".

### 3.6 Mapa (`map-widget` + `or-map`)
Config `{zoom, center, showLabels, showUnits, showGeoJson, boolColors, textColors, thresholds, min, max, assetType, allOfType, attributeName, assetIds[], attributes[]}`: colorea marcadores por valor de un atributo con umbrales (p. ej. velocidad) o por booleano. `or-map` (MapLibre GL 6.8) trae: clustering con **marcador tipo donut** (`markers/or-cluster-marker.ts`), geocoder propio contra Nominatim (`controls/geocoder.ts:191` → `${geocodeUrl}/search?...`), filtros preconfigurados por realm (`controls/preset-filter.ts`, definidos en `manager_config.json → pages.map.filters[] {label, realms[], query: AssetQuery}`), leyenda, geolocate, coordenadas, capa GeoJSON por realm (`MapRealmConfig.geoJson`).
- Backend `MapService`: sirve estilo vectorial desde `mapdata.mbtiles` (`GET map/tile/{z}/{x}/{y}`), o proxy a `tileserver-gl` (`OR_MAP_TILESERVER_HOST`); subida de `.mbtiles` por realm (`POST map/upload`, `getCustomMapInfo`, `deleteMap`); `mapsettings.json` con `options.{realm}.{center,bounds,zoom,minZoom,maxZoom,boxZoom,geocodeUrl}`.
- Historial de ubicación: no hay "player"; `or-attribute-history` grafica/tabula cualquier atributo (incluye `location` como tabla). Talentus ya supera esto con su player.

### 3.7 Imagen con marcadores (`image-widget`)
`{imagePath, markers: ImageAssetMarker[] (attributeRef + coordenadas % sobre la imagen), showTimestampControls}` → plano de planta/patio con valores superpuestos. Útil para "patio de buses"/almacén.

### 3.8 Attribute input / Gateway
- `attributeinput`: `{attributeRefs, readonly, showHelperText}` → control para escribir un atributo (comando a dispositivo: bloqueo de motor, etc.).
- `gateway`: `{gatewayId, type: HTTPS|TCP, target, targetPort}` abre un **túnel** al dispositivo edge (GatewayTunnelInfo) y lo embebe; sólo relevante si Talentus hace edge gateways.

### 3.9 Propuesta de porte
- **Reemplazar Flot por ECharts 6** (UMD, sin dependencias, funciona con jQuery; `echarts.init($('#el')[0])`). Mantener Flot sólo en los 97 reportes hasta migrarlos. Copiar el diseño de opciones de `or-chart` (eje de tiempo, tooltip `trigger:'axis'`, `dataZoom` opcional, `sampling:'lttb'` nativo en ECharts para el lado cliente).
- **Downsampling en MySQL**: no hay `lttb()`; implementarlo en PHP (algoritmo LTTB ~40 líneas) sobre el resultado, o agregación `GROUP BY FLOOR(UNIX_TIMESTAMP(ts)/:bucket)` con `AVG/MIN/MAX/SUM/COUNT` para el modo `interval` (gap-fill en PHP). Exponer el mismo JSON `datapointQuery {type, fromTimestamp, toTimestamp, amountOfPoints|interval, formula}`.
- Gauge: `gaugeJS` (canvas, sin deps) o `echarts` serie `gauge` (uno menos). Umbrales `[[valor,color],...]` tal cual.
- KPI/tabla: componentes jQuery simples; la config JSON del widget se mantiene idéntica para compatibilidad futura.
- Mapa: en el dashboard, un widget que reutilice el Leaflet actual (1.0.3) con `L.markerClusterGroup`; migrar a MapLibre sólo si se quieren tiles vectoriales propios (`mbtiles` + tileserver-gl, offline, sin coste por request). El donut-cluster y el coloreado por umbral se portan tal cual (SVG en `divIcon`).

---

## 4. (c) Scheduler / recurrencias (RFC 5545)

### 4.1 Modelo
`model/src/main/java/org/openremote/model/calendar/CalendarEvent.java`:
```java
public class CalendarEvent { Date start; Date end; @JsonSerialize(converter=RecurStringConverter) Recur<LocalDateTime> recurrence; // ical4j
  public Pair<Long,Long> getNextOrActiveFromTo(Date when) { ... } }
```
JSON: `{"start":1441177200000,"end":1441206000000,"recurrence":"RRULE:FREQ=DAILY;INTERVAL=2;COUNT=4"}` (`end` nulo = nunca termina). Documentado en el Javadoc por qué **descartaron CRON**: no permite "cada dos jueves".
- Uso: `Ruleset.validity` (RulesetDeployment.java:122, `updateValidity()` calcula el próximo intervalo activo y expira la regla), `CalendarEventPredicate` para filtrar assets por calendario, horarios de simulador.

### 4.2 UI `or-scheduler` (rrule.js)
`or-scheduler/src/index.ts` (840 líneas) + `types.ts` + `util.ts`: props `schedule: CalendarEvent`, `defaultSchedule`, `timezone offset`; tipos de evento `EventTypes {default, once, recurring}`; frecuencias `YEARLY..SECONDLY`; `BY_RRULE_PARTS` (`bymonth, byweekno, byyearday, bymonthday, byweekday, byhour, byminute, bysecond`) con tablas de aplicabilidad `RFC_STRICT_NOT_APPLICABLE` / `INTUITIVE_NOT_APPLICABLE` por frecuencia; fin `RRULE_ENDS {never, until, count}`; muestra resumen traducido ("activo de ... a ...", "para N días").

### 4.3 Propuesta de porte
- Tabla `schedules(id, start_at, end_at, rrule varchar, tz)`; PHP: `rlanvin/php-rrule` (RFC 5545 completo, `getOccurrencesBetween`, `occursAt`) para replicar `getNextOrActiveFromTo`. Cachear en Redis la "próxima ventana activa" por regla.
- Frontend: `rrule.js` (UMD) + formulario Bootstrap 3 generado con la misma matriz de aplicabilidad; texto humano con `rrule.toText()` (en inglés; usar tabla de traducción propia como hace `util.ts`).
- Casos de uso Talentus: alertas sólo en horario laboral, geocercas activas por turno, "ventana de mantenimiento", reportes programados (ya existen cron jobs: migrar a RRULE para flexibilidad), zonas de velocidad por hora.

---

## 5. (d) Seguridad: realms, roles compuestos, usuarios restringidos

### 5.1 Realm = tenant (Keycloak)
- `Realm.java` (vista JPA sobre tablas de Keycloak): `name, displayName, enabled, passwordPolicy[], resetPasswordAllowed, duplicateEmailsAllowed, rememberMe, registrationAllowed, registrationEmailAsUsername, verifyEmail, loginWithEmail, loginTheme, accountTheme, adminTheme, emailTheme, accessTokenLifespan, realmRoles`.
- `ManagerKeycloakIdentityProvider.createRealm()` (línea 1124): crea realm Keycloak, `configureRealm()` (temas `openremote`, token lifespan, SSO idle/max/offline timeouts), crea roles de realm (`Realm.DEFAULT_REALM_ROLES` = `restricted_user`), desactiva `VERIFY_PROFILE`, crea el cliente público `openremote` con los `ClientRole` y sus compuestos. `deleteRealm()` bloquea si quedan assets y limpia gateway connections, provisioning configs y rulesets.
- Todas las entidades llevan columna `realm` y toda consulta REST valida `isRealmActiveAndAccessible(realm)`; cross-realm sólo superusuario (`admin` del realm `master`).
- LDAP/AD: `addLDAPConfiguration` / `addLDAPMapper` (federación por realm).

### 5.2 Roles de cliente granulares + compuestos
`model/.../security/ClientRole.java`: `read:admin, read:logs, read:users, read:map, read:assets, read:rules, read:insights, read:alarms, read:notifications, read:services, read:tunnels, write:user, write:admin, write:logs, write:assets, write:attributes, write:rules, write:insights, write:alarms, write:notifications, write:services, write:tunnels` y compuestos `read` y `write` (listas de los anteriores). `Role.java` `{id, name, description, composite, assigned, compositeRoleIds[]}`; la página `page-roles.ts` permite al admin del realm **crear sus propios roles compuestos** combinando los atómicos (`UserResource.updateRoles(realm, roles)`); los endpoints protegen con `@RolesAllowed({"write:insights"})` etc.

### 5.3 Usuarios restringidos (object-level)
- Rol de realm `restricted_user` (`Constants.RESTRICTED_USER_REALM_ROLE`); `AuthContext.isRestrictedUser()`.
- Tabla `USER_ASSET_LINK(realm, user_id, asset_id)` (`UserAssetLink.java`); UI en `page-users.ts` con `or-asset-tree` con checkboxes para enlazar assets; API `AssetResource.createUserAssetLinks/deleteUserAssetLinks` (restringidos no pueden tocar links).
- Lectura: `AssetResourceImpl:274` → si restringido y `!assetStorageService.isUserAsset(userId, assetId)` → 403; se cargan assets con `Access.PROTECTED` que **filtra atributos** sin meta `ACCESS_RESTRICTED_READ`.
- Escritura: sólo atributos con meta `ACCESS_RESTRICTED_WRITE` (`AssetResourceImpl:385`); los meta de acceso no se pueden auto-asignar; los atributos nuevos creados por un restringido quedan legibles/escribibles por restringidos.
- Metas disponibles (`MetaItemType.java:61-86`): `ACCESS_PUBLIC_READ`, `ACCESS_PUBLIC_WRITE`, `ACCESS_RESTRICTED_READ`, `ACCESS_RESTRICTED_WRITE`, `READ_ONLY` → tres niveles por atributo: público/anónimo, restringido, regular.
- Efecto en cascada: dashboards (sección 2.5), notificaciones, reglas (`RulesResourceImpl`), datapoints, MQTT (`MQTTBrokerService` autoriza topics por links).

### 5.4 Service accounts y atributos de usuario
- `User.SERVICE_ACCOUNT_PREFIX = "service-account-"`; `createUpdateUser` crea un cliente Keycloak con `serviceAccountsEnabled=true` y expone `secret` (`@Formula` que lee `PUBLIC.CLIENT.SECRET`) → integraciones con `client_credentials`. `GET user/{realm}/reset-secret/{userId}` rota el secreto.
- `UserAttribute` (clave/valor): `systemAccount`, `emailNotificationsDisabled`, `pushNotificationsDisabled`, `locale`.
- `UserResource`: `query`, `{realm}/users` PUT/POST, `request-password-reset`, `reset-password`, `userRoles`, `{realm}/{clientId}/roles`, `{realm}/roles` (realm roles).

### 5.5 Propuesta de porte (sin Keycloak)
Talentus ya tiene SSO Azure AD y roles; lo que falta es **granularidad y nivel de objeto**:
1. **Permisos atómicos + roles compuestos**: paquete `spatie/laravel-permission`: permisos `read:devices, write:devices, read:reports, write:dashboards, read:insights, ...` (copiar la lista de `ClientRole`), roles = conjuntos; UI "Roles" donde el reseller/admin crea roles compuestos por cuenta (`team_id` = cuenta/realm). Los `@RolesAllowed` → middleware `permission:`.
2. **Tenant/realm**: la "cuenta" (y sub-cuentas) ya cumple el papel; añadir `realm_settings` (dominio, logo, idioma, políticas de contraseña, lifetime de token API) replicando `Realm` + `ManagerAppRealmConfig`. Multi-tenant por fila (`account_id`) con `Global Scope` Eloquent es suficiente; no hace falta Keycloak.
3. **Usuarios restringidos**: flag `is_restricted` + tabla `user_device_links(user_id, device_id)` (= `USER_ASSET_LINK`); scope `Device::visibleTo($user)`; y por **atributo**: tabla `device_attribute_acl(attribute, public_read, restricted_read, restricted_write)` o un JSON de metas por tipo de dispositivo → permite "el chofer ve velocidad y ubicación pero no consumo ni costo".
4. **Service accounts**: `laravel/passport` `client_credentials` o `sanctum` tokens de "usuario de servicio" con prefijo `service-account-` y rotación de secreto.
5. Sin Keycloak, considerar **Keycloak sólo si** se quiere federar múltiples IdP por cliente (Azure AD por tenant, LDAP). OpenRemote lo usa a través de `keycloak-admin-client` 26 y `keycloak-js` 25 (OIDC PKCE en el browser).

---

## 6. (e) OpenAPI / Swagger y cliente generado

- `ManagerWebService.getOpenApiResource()` (`manager/.../web/ManagerWebService.java:144`): `SwaggerConfiguration().resourcePackages("org.openremote.model.*").openAPI(loadOpenApiBase())` → escanea las **interfaces** JAX-RS anotadas con `@Operation/@Parameter/@ApiResponse/@Schema` (ejemplos centralizados en `model/.../http/OpenApiExamples.java`, respuestas comunes en `OpenApiResponses.Authenticated/BadRequest/...`) y publica `/api/master/openapi.{json,yaml}`. `openapi-base.yaml` define servidores y security schemes (OAuth2 Keycloak).
- `ui/app/swagger` sirve Swagger UI (`swagger-initializer.js`).
- `ui/component/rest/build.gradle` genera `restclient.ts` con `cz.habarta.typescript-generator` (clases Java → interfaces TS en `ui/component/model`, recursos → métodos axios), de modo que **el modelo TS y el cliente nunca se desincronizan del backend**.

**Porte**: `darkaonline/l5-swagger` (swagger-php 4 con atributos PHP 8 `#[OA\Get(...)]`) sobre los controladores existentes de la API con token; centralizar ejemplos como `OpenApiExamples`. Generar cliente JS/TS del spec con `openapi-typescript` u `openapi-generator` para la app móvil y para el futuro MCP. Beneficio inmediato: documentación para clientes/integradores de Talentus y base para el MCP.

---

## 7. (f) MCP / IA

- **No hay** rastro de "MCP", "ModelContextProtocol", OpenAI/LLM en `core` (búsqueda en Java/TS/MD/JSON). Lo que existe y se parece a "extensiones":
  - `ExternalServiceRegistryService` + `ExternalServiceResource` (`service`): registro con lease de 60 s (`DEFAULT_LEASE_DURATION_MS`), `heartbeat()`, estados `ExternalServiceStatus`, servicios **globales (multi-tenant)** o por realm; cada `ExternalService {serviceId, instanceId, version, realm, icon, label, homepageUrl, status, isGlobal}` se muestra en la página "Services" dentro de un **iframe** (`page-services.ts:295 refreshIframe`). Es la vía oficial para colgar microservicios (p. ej. un asistente IA) en la UI.
  - Alarmas, webhooks (`manager/webhook`) y reglas con acciones HTTP permiten disparar servicios externos.
- **Propuesta**: construir en Talentus un **servidor MCP propio** (Node/TS con `@modelcontextprotocol/sdk` o PHP) que exponga herramientas sobre la API REST documentada en OpenAPI (sección 6): `list_devices`, `get_last_position`, `get_history(device, from, to, lttb N)`, `list_alerts`, `run_report(id, params)`, `create_geofence`, con autenticación por service account (sección 5.4) y respetando `visibleTo`. Replicar el patrón "Services": tabla `external_services` con heartbeat y una página que embebe iframes (chat IA, BI externo). Primeras consultas de valor: "¿qué unidades excedieron 90 km/h hoy?", resumen de alertas, generación de reporte en lenguaje natural.

---

## 8. (g) Observabilidad

- `container/.../Container.java:114`: si `OR_METRICS_ENABLED` crea `PrometheusMeterRegistry` y expone `/metrics` en `OR_METRICS_PORT` (8405). Métricas de dominio: `or.attributes` (timer del pipeline de eventos de atributo, `AssetProcessingService`), `or.rules` (ejecución de reglas), `or.provisioning` (MQTT), `or.camel.route.queue.size`, métricas de ActiveMQ Artemis (MQTT) con tag `source`.
- Health: `GET /api/master/health` y `/info` (`StatusResource`); `HealthService` descubre `HealthStatusProvider` por `ServiceLoader` (`JvmHealthStatusProvider`, `SystemHealthStatusProvider`, `CamelHealthStatusProvider`, `SslHealthStatusProvider` → caducidad de certificados).
- Tracing: OpenTelemetry **javaagent opcional**, traces-only (`OTEL_JAVAAGENT_ENABLED`, `OTEL_EXPORTER_OTLP_ENDPOINT` hacia Grafana Alloy), ver `profile/deploy.yml:340-348`; `query-exporter` para métricas de PostgreSQL (`deploy.yml:491`).
- Logs: `SyslogEvent {level, category (ASSET, AGENT, NOTIFICATION, ALARM, RULES, PROTOCOL, GATEWAY, MODEL_AND_VALUES, API, DATA), subCategory, message}` persistidos y consultables (`GET syslog/event` con paginación por cabecera `Link`, `PUT syslog/config` para niveles por categoría) + **log viewer en UI** (`or-log-viewer`: filtros nivel/categoría/subcategoría/texto, live via WebSocket `SyslogEvent`).

**Porte**: `promphp/prometheus_client_php` con almacenamiento Redis (ya disponible) exponiendo `/metrics`: contadores `talentus_positions_ingested_total{protocol}`, histogramas de latencia del parser → BD → socket, cola de jobs, dispositivos online; `spenceryeh/laravel-health` o `spatie/laravel-health` para `/health` (BD, Redis, disco, cola, certificado TLS, último paquete GPS recibido). Para logs estructurados consultables desde la UI: canal Monolog a tabla `system_logs(level, category, sub_category, message, context JSON)` + vista con filtros (= `or-log-viewer`) y broadcast por Socket.io. OTel: `open-telemetry/opentelemetry-php` sólo si ya hay Grafana/Tempo; opcional.

---

## 9. (h) White-label / appearance por realm

- Fuente única: `deployment/manager/app/manager_config.json` (persistido también en `OR_STORAGE_DIR` por `ConfigurationService`), modelo `ManagerAppConfig {loadLocales, languages{}, realms{}, pages{}, manager: ManagerConfig}`; `ManagerAppRealmConfig {appTitle, styles, logo, logoMobile, favicon, language, headers[], notifications{languages[], defaultLanguage}}`. Ejemplo real:
```json
"realms": {
  "default": { "appTitle": "OpenRemote Manager Demo", "language": "en" },
  "smartcity": { "styles": ":host > * {--or-app-color2: #F6F7FA; --or-app-color3: #1B1464; --or-app-color4: #662D91;}",
                 "logo": "/images/logo_smartcity.svg", "logoMobile": "/images/logo-mobile_smartcity.svg" }
}
```
- Tema = **CSS custom properties** `--or-app-color1..8` (fondo, superficie, texto, acento, bordes, etc.) definidas en `ui/component/core` (`DefaultColor1..8`); todos los componentes las consumen. `headers[]` elige qué páginas aparecen en el menú de ese realm; `pages.map.filters` y `pages.assets.viewer.assetTypes` permiten personalizar mapa y vistas por realm.
- UI "Appearance" (`page-configuration.ts` + `components/configuration/or-conf-realm/or-conf-realm-card.ts`): editor por realm con color pickers (parsea `styles`), título, selectores de logo/logo móvil/favicon con subida (`ConfigurationResource.imageUpload` → `POST configuration/manager/image`, validadores `SvgImageValidator`/`RasterImageValidator`), idioma por defecto, páginas visibles, idiomas de notificación; y editor JSON crudo (`or-conf-json.ts`, Ace). También `or-conf-map-*` para `mapsettings.json` (center/zoom/bounds por realm, geojson, subida de mbtiles).
- Keycloak: tema de login `openremote` por realm (`loginTheme`, `accountTheme`, `emailTheme`), editable en `page-realms.ts`.
- Console (apps móviles): `console_config.json {showAppTextInput, showRealmTextInput, app, allowedApps[], apps}` → una misma app puede apuntar a varios realms/marcas.

**Porte**: Talentus ya tiene `template_color` (26 temas) + CSS/JS custom por marca. Mejora propuesta: pasar de temas fijos a **variables CSS** (`--tp-color-primary`, `--tp-color-surface`, ...) generadas desde una tabla `brand_settings(account_id, app_title, logo, logo_mobile, favicon, css_vars JSON, default_language, enabled_modules JSON, login_theme, notification_languages JSON)`; generar `:root{...}` en un `<style>` en Blade (Bootstrap 3 no usa variables pero el SCSS propio sí puede apuntar a ellas en los overrides; con `postcss` basta). Añadir pantalla "Apariencia" con color pickers (`bootstrap-colorpicker`), subida validada de SVG/PNG (sanitizar SVG con `enshrined/svg-sanitize`) y selector de módulos visibles (= `headers[]`). Para dominios por marca: `brand_settings.domain` → middleware que resuelve la marca por `Host` (equivalente a la cabecera `Realm`).

---

## 10. (i) Consola móvil y geofences locales

- Registro de consola: `POST console/register` con `ConsoleRegistration {id, name, version, platform, model, providers{...}, apps[]}` → se crea un `ConsoleAsset` con atributo `consoleProviders`. Cada `ConsoleProvider {version, requiresPermission, hasPermission, success, enabled, disabled, data{}}`; proveedores: `push` (`version: "fcm" | "web"`, `data.token`), `geofence` (`version: "ORConsole"`), `storage`.
- Push: `PushNotificationHandler` (Firebase Admin SDK, `FCM_PROVIDER_NAME="fcm"`), mapa `consoleFCMTokenMap`; objetivo por usuario/asset/realm/consola; `pushNotificationsDisabled` como atributo de usuario para opt-out.
- **Geofences en el dispositivo**: `ORConsoleGeofenceAssetAdapter` (`manager/.../rules/geofence/`) extrae de las reglas los predicados de ubicación (`RadialGeofencePredicate {lat,lng,radius}`, `RectangularGeofencePredicate`, `GeoJSONGeofencePredicate`) por asset consola, los convierte a `GeofenceDefinition {id, lat, lng, radius, httpMethod, url}` y, cuando cambian (debounce 60 s, batch 10 s), envía un push **silencioso** `{"action":"GEOFENCE_REFRESH"}`; la app llama `GET rules/geofences/{assetId}` (accesible anónimo si el asset es público, o restringido con link) y registra las geocercas en el SO (Android/iOS geofencing API). Al entrar/salir, el teléfono hace `httpMethod url` (escribe el atributo `location` del asset) → el servidor evalúa reglas aunque la app esté cerrada, con consumo de batería mínimo.

**Porte**: para la app móvil de Talentus (choferes/supervisores con teléfono como tracker): tabla `mobile_consoles(user_id, platform, model, fcm_token, providers JSON)`, endpoint `POST /api/console/register`; `GET /api/geofences/mine` que devuelva círculos (`lat,lng,radius,callback_url`) derivados de las geocercas de la cuenta (polígonos → círculo envolvente o lista de centros; iOS limita a 20 regiones, Android a 100) y push silencioso FCM `GEOFENCE_REFRESH` al cambiar. Reutilizar FCM (`kreait/laravel-firebase`). Permite "check-in/out automático" sin GPS continuo.

---

## 11. Otros hallazgos útiles

### 11.1 Export de datapoints (CSV)
`GET asset/datapoint/export?attributeRefs=[{id,name}]&fromTimestamp&toTimestamp&format=CSV|CSV_CROSSTAB|CSV_CROSSTAB_MINUTE` → **ZIP en streaming** (`AssetDatapointService.exportDatapoints` usa `COPY ... TO STDOUT` de PostgreSQL por `PipedInputStream`, tablas temporales `tmp_export_attributes/tmp_export_bounds`, límite `OR_DATA_POINTS_EXPORT_LIMIT` con pre-conteo → 413 Payload Too Large). UI `page-export.ts`: árbol de assets → selección de atributos → tabla con periodo disponible (`GET asset/datapoint/periods`) → descarga. Para Talentus: ya existe export; vale la pena el **crosstab por minuto** (una columna por sensor/vehículo alineada por minuto) y el streaming (`LOAD DATA`/`SELECT ... INTO OUTFILE` no aplica; usar `fputcsv` con cursor `chunk`/`lazy()` y `ZipStream-PHP`).

### 11.2 Asset tree (`or-asset-tree`)
Árbol jerárquico con carga perezosa por `parentId` (`dataProvider(offset, limit, parentId)`, `queryLimit 100`, `paginationThreshold 1000`), filtro por nombre/tipo/atributo (`applyFilter`), ordenación, selección múltiple con checkboxes, **drag & drop para re-parentar**, suscripción a eventos de asset. Para Talentus: jerarquía `Cuenta → Sub-cuenta → Grupo → Vehículo` con un plugin jQuery (`jstree` soporta lazy load, checkboxes y DnD, y es compatible con Bootstrap 3).

### 11.3 JSON Forms (`or-json-forms`)
Renderizador Lit de `@jsonforms/core` (schema + uischema + ajv) usado por `or-attribute-input` para editar valores complejos: el schema viene del servidor (`GET model/valueDescriptors` / `AssetModelResource.getValueDescriptorSchema`). Para Talentus: formularios de **configuración de dispositivo por modelo** (parámetros del rastreador, comandos con argumentos) definidos como JSON Schema en BD y renderizados con `json-editor/json-editor` (soporta tema Bootstrap 3 nativo) — sin tocar PHP por cada modelo nuevo.

### 11.4 Alarmas, provisioning, gateway tunnels
`page-alarms` (alarmas con severidad/estado/asignado/enlazadas a assets), `page-provisioning` (auto-provisioning de dispositivos por certificado X.509 vía MQTT), `page-gateway-tunnel` (túneles HTTPS/TCP a equipos remotos). Fuera del alcance de este informe pero relevantes para el módulo de alertas y para rastreadores con MQTT.

### 11.5 i18n
`i18next` + backend HTTP `/shared/locales/{{lng}}/{{ns}}.json` (namespaces `or` y `app`), 13 idiomas (`ar, cn, cs, de, en, es, fr, it, nl, pl, pt, ro, uk`), idioma por realm (`ManagerAppRealmConfig.language`) y por usuario (`User.LOCALE_ATTRIBUTE`), mapeo a locales de Moment. Talentus ya lo cubre; sólo copiar la idea de idioma por marca + idioma por usuario para notificaciones (`notifications.languages`).

### 11.6 Rive renderer
`<or-rive-renderer url="x.riv">` para animaciones vectoriales interactivas (estado del dispositivo animado). Decorativo; `@rive-app/canvas` funciona con cualquier stack.

---

## 12. Hoja de ruta sugerida (incremental, sin romper Laravel 8 + jQuery)

1. **Semana 1-2 — Fundaciones**: `l5-swagger` sobre la API actual; Vite junto a Gulp para compilar nuevos "módulos" (GridStack, ECharts, rrule) como bundles UMD cargados desde Blade; tabla `dashboards` + API con `visibleTo`.
2. **Semana 3-5 — Dashboard builder**: GridStack 14 + registro de widgets (linechart ECharts, barchart, gauge, kpi, table, map-Leaflet, image); presets de pantalla; página pública compartible; refresh por intervalo + Socket.io.
3. **Semana 6 — Scheduler**: tabla `schedules` + `php-rrule` + componente rrule.js; aplicar a alertas y geocercas.
4. **Semana 7-8 — Seguridad**: permisos atómicos + roles compuestos (`spatie/laravel-permission`), usuarios restringidos con `user_device_links` y ACL por atributo, service accounts con rotación de secreto.
5. **Semana 9 — Apariencia por marca**: `brand_settings` con CSS vars, uploader validado, módulos visibles, resolución por dominio.
6. **Semana 10 — Observabilidad y logs**: `/metrics` Prometheus (Redis), `/health`, tabla `system_logs` + visor.
7. **Después**: MCP server sobre la API OpenAPI; geofences locales en la app móvil con FCM silencioso; evaluar MapLibre + mbtiles si el coste de tiles o el offline lo justifican.

Licencia: OpenRemote es **AGPL-3.0**; portar *ideas, modelos de datos y algoritmos* es libre, pero copiar código fuente (TS/Java) a un producto cerrado obliga a AGPL. Las librerías terceras citadas (GridStack MIT, ECharts Apache-2, rrule.js BSD, gaugeJS MIT, MapLibre BSD, json-editor MIT) sí pueden usarse directamente.
