# UI v2 frente a legacy: novedades y mejoras

> Revisión del código de la rama `nuevo-tema` (14-nov-2026). Se comparó cada módulo de `/ui/v2/*`
> (`app/Http/Controllers/Ui/V2/`, `resources/views/ui/v2/`, `resources/assets/ui-v2/js/`) con su
> equivalente legacy (`Tobuli/Views/Frontend/`, `resources/assets/js/`).
>
> - **NUEVA**: no existe en el legacy.
> - **MEJORADA**: existe en el legacy, pero la v2 hace más o lo hace mejor.
> - **Paridad**: portada sin cambios funcionales (se lista al final de cada módulo).
> - **Pendiente**: el legacy lo tiene y la v2 todavía no (sección final).

---

## Resumen

| Categoría | Qué hay |
| --- | --- |
| **Módulos completamente nuevos** | Operaciones (control de vueltas y reporte de turno), Seguridad (control de flota), Monitoreo de grupo, Co-Pilot, paleta de comandos Ctrl+K, hitos kilométricos del Perú, mapa de riesgo de eventos, plantillas de alerta, comandos pendientes «enviar cuando esté en línea» |
| **Módulos rediseñados con mejoras grandes** | Dashboard, Flota (lista de objetos), Historial, Reportes, Eventos, Comandos, Gastos, Cumplimiento de rutas, Dashboard del vehículo y widget de bloqueo de motor |
| **Añadido después de la auditoría (§5, ya en `nuevo-tema`)** | Vídeo JT1078 completo (en vivo, audio, hablar con la cámara, grabaciones con descarga MP4/FTP, permiso `device_video`), marcas de combustible en el Historial, «Probar FTP», registro de actividad y edición múltiple de conductores |
| **Base transversal nueva** | Tema claro/oscuro en caliente, densidad, Tailwind y tokens CSS, iconos SVG, componentes propios (datepicker, select, chips, modal nativo), accesibilidad WCAG 2.2 AA, diseño responsive, carga de JS por página, preferencia legacy↔v2 |

---

## 1. Módulos nuevos (sin equivalente en el legacy)

### Operaciones: control de vueltas (`/ui/v2/operations`)
- Página con 4 pestañas (En vivo, Vueltas, Operaciones, Turno) — `pages/operations.blade.php:52-61`.
- **En vivo**: estado de cada vehículo y vueltas del día. Se refresca cada 20 s — `tp-operations.js:38`.
- **Vueltas**: historial por rango, con tramos y paradas desplegables — `OperationsController.php:344,392`.
- **Configurar operaciones**: turnos, pausas, puntos de carga y descarga (geocercas) y tipo/estados de vehículo. Se pueden activar, desactivar y borrar — `OperationService.php`.
- Motivos de parada y justificación de cada parada (mantenimiento o standby) — `OperationsController.php:664-744`.
- **Conteo automático durante la ingesta** (`OperationChecker` en `PositionsWriter`), con una máquina de estados carga → ida → descarga → vuelta. Cambia solo el estado cargado/vacío del vehículo.
- **Recalcular** con vista previa, barra de progreso y cola — `OperationsController.php:460-546`.
- **Pestaña Turno**: tarjetas de indicadores, 5 gráficas por hora, barra de estados de 24 h, horómetro, kilometraje, abastecimientos y salidas del área de operación. Se exporta a Excel con gráficos nativos y a PDF.
- Reportes 910–914 (vueltas, paradas, horas de trabajo, visitas de abastecimiento, turno en PDF).
- Comandos programados `operations:process`, `operations:summaries` y `operations:clean`.

### Seguridad: control de flota (`/ui/v2/security`)
- Reproduce el Excel del cliente (`docs/Dashboard_FM_Seguridad.xlsx`) dentro de la plataforma.
- **Filtros**: flota por tipo de vehículo, turno (día, noche o ambos), rango de fechas y unidad — `SecurityController.php:192-232`.
- **Resultados**: 8 tarjetas, resumen diario, detalle por unidad y por unidad-día, y estado en vivo. Las gráficas ECharts se adaptan al tema.
- **Ajustes por usuario**: horario del turno de día, límite de velocidad manual, vigencia, minutos de parada y de corte.
- Recálculo síncrono (hasta 31 días) más el agregado horario `security:aggregate`.
- Exportación a Excel de hasta 93 días.

### Monitoreo de grupo (`/ui/v2/monitor`)
- Cuadrícula de 2 a 32 mini-mapas; cada celda sigue en vivo a un vehículo.
- Recuerda el tamaño, la selección y las capas (geocercas, POIs, rutas, cola) — `monitor.js:53-92`.

### Co-Pilot (`/ui/v2/copilot`)
- Insights con reglas propias, sin LLM externo: vehículos offline mucho tiempo, ralentí prolongado, sin señal GPS, reincidentes.
- Chat que reconoce la intención y lleva a Flota, Eventos o Alertas con el filtro ya puesto — `CopilotController.php:218-240`.

### Paleta de comandos (Ctrl/Cmd+K o «/»)
- Busca vehículos (por nombre, placa o IMEI), geocercas y POIs, respetando permisos. Guarda recientes y se usa entera con teclado — `command-palette.js`, `ShellController.php:46-127`.

### Funciones nuevas dentro de módulos que ya existían
| Módulo | Función nueva | Referencia |
| --- | --- | --- |
| Mapa en vivo | **Hitos kilométricos del Perú**: capa con densidad según el zoom (cada 1, 10 o 100 km) y referencia «PE-1N · km 557» en los popups | `tp-hitos.js`, `MapLayersController.php:622` |
| Mapa en vivo | **Color del marcador por velocidad**, con aviso de «cerca» o «por encima» del límite de la geocerca para el tipo y estado del vehículo | `live.js:289-346` |
| Mapa en vivo | **Tablero del vehículo**: esferas con aguja (velocidad frente al límite) y testigos que salen de sensores reales | `live.js:1085-1243` |
| Mapa en vivo | **Historial de cambios del vehículo** (ficha, sensores, servicios) | `DevicesController.php:683-700` |
| Mapa en vivo | **Copiar sensores** de un vehículo a otros | `routes/web.php:1364-1365` |
| Historial | **Colores del recorrido por vehículo** (por viaje, color único o por velocidad con rangos propios) | `HistoryController.php:227-234` |
| Historial | **Km del resumen desde una sola fuente** (`DeviceMileageService`), para que cuadren con el Dashboard | `HistoryController.php:247-253` |
| Historial | **Enlace directo** `?device=&range=&from=&to=` y reproducción de un solo tramo desde la línea de tiempo | `history.js:501-617,1502` |
| Reportes | **Día exacto de la programación** (día de la semana o del mes) | `ReportsController.php:316-320` |
| Reportes | **Filtro por días de la semana y franja horaria** (slider) | `ReportsController.php:322-327` |
| Eventos | **Mapa de riesgo de la flota** (hasta 1500 puntos por severidad) | `EventsController.php:238-296` |
| Eventos | **«Atender» evento** (crea una acción de llamada y marca «Atendido»), exportar CSV y salto a Historial | `EventsController.php:209-221` |
| Alertas | **Plantillas de alerta** (admin): con plan de facturación, se aplican al crear usuarios | `AlertsController.php:137-257` |
| Comandos | **«Enviar cuando esté en línea»**, con pestaña Pendientes: el GPRS a un equipo desconectado queda en cola y lo envía `commands:dispatch-pending` | `CommandsController.php:98-197` |
| Cuenta | Confirmación de email por código único con reenvío | `AccountController.php:357-384` |

---

## 2. Módulos mejorados

### Dashboard
- **Legacy**: 6 bloques HTML estáticos.
- **v2**: un panel con rangos (hoy, ayer, semana, mes o personalizado) en la zona horaria del usuario.
- **Nuevo contenido**: puntaje de conducción, ranking, tendencia de riesgo, vehículos de mayor riesgo, alertas por tipo y kilometraje.
- Carga por partes en paralelo y se refresca solo — `DashboardController.php:71-122,504-660`.

### Flota (antes «Lista de objetos»)
- Búsqueda, filtros por grupo, modelo y protocolo, y estado en la URL (`?status=`, el Dashboard enlaza así).
- Exporta a CSV y resuelve las direcciones de las filas visibles.
- Se refresca solo cuando no hay nada seleccionado — `fleet.js:22-30,481-544,698-720`.

### Mapa en vivo
- **Lista**: contadores por estado, filtro segmentado, vista agrupada o plana (se recuerda) y menú kebab sin AJAX (sale de una plantilla con los permisos ya resueltos).
- **Filtro avanzado**: se resuelve en el servidor con los mismos scopes del legacy; filtra la lista y el mapa por separado.
- **Sondeo**: se pausa con la pestaña oculta y se reanuda al volver (el legacy sondea siempre) — `live.js:3045-3078`.
- **Bloqueo de motor**: comandos de corte y restablecimiento preconfigurados (un clic, GPRS o SMS) e historial de bloqueos — `live.js:1747-1950`.
- **Móvil**: la lista y los filtros se abren como panel en pantallas de 900 px o menos.

### Historial
- Rangos rápidos: hoy, ayer, 2 y 3 días, semana, semana anterior, mes, mes anterior y última hora.
- Capas de geocercas, rutas y POIs desde el propio historial.
- Gráfica con pestañas por sensor, zoom y cursor sincronizado con el mapa.
- Registro de datos con todas las columnas del XML `other` y orden ascendente o descendente (en el legacy el descendente no funcionaba).
- El cursor de reproducción usa el icono real del vehículo, con su rumbo, y un HUD de datos.
- La unidad de combustible es la del usuario (L o gal), no «L» fija.

### Reportes
- **Guardados**: búsqueda, filtro por tipo, paginación y resumen de la programación.
- **Logs**: búsqueda también por email, tamaño legible, estado de envío y descarga.
- Selector de vehículos con chips, pensado para miles de equipos.
- **Al editar**: se recupera el periodo rápido guardado y el FTP ya no se pierde.

### Eventos
- Filtros por tipo (catálogo completo), vehículo, horas o rango, y búsqueda por nombre de vehículo. El legacy solo tenía búsqueda de texto.
- **Carga y actualización**: scroll infinito; además, la página se actualiza en vivo, con pitido en eventos críticos.
- Marcador real del vehículo, geocodificación bajo demanda y capas del mapa.
- La hora sale en la zona horaria del usuario e incluye los eventos de alertas compartidas.

### Alertas
- Tabla de reglas con búsqueda, número de vehículos y solo los canales que están activos.
- Selectores de vehículos y geocercas con grupos y contador, y editor en pestañas.
- Apertura directa con `?edit=`.

### Comandos
- **Una sola página con pestañas** (Horario, Registros, Pendientes, Plantillas GPRS y SMS). En el legacy esto está repartido en 3 o 4 pantallas.

### Rutas: cumplimiento
- **Cumplimiento dentro de la página de programaciones**: en el legacy solo existe como reporte 909. La v2 filtra por vehículo, muestra solo incumplimientos si se pide y da el detalle por punto (planificado, real y desvío).
- Valida las geocercas de los puntos de control y guarda en transacción.

### Gastos
- **De modal por vehículo a página de flota**: búsqueda, filtro de vehículo, rango de fechas y total.

### Mantenimiento y servicios
- Orden, búsqueda y paginación sin recargar, con barras de color por umbral.
- **Un mismo modal en varios sitios**: se usa desde el mapa en vivo y desde la página.
- Los errores de validación se marcan en cada campo.

### Otros módulos mejorados
- **Conductores**: búsqueda, filtro con o sin dispositivos y selector múltiple con chips.
- **Tareas**: selector de dirección en un mapa propio (buscar, hacer clic o arrastrar, con geocodificación inversa).
- **Acciones de llamada**: también como modal global, desde el kebab del mapa y desde el popup del evento.
- **Tipos de vehículo**: cada estado tiene nombre, etiqueta corta y color (chips en la tabla).
- **Medios del vehículo**: la galería se actualiza sola al llegar una foto por socket.
- **Chat**: conversaciones ordenadas por no leídos y con búsqueda. El sondeo se adapta según el socket esté o no conectado.
- **Bandeja de notificaciones**: scroll infinito, severidad, badge y «marcar todo leído»; el evento abre su popup en el mapa.

### Portados con paridad
- **Mapa**: capas base, clúster, seguir vehículo, colas, nombres, tráfico, regla, mostrar punto, buscar dirección y unidades sin transmitir.
- **Capas del mapa**: geocercas (corredor, matriz de velocidad por tipo y estado, import/export), POIs y rutas con grupos.
- **Vehículo**: modal de 14 pestañas, widgets, kebab de acciones y grupos con reenvíos.
- **Alertas**: tipos, horario, canales y vigencia por vehículo.
- **Historial**: exportación GSR/KML/GPX/CSV y borrado de posiciones.
- **Módulos de gestión**: compartir, tareas (CSV y conjuntos), checklists, reenvíos, tipos de trayecto, ajustes de cuenta (12 pestañas) y eventos personalizados.
- **Avisos**: popups del admin, notificaciones en vivo (sonido y Notification API) y la tabla de rutas.

---

## 3. Base transversal de la v2

| Tema | Tipo | Detalle |
| --- | --- | --- |
| Layout aislado (`.tp-scope`) con rail y topbar propios, `lang` y RTL automáticos | MEJORADA | `layout.blade.php:25-26,85-100` |
| Rail compacto o expandido (se recuerda) | NUEVA | `partials/rail.blade.php` |
| Tema claro/oscuro en caliente, sin parpadeo al cargar | MEJORADA | En el legacy cada tema es un CSS compilado aparte y no se cambia en vivo |
| Densidad cómoda/compacta | NUEVA | `topbar.blade.php:203` |
| Tailwind, PostCSS y tokens CSS (`--surface-*`, `--fg-*`) en Gulp | NUEVA | `gulpfile.js:220-261` |
| Iconos SVG inline, sin fuentes de iconos | NUEVA | `partials/icons.blade.php` |
| Componentes propios: `tp-datepicker`, `tp-select` (buscador y teclado), `tp-chips-select`, `modal.js` (`<dialog>` nativo, errores 422 por campo que saltan a su pestaña) | NUEVA | `resources/assets/ui-v2/js/` |
| Toasts accesibles (`aria-live`) | NUEVA | `partials/toast-host.blade.php` |
| Accesibilidad WCAG 2.2 AA: 119 de 172 hallazgos corregidos | MEJORADA | `docs/accesibilidad-ui-v2.md` |
| Responsive (cortes a 1023, 767 y 639 px, menú con velo, `prefers-reduced-motion`) | MEJORADA | `components.css`, `shell.css` |
| Rendimiento: un JS y un CSS por página (`@push` y `defer`) en lugar de bundles enormes; ECharts solo donde se usa | MEJORADA | p. ej. `security.blade.php:117` |
| Socket: guarda suscripciones antes de conectar y avisa del estado para caer a sondeo | MEJORADA | `tp-socket.js` |
| Preferencia `ui_version` (legacy↔v2): los usuarios nuevos empiezan en v2 y el legacy redirige según la preferencia | NUEVA | `BaseController.php:36-43`, `ObjectsController.php:36`, `ShellController@legacy` |
| Permisos y gates de addon/plugin copiados del legacy | Paridad | — |

---

## 4. Pendiente o con fallos (detectado en la revisión)

**Funciones del legacy que faltan en la v2**
- ~~**Vídeo en vivo y grabaciones**~~ — **resuelto** (ver §5).
- **Planes y «upgrade» del dispositivo**: el legacy tiene el botón (`Objects/item.blade.php:18-23`); en la v2 no hay módulo.
- ~~**Opciones de los bloques del Dashboard**~~ — **resuelto el 18-nov-2026**: botón de engranaje en `/ui/v2/dashboard` con un `tpModal` (colores de estado, vehículos y periodo del bloque de distancia, periodo de eventos y tareas). Guarda en `users.settings → dashboard.blocks.<bloque>.options`, la misma estructura que el legacy (`DashboardController@updateOptions`).
- ~~**Marcas de llenado y robo de combustible en el Historial**~~ — **resuelto** (ver §5).
- ~~**Botón «Probar FTP» en Reportes**~~ — **resuelto** (ver §5).
- ~~**Conductores: registro de actividad y edición múltiple**~~ — **resuelto** (ver §5).
- **Checklists**: faltan el código QR y la vista previa (el addon está apagado en esta instalación).
- ~~**Chat**~~ — **resuelto el 18-nov-2026**: la búsqueda ya existía (mismo scope `search()` que el legacy); se añadió la carga al bajar de 50 en 50 con «Mostrando X de Y» (`ChatController@conversations` con `offset`/`limit`).

**Fallos encontrados en el código de la v2**
- ~~**El tema y la densidad no se guardan en el servidor.**~~ **Corregido el 14-nov-2026**: `ShellController@preferences` usa `setSettings`, y el layout lee la preferencia de la cuenta, que gana a la cookie y al localStorage.
- ~~**Abrir cualquier URL `/ui/v2/*` vuelve a poner `ui_version=v2`.**~~ **Corregido el 14-nov-2026**: la preferencia solo cambia por elección explícita. «Nueva UI» lleva a `/ui/v2/switch` y «Versión anterior» a `/ui/v2/legacy`.
- ~~**El estado «leído» de las notificaciones**~~ — **corregido el 18-nov-2026**: «Marcar todo como leído» guarda el id del último evento en `users.settings → tp_notif_seen_id` (`ShellController@notificationsSeen`) y la bandeja llega con `read` resuelto. En cuentas demo sigue en el localStorage.
- **El mapa en vivo y la pestaña En vivo de Operaciones se actualizan por sondeo**: el socket solo lleva avisos, mensajes y fotos.
- ~~**Textos sin traducir**~~ — **corregido el 18-nov-2026**: los citados («Geocerca», «POI», «Confirmar email», «Command Palette»), `CHAN_LABEL`/`TYPE_LABELS` de `alerts.js`, «Aviso»/«OK»/«Guardando…» de `modal.js`, los `title="Editar"`/«Eliminar» de cuenta, reenvíos, POIs y geocercas, y unos 30 literales más de las vistas v2 (tablero, mapa, flota, eventos, paleta, bandeja…). Quedan literales dentro de otros JS de la v2 (p. ej. `tp-route-schedules.js`, respaldos de `T(...)`).
- **Accesibilidad**: quedan 49 campos sin etiqueta y 4 conmutadores sin estado.
- ~~**Pendientes de verificar**~~ — **revisado el 18-nov-2026**: los equipos ya no se desasociaban (`CommandsController@saveTemplate` los conserva salvo con la plantilla adaptada a equipos, como el `syncLoader` del legacy), pero los **tipos de dispositivo** sí: el formulario no los preseleccionaba al editar. Corregido, junto con los campos ocultos que se seguían viendo (`.tp-field[hidden]`) y el protocolo, que ahora solo viaja con la plantilla adaptada a protocolo. Los CustomFields ya estaban en la ficha del vehículo (pestaña «Campos personalizados», mismo gate `can('view', $item, 'custom_fields') && hasCustomFields()` que el legacy); en dev no hay campos definidos para verlo en pantalla.
- ~~**Estilos del popup legacy sin compilar**~~ — **resuelto el 18-nov-2026**: `gulp templates` con Node 16 (node-sass no compila con Node 22). Los 29 temas llevan `.popup-actions`; el diff de los CSS solo trae esas tres reglas.

---

## 5. Cambios posteriores a la auditoría (14-nov-2026)

### Vídeo JT1078 (en `nuevo-tema`: commits `1bd3ccb8`, `2008770c`, `a05fa60c`, fusión `e26b422d`)
Es un port de master-tracking que reemplaza la base de vídeo antigua (Howen, `video_channels`, proxy HLS en PHP). Funciona con el fork de Traccar (rama `talentus`). Plan en `docs/superpowers/plans/2026-11-14-video-jt1078-ui-v2.md`.

**Backend (común al legacy y a la v2)**
- **Cámara por equipo**: `has_camera`, `camera_channels`, roles por canal y `camera_attributes`. Los datos se migran desde `video_channels`, que queda deprecada.
- **Permiso nuevo `device_video`** (ver y editar). Solo lo reciben los dueños de equipos con cámara, sus managers, sus planes y los admins. Antes no había permiso propio.
- **En vivo**: FLV directo del tracker con URL firmada (HMAC), sin pasar por PHP. HLS de respaldo, HD/SD, H.265 si el navegador lo soporta.
- **Audio y hablar con la cámara**: audio G.711, G.726 y AAC; hablar con la cámara (micrófono, necesita HTTPS).
- **Grabaciones**: búsqueda (desde hoy a las 00:00 por defecto) y reproducción. **Descarga a MP4** en la cola `video` (con worker propio y ffmpeg) o subida FTP nativa del equipo (0x9206). Comandos `video:clean-downloads` y `video:ftp-user`.
- **Capacidades de la cámara**: detección desde el equipo.
- **Equipos offline**: se rechazan los comandos con un mensaje claro.
- **API para la app**: `devices/video/{id}/config`.
- **Protocolos** configurables con `VIDEO_PROTOCOLS`. Howen entrará por el fork con `VIDEO_PROTOCOLS=jt808,howen`. Se eliminó el adaptador viejo (`api/howen/*`, `streamProxy`, rutas `device_video/*`).

**UI v2** (`tp-device-video.js`, `css/device-video.css`)
- **Kebab**: «Vídeo en vivo» y «Grabaciones de vídeo», en lugar del modal explicativo.
- **Reproductor**: en un modal, con selector de canal y cuadrícula de 1 a 3 columnas.
- **Grabaciones**: reproducción del historial y descargas (bajar, borrar o cancelar FTP).
- **Widget del drawer**: un botón por canal; antes abría el visor viejo en otra pestaña.
- **Ficha del vehículo**: pestaña Vídeo nueva con canales, roles y «Detectar desde el equipo».

**Sin verificar**: vídeo, audio, hablar con la cámara y descargas reales (no había cámara física ni fork local). Lo que se probó en el navegador:
- menús, cuadrícula, ficha y búsqueda;
- 403 sin `device_video`;
- sin botón en protocolos no soportados.

Para producción hay que seguir `docs/video/GUIA-DESPLIEGUE.md`: Apache `ProxyPass /tracker-media/`, variables `VIDEO_*`, worker de la cola `video` y ffmpeg.

### Paridad legacy (commit `45b8dee0`, fusionado en `nuevo-tema` con `2788dae0`)
- **Historial**: marcas de llenado y robo de combustible en la gráfica, con etiqueta al pasar el ratón y sincronizadas con el cursor del mapa.
- **Reportes**: botón «Probar FTP» junto al campo, con la misma ruta `ftp.test` del legacy.
- **Conductores**:
  - registro de actividad desde el kebab (filtro desde/hasta y paginación);
  - edición múltiple de los seleccionados (nombre, RFID, teléfono, email, descripción; se guarda todo o nada).
- **Sin verificar**: las marcas con rellenos y robos reales, y un envío FTP correcto.

### Preferencias de la v2 (en la copia de trabajo de `nuevo-tema`, sin commit)
- El tema y la densidad se guardan en la cuenta y siguen al usuario entre dispositivos.
- La elección de «legacy» ya no se pierde al abrir un enlace de la v2: «Nueva UI» lleva a `/ui/v2/switch`.

---

**Aclaración**: Programaciones de ruta, puntos de control y Tipos de trayecto **no son novedades de la v2**. Ya existían en el legacy (desarrollo propio, commit `43e2937a`); la v2 solo los porta y les añade la pestaña de Cumplimiento.
