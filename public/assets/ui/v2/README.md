# TalentusPro · Design System (Fase 0 Foundations)

> Modern Enterprise Command Center · v1.0
> Rediseño visual completo de la plataforma GPS Tracking.

---

## 📁 Estructura

```
public/assets/talentus/
├── css/
│   ├── tokens.css         ← Variables CSS (color, spacing, type, shadow, motion)
│   └── components.css     ← Componentes base (.tp-* prefix)
├── showcase.html          ← Página de demostración interactiva
└── README.md              ← Este archivo

resources/assets/scss/talentus/
├── _index.scss            ← Entry para Gulp pipeline
├── _tokens.scss           ← Espejo SCSS de tokens.css
└── _components.scss       ← Espejo SCSS de components.css

resources/assets/scss/templates/
└── talentus.scss          ← Template para `gulp sass-all` → public/assets/css/talentus.css
```

---

## 🚀 Vista previa inmediata (sin build)

Abre en tu navegador:

```
http://localhost/talentus-pro-tracking/public/assets/talentus/showcase.html
```

(Ajusta el host según tu Laragon.)

La página incluye:

- Shell completo (Rail + Topbar + Workspace)
- Live Ops preview (lista + mapa simulado + marcadores)
- 6 KPI cards del Command Center
- Sistema de color completo
- Tipografía, botones, formularios, chips, alertas, cards, tablas, tabs
- Toggle **Light/Dark** + **Comfortable/Compact** flotante (esquina inferior derecha)

---

## 🧩 Uso en Blade (sin tocar layout legacy)

En cualquier vista nueva:

```blade
<!DOCTYPE html>
<html lang="es" data-theme="dark" data-density="comfortable">
<head>
    <link rel="stylesheet" href="{{ asset('assets/talentus/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/talentus/css/components.css') }}">
</head>
<body class="tp-scope">

    <div class="tp-app">
        <aside class="tp-rail">…</aside>
        <header class="tp-topbar">…</header>
        <main class="tp-workspace">

            <div class="tp-card">
                <h2 class="tp-h3">Hola Command Center</h2>
                <button class="tp-btn tp-btn--primary">Acción</button>
            </div>

        </main>
    </div>

</body>
</html>
```

**Importante:** todo está namespaced con `.tp-*` y vive dentro de `.tp-scope`, por lo que **NO colisiona con la UI legacy (Bootstrap/Tobuli)**. Puedes inclusive renderizar componentes nuevos dentro de páginas viejas envolviendo en `<div class="tp-scope">…</div>`.

---

## 🎨 Tokens principales

### Colores de marca

```css
--brand-900: #060b34 /* Primary Dark */ --brand-800: #0e2157 /* Secondary */
  --brand-700: #122f71 /* Accent · primary action */ --brand-500: #2e63d9
  /* Links, info */ --neutral-400: #898989 /* Brand neutral */;
```

### Tipografía

- **UI:** Inter (carga desde `rsms.me/inter` o self-hosted)
- **Mono (métricas/IDs/coords):** JetBrains Mono
- **Base:** 13px (densidad enterprise)

### Estado operacional (mapa & listas)

```css
--status-moving: #10b981 /* verde */ --status-idle: #f59e0b /* ámbar */
  --status-stopped: #6b7280 --status-offline: #4b5563 --status-alert: #ef4444
  --status-sos: #b91c1c /* pulsing */;
```

---

## 🌗 Modos

| Atributo       | Valores                  | Default                          |
| -------------- | ------------------------ | -------------------------------- |
| `data-theme`   | `light`, `dark`          | `dark` (Live Ops/Command Center) |
| `data-density` | `comfortable`, `compact` | `comfortable`                    |

Se aplican en `<html>`. Persistir vía localStorage en producción.

---

## 🧱 Componentes incluidos

- **Shell:** `.tp-app`, `.tp-rail`, `.tp-topbar`, `.tp-workspace`
- **Layout:** `.tp-stack`, `.tp-row`, `.tp-grid`, `.tp-divider`
- **Surface:** `.tp-card`, `.tp-surface`, `.tp-glass`
- **Buttons:** `.tp-btn` con variantes `--primary`, `--secondary`, `--ghost`, `--danger`, `--critical`, `--success` + tamaños `--sm`, `--lg`, `--icon`, `--block` + `.tp-btn-group`
- **Inputs:** `.tp-input`, `.tp-select`, `.tp-textarea`, `.tp-field`, `.tp-label`, `.tp-input-wrap`, `.tp-switch`
- **Chips/Badges:** `.tp-chip` (variantes semánticas), `.tp-badge`, `.tp-dot` (estado operacional)
- **Alerts:** `.tp-alert` (info/success/warning/danger/critical)
- **Tables:** `.tp-table` (sticky header, hover, dense)
- **KPI:** `.tp-kpi` con value mono + delta + sparkline slot
- **Tabs:** `.tp-tabs`, `.tp-segmented`
- **Tooltip:** `.tp-has-tooltip[data-tooltip="…"]` (CSS-only)
- **Avatar:** `.tp-avatar` (sm/lg)
- **Utilidades:** `.tp-text-mono`, `.tp-text-muted`, `.tp-text-overline`, `.tp-truncate`, `.tp-sr-only`, margins…

---

## ⚙️ Integración con Gulp (opcional)

Si quieres compilar también vía tu pipeline:

```bash
gulp sass-all       # genera public/assets/css/talentus.css
gulp minify-css     # minifica
```

Esto compila `resources/assets/scss/templates/talentus.scss`. Los SCSS partials son simples re-imports del CSS plano (el design system **no necesita Sass** — usa CSS custom properties).

---

## 🗺️ Próximos pasos (Fase 1)

- [ ] Command Palette ⌘K (búsqueda global → Devices, Geofences, Users)
- [ ] Workspace switcher multi-empresa
- [ ] Layout Blade reutilizable (`layouts.talentus`) con feature flag por usuario
- [ ] Migrar `objects.index` (módulo principal de tracking) a Live Ops nuevo
- [ ] Iconografía Lucide self-hosted (sustituye SVGs inline)
- [ ] Persistencia de tema/densidad por usuario en BD (`users.preferences`)

---

## 📜 Convenciones

1. **Prefijo `.tp-`** obligatorio en TODO selector nuevo (evita colisión con Bootstrap/Tobuli legacy).
2. **CSS custom properties** para todo lo tokenizable (color, spacing, radius, motion). Nada de valores mágicos.
3. **Densidad enterprise** por default: 13px body, 36px button height, 24px chip height.
4. **Accesibilidad AA** mínimo: `:focus-visible` con `--shadow-focus`, `aria-*` en componentes complejos, `prefers-reduced-motion` respetado.
5. **Dark mode** es el default operacional (reduce fatiga en pantallas 24/7).
6. **Glass overlays** solo sobre el mapa, nunca sobre páginas de gestión.

---

**Versión:** 1.0 · Fase 0 (Foundations)
**Autor:** Product Design 2026
**Compatibilidad:** Chrome/Edge/Firefox/Safari últimos 2 años. CSS custom properties + `backdrop-filter` requeridos.
