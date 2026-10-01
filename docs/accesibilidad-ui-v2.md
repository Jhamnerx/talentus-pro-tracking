# Accesibilidad · UI v2 · botones y elementos interactivos

Revisión del 1-oct-2026 sobre `resources/views/ui/v2` (52 blades),
`resources/assets/ui-v2/js` (46 ficheros) y `resources/assets/ui-v2/css`
(23 ficheros). Marco de reglas: skill `ui-ux-pro-max`, prioridades 1 y 2
(Accesibilidad y Táctil/Interacción), contrastadas con WCAG 2.2 AA.

El escaneo cubre las dos fuentes de marcado: los blades **y las cadenas de
JavaScript**, que en esta aplicación generan la mayoría de los controles
(listas de vehículos, tablas, menús de tres puntos).

## Resumen

| # | Problema | Casos | Severidad |
|---|----------|------:|-----------|
| 1 | Elemento no interactivo con click (sin teclado) | 12 | Crítico |
| 2 | Campo de formulario sin etiqueta | 84 | Alto |
| 3 | Icono decorativo sin `aria-hidden` | 56 | Medio |
| 4 | Conmutador sin estado expuesto | 17 | Alto |
| 5 | `prefers-reduced-motion` sin respetar | 13 ficheros | Medio |
| 6 | Destino táctil por debajo de 24×24 px | 3 | Alto |
| 7 | Foco sin indicador visible | 3 | Crítico |
| 8 | Botón de solo icono sin nombre accesible | 2 | Crítico |

**~189 casos reales.** El escaneo en bruto dio 510; el resto eran falsos
positivos del propio escáner, depurados en seis pasadas (ver *Método*).

---

## 1 · Elemento no interactivo con click · 12 casos · CRÍTICO

Un `<tr>`, `<div>` o `<span>` con manejador de click **no recibe foco con
Tab y no responde a Enter ni a Espacio**. Con teclado o lector de pantalla,
esa función no existe.

Son estos doce, verificados uno a uno:

| Dónde | Qué es |
|-------|--------|
| `tp-select.js:283` y `:292` `<div data-dd-val>` | **opciones del desplegable propio** |
| `reports.js:1068` `<tr data-rep-row>` | fila de reporte guardado → abre la edición |
| `reports.js:1105` `<tr data-log-row>` | fila de log → descarga el fichero |
| `tp-chat.js:56` `<li data-device>` | conversación del chat |
| `routes-table.js:132` y `:133` `<span data-toggle>` | insignia activo/inactivo, conmuta |
| `live.js:2536` `<div data-dgm-head>` | cabecera de grupo plegable |
| `alerts.js:827` `<span data-day>` | celda del horario semanal |
| `tp-account.js:242` `<tr data-acc-item>` | fila de la cuenta |
| `history.js:701` `<div data-idx>` | elemento de la línea de tiempo |
| `device-modal.js:339` `<span data-idx>` | fila de color de ruta |

**Corrección.** Para lo que es un botón, usar `<button type="button">` con el
reseteo de estilo que ya existe. Donde la fila debe seguir siendo `<tr>`, dar
`tabindex="0"`, `role="button"` y manejar `keydown` con Enter y Espacio. El
caso de `tp-select.js` es el más grave y el único que no es mecánico: un
desplegable propio necesita `role="listbox"` / `role="option"`,
`aria-activedescendant` y navegación con flechas.

## 2 · Campo de formulario sin etiqueta · 84 casos · ALTO

- **29** usan el `placeholder` como única etiqueta. El placeholder desaparece
  al escribir y muchos lectores de pantalla no lo anuncian.
- **55** no tienen ni etiqueta ni placeholder.

| Fichero | Casos |
|---------|------:|
| `modals/devices/sensor-form.blade.php` | 24 |
| `pages/history.blade.php` | 6 |
| `pages/live.blade.php` | 6 |
| `modals/devices/partials/exactitud.blade.php` | 4 |
| `modals/devices/partials/principal.blade.php` | 4 |
| `pages/events.blade.php` | 4 |

**Corrección.** `<label for>` visible donde quepa. En las barras de
herramientas, donde una etiqueta visible estorba (buscadores, selectores de
grupo), basta `aria-label`. Ejemplos concretos: `#tp-chat-input`,
`#tp-routes-tbl-search`, `#tp-zones-group`.

## 3 · Icono decorativo sin `aria-hidden` · 56 casos · MEDIO

Botones que ya llevan texto visible y, además, un `<svg>` sin ocultar. El
lector lo anuncia o lo nombra por su contenido, duplicando la lectura.

**Corrección.** `aria-hidden="true"` y `focusable="false"` en todo `<svg>`
que acompañe a un texto. Es mecánico y se puede hacer en una pasada.

## 4 · Conmutador sin estado expuesto · 17 casos · ALTO

`classList.toggle("is-active" | "is-on")` cambia el aspecto pero no dice
nada a la tecnología asistiva.

| Dónde | Falta |
|-------|-------|
| `reports.js:1197` pestañas | `aria-selected` + `role="tab"` |
| `alerts.js:925,942,961` rejilla horaria | `aria-pressed` por celda |
| `history.js:280` menú de capas | `aria-checked` |

**Corrección.** Fijar el atributo en el mismo sitio donde se alterna la
clase, para que no se puedan separar.

## 5 · `prefers-reduced-motion` sin respetar · 13 ficheros · MEDIO

De los 15 CSS con transiciones o animaciones, **solo 2** la contemplan
(`live-ops.css` y otro). Los 13 restantes animan siempre, incluso con el
sistema configurado para reducir movimiento.

**Corrección.** Un bloque global en `components.css` o `tokens.css`:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .01ms !important;
    scroll-behavior: auto !important;
  }
}
```

## 6 · Destino táctil por debajo de 24×24 px · 3 casos · ALTO

WCAG 2.2 AA pide 24 CSS px para punteros en web (el 44 pt de iOS y el 48 dp
de Android son otra regla, para nativo).

| Selector | Tamaño | Qué es |
|----------|--------|--------|
| `live-ops.css:718` `.tp-live__row-menu` | 22×22 | **tres puntos de cada vehículo** |
| `alerts.css:74` `.tp-alerts__rule-toggle` | 28×16 | interruptor de regla |
| `alerts.css:703` `.tp-alert-toggle` | 34×18 | interruptor de alerta |

**Corrección.** `min-width/min-height: 24px`, o un `::before` que extienda el
área sin tocar el aspecto. El de los vehículos es el que más se usa.

## 7 · Foco sin indicador visible · 3 casos · CRÍTICO

De los 8 `outline: none` del CSS, **5 están bien**: reemplazan el anillo con
`box-shadow`. Los otros tres, no:

| Fichero | Selector | Qué queda |
|---------|----------|-----------|
| `shell.css:239` | `.tp-cmdk__input` | nada — es la paleta de comandos |
| `copilot.css:104` | `.tp-copilot__ask-input` | nada |
| `chat.css:235` | `.tp-chat__composer input:focus` | solo `border-color`, 1 px |

**Corrección.** Reemplazar con `box-shadow: var(--shadow-focus)`, como ya hace
`components.css:545`. El borde de 1 px del chat difícilmente llega al 3:1 que
pide el criterio de apariencia del foco.

## 8 · Botón de solo icono sin nombre accesible · 2 casos · CRÍTICO

| Dónde | Qué es |
|-------|--------|
| `tp-call-actions.js:214` | menú de tres puntos; tiene `aria-haspopup` pero no nombre |
| `tp-commands.js:92` | selector de vehículos del envío de comandos |

Buena noticia: de los ~20 menús de tres puntos de la v2, **solo este** se
quedó sin nombre. El resto ya llevan `title` o `aria-label`.

**Corrección.** `aria-label="{{ trans('global.actions') }}"`.

---

## Orden sugerido

1. **Una tarde:** los puntos 7, 8 y 6 — siete sitios en total, todos de una línea.
2. **Un día:** el 5 (un bloque CSS) y el 3 (pasada mecánica sobre los `<svg>`).
3. **Dos o tres días:** el 1 y el 4, que exigen tocar el marcado generado en JS.
4. **Por módulo:** el 2, empezando por `sensor-form.blade.php`, que concentra 24.

El 1 y el 2 juntos son los que deciden si la aplicación se puede usar con
teclado. Los demás mejoran la experiencia pero no bloquean.

**Dónde está la palanca.** Tres arreglos cubren más de la mitad de los casos:

- `modal.js:526` — el constructor de pestañas `tpModal.tabs`, compartido.
  Añadir ahí `role="tab"` y `aria-selected` arregla las pestañas de todos
  los modales de una vez.
- Un bloque `@media (prefers-reduced-motion)` en `components.css` cubre los
  13 ficheros del grupo 5.
- `tp-select.js` es el desplegable propio de toda la v2: arreglarlo una vez
  vale para cada formulario que lo use.

## Método

Las cifras salen de un escaneo propio, depurado contra el código. La primera
pasada dio **510** hallazgos y la mayoría eran defectos del escáner, no de la
aplicación:

- Botones con texto visible contados como "solo icono", porque al quitar el
  `<svg>` el texto se quedaba sin delimitadores `>` `<`.
- `title` y `aria-label` concatenados en JS (`title="' + esc(…) + '"`) que el
  patrón no reconocía, y que además se parten en varias líneas.
- Delegación de eventos (listener en el contenedor + `closest()` dentro)
  contada como control sin semántica: son 111 casos y casi todos correctos.
- Puntos de leyenda y pseudo-elementos contados como destinos táctiles: de 29
  quedaron 3.
- `outline: none` con `box-shadow` de reemplazo: de 8 quedaron 3.
- `data-sensor` casando dentro de `data-sensor-input`, porque el guion cuenta
  como límite de palabra: 22 divs de un formulario marcados de más.
- Y el que más pesaba: **no distinguir el control del contenedor**. Un gancho
  aparece en un `closest()` por dos motivos opuestos —
  `e.target.closest("[data-x]")` significa que el usuario pulsa ahí, pero
  `otraVar.closest("[data-x]")` es subir desde un botón interior para acotar
  la fila. Solo el primero necesita teclado. De 60 quedaron 12.

**Lección para repetir la revisión:** en esta aplicación el marcado vive en
cadenas de JavaScript tanto como en los blades, y cualquier herramienta que
solo mire los blades se deja fuera la mayor parte de los controles.
