# Accesibilidad · UI v2 · botones y elementos interactivos

Revisión del 1-oct-2026 sobre `resources/views/ui/v2` (52 blades),
`resources/assets/ui-v2/js` (46 ficheros) y `resources/assets/ui-v2/css`
(23 ficheros). Marco de reglas: skill `ui-ux-pro-max`, prioridades 1 y 2,
contrastadas con WCAG 2.2 AA.

El escaneo cubre las dos fuentes de marcado: los blades **y las cadenas de
JavaScript**, que en esta aplicación generan la mayoría de los controles.

> **Estado: cerrado el 1-oct-2026.** Siete de los ocho grupos corregidos.
> Lo que queda está **aplazado a propósito**, con el criterio explicado en
> *Por qué se para aquí*.

## Dónde estamos

| # | Problema | Encontrados | Pendientes | Severidad |
|---|----------|------------:|-----------:|-----------|
| 1 | Elemento no interactivo con click | 6 | **0** | Crítico |
| 2 | Campo de formulario sin etiqueta | 84 | **49** | Aplazado |
| 3 | Icono decorativo sin `aria-hidden` | 56 | **0** | Medio |
| 4 | Conmutador sin estado expuesto | 17 | **4** | Aplazado |
| 5 | `prefers-reduced-motion` sin respetar | 13 ficheros | **0** | Medio |
| 6 | Destino táctil por debajo de 24×24 px | 3 | **0** | Alto |
| 7 | Foco sin indicador visible | 2 | **0** | Crítico |
| 8 | Botón de solo icono sin nombre accesible | 2 | **0** | Crítico |

**53 pendientes de 172.** Lo corregido está en nueve commits, de
`4f0fb712` a `c344d5d0`.

## Por qué se para aquí

Lo corregido no era "accesibilidad" en abstracto: era cosas que **afectaban
a todo el mundo**. El menú de tres puntos de cada vehículo medía 22 px, que
falla en móvil para cualquiera. La paleta de comandos no mostraba el foco,
así que quien navega con Tab iba a ciegas. Las filas de reportes no
respondían a Enter. Y `prefers-reduced-motion` lo activa gente con mareo o
migraña, que es mucho más común que la ceguera.

Lo que queda —49 etiquetas de campo y la rejilla— **solo lo nota alguien
que use lector de pantalla**, y está en pantallas de configuración
ocasional: el modal de sensores lo toca quien da de alta equipos, muy de
vez en cuando. Para un panel de operaciones de flota, el coste de acertar
con 49 textos técnicos no se justifica hoy.

**Esto cambia si aparece un cliente del sector público.** La plataforma ya
reporta a OSINERGMIN, SUTRAN y MTC, así que opera en transporte regulado.
En Perú, la Ley 29973 y la NTP que adopta WCAG pueden aparecer en un pliego
de licitación. Si eso entra en el horizonte, estos 53 pasan de prescindible
a requisito, y conviene hacerlos con calma antes y no con prisa durante un
concurso.

Todo queda localizado por fichero y línea más abajo: retomarlo es abrir este
documento, no volver a investigar.

---

## Lo que queda

### 4 · Conmutador sin estado expuesto · 4 casos · ALTO

Solo queda la **rejilla del horario de alertas** (`alerts.js:920`, `:925`,
`:942`, `:961`). Es la única pieza que necesita diseño, no un atributo.

Son **672 celdas** — 96 franjas de 15 minutos × 7 días —, así que hacerlas
tabulables una a una está descartado: el patrón correcto es `role="grid"`
con `role="row"` y `role="gridcell"`, tabindex itinerante y navegación con
flechas. Unas 3 o 4 horas, y toca un formulario que guarda datos.

Lo que **sí** está ya resuelto ahí, comprobado al mirarlo:

- **La alternativa al arrastre existe.** Se pinta arrastrando, pero un click
  simple en una celda la cambia, y hay un botón por día más cuatro presets
  (limpiar, siempre, laborables, fin de semana). El criterio de arrastre de
  WCAG 2.2 queda cubierto.
- **Hay camino de teclado grueso**: los botones de día y los presets son
  `<button>` de verdad. Lo que falta es la celda individual.

De los 17 originales, nueve se corrigieron y **dos eran código muerto**:

| Dónde | Qué pasa |
|-------|----------|
| `fleet.js:899` | `querySelectorAll("[data-status]")` para "reflejar el botón activo del segmentado". Ese segmentado ya no existe: la página filtra con `<select id="tp-fleet-status">`, y los únicos `data-status` del proyecto están en `live.js` y son los chips de estado del vehículo |
| `live.js:3311` y `:3344` | operan sobre `.tp-live__paneltab`, que no se pinta en ningún sitio. Los paneles se cambian desde los enlaces del rail, más abajo en la misma función |

No se han borrado: eliminar código es decisión del equipo, y conviene mirar
antes si alguien lo dejó a medio migrar.

### 2 · Campo de formulario sin etiqueta · 49 casos · APLAZADO

En esta base **no hay un solo `<label for>`**: 129 `<label>` envuelven su
campo —eso vale— y 90 `<span class="tp-form__label">` son texto visible sin
asociar. De ahí salen todos estos.

Los 35 que se podían resolver sin interpretar ya están hechos. Los 49 que
quedan se reparten así:

| Dónde | Casos | Qué hace falta |
|-------|------:|----------------|
| `sensor-form.blade.php` | 18 | Son campos técnicos —inicio y cantidad de un bitcut, operador y valor de una condición— donde el nombre tiene que ser preciso |
| `poi-form`, `principal`, y 20 blades más | 31 | Pantallas de configuración ocasional |

De esos 49, **5** tienen un `<span>` encima que nombra una sección con **dos
controles** (un operador y un valor): usar ese texto para ambos confunde más
que ayuda, y uno de ellos es un `&nbsp;` que solo separa.

**Qué hace falta.** Decidir el texto de cada campo, que es trabajo de quien
conozca el dominio o de rescatar los equivalentes del sistema clásico. El
script `campos.py` los clasifica y da fichero y línea.

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
