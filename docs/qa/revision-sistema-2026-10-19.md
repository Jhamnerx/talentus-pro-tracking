# Revisión funcional de la plataforma (UI v2) · servidor de pruebas

- **Servidor:** https://backup.talentustechnology.com (rama `seguridad`)
- **Usuario:** pruebaoperaciones@test.com (id 864) · 5 vehículos, 1 operación «Acarreo prueba»
- **Fecha:** 19-10-2026 · navegador integrado, tema oscuro (también se comprobó el tema claro)
- **Alcance:** Tablero, Vehículos (alta/edición/colores), Monitoreo de grupo, Eventos, Alertas, Historial, Reportes, Enviar comando (FOTA), Compartir, Mantenimiento, Gastos, Conductores, Acciones de llamada, Geocercas, Rutas, Programaciones de ruta, Operaciones, Seguridad, POI, Ajustes de cuenta, Notificaciones.

## 0. Estado de las correcciones (commit `e5410671`, 19-oct)

| Hallazgo | Estado |
| --- | --- |
| Modal de alertas ilegible en oscuro | ✅ Corregido |
| Radio del círculo contradictorio | ✅ Corregido (solo lectura, como legacy) |
| POI sin icono: error silencioso | ✅ Corregido (validación + resumen) |
| Gastos sin fecha: sin aviso | ✅ Corregido (toast) |
| Registros de comando en UTC | ✅ Corregido (formato legacy) |
| Fechas por defecto de reportes | ✅ Igual que legacy (hoy 00:00 → mañana 00:00) |
| Seguridad: días con límites distintos | ✅ Al cambiar ajustes se ofrece recalcular |
| Reporte HTML se descarga | Se mantiene (decisión) |
| Socket 9002 | Mismo puerto que legacy; revisar `.env` / proceso node en el VPS |
| Compartir con mapa gris | No reproducible en la segunda carga (tiles lentos) |
| Parada en operación repetitiva | Se ignora mientras se ajustan las geocercas |
| Permisos del usuario de prueba (IMEI/SIM/borrar) | Pendiente del admin |

## 1. Resumen

| Módulo | Estado | Errores | Mejoras |
| --- | --- | --- | --- |
| Tablero | ✅ Funciona | 0 | 3 |
| Vehículos (alta) | ⛔ Bloqueado por permisos | 1 crítico + 1 menor | 3 |
| Vehículos (edición, colores) | ✅ Funciona | 0 | 2 |
| Vehículos (eliminación) | ⚠️ No probable (sin permiso `devices.remove`) | – | – |
| Flota (tabla) | ✅ | 0 | 1 |
| Monitoreo de grupo | ✅ | 0 | 0 |
| Eventos | ✅ | 0 | 3 |
| Alertas | ✅ CRUD | 2 (visual oscuro, etiqueta tipo) | 1 |
| Historial | ✅ | 0 | 2 |
| Reportes | ✅ Generar/Guardar | 0 | 4 |
| Enviar comando | ✅ FOTA enviado | 2 | 1 |
| Compartir | ✅ | 1 (mapa gris) | 0 |
| Mantenimiento | ✅ listado | 0 | 1 |
| Gastos | ✅ CRUD | 1 (sin aviso al faltar fecha) | 0 |
| Conductores | ✅ CRUD | 0 | 0 |
| Acciones de llamada | ✅ CRUD | 0 | 0 |
| Geocercas | ✅ dibujo y alta | 1 (radio ignorado) | 1 |
| Rutas | ✅ vacío | 0 | 1 |
| Programaciones de ruta | ✅ formulario (sin rutas para probar) | 0 | 0 |
| Operaciones | ✅ | 0 | 1 |
| Seguridad | ✅ | 0 | 3 (datos) |
| POI | ✅ alta | 2 | 1 |
| Tareas / Chat | 403 sin permiso | 1 (página cruda) | – |
| Infraestructura | ⚠️ | socket 9002 caído | 2 |

**Lo más urgente (orden sugerido):**

1. Permisos del usuario de prueba: sin `device.imei` y `device.sim_number` no puede registrar vehículos (igual que en legacy) y sin `devices.remove` no puede borrarlos. Dar permisos o, en v2, ocultar el botón «+» cuando no pueda rellenar el IMEI.
2. Socket en el puerto 9002 no responde: todo cae a polling (funciona, pero el tiempo real llega con hasta 5 s de retraso y la consola se llena de errores).
3. Modal de alertas en tema oscuro: título, pestaña activa y nombres de grupo ilegibles.
4. Geocerca: el radio escrito en el formulario se ignora; se guarda el radio dibujado.
5. POI: sin aviso cuando falta el icono (422 silencioso) y el primer icono del catálogo está roto.
6. Fecha de Registros de comandos en UTC.
7. La alerta «Parada en operación» genera un evento cada 1 a 3 min por vehículo (846 por semana): inunda Eventos, Tablero y bandeja.

## 2. Detalle por módulo

### 2.1 Acceso

- **Bug legacy:** `/admin/login_as/{id}` abierto directo devuelve un fragmento de modal y el botón «Si» falla (`ReferenceError: $ is not defined`). Solo funciona dentro del panel admin. El formulario sí envía si se hace submit a mano.

### 2.2 Tablero

- ✅ Tarjetas (flota, movimiento, inactivos, detenidos, sin datos, velocidad media, utilización), donas en vivo, puntuación de conducción, tendencia, últimas notificaciones, alertas por tipo, ranking, kilometraje, top velocidad, actividad 24 h. Rango Hoy/Ayer/Semana/Mes y fechas libres.
- **Mejora:** «Últimas notificaciones» y «Alertas por tipo» clasifican la alerta de operación como «Otros» (846 de 930). Mostrar el nombre real de la alerta.
- **Mejora:** las tarjetas no llevan a la lista filtrada (clic en «Detenidos» → flota filtrada).
- **Mejora:** los botones Hoy/Ayer inactivos parecen deshabilitados (contraste bajo).

### 2.3 Vehículos (mapa en vivo y Flota)

- ✅ Lista por grupos, filtros por estado, búsqueda, mapa con placa + velocidad, refresco 15 s, ficha lateral con velocímetro, odómetro, horómetro, sensores, servicios, eventos recientes, bloqueo de motor, dirección (tarda unos 10 s en resolverse).
- ⛔ **Alta de vehículo:** con este usuario los campos IMEI y Nº de SIM salen deshabilitados (no tiene permiso de edición `device.imei` ni `device.sim_number`) y ambos son obligatorios, así que es imposible crear. Es el mismo comportamiento que el legacy: configuración de permisos del admin. **Mejora v2:** ocultar «Nuevo vehículo» o avisar «No tienes permiso para registrar el IMEI» en vez de dejar un formulario que nunca se puede enviar.
- **Mejora:** «Número de SIM» es obligatorio pero vive en la pestaña Información sin asterisco (el resumen de errores sí salta al campo).
- **Mejora (accesibilidad):** etiquetas sin `for`/`id` (Nombres, IMEI).
- ✅ **Edición:** color de cola `#ff0000`, largo 7, recorrido «Único» `#00aaff` y comentario se guardan, se reflejan al reabrir y la cola roja se dibuja en el mapa. Colores de estado (Movimiento verde, Detenido gris, Sin conexión amarillo, Ralentí naranja, Bloqueado negro) se cargan bien; los selectores de color se deshabilitan cuando el tipo de icono no es «Flecha» (correcto). Pestaña Cola: por viajes / único / por velocidad con rangos editables / por sensor. Valores restaurados al final.
- ⚠️ **Eliminación:** no aparece «Eliminar» (ni en el menú ni en el modal) porque el usuario no tiene `devices.remove`. No se pudo probar.
- **Mejora:** al guardar no hay toast de confirmación.
- **Flota (/fleet):** menú de fila solo Ver en mapa / Editar / Historial. Al marcar filas no aparece ninguna acción masiva (solo «Limpiar»): la selección múltiple no sirve de nada todavía.
- **Mejora:** el widget flotante «Servicios» tapa parte del mapa al abrir la ficha.

### 2.4 Monitoreo de grupo

- ✅ Rejilla de 2 a 32 celdas, selector por grupo con estado, cada celda sigue al vehículo en vivo (CFX-759 probado), capas Geocercas/POI/Rutas/Cola, se recuerda en el navegador.

### 2.5 Eventos

- ✅ Lista con filtros (vehículo, tipo, fechas, búsqueda), clic centra el mapa y abre popup con Atender / Historial / Eliminar, exportar, refrescar, borrar todo.
- **Mejora:** el mapa arranca en toda Sudamérica hasta elegir un evento; centrar en el último.
- **Mejora:** las direcciones de eventos antiguos salen «…» (no geocodificadas).
- **Mejora funcional:** «Parada en operación» dispara en cada parada mayor de 60 s, varias veces por hora y vehículo. Añadir re-aviso (un evento por parada o cada N minutos).

### 2.6 Alertas

- ✅ 14 reglas listadas, interruptor, crear (probado: «QA salida geocerca», tipo Geocerca de Salida, CFX-759, geocerca Carga, sonido/popup/push por defecto), editar nombre, menú Editar/Activar/Eliminar.
- **Bug visual (oscuro):** título del modal, pestaña activa y cabeceras de grupo usan `var(--brand-800)` (`resources/assets/ui-v2/css/alerts.css` líneas 989, 1141, 1151 y 1205), que en oscuro es azul marino sobre fondo oscuro (rgb 14,33,87 sobre 26,31,43). En tema claro se ve bien.
- **Bug i18n:** la columna Tipo muestra el código crudo («geofence out», «geofence inout») en vez de «Geocerca de Salida / Entrada-Salida».
- **Mejora:** el select de sonido lista nombres de archivo (`abrupt_acceleration`, `expired_sim`…).
- La alerta QA quedó creada a propósito: alimenta «Salidas de geocerca» en Seguridad.

### 2.7 Historial

- ✅ Picker con búsqueda, rango de fechas, resumen (15,3 km, 48 kph máx, 7 viajes, 8 paradas, 937 puntos), línea de tiempo viajes/paradas/eventos, gráfico (velocidad, altitud, baterías, encendido, odómetro), registro de datos, reproductor x1 a x120, exportar y borrar.
- **Mejora:** «Velocidad media 19 km/h» mientras el resto dice «kph».
- **Mejora:** «Duración 15h 37m» es el rango completo, no el tiempo en movimiento.

### 2.8 Reportes

- ✅ Modal con 93 tipos, 7 formatos, periodos, picker de vehículos por grupo, geocercas, control de días y horas, programación diaria/semanal/mensual, email. «Generar» (Recorridos y paradas, T6G-611, hoy) devolvió el HTML correcto (45 KB). «Guardar» crea el reporte en la lista.
- **Mejora:** «Fecha hasta» arranca a las 00:00 del mismo día que «Fecha desde», es decir, rango vacío por defecto; debería ser 23:59.
- **Mejora:** en formato HTML el reporte se descarga como adjunto; el legacy lo abre en pestaña. Para HTML conviene abrir en pestaña.
- **Mejora:** tras «Guardar» el modal sigue abierto sin confirmación visible.
- **Mejora:** el campo «Metas» no tiene explicación.

### 2.9 Enviar comando (FOTA)

- ✅ Pestañas Horario / Registros / En cola / Plantillas GPRS / SMS. Modal: conexión GPRS/SMS, «enviar al conectarse», picker de vehículos, tipo (serie, personalizado, foto, vídeo, plantillas GPRS: Apagar/Encender motor, Puertas, **Conectar Fota**, Get ios…), confirmación.
- ✅ **Probado:** plantilla «Conectar Fota» (`web_connect`) enviada a T6G-611. Registros: *Enviado*. Falta confirmar en FOTA Web de Teltonika que el equipo se conectó (necesita la cuenta FOTA del cliente).
- **Bug:** Registros muestra la fecha en UTC (`2026-10-19 20:47:19`) cuando la hora local era 15:47. El resto de la plataforma usa UTC-5.
- **Bug UX:** al elegir un vehículo el desplegable no se cierra y tapa «Tipo» y «Mensaje».
- Texto redundante: «Comando enviado con éxito. Exitoso».

### 2.10 Compartir

- ✅ Crear enlace (T6G-611, sin expiración) genera la fila con URL pública; la página `/sharing/{hash}` muestra el vehículo y su recorrido.
- **Bug:** en la página pública el mapa salió gris (sin tiles) en la primera carga; solo se veían ruta e icono. Revisar la capa base y la clave de Google en la vista de compartir.
- Enlace «QA compartir» dejado creado.

### 2.11 Mantenimiento

- ✅ Listado con barras de progreso (km, horas motor, días) para los 3 servicios del T6G-611, búsqueda.
- **Mejora:** no hay botón «+ Nuevo servicio» en la página; solo se crean desde la pestaña Servicios del vehículo.

### 2.12 Gastos

- ✅ Crear (T6G-611, «QA combustible», 10 × 15,5 = 155) genera la fila con Editar/Eliminar.
- **Bug:** con la fecha vacía, «Guardar» no hace nada: ni resumen de errores ni toast (el campo lleva asterisco pero la validación es silenciosa).

### 2.13 Conductores

- ✅ Crear «QA conductor» (teléfono) genera la fila. Formulario: dispositivos, fijar como actual, RFID, email, teléfono, descripción.

### 2.14 Acciones de llamada

- ✅ Crear: al elegir vehículo carga sus eventos con alerta (26, con contexto de operación «Ciclo #17»); respuesta, fecha/hora y notas; la fila muestra el usuario.

### 2.15 Geocercas

- ✅ Panel con 7 geocercas, filtros (todas/polígonos/círculos, grupo, categoría), centrar/editar/borrar, exportar/importar. Alta: elegir tipo (Polígono/Círculo/Corredor), dibujar, formulario (nombre, color, grupo, categoría, vehículo móvil, radio, límite de velocidad, límites por tipo AUTOS/CAMION/CAMIONETAS) y aparece en la lista y en el mapa.
- **Bug:** el **radio escrito** en el formulario se ignora: puse 200 m y se guardó 18 167 m (el del círculo dibujado). `live-zones.js` manda el `radius` del formulario pero la geometría dibujada prevalece.
- **Mejora:** al dibujar aparecen dos avisos, uno en inglés de leaflet-draw («Click and drag to draw circle.») y otro en español.
- Geocerca «QA geocerca» dejada creada (borrar o corregir radio).

### 2.16 Rutas y Programaciones de ruta

- Rutas: sin datos («No hay rutas .» con espacio antes del punto). Botones nueva/importar/exportar presentes; no se probó el dibujo.
- Programaciones: formulario completo (nombre, ruta, días, salida ida/vuelta, tolerancias, vehículos, activa). Sin rutas no se pudo guardar una.

### 2.17 Operaciones

- ✅ En vivo: operación, fecha, vueltas hoy 30, ciclo promedio 12 min 39 s, 4 vehículos en ciclo, 69 paradas no autorizadas, tabla por vehículo con estado (Vuelta/Ida/Sin datos + detenido), vueltas, último ciclo, detenido, ralentí, última trama. Pestañas Vueltas y Operaciones.
- **Mejora:** 69 paradas no autorizadas en un día refuerza lo dicho en Eventos: conviene un re-aviso o umbral mayor.

### 2.18 Seguridad · Control de flota

- ✅ Filtros (flota, turno, desde/hasta, dispositivo, vigencia, límite manual), 8 tarjetas, estado actual por unidad, gráficos (estado, por día, velocidad media por unidad, excesos por día), resumen diario, resultado por unidad, detalle diario; Ajustes (turno, parada mínima, corte de señal), Recalcular, Exportar a Excel.
- **Revisar datos:** los 125 excesos están todos el 14/10 y 0 el resto de días; parece un cálculo hecho con un límite manual distinto ese día. Conviene «Recalcular» forzado de todo el rango.
- **Datos:** CFW-813 tiene 0,01 h de motor y 58 h de movimiento: le falta el sensor de encendido.
- **Datos:** 3 de 5 unidades sin Tipo (CFW-877, CFX-759, CFX-784); asignarlo en Editar vehículo → Información.
- Salidas de geocerca = 42 (ya cuenta con la alerta «geocerca salida y entrada» más la QA nueva).

### 2.19 POI

- ✅ «+», clic en el mapa, formulario (nombre, descripción, grupo, icono) y aparece en la lista y el mapa.
- **Bug:** si no se elige icono, el servidor responde 422 (`map_icon_id: Necesario`) y el modal **no muestra nada**; parece que el botón no funciona. Preseleccionar un icono o mostrar el error.
- **Bug:** dos iconos del catálogo tienen la imagen rota (`/images/map_icons/3b78b0…png`, `a4f1ae…png`); el primero de la lista es uno de ellos, así que el POI creado se ve como pin roto («Marker»).
- **Mejora:** la lista vacía no muestra mensaje («Sin POIs»).
- POI «QA POI prueba» dejado creado.

### 2.20 Tareas y Chat

- El usuario no tiene permiso: el rail no los muestra (correcto), pero la URL directa devuelve una página cruda «403 FORBIDDEN» sin layout. Mejor una pantalla amigable con enlace al Tablero.

### 2.21 Ajustes de cuenta y bandeja

- ✅ Ajustes: Principal (unidades, formatos, zona horaria UTC-5), Grupos de vehículos, Conductores, Tipos de vehículo, Eventos, Widgets, Tablero, Cliente, Mapa.
- ✅ Bandeja de notificaciones: filtros Todas/Críticas/Alertas/Informativos, marcar leídas; cambio de tema claro/oscuro OK.
- **Mejora:** la bandeja está llena de «Parada en operación» (ver 2.5).

### 2.22 Infraestructura

- **Socket.io `:9002` no responde** (ERR_CONNECTION_TIMED_OUT de unos 10 s en cada página). La v2 cae a polling `devices/updates` cada 5 s y funciona, pero hay que abrir el puerto o arrancar `node socket/socket.js` en el VPS.
- La v2 carga Leaflet, plugins y fuentes desde CDN (unpkg, jsdelivr, rsms.me). En redes corporativas restringidas no habrá mapa; conviene empaquetar en `public/assets/ui/v2`.
- Las direcciones (geocodificación) tardan unos 10 s.

## 3. Facilidad de uso (impresión general)

- **Bien:** navegación por rail clara, búsqueda global Ctrl+K, modales consistentes con resumen de errores que salta al campo, pickers de vehículos agrupados, tablas con paginación, tema oscuro/claro, monitoreo de grupo muy práctico.
- **A mejorar:** feedback tras guardar (muchos modales no confirman); validaciones silenciosas (Gastos, POI); desplegables que no se cierran (comandos); textos sin traducir o crudos (tipo de alerta, sonidos, aviso de leaflet-draw); unidades mezcladas (kph / km/h); horas en UTC en Registros de comando.

## 4. Datos de prueba creados (limpiar o conservar)

| Elemento | Dónde | Acción sugerida |
| --- | --- | --- |
| Alerta «QA salida geocerca (edit)» | Alertas | Conservar (sirve a Seguridad) o eliminar |
| Reporte «QA recorridos» | Reportes | Eliminar |
| Enlace «QA compartir» | Compartir | Eliminar |
| Gasto «QA combustible» (T6G-611) | Gastos | Eliminar |
| Conductor «QA conductor» | Conductores | Eliminar |
| Acción de llamada «QA llamada de prueba» | Acciones de llamada | Eliminar |
| Geocerca «QA geocerca» (radio 18 km) | Geocercas | Eliminar |
| POI «QA POI prueba» | POI | Eliminar |
| Comando «Conectar Fota» a T6G-611 | Registros de comando | Verificar en FOTA Web |

El vehículo T6G-611 quedó con sus valores originales (cola verde `#0fac84`, largo 10, recorrido por viajes, sin comentario).
