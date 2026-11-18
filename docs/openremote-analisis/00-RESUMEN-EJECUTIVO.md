# OpenRemote → Talentus Pro Tracking: qué rescatar y en qué orden

**Fuente analizada:** `openremote/core` rama `main`, commit `18f38fbd` (30-oct-2026), ~1.040 clases Java + ~400 TS.
**Destino:** Talentus Pro Tracking (GPSWox 3.7.13 sobre Laravel 9, MySQL, Redis, Socket.io; UI v2 con Tailwind y componentes `tp-*`; legacy jQuery/Bootstrap 3 conviviendo).
**Licencia:** OpenRemote es **AGPLv3**. Lo que se propone aquí es portar *modelos de datos, algoritmos y conceptos* reescritos en PHP/JS, no copiar código. Copiar código Java/TS tal cual obligaría a publicar Talentus bajo AGPL.

Los cuatro informes detallados (con rutas de archivo, clases, líneas y JSON reales) están en esta misma carpeta:

| Informe | Contenido |
| --- | --- |
| `01-reglas.md` | Motor de reglas when-then, flow y Groovy; propuesta completa de porte a Laravel |
| `02-modelo-alarmas-datos.md` | Alarmas con ciclo de vida, metadatos de atributos, forecast, retención, webhooks, asset types, notificaciones, syslog |
| `03-conectividad-agentes.md` | Agentes/protocolos, pipeline de valor (filters/converters), HTTP polling, MQTT broker+API, provisioning X.509, ChirpStack, Modbus, gateway, simulador |
| `04-ui-dashboards-seguridad.md` | Dashboard builder Insights, widgets/ECharts, scheduler RRULE, realms/roles/usuarios restringidos, OpenAPI, observabilidad, white-label, consola móvil |

---

## 1. Veredicto general

OpenRemote **no es un competidor de Talentus en rastreo GPS**: no tiene trips, paradas, odómetro, combustible, conductores, reportes de flota ni protocolos de rastreadores (solo Teltonika vía un proyecto aparte). En todo eso Talentus está muy por delante.

Lo que OpenRemote sí tiene y Talentus no es la **capa de plataforma IoT genérica**: reglas combinables por el usuario, alarmas como tickets, dashboards construibles, atributos tipados con metadatos, ingesta de cualquier fuente (MQTT/HTTP/LoRa/Modbus) y un modelo de permisos más fino. Esa es la mina.

## 2. Gap analysis: lo que Talentus NO tiene y OpenRemote sí

Ordenado por **valor para tus clientes de flota ÷ esfuerzo**. Los IDs remiten a la sección del informe detallado.

### Nivel A — alto valor, encaja directo en lo que ya existe

| # | Funcionalidad | Qué aporta a Talentus | Esfuerzo | Detalle |
| --- | --- | --- | --- | --- |
| A1 | **Reglas when-then combinables** (AND/OR entre atributos, `duration` "si permanece X min", `notUpdatedFor`, `previousValue`, `recurrence` cooldown, rama `otherwise`, `wait` encadenado, validez por RRULE) | Hoy cada alerta es una clase PHP fija. Con esto el cliente arma "si velocidad > 80 **y** dentro de geocerca Mina **y** sensor carga = lleno durante 5 min → push + webhook + crear alarma". Reemplaza/absorbe las 15 `AlertCheck` actuales. | Alto (3-4 sem) | `01-reglas.md` §2, §3, §8 |
| A2 | **Alarmas con ciclo de vida** (OPEN → ACKNOWLEDGED → IN_PROGRESS → RESOLVED → CLOSED, severidad, asignado, N:M con vehículos, acción desde reglas) | Los eventos de Talentus son solo un log. Esto convierte alertas críticas en tickets gestionables por el operador/supervisor, con SLA y responsables. Tu rol "Supervisor" encaja perfecto. | Medio (1-2 sem) | `02-…` §1 |
| A3 | **Dashboard builder** (grid drag&drop con GridStack, widgets: línea/barras ECharts, gauge con umbrales, KPI con delta, tabla, mapa con color por umbral, imagen con marcadores; acceso PUBLIC/SHARED/PRIVATE; presets por breakpoint) | Tu tablero es fijo. Esto deja que cada cliente arme su propio panel y lo comparta por enlace público (como ya haces con Sharing de ubicación). | Medio-alto (2-3 sem) | `04-…` §2, §3 |
| A4 | **Webhooks robustos** (cola, reintentos con backoff, log de entregas, firma HMAC, plantillas, reenvío manual) | OpenRemote tampoco tiene reintentos; la propuesta combina su modelo con lo que te falta. Clave para tus integraciones regulatorias y forwards. | Bajo-medio (1 sem) | `02-…` §5 |
| A5 | **Anti-spam de notificaciones** (`repeatFrequency` ONCE/HOURLY/DAILY/… por fuente+destino) y **targets** (usuario, vehículo→usuarios vinculados, realm) | Elimina el spam de alertas repetidas sin tocar cada AlertCheck. | Bajo (3-5 días) | `01-…` §4, `02-…` §7 |
| A6 | **Scheduler RRULE** (RFC 5545 con `php-rrule` + `rrule.js`) para validez de reglas, geocercas por horario, comandos programados | Hoy tienes días/horas por alerta; esto generaliza a "cada 2do lunes", "solo feriados", etc. | Bajo (3-5 días) | `04-…` §4 |

### Nivel B — valor claro, abre nuevas líneas de negocio

| # | Funcionalidad | Qué aporta | Esfuerzo | Detalle |
| --- | --- | --- | --- | --- |
| B1 | **Pipeline de valor** (`valueFilters`: jsonPath/regex/substring/math; `valueConverter` mapa; `writeValue` con `%VALUE%`/`%TIME%`) | Fórmulas avanzadas para sensores sin programar; base para B2-B4. Portable 1:1 a PHP y JS. | Medio (1 sem) | `03-…` §2 |
| B2 | **HTTP Agent con polling** (API externa → atributos, paginación, OAuth2, `pollingAttribute`) | Integrar clima (OpenWeather), precio de combustible, tipo de cambio, ERPs de clientes como "sensores virtuales". | Bajo-medio (1 sem) | `03-…` §3 |
| B3 | **MQTT API para clientes/integradores** (broker externo EMQX con auth HTTP contra Laravel; topics `{cuenta}/{clientId}/attributevalue/{sensor}/{device}`; presencia por conexión) | Vendes acceso en tiempo real a integradores sin exponer tu Socket.io; sustituye parte de los forwards. **No portar el broker embebido**. | Medio (2 sem) | `03-…` §4 |
| B4 | **Ingesta IoT no-GPS**: ChirpStack/LoRaWAN (demultiplexado por fPort), Modbus (lote de registros contiguos), MQTT genérico | Sensores de temperatura en almacenes, tanques, medidores: mismo dashboard y reglas que la flota. Nueva línea de venta. | Medio-alto | `03-…` §6 |
| B5 | **Atributos con metadatos** (unidades componibles, formato, constraints min/max/pattern, readOnly, storeDataPoints, accessPublicRead) | Unifica sensores, campos custom y futuros asset types bajo un mismo esquema validable. | Medio | `02-…` §2 |
| B6 | **Asset types dinámicos** (tipos definidos por JSON: icono, color, atributos) + **AssetQuery** serializable (grupos inteligentes, destinatarios por consulta) | Rastrear cosas que no son vehículos (tanques, generadores, personal) sin tocar código. | Alto | `02-…` §6, §8 |
| B7 | **Permisos finos**: 22 permisos atómicos `read:*/write:*` + roles compuestos creables por el admin del cliente; **usuarios restringidos** con acceso por vehículo y por atributo (`ACCESS_RESTRICTED_READ`) | Tus 6 roles fijos → roles por cliente. `spatie/laravel-permission` + tabla `user_device_links` + ACL por sensor. Sin Keycloak. | Medio | `04-…` §5 |
| B8 | **Consultas de datapoints** (`lttb` downsampling, `interval` con MIN/AVG/MAX/SUM/COUNT/DIFF/MODE/MEDIAN + gapFill) | Gráficas de combustible/temperatura de meses sin matar MySQL. | Medio | `02-…` §4 |

### Nivel C — útil, menor urgencia

| # | Funcionalidad | Nota | Detalle |
| --- | --- | --- | --- |
| C1 | **Forecast**: promedio exponencial ponderado + `predicted_datapoints`; propuesta extra de tendencia lineal para mantenimiento preventivo (odómetro/horas motor → fecha estimada de servicio) | OpenRemote no tiene ML real; lo valioso es el concepto de "datapoint predicho" que se convierte en real al llegar su hora. | `02-…` §3 |
| C2 | **Retención/purga** multinivel y particionado | Tus tablas por dispositivo ya ayudan; añadir purga por cuenta/sensor + rollups. | `02-…` §4 |
| C3 | **Syslog centralizado** con categorías y visor en vivo (Monolog → tabla + Redis publish) | Diagnóstico de soporte más rápido. | `02-…` §7 |
| C4 | **OpenAPI/Swagger** autogenerado (`l5-swagger`) + cliente TS (`openapi-typescript`) | Tu `API_DOCUMENTATION.md` manual → vivo. Prerrequisito del MCP. | `04-…` §6 |
| C5 | **Observabilidad**: Prometheus (`prometheus_client_php`), `/health`, métricas de cola/reglas | Para tus VPS de clientes. | `04-…` §8 |
| C6 | **White-label por realm**: `appTitle`, CSS vars `--color1..8`, logo/favicon, idioma, resolución por dominio | Ya tienes 26 temas; esto lo hace editable por el reseller desde la UI. | `04-…` §9 |
| C7 | **Geocercas evaluadas en el teléfono** (push silencioso `GEOFENCE_REFRESH` → el móvil descarga sus geocercas y hace callback HTTP) | Para GPS UBICAR / apps que vendes: alertas de geocerca sin servidor cuando el rastreador es el celular. | `04-…` §10 |
| C8 | **Simulador** (replay con RRULE) | Script Node que reproduce GPX hacia Traccar (OsmAnd) para demos y QA. | `03-…` §8 |
| C9 | **Provisioning X.509** por MQTT | Solo si vendes hardware propio. | `03-…` §5 |
| C10 | **Filtros del gateway** (`delta`, `duration`, `valueChange`, `skipAlways`, `excludeAttributes`) | Aplicar a tus forwards para reducir tráfico a SUTRAN/OSINERGMIN. | `03-…` §7 |

### No vale la pena portar

- Broker MQTT embebido (Artemis) y Keycloak como IdP obligatorio.
- Edge Gateway completo (tu sistema de retransmisión ya cubre el caso).
- Flow rules (editor de nodos) y Groovy rules (`eval` en PHP es inseguro y poco demandado por clientes de flota).
- Lit/Vaadin web components (tu v2 va con Tailwind + `tp-*`); sí tomar **ECharts**, **GridStack** y **rrule.js**, que son vanilla.
- MCP server: **no existe en el core** (solo un registro de servicios externos). Si lo quieres, hazlo propio sobre tu OpenAPI.
- OTA/hawkBit: tampoco está en el core.

## 3. Hoja de ruta sugerida (12-16 semanas, incremental sobre la v2)

```
Fase 1 (sem 1-3)   A5 anti-spam + A6 RRULE + A4 webhooks robustos + C4 OpenAPI
                   → mejoras visibles sin tocar el núcleo
Fase 2 (sem 3-6)   A2 alarmas con ciclo de vida (+ acción "crear alarma" en alertas actuales)
                   + B1 pipeline de valor como "fórmula avanzada" de sensores
Fase 3 (sem 6-10)  A1 motor de reglas when-then
                   (FactStore Redis, evaluador tras AlertCheck, tick por minuto,
                    comando rules:import-legacy para migrar alertas existentes)
Fase 4 (sem 10-13) A3 dashboard builder + B8 consultas LTTB/interval (ECharts sustituye Flot)
Fase 5 (sem 13-16) B2 HTTP agent + B3 EMQX/MQTT API + B7 permisos finos
Después            B4-B6 (IoT no-GPS, asset types), C1-C3, C5-C8
```

## 4. Mapa de archivos de OpenRemote a estudiar primero

Rutas relativas a `C:\laragon\www\openremote-core` (rama `main`):

| Tema | Archivos |
| --- | --- |
| Reglas JSON | `model/src/main/java/org/openremote/model/rules/json/*.java`, `manager/src/main/java/org/openremote/manager/rules/JsonRulesBuilder.java` (líneas 302-702 evaluación, 1073-1745 acciones), `test/src/test/resources/org/openremote/test/rules/*.json` (ejemplos reales) |
| Alarmas | `model/.../alarm/Alarm.java`, `SentAlarm.java`, `manager/.../alarm/AlarmService.java`, `manager/src/main/resources/org/openremote/manager/setup/database/V20240813_01__Alarms.sql` |
| Dashboards | `model/.../dashboard/*.java`, `ui/component/or-dashboard-builder/src/` (widgets en `widgets/`), `manager/.../dashboard/DashboardStorageService.java` |
| Pipeline de valor | `model/src/main/java/org/openremote/model/protocol/ProtocolUtil.java`, `model/.../value/ValueFilter*.java`, `model/.../asset/agent/AgentLink.java` |
| HTTP agent | `agent/.../protocol/http/HTTPProtocol.java`, `HTTPAgentLink.java` |
| MQTT API | `manager/.../mqtt/DefaultMQTTHandler.java`, `MQTTBrokerService.java`, `UserAssetProvisioningMQTTHandler.java` |
| Notificaciones | `model/.../notification/*.java`, `manager/.../notification/NotificationService.java` (`okToSendNotification`) |
| Datapoints | `manager/.../datapoint/AssetDatapointService.java`, `model/.../datapoint/query/*.java`, `ForecastService.java` |
| Seguridad | `model/.../security/ClientRole.java`, `manager/.../security/KeycloakIdentityProvider.java`, `UserAssetLink` |
| Scheduler | `model/.../calendar/CalendarEvent.java`, `ui/component/or-scheduler/` |
| White-label | `ui/app/manager/src/pages/page-configuration.ts`, `deployment/manager/app/manager_config.json` |
