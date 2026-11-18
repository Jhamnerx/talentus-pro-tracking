# OpenRemote – Modelo de datos y servicios: qué portar a Talentus Pro Tracking

Análisis del repositorio `openremote/core` (Java 21, Postgres + TimescaleDB, Hibernate, Camel) clonado en `/home/claude/openremote/core`, enfocado en lo que **Talentus Pro Tracking** (Laravel 8 / MySQL / Redis) no tiene y vale la pena portar. Cada sección indica: qué hace OpenRemote (archivos y clases), cómo lo modela (tablas / JSON), la lógica clave y una propuesta concreta de porte a Laravel.

Rutas base usadas en el documento:

- `model/` = `model/src/main/java/org/openremote/model/`
- `manager/` = `manager/src/main/java/org/openremote/manager/`
- `sql/` = `manager/src/main/resources/org/openremote/manager/setup/database/` (migraciones Flyway, convención `V<yyyyMMdd>_<HHmm>__Nombre.sql`, ver `AGENTS.md`)

---

## 0. Panorama: cómo modela OpenRemote el mundo (para entender el resto)

OpenRemote **no** tiene una tabla por entidad de negocio (vehículo, sensor, conductor…). Tiene **una sola tabla `ASSET`** con un árbol (ltree) y un **JSONB `attributes`**, y todo lo demás (datapoints, alarmas, notificaciones, reglas) referencia `asset_id + attribute_name`.

`sql/V20191202_01__Schema.sql`:

```sql
create table ASSET (
  ID varchar(22) not null, ATTRIBUTES jsonb, CREATED_ON timestamptz not null,
  NAME varchar(1023) not null, PARENT_ID varchar(22), PATH ltree,
  REALM varchar(255) not null, TYPE varchar(500) not null,
  ACCESS_PUBLIC_READ boolean not null, VERSION int8 not null, primary key (ID));
create table ASSET_DATAPOINT (TIMESTAMP timestamp, ENTITY_ID varchar(22), ATTRIBUTE_NAME varchar(255), VALUE jsonb, primary key (TIMESTAMP, ENTITY_ID, ATTRIBUTE_NAME));
create table ASSET_PREDICTED_DATAPOINT (... misma forma ...);
```

Cada atributo dentro del JSONB tiene la forma `{"value": ..., "timestamp": 1699999999999, "type": "positiveNumber", "meta": {"units": ["kilo","metre","per","hour"], "readOnly": true, "storeDataPoints": true, ...}}` (`model/attribute/Attribute.java`, serializador `AttributeSerializer`, deserializador que hidrata el `value` a partir del `type`).

**Qué hay y qué no hay respecto al dominio GPS**:

- No existe `VehicleAsset`. El asset más parecido es `model/asset/impl/ShipAsset.java` (MSSI/IMO/ENI, `direction` tipo `DIRECTION`, `speed` con `UNITS_KNOT`, `length` con `UNITS_METRE`). La ubicación es un atributo genérico heredado por todos los assets: `Asset.LOCATION = new AttributeDescriptor<>("location", ValueType.GEO_JSON_POINT)` (`model/asset/Asset.java:315`), con `GeoJSONPoint {type:"Point", coordinates:[lng,lat]}`.
- No hay concepto de "trip", "session", ni tabla de posiciones: una posición es simplemente un datapoint del atributo `location`. Las consultas geo se hacen sobre el JSONB con PostGIS (`ST_Within(ST_MakePoint((attr #>> '{coordinates,0}')::numeric, ...), ST_MakeEnvelope(...))`, `AssetStorageService.java:2985-3015`).
- Conclusión: en lo que es rastreo (posiciones, viajes, geocercas, sensores calibrados, reportes) **Talentus está muy por delante**. Donde OpenRemote aporta es en la **capa genérica de metadatos, alarmas, datapoints agregados, predicción, retención, notificaciones y eventos**. Eso es lo que se detalla abajo.

---

## 1. (a) Sistema de alarmas con ciclo de vida

### Qué hace OpenRemote

Archivos: `model/alarm/Alarm.java`, `SentAlarm.java`, `AlarmAssetLink.java`, `AlarmEvent.java`, `AlarmResource.java`; `manager/alarm/AlarmService.java`, `AlarmResourceImpl.java`; SQL `sql/V20240813_01__Alarms.sql`; acción de reglas `model/rules/json/RuleActionAlarm.java` ejecutada en `manager/rules/JsonRulesBuilder.java:1430-1480`; UI `ui/app/manager/src/pages/page-alarms.ts`.

`Alarm.java` (DTO de entrada):

```java
public enum Source { MANUAL, CLIENT, GLOBAL_RULESET, REALM_RULESET, ASSET_RULESET, AGENT }
public enum Status { OPEN, ACKNOWLEDGED, IN_PROGRESS, RESOLVED, CLOSED }
public enum Severity { LOW, MEDIUM, HIGH }
protected String title; protected String content; protected Severity severity; protected Status status;
protected String assigneeId; protected String realm; protected String sourceId; protected Source source;
```

`SentAlarm.java` (entidad persistida, tabla `ALARM`) añade `createdOn`, `acknowledgedOn`, `lastModified`, `assigneeUsername`/`sourceUsername` (vía `@Formula` sobre `USER_ENTITY`) y `@ManyToMany List<Asset> asset` vía `ALARM_ASSET_LINK`.

```sql
create table ALARM (ID int8, REALM varchar(255), TITLE varchar(255), CONTENT varchar(4096),
  SEVERITY varchar(15), STATUS varchar(15), SOURCE varchar(50), SOURCE_ID varchar(43),
  CREATED_ON timestamptz, ACKNOWLEDGED_ON timestamptz, LAST_MODIFIED timestamptz, ASSIGNEE_ID varchar(255));
create table ALARM_ASSET_LINK (SENTALARM_ID int8, REALM varchar(255), ASSET_ID varchar(22), CREATED_ON timestamptz,
  primary key (ASSET_ID, REALM, SENTALARM_ID));
```

Lógica clave en `AlarmService.java`:

- `sendAlarm(Alarm, List<String> assetIds)`: valida realm/título/severidad/source, crea `SentAlarm` con `status=OPEN`, enlaza assets con `insert ... on conflict do nothing`, publica `AlarmEvent(realm, CREATE)` por websocket y, **si `severity == HIGH`**, llama a `sendAssigneeNotification`.
- `sendAssigneeNotification`: si hay `assigneeId` notifica sólo a ese usuario; si no, consulta con `UserQuery` a todos los usuarios del realm con rol `write:alarms` (excluyendo usuarios "restringidos" que no tengan acceso a *todos* los assets enlazados). Envía **email + push** con una tabla HTML (Title, Content, Created, Source, Severity, Status, Linked assets, Assignee, URL `https://host/manager/#/alarms/{id}?realm=...`) a través de `NotificationService.sendNotificationAsync(..., Source.INTERNAL, "alarms")`.
- `updateAlarm(old, new)`: UPDATE de título/contenido/severidad/estado/assignee + `lastModified`; si pasa a HIGH re-notifica excluyendo al assignee anterior.
- `getAlarms(realm, status, assetId, assigneeId)`: filtros simples por JPQL, orden `createdOn desc`.
- Autorizador de suscripción websocket: un usuario no superusuario sólo recibe `AlarmEvent` de su realm (`RealmFilter`).
- Desde reglas JSON (`RuleActionAlarm { Alarm alarm; String assigneeId; }`) el contenido admite el placeholder `%TRIGGER_ASSETS%`, que se reemplaza por un bloque de texto `ID / Asset name / Attribute name / Value` por cada asset disparador (`JsonRulesBuilder.getAlarmContent`).
- La API (`AlarmResource`) es: `GET /alarm?realm&status&assetId&assigneeId`, `POST /alarm?assetIds=…`, `GET/PUT/DELETE /alarm/{id}`, `DELETE /alarm` (bulk), `GET /alarm/{id}/assets`, `PUT /alarm/assets` (añadir enlaces, idempotente). Roles `read:alarms` / `write:alarms`.

**Lo que NO tiene** (y que Talentus debería añadir al portar): comentarios/hilo de actividad, historial de cambios de estado, SLA/escalado automático, deduplicación/agrupación de alarmas repetidas, auto-cierre cuando la condición desaparece. En OpenRemote la "alarma" es un ticket operativo simple y la deduplicación la hace el motor de reglas (reset de condición).

### Diferencia con Talentus

Talentus tiene **alertas = eventos** (una fila por disparo, con canales email/SMS/push/webhook/comando) pero no tiene un **ticket con ciclo de vida** que se asigne a un operador, se reconozca y se cierre. Eso es exactamente lo que falta para clientes con centro de control (operador/supervisor).

### Propuesta de porte a Laravel

Migraciones (MySQL 8):

```php
Schema::create('alarms', function (Blueprint $t) {
    $t->bigIncrements('id');
    $t->unsignedInteger('account_id')->index();          // equivalente a "realm": cuenta/reseller/cliente
    $t->string('title');
    $t->text('content')->nullable();
    $t->enum('severity', ['low','medium','high','critical'])->index();
    $t->enum('status', ['open','acknowledged','in_progress','resolved','closed'])->default('open')->index();
    $t->enum('source', ['manual','api','alert_rule','device','system']);
    $t->string('source_id', 64)->nullable();              // alert id, device id, user id...
    $t->unsignedInteger('created_by')->nullable();
    $t->unsignedInteger('assignee_id')->nullable()->index();
    $t->timestamp('acknowledged_at')->nullable();
    $t->timestamp('resolved_at')->nullable();
    $t->timestamp('closed_at')->nullable();
    $t->string('dedup_key', 191)->nullable()->index();    // mejora: evitar alarmas repetidas abiertas
    $t->json('context')->nullable();                      // snapshot: posición, sensor, valor, geocerca
    $t->timestamps();
});
Schema::create('alarm_device', function (Blueprint $t) {   // = ALARM_ASSET_LINK
    $t->unsignedBigInteger('alarm_id'); $t->unsignedInteger('device_id');
    $t->timestamp('created_at')->nullable();
    $t->primary(['alarm_id','device_id']);
});
Schema::create('alarm_comments', function (Blueprint $t) { // mejora sobre OpenRemote
    $t->bigIncrements('id'); $t->unsignedBigInteger('alarm_id')->index();
    $t->unsignedInteger('user_id'); $t->text('body'); $t->timestamps();
});
Schema::create('alarm_history', function (Blueprint $t) {  // mejora: auditoría de transiciones
    $t->bigIncrements('id'); $t->unsignedBigInteger('alarm_id')->index();
    $t->unsignedInteger('user_id')->nullable();
    $t->string('field', 32); $t->string('old_value')->nullable(); $t->string('new_value')->nullable();
    $t->timestamp('created_at');
});
```

Modelos y servicios:

- `App\Models\Alarm` (con `belongsToMany(Device::class)`, `hasMany(AlarmComment)`, `hasMany(AlarmHistory)`, `belongsTo(User,'assignee_id')`), `AlarmComment`, `AlarmHistory`.
- `App\Services\AlarmService` con `open(array $data, array $deviceIds, ?string $dedupKey)`, `acknowledge($alarm, $user)`, `assign($alarm, $assignee)`, `transition($alarm, $status, $user)` (máquina de estados: `open→acknowledged→in_progress→resolved→closed`, permitiendo `open→resolved` y reapertura `resolved→open`), `comment(...)`. Al abrir con severidad `high/critical` disparar `AlarmOpened` event → listener que reutiliza los canales existentes de Talentus (email/push/SMS) hacia el assignee o hacia todos los usuarios con permiso `alarms.write` de la cuenta (mismo criterio que OpenRemote).
- Integración con las alertas actuales: añadir a la configuración de cada alerta la acción **"Crear alarma"** (severidad, asignar a, plantilla de título/contenido con placeholders `{device.name}`, `{sensor.value}`, `{geofence.name}`, equivalente a `%TRIGGER_ASSETS%`) y un `dedup_key = alert_id:device_id` para que mientras exista una alarma abierta para esa combinación no se abra otra (se añade una fila a `alarm_history` tipo "re-disparo"). Opcional: auto-resolver cuando la alerta de "fin" (salida de geocerca, ignición off) llegue.
- Eventos tiempo real: `broadcast(new AlarmUpdated($alarm))` por canal privado `account.{id}.alarms` (Laravel Echo / Redis; Talentus ya tiene Redis). Equivale al `AlarmEvent` + `RealmFilter`.
- API REST (Fractal): `GET /api/alarms?status&severity&device_id&assignee_id&from&to`, `POST /api/alarms`, `PATCH /api/alarms/{id}` (status/assignee/severity), `POST /api/alarms/{id}/comments`, `POST /api/alarms/{id}/devices`.
- UI (Bootstrap 3 / jQuery): página "Alarmas" con tabla filtrable por estado/severidad/asignado (como `page-alarms.ts`: filtros `all / allActive / status`), badge de contadores en el header, panel lateral con detalle, enlaces a dispositivos (centrar mapa), comentarios e historial. Roles: `operator` ve/gestiona, `supervisor` asigna, `user` sólo ve las suyas.

---

## 2. (b) Atributos con metadatos: unidades, formato, constraints, read-only, store datapoints

### Qué hace OpenRemote

Archivos: `model/attribute/Attribute.java`, `MetaItem.java`, `MetaMap.java`; `model/value/MetaItemType.java`, `ValueDescriptor.java`, `AttributeDescriptor.java`, `ValueType.java`, `ValueConstraint.java`, `ValueFormat.java`; unidades en `model/Constants.java:106-164`.

**MetaItemType** (catálogo de metadatos aplicables a cualquier atributo) — los relevantes para Talentus:

| Meta | Tipo | Efecto |
|---|---|---|
| `label` | text | Etiqueta UI |
| `units` | text[] | Unidad **componible**: `["kilo","metre","per","hour"]` → "km/h"; UI traduce cada token (i18n). Códigos de moneda ISO 4217 en mayúsculas tienen tratamiento especial. |
| `format` | ValueFormat | Cómo renderizar: decimales, agrupación, `asOnOff`, `asOpenClosed`, `asPressedReleased`, `asSlider`, `resolution`, estilos de fecha (`dateStyle`, `timeStyle`, `momentJsFormat`), `multiline` |
| `constraints` | ValueConstraint[] | Validación: `size{min,max}`, `min`, `max`, `pattern{regexp,flags}`, `allowedValues`, `past/pastOrPresent/future/futureOrPresent`, `notEmpty/notBlank/notNull`; cada una con `message` i18n y `evaluate(value, now)` |
| `readOnly` | bool | Sólo lectura en UI (sin seguridad) |
| `secret` | bool | Mostrar oculto (contraseña) |
| `storeDataPoints` | bool | Guardar histórico en `ASSET_DATAPOINT` (por defecto se guarda si el atributo tiene `agentLink`) |
| `dataPointsMaxAgeDays` | int | **Deprecated** – ver sección 4 |
| `hasPredictedDataPoints`, `applyPredictedDataPoints`, `forecast` | bool/bool/ForecastConfiguration | Ver sección 3 |
| `ruleState`, `ruleResetImmediate` | bool | Expone el atributo al motor de reglas / permite re-disparo inmediato |
| `accessPublicRead/Write`, `accessRestrictedRead/Write` | bool | Visibilidad para enlaces públicos / usuarios restringidos |
| `showOnDashboard`, `momentary`, `multiline` | bool | UI |
| `attributeLinks` | AttributeLink[] | Propagar el valor a otro atributo aplicando `ValueFilter[]` (`regex`, `jsonPath`, `subString`, `mathExpression`) y un `converter` (mapa valor→valor) |
| `agentLink` | AgentLink | Enlace al protocolo: `valueFilters`, `valueConverter`, `writeValueFilters`, `writeValueConverter`, `messageMatchPredicate` (`model/asset/agent/AgentLink.java:49-95`) |

**ValueDescriptor** (`model/value/ValueDescriptor.java:118-131`): `name`, `type` (clase Java), `arrayDimensions`, `constraints[]`, `format`, `units[]`, `metaUseOnly`. Catálogo en `ValueType.java`: `boolean, integer, long, number, bigNumber, text, JSON, positiveInteger, negativeInteger, positiveNumber, byte, timestamp, timestampIso8601, dateAndTime, durationIso8601, periodIso8601, email, UUID, assetId, direction (0-360), port, hostOrIP, IPAddress, GEO_JSON_POINT, calendarEvent, colourRGB, HTTP_URL, WS_URL, cronExpression, assetQuery, forecastConfiguration, …`.

**AttributeDescriptor** = nombre + ValueDescriptor + meta por defecto + `optional`. Los descriptores "estáticos" viven en clases Java (`ShipAsset.SPEED = new AttributeDescriptor<>("speed", POSITIVE_NUMBER).withUnits(UNITS_KNOT)`), pero el **valor real de cada instancia** siempre va en el JSONB con su propio `meta`, que **sobrescribe/añade** al del descriptor (`Attribute.addOrReplaceMeta`).

**Persistencia del valor** (`AssetStorageService.updateAttributeValue`, línea 2169): `UPDATE asset SET attributes[?] = attributes[?] || '{"value":…,"timestamp":…}'::jsonb WHERE id=? AND delete_pending IS FALSE` — actualización atómica de un solo atributo sin reescribir el JSON entero.

**Pipeline de escritura** (`manager/asset/AssetProcessingService.processAttributeEvent`, línea 410): lock por asset → cargar asset → coerción de tipo (`ValueUtil.getValueCoerced`) → validación de constraints → cadena de `AttributeEventInterceptor` ordenados por prioridad (agentes, gateway, reglas, attribute-links) → si el evento es **más antiguo** que el valor actual se marca `OutdatedAttributeEvent` (no se actualiza el estado pero **sí se guarda en histórico**, `AssetDatapointService.onOutdatedAttributeEvent`) → UPDATE JSONB → publicar `AttributeEvent` a suscriptores websocket y a `AssetDatapointService` (que inserta datapoint si `storeDataPoints`).

### Diferencia con Talentus

Talentus tiene sensores por dispositivo con calibración y fórmulas (ya cubre `valueFilters`/`mathExpression`), pero el "tipo" de sensor y su unidad están hardcodeados por tipo de sensor; no hay metadatos declarativos (constraints, formato, read-only, "guardar histórico sí/no", visibilidad pública) ni posibilidad de definir atributos arbitrarios con tipo.

### Propuesta de porte a Laravel

No reemplazar las tablas de sensores; **añadir una capa de metadatos** reutilizable por sensores, campos personalizados y los futuros asset types (sección 6):

```php
Schema::create('attribute_definitions', function (Blueprint $t) {
    $t->increments('id');
    $t->unsignedInteger('account_id')->nullable();          // null = global/sistema
    $t->string('owner_type', 40);                            // 'sensor_type' | 'asset_type' | 'device_custom_field'
    $t->unsignedInteger('owner_id')->nullable();
    $t->string('name', 64);                                  // clave técnica: fuel_level, engine_hours...
    $t->string('label', 128);
    $t->string('value_type', 32);                            // boolean|integer|number|text|json|timestamp|geo_point|enum|duration|color|url
    $t->json('units')->nullable();                           // ["kilo","metre","per","hour"]
    $t->json('format')->nullable();                          // {"maximumFractionDigits":1,"asOnOff":true,...}
    $t->json('constraints')->nullable();                     // [{"type":"min","min":0},{"type":"max","max":100},{"type":"pattern","regexp":"^[A-Z]{3}-\\d{3}$"}]
    $t->boolean('read_only')->default(false);
    $t->boolean('secret')->default(false);
    $t->boolean('store_datapoints')->default(true);
    $t->unsignedInteger('retention_days')->nullable();       // sección 4
    $t->boolean('public_read')->default(false);              // visible en sharing público
    $t->boolean('show_on_dashboard')->default(false);
    $t->boolean('rule_state')->default(true);                // disponible para alertas
    $t->json('meta')->nullable();                            // extensible: forecast, attributeLinks...
    $t->unique(['owner_type','owner_id','name']);
});
```

- Clase `App\Support\Values\ValueType` (enum PHP 8 o constantes) con coerción (`coerce(mixed $v): mixed`) y `App\Support\Values\ConstraintEvaluator` que implementa `size/min/max/pattern/allowedValues/past/future/notEmpty/notBlank/notNull` como clases `Constraints\Min`, `Constraints\Max`… con `evaluate($value, Carbon $now): bool` y `message()` traducible (`resources/lang/es/constraints.php`). Se integra como `FormRequest` rules dinámicas al editar sensores/campos y como validación en el pipeline de ingestión.
- `App\Support\Values\UnitFormatter`: diccionario `resources/lang/es/units.php` con tokens (`kilo=>'k', metre=>'m', per=>'/', hour=>'h', celsius=>'°C', litre=>'L', percentage=>'%', rpm=>'rpm', knot=>'kn', degree=>'°'`) y una función `format(array $units): string` que concatena prefijo+unidad+`/`+unidad. Esto permite que cada sensor custom declare su unidad sin tocar código, y que el reporte/dashboard la pinte igual.
- `App\Support\Values\ValueFormat::render($value, array $format, string $locale)`: usa `NumberFormatter` (intl) para `minimumFractionDigits/maximumFractionDigits/useGrouping`, mapea booleanos a `on/off`, `abierto/cerrado`, fecha con `Carbon::isoFormat`.
- Blade component `<x-attribute-value :definition :value>` y helper JS en los widgets del dashboard que lean `format` y `units` del JSON que devuelve la API (Fractal transformer `AttributeDefinitionTransformer`).
- Para los sensores existentes: migración que **genere** una `attribute_definition` por `sensor_type` conocido (fuel → `number`, units `["litre"]`, constraints min 0; temp → `["celsius"]`; rpm → `["rpm"]`; ignition → `boolean` con `format.asOnOff`; odometer → `["kilo","metre"]`, read_only; engine_hours → `["hour"]`), y una FK `sensors.attribute_definition_id` para que un sensor custom pueda sobrescribir label/units/format/constraints por instancia (equivalente al `meta` por atributo).

---

## 3. (c) Forecast / predicción de series temporales

### Qué hace OpenRemote

Archivos: `model/value/ForecastConfiguration.java`, `ForecastConfigurationWeightedExponentialAverage.java`; `manager/asset/ForecastService.java`; `manager/datapoint/AssetPredictedDatapointService.java`, `ApplyPredictedDataPointsService.java`; `model/datapoint/AssetPredictedDatapoint.java`, `AssetPredictedDatapointResource.java`; tabla `ASSET_PREDICTED_DATAPOINT` (hypertable Timescale).

No hay Prophet ni ML externo: **el único algoritmo incluido es un promedio exponencial ponderado (WEA)** aplicado sobre muestras **periódicas** del pasado. Configuración (meta `forecast` del atributo):

```json
{"type":"wea","pastPeriod":"P1D","pastCount":7,"forecastPeriod":"PT1H","forecastCount":24}
```

Lógica (`ForecastService.ForecastTaskManager`):

1. Al arrancar busca todos los atributos con meta `forecast` (`AssetQuery().attributes(new AttributePredicate().meta(new NameValuePredicate(FORECAST, …)))`) y escucha `PERSISTENCE_TOPIC` para altas/bajas/cambios de atributos con esa meta.
2. Genera los timestamps futuros `now + forecastPeriod * i` (i = 1..forecastCount).
3. Para cada timestamp futuro toma `pastCount` muestras separadas `pastPeriod` hacia atrás (`calculateSampleTimestamps`, línea 493: `now - pastPeriod*k + forecastPeriod*i`), busca el datapoint más cercano a cada muestra en el histórico (`findSampleDatapoints` sobre buckets) y, si tiene las `pastCount` muestras, aplica:

```java
// a = 2 / (R + 1); Attr(t) = Attr(t-p) * a + Attr(t-2p) * (1 - a)   (reduce de la más antigua a la más reciente)
double a = 2 / (R + 1);
values.stream().map(v -> (Number) v).reduce((older, old) -> old.doubleValue()*a + older.doubleValue()*(1-a));
```

4. Hace `purgeValues(assetId, attr)` de la tabla de predicción y `updateValues(...)` con la nueva serie; publica `AssetPredictedDatapointEvent`. Re-planifica cada `forecastPeriod` (mínimo 15 min por defecto).
5. `ApplyPredictedDataPointsService`: si el atributo tiene `hasPredictedDataPoints=true` **y** `applyPredictedDataPoints=true`, programa (PriorityQueue por timestamp) escribir el valor predicho **como valor real del atributo** cuando llega su timestamp (sirve para programar valores futuros: tarifas, setpoints, horarios).
6. `AssetPredictedDatapointService.purgeDatapoints()` diario: borra predicciones antiguas; la API `/asset/predicted/datapoint` permite a un sistema externo (Python/Prophet) **inyectar** sus propias predicciones (`updateValues`), que el chart `or-chart` dibuja con línea discontinua junto al histórico.

### Diferencia con Talentus

Talentus no tiene nada de predicción. Para flota, los casos útiles no son "consumo del edificio" sino: **predicción de kilometraje/horas motor para mantenimiento preventivo**, **predicción de nivel de combustible / autonomía**, **hora estimada de llegada**, **tendencia de temperatura en cadena de frío**, **estimación de días hasta vencimiento de SOAT/revisión según uso**.

### Propuesta de porte a Laravel

```php
Schema::create('predicted_datapoints', function (Blueprint $t) {
    $t->unsignedInteger('device_id'); $t->string('attribute', 64);
    $t->timestamp('ts'); $t->double('value')->nullable(); $t->json('meta')->nullable(); // intervalo confianza, modelo
    $t->primary(['device_id','attribute','ts']);
});
Schema::create('forecast_configs', function (Blueprint $t) {
    $t->increments('id'); $t->unsignedInteger('device_id'); $t->string('attribute', 64);
    $t->string('type', 16);          // 'wea' | 'linear' | 'external'
    $t->json('params');              // {"pastPeriod":"P1D","pastCount":7,"forecastPeriod":"PT1H","forecastCount":24}
    $t->boolean('apply_predicted')->default(false);
    $t->timestamp('next_run_at')->nullable(); $t->timestamps();
    $t->unique(['device_id','attribute']);
});
```

- `App\Services\Forecast\WeightedExponentialAverageForecaster` (porte directo del algoritmo anterior; lee las muestras con una consulta "valor más cercano a timestamp" sobre la tabla de posiciones/sensores del dispositivo, usando `ORDER BY ABS(TIMESTAMPDIFF(SECOND, time, ?)) LIMIT 1` o el bucketing previo que hace OpenRemote para evitar N consultas).
- `App\Services\Forecast\LinearTrendForecaster` (regresión lineal simple sobre odómetro/horas motor → fecha estimada en que se alcanza el umbral de mantenimiento; esto es lo que de verdad usará un cliente de flota y OpenRemote no lo trae).
- `ForecastConfig` + comando `forecast:run` programado cada 15 min (Laravel scheduler) que procesa los `next_run_at <= now()`, escribe en `predicted_datapoints` (borrar + insertar como OpenRemote) y dispara `PredictedDatapointsUpdated`.
- Endpoint `PUT /api/devices/{id}/predicted/{attribute}` para inyectar predicciones externas (p. ej. un microservicio Python con Prophet) — mismo diseño que `AssetPredictedDatapointResource`.
- UI: en el gráfico de sensor del historial (Leaflet/Chart.js) añadir dataset "Predicción" con línea discontinua; en el módulo de mantenimiento mostrar "fecha estimada" calculada por `LinearTrendForecaster`.
- `apply_predicted`: sólo tiene sentido para atributos escribibles (p. ej. "límite de velocidad programado por horario"); se puede dejar fuera en la primera fase.

---

## 4. (d) Retención / purga de histórico y almacenamiento de datapoints

### Qué hace OpenRemote

Archivos: `manager/datapoint/AssetDatapointService.java`, `AbstractDatapointService.java`, `AssetPredictedDatapointService.java`; `manager/syslog/SyslogService.java`; SQL `sql/V20230120_01__Timescale.sql`, `V20260506_02__EnableHyperCore.sql`; config en `profile/deploy.yml:362-370`.

- Datapoints: tabla `asset_datapoint(timestamp, entity_id, attribute_name, value jsonb)` convertida a **hypertable TimescaleDB** con chunks de 7 días, **columnstore (compresión) a partir de 7 días**, `segmentby = entity_id, attribute_name`, `orderby = timestamp DESC`.
- Escritura: `upsertValue` → `INSERT … ON CONFLICT (timestamp, entity_id, attribute_name) DO UPDATE` (un punto por milisegundo por atributo).
- Retención: variable `OR_DATA_POINTS_MAX_AGE_WEEKS` (−1 = no purgar). Tarea semanal `purgeDataPoints()` que ejecuta `SELECT drop_chunks('asset_datapoint', older_than => cutoff)`. La retención **por atributo** (`dataPointsMaxAgeDays`) fue **deprecada** porque `drop_chunks` es global: ahora el valor máximo entre atributos sólo puede *ampliar* la retención global, nunca acortarla (comentario en `MetaItemType.DATA_POINTS_MAX_AGE_DAYS`). La versión anterior sí hacía `DELETE … WHERE timestamp < now - N days` por atributo (`AbstractDatapointService.doPurge(whereClause, date)` sigue existiendo).
- Límites: `OR_DATA_POINTS_QUERY_LIMIT` (100 000 puntos por consulta, se verifica con un `COUNT` previo y se lanza `DatapointQueryTooLargeException` → HTTP 413) y `OR_DATA_POINTS_EXPORT_LIMIT` (1 000 000 para export CSV/JSON en streaming con `PipedInputStream`).
- Predicciones: purga diaria propia. Syslog: `OR_SYSLOG_MAX_AGE_DAYS` (5) con purga diaria a las ~03:00.
- **Consultas agregadas** (`model/datapoint/query/*`): `AssetDatapointAllQuery` (crudo), `AssetDatapointNearestQuery` (valor más cercano a un instante), `AssetDatapointLTTBQuery` (downsampling *Largest-Triangle-Three-Buckets* vía función `lttb()` del toolkit Timescale: devuelve N puntos visualmente representativos), `AssetDatapointIntervalQuery` con `interval` ("1 hour", "15 minutes") + `formula ∈ {MIN, AVG, MAX, SUM, COUNT, DIFFERENCE, MODE, MEDIAN}` + `gapFill` (`time_bucket_gapfill` + `locf`). `DIFFERENCE` usa `last(value) - LAG(...)` por bucket (ideal para consumo/odómetro). Soporta números y booleanos (para booleanos SUM = tiempo en true).

### Diferencia con Talentus

Talentus guarda posiciones en tablas por dispositivo (MySQL) y no tiene: política de retención configurable (por cuenta/dispositivo/sensor), compresión/particionado automático, límite de puntos por consulta, ni consultas agregadas reutilizables (cada uno de los 97 reportes agrega a su manera en PHP con el pipeline Append*/Group*). El downsampling LTTB tampoco existe (los gráficos de sensores cargan todo el rango).

### Propuesta de porte a Laravel/MySQL

1. **Particionado** de las tablas de posiciones por rango de tiempo (MySQL `PARTITION BY RANGE (TO_DAYS(time))`, una partición por mes; comando `positions:partitions:ensure` que crea las futuras y `ALTER TABLE … DROP PARTITION` para purgar → equivalente barato de `drop_chunks`). Si las tablas por dispositivo son muchas, alternativamente consolidar en una tabla `positions` particionada por mes con `(device_id, time)` como clave (segmentby equivalente).
2. **Política de retención** en tres niveles (como OpenRemote antes de deprecar): `accounts.retention_days` (plan comercial), `devices.retention_days` (override), `attribute_definitions.retention_days` (para sensores "ruidosos" tipo RPM). Comando diario `history:purge` que, por dispositivo, borra en lotes (`DELETE … WHERE time < ? LIMIT 10000` en bucle) o suelta particiones completas cuando toda la partición es más vieja que la retención mínima de la cuenta. Registrar cada purga en el syslog (sección 7).
3. **Tabla de agregados** `sensor_rollups(device_id, attribute, bucket_start, interval ENUM('5m','1h','1d'), min, max, avg, sum, count, last, first)` llenada por un job incremental cada 5 min (equivalente a *continuous aggregates*); los widgets y reportes de largo rango consultan esto en vez de las posiciones crudas. Mantiene MySQL rápido sin Timescale.
4. **Servicio `DatapointQueryService`** con las mismas 4 consultas: `all`, `nearest(ts)`, `interval(interval, formula, gapFill)` y `lttb(points)`. LTTB se implementa en PHP (algoritmo ~60 líneas) sobre el resultado crudo o sobre rollups; `interval` se traduce a `GROUP BY FLOOR(UNIX_TIMESTAMP(time)/:secs)` con `MIN/MAX/AVG/SUM/COUNT`, `DIFFERENCE` con variables de ventana (`LAG()` en MySQL 8) y `gapFill` generando la serie de buckets en PHP. Límite configurable `HISTORY_QUERY_LIMIT` (100k) que devuelve 413 como OpenRemote.
5. Exponer como API `GET /api/devices/{id}/history/{attribute}?type=lttb&points=500&from&to` y usarlo en el gráfico de sensores (hoy cargando todo).

---

## 5. (e) Webhooks robustos

### Qué hace OpenRemote

Archivos: `model/webhook/Webhook.java`, `manager/webhook/WebhookService.java`, `manager/rules/facade/WebhooksFacade.java`, `model/rules/json/RuleActionWebhook.java`, sustitución en `manager/rules/JsonRulesBuilder.java:1395-1430`.

```java
public class Webhook { String name; String url; Map<String, List<String>> headers; HTTPMethod httpMethod;
                       UsernamePassword usernamePassword; OAuthGrant oAuthGrant; String payload; }
```

- Autenticación **Basic** u **OAuth2** (`OAuthGrant`: client credentials / password / refresh token, el `WebTargetBuilder` renueva el token).
- `payload` es texto libre; si contiene `%TRIGGER_ASSETS%` se reemplaza por JSON de los assets/atributos que dispararon la regla (`insertTriggeredAssetInfo`); también existe `%ASSET_ID%`, `%RULESET_ID%`, `%RULESET_NAME%`.
- Content-Type tomado del header o `application/json` por defecto.
- `sendHttpRequest` sólo registra éxito/fallo en el log: **no hay reintentos, ni cola, ni registro de entregas, ni firma HMAC**. Es más simple que lo que probablemente ya tiene Talentus.

### Diferencia con Talentus

Talentus ya tiene webhook como canal de alerta. Lo que vale la pena tomar de OpenRemote es: la **plantilla de payload con placeholders**, el soporte **OAuth2** y la **cabecera Content-Type configurable**. Lo que hay que **añadir (OpenRemote tampoco lo tiene)**: reintentos con backoff, log de entregas y firma.

### Propuesta de porte a Laravel

```php
Schema::create('webhook_endpoints', function (Blueprint $t) {
    $t->increments('id'); $t->unsignedInteger('account_id')->index(); $t->string('name');
    $t->string('url', 2048); $t->string('http_method', 8)->default('POST');
    $t->json('headers')->nullable();                         // {"Content-Type":["application/json"],"X-Api-Key":["..."]}
    $t->string('auth_type', 16)->default('none');            // none|basic|bearer|oauth2_client_credentials|hmac
    $t->text('auth_config')->nullable();                     // encrypted cast
    $t->longText('payload_template')->nullable();            // Blade-like: {"device":"{{ device.name }}","lat":{{ position.lat }},...}
    $t->string('secret', 128)->nullable();                   // firma HMAC-SHA256 en header X-Signature
    $t->unsignedTinyInteger('max_attempts')->default(5);
    $t->boolean('enabled')->default(true); $t->timestamps();
});
Schema::create('webhook_deliveries', function (Blueprint $t) {
    $t->bigIncrements('id'); $t->unsignedInteger('endpoint_id')->index();
    $t->string('event_type', 64); $t->string('event_id', 64)->nullable();   // alert_event:123, alarm:45
    $t->longText('request_body'); $t->json('request_headers')->nullable();
    $t->unsignedSmallInteger('response_status')->nullable(); $t->text('response_body')->nullable();
    $t->unsignedTinyInteger('attempt')->default(1); $t->string('status', 16); // pending|success|failed|dead
    $t->string('error')->nullable(); $t->timestamp('next_retry_at')->nullable(); $t->timestamps();
});
```

- Job `DeliverWebhook implements ShouldQueue` con `$tries = 5`, `backoff() => [30, 120, 600, 3600, 21600]`, idempotencia por header `X-Delivery-Id`; usa `Http::withHeaders()->withBasicAuth()/withToken()` y para OAuth2 un `OAuthTokenProvider` cacheado en Redis. Guarda cada intento en `webhook_deliveries`.
- Plantilla: motor seguro (no Blade real por seguridad) tipo Mustache/`Illuminate\Support\Str::of()->replaceMatches('/\{\{\s*([\w.]+)\s*\}\}/')` sobre un contexto plano `device.*, position.*, sensor.*, geofence.*, alert.*, alarm.*, user.*`; placeholder `{{ trigger_json }}` equivale a `%TRIGGER_ASSETS%`.
- UI: en la alerta, selector de endpoint + botón "Probar" (envío con payload de ejemplo) + pestaña "Entregas" con filtro por estado y botón "Reenviar". Eventos emisores: alertas (ya), alarmas (nuevo), cambios de estado de dispositivo (online/offline), creación de viaje.

---

## 6. (f) Asset types dinámicos (definibles por el usuario)

### Qué hace OpenRemote

Archivos: `model/asset/AssetDescriptor.java`, `AssetTypeInfo.java`, `AssetModelProvider.java`, `StandardModelProvider.java`; `manager/asset/AssetModelService.java` (incl. `initDynamicModel`, `DIRECTORY_NAME = "asset_model"`); `model/asset/AssetModelResource.java`; test `test/src/test/groovy/org/openremote/test/model/AssetModelTest.groovy:82-101`.

- Un **asset type** = `AssetDescriptor {name, icon (MDI), colour}` + lista de `AttributeDescriptor` + `MetaItemDescriptor[]` propios + `ValueDescriptor[]` propios ⇒ `AssetTypeInfo`.
- Los tipos "de fábrica" son clases Java (`ThingAsset`, `BuildingAsset`, `RoomAsset`, `ShipAsset`, `WeatherAsset`, `ElectricityBatteryAsset`, `GroupAsset`, `ConsoleAsset`…) descubiertas por reflexión. Los tipos **dinámicos** se cargan desde ficheros JSON en `<storageDir>/asset_model/<Nombre>` al arrancar (`AssetModelService.initDynamicModel` → `loadDescriptors(AssetTypeInfo.class, storageDir)`), con `AssetDescriptor.isDynamic()=true`. En el test se ve el formato: un `AssetTypeInfo` serializado con `assetDescriptor`, `attributeDescriptors`, `metaItemDescriptors`, `valueDescriptors` (un `valueDescriptor` dinámico puede llevar `constraints` tipo `allowedValues`).
- La API `GET /model/asset/descriptors?parentId&parentType` y `GET /model/asset/info/{type}` devuelve la lista para que la UI construya formularios de "Añadir asset" y el editor de atributos (`ui/component/or-asset-tree`, `or-attribute-input`).
- La columna `ASSET.TYPE` guarda el nombre del tipo; Hibernate usa `UnknownAsset` como fallback para tipos no registrados, así que **borrar un tipo no rompe los datos**.
- `GroupAsset` tiene atributo `childAssetType` para agrupar assets homogéneos. Jerarquía padre/hijo con `ltree PATH` (`update_asset_parent_info()` trigger) y consultas `PathPredicate`/`ParentPredicate` recursivas.

### Diferencia con Talentus

Talentus modela sólo dispositivos GPS (vehículo = dispositivo) con campos personalizados planos. Un cliente que quiera registrar **remolques, contenedores, maquinaria sin GPS, conductores como asset, sedes/almacenes, cisternas** no puede, y tampoco puede relacionarlos (tracto → remolque, flota → sede).

### Propuesta de porte a Laravel

```php
Schema::create('asset_types', function (Blueprint $t) {
    $t->increments('id'); $t->unsignedInteger('account_id')->nullable(); // null = tipo del sistema
    $t->string('name', 64); $t->string('label', 128); $t->string('icon', 64)->nullable(); $t->string('color', 7)->nullable();
    $t->unsignedInteger('parent_type_id')->nullable();                  // herencia simple de atributos
    $t->boolean('is_system')->default(false); $t->timestamps();
    $t->unique(['account_id','name']);
});
// atributos del tipo: filas en attribute_definitions con owner_type='asset_type', owner_id=asset_types.id (sección 2)
Schema::create('assets', function (Blueprint $t) {
    $t->increments('id'); $t->unsignedInteger('account_id')->index(); $t->unsignedInteger('asset_type_id')->index();
    $t->string('name'); $t->unsignedInteger('parent_id')->nullable()->index(); $t->string('path', 512)->index(); // "1/14/203"
    $t->unsignedInteger('device_id')->nullable()->unique();             // vínculo opcional al GPS
    $t->json('attributes');                                              // {"plate":{"value":"ABC-123","ts":...},"capacity":{"value":30000,"ts":...}}
    $t->boolean('public_read')->default(false); $t->timestamps(); $t->softDeletes();
});
```

- Tipos del sistema sembrados: `Vehicle` (enlazado a `device`), `Trailer`, `Driver` (puente con la tabla `drivers`), `Site`/`Warehouse` (con `location` geo_point), `Group`. El usuario (rol admin/reseller) crea tipos nuevos en una pantalla "Tipos de activo" con constructor de atributos (nombre, etiqueta, tipo de valor, unidades, formato, constraints, read-only, guardar histórico) — eso es el "Asset Type Builder" apoyado en la tabla de la sección 2.
- `App\Models\Asset` con cast `attributes` a `AsArrayObject`, accesor `attr($name)`, `setAttr($name, $value, $ts)` que valida contra la definición (coerción + constraints) y, si `store_datapoints`, inserta en `asset_datapoints(asset_id, attribute, ts, value JSON)`. Path materializado estilo ltree con `LIKE 'path%'` para consultas recursivas (índice prefix).
- Vinculación con el GPS: al llegar una posición, un listener copia a los atributos del asset `Vehicle` los sensores marcados `rule_state` (velocidad, ignición, odómetro…) → las alertas/alarmas pueden usar atributos de assets no-GPS (p. ej. "temperatura del remolque enganchado").
- API: `GET /api/asset-types`, `GET /api/asset-types/{id}/info` (descriptor + atributos → formulario dinámico en la UI), CRUD `/api/assets` con `?type&parent_id&recursive`, y `AssetQuery` ligero (sección 8).

---

## 7. (g) Notificaciones localizadas, targets y syslog centralizado

### 7.1 Notificaciones — qué hace OpenRemote

Archivos: `model/notification/Notification.java`, `SentNotification.java`, `AbstractNotificationMessage.java`, `EmailNotificationMessage.java`, `PushNotificationMessage.java`, `LocalizedNotificationMessage.java`, `RepeatFrequency.java`, `PushNotificationAction.java`, `PushNotificationButton.java`; `manager/notification/NotificationService.java`, `EmailNotificationHandler.java`, `PushNotificationHandler.java`, `LocalizedNotificationHandler.java`; SQL `V20191202_01__Schema.sql` (tabla `NOTIFICATION`) y `V20260715_1443__AddNotificationRealm.sql`.

```java
public class Notification { String name; AbstractNotificationMessage message; RepeatFrequency repeatFrequency; String repeatInterval; List<Target> targets; }
enum TargetType { REALM, USER, ASSET, CUSTOM }   // Target {type, id, locale, allowedLocales, data}
enum RepeatFrequency { ALWAYS, ONCE, HOURLY, DAILY, WEEKLY, MONTHLY, ANNUALLY }
```

- `message` es polimórfico por `type`: `email` (`from, replyTo, subject, text, html, to[], cc[], bcc[]`), `push` (`title, body, action{url, httpMethod, data, silent, openInBrowser}, buttons[{title, action}], data, priority, targetType(DEVICE|TOPIC|CONDITION), ttlSeconds`) y **`localized`** (`defaultLanguage`, `languages: {"es": {email…}, "en": {push…}}`).
- **Resolución de targets por handler**: `REALM` → todos los usuarios del realm (email) o todas las consolas del realm (push); `USER` → usuario (email) o sus consolas (push); `ASSET` → si es `ConsoleAsset` usa su token FCM, si no, los usuarios enlazados al asset (`UserQuery().assets(id)`) o el atributo `email` del asset; `CUSTOM` → dirección literal / topic FCM. Los usuarios con atributo `emailNotificationsDisabled` se excluyen.
- `LocalizedNotificationHandler.getTargets` pide los targets a cada handler por idioma y marca en cada target los `allowedLocales`; al enviar elige el idioma del target (preferencia del usuario) → fallback `defaultLanguage`.
- **Anti-spam `okToSendNotification`** (línea 704): busca la última `SentNotification` con mismo `source + sourceId + target + targetId + name`; `ONCE` nunca repite; `HOURLY/DAILY/…` sólo reenvía tras el truncado de periodo (`lastSend.truncatedTo(DAYS).plus(1, DAYS)`); `repeatInterval` admite ISO‑8601 (`PT30M`).
- Tabla `NOTIFICATION` (= `SentNotification`): `id, name, type, target, target_id, source, source_id, realm, message jsonb, error, sent_on, delivered_on, acknowledged_on, acknowledgement`. Hay endpoints para marcar **entregada** y **reconocida** (`setNotificationDelivered`, `setNotificationAcknowledged(id, acknowledgement)`) — las apps móviles llaman al abrir la push y al pulsar un botón, lo que permite medir lectura.
- API con filtros por tipo, from/to, realm, user, asset y paginación/orden.

### 7.2 Syslog — qué hace OpenRemote

Archivos: `model/syslog/SyslogEvent.java`, `SyslogCategory.java`, `SyslogLevel.java`, `SyslogConfig.java`, `SyslogResource.java`; `manager/syslog/SyslogService.java`; tabla `SYSLOG_EVENT(id, timestamp, category, level, message varchar(131072), subcategory)`.

- `SyslogService` es un `java.util.logging.Handler`: cada `LogRecord` se mapea a `SyslogEvent` con `category ∈ {ASSET, AGENT, NOTIFICATION, ALARM, RULES, PROTOCOL, GATEWAY, MODEL_AND_VALUES, API, DATA}` y `subcategory` (nombre del logger, p. ej. ruleset o protocolo), se **persiste** si nivel ≥ `storedLevel` y categoría ∈ `storedCategories`, y se **publica por websocket** a la UI (`page-logs.ts` muestra el log en vivo con filtros).
- Config runtime (`SyslogConfig {storedLevel, storedCategories, storedMaxAgeMinutes}`) editable por API; purga diaria ~03:00 según `OR_SYSLOG_MAX_AGE_DAYS` (5 por defecto).

### Diferencia con Talentus

Talentus envía email/SMS/push/webhook por alerta pero: no tiene mensajes **multi-idioma por destinatario**, no tiene **targets abstractos** (cuenta entera, usuarios enlazados a un dispositivo, "custom"), no registra `delivered/acknowledged`, y su anti-repetición es por alerta (no por destino+nombre+frecuencia). No tiene un log de sistema consultable por el usuario (sólo logs Laravel en disco y change logs de entidades).

### Propuesta de porte a Laravel

```php
Schema::create('notification_templates', function (Blueprint $t) {
    $t->increments('id'); $t->unsignedInteger('account_id')->nullable(); $t->string('name', 64);
    $t->string('channel', 16);               // email|push|sms|whatsapp
    $t->string('default_locale', 5)->default('es');
    $t->json('locales');                     // {"es":{"subject":"...","body":"..."},"en":{...}} con placeholders {{ device.name }}
    $t->json('actions')->nullable();         // push: {"url":"/devices/{{device.id}}","buttons":[{"title":"Ver","url":...},{"title":"Reconocer","url":"/api/alarms/{{alarm.id}}/ack"}]}
    $t->timestamps();
});
Schema::create('sent_notifications', function (Blueprint $t) {
    $t->bigIncrements('id'); $t->unsignedInteger('account_id')->index(); $t->string('name', 64)->index();
    $t->string('channel', 16); $t->string('target_type', 16); $t->string('target_id', 64);   // account|user|device|custom
    $t->string('source', 24); $t->string('source_id', 64)->nullable();
    $t->json('message'); $t->string('locale', 5)->nullable();
    $t->text('error')->nullable(); $t->timestamp('sent_at'); $t->timestamp('delivered_at')->nullable();
    $t->timestamp('acknowledged_at')->nullable(); $t->string('acknowledgement', 64)->nullable();
    $t->index(['source','source_id','target_type','target_id','name','sent_at']);
});
```

- `App\Services\Notifications\NotificationDispatcher::send(NotificationRequest $r)` con `targets[] {type: account|user|device|custom, id}` y `repeat: always|once|hourly|daily|weekly|monthly|P T…`. Resolver targets como OpenRemote: `account` → usuarios de la cuenta con el canal habilitado; `device` → usuarios con acceso al dispositivo (ya existe la relación usuario‑dispositivo en Talentus) o el campo email/teléfono del dispositivo/conductor; `custom` → dirección literal. Comprobar `okToSend` con la última fila de `sent_notifications`.
- Localización: `users.locale` (ya hay i18n en Laravel) y plantillas por idioma; fallback a `default_locale`. Sustitución de placeholders con el mismo motor seguro de la sección 5.
- Push: la app GPS UBICAR/Orbitrack registra el token FCM; añadir endpoints `POST /api/notifications/{id}/delivered` y `/acknowledged` que la app llame al recibir/abrir → métricas de lectura y botón "Reconocer alarma" directo desde la push.
- Pantalla "Notificaciones enviadas" con filtros (canal, destino, estado, fechas) y reintento manual.

**Syslog en Laravel**:

```php
Schema::create('syslog_events', function (Blueprint $t) {
    $t->bigIncrements('id'); $t->unsignedInteger('account_id')->nullable()->index();
    $t->string('category', 24)->index();   // device|protocol|alert|alarm|notification|webhook|report|api|auth|maintenance|system
    $t->string('subcategory', 128)->nullable(); $t->unsignedTinyInteger('level'); // 0 debug..4 error (SyslogLevel)
    $t->text('message'); $t->json('context')->nullable(); $t->timestamp('created_at')->index();
});
```

- Canal Monolog personalizado `syslog_db` (handler que escribe en la tabla en lote + `Redis::publish('syslog', …)` para la vista en vivo), configurable en `config/syslog.php`: `stored_level`, `stored_categories`, `max_age_days` (comando `syslog:purge` diario). Usar `Log::channel('syslog_db')->withContext(['category'=>'protocol','device_id'=>…])` en el decodificador de tramas, en alertas, webhooks y purgas → el soporte técnico (rol de Jhamner) deja de depender de `storage/logs`. Página "Registro del sistema" con filtros por categoría/nivel/dispositivo/fechas y streaming (Echo).

---

## 8. Extras que conviene tomar aunque no estén en la lista priorizada

### 8.1 AssetQuery: un lenguaje de consulta serializable (JSON) reutilizado en API, reglas y notificaciones

`model/query/AssetQuery.java`: `{recursive, select{attributes[]}, access, ids[], names[StringPredicate], parents[], paths[], realm, userIds[], types[], attributes: LogicGroup<AttributePredicate>, orderBy, limit, offset}`. `AttributePredicate {name: StringPredicate, value: ValuePredicate, meta[], previousValue, timestampOlderThan}`. `ValuePredicate` polimórfico: `string {match EXACT|BEGIN|END|CONTAINS, caseSensitive, value, negate}`, `number {operator EQUALS|GREATER_THAN|GREATER_EQUALS|LESS_THAN|LESS_EQUALS|BETWEEN, value, rangeValue, negate}`, `boolean`, `datetime`, `array`, `valueEmpty`, `valueAny`, `calendarEvent`, y geocercas `radial {lat,lng,radius}`, `rect {latMin,lngMin,latMax,lngMax}`, `geojson`. `LogicGroup {operator AND|OR, items[], groups[]}` permite anidar. El mismo objeto se usa en `RuleActionTarget.matchedAssets/assets` y para las suscripciones websocket.

Porte: `App\Support\Query\AssetQuery::fromArray($json)->apply(Builder $q)` que traduce a Eloquent/`JSON_EXTRACT` sobre `assets.attributes` y `devices`; reutilizar en filtros de la lista de dispositivos, en "grupo dinámico de dispositivos" (filtro guardado), en destinatarios de alertas y en widgets del dashboard. Es la base para los "grupos inteligentes" que un cliente de flota pide siempre ("todos los que llevan >12h de motor hoy").

### 8.2 ClientEventService: suscripciones websocket con filtros autorizados

`manager/event/ClientEventService.java`, `EventSubscriptionAuthorizer`, `model/event/shared/EventSubscription.java` (`eventType, filter, subscriptionId`), `model/asset/AssetFilter.java` (`assetIds, assetNames, assetTypes, realm, parentIds, path, attributeNames, publicEvents, restrictedEvents, valueChanged, userAssetIds`). Un cliente envía `SUBSCRIBE:{"eventType":"attribute","filter":{"assetIds":[...],"attributeNames":["location"],"valueChanged":true}}` y el servidor aplica además un filtro de autorización por realm/usuario antes de emitir. Porte: canales privados de Laravel Echo (`account.{id}`, `device.{id}`) + `valueChanged` server-side para no reenviar posiciones repetidas; eventos tipados `AttributeEvent`, `AlarmEvent`, `SyslogEvent`, `PredictedDatapointsEvent`.

### 8.3 Attribute links con filtros de valor

`MetaItemType.ATTRIBUTE_LINKS` + `AttributeLink {ref, converter, filters[]}` con `ValueFilter`s `regex`, `jsonPath`, `subString`, `mathExpression` (exp4j). Porte: "sensor derivado" en Talentus = atributo que se calcula a partir de otro de **otro dispositivo/asset** (p. ej. remolque toma la velocidad del tracto) con una fórmula; hoy las fórmulas custom sólo miran el propio dispositivo.

### 8.4 Eventos "outdated"

Si llega una posición/valor con timestamp anterior al actual (buffer offline del equipo), OpenRemote **no** sobrescribe el estado actual pero **sí** lo guarda en histórico (`OutdatedAttributeEvent`). Conviene verificar que el ingestor de Talentus hace lo mismo con tramas almacenadas (Teltonika/FMC150 envía lotes históricos).

### 8.5 CalendarEvent (iCal RRULE) como tipo de valor

`model/calendar/CalendarEvent.java {start, end, recurrence: Recur}` y `CalendarEventPredicate` en consultas: permite atributos "horario de operación" con recurrencia iCal y reglas "sólo activa dentro del horario". Útil para alertas por horario laboral / fin de semana (Talentus tiene horarios en alertas pero no RRULE).

---

## 9. Hoja de ruta sugerida (orden de implementación en Talentus)

| Fase | Entregable | Dependencias | Esfuerzo estimado |
|---|---|---|---|
| 1 | **Alarmas** (tablas, servicio, API, UI, acción "crear alarma" en alertas, notificación a assignee) | ninguna | 2–3 semanas |
| 2 | **Syslog en BD** + página de registro en vivo | Redis/Echo | 1 semana |
| 3 | **Webhooks robustos** (endpoints, plantillas, cola con reintentos, log de entregas, HMAC) | colas Laravel | 1–2 semanas |
| 4 | **Metadatos de atributos** (`attribute_definitions`, unidades/formato/constraints, migración de sensores) | — | 2 semanas |
| 5 | **Retención y agregados** (particionado, `history:purge` multinivel, rollups, `DatapointQueryService` con LTTB/interval) | 4 | 2–3 semanas |
| 6 | **Notificaciones localizadas/targets/anti-repetición/acuse** | 1, 4 | 2 semanas |
| 7 | **Asset types dinámicos** + `AssetQuery` + grupos inteligentes | 4 | 3–4 semanas |
| 8 | **Forecast** (WEA + tendencia lineal para mantenimiento, inyección externa) | 5 | 1–2 semanas |

Decisiones de diseño a respetar al portar (aprendidas del código de OpenRemote):

1. Separar el **DTO de entrada** (`Alarm`, `Notification`) de la **entidad persistida** (`SentAlarm`, `SentNotification`): facilita la API y la auditoría.
2. Autorización en el **controlador**, no en el servicio; el servicio documenta "el llamador es responsable de la autorización de la cuenta" (`AGENTS.md`, sección *Access control*).
3. Todo cambio de estado publica un **evento tipado** (CREATE/UPDATE/DELETE + realm/cuenta) para la UI en tiempo real.
4. Límites duros configurables (puntos por consulta, exportación, tamaño de contenido 4096) y respuestas 413 en vez de tumbar MySQL.
5. La retención *por atributo* OpenRemote la terminó deprecando por coste operativo con Timescale; en MySQL con particiones mensuales por tabla sí se puede mantener multinivel, pero hay que tener un **mínimo global por cuenta** para poder soltar particiones completas.
