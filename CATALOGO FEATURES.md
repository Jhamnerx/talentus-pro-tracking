# Catálogo funcional completo de Wialon

> Referencia de todas las características de la plataforma Wialon (Hosting y Local) para guiar el diseño y desarrollo de tu propia plataforma de rastreo GPS.
> Fuente principal: documentación oficial de Gurtam (help.wialon.com). Última actualización: junio 2026.

---

## 1. Arquitectura general de la plataforma

Wialon se divide en dos grandes bloques que conviene replicar en tu plataforma:

- **Sistema de monitoreo (Monitoring System):** la interfaz operativa donde el usuario final ve unidades en el mapa, genera reportes, configura notificaciones, etc.
- **Sistema de gestión / CMS Manager (Management System):** la capa administrativa donde se crean cuentas, usuarios, planes tarifarios, facturación y la estructura de servicios (qué puede hacer cada cliente).

Wialon se ofrece en dos modalidades:

- **Wialon Hosting:** SaaS en la nube de Gurtam (multiempresa, sin servidores propios).
- **Wialon Local:** instalación on-premise sobre los servidores del cliente (control total de datos).

Para tu producto deberás decidir entre nube multi-tenant, on-premise, o ambos. La separación monitoreo / gestión es clave: permite vender el servicio a revendedores y a clientes finales con distintos niveles de acceso.

---

## 2. Núcleo de datos: unidades, mensajes y telemetría

### 2.1 Unidades (Units)

La "unidad" es el objeto rastreado (vehículo, activo, persona, máquina). Características:

- Alta de unidad asociada a un **dispositivo GPS** mediante tipo de equipo y **identificador único** (IMEI/ID).
- **Puerto y contraseña** del dispositivo para recepción de datos.
- Campos personalizados (custom fields) y campos administrativos.
- **Iconos** y rotación de icono según rumbo.
- Agrupación de unidades en **grupos de unidades** (unit groups).
- Imágenes/fotos de la unidad, perfil del vehículo (marca, modelo, año, VIN, color, tipo de combustible, capacidad del tanque).
- **Contadores:** odómetro (kilometraje), horas de motor (engine hours), contador de GPRS/tráfico.
- Estado de **conexión** y de **movimiento** (en línea/fuera de línea, en movimiento/detenido).
- Consumo de combustible calculado y parámetros de cálculo.

### 2.2 Mensajes (Messages)

- Recepción y almacenamiento de mensajes de datos (posiciones, parámetros, eventos).
- Visor de mensajes con filtrado por tipo (datos, SMS, comandos, eventos).
- Importación/exportación de mensajes.
- Edición y borrado de mensajes (limpieza de datos erróneos).
- Parámetros crudos de telemetría disponibles para crear sensores.

### 2.3 Tracks (recorridos)

- Visualización del recorrido histórico sobre el mapa.
- Coloreado del track por velocidad, sensor o parámetro.
- Detección de viajes (trips) y paradas/estacionamientos (parkings/stops).
- Marcadores de eventos sobre el track.
- Reproducción del recorrido ("Track Player").

---

## 3. Sistema de monitoreo (interfaz operativa)

### 3.1 Mapa y rastreo en tiempo real

- Seguimiento **en vivo** con actualización de posición en segundos.
- Múltiples **capas de mapa** (Gurtam Maps, Google, OpenStreetMap, Bing, HERE, etc.).
- Tooltip de unidad con datos clave (velocidad, ubicación, sensores, geocerca actual).
- Seguimiento ("watch over"/follow) de una o varias unidades.
- Búsqueda de direcciones y **geocodificación inversa** (coordenadas → dirección).
- Capas de tráfico, imágenes satelitales y mapas personalizados.
- Medición de distancias y áreas sobre el mapa.
- Vista de últimas posiciones y "rastros" (trails) recientes.

### 3.2 Panel de monitoreo (Monitoring panel)

- Lista de trabajo (work list) de unidades activas.
- Indicadores rápidos: conexión, movimiento, GPS, batería, señal.
- Acceso rápido a track, reportes, comandos y propiedades por unidad.
- Estados de la unidad (status) configurables (ej. "negocio"/"privado").

### 3.3 Dashboard

- Resumen visual del estado de la flota.
- Evaluación rápida del estado de dispositivos y conexión.
- Visualización de geocercas donde se encuentran las unidades.
- Widgets de actividad y KPIs.

### 3.4 Sensores (Sensors)

Capa que transforma parámetros crudos del hardware en valores útiles:

- Tipos: combustible, temperatura, encendido (ignition), velocidad del motor (RPM), voltaje, peso/ejes, contadores, sensores digitales/analógicos, conteo de pasajeros, etc.
- **Tablas de cálculo** y curvas de calibración (ej. sonda de combustible → litros).
- Validación de sensores (un sensor valida a otro).
- Visibilidad, orden, descripción y unidades de medida configurables.
- Copia de sensores entre unidades.
- Fórmulas y parámetros personalizados.

### 3.5 Geocercas (Geofences)

- Formas: **polígono, línea y círculo** de cualquier radio.
- Agrupación de geocercas y control de visibilidad/color.
- Importación/exportación (incl. KML, WKT).
- Uso transversal en notificaciones, reportes, rutas y tooltips.
- Geocercas a partir de grupos de puntos / puntos de interés (POI).
- Cálculo de área y perímetro.

### 3.6 Notificaciones (Notifications)

Motor de alertas basado en condiciones. Tipos principales:

- **Velocidad** (exceso/por debajo de umbral).
- **Geocerca** (entrada/salida de zonas).
- **Valor de sensor** y rango de parámetros.
- **Alarma/SOS**, pérdida de conexión, antijammer (pérdida de señal GPS/GSM).
- **Inactividad**, estacionamiento prohibido, movimiento no autorizado.
- **Mantenimiento** por kilometraje, horas de motor o fecha.
- **Texto en mensaje de datos**, dirección, valor de conteo de viajes.
- **Estado del conductor**, identificación de conductor/remolque.
- Interacción de unidades (proximidad entre unidades).

**Acciones de notificación:**

- Notificar online (popup), por **e-mail** y por **SMS**.
- Registrar el evento en el historial de la unidad (para reportes).
- Cambiar el **estado** o ícono de la unidad.
- **Enviar un comando** al dispositivo automáticamente.
- Enviar un reporte por correo.
- Llamar a un endpoint (request a servidor externo) / webhook.
- Registrar como mantenimiento, contar como evento, asignar conductor, bloquear usuario, etc.

Parámetros: control horario (días/horas activos), límite de activaciones, intervalo de tiempo, texto con variables/plantillas, máximo de alertas, etc.

### 3.7 Reportes (Reports)

Motor de reportes muy completo (más de 150 plantillas/tablas):

- Formatos de salida: **tablas, gráficas, recorridos en mapa, estadísticas y marcadores de eventos**.
- Tablas típicas: viajes, paradas, estacionamientos, kilometraje, velocidad, exceso de velocidad, combustible (llenados, robos, consumo), eventos, geocercas, visitas a POI, conducción ecológica, horas de motor, utilización de la unidad, conductores, mantenimiento, costos, etc.
- **Plantillas de reporte** reutilizables y exportables.
- Generación por unidad, grupo de unidades, conductor o remolque.
- Programación de envío automático por e-mail (vía notificación/jobs).
- Exportación a **PDF, Excel, XML, HTML, CSV**.
- Filtros avanzados (geocercas, sensores, duración, velocidad, días/horas).
- Gráficas personalizables y mapas embebidos.

### 3.8 Trabajos programados (Jobs)

Tareas automáticas que se ejecutan según horario:

- Ejecución y envío de reportes por e-mail.
- Envío de comandos a unidades.
- Cambio de contadores, de acceso, de parámetros.
- Mensajes/recordatorios a conductores.

### 3.9 Rutas, puntos de control y horarios (Routes)

- Definición de **rutas** con puntos de control (checkpoints) basados en geocercas/POI.
- **Horarios** (schedules) y rondas/turnos (rounds).
- Asignación de rutas a unidades.
- Control de adherencia a la ruta y al horario (retrasos, adelantos, saltos de punto).
- Notificaciones de progreso de ruta.

### 3.10 Conductores y remolques (Drivers & Trailers)

- Base de **conductores** con foto, teléfono, código de identificación (iButton/RFID), campos personalizados.
- **Asignación automática** de conductor a unidad por identificación.
- Asignación manual y por reglas.
- **Remolques** (trailers) y **pasajeros** con la misma lógica de asignación e identificación.
- Reportes y notificaciones específicos por conductor/remolque.

### 3.11 Comandos (Commands)

- Envío de comandos al dispositivo (bloqueo de motor, salidas digitales, solicitar posición, reiniciar, configurar, etc.).
- Comandos por GPRS y por SMS.
- Comandos personalizados por tipo de equipo.
- Envío manual, programado (jobs) o automático (notificaciones).
- Cola y registro de comandos enviados.

### 3.12 POI (Puntos de interés)

- Creación de puntos de interés con icono, radio y descripción.
- Agrupación y uso en geocercas y reportes.
- Importación/exportación.

### 3.13 Usuario e interfaz

- Multilenguaje, husos horarios, sistema métrico/imperial.
- Personalización de columnas, paneles y monitoreo.
- Mensajería interna y registro de eventos.
- Modo de mapa, marcadores y favoritos.

---

## 4. Sistema de gestión (CMS Manager) — capa administrativa

### 4.1 Estructura de servicios y jerarquía

- **Cuentas (accounts/resources):** contenedor de objetos, servicios y facturación.
- **Usuarios (users):** con credenciales, derechos de acceso y configuración propia.
- **Jerarquía de cuentas** (top account → subcuentas → clientes finales) para revendedores.
- **Servicios activables** por cuenta (qué módulos/funciones puede usar el cliente).
- Límites por cuenta (número de unidades, usuarios, geocercas, etc.).

### 4.2 Derechos de acceso (Access rights)

- Permisos granulares sobre cada objeto (ver, editar, borrar, ejecutar comandos, gestionar acceso, etc.).
- Roles y plantillas de acceso.
- Acceso a nivel de unidad, grupo, geocerca, conductor, etc.

### 4.3 Facturación y planes tarifarios (Billing)

- **Planes tarifarios** y servicios de pago.
- Cobro por unidad activa, por servicios o por paquetes.
- Saldo de cuenta, días de bloqueo, fechas de pago.
- Bloqueo/desbloqueo automático por falta de pago.
- Historial de facturación y movimientos.
- Transferencia de unidades entre cuentas.

### 4.4 Administración de objetos

- Creación masiva y gestión de unidades, usuarios, recursos.
- Importación/exportación de configuración.
- Plantillas de unidad para alta rápida.
- Registro de auditoría (log) de acciones administrativas.

---

## 5. Integraciones, APIs y desarrollo

- **Remote API / SDK** (HTTP/JSON) para integrar con sistemas externos (ERP, CRM, facturación, BI).
- **JavaScript SDK** y librerías para construir apps propias sobre Wialon.
- **Webhooks / requests** desde notificaciones.
- **Retranslators:** reenvío de datos crudos a otras plataformas/autoridades (formatos como Wialon IPS, EGTS, etc.).
- **WiaTag / protocolos abiertos** para integrar nuevos dispositivos.
- Marketplace de **aplicaciones** y SDK de apps.
- Soporte de **+3.000 modelos de dispositivos** y cientos de protocolos GPS/telemáticos.

---

## 6. Conectividad de hardware y protocolos

- Amplísima compatibilidad de equipos GPS/GPRS de múltiples fabricantes (Teltonika, Queclink, Concox, Ruptela, Suntech, etc.).
- Soporte de sondas de combustible, lectores RFID/iButton, sensores de temperatura, CAN bus/FMS, tacógrafos, cámaras, etc.
- Recepción por TCP/UDP, SMS y HTTP.
- Configuración remota de dispositivos.
- App **WiaTag** que convierte un smartphone en rastreador GPS.

---

## 7. Telemática avanzada y módulos especializados

- **Eco-driving / conducción segura:** puntuación de conductores por aceleración, frenado, curvas, exceso de velocidad.
- **Control de combustible:** detección de llenados y robos, consumo real vs. teórico, gráficas de nivel.
- **Mantenimiento de flota:** intervalos por km/horas/fecha, costos, recordatorios (ver app Fleetrun).
- **Video telemática:** integración con cámaras/DVR y eventos de video.
- **Tacógrafo:** descarga y análisis de datos de tacógrafo (Tacho Manager / Tacho View).
- **Tiempo real de pasajeros / transporte público** (ver app NimBus).
- **Agricultura** (ver app Hecterra: control de labores en campo).
- **Logística y entregas** (ver app Logistics).

---

## 8. Ecosistema de aplicaciones de Wialon

Wialon no es una sola interfaz, sino una plataforma con un catálogo de apps (apps.wialon.com). Las más relevantes a replicar/inspirarse:

| App                            | Función                                                            |
| ------------------------------ | ------------------------------------------------------------------ |
| **Wialon (móvil iOS/Android)** | App de gestión de flota para el usuario final.                     |
| **WiaTag**                     | Convierte el smartphone en rastreador GPS (monitoreo de personal). |
| **Logistics**                  | Planificación y optimización de rutas de reparto y entregas.       |
| **NimBus**                     | Transporte público: rutas, horarios, control de cumplimiento.      |
| **Hecterra**                   | Agricultura: control de labores de campo por área y maquinaria.    |
| **Fleetrun**                   | Mantenimiento: servicios, intervalos, costos y repuestos.          |
| **Eco Driving**                | Evaluación de la conducción y puntuación de conductores.           |
| **Nspot**                      | Control de personal/horarios y geocercas para fuerza laboral.      |
| **Tacho Manager / Tacho View** | Gestión y análisis de datos de tacógrafo (normativa UE).           |
| **Track Player**               | Reproducción animada de recorridos.                                |
| **Dashboard**                  | Tableros de KPIs de la flota.                                      |
| **Sensolator**                 | Visualización avanzada del estado de sensores.                     |
| **Actualizer**                 | Actualización masiva de propiedades de unidades.                   |
| **Chatterbox**                 | Mensajería con conductores.                                        |
| **Gurtam Maps**                | Capa de mapas propia.                                              |
| **Configurator**               | Configuración remota de dispositivos.                              |
| **Backup Viewer**              | Visualización de respaldos.                                        |

---

## 9. Características transversales / no funcionales

- **Multi-tenant** con aislamiento de datos por cuenta.
- **Escalabilidad** a millones de unidades (Wialon maneja >4 millones de unidades a nivel global).
- **Multilenguaje** y multimoneda.
- **Alta disponibilidad** y respaldos.
- **Roles y seguridad** granulares, log de auditoría.
- **APIs abiertas** para integración y revendedores.
- **App móvil** para usuario final y para administradores.
- **White-label** (marca propia para revendedores).

---

## 10. Checklist sugerido para tu MVP (orientación de fases)

**Fase 1 – Núcleo:** recepción de datos de dispositivos (1–2 protocolos), alta de unidades, mapa en vivo, tracks históricos, usuarios y login.

**Fase 2 – Operación:** sensores básicos (encendido, combustible), geocercas, notificaciones (velocidad/geocerca por e-mail), reportes básicos (viajes, paradas, kilometraje), app móvil.

**Fase 3 – Gestión:** cuentas/jerarquía, derechos de acceso, facturación, planes tarifarios, comandos al dispositivo.

**Fase 4 – Avanzado:** conductores/RFID, rutas y horarios, mantenimiento, eco-driving, control avanzado de combustible, API pública y retransmisión, white-label.

---

## Fuentes

- [Wialon Hosting — Plataforma de rastreo GPS e IoT](https://hosting.wialon.com/)
- [Wialon — Telematics platform for fleet management](https://wialon.com/)
- [Monitoring System | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/monitoring-system)
- [Monitoring | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/monitoring-system/monitoring)
- [Dashboard | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/monitoring-system/dashboard)
- [Notifications | Wialon Help center](https://help.wialon.com/en/wialon-local/2104/user-guide/monitoring-system/notifications)
- [Notification Actions | Wialon Help center](https://help.wialon.com/en/wialon-local/2104/user-guide/monitoring-system/notifications/notification-actions)
- [Notification types | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/monitoring-system/notifications/creating-notifications/notification-types)
- [Sensors | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/monitoring-system/units/unit-properties/sensors)
- [Geofences | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/monitoring-system/geofences)
- [Apps | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/management-system/apps)
- [Configuring service structure | Wialon Hosting Help center](https://help.wialon.com/en/wialon-hosting/user-guide/management-system/configuring-service-structure)
- [Wialon Apps — catálogo de aplicaciones](https://apps.wialon.com/)
