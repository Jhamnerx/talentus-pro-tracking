# UI v2 frente a legacy: novedades y mejoras

> Revisión del código de la rama `nuevo-tema` (actualizada el 18-nov-2026). Se comparó cada módulo de `/ui/v2/*`
> (`app/Http/Controllers/Ui/V2/`, `resources/views/ui/v2/`, `resources/assets/ui-v2/js/`) con su
> equivalente legacy (`Tobuli/Views/Frontend/`, `resources/assets/js/`).
>
> - **NUEVA**: no existe en el legacy.
> - **MEJORADA**: existe en el legacy, pero la v2 hace más o lo hace mejor.
> - **Paridad**: portada sin cambios funcionales.
>
> La §1 reúne todas las características. La §2 resume el fork de Traccar del que dependen el vídeo y la ingesta. La §3 lista lo que sigue pendiente.

---

## Resumen

| Categoría | Qué hay |
| --- | --- |
| **Módulos nuevos** | Talentus IA CHAT, Operaciones (control de vueltas y reporte de turno), Seguridad (control de flota), Monitoreo de grupo, Co-Pilot, paleta de comandos Ctrl+K, vídeo JT1078 en vivo y grabaciones |
| **Funciones nuevas en módulos que ya existían** | Hitos kilométricos del Perú, mapa de riesgo de eventos, plantillas de alerta, comandos «enviar cuando esté en línea», atajos de historial y «Reporte de rutas» en el popup, reportes visibles en el sistema con mapa flotante, galería de Cámara / Medios, opciones de los bloques del tablero |
| **Módulos rediseñados** | Tablero, Flota, Mapa en vivo, Historial, Reportes, Eventos, Alertas, Comandos, Gastos, Cumplimiento de rutas, Mantenimiento, ficha del vehículo |
| **Base transversal** | Tema claro/oscuro en caliente guardado en la cuenta, densidad, Tailwind y tokens CSS, iconos SVG, componentes propios, estilo común entre módulos, accesibilidad WCAG 2.2 AA, responsive, carga de JS por página, preferencia legacy↔v2, textos traducidos |
| **Servidor de rastreo (§2)** | Fork de Traccar `talentus`: vídeo JT1078 completo, streams por IMEI, posiciones en Redis con `uniqueId`, usuario de servicio, comandos por `uniqueId`, IO extra de Teltonika |

---

## 1. Características de la UI v2

### 1.1 Módulos nuevos

#### Talentus IA CHAT: asistente IA · NUEVA
Port de AirBot (airpatrol) sobre la API de Claude, solo en la UI v2. Es distinto del Co-Pilot, que sigue con sus hallazgos por reglas.

- **Ventana flotante** con botón abajo a la derecha, botón en la barra superior y **Ctrl+I**. Se minimiza, avisa con un punto si responde minimizada y mantiene la conversación al cambiar de pantalla. Las conversaciones se guardan.
- **Respuestas en streaming** con botones que llevan a la v2: ver en el mapa, abrir el historial con el rango o un **atajo** (editar vehículo, vídeo, fotos, compartir, servicios, configurar equipo, nueva regla, cuenta…).
- **24 consultas**: buscar unidades (también por placa sin guion ni espacios), estado y ubicación, resumen general, operación diaria, recorridos y paradas, excesos de velocidad, geocercas, alertas y eventos, conductores, combustible, horas de motor, odómetro, mantenimiento, vencimientos, diagnóstico de equipos sin transmitir, comparar periodos, exportar un reporte o un recorrido, abrir pantallas y la **guía de uso de la v2** (`resources/ai/guides.php`).
- **5 acciones, siempre con confirmación** y registradas (`ai_action_logs`): crear una alerta, enviar un comando, programar un reporte, renombrar una unidad y renovar una licencia.
- **Respeta los permisos**: el modelo solo ve las acciones, atajos, pantallas y guías que la cuenta permite.
- **Fuera del chat**: tarjeta de resumen en el Tablero, botón «Explicar con…» en el visor de reportes, resumen en los correos de reportes programados y correo diario de alertas a las 07:00 (`ai:alerts-digest`, apagado por defecto).
- **Administración** (Admin › Configuración › Talentus IA CHAT): nombre visible, interruptor, clave de la API cifrada, modelo del chat y de los resúmenes, esfuerzo, **modelo por plan**, cuotas de preguntas por usuario, plan o general, **tope mensual en USD** y consumo por modelo. Modelos: Claude Sonnet 5.5 (por defecto), Haiku 5.5 y Opus 5.5.
- **Permiso `ai_assistant`** (ver = chat, editar = acciones), apagado por defecto. `php artisan ai:compare` compara modelos; costos en `docs/ia-proveedores-costos.md`.
- **Para activarlo**: migraciones `2026_11_15_*` y `2026_11_16_*`, la clave de la API en Admin y el permiso a quien lo vaya a usar.

#### Vídeo JT1078: en vivo y grabaciones · NUEVA
Port de master-tracking que reemplaza la base de vídeo antigua (Howen, `video_channels`, proxy HLS en PHP). Funciona con el fork de Traccar (§2). Plan en `docs/superpowers/plans/2026-11-14-video-jt1078-ui-v2.md`; despliegue en `docs/video/GUIA-DESPLIEGUE.md`.

- **Cámara por equipo**: `has_camera`, `camera_channels`, roles por canal y `camera_attributes`, con pestaña Vídeo en la ficha y «Detectar desde el equipo». Los canales solo se envían con «Tiene cámara» marcado.
- **Permiso `device_video`** (ver y editar), dado solo a dueños de equipos con cámara, sus managers, sus planes y los admins.
- **En vivo**: FLV directo del tracker con URL firmada (HMAC), sin pasar por PHP; HLS de respaldo, HD/SD y H.265 si el navegador lo soporta. Reproductor en un modal con selector de canal y cuadrícula de 1 a 3 columnas.
- **Audio y hablar con la cámara**: G.711, G.726 y AAC; micrófono (necesita HTTPS).
- **Grabaciones**: búsqueda y reproducción; **descarga a MP4** en la cola `video` (worker propio y ffmpeg) o subida FTP nativa del equipo, con bajar, borrar y cancelar.
- **Accesos**: «Vídeo en vivo» y «Grabaciones de vídeo» en el kebab, y un botón por canal en el widget del drawer.
- Comandos rechazados con mensaje claro si el equipo está offline. API para la app: `devices/video/{id}/config`.
- **Protocolos** con `VIDEO_PROTOCOLS` (por defecto `jt808`).

#### Operaciones: control de vueltas (`/ui/v2/operations`) · NUEVA
- Página con 4 pestañas: En vivo, Vueltas, Operaciones y Turno.
- **En vivo**: estado de cada vehículo y vueltas del día, refrescado cada 20 s.
- **Vueltas**: historial por rango con tramos y paradas desplegables; carga sola con las fechas por defecto.
- **Configurar operaciones**: turnos, pausas, puntos de carga y descarga (geocercas) y tipo/estados de vehículo.
- Motivos de parada y justificación de cada parada (mantenimiento o standby).
- **Conteo automático durante la ingesta** (`OperationChecker` en `PositionsWriter`), con una máquina de estados carga → ida → descarga → vuelta que cambia el estado cargado/vacío del vehículo.
- **Recalcular** con vista previa y barra de progreso que cuenta solo tu lote y estima el tiempo con los tramos que van antes que los tuyos. `operations:process` limita cada pasada por tiempo, así que un recálculo largo no frena los tramos nuevos.
- **Turno**: tarjetas de indicadores, 5 gráficas por hora, barra de estados de 24 h, horómetro, kilometraje, abastecimientos y salidas del área; exporta a Excel con gráficos nativos y a PDF.
- Reportes 910–914 y comandos `operations:process`, `operations:summaries` y `operations:clean`.

#### Seguridad: control de flota (`/ui/v2/security`) · NUEVA
- Reproduce el Excel del cliente (`docs/Dashboard_FM_Seguridad.xlsx`) dentro de la plataforma.
- Filtros por tipo de vehículo, turno (día, noche o ambos), rango de fechas y unidad.
- 8 tarjetas, resumen diario, detalle por unidad y por unidad-día, y estado en vivo, con gráficas ECharts que siguen el tema.
- Ajustes por usuario (horario del turno, límite de velocidad manual, vigencia, minutos de parada y de corte), recálculo de hasta 31 días, agregado horario `security:aggregate` y Excel de hasta 93 días.

#### Monitoreo de grupo (`/ui/v2/monitor`) · NUEVA
- Cuadrícula de 2 a 32 mini-mapas; cada celda sigue en vivo a un vehículo.
- Recuerda el tamaño, la selección y las capas (geocercas, POIs, rutas, cola).

#### Co-Pilot (`/ui/v2/copilot`) · NUEVA
- Hallazgos con reglas propias, sin LLM: vehículos offline mucho tiempo, ralentí prolongado, sin señal GPS, reincidentes.
- Chat que reconoce la intención y lleva a Flota, Eventos o Alertas con el filtro puesto.

#### Paleta de comandos (Ctrl/Cmd+K o «/») · NUEVA
- Busca vehículos (nombre, placa o IMEI), geocercas y POIs respetando permisos. Guarda recientes y se usa entera con teclado.

### 1.2 Funciones nuevas dentro de módulos que ya existían

| Módulo | Función nueva | Referencia |
| --- | --- | --- |
| Mapa en vivo | **Hitos kilométricos del Perú**: capa con densidad según el zoom y referencia «PE-1N · km 557» en los popups | `tp-hitos.js`, `MapLayersController.php` |
| Mapa en vivo | **Color del marcador por velocidad**, con aviso de «cerca» o «por encima» del límite de la geocerca para el tipo y estado del vehículo | `live.js` |
| Mapa en vivo | **Tablero del vehículo**: esferas con aguja (velocidad frente al límite) y testigos de sensores reales | `live.js` |
| Mapa en vivo | **Historial de cambios del vehículo** (ficha, sensores, servicios) y **copiar sensores** a otros vehículos | `DevicesController.php` |
| Mapa (legacy y v2) | **Atajos de historial** en el popup, la ficha y el kebab: Última hora, Hoy y Ayer (permiso `history.view`) | `model/device.js`, `live.js` |
| Mapa (legacy y v2) | **Reporte de rutas** (tipo 43) desde el popup: formulario con fechas y reporte HTML en una pestaña nueva, sin guardar nada (permiso `reports.view`) | `devices/route_report/{id}` |
| Historial | **Colores del recorrido por vehículo** (por viaje, color único o por velocidad con rangos propios) | `HistoryController.php` |
| Historial | **Km del resumen desde una sola fuente** (`DeviceMileageService`), para que cuadren con el Tablero | `HistoryController.php` |
| Historial | **Enlace directo** `?device=&range=&from=&to=` y reproducción de un solo tramo | `history.js` |
| Historial | **Marcas de llenado y robo de combustible** en la gráfica, sincronizadas con el cursor del mapa | `history.js` |
| Reportes (legacy y v2) | **Reportes visibles en el sistema** (`Report::$viewable`): Paradas en un visor a pantalla completa con «Mostrar mapa» por parada y ventana flotante «Ubicación de la Unidad» | `map_window.blade.php` |
| Reportes | **Teselas de Google** en los reportes 43 y 65 | `map_tiles.blade.php` |
| Reportes | **Día exacto de la programación** y **filtro por días de la semana y franja horaria** | `ReportsController.php` |
| Reportes | **«Probar FTP»** junto al campo | ruta `ftp.test` |
| Tablero | **Opciones de los bloques** (colores de estado, vehículos y periodos), en la misma estructura que el legacy | `DashboardController@updateOptions` |
| Eventos | **Mapa de riesgo de la flota** (hasta 1500 puntos por severidad) | `EventsController.php` |
| Eventos | **«Atender» evento** (crea una acción de llamada), exportar CSV y salto a Historial | `EventsController.php` |
| Alertas | **Plantillas de alerta** (admin), con plan de facturación, aplicadas al crear usuarios | `AlertsController.php` |
| Comandos | **«Enviar cuando esté en línea»** con pestaña Pendientes (`commands:dispatch-pending`) | `CommandsController.php` |
| Conductores | **Registro de actividad** desde el kebab y **edición múltiple** de los seleccionados | `tp-drivers.js` |
| Cámara / Medios | **Galería desde el menú lateral**: selector de vehículo con buscador y luego sus fotos y vídeos FTP (permiso `camera`), actualizada sola al llegar una foto | `tp-device-media.js` |
| Cuenta | Confirmación de email por código único con reenvío | `AccountController.php` |

### 1.3 Módulos mejorados

- **Tablero**: de 6 bloques estáticos a un panel con rangos (hoy, ayer, semana, mes o personalizado) en la zona horaria del usuario, con puntaje de conducción, ranking, tendencia de riesgo, vehículos de mayor riesgo, alertas por tipo y kilometraje. Carga por partes en paralelo y se refresca solo.
- **Flota** (antes «Lista de objetos»): búsqueda, filtros por grupo, modelo y protocolo, estado en la URL (`?status=`), exportación CSV, direcciones de las filas visibles y orden por nombre sin espacios iniciales.
- **Mapa en vivo**:
  - lista con contadores por estado, filtro segmentado, vista agrupada o plana y kebab sin AJAX;
  - filtro avanzado en el servidor con los mismos scopes del legacy;
  - sondeo que se pausa con la pestaña oculta;
  - **bloqueo de motor** con comandos preconfigurados (un clic, GPRS o SMS) e historial;
  - panel móvil en pantallas de 900 px o menos.
- **Historial**: rangos rápidos, capas de geocercas, rutas y POIs, gráfica con pestañas por sensor, zoom y cursor sincronizado, registro con todas las columnas del XML `other` y orden ascendente o descendente, cursor con el icono real del vehículo y unidad de combustible del usuario.
- **Reportes**: guardados con búsqueda, filtro, paginación y resumen de la programación; logs con búsqueda por email, estado de envío y descarga; selector de vehículos con chips para miles de equipos; al editar se recupera el periodo y el FTP.
- **Eventos**: filtros por tipo, vehículo, horas o rango; scroll infinito y actualización en vivo con pitido en eventos críticos; marcador real del vehículo, geocodificación bajo demanda y hora en la zona del usuario.
- **Alertas**: tabla de reglas con búsqueda, número de vehículos y canales activos; selectores de vehículos y geocercas con grupos; editor en pestañas; apertura directa con `?edit=`.
- **Comandos**: una sola página con pestañas (Horario, Registros, Pendientes, Plantillas GPRS y SMS) en lugar de 3 o 4 pantallas; al editar una plantilla se conservan los equipos y los tipos de dispositivo.
- **Rutas: cumplimiento**: dentro de la página de programaciones (en el legacy solo es el reporte 909), con filtro por vehículo, solo incumplimientos y detalle por punto.
- **Gastos**: de modal por vehículo a página de flota con búsqueda, filtro, rango de fechas y total.
- **Mantenimiento y servicios**: orden, búsqueda y paginación sin recargar, barras de color por umbral y un mismo modal desde el mapa y desde la página.
- **Ficha del vehículo**: disposición del legacy (600 px, campos a una columna), Usuarios con «Seleccionar todo» y carga al bajar, Sensores y Servicios con edición en línea, ayuda con imagen en el IMEI, pestañas en una fila, errores marcados en su pestaña con foco en el primero.
- **Otros**:
  - **Conductores**: búsqueda, filtro con o sin dispositivos y selector múltiple.
  - **Tareas**: selector de dirección en un mapa propio.
  - **Acciones de llamada**: modal global desde el kebab y desde el popup del evento.
  - **Tipos de vehículo**: estados con nombre, etiqueta corta y color.
  - **Chat**: conversaciones por no leídos, búsqueda y carga de 50 en 50 al bajar; sondeo según el socket.
  - **Bandeja de notificaciones**: scroll infinito, severidad, badge y «marcar todo leído» guardado en la cuenta (se ve igual en todos los dispositivos); el evento abre su popup en el mapa.

**Portados con paridad**:
- capas base, clúster, seguir vehículo, colas, nombres, tráfico, regla, mostrar punto, buscar dirección y unidades sin transmitir;
- geocercas (corredor, matriz de velocidad por tipo y estado, import/export), POIs y rutas con grupos;
- modal del vehículo con sus pestañas (incluidos los CustomFields), widgets, kebab y grupos con reenvíos;
- alertas, exportación del historial (GSR/KML/GPX/CSV) y borrado de posiciones;
- compartir, tareas, checklists (con su **vista previa** de solo lectura y el **código QR** del equipo, gate `checklist_qr_code.view` como el legacy), reenvíos, tipos de trayecto, ajustes de cuenta y eventos personalizados;
- **planes del equipo** («Mejorar» en la fila del vehículo vencido y entrada del menú, con las reglas de `DevicePlansController`; con `custom_device_add` lleva al paso «plan» del registro);
- menú de la lista con **cambiar estado del vehículo**, **chat** (`/ui/v2/chat?device=`), **QR del checklist** y **restablecer UUID de la app**;
- popups del admin, notificaciones en vivo y la tabla de rutas.

Programaciones de ruta, puntos de control y Tipos de trayecto **no son novedades de la v2**: ya existían en el legacy (commit `43e2937a`); la v2 los porta y añade la pestaña de Cumplimiento.

### 1.4 Base transversal

| Tema | Tipo | Detalle |
| --- | --- | --- |
| Layout aislado (`.tp-scope`) con rail y topbar propios, `lang` y RTL automáticos | MEJORADA | `layout.blade.php` |
| Rail compacto o expandido (se recuerda), con un icono distinto por módulo | NUEVA | `partials/rail.blade.php` |
| Tema claro/oscuro en caliente y densidad cómoda/compacta, guardados en la cuenta (siguen al usuario entre dispositivos) | NUEVA | `ShellController@preferences` |
| Preferencia `ui_version` (legacy↔v2): cambia solo por elección explícita («Nueva UI» → `/ui/v2/switch`, «Versión anterior» → `/ui/v2/legacy`) | NUEVA | `ShellController` |
| Tailwind, PostCSS y tokens CSS (`--surface-*`, `--fg-*`) en Gulp | NUEVA | `gulpfile.js` |
| Iconos SVG inline, sin fuentes de iconos | NUEVA | `partials/icons.blade.php` |
| Componentes propios: `tp-datepicker`, `tp-select`, `tp-chips-select`, `modal.js` (`<dialog>` nativo con nombre accesible, errores 422 por campo y `aria-invalid`) | NUEVA | `resources/assets/ui-v2/js/` |
| **Estilo común entre módulos**: misma cabecera de página, botón principal con texto en el color de marca, alturas, pestañas subrayadas, cabeceras de tabla, estados vacíos (`tpEmpty`), «—» en celdas vacías, orden del pie de los modales y formato de fecha del usuario (`tpFmt`) | NUEVA | `components.css`, `modal.js` |
| Toasts accesibles (`aria-live`) | NUEVA | `partials/toast-host.blade.php` |
| Accesibilidad WCAG 2.2 AA, menús con teclado y contraste revisado; **todos los campos con nombre accesible** (enlace automático de la etiqueta del bloque con `tpA11y.linkLabels` y `aria-label` donde un bloque tiene varios campos) y rejilla de horario de alertas con flechas | MEJORADA | `docs/accesibilidad-ui-v2.md`, `modal.js` |
| Responsive (cortes a 1023, 767 y 639 px, menú con velo, `prefers-reduced-motion`); a 375 px ninguna página tiene scroll horizontal | MEJORADA | `components.css`, `shell.css` |
| Textos traducidos en castellano e inglés en vistas y JS (sin literales en los JS de la v2) | MEJORADA | `resources/lang/{es,en}/ui.php` |
| Un JS y un CSS por página (`@push` y `defer`); ECharts solo donde se usa | MEJORADA | — |
| Socket: guarda suscripciones antes de conectar y avisa del estado para caer a sondeo | MEJORADA | `tp-socket.js` |
| Permisos y gates de addon/plugin copiados del legacy | Paridad | — |

---

## 2. Servidor de rastreo: fork de Traccar para Talentus

Producción usa el fork `github.com/Jhamnerx/traccar`, rama **`talentus`** (local en `E:\Users\jamne\IdeaProjects\traccar`), rebasado sobre Traccar upstream (último rebase 3-nov-2026, con copias `backup/talentus-pre-rebase-*`). Tiene 23 commits propios. No es parte de la UI, pero varias funciones de la v2 y del legacy dependen de él.

### Integración con la plataforma (Laravel)
| Qué | Para qué | Dónde lo usa Laravel |
| --- | --- | --- |
| **Redis**: gestor de conexión, `redis.enable` / `redis.url`; registra cada equipo conectado | Estado en vivo y conexión de los equipos sin consultar la base de Traccar | `config/tracker.php` (`redis.*`), `CheckPositionsCommand` (`position.*`) |
| **Posiciones en Redis como JSON plano con `uniqueId`** (`PositionForwarderRedis`) | Laravel recibe la posición con el IMEI y la escribe en sus tablas por dispositivo | `app/Console/PositionsWriter.php` (claves `positions.{imei}`) |
| **`database.savePositions`** | Permite no guardar las posiciones en la base de Traccar (las guarda Laravel) | — |
| **Usuario de servicio** (`user.password`) | La API de Traccar acepta al usuario `admin` con esa clave sin usuario en la base | `config/tracker.php` (`user.password`) |
| **Comandos por `uniqueId`** | La web puede mandar un comando con el IMEI, sin conocer el id interno de Traccar | Envío de comandos GPRS |

### Protocolos
- **Teltonika**: los IO 1–4 también llegan como `di1`–`di4` (0/1), y 16 (odómetro), 113 (batería) y 199 (odómetro de viaje) también como `io16`, `io113`, `io199`. Más claves de IO de los modelos FMB en `setup/traccar.xml`. Los IO con alarma (remolque, accidente, jamming, ralentí, corte de energía, conducción brusca) llegan como `alarm`; Laravel los interpreta con `TeltonikaEvents` (reporte 902, Securitas, Comsatel, UNIGIS).
- **Jimi**: fotos de evidencia de alarmas en el puerto 6267.
- Documentación de protocolos en el repositorio: Teltonika, Queclink GV310LAU y JimiIoT 4G (v2.6.2).

### Vídeo JT1078 (JT808), lo que necesita el vídeo de la §1.1
- **En vivo**: HLS por IMEI (`/api/stream/imei/{uniqueId}/{canal}/live.m3u8`) y **HTTP-FLV de baja latencia** (`live.flv`, con pista de audio AAC opcional) y audio en vivo, autorizados con token HMAC (`media.streamSecret`) en vez de sesión. Sub-stream (SD) y audio a pedido.
- **Hablar con la cámara**: `POST /api/stream/live/{uniqueId}/{canal}/talk` envía el audio ya codificado por la conexión del equipo (escucha, conversación y difusión).
- **Grabaciones del equipo**: consulta (0x9205/0x1205), reproducción (0x9201/0x9202) separada del en vivo para que no se pisen, y lista en `/api/stream/{id|imei}/resources`. Fechas ISO-8601 convertidas a la zona del equipo.
- **Subida FTP nativa** (0x9206, con pausa, continuar y cancelar 0x9207, y aviso de fin 0x1206); las tareas se listan en `/uploads`.
- **Capacidades de la cámara** (0x9003/0x1003): códecs, canales y altavoz, en `/attributes`; con esto funciona «Detectar desde el equipo».
- **Estado del stream** (`/status`: conectado, códec H.264/H.265, último fotograma) y rechazos del equipo por petición (0x0001).
- **Robustez**: los streams sin espectadores se cierran a los 60 s (`media.streamIdleTimeout`) y mandan `videoStop` al equipo; las peticiones de reproducción caducan a los 30 s.

---

## 3. Pendiente

**Limitaciones de la v2**
- **El mapa en vivo y la pestaña En vivo de Operaciones se actualizan por sondeo**: el socket solo lleva avisos, mensajes y fotos.

**Fork de Traccar**
- La rama local `jt808-anonymous-terminal` (3 commits, sin subir a GitHub) añade soporte para **MDVR Streamax**: identifica por placa o VIN los terminales que mandan ceros como teléfono, separa los rechazos de vídeo por canal y detecta la longitud del SIM (2016 o 2019). Hay que fusionarla en `talentus` y desplegarla antes de conectar esos equipos.
- **Howen** usará el mismo contrato de vídeo cuando el fork implemente el protocolo; en Laravel se activa con `VIDEO_PROTOCOLS=jt808,howen`.

**Sin verificar**
- Vídeo, audio, hablar con la cámara y descargas reales: no se ha probado con una cámara física y el fork en local.
- Marcas de combustible con rellenos y robos reales, un envío FTP correcto, el leído de notificaciones en cuentas demo, la carga del chat con muchos vehículos y los CustomFields en pantalla (en desarrollo no hay campos definidos).
- **Checklists con datos**: en desarrollo el addon está apagado y no existen las tablas `checklists*`. El QR se probó con el permiso (imagen, enlace y descarga); la vista previa solo con datos de ejemplo en el navegador.
- **«Cambiar estado» desde el menú de la lista**: el admin de desarrollo no tiene tipos de vehículo, así que la opción no aparece; reusa el modal del panel del vehículo, que sí está probado.
- **Pago de un plan**: el modal de planes se probó con un plan temporal; el enlace «Mejorar» lleva a `payments.order`, que no se siguió.
- **Operaciones, Seguridad y Acciones de llamada** se recorrieron el 18-nov con permisos temporales (carga, consola, red, modales y envío vacío), sin datos de operación.
