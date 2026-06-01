# Talentus Pro · Plataforma de Rastreo · Rediseño UI/UX

> Build paralelo bajo **strangler pattern**: cero modificaciones al legacy de Tobuli/Traccar.
> Todo el código nuevo vive bajo `talentus/*` (rutas, vistas, assets) y delega acciones pesadas al sistema legacy vía deep-links.

---

## Stack

- **Laravel 8.x** · doble namespace `App\` + `Tobuli\`
- **MapLibre GL 4.7.1** (CDN, raster sources)
- **ECharts 5.5.1** (CDN, SVG renderer)
- **Vanilla JS** (sin frameworks, módulos IIFE)
- **CSS tokens** (`--surface-*`, `--fg-*`, `--brand-*`, `--status-*`)
- **Class prefix**: `.tp-*`

## Estructura

```
app/Http/Controllers/Frontend/TalentusController.php   ← controlador único
routes/web.php                                          ← grupo prefix='talentus'
resources/views/talentus/
    layout.blade.php
    partials/{rail,topbar,…}.blade.php
    pages/{live,dashboard,fleet,zones,alerts,history,reports,admin,copilot}.blade.php
public/assets/talentus/
    css/*.css
    js/*.js
```

## Rutas (todas bajo `auth` middleware, prefix `/talentus`, as `talentus.*`)

| URI                   | Name                 | Descripción                     |
| --------------------- | -------------------- | ------------------------------- |
| `/talentus/preview`   | `talentus.preview`   | Landing / hero                  |
| `/talentus/live`      | `talentus.live`      | Live Ops (mapa tiempo real)     |
| `/talentus/dashboard` | `talentus.dashboard` | Command Center / KPIs           |
| `/talentus/fleet`     | `talentus.fleet`     | Vista de flota tabular          |
| `/talentus/zones`     | `talentus.zones`     | Geocercas                       |
| `/talentus/alerts`    | `talentus.alerts`    | Centro de alertas               |
| `/talentus/history`   | `talentus.history`   | Historial + replay              |
| `/talentus/reports`   | `talentus.reports`   | Reportes                        |
| `/talentus/admin`     | `talentus.admin`     | API / Webhooks / SSO / Branding |
| `/talentus/copilot`   | `talentus.copilot`   | Co-Pilot IA                     |
| `/talentus/legacy`    | `talentus.legacy`    | Bypass a la UI legacy           |
| `/talentus/api/*`     | `talentus.api.*`     | JSON endpoints internos         |

---

# CHANGELOG

## [10.0.0] – Phase 10 · Co-Pilot IA

### Added

- **Controller**: `copilot()`, `copilotInsights()`, `copilotAsk()` en `TalentusController`.
- **Insights heurísticos** (sin dependencia LLM externa):
  - Clasificación de flota: moving / idle / stopped / offline.
  - Detección de offline >24h, idle >2h, sin GPS fix.
  - Agregación de eventos 24h por tipo y dispositivo.
  - Flags: SOS, exceso velocidad (≥10), reincidentes (≥5 eventos), baja utilización (<40%).
- **Pregunta NL** (regex en español): parados, movimiento, idle, offline, velocidad, SOS, reportes, geocercas, historial, dashboard.
- **Búsqueda fallback** por nombre / IMEI / placa → deep link a Live Ops.
- **Vista** `copilot.blade.php` con hero, ask box, KPIs, insights, shortcuts y chat.
- **Assets** `css/copilot.css` + `js/copilot.js`.
- **Rail**: footer item `copilot` apunta a la nueva ruta.

### Fixed

- `topbar.blade.php`: `route('myaccount.index')` → `route('my_account.edit')` con fallback `Route::has`.

## [9.0.0] – Phase 9 · Admin & Integrations

- `admin()`, `adminData()`: API tokens (sin exponer hash), webhooks (Forward entity), SSO Azure detection, branding.
- Filtrado por rol (`isAdmin`, `isManager`).
- Vista `admin.blade.php` + assets.

## [8.0.0] – Phase 8 · Reports

- `reports()`, `reportsData()` con `Tobuli\Reports\ReportManager`.
- Lista de `Report` (con conteo de devices y chips de schedule) y `ReportLog` (50 últimos).
- Vista `reports.blade.php` + assets.

## [7.0.0] – Phase 7 · History & Replay

- `history()`, `historyData()`: posiciones desde `traccar_mysql`.
- Cálculo de distancia (Haversine), agrupación de paradas ≥3min < min_moving_speed.
- KPIs: distance_km, duration_sec, max_speed, avg_speed, stops, points.
- Vista `history.blade.php` con MapLibre + replay + assets.

## [6.0.0] – Phase 6 · Alerts

- `alerts()`, `alertsData()` con bucket `critical|warning|info`.
- Vista + filtros + ack.

## [5.0.0] – Phase 5 · Geofences

- `zones()`, `zonesData()`.

## [4.0.0] – Phase 4 · Fleet

- `fleet()`, `fleetData()` con bucket de estados.

## [3.0.0] – Phase 3 · Command Center

- `dashboard()`, `dashboardData()` con KPIs + gráficos ECharts.

## [2.0.0] – Phase 2 · Live Ops

- `live()`, `devices()` feed con MapLibre.

## [1.0.0] – Phase 1 · Shell

- `layout`, `rail`, `topbar`, design tokens, dark/light, density toggle, preferences endpoint.

## [0.0.0] – Phase 0 · Setup

- Bootstrap del namespace `talentus/*`, estructura inicial, branding.

---

# Seguridad (OWASP-aware)

- **A01 Broken Access Control**: todo bajo `auth`, queries scoped por `$user->devices()` / `$user->events()`. Sin escalado entre tenants.
- **A02 Crypto**: `api_hash` nunca expuesto en respuestas.
- **A03 Injection**: queries vía Eloquent, `LIKE` con bindings, validación de longitud (`q` 1–200), regex anclados.
- **A04 Insecure Design**: Co-Pilot 100% local (sin LLM externo) → cero filtración de datos a terceros.
- **A05 Misconfig**: views compiladas limpias, `view:clear` tras cada deploy.
- **A07 Identification**: CSRF habilitado por middleware web.
- **A08 Integrity**: assets servidos same-origin, versionados (`?v=`).
- **A09 Logging**: integrado al sistema legacy (`EventLoggable`, `ChangeLogs`).
- **DOM**: `escapeHtml()` aplicado en toda inyección dinámica de JS.

# Testing manual

```
/talentus/preview      → hero
/talentus/live         → mapa con vehículos
/talentus/dashboard    → KPIs
/talentus/fleet        → tabla
/talentus/zones        → geocercas
/talentus/alerts       → alertas
/talentus/history      → historial + replay
/talentus/reports      → reportes
/talentus/admin        → integraciones
/talentus/copilot      → IA
```

Sugerencias para probar Co-Pilot:

- «vehículos parados»
- «excesos de velocidad»
- «alertas sos»
- «reporte de ayer»
- «ABC-123» (placa o IMEI)
