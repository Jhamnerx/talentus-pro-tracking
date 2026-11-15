# QA UI v2 con Playwright · 14-nov-2026

- **Entorno:** `http://talentus-pro-tracking.test` (Laragon, copia de dev), rama `nuevo-tema` con la copia de trabajo de ese momento.
- **Cuenta:** administrador (`group_id` 1), sesión iniciada por el usuario.
- **Navegador:** Chromium de Playwright, a 1440 × 900 y también a 1024, 768 y 375 px de ancho.
- **Método:**
  - **Barrido automático** de las 18 páginas accesibles en cinco combinaciones: 1440 claro y oscuro, 1024 claro compacto, 768 oscuro y 375 claro. Por cada página guarda:
    - una captura;
    - los errores de consola;
    - las peticiones 4xx/5xx o fallidas;
    - si hay scroll horizontal y qué elementos se salen del viewport;
    - las claves i18n crudas (`ui.*`, `front.*`…).
  - **Escáner de contraste** de los textos visibles, en claro y oscuro (avisa por debajo de 3:1).
  - **Pruebas a mano** de:
    - el shell (paleta, bandeja, menú de cuenta);
    - el drawer y el kebab del vehículo;
    - las 13 pestañas de la ficha del vehículo;
    - la validación 422;
    - los filtros, la búsqueda, el orden y la paginación.
- **Causa:** cada fallo funcional se confirmó en el código antes de anotarlo. Donde pone «Causa», la línea se leyó; no es una suposición.
- **Sin cambios en los datos:**
  - no se guardó nada, no se enviaron comandos ni correos y no se borró nada;
  - las pruebas de 422 se hicieron con formularios de alta vacíos (el servidor los rechaza);
  - el tema y la densidad se cambiaron solo en el DOM (`data-theme` / `data-density`), sin tocar la preferencia de la cuenta.
- **Capturas:** en `docs/qa/img/qa-2026-11-14/`. Las del barrido se llaman `<página>-<ancho>-<tema>[-compact].png`. Las capturas a 1440 en claro llevan la barra de Debugbar abajo; el resto la tiene oculta.

> **Nota de método.** En la primera pasada en oscuro, los botones fantasma, el buscador del topbar y los selects parecían claros. Era un falso positivo: las transiciones CSS (`--dur-fast`) aún no habían terminado al hacer la captura. Las capturas en oscuro definitivas se tomaron con las transiciones desactivadas. **No es un fallo de la app.**

## Resumen

> **Correcciones 14-nov-2026:** los 4 ALTOS y los 9 MEDIOS están corregidos y verificados en el navegador (el panel del navegador de la app, con la misma sesión; el Chromium de Playwright lo tenía bloqueado la sesión de QA). Cada uno lleva su línea «Estado». Los BAJA y los «ya conocido» siguen igual, salvo lo que se arregló de paso (se indica en S2 y F2).

| Severidad | Nº | Dónde |
|-----------|---:|-------|
| Bloqueante | 0 | — |
| Alta | 4 | paleta Ctrl+K, Flota en móvil, Eventos (filtros que se pierden), bandeja de notificaciones (hora) |
| Media | 9 | atajo «Ctrl K» flotante, orden de Flota, título de Monitoreo en oscuro, drawer (01-01-1970), tablero sin estado de carga, `aria-hidden` con foco en el drawer, `aria-activedescendant` duplicado, radios cuadrados, densidad compacta en Flota |
| Baja | 25 | textos, paginación, coherencia visual entre módulos, accesibilidad menor; 6 de Operaciones, Seguridad y Acciones de llamada |

Siguen sin cambios estos fallos ya conocidos (de `docs/QA-UI-V2-informe.md` y `docs/ui-v2-novedades-vs-legacy.md` §4):
- servidor de sockets caído (`:9001/socket.io` → `ERR_CONNECTION_REFUSED` en todas las páginas);
- número de vehículos distinto entre páginas (#8);
- unidades de Mantenimiento (#22);
- `aria-label="Command Palette"` y «Confirmar email» sin traducir.

No salió **ninguna clave i18n cruda** ni ningún 4xx/5xx en las 18 páginas, en ninguna de las cinco combinaciones. Ninguna página tiene scroll horizontal del documento a 375, 768, 1024 ni 1440.

---

## Shell (topbar, paleta, bandeja, menú)

### ALTA · S1 · Ctrl+K: Enter sobre un resultado de búsqueda abre «Ir al mapa (Live)» en vez del resultado
- **Estado: Corregido** (`resources/assets/ui-v2/js/command-palette.js:280` quita `is-active` de todos los ítems, también los de grupos ocultos; `:320` `activateActive()` elige solo entre los visibles). Verificado en el navegador (1440, claro): Ctrl+K → «T6G-611» → Enter abre `/ui/v2/live?device=219`.
- **Pasos:**
  1. Abrir `/ui/v2/reports` (o cualquier página).
  2. Pulsar Ctrl+K y escribir `T6G-611`.
  3. Esperar al resultado «T6G-611 TOYOTA ETIOS», que sale resaltado.
  4. Pulsar Enter.
- **Esperado:** se abre el vehículo, igual que al hacer clic en el resultado.
- **Observado:** la página navega a `/ui/v2/live` sin seleccionar el vehículo. En el DOM hay **dos** `.tp-cmdk__item.is-active`: la acción oculta «Ir al mapa (Live)» y el resultado visible.
- **Captura:** `palette-enter-before.png` (antes de Enter, con el resultado resaltado) y `palette-enter-vehicle.png` (después: mapa general, sin vehículo).
- **Causa:**
  - `resources/assets/ui-v2/js/command-palette.js:307`: `activateActive()` coge el **primer** `.tp-cmdk__item.is-active` del panel, sin mirar si es visible.
  - `setActive()` (`:272-284`) recorre solo los ítems de grupos visibles (`getItems()`), así que el ítem de acciones que quedó activo al abrir la paleta conserva `is-active` cuando su grupo se oculta.
- Con el ratón funciona; falla con el teclado, que es el uso principal de la paleta.

### ALTA · S2 · La bandeja de notificaciones muestra las horas 5 h antes que el resto de la app
- **Estado: Corregido** (`app/Http/Controllers/Ui/V2/ShellController.php:187` manda el instante desde `events.time` en UTC, no `created_at`; `:195` `time` con `human()`, que sí convierte a la zona del usuario — `format()` lo dejaba en UTC y salía 00:13; `resources/assets/ui-v2/js/notifications.js:78` relativo con `Intl.RelativeTimeFormat` en el idioma de la página y, pasadas 24 h, la hora del servidor). Verificado: la API da `2026-11-08T00:13:02+00:00` / «07-11-2026 19:13:02», la misma hora que Eventos. También arregla el «hace Xs» fijo en castellano y el formato distinto de V10 para la bandeja.
- **Pasos:** abrir la campana del topbar y comparar con `/ui/v2/events` o con el bloque «Últimas notificaciones» del tablero.
- **Esperado:** la misma hora en todas partes (la alerta de AXK-873 MARIO SAMAN es de las **19:13** según Eventos, el tablero y la BD).
- **Observado:** la bandeja dice «7/11/2026, **14:13:02**». Además, el formato no coincide con el resto («2026-11-07 19:13:02», «07-11-2026 19:13:31»).
- **Captura:** `shell-notifications.png` (bandeja, 14:13) y `events-375-light.png` (Eventos, 19:13).
- **Causa:**
  - `app/Http/Controllers/Ui/V2/ShellController.php:187` manda `created_at->toIso8601String()`. La columna guarda hora local de Lima (19:13), pero la app tiene `timezone = UTC`, así que sale `…T19:13:02+00:00`.
  - `resources/assets/ui-v2/js/notifications.js:70-75` (`fmtTime`) hace `new Date(iso).toLocaleString()` y el navegador (UTC-5) le resta otras 5 h.
  - El mismo payload ya trae `time`, formateado con la zona del usuario (`:189`).
- **Relacionado (bajo):** `fmtTime` tiene «hace Xs / m / h» fijo en castellano.

### MEDIA · S3 · El atajo «Ctrl K» flota a media altura en el borde derecho, en todas las páginas y anchos
- **Estado: Corregido** (`resources/assets/ui-v2/css/shell.css:50`: `.tp-topbar__kbd` en línea dentro del botón, sin `position:absolute` ni caja propia). Verificado a 1440 y 375: el atajo queda dentro del botón de búsqueda.
- **Pasos:** abrir cualquier página v2 a cualquier ancho y mirar el borde derecho a ~440 px del borde superior.
- **Esperado:** las teclas «Ctrl» «K» dentro del botón de búsqueda del topbar, o ocultas.
- **Observado:** una cajita «Ctrl | K» flota sobre el contenido a la derecha: encima de la tabla de Flota, de la columna «Protocolo», del kebab de Mantenimiento o de la celda de alertas en móvil. A 1440 está en `left 1364, top 438`; a 375, en `left 299, top 438`.
- **Captura:** `fleet-1440-light.png` (encima de «teltonika»), `alerts-375-light.png`, `maintenance-1440-dark.png`.
- **Causa:**
  - `resources/assets/ui-v2/css/components.css:1495-1510` declara `.tp-topbar__kbd { position:absolute; right:8px; top:50% … }`, pensado para el antiguo `.tp-topbar__search` con `position:relative`.
  - El botón actual `.tp-topbar__search-btn` (`shell.css:21`) es `position:static`, así que el `kbd` toma como referencia `.tp-app-body` (toda la página).
  - `shell.css:50` redefine `.tp-topbar__kbd` sin anular el `position`.

### MEDIA · S4 · Paleta: `aria-activedescendant` apunta a un elemento oculto (IDs duplicados)
- **Estado: Corregido** (`resources/assets/ui-v2/js/command-palette.js:274` contador global de ids; ya no se numeran por posición). Verificado: `aria-activedescendant` = `tp-cmdk-opt-5`, apunta al resultado y no hay ids repetidos.
- **Pasos:** Ctrl+K → escribir «T6G-611» → leer `aria-activedescendant` de la caja de texto.
- **Esperado:** el ID del resultado resaltado, único en el documento.
- **Observado:** `aria-activedescendant="tp-cmdk-opt-0"`. Hay **2** elementos con ese ID: «Ir al mapa (Live)» (oculto) y el resultado. `getElementById` devuelve el oculto, así que un lector de pantalla anuncia la opción equivocada.
- **Causa:** `command-palette.js:282`: `if (!i.id) i.id = "tp-cmdk-opt-" + n;` numera según la posición entre los ítems **visibles**. Las acciones ya tienen `opt-0…` de la primera apertura, y los resultados reciben los mismos números.

### BAJA · S5 · Logo del topbar: «P» blanca invisible en claro y suelta en oscuro
- **Observado:** junto al nombre de la marca hay un `<span class="tp-rail__logo">P</span>` de 26 px con texto blanco y sin fondo. En claro deja un hueco en blanco; en oscuro se ve una «P» suelta.
- **Captura:** `fleet-1440-dark.png`, `monitor-1440-dark.png` (arriba a la izquierda).
- **Causa:** `resources/views/ui/v2/partials/topbar.blade.php:26` reutiliza la clase del rail (`components.css:1300`), que da el fondo en otro contexto.

### BAJA · S6 · Menú de cuenta: el email aparece dos veces y el foco no entra en el menú
- **Observado:**
  - la cabecera del menú repite «admin@talentustechnology.com» como nombre y como subtítulo;
  - al abrir con el ratón el foco se queda en el botón «AD». Es un `role="menu"`, así que debería pasar al primer `menuitem`.
- **Captura:** `shell-user-menu.png`.

### BAJA · S7 · «Cámara / Medios» en el rail no abre nada
- **Pasos:** pulsar «Cámara / Medios» en el rail.
- **Observado:** el enlace (`href="#"`) solo muestra el toast «Disponible próximamente en la nueva UI.». Las fotos del equipo existen por otras vías.
- **Captura:** `rail-camara-medios.png`.
- **Causa:** `resources/views/ui/v2/partials/rail.blade.php:28` (`'pending' => true`). Es una decisión de producto; conviene ocultarlo o enlazarlo.

### Ya conocido, sigue
- Socket `:9001` sin conexión (`ERR_CONNECTION_REFUSED` cada pocos segundos en la consola de todas las páginas).
- `aria-label="Command Palette"` en inglés y «Confirmar email» sin traducir (`ui-v2-novedades-vs-legacy.md` §4).

---

## Vehículos: Flota (`/ui/v2/fleet`)

### ALTA · F1 · En móvil (375 px) la tabla de vehículos mide 0 px de alto
- **Estado: Corregido** (`resources/assets/ui-v2/css/fleet.css:599`: `.tp-fleet` desplaza en vertical y la tarjeta de la tabla tiene `flex:1 0 0` con mínimo de 360 px; `:624` en móvil, 75vh). Verificado a 375 × 812: la tabla mide 470 px con scroll interno y se llega bajando; a 1440 × 900 la página no desplaza y la tabla sigue con scroll interno y cabecera fija.
- **Pasos:** abrir `/ui/v2/fleet` a 375 × 812.
- **Esperado:** la lista de vehículos visible (con scroll de página o de tarjeta).
- **Observado:** los filtros ocupan toda la pantalla y la tarjeta «Vehículos 1580 dispositivos» muestra solo la cabecera de la tabla y la paginación. `.tp-fleet__table-wrap` mide **0 px** y `main` tiene `overflow:hidden`, así que no hay forma de ver ni un vehículo.
- **Captura:** `fleet-375-light.png`.
- **Causa:**
  - `resources/assets/ui-v2/css/fleet.css:7-12`: `.tp-fleet` es una columna flex con `height:100%`.
  - `fleet.css:575-581`: la tabla dentro de la tarjeta lleva `flex:1; min-height:0`.
  - Las reglas móviles (`:335`, `:593`) no permiten el scroll de la página ni pliegan los filtros, así que la tabla se encoge a cero.

### MEDIA · F2 · Ordenar por columna no hace nada
- **Estado: Corregido** (`resources/assets/ui-v2/js/fleet.js:768` clic delegado en la tabla, recarga desde la página 1 con `sort`/`dir`; `app/Http/Controllers/Ui/V2/DevicesController.php:239` ordena en SQL sobre toda la flota por nombre, placa (vacías al final) o velocidad; se quitó el orden en cliente). «ESTADO» ya no se ofrece como ordenable: se calcula por vehículo y no se puede ordenar en SQL. Arreglado de paso: el checkbox «seleccionar todo» también perdía su manejador al repintar la cabecera (`fleet.js:795`), y una respuesta vieja ya no pisa a la nueva (`fleet.js:614`). Verificado: placa ↑ / ↓ piden `sort=plate&dir=asc|desc`, cambian filas y flecha.
- **Pasos:** en `/ui/v2/fleet`, hacer clic en la cabecera «NUMERO DE PLACA» (o «ESTADO»). El cursor es de enlace.
- **Esperado:** se reordena y cambia el indicador.
- **Observado:** no hay petición, el orden de las filas no cambia y la flecha sigue en «NOMBRES ↑».
- **Causa:**
  - `resources/assets/ui-v2/js/fleet.js:776-785` engancha los `click` a `thead th[data-sort]` **una sola vez**, al iniciar.
  - `fleet.js:274` vuelve a pintar la cabecera con `thead.innerHTML = …` al cargar las columnas, así que los `th` con manejador desaparecen.
  - Además, `sortItems` (`:96`) ordena solo la página actual (100 filas), aunque el comentario de `:272` hable del «sort del backend».

### MEDIA · F3 · La densidad compacta no cambia las filas de Flota
- **Estado: Corregido** (`resources/assets/ui-v2/css/fleet.css:592`, variante `[data-density="compact"]`). Verificado: la celda pasa de 57,6 a 41,6 px.
- **Observado:** las filas miden 58 px en cómoda y en compacta. En Alertas, Conductores y Mantenimiento pasan de 45 a 37 px.
- **Causa:** `fleet.css:588-591` fija `tbody td { padding-top:13px; padding-bottom:13px }` sin variante por `[data-density]`.

### BAJA · F4 · Paginación sin espacio y plural fijo
- **Observado:**
  - «Página1 / 16» en Flota;
  - «Pág1 / 1» en Gastos y Programaciones de ruta;
  - «1 dispositivos» al filtrar un solo vehículo.
- **Captura:** `fleet-1440-light.png` (abajo a la derecha), `route-schedules-1440-light.png`.
- **Causa:** `fleet.js:598`, `tp-expenses.js:56` y `tp-route-schedules.js:115` concatenan `tpT('ui.page'|'ui.page_short') + n` sin espacio. `tp-operations.js:243,420` sí lo pone.

### BAJA · F5 · Nombres con espacio inicial se ordenan los primeros
- **Observado:** «␠BLP-880 ECORTA», «␠CEE-797…» van antes de «-PRUEBA CAMARA» y de «320685» con «NOMBRES ↑».
- **Causa:** es un dato de origen (el nombre tiene espacios), pero la API (`/ui/v2/api/fleet`) y la lista podrían usar `trim()`. Pasa igual en `/ui/v2/api/history/devices`.

### Ya conocido, sigue (#8)
- **Cifras distintas:** Flota «1580 dispositivos», mapa en vivo «Todos 1580» (tras cargar; durante los primeros segundos muestra «1000» y «1000 desconectados» porque carga por lotes), y Tablero y Copiloto «FLOTA TOTAL 1597».

---

## Mapa en vivo y drawer del vehículo (`/ui/v2/live`)

### MEDIA · L1 · «Última señal 01-01-1970 00:00:00» en el drawer de un equipo sin conexión
- **Estado: Corregido** (`app/Http/Controllers/Ui/V2/DevicesController.php:3040`: `Device::time` ya viene formateado o es un texto de estado; se usa tal cual, como la lista). Verificado con el device 341: «Última señal: Desactivado». Lo «Relacionado» (SAT, duraciones en horas, «Encendido/Apagado» fijos) **no se tocó**.
- **Pasos:** en `/ui/v2/live`, abrir «-PRUEBA CAMARA» (device 341).
- **Esperado:** «—» o «Desactivado», igual que la lista y Flota.
- **Observado:**
  - la tarjeta «Objeto» dice **01-01-1970 00:00:00**;
  - el mismo panel muestra «SAT —» en el indicador y «Satélites 15» en Sensores;
  - «DETENIDO 3557h 22min 9s» y los «Eventos recientes» («1964h 16min 5s») no se pasan a días.
- **Captura:** `live-drawer-341.png`.
- **Causa:** `app/Http/Controllers/Ui/V2/DevicesController.php:3020`: `$device->time ? \Formatter::time()->format($device->time) : null`. Para este equipo `$device->time` es el texto «Desactivado» (lo confirma `/ui/v2/api/devices`), que es *truthy* y se formatea como epoch 0.
- **Relacionado:** «Encendido» y «Apagado» están escritos a mano en castellano en `:3013-3014`.

### MEDIA · L2 · Con el drawer cerrado, sus botones siguen en el orden de tabulación bajo `aria-hidden="true"`
- **Estado: Corregido** (`resources/views/ui/v2/pages/live.blade.php:715` nace con `inert`; `resources/assets/ui-v2/js/live.js:1171` lo quita al abrir; `:3675` lo pone al cerrar y devuelve el foco al mapa). Verificado: cerrado = `aria-hidden=true` + `inert`, foco en `#tp-live-map`.
- **Observado:** `#tp-live-drawer` cerrado tiene `aria-hidden="true"`, `opacity:0` y `pointer-events:none`, pero sus 3 botones («Cerrar», «Cambiar», «Centrar en mapa») siguen siendo enfocables con Tab. El foco se va a un elemento invisible y silenciado para los lectores de pantalla.
- **Causa:** `resources/views/ui/v2/pages/live.blade.php:702` y `resources/assets/ui-v2/js/live.js:1015` solo cambian `aria-hidden`. Hace falta `inert` (o `visibility:hidden`) mientras está cerrado.

### BAJA · L3 · Barra de widgets del drawer
- **Observado:**
  - los widgets «Servicios» y «Eventos recientes» tapan el chip «Actualizando cada 15s» y la atribución de Leaflet, y tienen su propio scroll horizontal;
  - el botón «Añadir servicio» es gris pizarra, cuando en el resto de la v2 la acción principal es azul de marca o turquesa.
- **Captura:** `live-drawer-341.png`.

### BAJA · L4 · Kebab del vehículo: «Video» sin tilde
- **Observado:** «Video en vivo», «Grabaciones de video» y la pestaña «Video» de la ficha. La documentación y el resto de la UI usan «Vídeo».
- **Captura:** `live-kebab-341.png`.
- El kebab funciona: menú completo, posición correcta y sin errores.

### BAJA · L5 · El filtro «Desconec…» sale cortado a 1440 px
- **Captura:** `live-1440-light.png`.

---

## Ficha del vehículo (modal, 13 pestañas)

Las 13 pestañas (Principal, Usuarios, Iconos, Información, Sensores, Servicios, Exactitud, Cola, Video, Servicios Web, Gastos, Cámaras e Historial) abren **sin errores de consola ni de red** con el equipo 341. Es un `<dialog>` nativo abierto con `showModal()` (`:modal` = `true`), así que el foco sí queda atrapado.

### MEDIA · D1 · Radios con forma de casilla en los modales
- **Estado: Corregido** (`resources/assets/ui-v2/css/device-modal.css:436`: `.tp-modal .tp-choice-input[type=radio]` con `border-radius:50%`). Verificado en «Enviar comando»: GPRS y SMS a 50 %.
- **Pasos:** «Enviar comando» → modal → Conexión «GPRS / SMS».
- **Observado:** el radio sin marcar (SMS) es un cuadrado con radio de 5 px, idéntico a una casilla. Solo el marcado sale redondo.
- **Captura:** `modal-send-command.png`.
- **Causa:** `resources/assets/ui-v2/css/device-modal.css:417-429`: `.tp-modal .tp-choice-input { border-radius:5px }` vale para todos los tipos. El `50%` de los radios solo está en `.tp-form input[type=radio]` y en `…[type=radio]:checked` (`:436`, `:462`).

### BAJA · D2 · Resumen de errores con el «?» de las ayudas
- **Pasos:** «Agregar objeto» → «Guardar» vacío.
- **Observado:**
  - la caja dice «**IMEI del dispositivo ? / ID de rastreador ?**: Necesario», porque se cuela el texto de los iconos de ayuda;
  - «Numero de SIM» (sin tilde) aparece como error, pero el campo está en otra pestaña y esa pestaña no se marca;
  - los campos con error no llevan `aria-invalid`, ni aquí ni en conductor ni en alerta.
- **Captura:** `validation-device-new.png`. La 422 por campo funciona: `POST /ui/v2/api/devices` → 422, con mensajes inline y el toast «Revisa los campos marcados.».

### BAJA · D3 · Pestaña Vídeo: controles de canales y botón «Detectar desde el equipo»
- **Observado:**
  - los selects de rol de los canales 3 a 8 siguen activos aunque su casilla esté desmarcada;
  - «Detectar desde el equipo» es un `tp-btn--ghost` de 28 px que parece texto suelto dentro de un campo.
- Conviven las pestañas «Video» (JT1078) y «Cámaras» (cámaras FTP), con nombres que se confunden.
- **Captura:** `device-modal-tab-8.png`, `device-modal-tab-11.png`.

### BAJA · D4 · Diálogos sin nombre accesible y pestañas en tres filas
- **Observado:**
  - los `<dialog>` de `tpModal` no tienen `aria-labelledby` (`resources/assets/ui-v2/js/modal.js:77`, `document.createElement("dialog")`);
  - en el modal de 600 px las 13 pestañas ocupan tres líneas, y la altura del modal salta de 382 a 840 px al cambiar de pestaña;
  - «Eliminar» (rojo) va pegado a «Guardar».
- **Captura:** `device-modal-principal.png`.

---

## Tablero (`/ui/v2/dashboard`)

### MEDIA · DB1 · Unos 8 s sin datos ni indicador de carga
- **Estado: Corregido** (`resources/views/ui/v2/pages/dashboard.blade.php:13` empieza con `is-booting` y `aria-busy`; `resources/assets/ui-v2/css/dashboard.css:721` esqueletos animados en KPIs y donuts, y «Cargando…» legible; `resources/assets/ui-v2/js/dashboard.js:534` los quita al llegar la primera respuesta). Verificado: 7,5 s con esqueletos y luego datos. La lentitud de la API (≈8 s) **no se tocó**.
- **Pasos:** abrir `/ui/v2/dashboard`.
- **Observado:**
  - `GET /ui/v2/api/dashboard?scope=all…` tarda **7,9 s**;
  - mientras tanto todos los KPI muestran «—», los tres donuts están vacíos y «Últimas notificaciones» dice «Cargando…» con contraste de 2,1:1;
  - no hay esqueletos ni spinner, y parece una página rota.
- **Captura:** `dashboard-1440-light.png` (a los 4 s) y `dashboard-1440-light-loaded-full.png` (cargado).

### BAJA · DB2 · La séptima tarjeta KPI queda sola en una fila
- **Observado:** «UTILIZACIÓN» ocupa sola la segunda fila de la rejilla de 6 columnas. El valor de los KPI va alineado a la izquierda y la etiqueta y el icono centrados.
- **Captura:** `dashboard-1440-light-loaded-full.png`.

---

## Eventos (`/ui/v2/events`)

### ALTA · E1 · Los cambios de búsqueda o filtro durante una carga se pierden (resultados que no corresponden al filtro)
- **Estado: Corregido** (`resources/assets/ui-v2/js/events.js:524`: cada recarga cancela la anterior con `AbortController` y solo pinta la última; el «cargar más» del scroll sí espera; el sondeo en vivo descarta respuestas de un filtro anterior). Verificado: «MARIO SAMAN» y a los 0,7 s «zzzzqq» → se piden las dos y la lista queda «Sin eventos…».
- **Pasos:**
  1. Abrir `/ui/v2/events`.
  2. Escribir «MARIO SAMAN» en «Buscar evento…».
  3. Mientras carga (la petición tarda ~3 s), cambiar el texto a «zzzzqq».
- **Esperado:** lista vacía para «zzzzqq».
- **Observado:**
  - la lista se queda con los eventos de MARIO SAMAN y la caja de texto dice «zzzzqq»;
  - en Red solo hay dos peticiones (`q=` y `q=MARIO SAMAN`): la de «zzzzqq» **nunca se envía**;
  - lo mismo pasa con los selects de tipo y vehículo.
- **Captura:** `events-search-empty.png`.
- **Causa:** `resources/assets/ui-v2/js/events.js:522`: `if (state.loading) return;` también descarta las recargas con `reset=true`. Los manejadores de `:738-745` llaman a `load(true)` sin reintentar.
- Distinto del #9 del informe anterior («la búsqueda no encuentra nada»): ahora sí busca, pero se queda con la consulta anterior.

### BAJA · E2 · Buscador sin icono y pie de lista apretado
- **Observado:**
  - el input tiene `padding-left:36px`, pero `.tp-input-wrap` no lleva lupa y queda un hueco;
  - el pie muestra «50 / eventos / (+)» en tres líneas, y «Borrar todo» (destructivo) tiene el mismo estilo que «Refrescar».
- **Captura:** `events-1024-light-compact.png`, `events-375-light.png`.

---

## Monitoreo de grupo (`/ui/v2/monitor`)

### MEDIA · M1 · El título es invisible en tema oscuro
- **Estado: Corregido** (`resources/assets/ui-v2/css/monitor.css:248` título con `--fg-primary` en oscuro; `resources/assets/ui-v2/css/live-ops.css:2520` lo mismo para `.tp-follow__title`; `monitor.css:194` ya tenía variante). Verificado: en oscuro `rgb(242,244,248)` sobre `rgb(13,16,24)`; en claro sigue el azul de marca.
- **Observado:** «Monitoreo de grupo» sale `#0e2157` sobre fondo casi negro, con un contraste de **1,24:1**.
- **Captura:** `monitor-1440-dark.png`.
- **Causa:** `resources/assets/ui-v2/css/monitor.css:23-27` (`color: var(--brand-800)`) no tiene la variante oscura que sí tiene el tablero (`dashboard.css:745`). Revisar también `monitor.css:194` y `live-ops.css:2493` (`.tp-follow__title`), que usan el mismo token.

### BAJA · M2 · «Elige hasta9vehículos»
- **Observado:** faltan los espacios alrededor del número. El `title` de los botones de tamaño dice «9vehículos, hasta3 columnas», con «columnas» escrito a mano.
- **Causa:** `resources/assets/ui-v2/js/monitor.js:490` y `:495` concatenan `tpT('ui.pick_up_to') + state.size + tpT('ui.monitor_grid_note')` sin espacios (las cadenas de `resources/lang/es/ui.php:688` y `:517` no los llevan).

---

## Alertas, Conductores, Comandos, Reportes, Gastos y Programaciones

La validación 422 funciona en las tres altas probadas en vacío:
- vehículo: `POST /ui/v2/api/devices` → 422;
- conductor: `POST /ui/v2/api/drivers` → 422;
- alerta: validación en el cliente con «Ponle un nombre a la regla.».

No hubo errores de consola ni de red al abrir:
- Ajustes de cuenta;
- Enviar comando;
- Reporte nuevo (`?create=1`);
- los paneles de Geocercas, Rutas y POI.

### BAJA · C1 · Alertas: chips de canal con contraste bajo
- **Observado:** «Sonido», «Push», «Color», «Popup» y «Siempre» en las reglas inactivas: 2,0:1 en claro y 2,6:1 en oscuro, con 10 px de letra.
- A 375 px los badges «Evento personalizado» se parten en dos cajas.
- **Captura:** `alerts-1440-light.png`, `alerts-375-light.png`.

### BAJA · C2 · Textos mezclados es/en
- «24 hour clock» y «SMS gateway» en Ajustes de cuenta (`modal-account-settings.png`).
- En Copiloto:
  - «Insights & recomendaciones» y «Ver dashboard» (`resources/lang/es/ui.php:214`);
  - el título «Co-Pilot IA» de la página (`resources/views/ui/v2/pages/copilot.blade.php:24`) frente a «Copiloto IA» en la pestaña del navegador.
- «No hay rutas .», con un espacio antes del punto, en el panel Rutas (`live-panel-routes.png`).

---

## Operaciones, Seguridad y Acciones de llamada (segunda pasada)

Se revisaron con un permiso `view` temporal concedido al admin, a petición del usuario:
- se insertaron tres filas en `user_permissions` (`operations`, `security` y `call_actions`, solo `view=1`) y se **borraron** al terminar;
- se comprobó que las tres páginas vuelven a dar 403.

Detalles de la pasada:
- **Barrido:** sin errores de consola, sin 4xx/5xx, sin claves crudas, sin scroll horizontal y sin textos por debajo de 3:1, a 1440 (claro y oscuro) y a 375 px.
- **Sin datos de operaciones:** el admin no tiene ninguna operación, y `/ui/v2/api/operations` devuelve 0. Por eso En vivo, Vueltas y Turno solo se vieron vacías, y no se pudo probar el reporte de turno ni el Excel.
- **Cambios de otra tarea:** Operaciones tenía cambios sin commit de otra tarea (`tp-operations.js`, `OperationsController.php`). Lo revisado es la copia de trabajo de ese momento.
- **Capturas:** `operations-1440-light.png`, `operations-tab-*.png`, `operations-375-light.png`, `security-1440-{light,dark}.png`, `security-375-light.png`, `call-actions-1440-light.png`.

### BAJA · O1 · El botón de filtrar dice «Búsqueda» (sustantivo) en vez de «Buscar»
- **Dónde:** Seguridad (`#tp-sec-run`) y la pestaña Vueltas de Operaciones (`#tp-ops-run`).
- **Causa:** usan `trans('front.search')`, que en castellano es «Búsqueda» (`resources/lang/es/front.php:1164`). Está en `resources/views/ui/v2/pages/security.blade.php:67` y `operations.blade.php:141`. En el resto de la v2 la acción equivalente es «Aplicar», «Mostrar historial» o «Generar».

### BAJA · O2 · Operaciones › Vueltas: «Elige el rango de fechas.» con las fechas ya puestas
- **Pasos:** abrir la pestaña Vueltas. «Desde» y «Hasta» ya vienen con el día de hoy.
- **Observado:** la tabla dice «Elige el rango de fechas.» hasta que se pulsa el botón, y la paginación muestra «—». El mensaje debería pedir que se pulse buscar, o la tabla debería cargarse con las fechas por defecto.
- **Captura:** `operations-tab-vueltas.png`. El texto está en `resources/lang/es/ui.php:679` (`pick_date_range`).

### BAJA · O3 · Operaciones › Turno: el select «Operación» sale vacío y estrecho
- **Observado:** el select `#tp-ops-shift-operation` (`operations.blade.php:230`) mide unos 60 px, no tiene texto de ejemplo y no muestra ninguna opción; la etiqueta «Fecha» queda pegada a «Operación». En En vivo, el mismo selector tiene el texto «Elige una operación para ver sus vehículos.».
- «Exportar Excel» está desactivado sin explicar por qué.
- **Captura:** `operations-tab-turno.png`.

### BAJA · O4 · Seguridad: la columna «Antigüedad (min)» queda cortada
- **Observado:**
  - la tabla «Estado actual» (`.tp-sec__wrap`) mide 840 px dentro de una tarjeta de 651, y los valores salen cortados («9…», «510.:»);
  - la barra de scroll horizontal está al final de un contenedor con scroll vertical, así que no se ve hasta bajar del todo;
  - la columna «TIPO» sale «—» en todos los vehículos (el admin no tiene clasificación: es correcto, pero ocupa sitio).
- **Captura:** `security-1440-light.png`.

### BAJA · O5 · Acciones de llamada: la cabecera «PARAR EN»
- **Observado:** la columna de la hora de la llamada se titula «PARAR EN».
- **Causa:** `resources/views/ui/v2/pages/call-actions.blade.php:49` usa `validation.attributes.called_at`, traducida como «Parar en» (`resources/lang/es/validation.php:697`). Debería decir algo como «Llamado el» u «Hora de la llamada».

### BAJA · O6 · Iconos repetidos en el rail
- **Observado:**
  - Seguridad usa la misma campana que Alertas;
  - Operaciones usa el mismo icono que el elemento de Copiloto;
  - con estos tres módulos el rail tiene 22 entradas y a 900 px de alto se corta abajo.
- Se suma a V11.

---

## Coherencia visual entre módulos (frente a `tokens.css`)

Todas son **BAJA**, pero juntas son lo que más se nota al pasar de un módulo a otro:

| # | Inconsistencia | Ejemplos (capturas a 1440) |
|---|----------------|----------------------------|
| V1 | **Cabecera de página**: icono en cuadro tintado y título de 18 px (Reportes, Conductores, Gastos, Programaciones) / icono suelto y título más pequeño (Enviar comando) / sin icono y título grande (Vehículos) / título de 16 px (Monitoreo) | `reports-`, `commands-`, `fleet-`, `monitor-1440-light.png` |
| V2 | **Acción principal**: «+» solo icono (Reportes, Conductores, Gastos) / con texto («Nueva regla», «Agregar objeto», «Nueva programación») / dentro de la tarjeta a la izquierda (Programaciones) | `reports-`, `drivers-`, `route-schedules-1440-light.png` |
| V3 | **Color del botón principal**: turquesa `--accent` (Agregar objeto, Mostrar historial, Enviar comando) / azul marino de marca (Guardar, Preguntar, Aplicar) / gris pizarra (Añadir servicio) | `device-modal-tab-11.png` («Agregar cámara» turquesa junto a «Guardar» azul marino) |
| V4 | **Altura de botón**: 28 px (`tp-btn--sm`: Columnas, Exportar, Cancelar y Enviar del modal de comando) / 36 px («+» de Conductores, Guardar de la ficha) | — |
| V5 | **Pestañas**, cuatro estilos: subrayado y texto (ficha del vehículo) / anchas en toda la fila (Enviar comando) / texto plano (Reportes) / píldoras (Ajustes de cuenta, Programaciones) | `modal-account-settings.png`, `commands-1440-light.png` |
| V6 | **Cabecera de tabla**: MAYÚSCULAS grises de 11 px (Flota, Reportes, Conductores) / tipo título en negrita oscura (Enviar comando, tabla de Cámaras) | `commands-1440-light.png` |
| V7 | **Estado vacío**: «No encontrado» / «Sin gastos.» / «Todavía no hay programaciones.» / «Sin datos» / «Sin geocercas» | varias |
| V8 | **Valor vacío en celdas**: «-» (Mantenimiento, Conductores) / «—» (Flota, drawer) | `maintenance-1440-dark.png` |
| V9 | **Orden del pie de modal**: primario a la izquierda (ficha: Guardar · Cancelar · Eliminar) / a la derecha (Enviar comando: Cancelar · Enviar) / cuatro botones (Reporte: Generar · Guardar · Nuevo · Cancelar) | `device-modal-principal.png`, `modal-send-command.png` |
| V10 | **Formato de fecha**: «2026-11-07 19:13:02» (Eventos) / «07-11-2026 19:13:31» (Flota) / «7/11/2026, 14:13:02» (bandeja) / «11-07 19:13» (tablero) | — |
| V11 | Rail: **el mismo icono de reloj** para Historial y Programaciones de ruta | barra lateral |
| V12 | Tema oscuro: **la esquina del scroll** de las tablas sale blanca | `fleet-768-dark.png` (abajo a la derecha) |

---

## Lo que no se pudo revisar y por qué

| Qué | Por qué |
|-----|---------|
| **Operaciones con datos** (reporte de turno, Excel, recálculo, crear o editar operaciones) | Se revisaron en una segunda pasada con un `view` temporal (ver la sección Operaciones). El admin no tiene operaciones y el permiso solo era de lectura, así que no se vio ningún dato ni ningún formulario de edición. Las capturas `*-1440-403.png` son de antes de conceder el permiso. |
| **Historial con datos** y marcas de combustible | En esta copia el device 219 ya no tiene tabla de posiciones en ninguna base: ni `positions_219` en `gpswox_traccar` ni en las demás. `/api/history` devuelve 200 con 0 posiciones y «Sin datos» (`history-219.png`). La memoria del proyecto está desactualizada en este punto. |
| Vídeo en vivo, grabaciones y descargas reales | No hay cámara ni fork de Traccar en local (igual que en `docs/video`). Solo se revisaron la ficha, el kebab y el formulario. |
| Notificaciones en tiempo real, toasts de socket, chat en vivo | El servidor de sockets (`:9001`) no está levantado. |
| Idioma inglés | Cambiar el idioma guarda la preferencia en la cuenta (`/my_account_settings/change_language/en`). Se revisaron las claves crudas en castellano (ninguna) y los textos fijos en el código. |
| Guardar, editar y borrar (alertas, geocercas, POIs, rutas, gastos, tareas, comandos) | Por la regla de no tocar datos solo se probaron las altas vacías (422) y la apertura de formularios. Los fallos de guardado del informe del 29-sep (#2, #3, #5, #6, #11) **no se han vuelto a probar**. |
| Dibujo de geocercas, POI y rutas en el mapa | Exige crear el registro para terminar el flujo. |
| Widgets del drawer (configurar) y edición de servicios | Solo se vieron en modo lectura. |
| Paleta, menú y modales con lector de pantalla real | Solo se revisaron atributos ARIA y foco con teclado en Chromium. |
| Safari y Firefox | Solo Chromium. |

## Limpieza
- No se creó ni modificó ningún registro, salvo el permiso temporal de la segunda pasada: tres filas `view` en `user_permissions` para el usuario 1, insertadas y borradas. Se comprobó que no queda ninguna y que las páginas vuelven a dar 403.
- No se cambiaron la preferencia de tema ni la de densidad de la cuenta.
- Scripts del barrido: `.playwright-mcp/sweep.js` y `.playwright-mcp/contrast.js`, en una carpeta ignorada por git. Sirven para repetir la pasada.
