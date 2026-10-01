# Accesibilidad · UI v2 · botones y elementos interactivos

Revisión del 1-oct-2026 sobre `resources/views/ui/v2` (52 blades),
`resources/assets/ui-v2/js` (46 ficheros) y `resources/assets/ui-v2/css`
(23 ficheros). Marco de reglas: skill `ui-ux-pro-max`, prioridades 1 y 2,
contrastadas con WCAG 2.2 AA.

El escaneo cubre las dos fuentes de marcado: los blades **y las cadenas de
JavaScript**, que en esta aplicación generan la mayoría de los controles.

> **Estado:** seis de los ocho grupos están corregidos. Quedan los
> conmutadores y los campos sin etiqueta.

## Dónde estamos

| # | Problema | Encontrados | Pendientes | Severidad |
|---|----------|------------:|-----------:|-----------|
| 1 | Elemento no interactivo con click | 6 | **0** | Crítico |
| 2 | Campo de formulario sin etiqueta | 84 | **84** | Alto |
| 3 | Icono decorativo sin `aria-hidden` | 56 | **0** | Medio |
| 4 | Conmutador sin estado expuesto | 17 | **12** | Alto |
| 5 | `prefers-reduced-motion` sin respetar | 13 ficheros | **0** | Medio |
| 6 | Destino táctil por debajo de 24×24 px | 3 | **0** | Alto |
| 7 | Foco sin indicador visible | 2 | **0** | Crítico |
| 8 | Botón de solo icono sin nombre accesible | 2 | **0** | Crítico |

**96 pendientes de 172.** Lo corregido está en cuatro commits: `4f0fb712`,
`2ba82bcb`, `30ed8e43` y `4c1c59a4`.

---

## Lo que queda

### 4 · Conmutador sin estado expuesto · 12 casos · ALTO

Cambiar una clase CSS cambia el aspecto, no lo que anuncia un lector de
pantalla. No son un patrón, son cuatro:

| Patrón | Dónde | Qué falta |
|--------|-------|-----------|
| **Pestañas sin rol** (4) | `reports.js:1201`, `fleet.js:900`, `live.js:3345`, `tp-account.js:972` | `role="tablist"`, `role="tab"`, `role="tabpanel"`, los `id` que los enlazan, y luego `aria-selected` |
| **Rejilla del horario** (4) | `alerts.js:920`, `:925`, `:942`, `:961` | `aria-pressed` por celda **y** una alternativa al arrastre (criterio de arrastre de WCAG 2.2) |
| **Pulsadores** (3) | `reports.js:430`, `tp-tasks.js:1079`, `command-palette.js:273` | `aria-pressed`, salvo el de la paleta que es `aria-selected` sobre una opción |
| **Menú de capas** (1) | `history.js:280` | `aria-checked` |

Las tres que ya estaban bien estructuradas (`modal.js`, `device-modal.js`,
`tp-route-schedules.js`) se corrigieron en `30ed8e43`.

**Caso aparte.** La cabecera de grupo plegable de `live.js:2536` lleva un
`<input type="checkbox">` dentro, así que **no puede** ser `role="button"`:
sería ARIA inválido. Hay que convertir el galón en un `<button>` propio que
pliegue, con `aria-expanded`. Es reestructurar, no añadir un atributo.

### 2 · Campo de formulario sin etiqueta · 84 casos · ALTO

- **29** usan el `placeholder` como única etiqueta. Desaparece al escribir y
  muchos lectores no lo anuncian.
- **55** no tienen ni etiqueta ni placeholder.

| Fichero | Casos |
|---------|------:|
| `modals/devices/sensor-form.blade.php` | 24 |
| `pages/history.blade.php` | 6 |
| `pages/live.blade.php` | 6 |
| `modals/devices/partials/exactitud.blade.php` | 4 |
| `modals/devices/partials/principal.blade.php` | 4 |
| `pages/events.blade.php` | 4 |

**Corrección.** `<label for>` visible donde quepa; `aria-label` en las barras
de herramientas, donde una etiqueta visible estorba.

---

## Lo corregido

### 8 · Botón de solo icono sin nombre · `4f0fb712`

`tp-call-actions.js` (menú de tres puntos) y `tp-commands.js` (selector de
vehículos, que tiene un `<label>` al lado pero un `<label>` no puede
etiquetar un `<button>`).

### 7 · Foco sin indicador · `4f0fb712`

`.tp-cmdk__head:focus-within` para la paleta de comandos, cuyo input no
tiene borde propio, y el anillo de `.tp-input` para el compositor del chat,
que solo cambiaba el color de un borde de 1 px.

### 6 · Destino táctil · `4f0fb712`

`.tp-live__row-menu` de 22 a 24 px. Los dos interruptores de alertas
conservan su tamaño visual y amplían el **área** con un `::before` de 24 px;
el `::after` ya era el deslizante.

### 5 · Movimiento reducido · `4f0fb712`

Un bloque global en `components.css`. A 0,01 ms y no a 0, para que sigan
disparándose los eventos de fin de transición de los que depende algún
componente.

### 3 · Iconos decorativos · `2ba82bcb`

57 `<svg>` en 18 ficheros. Solo los que están dentro de un control **con
texto visible**; los de un botón de solo icono se dejaron intactos, porque
ahí el icono es lo único que hay.

### 1 · Elementos con click · `4c1c59a4`

Un **puente de teclado** en `modal.js`, que se carga en todas las páginas:
si un elemento declara rol de control y puede recibir foco, Enter y Espacio
le disparan un click. Así los manejadores de click existentes valen tal
cual, en vez de escribir uno de teclado por sitio. Probado contra 13 casos.

Marcados los seis sitios: las dos filas de `reports.js`, la fila de cuenta,
la conversación del chat (con `aria-current`) y las dos insignias de ruta,
que van como `role="switch"` con `aria-checked` porque activo/inactivo **es**
un conmutador y ese estado vivía solo en la clase.

Más un anillo de foco en `components.css`: dar `tabindex` sin estilo de foco
deja llegar con Tab pero sin ver dónde estás.

---

## Método, y los cuatro sesgos que tuvo

Las cifras salen de un escáner propio. **La primera pasada dio 510 y la
mayoría eran defectos del escáner, no de la aplicación.** Quedan anotados
porque quien repita la revisión se va a tropezar con los mismos:

1. **Botones con texto contados como "solo icono".** Al quitar el `<svg>`, el
   texto se quedaba sin los delimitadores `>` `<` que buscaba el patrón.
   10 → 2.
2. **`title` y `aria-label` concatenados en JS** (`title="' + esc(…) + '"`),
   que además se parten en varias líneas: hacía falta `re.S`.
3. **Prefijos de atributo.** `data-sensor` casaba dentro de
   `data-sensor-input`, porque el guion cuenta como límite de palabra:
   22 `<div>` de un formulario marcados de más.
4. **Ganchos recogidos globalmente.** El peor. `data-idx` usado como destino
   de click en `device-modal.js` marcaba cualquier `data-idx` de cualquier
   otro fichero — `history.js` salía en la lista sin tener ese manejador.
   Ahora se analiza **fichero a fichero**.

Más dos distinciones que el escáner no hacía y que importan más que los
patrones:

- **Control contra contenedor.** Un gancho sale en un `closest()` por dos
  motivos opuestos: `e.target.closest("[data-x]")` significa que el usuario
  pulsa ahí; `otraVar.closest("[data-x]")` es subir desde un botón interior
  para acotar la fila. Solo el primero necesita teclado.
- **Si ya hay un control dentro**, el teclado llega por ahí y el contenedor
  no necesita nada.

Con todo eso, el grupo 1 pasó de **60 a 6**.

### Un falso positivo que conviene no repetir

Una versión anterior de este informe decía que `tp-select.js` era *"el más
grave y el único que no es mecánico: un desplegable propio inoperable sin
ratón"*. **Era falso.** Ya tiene flechas arriba/abajo, Enter y Espacio para
elegir, `aria-haspopup="listbox"`, `aria-expanded` mantenido y opciones con
`role="option"` y `aria-selected`. El escáner vio los `<div>` de las opciones
sin mirar que el teclado entra por el botón, que es el patrón ARIA correcto.

### Cómo repetir la revisión

```bash
python docs/accesibilidad-ui-v2.src/escaner.py              # los siete grupos
python docs/accesibilidad-ui-v2.src/control-o-contenedor.py # grupo 1, fino
```

**Lo que más vale de todo esto:** en esta aplicación el marcado vive en
cadenas de JavaScript tanto como en los blades. Cualquier herramienta que
solo mire los `.blade.php` se deja fuera las listas, las tablas y todos los
menús. Y ningún escáner dice si la navegación con teclado *funciona*: eso
necesita a alguien recorriendo la interfaz con Tab, y encaja en la Fase 1 de
pruebas.
