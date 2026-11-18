# Análisis del motor de reglas de OpenRemote y propuesta de porte a Talentus Pro Tracking (Laravel)

Fuente analizada: `/home/claude/openremote/core` (OpenRemote manager, Java 21 + UI Lit/TypeScript).
Todas las rutas de este informe son relativas a esa carpeta.

---

## 0. Resumen ejecutivo: qué tiene OpenRemote que Talentus no tiene

| Capacidad OpenRemote | Dónde vive | Talentus hoy | Prioridad de porte |
|---|---|---|---|
| Reglas **when-then declarativas (JSON)** combinables por el usuario: grupos AND/OR anidados de condiciones, cada condición con N predicados sobre atributos | `model/.../rules/json/JsonRule.java`, `manager/.../rules/JsonRulesBuilder.java` | Una clase PHP fija por tipo de alerta | Alta |
| **Predicados tipados** (number con operador/BETWEEN, string EXACT/BEGIN/END/CONTAINS, boolean, datetime con ventana deslizante, array, value-empty, geocerca radial/rectangular/GeoJSON) con negación | `model/.../query/filter/*Predicate.java` | Umbrales fijos por tipo | Alta |
| **Condición temporal "si permanece X minutos"** (`duration` por predicado) y **"no actualizado desde hace X"** (`timestampOlderThan`) | `RuleCondition.duration`, `AttributePredicate.timestampOlderThan`, `JsonRulesBuilder.asAttributeMatcher` | Idle/offline hardcodeados | Alta |
| **Disparo por flanco + reset automático**: la regla dispara al pasar de no-cumple a cumple; se "re-arma" cuando la condición deja de cumplirse (o inmediatamente con meta `ruleResetImmediate`) | `JsonRulesBuilder.RuleConditionState.previouslyMatchedAssetStates` | Cada AlertCheck implementa su propio anti-duplicado | Alta |
| **Recurrencia / cooldown configurable** (`recurrence.mins`: null = una vez hasta reset, 0 = siempre, N = cada N minutos; scope PER_ASSET o GLOBAL) | `RuleRecurrence.java`, `JsonRulesBuilder.buildRhsAction` | No configurable | Alta |
| **Rama `otherwise`** (acciones para los assets que NO cumplen) y hooks `onStart`/`onStop` | `JsonRule.otherwise/onStart/onStop` | No | Media |
| **Acciones encadenadas con retardo** (`wait` acumulativo), múltiples acciones por regla, targets por acción (assets de la condición X, consulta de assets, consulta de usuarios, usuarios vinculados al asset, custom) | `RuleAction*.java`, `RuleActionTarget.java`, `executeRuleActions` | 1 alerta → canales fijos | Alta |
| Acción **write-attribute / update-attribute** (la regla modifica el estado del asset, lo que puede disparar otras reglas) | `RuleActionWriteAttribute`, `RuleActionUpdateAttribute` | Solo Command GPRS | Media |
| Acción **alarm** (incidente con severidad/estado/asignado, vinculado a assets) | `RuleActionAlarm`, `facade/AlarmFacade.java` | Evento en tabla events | Media |
| **Triggers temporales**: cron UTC, posición solar (sunrise/sunset/twilight con offset) | `RuleCondition.cron/sun`, `SunPositionTrigger.java` | Horarios por alerta solo como filtro | Media |
| **Validez programada del ruleset** (CalendarEvent start/end + RRULE → estados PAUSED/EXPIRED) | `Ruleset.meta.validity`, `RulesetDeployment.getNextOrActiveFromTo` | Días/horas activos (similar, menos general) | Baja |
| **Plantillas en mensajes**: `%RULESET_NAME%`, `%RULESET_ID%`, `%TRIGGER_ASSETS%` (tabla HTML/texto/JSON), `%ASSET_ID%` | `JsonRulesBuilder.insertTriggeredAssetInfo` | Plantillas parciales | Media |
| **Anti-spam de notificaciones** por `repeatFrequency` (ONCE/HOURLY/DAILY/…)/`repeatInterval` con histórico `SentNotification` | `manager/.../notification/NotificationService.okToSendNotification` | No | Media |
| **Reglas con script Groovy** (DSL `rules.add().when{}.then{}`, hechos temporales, facades) | `RulesBuilder.java`, `RulesetDeployment.compileRulesGroovy` | No | Baja (riesgo seguridad) |
| **Flow rules** (grafo de nodos INPUT/PROCESSOR/OUTPUT con editor visual) | `model/.../rules/flow/*`, `manager/.../rules/flow/NodeModel.java`, `ui/component/or-rules/src/flow-viewer` | No | Baja |
| **Ámbitos**: global / realm (tenant) / asset (subárbol) con un motor por ámbito | `RulesService.getEnginesInScope` | Alertas por usuario/dispositivo | Media (realm = empresa cliente) |
| **Detección de bucles** (100 disparos por ejecución), `continueOnError`, estados de despliegue publicados a la UI | `RulesFacts.MAX_RULES_TRIGGERED_PER_EXECUTION`, `RulesetStatus` | No | Media |
| **Datos predichos / históricos** accesibles desde reglas (`PredictedDatapoints`, `HistoricDatapoints`, nodo `HISTORIC_VALUE`) | `facade/PredictedFacade.java`, `HistoricFacade.java` | No | Baja |
| **Geocercas empujadas al cliente** (consola móvil evalúa localmente y notifica) | `rules/geofence/ORConsoleGeofenceAssetAdapter.java` | Servidor evalúa | Baja |
| **Editor visual** when-then (or-rules) configurable por white-label (`RulesConfig`) | `ui/component/or-rules/src/json-viewer/*` | Formularios fijos | Alta (concepto) |

---

## 1. Arquitectura del motor

### 1.1 Piezas y responsabilidades

```
AttributeEvent (cambio de atributo de un asset, ya persistido)
        │
        ▼
RulesService (manager/src/main/java/org/openremote/manager/rules/RulesService.java)
  - isRuleState(): solo atributos con meta ruleState=true (o con agentLink) entran al motor
  - getEnginesInScope(realm, assetPath): global → realm → asset (de ancestro a hijo)
  - gestiona 1 RulesEngine por ámbito: globalEngine, realmEngines{realm}, assetEngines{assetId}
        │
        ▼
RulesEngine<T extends Ruleset> (RulesEngine.java)
  - buffers insertInfos/updateInfos/retractInfos (sincronizados)
  - scheduleFire(quickFire): 3 s tras un cambio (OR_RULES_QUICK_FIRE_MILLIS=3000) o
    periódico cada 50 s (OR_RULES_MIN_TEMP_FACT_EXPIRATION_MILLIS=50000) para reglas temporales
  - fireAllDeployments() → actualiza RulesFacts → facts.removeExpiredTemporaryFacts() → doFire()
  - doFire(): por cada RulesetDeployment DEPLOYED → engine.fire(deployment.getRules(), facts) (easy-rules)
  - deployments: Map<rulesetId, RulesetDeployment>
        │
        ▼
RulesetDeployment (RulesetDeployment.java)
  - compile(): switch(lang) → compileRulesJson / compileRulesGroovy / compileRulesFlow (JAVASCRIPT: legacy, no soportado)
  - validez (meta.validity CalendarEvent) → status PAUSED/EXPIRED
  - scheduleRuleAction(runnable, delayMillis): ejecutor para acciones diferidas ("wait")
        │
        ▼
RulesBuilder (RulesBuilder.java)  → construye org.jeasy.rules Rule[] con name/description/priority/when/then
  JsonRulesBuilder extends RulesBuilder   (reglas JSON when-then)
  FlowRulesBuilder                        (reglas de flujo)
  Groovy script usa RulesBuilder directamente vía binding "rules"
        │
        ▼
RulesFacts (RulesFacts.java) extends org.jeasy.rules.api.Facts implements RuleListener
  - ASSET_STATES: Collection<AttributeInfo> (un hecho por atributo con ruleState)
  - named facts, anonymous facts, TemporaryFact<T> (expiran), vars (bind/bound entre LHS y RHS)
  - matchAssetState(AssetQuery) → AssetQueryPredicate (AssetQueryPredicate.java)
  - afterEvaluate(): triggerCount++ → RulesLoopException si >= 100
```

Facades que las acciones usan (interfaces en `model/.../rules/`, impl. en `manager/.../rules/facade/`):

| Facade | Métodos | Impl |
|---|---|---|
| `Assets` | `getResults(AssetQuery)`, `dispatch(assetId, attr, value)` (escribe atributo vía AssetProcessingService) | `AssetsFacade.java` |
| `Users` | `getResults(UserQuery)` → ids de usuario | `UsersFacade.java` |
| `Notifications` | `send(Notification)` | `NotificationsFacade.java` |
| `Webhooks` | `send(webhook, mediaType, target)`, `buildTarget(webhook)` | `WebhooksFacade.java` |
| `Alarms` | `create(Alarm, List<assetIds>)`, `linkAssets(...)` | `AlarmFacade.java` |
| `HistoricDatapoints` | `getValueDatapoints(ref, query)` | `HistoricFacade.java` |
| `PredictedDatapoints` | `getValueDatapoints`, `updateValue`, `purgeValues*` | `PredictedFacade.java` |

### 1.2 Entidad `Ruleset` (persistencia)

`model/src/main/java/org/openremote/model/rules/Ruleset.java` (`@MappedSuperclass`) con subclases `GlobalRuleset`, `RealmRuleset` (+`realm`, `accessPublicRead`), `AssetRuleset` (+`assetId char(22)`, `realm`, `accessPublicRead`). Tablas en `manager/src/main/resources/org/openremote/manager/setup/database/V20191202_01__Schema.sql`:

```sql
create table ASSET_RULESET (
  ID int8 not null, CREATED_ON timestamptz not null, ENABLED boolean not null,
  LAST_MODIFIED timestamptz not null, NAME varchar(255) not null,
  RULES text not null,                       -- el JSON/Groovy/Flow completo
  RULES_LANG varchar(255) not null default 'GROOVY',   -- JSON | GROOVY | FLOW | JAVASCRIPT(legacy)
  VERSION int8 not null, ASSET_ID char(22) not null,
  ACCESS_PUBLIC_READ boolean not null default false,
  META jsonb, primary key (ID));
```

Campos `meta` reconocidos (constantes en `Ruleset.java`): `showOnList`, `continueOnError` (default true), `validity` (CalendarEvent `{start, end, recurrence: "FREQ=WEEKLY;BYDAY=MO,TU"}`), `triggerOnPredictedData`.

Estados (`RulesetStatus.java`): `READY, DEPLOYED, COMPILATION_ERROR, VALIDITY_PERIOD_ERROR, EXECUTION_ERROR, LOOP_ERROR, DISABLED, PAUSED, EXPIRED, REMOVED, EMPTY`.

REST (`model/.../rules/RulesResource.java`, impl `RulesResourceImpl.java`): `GET /rules/info/{global|realm/{r}|asset/{id}}` (estado del motor), CRUD `POST/GET/PUT/DELETE /rules` (global), `/rules/realm`, `/rules/asset`, listados `realm/for/{realm}`, `asset/for/{assetId}`, y `GET /rules/geofences/{assetId}`.

### 1.3 Qué es un "hecho" (fact)

`AttributeInfo` = (assetId, assetName, assetType, parentId, path, realm, attributeName, value, oldValue, timestamp, oldValueTimestamp, meta). Equivalente directo en Talentus: **una fila "device + campo"**: `devices.speed`, `devices.ignition`, `positions.other.io239`, `device_sensors.temp1`, etc. El motor nunca consulta la BD al evaluar; trabaja sobre el snapshot en memoria de todos los atributos del ámbito. Esto es lo que hace trivial combinar condiciones entre atributos del mismo asset.

---

## 2. Modelo de datos de una regla when-then (JSON)

### 2.1 Clases (paquete `model/src/main/java/org/openremote/model/rules/json/`)

```java
// JsonRulesetDefinition.java
public JsonRule[] rules; public ObjectNode meta;

// JsonRule.java
public String name; public String description;
public int priority = Integer.MAX_VALUE - 1;       // menor = antes
public LogicGroup<RuleCondition> when;              // árbol AND/OR
public RuleAction[] then;                           // acciones sobre assets que cumplen
public RuleAction[] otherwise;                      // acciones sobre assets que NO cumplen
public RuleRecurrence recurrence;                   // cooldown
public RuleAction[] onStart; public RuleAction[] onStop;   // al desplegar / retirar el ruleset

// RuleCondition.java  (exactamente UNO de: assets | cron | sun)
public Map<Integer, String> duration;   // índice del predicado de attributes.items -> ISO8601 (PT5M): "debe cumplirse durante"
public String cron;                     // cron Quartz en UTC
public SunPositionTrigger sun;          // {position: SUNRISE|SUNSET|TWILIGHT_*, location: GeoJSONPoint, offsetMins}
public AssetQuery assets;               // qué assets y qué predicados de atributos
public String tag;                      // id de la condición (por defecto índice "0","1",...) para referenciarla en target.conditionAssets

// RuleRecurrence.java
public enum Scope { PER_ASSET, GLOBAL }  // default PER_ASSET
public Scope scope;
public Long mins;      // null = NUNCA recurre (una vez hasta que la condición se resetea), 0 = SIEMPRE, >0 = N minutos

// RuleAction.java   (discriminador JSON: "action")
//   "wait" | "write-attribute" | "update-attribute" | "notification" | "webhook" | "alarm"
public RuleActionTarget target;

// RuleActionTarget.java (uno de):
public String conditionAssets;   // tag de la condición → sus assets que cumplieron (o no, en otherwise)
public AssetQuery matchedAssets; // filtra los assets que cumplieron con otra AssetQuery (p.ej. por tipo)
public AssetQuery assets;        // consulta arbitraria de assets (no necesariamente los que dispararon)
public UserQuery users;          // consulta de usuarios (roles, usernames, assets vinculados...)
public Boolean linkedUsers;      // usuarios vinculados (USER_ASSET_LINK) a los assets que dispararon
public String custom;            // target libre (p.ej. token FCM / email)
```

`LogicGroup<T>` (`model/.../query/LogicGroup.java`): `{ operator: AND|OR, items: T[], groups: LogicGroup<T>[] }` — recursivo. Se usa dos veces: `when` es `LogicGroup<RuleCondition>` y `AssetQuery.attributes` es `LogicGroup<AttributePredicate>` (en JSON rules solo un nivel: `JsonRulesBuilder` hace `attributePredicates.groups = null`, línea 222, porque la UI no anida).

`AssetQuery` (`model/.../query/AssetQuery.java`), campos relevantes para reglas: `ids[]`, `names[] (StringPredicate)`, `parents[]`, `paths[]`, `realm`, `userIds[]`, `types[]`, `attributes (LogicGroup<AttributePredicate>)`, `orderBy`, `limit`.

`AttributePredicate extends NameValuePredicate` (`model/.../query/filter/`):
```java
public StringPredicate name;        // nombre del atributo
public boolean negated;
public Path path;                   // ruta dentro de un valor JSON (["io", 239] / ["temp","value"])
public ValuePredicate value;        // predicado sobre el valor actual
public NameValuePredicate[] meta;   // predicados sobre meta-items
public ValuePredicate previousValue; // predicado sobre el valor anterior (detecta transiciones)
public String timestampOlderThan;   // ISO8601: "no actualizado desde hace" (PT3M)
```

`ValuePredicate` (discriminador `predicateType`), `ValuePredicate.java` líneas 60-74:

| predicateType | Campos | Semántica (`asPredicate`) |
|---|---|---|
| `string` | `value, match: EXACT\|BEGIN\|END\|CONTAINS, caseSensitive, negate` | comparación de texto |
| `number` | `value, rangeValue, operator: EQUALS\|GREATER_THAN\|GREATER_EQUALS\|LESS_THAN\|LESS_EQUALS\|BETWEEN, negate` | `AssetQuery.Operator.compare` |
| `boolean` | `value` | coerción a boolean |
| `datetime` | `value, rangeValue, operator, negate` — valor fijo ISO8601 **o duración relativa** (`PT1H` = ahora+1h, `-PT1H`) | ventana deslizante calculada con `currentMillis` |
| `radial` | `lat, lng, radius(m), negated` | punto dentro del círculo |
| `rect` | `latMin, lngMin, latMax, lngMax, negated` | punto dentro del rectángulo |
| `geojson` | `geoJSON (Polygon/MultiPolygon/Feature), negated` | JTS `contains` |
| `array` | `value, index, lengthEquals, lengthGreaterThan, lengthLessThan, negated` | contiene / longitud |
| `value-empty` | `negate` | null/vacío |
| `value-any` | — | siempre true |
| `calendar-event` | `timestamp` | el evento de calendario está activo en ese instante |

### 2.2 Ejemplos reales del repo

**Alarma por umbral** (`test/src/test/resources/org/openremote/test/rules/CO2AlarmRule.json`):
```json
{ "rules": [ {
  "name": "CO2 Alarm Rule",
  "recurrence": { "mins": 0 },
  "when": { "operator": "OR", "groups": [ { "operator": "AND", "items": [ {
      "assets": {
        "types": ["RoomAsset"],
        "ids": ["7BQS4awDpktKayW0HrngTR"],
        "attributes": { "items": [ {
          "name":  { "predicateType": "string", "match": "EXACT", "value": "co2Level" },
          "value": { "predicateType": "number", "operator": "GREATER_THAN", "value": 5000 }
        } ] }
      } } ] } ] },
  "then": [ { "action": "alarm",
              "alarm": { "title": "%RULESET_NAME%", "content": "%TRIGGER_ASSETS%", "severity": "HIGH", "status": "OPEN" } } ]
} ] }
```

**"Si permanece X minutos" + dos ramas OR** (`JsonAttributeDurationRule.json`): el `duration` mapea índice de predicado → duración; aquí `brightness > 50` debe mantenerse 5 min y `brightness > 10` 3 min, y `onOff == true` sin duración:
```json
"items": [ {
  "assets": { "types": ["LightAsset"], "attributes": { "items": [
      { "name": {"predicateType":"string","match":"EXACT","value":"onOff"},      "value": {"predicateType":"boolean","value":true} },
      { "name": {"predicateType":"string","match":"EXACT","value":"brightness"}, "value": {"predicateType":"number","operator":"GREATER_THAN","value":50} },
      { "name": {"predicateType":"string","match":"EXACT","value":"brightness"}, "value": {"predicateType":"number","operator":"GREATER_THAN","value":10} }
  ] } },
  "duration": { "1": "PT5M", "2": "PT3M" }
}, ... ],
"then": [ { "action": "write-attribute",
            "target": { "matchedAssets": { "types": ["LightAsset"] } },
            "attributeName": "notes", "value": "Triggered" } ]
```

**"No actualizado desde hace 3 min"** (`JsonNotUpdatedForRule.json`) — equivalente directo al *offline* de Talentus:
```json
{ "name": {"predicateType":"string","match":"EXACT","value":"onOff"}, "timestampOlderThan": "PT3M" }
```

**Webhook con payload JSON de los assets disparadores** (`JsonRuleWebhook.json`):
```json
{ "action": "webhook", "webhook": { "httpMethod": "POST", "url": "https://basicserver/webhookjson",
    "headers": { "test-header": ["test-value"], "content-type": ["application/json"] },
    "payload": "%TRIGGER_ASSETS%" } }
```

**Trigger solar** (`JsonRuleSunriseOffset.json`): `"when": {..."items":[{ "sun": { "position": "SUNRISE", "offsetMins": 10, "location": {"type":"Point","coordinates":[0,0]} } }]}` con `then` `write-attribute` a `target.assets.ids`.

**Notificaciones con targets variados** (`BasicJsonRules.json`): geocerca radial negada (fuera de 100 m), `target.linkedUsers=true` + `users.realmRoles`, email directo `to[]`, mensaje `localized` (varios idiomas), `%TRIGGER_ASSETS%` y `%ASSET_ID%` en body/URL de push.

Plantilla por defecto que crea la UI al pulsar "nueva regla" (`ui/component/or-rules/src/json-viewer/or-rule-json-viewer.ts` líneas 176-181): `{ recurrence: { mins: 0 } }`; al añadir acción (`or-rule-then-otherwise.ts` líneas 606-690) genera alarm `{title:"%RULESET_NAME%", content:"%TRIGGER_ASSETS%", severity:"LOW", status:"OPEN"}`, webhook `{httpMethod:"POST", payload:'{"rule":"%RULESET_NAME%","assets":"%TRIGGER_ASSETS%"}'}`, email `{subject:"%RULESET_NAME%", html:"%TRIGGER_ASSETS%"}`, push `{title, body, action:{openInBrowser:false}}`.

### 2.3 Modelos de acciones (campos que viajan en el JSON)

| action | Clase | Campos propios |
|---|---|---|
| `notification` | `RuleActionNotification` | `notification: { name, message: {type: "email"\|"push"\|"localized", ...}, repeatFrequency, repeatInterval }`. Email: `from, replyTo, subject, text, html, to[], cc[], bcc[]`. Push: `title, body, action{url, openInBrowser, ...}, buttons[], data, priority, ttlSeconds`. Localized: `defaultLanguage, languages{lang: message}` |
| `webhook` | `RuleActionWebhook` | `webhook: { name, url, httpMethod, headers{k:[v]}, usernamePassword, oAuthGrant, payload }`, `mediaType` |
| `alarm` | `RuleActionAlarm` | `alarm: { title, content, severity: LOW\|MEDIUM\|HIGH, status: OPEN\|ACKNOWLEDGED\|IN_PROGRESS\|RESOLVED\|CLOSED }`, `assigneeId` |
| `write-attribute` | `RuleActionWriteAttribute` | `attributeName, value` |
| `update-attribute` | `RuleActionUpdateAttribute` | `attributeName, value, key, index, updateAction: ADD\|ADD_OR_REPLACE\|REPLACE\|DELETE\|CLEAR` (para atributos array/map) |
| `wait` | `RuleActionWait` | `millis` (retardo acumulativo para las acciones siguientes) |

---

## 3. Cómo se evalúa una regla JSON (ciclo completo)

Archivo: `manager/src/main/java/org/openremote/manager/rules/JsonRulesBuilder.java` (2170 líneas). Tres clases internas: `RuleConditionState` (estado por condición), `RuleConditionEvaluationResult`, `RuleState` (estado por regla).

### 3.1 Compilación (`JsonRulesBuilder(...)`, líneas 727-774 y `add(JsonRule)` 838-868)

1. Sustituye `%RULESET_ID%` y `%RULESET_NAME%` en el texto crudo; parsea `JsonRulesetDefinition`.
2. Para cada `JsonRule`: nombre único; `addRuleConditionStates` recorre el árbol `when` asignando `tag` = índice si falta y creando un `RuleConditionState` por condición.
3. `RuleConditionState` constructor (líneas 101-243):
   - `cron` → `org.quartz.CronExpression` UTC, `timePredicate` = "¿ya pasó la próxima ejecución?".
   - `sun` → `org.shredzone.commons.suncalc.SunTimes` para la ubicación; `timePredicate` cierto si `now ∈ [next, next+60s)`.
   - `assets` → extrae `orderBy`, `limit`, `attributes`; si hay `duration` usa `asAttributeMatcher(...)` propio con `durationMatchTimes`, si no `AssetQueryPredicate.asAttributeMatcher`. Deja en `ruleCondition.assets` solo los filtros de asset (ids/types/parents...), sin atributos, que es lo que se usa para el conjunto "unfiltered".
4. `buildLhsCondition` → `facts -> { ruleState.update(now); return ruleState.matched; }`; `buildRhsAction` → ejecuta `then`/`otherwise` y en `finally` registra recurrencia y "previously matched".

### 3.2 Mantenimiento del conjunto de hechos candidato (`updateUnfilteredAssetStates`, líneas 245-300)

Se llama al arrancar (`start()`) y en cada `AssetStateChangeEvent` (CREATE/UPDATE/DELETE) que `RulesEngine.fireAllDeployments` notifica. Mantiene `unfilteredAssetStates` = todos los `AttributeInfo` que pasan la `AssetQuery` *sin* predicados de atributo (p.ej. todos los atributos de todos los `RoomAsset`). En UPDATE se reemplaza el hecho (mismo assetId+attr) para tener el valor fresco; en DELETE se borra y se limpian los timers de `duration`. Pone `lastEvaluationResult = null` para forzar reevaluación.

### 3.3 Evaluación de una condición (`RuleConditionState.update`, líneas 302-473)

```
si lastEvaluationResult.matches ya es true  → return (se consumirá en el RHS)
si timePredicate != null                     → matches = timePredicate(now); return
si unfilteredAssetStates vacío               → no match, limpia previouslyMatched
agrupar unfilteredAssetStates por assetId → por cada asset: assetPredicate(states) devuelve
   el Set de AttributeInfo que satisfacen TODOS los predicados (AND) o null
matched / unmatched
si trackUnmatched (hay 'otherwise'): quitar de unmatched los que ya estaban en previouslyUnmatched
previouslyMatched.removeIf(ya no está en matched  ||  (RULE_RESET_IMMEDIATE && timestamp nuevo))   ← RESET
matched.removeIf(asset con timer de recurrencia activo en nextRecurAssetIdMap)                      ← COOLDOWN
matched.removeIf(previouslyMatched.contains)                                                       ← FLANCO (no re-disparar)
distinct por assetId, orderBy, limit
lastEvaluationResult = (matchedIds no vacío || (trackUnmatched && unmatchedIds no vacío), ...)
```

Implicaciones de diseño que conviene copiar en PHP:
- **Disparo por flanco**: una condición solo "dispara" para un asset cuando pasa de no cumplir a cumplir. Mientras siga cumpliendo no vuelve a disparar (salvo `recurrence.mins = 0` y que `previouslyMatched` se vacíe... ver 3.5).
- **Reset**: se re-arma automáticamente cuando el asset deja de cumplir. Con meta `ruleResetImmediate=true` en el atributo (`MetaItemType.RULE_RESET_IMMEDIATE`) basta que llegue un nuevo timestamp para re-armar: útil para atributos "evento" (botón SOS, pulso) frente a atributos "estado".
- `trackUnmatched` existe solo si la regla tiene `otherwise`.

### 3.4 Predicados con duración (`asAttributeMatcher`, líneas 2042-2169)

Por cada par (AttributeInfo, índice de predicado) guarda en `durationMatchTimes` el instante del **primer** match. En evaluaciones posteriores: `durationMatches = previousMatchTime + duration <= now`. Si el predicado deja de cumplirse se borra el timer. Como el motor se dispara como mínimo cada 50 s (temporizador periódico), una duración de 5 min se detecta con ±50 s de retraso.

`timestampOlderThan` se evalúa en `AssetQueryPredicate.asAttributeMatcher` (líneas 216-222): `attributeInfo.getTimestamp() > now - duration → false`.

### 3.5 Combinación AND/OR y recurrencia (`RuleState.update` y `updateMatches`, líneas 546-702)

- Si `nextRecur > now` (recurrencia GLOBAL activa) la regla no se evalúa.
- Limpia timers por asset vencidos de `nextRecurAssetIdMap`.
- `updateMatches(when, thenIds, otherwiseIds)`: AND → `allMatch` de `lastEvaluationResult.matches` de cada condición del grupo; OR → `anyMatch`; recursivo sobre `groups`. Acumula en `thenMatchedAssetIds` la unión de `matchedAssetIds` de las condiciones que cumplen, y en `otherwiseMatchedAssetIds` la unión de `unmatchedAssetIds`.
- Truco anti-estancamiento (líneas 613-638): en un grupo AND con varias condiciones, si el grupo no cumple pero alguna condición no tiene "previously matched", se vacía `previouslyMatched` de las demás para que puedan volver a disparar cuando el grupo cumpla de nuevo (si no, una condición ya "consumida" bloquearía el AND para siempre).

### 3.6 Ejecución del RHS (`buildRhsAction`, líneas 908-1001)

- Solo ejecuta si `rulesEngine.hasPreviouslyFired()` (evita disparos masivos en el arranque).
- `then` si `thenMatched()`, `otherwise` si `otherwiseMatched()`.
- `finally`: calcula `nextRecur = now + mins*60000` (o `Long.MAX_VALUE` si `mins == null`), lo guarda por asset (`PER_ASSET`) o global; añade `matchedAssetStates` a `previouslyMatchedAssetStates` de cada condición; pone `lastEvaluationResult = null`.
- Nota: con `mins = 0` → `nextRecur == now`, no se guarda timer, pero `previouslyMatched` sí, así que "ALWAYS" en la UI realmente significa "cada vez que vuelva a cumplirse tras resetear", no "en cada ciclo".

### 3.7 Encadenado de acciones (`executeRuleActions`, líneas 1003-1062)

Itera las acciones en orden; `wait` devuelve `RuleActionExecution(null, millis)` que incrementa `delay`; cada acción con `delay > 0` se programa vía `scheduledActionConsumer` (= `RulesetDeployment.scheduleRuleAction`, que cancela todo al parar el ruleset). Las acciones sin retardo se ejecutan inline.

### 3.8 Resolución de targets (`getRuleActionTargetIds`, líneas 1899-1975)

Orden: `conditionAssets` (tag) → `matchedAssets` (AssetQuery filtrando los ids disparadores) → `assets` (AssetQuery libre vía facade) → `users` (UserQuery) → `custom` → por defecto **todos** los assets que cumplieron (o no cumplieron, en `otherwise`).

Caso especial `linkedUsers` (líneas 1223-1327): usuarios vinculados a los assets disparadores (`UserQuery.assets(ids)`), y si el cuerpo contiene `%TRIGGER_ASSETS%` genera **una notificación por usuario** con solo sus assets (`findUserAssetLinks`). Esto es exactamente el modelo "alerta asignada a múltiples usuarios, cada uno ve solo sus vehículos" de Talentus.

### 3.9 Plantillas de texto

`insertTriggeredAssetInfo` (líneas 1829-1883): `%TRIGGER_ASSETS%` → tabla HTML (`<table><tr><th>Asset ID</th><th>Asset Name</th><th>Attribute</th><th>Value</th>`), texto tabulado, o JSON (`writerWithView(AttributeEvent.Enhanced.class)`) según contexto. `getAlarmContent` (1790) para alarmas. `replaceAssetIdPlaceholder` (1808) `%ASSET_ID%` en body/URL de push (primer id o lista separada por coma).

### 3.10 Ciclo del motor (RulesEngine)

- `insertOrUpdateAttributeInfo`/`retractAttributeInfo` (líneas 669-700) acumulan y llaman `scheduleFire(true)` → debounce de 3 s (si ya hay un timer que vence antes de 3 s no hace nada).
- `onFireTimerTriggered` → `fireAllDeployments` (CAS `executing`) → reprograma `scheduleFire(false)` a 50 s.
- `fireAllDeployments` (526-597): aplica retracts, inserts, updates a `facts` notificando a cada deployment (`onAssetStatesChanged`), expira hechos temporales, `doFire()`, loggea si tarda > 500 ms.
- `doFire` (599-649): para cada deployment DEPLOYED → `facts.reset()` → `engine.fire(rules, facts)`; excepción → `EXECUTION_ERROR`/`LOOP_ERROR`, y si `!continueOnError` o bucle → `stop()` del motor completo.
- `RulesFacts.afterEvaluate` cuenta disparos; ≥100 en una ejecución → `RulesLoopException`.
- Ámbito: un `AttributeEvent` llega a global + su realm + cada asset del `path` (ancestros) que tenga rulesets (`RulesService.getEnginesInScope`, 913-936). Un `AssetRuleset` ve solo el subárbol de su asset.

---

## 4. Tipos de acciones (detalle de ejecución, `buildRuleActionExecution`, líneas 1073-1745)

| Acción | Qué hace realmente |
|---|---|
| `notification` | Clona la `Notification`; decide `TargetType` (USER si target.users/linkedUsers, CUSTOM si target.custom, si no ASSET); sustituye placeholders; `notificationsFacade.send(notification)`. El `NotificationService` aplica `repeatFrequency`/`repeatInterval` consultando `SentNotification` (`okToSendNotification`, NotificationService.java 889-924): `ALWAYS` siempre, `ONCE` solo la primera vez, `HOURLY/DAILY/WEEKLY/MONTHLY/ANNUALLY` truncando al periodo, o `repeatInterval` ISO8601. Handlers: email, push (FCM), localized. |
| `webhook` | Clona `Webhook`; `%TRIGGER_ASSETS%` → JSON; deduce `mediaType` del header content-type (default JSON); `webhooksFacade.send(...)`. Soporta usernamePassword y OAuth. |
| `alarm` | Valida title/content/severity; `%TRIGGER_ASSETS%` en content; `sourceId = rulesetId`; `alarmsFacade.create(alarm, assetIds)` (crea registro + vínculos asset-alarma). |
| `write-attribute` | Para cada asset target: `assetsFacade.dispatch(id, attributeName, value)` → pasa por el pipeline normal de atributos (y por tanto puede disparar otras reglas; de ahí la detección de bucles). Rechaza targets de usuarios. |
| `update-attribute` | Lee el valor actual del atributo desde `facts`, aplica ADD/REPLACE/DELETE/CLEAR a lista o mapa, y hace `dispatch`. |
| `wait` | Solo retardo acumulativo. |

Qué NO hay como acción JSON: SMS (no existe handler SMS en OpenRemote; se haría vía webhook), comando a dispositivo (se modela como `write-attribute` a un atributo con agentLink que el agente traduce a comando), popup/sonido/color (son conceptos de la consola). Talentus ya tiene estos canales: se conservan.

---

## 5. Groovy rules y Flow rules

### 5.1 Groovy (`RulesetDeployment.compileRulesGroovy`, líneas 382-424)

Un `GroovyShell` estático compila el texto; el binding expone `LOG, rules (RulesBuilder), assets, users, notifications, historicDatapoints, predictedDatapoints, realm, assetId`. El script llama:

```groovy
rules.add()
  .name("Alert user when alarm has been triggered and not done so already")
  .when({ facts -> facts.matchAssetState(new AssetQuery().types(BuildingAsset).attributeValue("alarmEnabled", true))
                     .filter { !facts.matchFirst(AlertSilence) { it.residenceId == ... }.isPresent() } ... ; facts.bind("alarmTrigger", x); true })
  .then({ facts -> notifications.send(notification); facts.putTemporary("PT30M", new AlertSilence(residenceId: ...)) })
```
(`test/src/test/resources/org/openremote/test/rules/ResidenceNotifyAlarmTrigger.groovy`). El patrón **hecho temporal `AlertSilence` con `putTemporary("PT30M", ...)`** es la forma Groovy de implementar un cooldown; en JSON rules eso lo da `recurrence`.

API de `RulesFacts` usada por scripts: `matchAssetState(AssetQuery)`, `matchFirstAssetState`, `match(Class, predicate)`, `matchFirst(name)`, `put(obj)`, `put(name, obj)`, `putTemporary(duration, obj)`, `remove`, `bind/bound` (variables LHS→RHS, se limpian en `beforeEvaluate`), `getClock()` (`RulesClock.getCurrentTimeMillis/getNow`), `updateAssetState` (deprecated → `assets.dispatch`). `AssetQuery` tiene builder fluido: `.types(RoomAsset).ids(id).parents(id).attributeValue("motionSensor", GREATER_THAN, 0).attributeName("co2Level")`.

Seguridad: hay `// TODO Implement sandbox` (línea 390) y un `GroovyValueFilter` incompleto (línea 66); el único control real es `OR_RULES_GROOVY_EXECUTION_ENABLED` (RulesService 114). `JAVASCRIPT` solo se conserva para registros legados (`compile()` devuelve false con warning). Conclusión para Talentus: **no portar scripting a PHP de usuario final** (eval de PHP es inaceptable); si se quiere algo similar, un lenguaje de expresiones acotado (Symfony ExpressionLanguage) sobre los hechos.

### 5.2 Flow rules

Modelo (`model/src/main/java/org/openremote/model/rules/flow/`): `NodeCollection {name, description, nodes[], connections[{from: socketId, to: socketId}]}`; `Node {id, type: INPUT|PROCESSOR|OUTPUT, priority, name (= NodeModel enum), position{x,y}, size, internals[{name, picker{type: TEXT|MULTILINE|NUMBER|DROPDOWN|DOUBLE_DROPDOWN|CHECKBOX|ASSET_ATTRIBUTE|COLOR|DATE, options[]}, value}], inputs[NodeSocket], outputs[NodeSocket], displayCharacter}`; `NodeSocket {id, name, type: NUMBER|NUMBER_ARRAY|STRING|BOOLEAN|COLOR|ANY, nodeId, index}`. Ejemplo: `test/src/test/resources/org/openremote/test/rules/BasicFlowRules.json`.

Catálogo de nodos (`manager/src/main/java/org/openremote/manager/rules/flow/NodeModel.java`, enum): INPUT: `READ_ATTRIBUTE, DERIVATIVE, INTEGRAL, HISTORIC_VALUE, BOOLEAN_INPUT, NUMBER_INPUT, TEXT_INPUT`; PROCESSOR: `AND_GATE, OR_GATE, NOT_GATE, ADD/SUBTRACT/MULTIPLY/DIVIDE_OPERATOR, EQUALS_COMPARATOR, GREATER_THAN, LESS_THAN, SUM/MAX/MIN/AVERAGE/MEDIAN_PROCESSOR, ROUND_NODE, ABS_OPERATOR, POW_OPERATOR, NUMBER_SWITCH, TEXT_SWITCH, COMBINE_TEXT, SIN, COS, TAN, SQRT, MOD`; OUTPUT: `LOG_OUTPUT, WRITE_ATTRIBUTE`. Cada entrada define el `Node` plantilla, una `NodeImplementation` (cómo calcula su valor a partir de `info.getValueFromInput(i)`) y una `NodeTriggerFunction` (cuándo dispara).

Compilación (`manager/.../rules/FlowRulesBuilder.java`): **una regla easy-rules por nodo OUTPUT**. Condición = `backtrackFrom(outputNode)` recorre conexiones hacia atrás y la regla dispara si cualquier nodo del árbol tiene `NodeTriggerFunction.satisfies` (para `READ_ATTRIBUTE`: el atributo tiene `timestamp > triggerMap[rule]` y `value != oldValue`). Acción = evaluar el grafo recursivamente y `assets.dispatch(assetId, attr, value)` si el valor cambia. `GET /flow` (`FlowResource`) devuelve el catálogo de nodos a la UI.

UI: `ui/component/or-rules/src/flow-viewer/` (componentes `flow-editor`, `editor-workspace`, `node-panel`, `flow-node`, `flow-node-socket`, `connection-line`, `internal-picker`, `context-menu`, servicios `copy-paste-manager`, `exporter`, `project`, `shortcuts`). Es un editor de nodos tipo Node-RED en Lit. Valor para Talentus: bajo; es más útil para cálculos derivados (p.ej. "consumo = odómetro × factor") que para alertas. Si algún día se quiere, Drawflow (JS puro, sin framework) es el equivalente embebible en jQuery/Bootstrap.

---

## 6. Qué del editor UI (or-rules) es portable como concepto a jQuery/Bootstrap 3

Estructura (`ui/component/or-rules/src/`):

| Componente | Función | Concepto portable |
|---|---|---|
| `or-rule-tree.ts` (762 l.) | Árbol de rulesets con **grupos (carpetas)**, drag & drop, multi-selección, copiar/borrar, filtro por ámbito realm/asset, badge de estado (`RulesetStatus`) | Lista lateral Bootstrap `list-group` con carpetas y badge de estado (verde DEPLOYED, rojo error, gris DISABLED/PAUSED) |
| `or-rule-viewer.ts` | Cabecera: nombre editable, checkbox **enabled**, botón **`or-scheduler`** para `meta.validity` (RRULE), botón guardar deshabilitado si inválido (`_cannotSave`), aviso si lang JAVASCRIPT legado | Cabecera con `form-inline`; modal de "programación de actividad" (fecha inicio/fin + repetición semanal) |
| `or-rule-json-viewer.ts` | Carga `rules.rules[0]` (la UI solo soporta **1 regla por ruleset**, línea 186), orquesta `or-rule-when` + `or-rule-then-otherwise`, carga `AssetTypeInfo` (descriptores de atributos para listar operadores válidos), `validate()` | Un ruleset = una regla simplifica la UI y la BD |
| `or-rule-when.ts` | Renderiza `LogicGroup<RuleCondition>`: paneles "**when**" / "**or when**" (grupo OR raíz con grupos AND hijos); dentro de cada grupo las condiciones se unen con icono "&"; menú "+" para añadir condición (tipo de asset o "time") | Paneles Bootstrap apilados: cada panel = grupo AND; botón "O cuando…" añade panel; dentro, filas con "Y" |
| `or-rule-condition.ts` | Selector de **tipo de condición**: menú con tipos de asset (icono+color) o `ConditionType.TIME`; delega a `or-rule-asset-query` o `or-rule-trigger-query` | Dropdown "Tipo: Vehículo / Conductor / Geocerca / Horario" |
| `or-rule-asset-query.ts` (1225 l.) | Fila: **tipo de asset → selector de assets concretos (o "cualquiera")** → lista de predicados de atributo: `atributo` (select de descriptores) / `operador` (`AssetQueryOperator`, filtrado según `jsonType` del atributo: number → `=,≠,>,≥,<,≤,entre,no entre`; string → `=,≠,contiene,empieza,termina`; boolean → `es verdadero/falso`; geo → `dentro/fuera radio`, `dentro/fuera rectángulo`, `dentro/fuera área (GeoJSON)`; todos → `vacío/no vacío`, **`notUpdatedFor`**) / `valor` (input generado por `ValueInputProvider`; modal de mapa Leaflet para radio `or-rule-radial-modal.ts`) / botón "**durante X minutos**" (`attributeDurationTemplate` → `condition.duration[index]`) | Tabla de filas `<select atributo><select operador><input valor>[durante __ min][x]`; el catálogo de atributos de Talentus (speed, ignition, io*, sensores) reemplaza a los descriptores; para el operador geo reutilizar el Leaflet existente |
| `or-rule-trigger-query.ts` | Condición de tiempo: hora del día (→ `cron`) o posición solar (SUNRISE/SUNSET/TWILIGHT_*, `offsetMins`, ubicación en mapa) | Select "A las HH:mm" / "Amanecer ± min" |
| `or-rule-then-otherwise.ts` (758 l.) | Panel "**then**": select de **recurrencia** (`RecurrenceOption`: always=0, once=null, oncePerHour=60, oncePerDay=1440, oncePerWeek=10080, líneas 555-570); lista de acciones; menú "+" con `ActionType`: `wait, alarm, email, email_localized, push, push_localized, attribute (write-attribute por tipo de asset), webhook`; por acción un selector de **target** (`NotificationTargetType`: assets que dispararon, assets concretos, usuarios, usuarios vinculados, custom) | Select "Repetir: siempre / una vez / cada hora / cada día / cada semana"; lista de tarjetas de acción con dropdown de tipo y de destinatarios |
| `or-rule-action-*.ts`, `forms/or-rule-form-*.ts` | Formularios de email (to/cc/bcc, subject, html), push (title, body, acción URL/abrir en app, botones), webhook (url, método, headers, auth, payload), alarm (título, contenido, severidad, asignado), attribute (asset+atributo+valor), localized (tabs por idioma) | Formularios Bootstrap; los de Talentus para Email/SMS/Push/Webhook/Command ya existen: solo falta envolverlos como "acción" |
| `or-rule-json-dialog.ts` | Modal con el **JSON crudo** editable | Textarea JSON para usuarios avanzados/soporte |
| `or-rule-text-viewer.ts` | Editor de código (Groovy) | No portar |
| `index.ts` → `RulesConfig` | Configuración por white-label: `allowedLanguages, allowedConditionTypes, allowedActionTypes, allowedAssetQueryOperators{jsonType: ops[]}, allowedRecurrenceOptions, allowedActionTargetTypes, hide*Options, json.rule (plantilla de regla nueva), descriptors.when/action.assets{type: {includeAttributes, excludeAttributes, attributeDescriptors}}` | Un `config/rules.php` que lista atributos exponibles, operadores por tipo y acciones permitidas por plan/rol de cliente |

Decisiones de UX de OpenRemote que vale la pena imitar: (1) un ruleset = una regla en la UI; (2) el árbol `when` se limita a *OR de grupos AND* (dos niveles) aunque el modelo permita más; (3) los predicados de atributos dentro de una condición son solo AND; (4) la condición "no actualizado desde" oculta el botón de duración (son excluyentes); (5) `%TRIGGER_ASSETS%` por defecto en todos los mensajes para que el usuario no empiece con un cuerpo vacío.

---

## 7. Geocercas y GPS: lo que hay en OpenRemote

- Predicados geo evaluados en servidor sobre el atributo `location` (GeoJSONPoint): `RadialGeofencePredicate`, `RectangularGeofencePredicate`, `GeoJSONGeofencePredicate` (polígono/multipolígono con JTS, `model/.../query/filter/GeoJSONGeofencePredicate.java`). `LocationAttributePredicate` es un `AttributePredicate` sobre `location`.
- "Entrar" vs "salir" se modela como **flanco**: regla A `dentro del radio` (dispara al entrar), regla B `negated: true` (dispara al salir), gracias al reset de `previouslyMatched`. Con `previousValue` se puede exigir transición explícita (antes fuera, ahora dentro).
- `RulesEngine.trackLocationPredicates` + `RulesFacts.storeLocationPredicates` + `RulesService.onEngineLocationRulesChanged` recopilan todas las geocercas radiales que afectan a un asset y `ORConsoleGeofenceAssetAdapter` las empuja por push silencioso a la app móvil (`GeofenceDefinition {id, lat, lng, radius, httpMethod, url}`) para que el teléfono evalúe localmente y llame al `url` al entrar/salir. Para Talentus (trackers hardware) no aplica, salvo para la app móvil GPS UBICAR / Orbitrack si algún día se quiere geofencing en el teléfono.

---

## 8. Propuesta concreta de porte a Laravel (Talentus Pro Tracking)

### 8.1 Principios

1. **Coexistir**, no reemplazar: las `*AlertCheck` siguen funcionando; se añade un `RuleEngineService::onPosition($device, $position, $prevPosition)` al final del mismo pipeline. Las alertas clásicas pueden migrarse después generando reglas JSON equivalentes (ver 8.7).
2. **Hechos = atributos de dispositivo en Redis**, no consultas SQL en caliente. Cada posición procesada actualiza un hash `facts:device:{id}` con `{speed, ignition, lat, lng, course, altitude, satellites, battery, power, driver_uid, io239, temp1, ..., ts, prev:{...}}`.
3. **Estado del motor en Redis** (previously matched, duration timers, cooldown), con TTL de seguridad, para que sea idempotente y sobreviva a reinicios de workers.
4. Evaluación **por evento** (al llegar posición) + **tick por minuto** (scheduler) para condiciones temporales (`durationMin`, `notUpdatedFor`, `cron`, `sun`) — equivalente al par quickFire 3 s / periódico 50 s de `RulesEngine`.
5. Las condiciones se evalúan en PHP puro sobre arrays (sin eval), con clases de predicado 1:1 con OpenRemote.

### 8.2 Tablas

```sql
-- Regla (1 ruleset = 1 regla, como la UI de OpenRemote)
CREATE TABLE rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,             -- dueño (cliente/empresa)  ≈ realm
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  priority INT NOT NULL DEFAULT 1000,
  definition JSON NOT NULL,                  -- {when, then, otherwise, recurrence, templates}
  validity JSON NULL,                        -- {start, end, rrule} o schedule días/horas actual de Talentus
  recurrence_scope ENUM('PER_DEVICE','GLOBAL') NOT NULL DEFAULT 'PER_DEVICE',
  recurrence_mins INT NULL,                  -- null=una vez hasta reset, 0=siempre, N=cooldown
  status ENUM('DEPLOYED','DISABLED','COMPILATION_ERROR','EXECUTION_ERROR','PAUSED','EXPIRED') NOT NULL DEFAULT 'DEPLOYED',
  last_error TEXT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME, updated_at DATETIME,
  INDEX (user_id, enabled)
);

-- Ámbito: a qué dispositivos aplica (equivale a AssetQuery.ids/types/parents del "when")
CREATE TABLE rule_devices (
  rule_id BIGINT UNSIGNED NOT NULL, device_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (rule_id, device_id), INDEX (device_id)
);   -- alternativa: rule_device_groups(rule_id, group_id) + flag all_devices en definition.when

-- Usuarios que reciben las acciones "a usuarios vinculados" (hoy: alert_user / user_device_pivot)
CREATE TABLE rule_users (rule_id BIGINT UNSIGNED, user_id INT UNSIGNED, PRIMARY KEY (rule_id, user_id));

-- Estado por regla x dispositivo (mirror persistente de lo que vive en Redis; opcional pero útil para la UI)
CREATE TABLE rule_states (
  rule_id BIGINT UNSIGNED, device_id INT UNSIGNED,
  matched TINYINT(1) NOT NULL DEFAULT 0,       -- previouslyMatched (está "disparada")
  matched_since DATETIME NULL,
  next_recur_at DATETIME NULL,                 -- cooldown
  durations JSON NULL,                         -- {"cond0.pred1": "2026-11-01 14:00:05"} primer match por predicado
  updated_at DATETIME,
  PRIMARY KEY (rule_id, device_id)
);

-- Log de disparos (reemplaza/extiende la tabla events para reglas)
CREATE TABLE rule_executions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id BIGINT UNSIGNED, device_id INT UNSIGNED NULL, position_id BIGINT UNSIGNED NULL,
  branch ENUM('then','otherwise') NOT NULL,
  matched_facts JSON NOT NULL,                 -- snapshot de los atributos que cumplieron (para %TRIGGER_ASSETS%)
  actions_result JSON NULL,
  created_at DATETIME, INDEX (rule_id, created_at), INDEX (device_id, created_at)
);

-- Alarmas/incidentes (acción "alarm")
CREATE TABLE alarms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, rule_id BIGINT UNSIGNED NULL,
  title VARCHAR(255), content TEXT, severity ENUM('LOW','MEDIUM','HIGH'),
  status ENUM('OPEN','ACKNOWLEDGED','IN_PROGRESS','RESOLVED','CLOSED') DEFAULT 'OPEN',
  assignee_id INT UNSIGNED NULL, created_at DATETIME, updated_at DATETIME
);
CREATE TABLE alarm_devices (alarm_id BIGINT UNSIGNED, device_id INT UNSIGNED, PRIMARY KEY (alarm_id, device_id));

-- Anti-spam de notificaciones por destino (equivale a SentNotification + repeatFrequency)
CREATE TABLE rule_notification_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rule_id BIGINT UNSIGNED, action_index SMALLINT,
  channel VARCHAR(20), target_type VARCHAR(20), target_id VARCHAR(191), sent_at DATETIME,
  INDEX (rule_id, action_index, channel, target_type, target_id, sent_at)
);
```

### 8.3 JSON schema de `definition` (versión Talentus, simplificada de JsonRule)

```json
{
  "v": 1,
  "when": {
    "operator": "OR",
    "groups": [
      {
        "operator": "AND",
        "items": [
          {
            "tag": "c0",
            "devices": { "all": false, "ids": [12, 15], "groupIds": [3] },
            "attributes": [
              { "name": "speed",    "op": "gt",  "value": 90 },
              { "name": "ignition", "op": "eq",  "value": true },
              { "name": "location", "op": "insideGeofence", "value": { "geofenceId": 44 } },
              { "name": "io239",    "op": "eq",  "value": 1, "previousValue": { "op": "eq", "value": 0 } },
              { "name": "driver_uid", "op": "notEmpty" }
            ],
            "duration": { "0": "PT5M" },
            "notUpdatedFor": null
          },
          { "tag": "c1", "time": { "cron": "0 22 * * *" } },
          { "tag": "c2", "time": { "sun": { "position": "SUNSET", "offsetMins": -30, "location": "device" } } }
        ]
      }
    ]
  },
  "recurrence": { "scope": "PER_DEVICE", "mins": 60 },
  "then": [
    { "action": "notification", "channel": "push",  "target": { "linkedUsers": true },
      "title": "%RULE_NAME%", "body": "%DEVICE_NAME%: %TRIGGER_FACTS%", "repeat": "HOURLY" },
    { "action": "notification", "channel": "email", "target": { "custom": "ops@cliente.pe" }, "subject": "...", "html": "%TRIGGER_FACTS_TABLE%" },
    { "action": "notification", "channel": "sms",   "target": { "users": { "ids": [7] } }, "text": "..." },
    { "action": "popup", "sound": "alarm.mp3", "color": "#ff0000" },
    { "action": "wait", "millis": 60000 },
    { "action": "command", "target": { "conditionDevices": "c0" }, "command": "engineStop" },
    { "action": "webhook", "url": "https://...", "method": "POST", "headers": {}, "payload": "%TRIGGER_FACTS_JSON%" },
    { "action": "alarm", "severity": "HIGH", "title": "...", "content": "%TRIGGER_FACTS%", "assigneeId": null },
    { "action": "setAttribute", "name": "custom.estado", "value": "en_ruta" }
  ],
  "otherwise": [
    { "action": "setAttribute", "name": "custom.estado", "value": "detenido" }
  ]
}
```

Operadores (`op`) → clases PHP (puerto 1:1 de `ValuePredicate`):

| op | Clase PHP | Tipos |
|---|---|---|
| `eq, neq, gt, gte, lt, lte, between, notBetween` | `NumberPredicate` / `DateTimePredicate` (acepta duración relativa `-PT1H`) | number/datetime |
| `eq, neq, contains, startsWith, endsWith` (+`caseSensitive`) | `StringPredicate` | string |
| `isTrue, isFalse` | `BooleanPredicate` | boolean |
| `empty, notEmpty` | `ValueEmptyPredicate` | any |
| `insideRadius, outsideRadius, insideRect, outsideRect, insideGeofence, outsideGeofence` | `RadialGeofencePredicate`, `RectGeofencePredicate`, `PolygonGeofencePredicate` (geofence de Talentus por id → polígono/círculo; PHP: ray casting o `ST_Contains` precalculado) | location |
| `notUpdatedFor` (ISO8601) | se evalúa sobre `fact.ts` como `timestampOlderThan` | any |
| `previousValue` | sub-predicado sobre `fact.prev` | any |

### 8.4 Servicio evaluador (clases PHP)

```
app/Rules/
  RuleEngineService.php        ≈ RulesService + RulesEngine
     onPosition(Device $d, Position $p, ?Position $prev)   // hook en el pipeline de posiciones
     tick()                                                // cada minuto (scheduler) para duration/notUpdatedFor/cron/sun
     evaluateRule(Rule $r, int $deviceId, FactStore $facts, Carbon $now)
  FactStore.php                ≈ RulesFacts (Redis hash por dispositivo; put(), get(), prev(), ts())
  Compiled/CompiledRule.php    ≈ JsonRulesBuilder.RuleState (cache en memoria/APCu de la definición parseada, por version)
  Compiled/ConditionState.php  ≈ RuleConditionState (previouslyMatched, durations, en Redis: rule:{id}:dev:{dev})
  Predicates/*.php             ≈ ValuePredicate hijos (interface: match(mixed $value, Carbon $now): bool)
  Conditions/AttributeMatcher.php ≈ AssetQueryPredicate.asAttributeMatcher + duration + timestampOlderThan
  Conditions/TimeTrigger.php   ≈ cron (dragonmantank/cron-expression) y sun (php sun info / suncalc)
  Actions/ActionInterface.php  execute(RuleContext $ctx): void
  Actions/NotificationAction.php  → reutiliza los senders existentes Email/SMS/FCM/Popup/Sound/Color
  Actions/WebhookAction.php, CommandAction.php (Command GPRS existente), AlarmAction.php, SetAttributeAction.php, WaitAction.php
  Templates/Placeholders.php   %RULE_NAME% %RULE_ID% %DEVICE_NAME% %DEVICE_IMEI% %TRIGGER_FACTS% %TRIGGER_FACTS_TABLE% %TRIGGER_FACTS_JSON% %ASSET_ID%→%DEVICE_ID% %LAT% %LNG% %SPEED% %ADDRESS%
  Jobs/ExecuteRuleActionsJob.php  (cola; soporta delay() para "wait" ≈ scheduleRuleAction)
```

Algoritmo `evaluateRule` (traducción directa de `RuleConditionState.update` + `RuleState.update` + `buildRhsAction.finally`):

```php
// 1. cooldown global
if ($state->nextRecurGlobal > $now) return;
// 2. por cada condición del árbol (solo para este device)
foreach ($compiled->conditions as $tag => $cond) {
    if ($cond->isTime()) { $res[$tag] = $cond->timeTrigger->due($now, $deviceState); continue; }
    if (!$cond->appliesTo($deviceId)) { $res[$tag] = false; continue; }      // rule_devices / all
    $m = $cond->matcher->match($facts, $now, $state->durations[$tag]);          // Set de facts que cumplen o null
    $was = $state->matched[$tag] ?? false;                                      // previouslyMatched
    if ($m === null) { $state->matched[$tag] = false; $res[$tag] = false; $unmatched[$tag] = true; continue; }  // RESET
    if ($was && !$cond->resetImmediate($facts)) { $res[$tag] = false; continue; }   // ya disparada → flanco
    if (($state->nextRecurPerDevice ?? 0) > $now) { $res[$tag] = false; continue; } // cooldown por device
    $res[$tag] = true; $matchedFacts[$tag] = $m;
}
// 3. combinar árbol AND/OR  (updateMatches)
$then = $this->evalGroup($compiled->when, $res);
// 4. RHS
if ($then)  dispatch(new ExecuteRuleActionsJob($rule, 'then', $deviceId, $matchedFacts));
if ($rule->otherwise && $unmatchedAny) dispatch(... 'otherwise' ...);
// 5. finally: marcar matched, programar recurrencia
foreach ($res as $tag => $ok) if ($ok) $state->matched[$tag] = true;
if ($then && $rule->recurrence_mins !== null && $rule->recurrence_mins > 0)
    $state->nextRecurPerDevice = $now->addMinutes($rule->recurrence_mins);     // o nextRecurGlobal
$state->save(); // Redis + (async) rule_states
```

Diferencias intencionadas con OpenRemote: se evalúa **por dispositivo** en el momento de su posición (OpenRemote reevalúa todo el ámbito cada disparo; con miles de trackers eso no escala en PHP), y el `tick()` por minuto solo recorre los pares (regla, device) con timers pendientes (`durations` no vacíos, `notUpdatedFor`, `cron/sun`), que se indexan en un `ZSET rules:timers` por `due_at`.

Integración con el pipeline existente:
```php
// donde hoy se hace foreach ($alertChecks as $check) $check->checkEvents($position, $prevPosition);
app(FactStore::class)->updateFromPosition($device, $position, $prevPosition);   // speed, ignition, lat/lng, io*, sensores calculados
app(RuleEngineService::class)->onPosition($device, $position, $prevPosition);   // reglas JSON del dueño del device (+ reglas de admin "globales")
```
El `FactStore` debe incluir los valores ya calculados por Talentus (sensores con fórmula, dirección, geocercas actuales) para que las reglas trabajen sobre el mismo vocabulario que ve el usuario en la UI.

### 8.5 Scheduling y validez

- `validity`: reutilizar el formato actual de horarios (días/horas) y además permitir `{start, end}`; evaluar al inicio de `evaluateRule` → estado `PAUSED` (como `RulesetDeployment.getStatus`).
- `time.cron`: `dragonmantank/cron-expression` (`isDue`/`getNextRunDate`), guardando `nextRun` en Redis como hace `timePredicate` con `nextExecuteMillis`.
- `time.sun`: `date_sun_info()` de PHP (sunrise/sunset/civil/nautical/astronomical twilight) cubre los `SunPositionTrigger.Position` principales; `location: "device"` usa la última posición del vehículo (OpenRemote exige punto fijo).

### 8.6 Acciones: mapeo a canales existentes

| Acción JSON | Implementación Talentus |
|---|---|
| `notification.channel = email/sms/push/popup/sound/color` | Senders actuales; `target.linkedUsers` = `rule_users` (o los usuarios con acceso al device); `target.users.ids`; `target.custom` (email/teléfono/token). Anti-spam `repeat` (`ALWAYS/ONCE/HOURLY/DAILY/...`) consultando `rule_notification_log` (puerto de `okToSendNotification`). Una notificación **por usuario** con solo sus dispositivos si el cuerpo usa `%TRIGGER_FACTS%` (puerto de la rama `linkedUsersTarget`). |
| `webhook` | Cliente HTTP actual + headers/auth; `%TRIGGER_FACTS_JSON%`. |
| `command` | Command GPRS existente al device de `target.conditionDevices`/disparador. |
| `alarm` | Nueva tabla `alarms` + pantalla de incidentes (abierto/reconocido/en curso/resuelto/cerrado), asignable. |
| `setAttribute` | Escribe un campo custom del device (`device_custom_fields`) y lo vuelve a inyectar en `FactStore` → puede disparar otras reglas; limitar a N disparos por evento por device (puerto de `MAX_RULES_TRIGGERED_PER_EXECUTION=100`) para evitar bucles. |
| `wait` | `->delay()` acumulativo en la cadena de Jobs. |

### 8.7 Mapeo de las `*AlertCheck` actuales a reglas JSON (para migración/plantillas)

| AlertCheck | Regla when-then equivalente |
|---|---|
| Overspeed | `speed gt {limit}` (+ opcional `duration PT1M` para evitar picos GPS); recurrence `mins: N`. |
| Geofence in / out | `location insideGeofence {id}` / `outsideGeofence {id}`; el flanco + reset da entrada/salida sin estado extra. |
| Geofence overspeed | AND `location insideGeofence` + `speed gt X`. |
| Idle (ralentí) | AND `ignition isTrue` + `speed lte 2` con `duration: {"1": "PT10M"}`. |
| Offline | `notUpdatedFor: "PT30M"` sobre `ts` (evaluado en `tick()`); `otherwise` → "volvió en línea". |
| SOS / eventos de protocolo | `io{n} eq 1` con `resetImmediate` (meta `RULE_RESET_IMMEDIATE`) o `previousValue op eq 0`. |
| Driver | `driver_uid notEmpty` / `driver_uid neq "..."` + `previousValue`. |
| Fuel | `fuel lt X` o caída: `fuel lt X` AND `previousValue gt X+Δ`. |
| Temperatura | `temp1 between/gt/lt`, `duration` para persistencia. |
| Ignición on/off | `ignition isTrue` / `isFalse` (dos reglas o then/otherwise). |
| Batería / Cinturón | `battery lt 20`, `seatbelt isFalse` AND `speed gt 10`. |
| Horario de alerta | `validity` de la regla. |
| Asignación a múltiples usuarios | `rule_users` + `target.linkedUsers`. |

Se puede escribir un comando `rules:import-legacy` que genere estas definiciones desde la tabla de alertas actual y las deje deshabilitadas para revisión.

### 8.8 UI jQuery/Bootstrap 3

1. Pantalla "Reglas": lista (`list-group`) con estado y botones; "Nueva regla" abre el editor.
2. Editor: cabecera (nombre, enabled, horario de validez), bloque **CUANDO** con paneles "o cuando…", cada uno con filas `tipo (Vehículo/Horario) | dispositivos (select2 multi + "todos") | atributo | operador | valor | durante`; el catálogo `atributo → tipo → operadores` viene de un endpoint `/api/rules/catalog` (equivale a `AssetTypeInfo` + `RulesConfig.allowedAssetQueryOperators`).
3. Bloque **ENTONCES** con select de recurrencia y tarjetas de acción (dropdown de tipo = canales ya existentes + webhook/comando/alarma/atributo, y dropdown de destinatarios); bloque **SI NO** opcional.
4. Botón "JSON" (textarea) para soporte.
5. Serializar a `definition` y `POST /api/rules`; validación servidor con un JSON Schema (justinrainbow/json-schema) + compilación de prueba (`CompiledRule::fromDefinition`) devolviendo `COMPILATION_ERROR` legible, igual que `RulesetDeployment.compile()`.

### 8.9 Riesgos y mitigaciones aprendidas de OpenRemote

- **Bucles** por `setAttribute` → contador por evento (100 en OR).
- **Granularidad temporal**: las duraciones se detectan con el retraso del tick (50 s en OR; 60 s con el scheduler de Laravel). Documentarlo en la UI ("≈1 min de tolerancia").
- **AND multi-condición bloqueado** por una condición ya consumida → aplicar el "clear previously matched" de `updateMatches` líneas 613-638.
- **Arranque**: no ejecutar acciones en la primera evaluación tras desplegar/reiniciar (`hasPreviouslyFired`) para evitar avalanchas de notificaciones.
- **Scripting**: no ofrecer Groovy/PHP eval a clientes; si hace falta flexibilidad, ExpressionLanguage con lista blanca de funciones.
