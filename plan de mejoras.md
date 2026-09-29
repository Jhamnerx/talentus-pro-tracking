# Plan de implementación y salida a producción

**Plataforma de Rastreo · Talentus Technology**
Estado a **29 de septiembre de 2026** · rama `nuevo-tema`

---

## Resumen ejecutivo

El plan de mejoras aprobado en agosto (puntos 8 a 15) está **cerrado: los seis
puntos se implementaron**, más el incidente de WiseTrack que iba fuera de plan.
En paralelo se completó la migración de la interfaz a la versión 2 y la
actualización del núcleo a GPSWox 3.7.13 sobre Laravel 9.

| Concepto                                    | Cifra                     |
| ------------------------------------------- | ------------------------- |
| Periodo de trabajo                          | 31-may → 29-sep-2026      |
| Commits por delante de producción           | 298                       |
| Ficheros tocados                            | 1.045                     |
| Líneas añadidas / eliminadas                | +185.846 / −30.058        |
| Migraciones de base de datos por aplicar    | 31                        |
| Puntos del plan 8–15 entregados             | 6 de 6                    |
| Funcionalidades entregadas (inventario §1.2)| 104, en 11 áreas          |
| Pendientes (aplazados por decisión)         | 2 — vídeo a bordo y ERP   |

**Fecha propuesta de salida a producción: martes 10 de noviembre de 2026.**
La anterior era el 3; se corre una semana porque entre el 28 y el 29 de
septiembre entraron tres funcionalidades nuevas (§1.6) después de la fecha de
congelación, por decisión expresa de dirección.

El mayor riesgo operativo **hoy** no es técnico: hay **241 commits sin subir al
repositorio remoto**. Todo ese trabajo existe en una sola máquina. Resolverlo
es lo primero del calendario y no admite espera.

---

## 1 · Lo entregado

### 1.1 · El plan de mejoras 8–15 — cerrado

Los seis puntos aprobados, con la fecha en que quedaron en la rama:

| #  | Mejora                                   | Fecha      | Commit     |
| -- | ---------------------------------------- | ---------- | ---------- |
| —  | **WiseTrack** (incidente, fuera de plan) | 22-ago     | `cba77cff` |
| 14 | Plantillas de alerta y aplicación masiva | 23-ago     | `a673b9d4` |
| 15 | Cabecera y pie de los reportes           | 23-ago     | `81a51614` |
| 11 | Panel de cumplimiento regulatorio        | 23–24-ago  | `41145667` |
| 8  | Ampliar el registro de cambios           | 24-ago     | `e3d6d91b` |
| 12 | Puntuación de conducción                 | 24-ago     | `bb2fd989` |
| 10 | Costo por kilómetro                      | 24-ago     | `eeff713e` |

Los puntos **9** (equipos sin registrar) y **13** (plantillas de alta de
vehículo) se descartaron por decisión del cliente y siguen descartados.

La especificación técnica original de cada punto se conserva íntegra en el
**anexo** al final de este documento.

### 1.2 · Inventario de funcionalidades entregadas

Todo lo que sigue está en la rama y no en producción. Se agrupa por módulo, no
por fecha; la cronología va en §1.3.

#### Mapa y operación en vivo

| Funcionalidad                                                           | Fecha  |
| ----------------------------------------------------------------------- | ------ |
| Mapa en vivo con panel lateral, tarjetas de vehículo y menú de acciones | jun    |
| **Cambio de motor de mapas: MapLibre → Leaflet**, el mismo del sistema clásico | 29-jun |
| Catálogo completo de capas base (Google, OSM, Carto, LocationIQ…)        | 29-jun |
| Iconos reales de marcador por tipo, nombres, cola (tail) y agrupación    | 28-jun |
| Filtro de estado con datos autoritativos del servidor                    | 28-jun |
| Carga de todas las páginas de vehículos (se quitó el tope de 1.000)      | 28-jun |
| Agrupación de la lista, «Sin grupo» siempre primero                      | jul    |
| Widgets del vehículo: panel lateral + franja inferior configurable       | jun    |
| Widgets de Objeto, Combustible (gráfico 24 h) y Comando GPRS             | 25-jul |
| **Seguir**: ventana flotante con mini-mapa                               | 18-ago |
| Preferencias de marcador aplicadas desde Ajustes                         | 24-jul |
| Unidades sin transmitir señaladas en el mapa                             | 28-ago |
| **Tiempo real por socket** y posiciones cada 5 s                         | 21-sep |

#### Monitoreo de grupo — módulo nuevo

| Funcionalidad                                                  | Fecha  |
| -------------------------------------------------------------- | ------ |
| Grilla de mini-mapas en vivo, uno por vehículo                  | 18-ago |
| Tamaño de cuadrícula configurable de 2 a 32 celdas              | 20-sep |

#### Flota

| Funcionalidad                                                     | Fecha  |
| ----------------------------------------------------------------- | ------ |
| Cabecera, panel de filtros y tabla en tarjeta                      | 20-ago |
| Columnas configurables (compartidas con la lista clásica)          | 19-ago |
| Columnas de sensor con rangos de color                             | 20-ago |
| Columna Dirección y refresco automático                            | 20-ago |
| Estados Lookup como filtros                                        | 19-ago |
| Icono asignado a cada vehículo en la lista                         | 20-sep |
| Copiar sensores de un vehículo a otros (tres modos)                | 20-ago |

#### Geocercas, POI y rutas

| Funcionalidad                                                        | Fecha  |
| -------------------------------------------------------------------- | ------ |
| POI: CRUD nativo sobre el mapa, marcadores arrastrables, por grupos   | 28-jun |
| Geocercas: dibujo de polígono y círculo, vértices editables           | 28-jun |
| Límite de velocidad por **tipo y estado de vehículo** en la geocerca  | 28-jun |
| **Geocerca de corredor** (línea con anchura)                          | 5-sep  |
| CRUD de grupos de geocercas y de POI                                  | 19-ago |
| Rutas: CRUD completo                                                  | 21-jul |
| **Puntos de control, programaciones y reporte de adherencia**         | 5-sep  |
| Hitos kilométricos como capa compartida                               | 21-jul |

#### Alertas y eventos

| Funcionalidad                                                      | Fecha  |
| ------------------------------------------------------------------ | ------ |
| Modal de alerta completo, 6 pestañas, en paridad con el clásico     | 29-jul |
| Índice de reglas con menú de acciones                               | 29-jul |
| Alertas del vehículo con periodo de vigencia                        | 19-ago |
| Editar la alerta desde el menú del vehículo                         | 20-ago |
| **Plantillas de alerta** sembradas al dar de alta un usuario (§14)  | 23-ago |
| Tipo **«Alarma del equipo»** sobre el campo `alarm` de Traccar      | 22-ago |
| Tipo **umbral sobre el valor de un sensor**                         | 19-sep |
| Acción **«enviar reporte por correo»** con ventana del evento       | 5-sep  |
| Operador «incluye» y comodín de protocolo en eventos                | 22-ago |
| Página de Eventos con mapa, borrado masivo y filtro por fechas      | jun/ago|
| Notificaciones en vivo: emergente, sonido y aviso de escritorio     | 22-jul |
| Avisos del administrador (franjas y emergentes)                     | 19-ago |

#### Reportes

| Funcionalidad                                                       | Fecha  |
| ------------------------------------------------------------------- | ------ |
| Módulo rediseñado con pestañas y modal Generar/Guardar               | 22-jul |
| Franja horaria como control de dos manijas                           | 22-jul |
| Periodo que termina en 23:59 (día completo) y rangos rápidos         | 23-jul |
| Corrección de un fallo heredado en `setPeriodTimeTo`                 | 22-jul |
| El modal recupera la forma y las opciones del sistema clásico        | 26-ago |
| **Cabecera y pie en todos los formatos** (§15)                       | 23-ago |
| Periodo mostrado en la hora del usuario                              | ago    |
| **Reporte de conducción**: eventos bruscos por cada 100 km (§12)     | 24-ago |
| **Reporte de costo de operación** (§10)                              | 24-ago |
| **Reporte de adherencia a ruta** (tipo 909)                          | 5-sep  |

#### Tablero

| Funcionalidad                                                   | Fecha  |
| --------------------------------------------------------------- | ------ |
| Tablero fusionado: flota, conducción y alertas                   | 25-jul |
| Tarjetas de estado con anillo y leyenda                          | 25-jul |
| Rango por zona horaria del usuario, con fuente única de fechas    | 25-jul |
| Filtros de rango acelerados                                      | 25-jul |
| Bloques que faltaban y kilometraje por selección                  | 19-ago |
| **Ranking de conducción** con el mismo cálculo del reporte (§12)  | 24-ago |

#### Vehículo: ficha y gestión

| Funcionalidad                                                        | Fecha  |
| -------------------------------------------------------------------- | ------ |
| Modal del vehículo con todas las pestañas en paridad                  | jul    |
| Pestañas Sensores, Usuarios, Servicios Web, Servicios, Gastos, Cámaras| jul    |
| Iconos de sensor y selector en el mapeo de valores                    | 19-ago |
| Historial de mantenimientos                                           | 19-ago |
| Configurador del GPS en el alta, y **configuración remota por SMS**   | 19-ago |
| Beacons                                                               | 19-ago |
| Galería de medios del vehículo                                        | 19-ago |
| **Listas de verificación** (plantillas y ejecución por servicio)      | 19-ago |
| Estados de vehículo con etiqueta corta, color y cambiador rápido      | 29-jul |
| Tipos de trayecto                                                     | 19-ago |
| Bloqueo de motor: candado en la lista y comandos por plantilla        | 26-jul |
| **Costo por kilómetro** del vehículo (§10)                            | 24-ago |
| **Historial de cambios del vehículo** y más cobertura de auditoría (§8)| 24-ago |
| Vídeo a bordo: canales por vehículo y visor gateado (hasta 6)         | 20-sep |

#### Otros módulos migrados

| Módulo                                                      | Fecha  |
| ----------------------------------------------------------- | ------ |
| Servicios / Mantenimiento                                    | 21-jul |
| Enviar comando (hub completo)                                | 21-jul |
| Gastos                                                       | 27-jul |
| Reenvíos, y su asignación a grupos de objetos                | 27-jul |
| Compartir (Sharing) + página pública con identidad v2        | 22-jul |
| Conductores                                                  | 22-jul |
| Tareas, conjuntos, importación CSV/XLS y asignación masiva   | 23-jul |
| Chat y Acciones de llamada                                   | 25-jul |
| Grupos de objetos                                            | 23-jul |
| Ajustes de cuenta con todas las pestañas                     | 24-jul |
| Cuenta: categorías de medios y credenciales secundarias      | 19-ago |
| Cuenta: SMS de prueba y vaciado de la cola                   | 19-ago |
| **Panel de cumplimiento regulatorio** (§11)                  | 23-ago |

#### Plataforma y componentes propios

| Funcionalidad                                                   | Fecha  |
| --------------------------------------------------------------- | ------ |
| Tema desmarcado y canal de compilación con Tailwind              | 26-jun |
| `tp-select`: componente de selección propio                      | 22-jul |
| `tp-datepicker`: calendario propio con salto a mes y año         | 22-jul |
| `tp-socket`: capa de tiempo real                                 | 21-sep |
| Selector de vehículos compartido por todas las pantallas         | 20-sep |
| Modo claro y oscuro con tokens de diseño                         | jul    |
| **Tema `admin-v2`** para el panel de administración              | 19-sep |
| Preferencia de interfaz persistida por usuario                   | 18-ago |
| Selector de idioma en la interfaz                                | 19-ago |
| Login moderno con la paleta v2                                   | 18-ago |
| Confirmación de correo por código único                          | 18-ago |
| Control de acceso del rail en paridad con el menú clásico        | 23-jul |

#### Núcleo, integraciones y datos

| Funcionalidad                                                        | Fecha  |
| -------------------------------------------------------------------- | ------ |
| **Actualización a GPSWox 3.7.13 sobre Laravel 9** (fases A, B1–B11)   | 1–2-sep|
| **2FA de Google** completo y middleware de teléfono                   | 2-sep  |
| Proveedor de correo **Gmail (OAuth2)**                                | 2-sep  |
| `Reply-To` configurable, igual para los cinco proveedores             | 27-ago |
| Normalización de la celda GSM y `apply_network_data`                  | 28-ago |
| **Índice en la cola de WiseTrack** (incidente en producción)          | 22-ago |
| Combustible: rellenos y robos sobre la curva, varias series a la vez  | 19-sep |
| Combustible: un reinicio del contador ya no borra el consumo          | 19-sep |

### 1.3 · Cronología del proyecto

| Periodo              | Hito                                                              | Commits |
| -------------------- | ----------------------------------------------------------------- | ------- |
| 31-may               | Arranque del tema nuevo: shell, páginas y modal de vehículo        | 2       |
| 26-jun → 29-jun      | Primera ola de migración de módulos                                | 55      |
| 21-jul → 29-jul      | Módulos operativos, Alertas con modal completo, estados de vehículo| 71      |
| 18-ago → 20-ago      | Olas 1 a 6: cierre de paridad con el sistema anterior              | 69      |
| 22-ago → 28-ago      | **Plan de mejoras 8–15** + correo, posiciones y eventos            | 33      |
| 1-sep → 2-sep        | **Actualización a GPSWox 3.7.13 y Laravel 9** (fases A y B1–B11)   | 18      |
| 5-sep                | Rutas con adherencia, geocerca de corredor, alerta por reporte     | 5       |
| 19-sep → 22-sep      | Tema del panel admin, telemetría de combustible y estabilización   | 24      |

### 1.4 · Actualización del núcleo (1–2 sep)

El sistema pasó de GPSWox 3.7.x a **3.7.13 sobre Laravel 9** en doce fases
documentadas (A, B1–B11): fusión de 40 archivos personalizados, consola y
reportes, alertas de expiración, entidades `Device` y `User`, rutas y
configuración, JS del mapa, vistas, idiomas, 2FA de Google y proveedor de
correo Gmail por OAuth2.

### 1.5 · Estabilización (19–22 sep)

Cuatro días de uso real destaparon **ocho defectos**, todos corregidos:

| Qué fallaba                                      | Alcance              | Fecha  |
| ------------------------------------------------ | -------------------- | ------ |
| El mapa clásico no cargaba (catálogo de mapas)    | Bloqueante           | 20-sep |
| El mapa clásico no cargaba (fichero sin empaquetar)| Bloqueante          | 21-sep |
| La lista de flota salía vacía (columna sin título)| Bloqueante           | 20-sep |
| Las fechas se enviaban en formato local → rechazo | Alto, 6 pantallas    | 22-sep |
| El botón de idioma sacaba al usuario de la v2     | Medio                | 20-sep |
| Modo oscuro: fila en blanco (24 reglas afectadas) | Medio, 9 pantallas   | 20-sep |
| «Ocioso» / «Inactivo» para el mismo estado        | Medio, terminología  | 20-sep |
| Widget de vídeo visible en vehículos sin cámara   | Medio                | 20-sep |

**Esta tabla es el argumento central del calendario que se propone abajo.**
Ocho defectos reales, tres de ellos capaces de dejar una pantalla inservible,
aparecieron con uso casual durante cuatro días. No hay suite de pruebas
automáticas en el proyecto, así que la única red disponible es una campaña de
pruebas manual y sistemática.

---

### 1.7 · Deuda saldada el 29 de septiembre (tarde)

Tres cosas que el propio plan señalaba como deuda, más un fallo que apareció
por el camino.

| Qué                                              | Commit     |
| ------------------------------------------------ | ---------- |
| `gulp sass-all` vuelve a compilar los 32 temas   | `4575f61f` |
| 24 claves de traducción que no resolvían          | `67aa02de` |
| Las 51 vistas de la v2, sin texto fijo            | `ccd4f223` |
| Los 12 controladores de la v2, sin texto fijo     | `e97087b0` |
| Puente de traducciones hacia el JS (no existía)   | `001af821` |
| El JS de la v2, sin texto fijo (1.274 cadenas)    | `7b27df00`, `db4ae1d0` |
| La página salía sin estilos con cuenta de manager | `34a5fc65` |
| Las 6 cuentas con el tema inválido, normalizadas   | (dato)     |

**`gulp sass-all`.** Fallaba con *Incompatible units: 'px' and 'vw'* porque
node-sass intenta resolver `min(86vw, 340px)` él mismo. Lo grave no era el
error sino dónde estaba: `admin-v2.scss` es el primero por orden alfabético,
así que la tarea moría ahí y **no se recompilaba ningún tema**. Todo el CSS
de `public/assets/css/` estaba viejo, no solo el de admin. Se comprobó que la
reconstrucción no pierde reglas comparando la salida cruda contra la
minificada de la misma fuente: las únicas diferencias son del minificador y
son intencionadas (comillas de `[attr=val]`, fusión de longhands en atajos,
descarte del `filter` de IE). De paso aparecieron dos temas,
`dark-tropical-blue` y `light-tropical-blue`, con fuente commiteada y CSS que
nunca se había generado.

**La traducción.** Era el punto más grande. Estado anterior: 36 de 51 vistas
usaban `trans()`, y el resto —más los controladores y **todo** el
JavaScript— llevaba el castellano escrito a pelo.

| Capa            | Cadenas | Resultado |
| --------------- | ------- | --------- |
| Claves rotas    | 24      | 427/427 resuelven en es y en en |
| Vistas Blade    | 106     | 0 literales fijos en las 51 vistas |
| Controladores   | 93      | 0 literales fijos en los 12 |
| JavaScript      | 1.274   | 0 literales fijos en los 35 ficheros |

Dos decisiones de fondo:

- Las cadenas propias viven en `resources/lang/{es,en}/ui.php` (835 claves) y
  no en `front.php`, que viene del upstream de GPSWox. Hay además un motivo
  de producto: **la v2 dice «vehículo» donde el legacy dice «dispositivo»**
  (47 contra 8 en las vistas; 111 contra 43 en los ficheros de idioma).
  Reusar `front.all_devices` habría revertido esa terminología.
- Donde el texto ya coincidía se reusa la clave del legacy, que está
  traducida a 40 idiomas. Son 149 claves de `front`, `global` y
  `validation.attributes`.

El JS necesitaba una pieza que **no existía**: un puente de traducciones. El
legacy sí lo tiene (`Frontend/Layouts/partials/trans.blade.php`), y esa
ausencia es justo la razón por la que el texto acabó incrustado en las
plantillas HTML que el JS construye. Ahora `partials/i18n.blade.php` publica
`window.tpLang` y un ayudante `tpT()`.

Por el camino apareció infraestructura a medias: seis ficheros
(`live-routes`, `history`, `commands-page`, `tp-commands`, `routes-table`,
`tp-services`) ya llamaban a `T(clave, "respaldo")` con 153 claves planas,
pero **nadie rellenaba `window.tpI18n`**, así que siempre devolvían el
castellano. Se rellena desde el puente y 122 de esas 153 resuelven sin tocar
esos ficheros.

**El fallo de estilos.** Al entrar con una cuenta de manager la página pedía
`/assets/css/airpatrol.css`, que da 404, y salía entera sin CSS. `airpatrol`
no es un tema de este repositorio: no tiene fuente SCSS, nunca tuvo CSS, y
tampoco figura en `config('tobuli.template_colors')`, así que el selector del
panel de admin ni lo muestra para poder corregirlo. Son 6 cuentas que lo
arrastran de una instalación anterior. `Appearance::getTemplateColor()`
resuelve ahora en cascada y devuelve el primer tema cuyo `.css` exista;
sirve para cualquier valor inválido, no solo para este.

**Lo que queda de esta tanda, no bloqueante:**

- El puente son 43 KB en cada página (~8 KB comprimido) porque manda las 835
  claves siempre. Si molesta, se parte por página.
- Unas 30 cadenas eran fragmentos de una concatenación (`"(máx"`,
  `"· límite"`). Se traducen como fragmento, que funciona pero no deja
  reordenar la frase. Pasarlas a marcadores exige reescribir cada sitio, como
  ya se hizo en `CopilotController`.
- El Co-Pilot reconoce la **pregunta** del usuario con expresiones regulares
  en castellano (`/(ralent|idle)/u`). Eso no es texto que se muestre sino
  reconocimiento de entrada; hacerlo multiidioma es otro trabajo.

---

### 1.6 · Entregado tras la fecha de congelación (28–29 sep)

Tres funcionalidades pedidas después del 28-sep, la fecha en que el plan
proponía congelar. Entraron por decisión de dirección, a cambio de correr la
salida una semana.

| Funcionalidad                                                        | Fecha  | Commit     |
| -------------------------------------------------------------------- | ------ | ---------- |
| Marcador coloreado por velocidad y por límite de geocerca             | 28-sep | `c3b69ae8` |
| **Tablero del vehículo** con agujas, adaptado a los sensores de cada equipo | 29-sep | `d356bff7` |
| Navegación en móvil: menú, lista de vehículos y panel lateral         | 29-sep | `44c47a00` |

Y cinco correcciones que salieron al construirlas:

| Qué fallaba                                                  | Alcance    | Commit     |
| ------------------------------------------------------------ | ---------- | ---------- |
| En móvil solo se veía el mapa: ni menú ni lista de vehículos  | Bloqueante | `44c47a00` |
| `shell.css` ocultaba el rail bajo 767 px con `display:none`   | Bloqueante | `f5f85868` |
| El botón de menú no enganchaba: el topbar aún no existía      | Bloqueante | `a2dc7bc3` |
| El panel lateral no se refrescaba: la velocidad se congelaba  | Alto       | `d356bff7` |
| Los contadores del mapa quedaban bajo las herramientas        | Medio      | `1e395f9c` |

Las tres bloqueantes eran **anteriores** a este trabajo: la navegación móvil
nunca había funcionado. La regla `data-mobile-filters` existía en el CSS desde
el principio y nada la activaba.

---

## 2 · Qué falta

### 2.1 · Aplazado por decisión del cliente

| # | Qué                          | Estado                                                                                   |
| - | ---------------------------- | ---------------------------------------------------------------------------------------- |
| 1 | **Vídeo a bordo**            | La interfaz está lista y gateada por vehículo (hasta 6 canales). Falta el decoder JT808/Howen. Hoy no hay equipos compatibles en la flota, así que no es verificable. |
| 2 | **Suscripciones y planes**   | Los pagos pasan al ERP de Talentus. No se migra el flujo de compra. A la espera de definir la integración. |

Ninguno de los dos bloquea la salida a producción.

### 2.2 · Fuera de alcance, por decisión

- **Administración**: sigue en el panel clásico, con el mismo control de acceso.
- **Autenticación y páginas públicas**: 13 módulos fuera del shell de usuario.

### 2.3 · Deuda que sí conviene atender antes de salir

| Qué                                                        | Por qué importa                                        |
| ---------------------------------------------------------- | ------------------------------------------------------ |
| **241 commits sin subir al remoto**                        | Todo el trabajo vive en una sola máquina               |
| **Sin suite de pruebas automáticas**                       | Cada regresión se detecta a mano o en producción       |
| ~~`gulp sass-all` roto~~ · **resuelto el 29-sep**          | Los 32 temas vuelven a compilar (`4575f61f`)           |
| ~~Traducción de la v2 incompleta~~ · **resuelta el 29-sep**| Vistas, controladores y JS pasan por `trans()`         |
| Cola de difusión sin worker (24 trabajos esperando)        | Los avisos emergentes no llegan si nadie procesa la cola|
| **`clean-css` 4.2.1 descarta las at-rules que no conoce**  | Toda `@container` desaparece del CSS compilado sin avisar |
| **Ocho cortes responsive distintos** entre hojas de estilo | 640, 720, 760, 767, 900, 960, 1100… las casi-coincidencias ya causaron un fallo real |

Las dos últimas se descubrieron el 29-sep y merecen una nota.

**El minificador se come las container queries.** Comprobado: entra
`@container x (max-width:1px){.a{color:red}}` y sale vacío. Cualquiera que
escriba una container query verá cómo desaparece en producción sin ningún
aviso. Hoy no hay ninguna viva, así que no rompe nada, pero es una trampa
puesta para el siguiente que lo intente. Se arregla actualizando
`gulp-clean-css` (3.10 → 4.x, que trae clean-css 5).

**Los cortes responsive no cuadran entre ficheros.** `shell.css` ocultaba el
rail a 767 px mientras `components.css` mostraba el botón de menú a 640: entre
esos dos anchos no había ni rail ni forma de abrirlo. Ya está corregido, pero
la causa de fondo —ocho breakpoints sueltos, con parejas a 6 px de distancia—
sigue ahí. Conviene fijarlos como tokens antes de que vuelva a pasar.

---

## 3 · Proceso de actualización

El despliegue no es «subir ficheros»: hay cambios de esquema y de dependencias.
El orden importa.

### 3.1 · Antes de la ventana

1. **Subir la rama al remoto** y etiquetar la versión candidata.
2. **Copia de seguridad completa** de las dos bases (`gpswox_web` y
   `gpswox_traccar`) y del directorio de subidas.
3. **Ensayo en preproducción** con una copia reciente de los datos reales:
   correr las 30 migraciones y medir cuánto tardan.
4. Confirmar que el servidor cumple los requisitos de **Laravel 9** (PHP 8.0+).

### 3.2 · Durante la ventana (estimada: 60–90 minutos)

| Paso | Acción                                                       |
| ---- | ------------------------------------------------------------ |
| 1    | Modo mantenimiento (`php artisan down`)                      |
| 2    | Detener los workers de cola y el servidor de sockets          |
| 3    | Desplegar el código y `composer install --no-dev`             |
| 4    | `php artisan migrate` — **30 migraciones**                   |
| 5    | Compilar assets (`gulp assets`) o desplegar los ya compilados |
| 6    | Limpiar cachés de configuración, rutas y vistas               |
| 7    | Arrancar workers y servidor de sockets                        |
| 8    | Salir de mantenimiento y verificar la lista de humo           |

### 3.3 · Vuelta atrás

El punto sin retorno son las migraciones. La vuelta atrás consiste en
restaurar la copia de la base y revertir la etiqueta de código. Por eso el paso
3.1.3 (ensayo con datos reales) no es opcional.

### 3.4 · Convivencia de las dos interfaces

La v2 y la interfaz clásica **conviven**: cada usuario tiene su preferencia
guardada y el botón «Versión anterior» sigue disponible. Eso permite un piloto
real sin obligar a nadie, y es la mejor red de seguridad del despliegue.

---

## 4 · Fases de pruebas

### Fase 0 · Consolidación — lun 5-oct a vie 9-oct

| Tarea                                                  | Criterio de salida                        |
| ------------------------------------------------------ | ----------------------------------------- |
| Subir los 241 commits al remoto                        | `origin/nuevo-tema` al día                |
| Congelar funcionalidades nuevas                        | Solo correcciones a partir de aquí        |
| ~~Reparar `gulp sass-all`~~ · hecho el 29-sep          | Los 32 temas compilan (`4575f61f`)        |
| Actualizar `gulp-clean-css` (deja de comerse `@container`) | Una container query de prueba sobrevive al build |
| Fijar los cortes responsive como tokens                | Un solo listado de breakpoints, sin parejas a 6 px |
| Levantar preproducción con copia de datos reales       | Entorno accesible al equipo               |
| Inventario de las 30 migraciones y su tiempo           | Duración medida de la ventana             |

### Fase 1 · Pruebas sistemáticas — lun 12-oct a vie 23-oct

Recorrido **módulo por módulo** con lista de verificación escrita. Cada módulo
se prueba en las dos combinaciones que ya han demostrado dar problemas: **tema
claro y oscuro**, y **los dos idiomas**.

| Bloque                                                        | Días |
| ------------------------------------------------------------- | ---- |
| Mapa en vivo, monitoreo de grupo, flota                        | 2    |
| Alertas, eventos, notificaciones y tiempo real                 | 2    |
| Reportes (los ~130 tipos, por muestreo dirigido)               | 2    |
| Historial, combustible y telemetría                            | 1    |
| Rutas, geocercas, POI y programaciones                         | 1    |
| Mantenimiento, gastos, tareas, conductores, chat               | 1    |
| Integraciones regulatorias (OSINERGMIN, SUTRAN, WiseTrack…)    | 1    |
| Roles y permisos: admin, gestor, operador, supervisor, demo    | 1    |

**Criterio de salida:** cero defectos bloqueantes abiertos; los de severidad
media, documentados y aceptados.

### Fase 2 · Piloto en producción — lun 26-oct a vie 6-nov

Un grupo reducido de usuarios reales trabaja en la v2 sobre datos de
producción, con la interfaz clásica como salida de emergencia.

| Semana | Alcance                                                |
| ------ | ------------------------------------------------------ |
| 1      | Equipo interno + 2 clientes voluntarios                |
| 2      | Ampliación a ~10 % de los usuarios activos             |

**Criterio de salida:** una semana completa sin incidencias bloqueantes y sin
peticiones de volver a la interfaz clásica por fallos.

### Fase 3 · Salida — martes 10-nov-2026

Ventana la noche del **lunes 2 al martes 3**. Martes, no viernes: deja cuatro
días hábiles por delante para atender lo que salga.

### Fase 4 · Estabilización — 10-nov a 20-nov

Atención prioritaria a incidencias y seguimiento de los indicadores del
despliegue.

---

## 5 · Calendario resumido

| Fase                       | Desde      | Hasta      | Duración  |
| -------------------------- | ---------- | ---------- | --------- |
| 0 · Consolidación          | lun 5-oct  | vie 9-oct  | 1 semana  |
| 1 · Pruebas sistemáticas   | lun 12-oct | vie 23-oct | 2 semanas |
| 2 · Piloto en producción   | lun 26-oct | vie 6-nov  | 2 semanas |
| 3 · **Salida a producción**| **mar 10-nov** | —      | 1 noche   |
| 4 · Estabilización         | mar 10-nov | vie 20-nov | 1,5 sem.  |

**El calendario asume dedicación continua y que la Fase 1 no destapa un defecto
estructural.** Si la Fase 1 encuentra algo de fondo, la fecha se mueve: es
preferible mover la fecha que salir con un defecto conocido.

---

## 6 · Riesgos

| Riesgo                                                   | Impacto | Mitigación                                                        |
| -------------------------------------------------------- | ------- | ----------------------------------------------------------------- |
| Los 241 commits viven en una sola máquina                 | Crítico | Fase 0, primer día                                                |
| Migraciones lentas sobre tablas grandes                   | Alto    | Ensayo en preproducción con datos reales (3.1.3)                  |
| Regresión no detectada por falta de pruebas automáticas   | Alto    | Fase 1 sistemática + piloto con vuelta atrás por usuario          |
| Laravel 9 exige PHP 8.0+ en el servidor                   | Alto    | Verificar antes de la ventana; bloquea el despliegue              |
| Posiciones en tablas por dispositivo (1.400+ vehículos)   | Medio   | No se tocan en esta entrega; solo verificar tras migrar           |
| ~~Traducción de la v2 incompleta~~ · resuelta el 29-sep    | —       | Vistas, controladores y JS pasan por `trans()` (§1.7)             |

---

## 7 · Decisiones que se necesitan

1. **¿Se confirma el 3 de noviembre** como fecha objetivo?
2. **¿Quiénes forman el grupo piloto** de la Fase 2?
3. **¿Hay entorno de preproducción** disponible, o hay que montarlo en Fase 0?
4. **¿Se acepta salir** sin vídeo a bordo ni integración con el ERP?
5. **¿Se aborda la traducción al inglés** antes de salir, o queda para después?

---
---

# Anexo · Especificación técnica original de los puntos 8 a 15

> Este anexo se conserva tal cual se aprobó el **20 de agosto de 2026**, cuando
> aún era una propuesta sin código. Todos sus puntos están hoy implementados
> (ver §1.1). Se mantiene como registro del análisis y de las decisiones
> tomadas, no como trabajo pendiente.

## Plan de mejoras — puntos 8 a 15 (propuesta original)

> Estado: **propuesta, sin código escrito**. Todo lo que sigue está contrastado
> contra la base de datos y el código a 20-ago-2026.
>
> Descartados por decisión del cliente: **9** (equipos sin registrar → se queda
> en el admin legacy) y **13** (plantillas de alta de vehículo → no viable, la
> copia de sensores ya cubre la necesidad).

---

### Resumen y orden propuesto

| #   | Mejora                                   | Esfuerzo | Bloqueos           | Orden  |
| --- | ---------------------------------------- | -------- | ------------------ | ------ |
| 14  | Plantillas de alerta y aplicación masiva | Medio    | —                  | **1º** |
| 11  | Panel de cumplimiento regulatorio        | Medio    | —                  | **2º** |
| 15  | Cabecera y pie de los reportes           | Bajo     | —                  | **3º** |
| 8   | Ampliar el registro de cambios           | Bajo     | —                  | **4º** |
| 12  | Puntuación de conducción                 | Medio    | Ver §12            | **5º** |
| 10  | Costo por kilómetro                      | Medio    | 1 pregunta abierta | **6º** |

Antes de todo eso va el **arreglo de WiseTrack** (índice + purga de fallidos),
aprobado y descrito en §11: es un incidente vivo, no una mejora.

El orden va por relación valor/riesgo, no por número.

---

### 14 · Plantillas de alerta y aplicación masiva

#### Lo que hay hoy

| Dato                        | Valor |
| --------------------------- | ----- |
| Alertas en la base          | 8.614 |
| Usuarios con alertas        | 773   |
| Alertas por usuario (media) | ~11   |

Los seis nombres más repetidos:

| Nombre              | Veces |
| ------------------- | ----- |
| Vehículo apagado    | 807   |
| Exceso de velocidad | 796   |
| Vehículo encendido  | 785   |
| Giro Brusco         | 772   |
| GNSS Jamming        | 770   |
| Jamming             | 769   |

Son ~4.700 alertas que repiten media docena de plantillas, creadas a mano
entrando como cada cliente. Es el punto con más retorno de los seis.

#### Qué se construye

1. **Catálogo de plantillas de alerta** (tabla nueva `alert_templates`): mismo
   contenido que una alerta —tipo, parámetros, canales, horarios— pero sin
   dueño ni vehículos.
2. **Aplicar plantilla a**: un usuario, varios usuarios, o todos los de un plan.
   Crea la alerta en cada destino y la asigna a sus vehículos según la regla
   elegida (todos / grupo / ninguno).
3. **Alertas por defecto del plan**: al crear un cliente nuevo, se le siembran
   las plantillas marcadas como predeterminadas de su plan.
4. **Pantalla de administración**: qué plantillas existen, a cuántos usuarios se
   han aplicado y cuáles están desactualizadas respecto a la plantilla.

#### "Todos los vehículos" ya existe — y funciona

Lo has pedido y resulta que la pieza está: `alerts.for_all_user_devices`.

No es una marca que se mire al evaluar, sino un **sincronizador**: cuando se
asigna o se quita un vehículo a un usuario, `UserDevicesAlertsDevicesSync`
inserta o borra las filas de `alert_device` de todas sus alertas marcadas con esa
bandera. O sea: **un vehículo nuevo entra solo en esas alertas**, que es
exactamente lo que necesitas.

Hoy la usan **44 alertas de 8.614**. Las plantillas deberían crear las alertas
con esta bandera activada por defecto.

#### Eventos personalizados en las plantillas

Una alerta de tipo `custom` apunta a filas de `events_custom`, que son **por
usuario y por protocolo**. Al aplicar la plantilla a un cliente hay que resolver
a qué evento personalizado suyo se engancha. Tres casos:

1. **Ya existe en ese usuario** (mismo protocolo y condiciones): se reutiliza.
2. **No existe**: se crea a partir del que trae la plantilla, **una variante por
   cada protocolo que los vehículos de ese usuario estén reportando** (ver el
   análisis del protocolo en §12).
3. **Existe con otro nombre**: se reutiliza y se avisa, sin renombrárselo.

Y para el caso que te preocupaba —un cliente añade mañana un equipo de un
protocolo que no tenía—: al reaplicar la plantilla, o mediante un trabajo
periódico que compare protocolos presentes contra variantes creadas, se le añade
la que falte.

Esto se apoya en lo del punto 12: si los eventos de conducción brusca pasan a ser
**de sistema y sembrados a todos**, la mayoría de plantillas caen en el caso 1 y
el problema casi desaparece.

#### Decisiones abiertas

- **¿Vínculo vivo o copia?** Si el cliente edita "su" alerta, ¿se desliga de la
  plantilla o se sobreescribe al reaplicar? Propongo **copia con marca de
  origen**: se sabe de qué plantilla salió y se avisa si difiere, pero el cliente
  manda sobre lo suyo.
- **¿Qué pasa al reaplicar?** Propongo no duplicar: si ya existe una alerta de
  esa plantilla, se actualiza; si el cliente la borró, no se recrea salvo que se
  pida.

#### Riesgos

Aplicar a 773 usuarios crea miles de filas en `alerts` y `alert_device`: va en
cola por lotes, no en la petición web. Y necesita vista previa —"esto va a crear
N alertas en M usuarios y tocar K vehículos"— antes de ejecutar.

---

### 11 · Panel de cumplimiento regulatorio

#### Lo que hay hoy

Las tablas de integración **son colas de salida, no historiales**: no tienen
campo de estado y las filas se borran al enviarse.

| Tabla                  | Filas   | Primer id | Estado       |
| ---------------------- | ------- | --------- | ------------ |
| `wisetrack`            | 694.191 | 1         | **no drena** |
| `sidersales`           | 33.335  | 312.408   | drena        |
| `sutran`               | 1.112   | 2.040.203 | drena        |
| `osinergmin`           | 76      | —         | drena        |
| `comsatel`, `mininter` | 0       | —         | sin uso      |

**Hallazgo operativo, independiente del panel:** de los 3.984 trabajos fallidos,
**3.813 son `SendDataWisetrackJob`**, entre el 11-may y el 22-jun. Por eso la
cola de WiseTrack arranca en el id 1 y ha crecido a 694.191 filas: los envíos
fallan y nadie se enteró en seis semanas.

**Aprobado, va primero:**

1. **Migración** que añade el índice de `device_id` a `wisetrack`. Ojo: crear un
   índice sobre 694k filas bloquea la tabla un momento; conviene hacerlo en hora
   valle y avisar. En producción se aplica con `php artisan migrate --force`.
2. **Purga de los trabajos fallidos** ya resueltos. Se hace con un comando, no a
   mano, para poder repetirlo y dejar constancia de cuántos se borraron.

Antes de purgar hay que mirar **por qué** fallan: si la causa sigue viva, la cola
volverá a crecer. Borrar los fallidos sin diagnosticar solo esconde el problema.

#### Qué se construye

Como no hay campo de estado, la señal de cumplimiento se deriva de:

- **Atraso de cola** por vehículo: filas pendientes y antigüedad de la más vieja.
- **Última fila enviada** por vehículo e integración.
- **Fallos recientes** por integración, leídos de `jobs_failed`.

La pantalla: una fila por vehículo obligado, una columna por integración
activa, semáforo verde/ámbar/rojo, y filtro "solo los que fallan".

#### Sobre el rendimiento — comprobado

Sí se puede sin tocar el envío, **con dos condiciones**.

**1. Falta un índice, y justo en la tabla grande.** Comprobado tabla por tabla:

| Tabla                    | Índice en `device_id`            |
| ------------------------ | -------------------------------- |
| `wisetrack` (694k filas) | **NO — solo PRIMARY(id)**        |
| `sidersales`             | sí                               |
| `sutran`                 | sí                               |
| `osinergmin`             | sí                               |
| `comsatel`               | sí                               |
| `mininter`               | sí (y además índice en `status`) |

Un `COUNT(*) ... WHERE device_id = ?` sobre `wisetrack` hoy recorre 694.191
filas. Crear ese índice es el primer paso, y probablemente también alivie al
propio job de envío.

Dato aparte: **`mininter` sí tiene columna `status`**, al contrario que las
demás. Para esa integración se puede saber el estado real de cada envío, no solo
inferirlo del atraso de la cola.

**2. Nada de consultar en vivo.** Las agregaciones no se hacen al abrir la
pantalla: un comando cada 5–10 minutos las calcula y guarda un resumen, y el
panel lee el resumen. El panel solo lee: no toca la cola ni compite por bloqueos
con los jobs de envío.

---

### 15 · Cabecera y pie de los reportes

#### Lo que hay hoy

`Reports/partials/layout.blade.php`:

- **HTML**: cabecera con logo y una curva decorativa.
- **PDF y demás**: `<img class="logo">` a pelo. Sin nombre de empresa, sin título
  del reporte, sin periodo, sin numeración de página, sin fecha de generación.

El logo sale de `Appearance` (marca blanca), así que ya es por cliente.

#### Qué se construye

Una banda de cabecera común a todos los formatos no-Excel: logo + nombre de
empresa + título del reporte + periodo + vehículo(s). Y un pie con número de
página y fecha de generación.

#### Riesgo a tener en cuenta

La hoja de estilos es `public/assets/css/report.css`, compilada desde el SCSS
**del tema legacy**. La comparten las dos UIs: mejorarla mejora los dos lados,
pero hay que recompilar con `gulp templates` y revisar que no se rompa ningún
reporte antiguo. Los ~111 blades de reporte heredan de este layout, así que el
cambio es en un sitio pero se ve en todos.

---

### 8 · Ampliar el registro de cambios

#### Lo que hay hoy

256.971 registros en `model_change_logs`, con índice compuesto por
`(subject_id, subject_type)` — consultar el historial de una entidad concreta es
inmediato.

| Entidad            | Registros |
| ------------------ | --------- |
| user               | 242.430   |
| device             | 7.600     |
| Alert              | 5.886     |
| Geofence           | 1.197     |
| DeviceService      | 97        |
| Report             | 76        |
| Poi / Route / Task | 32        |

**El 94 % es ruido**: casi todo son guardados de preferencias del usuario
(widgets, ajustes de mapa), no cambios que a nadie le interese auditar.

#### Respuesta a tu pregunta

**Sí, se puede llevar a la v2 sin problemas.** El índice compuesto por entidad ya
está, así que pedir "el historial de ESTE vehículo" es una consulta directa, no
un barrido de las 256.971 filas.

Se hacen las dos cosas: **botón "Historial de cambios"** en el vehículo (kebab
del mapa y pestaña del modal) **y** ampliar lo que se registra.

#### Entidades que HOY no registran nada

`DeviceSensor`, `UserDriver`, `DeviceMaintenance`, `Sharing`,
`UserGprsTemplate`, `CallAction`, `ChecklistTemplate`, `DeviceGroup`,
`VehicleType`, `Client`.

La más importante es **`DeviceSensor`**: acabamos de dar la copia masiva entre
vehículos, que puede cambiar la configuración de decenas de equipos de una vez,
y no queda rastro de quién lo hizo.

#### Qué se construye

1. **Botón "Historial de cambios" en el vehículo**: quién tocó qué y cuándo, con
   el antes y el después de cada campo. Lee `model_change_logs` filtrado por ese
   vehículo (y por sus sensores y servicios, ver punto 2). Solo lectura, y tras
   el permiso que corresponda.
2. **Ampliar la cobertura**: añadir el trait a `DeviceSensor`,
   `DeviceMaintenance`, `Sharing` y `DeviceGroup`, que hoy no dejan rastro.
3. **Recortar el ruido de `user`**: dejar de registrar los guardados de
   preferencias, que son el 94 % del volumen. Sin esto, el historial sigue siendo
   inutilizable aunque se amplíe.
4. Registrar la copia de sensores como **una** entrada con su alcance ("13
   sensores copiados a 8 vehículos"), no 104 entradas sueltas.

#### Decisión abierta

**Qué entra en el historial del vehículo.** Lo natural es que incluya lo que le
pasa a sus partes —sensores, servicios, mantenimientos—, no solo los cambios de
la ficha. Eso obliga a resolver los ids de esas entidades por vehículo; es
sencillo, pero conviene decidirlo antes de empezar.

---

### 12 · Puntuación de conducción

#### Cómo se registran hoy — y por qué conviene cambiarlo

Tenías razón: **hoy es por el nombre del evento**. No hay un tipo propio; son
eventos `custom` que se distinguen por su texto:

| Mensaje                    | Eventos |
| -------------------------- | ------- |
| Giro Brusco                | 64.495  |
| Aceleración Brusca         | 7.221   |
| Frenado Brusco             | 6.770   |
| Detección de choque        | 678     |
| Exceso de velocidad Sutran | 39.308  |
| Botón de Pánico Activo     | 23.085  |

Pero puntuar por texto es frágil: son **29 mensajes distintos definidos por 23
usuarios**, cada uno con su redacción.

#### Tu propuesta encaja con cómo funciona el motor

Revisado `EventCustomAlertCheck`: un evento personalizado compara
`position.parameters[tag]` contra un valor. La etiqueta **`alarm` ya se usa** en
5 eventos personalizados existentes. Así que los valores que manda el decoder
—`hardAcceleration`, `hardBraking`, `hardCornering`, `highRpm`— se pueden
detectar directamente, sin depender de cómo lo haya llamado cada cliente.

**Con una salvedad importante:** el motor exige que coincida el protocolo
(`$eventCustom->protocol != $position->protocol` → descarta). Los eventos
personalizados de hoy solo cubren tres protocolos (teltonika, gt06, gps103) y la
flota usa cinco.

#### El problema del protocolo — analizado

Propusiste definir las plantillas **por modelo de equipo** en vez de por
protocolo. Miré si el modelo permite deducir el protocolo, y la respuesta corta
es que **casi, pero no de forma fiable**:

- 28 modelos distintos; 6 aparecen con más de un protocolo. Casi siempre es un
  error de captura (`FMC920` sale 801 veces como teltonika y una vez suelta como
  tk103, gps103 y vacío).
- El campo `devices.device_model` es **texto libre**, y se nota: `FMC920`,
  `FMC-920`, `FMB920`, `FMB-920`, `F,B920` son la misma familia escrita de cinco
  formas. 26 valores distintos para ~6 familias reales.
- `device_type_id` sí es un catálogo de verdad (12 tipos, 1.459 de 1.530 equipos
  asignados), pero **un tipo abarca varios protocolos**: el tipo _FMX920_ cubre
  teltonika, tk103, gps103, android y gt06.

O sea: ni el modelo en texto libre ni el tipo dan un protocolo fiable.

**Y hay una tabla que ya existe para esto y está vacía:**

```
device_models: id, active, title, protocol, model      -- 0 filas
```

Es exactamente el catálogo modelo → protocolo que hace falta, y está sin
estrenar.

#### Estandarizar sobre `alarm` — la solución de fondo

Propusiste estandarizar apoyándote en que tu decoder es Traccar y que ahí casi
todo llega en `alarm`. Lo comprobé y **es la salida buena**, mejor que cualquiera
de las alternativas de más abajo.

**Traccar ya normaliza el evento entre protocolos.** En `Position.java` hay **38
valores estándar** (`hardAcceleration`, `hardBraking`, `hardCornering`,
`highRpm`, `jamming`, `accident`, `powerCut`, `sos`, `overspeed`, `tow`,
`tampering`…), y los emiten **139 de los 271 decoders**. Los cinco protocolos de
tu flota están entre ellos:

| Protocolo | Referencias a `ALARM_` en su decoder |
| --------- | ------------------------------------ |
| gt06      | 73                                   |
| tk103     | 19                                   |
| gps103    | 14                                   |
| teltonika | 11                                   |
| h02       | 4                                    |

Y no es teoría: muestreando posiciones reales del vehículo 219, los valores que
llegan son exactamente esos.

| Valor de `alarm` | Posiciones |
| ---------------- | ---------- |
| jamming          | 34         |
| hardBraking      | 24         |
| hardAcceleration | 23         |
| accident         | 16         |
| hardCornering    | 8          |

#### Por qué eso resuelve el problema del protocolo

Mira los eventos que tienes configurados hoy: `io252`, `io247`, `io255`, `io318`,
`io249`, `%setflag[6,7,0]%`. **Esos son puertos de entrada de un equipo
concreto** — por eso hay que redefinirlos para cada protocolo y cada modelo, y
por eso todo acaba duplicado.

Los mismos hechos ya vienen normalizados en `alarm`:

| Hoy (por equipo)                       | Estándar     |
| -------------------------------------- | ------------ |
| `io247` → Detección de choque          | `accident`   |
| `io252` → Desconexión de batería       | `powerCut`   |
| `io318` / `io249` → GNSS Jamming       | `jamming`    |
| `io255` → Exceso de velocidad          | `overspeed`  |
| `alarm` → Aceleración / Frenado / Giro | ya está bien |

**Con `alarm`, la definición del evento deja de depender del protocolo.** Una
sola definición vale para toda la flota, presente y futura. El comodín de
protocolo pasa a ser el complemento natural, no un parche.

#### Un detalle que hay que resolver antes

`Position.addAlarm()` **acumula separando por comas**: si el equipo reporta dos
alarmas a la vez, el valor es `hardBraking,overspeed`.

Y `ConditionService` solo tiene **igual, mayor, menor, distinto y vacío** — no
tiene "contiene". Con una condición de igualdad, ese evento doble **no se
detectaría**.

En la muestra real de 105 posiciones no apareció ningún valor múltiple, así que
hoy no está fallando. Pero con gt06 (73 alarmas distintas) es cuestión de tiempo.

**Solución**: añadir un operador "contiene" a `ConditionService`. Es aditivo —una
constante nueva— y no cambia el comportamiento de ninguna condición existente.

#### Qué se construye entonces

1. **Operador "contiene"** en `ConditionService`, y disponible en el formulario
   del admin.
2. **Catálogo estándar de eventos** basado en los valores de Traccar, sembrado a
   todos los usuarios y marcado como de sistema (no borrable, no renombrable).
3. **Tabla de equivalencias** para que los eventos actuales por puerto sigan
   contando en los informes y no se pierda el histórico.
4. El comodín de protocolo, que con `alarm` ya tiene sentido pleno.

#### Las alternativas que había estudiado antes

Tienes razón en que `device_type` no pide protocolo: su formulario solo tiene
activo, título, grupo de sensor y logo. Y el grupo de sensor tampoco sirve —
`sensor_groups` es solo `id, title, count`; el protocolo está únicamente dentro
del texto del título ("TELTONIKA - FMC920 - CORTE"). Adivinar el protocolo
parseando un nombre es justo el tipo de cosa que se rompe sola.

Cuatro caminos posibles:

|       | Alternativa                                          | Coste    | Problema                                                                                    |
| ----- | ---------------------------------------------------- | -------- | ------------------------------------------------------------------------------------------- |
| A     | Añadir `protocol` a `device_type`                    | Bajo     | Sería un protocolo _declarado_: hoy el tipo FMX920 agrupa equipos de 5 protocolos distintos |
| B     | Poblar `device_models`                               | Medio    | Catálogo nuevo que mantener, y enlazarlo con un `device_model` de texto libre es frágil     |
| C     | Resolver por lo que reportan los equipos del usuario | Medio    | Correcto siempre, pero crea N filas por evento y hay que re-sincronizar                     |
| **D** | **Comodín "cualquier protocolo" en el evento**       | **Bajo** | **Ninguno detectado — ver abajo**                                                           |

De las cuatro, la **D** es la que acompaña a la estandarización sobre `alarm`, y
lo comprobé antes de proponerla: de los **108 eventos personalizados existentes,
0 tienen el protocolo vacío**. Así que dar al valor vacío el significado
"cualquier protocolo" **no cambia el comportamiento de nada de lo que ya
existe**. Es un `if` en `EventCustomAlertCheck` y una opción más en el
formulario.

Con eso:

- **Un solo evento por defecto** cubre aceleración, frenado y giro para toda la
  flota, sea cual sea el equipo.
- **Los protocolos futuros entran solos**, sin tocar catálogos ni reaplicar nada.
  Que era exactamente tu preocupación.
- Y el que quiera afinar por protocolo lo sigue pudiendo hacer: el campo se
  queda como está, solo gana el valor "cualquiera".

La A sigue teniendo sentido por separado si quieres saber qué protocolo _debería_
hablar cada tipo de equipo, pero no hace falta para esto.

#### Qué se construye

1. **Eventos de sistema no borrables**: aceleración, frenado, giro y RPM altas,
   por valor de `alarm`, marcados como propios del sistema para que el cliente no
   los borre ni los renombre. Se siembran a todos los usuarios.
2. **Normalizador**: una tabla que dice qué mensajes cuentan como cada tipo de
   conducción brusca, para que los 64.495 "Giro Brusco" que ya existen sigan
   sumando y no se pierda el histórico.
3. **Reporte de conducción**: por vehículo, eventos por cada 100 km usando el
   kilometraje de `DeviceMileageService`, desglosado por tipo, exportable.
4. **Widget en el Dashboard**: top 5 mejor y peor del mes.

Primero el reporte, que es donde está el valor; el widget después.

#### Conductores: cómo funcionan hoy — respuesta a tu pregunta

**Cada conductor vive dentro de un usuario, no son globales.** La tabla
`user_drivers` tiene `user_id`, y se asignan a los vehículos de ESE usuario
(`user_drivers.device_id` para el principal y el pivote `user_driver_devices`
para varios).

Por eso, entrando como admin, no ves conductores: los tienen los usuarios de tus
clientes. En toda la base hay **9 conductores, 10 asignaciones y 14 lecturas de
RFID** — o sea, prácticamente nadie los usa todavía.

De ahí que el ranking arranque **por vehículo**, como dices, y activable. El
motor es el mismo: cuando un cliente empiece a usar RFID, atribuir el evento al
conductor en vez de al vehículo es un cambio pequeño.

#### Decisión abierta

**Cómo se pondera.** Un giro brusco no pesa lo mismo que un choque. Propongo
pesos configurables con un valor por defecto, en vez de una fórmula fija.

---

### 10 · Costo por kilómetro

#### Corrección: Gastos ya está construido, y con datos sembrados

Preguntaste si sería mejor usar Gastos. **Sí, y me equivoqué al restarle
importancia.** Miré la tabla de tipos y no está vacía: trae **20 tipos ya
sembrados**, y son justo categorías de mantenimiento.

|                                                                 |                     |
| --------------------------------------------------------------- | ------------------- |
| Combustible · Peaje · Lavado                                    | gastos de operación |
| Mantenimiento Preventivo · Cambio de Aceite · Cambio de Llantas | mantenimiento       |
| Alineación y Balanceo · Cambio de Frenos · Cambio de Filtros    | mantenimiento       |
| Reparación Motor · Transmisión · Eléctrica                      | correctivo          |

`device_expenses` guarda `type_id`, `quantity`, `unit_cost`, `date`, `supplier` y
`buyer`. La pestaña **Gastos** del modal del vehículo ya existe en la v2. Lo
único que falta es que alguien empiece a registrar.

#### El problema real: dos sitios para lo mismo

Hay dos registros paralelos del mismo hecho:

|        | `device_maintenances`      | `device_expenses`                           |
| ------ | -------------------------- | ------------------------------------------- |
| Filas  | 91                         | 0                                           |
| Campos | tipo, fecha, motivo, notas | tipo, cantidad, **costo**, fecha, proveedor |
| Dónde  | pestaña Historial          | pestaña Gastos                              |
| Dinero | **no**                     | sí                                          |

Se registra el mantenimiento en un sitio y el dinero en otro que nadie usa. Ahí
está el nudo, no en que falte un campo.

#### Propuesta

**Gastos es la fuente de costos reales.**

```
costo/km del periodo = suma de gastos del periodo ÷ km del periodo
```

Desglosado por tipo, que ya viene clasificado. Comparativa entre vehículos del
mismo grupo y evolución mensual.

**Y el campo por kilómetro que decidiste, como respaldo.** Confirmaste que va por
kilómetro: entra en Información junto a `fuel_price`, y sirve para los vehículos
que aún no tienen gastos registrados. Así el informe da un número desde el primer
día, y va ganando precisión conforme se capturen gastos reales.

En el informe se distingue de dónde sale cada cifra: **real** (de gastos) o
**estimada** (de la tarifa). Sin esa distinción, un costo estimado acaba
tomándose por contabilidad.

#### Lo que se construye

1. Campo de costo por km en Información, junto a `fuel_price`, con su migración.
2. Enlazar el alta de mantenimiento con un gasto, para no seguir registrando el
   hecho en un sitio y el dinero en otro.
3. Informe de costo por km: real, estimado, o mezcla, siempre indicando cuál.

### Fuera de plan, pero urgente

**3.813 envíos a WiseTrack fallidos** entre el 11-may y el 22-jun, con la cola
crecida a 694.191 filas. Es un incidente en producción, no una mejora. Conviene
mirarlo antes que cualquiera de los seis puntos.
