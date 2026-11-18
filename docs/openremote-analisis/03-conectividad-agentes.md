# 03 — Capa de conectividad de OpenRemote: Agentes, AgentLink, MQTT, Gateway, Provisioning y Simulador

**Fuente analizada:** `/home/claude/openremote/core` (Java 21, Gradle; módulos `model`, `agent`, `manager`, `container`).
**Destino del análisis:** Talentus Pro Tracking (Laravel 8/PHP + MySQL + Redis + Node Socket.io + Traccar).

> Convención: las rutas son relativas a `/home/claude/openremote/core`. Los fragmentos de código están recortados (sin logging ni imports) para mostrar únicamente la lógica que importa portar.

---

## 0. Resumen ejecutivo

OpenRemote resuelve la conectividad con **un único patrón** que se repite en todos los protocolos:

```
Agent (asset especial con la config de conexión)
   └── Protocol (instancia viva 1:1 con el Agent; abre sockets, hace polling, etc.)
         └── AgentLink (meta "agentLink" en cada atributo de cualquier asset)
               ├── valueFilters[]      (JSONPath → regex → substring → math)   ENTRADA
               ├── valueConverter{}    (mapa "ACTIVE"→true, "*"→@IGNORE)       ENTRADA
               ├── writeValueFilters/writeValueConverter                       SALIDA
               ├── writeValue "..%VALUE%..%TIME%.."  (plantilla)               SALIDA
               ├── messageMatchFilters + messageMatchPredicate (demultiplexar) ENTRADA
               └── campos propios del protocolo (topic, path, OID, registro…)
```

Para Talentus eso significa: **no hace falta portar 20 protocolos**; hace falta portar (a) el **pipeline de valor** (filtros/convertidores/plantillas), (b) el **modelo Agent/AgentLink** como tablas Laravel, y (c) **3 o 4 "drivers"** (HTTP polling, MQTT client, ChirpStack, Modbus TCP) corriendo como workers Node o jobs de Laravel. El broker MQTT embebido y el gateway son los dos bloques que **no** conviene portar literalmente: se replican con **Mosquitto/EMQX + un bridge Node** y con el sistema de *forwards* ya existente, respectivamente.

Prioridad sugerida (impacto × esfuerzo):

| # | Pieza | Valor para Talentus | Esfuerzo | Veredicto |
|---|-------|--------------------|----------|-----------|
| 1 | Value filters/converters/writeValue (pipeline de valor) | Alto: unifica sensores Traccar, BLE, IoT | Bajo (≈400 líneas PHP + 300 Node) | **Portar ya** |
| 2 | HTTP Agent con polling (`HTTPAgentLink.pollingMillis`) | Alto: clima, combustible, SUNAT/RENIEC, APIs de clientes | Bajo-medio | **Portar ya** |
| 3 | MQTT API para clientes (topics realm/clientId/…) | Alto: integradores, apps, dashboards externos | Medio (broker externo + bridge) | **Integrar (Mosquitto/EMQX + Node)** |
| 4 | MQTT Agent cliente (suscribir topics externos) | Medio-alto: sensores de terceros, ChirpStack | Bajo una vez hecho #1 | **Portar** |
| 5 | Provisioning X.509 por MQTT | Medio: dispositivos IoT propios (ESP32, gateways) | Medio | **Portar simplificado** |
| 6 | ChirpStack (LoRaWAN) | Medio: agro/minería/tanques en Perú | Bajo (es MQTT + gRPC/REST) | **Portar** |
| 7 | Modbus TCP | Medio: tanques, generadores, balanzas | Medio (worker Node con `jsmodbus`) | **Portar después** |
| 8 | Simulador (replay con RRULE) | Medio: demos comerciales, QA | Bajo | **Portar** |
| 9 | Gateway/sincronización entre instancias | Bajo: Talentus ya tiene forwards | Alto | **No portar; tomar ideas (filtros delta/duración)** |

---

## 1. Anatomía del modelo: Agent, Protocol, AgentLink

### 1.1 `Agent` = un asset con atributos de configuración

Archivo: `model/src/main/java/org/openremote/model/asset/agent/Agent.java`

Un agente **es un asset** (hereda `Asset<T>`), por lo que vive en el mismo árbol y tabla que los demás assets. Sus atributos típicos son descriptores estáticos:

```java
public abstract class Agent<T extends Agent<T,U,V>, U extends Protocol<T>, V extends AgentLink<?>> extends Asset<T> {
  DISABLED            = ("agentDisabled", BOOLEAN)
  STATUS              = ("agentStatus", CONNECTION_STATUS, READ_ONLY)   // CONNECTED/DISCONNECTED/ERROR/...
  MESSAGE_CONVERT_HEX / MESSAGE_CONVERT_BINARY / MESSAGE_CHARSET
  MESSAGE_MAX_LENGTH / MESSAGE_DELIMITERS / MESSAGE_STRIP_DELIMITER     // framing para TCP/UDP/Serial
  OAUTH_GRANT, USERNAME_AND_PASSWORD
  HOST, PORT, BIND_HOST, BIND_PORT, SERIAL_PORT, SERIAL_BAUDRATE
  POLLING_MILLIS, UPDATE_ON_WRITE
  public abstract U getProtocolInstance();      // fábrica: cada Agent crea su Protocol
  public <T extends MetaHolder> V getAgentLink(T attribute)  // lee meta "agentLink" del atributo
}
```

Cada subclase añade lo suyo: `HTTPAgent` (`baseURL`, `requestHeaders`, `requestQueryParameters`, `requestTimeoutMillis`, `followRedirects`), `MQTTAgent` (`clientId`, `secureMode`, `publishQos`, `subscribeQos`, `certificateAlias`, `resumeSession`, `websocketMode/Path/Query`, `lastWillTopic/Payload/Retain`, `wildcardSubscriptionTopics[]`), etc.

### 1.2 `AgentLink` = el mapeo atributo↔protocolo

Archivo: `model/src/main/java/org/openremote/model/asset/agent/AgentLink.java`

Es un POJO serializado como JSON dentro de la **meta** `agentLink` de un atributo (`MetaItemType.AGENT_LINK`, `model/.../value/MetaItemType.java:40`). Tiene polimorfismo por `type` (`HTTPAgentLink`, `MQTTAgentLink`, `ModbusAgentLink`, …; por defecto `DefaultAgentLink`).

```java
public abstract class AgentLink<T extends AgentLink<?>> implements Serializable {
  protected String id;                          // id del Agent
  protected ValueFilter[] valueFilters;          // ENTRADA protocolo→atributo, en orden
  protected Map<String,Object> valueConverter;   // ENTRADA: mapa clave(uppercase)→valor; "@IGNORE", "@NULL", "*"
  protected ValueFilter[] writeValueFilters;     // SALIDA atributo→protocolo
  protected Map<String,Object> writeValueConverter;
  protected String writeValue;                   // plantilla con %VALUE% / %TIME%
  protected ValuePredicate messageMatchPredicate;// ¿este mensaje es para este atributo?
  protected ValueFilter[] messageMatchFilters;   // filtros previos al predicado
  protected Boolean updateOnWrite;               // no esperar eco del dispositivo
}
```

Ejemplo real de un atributo con agent link (tal como se guarda en la columna JSONB `attributes` del asset):

```json
"temperature": {
  "type": "number",
  "meta": {
    "agentLink": {
      "type": "MQTTAgentLink",
      "id": "5zI6XqkyVk9hX2xLq5Q9Oe",
      "subscriptionTopic": "sensores/tanque1/telemetria",
      "valueFilters": [
        { "type": "jsonPath", "path": "$.temp", "returnFirst": true },
        { "type": "mathExpression", "expression": "x / 10" }
      ],
      "valueConverter": { "NULL": "@IGNORE" },
      "publishTopic": "sensores/tanque1/cmd",
      "writeValue": "{\"setpoint\": %VALUE%, \"ts\": \"%TIME:EPOCH_MILLIS%\"}"
    }
  }
}
```

### 1.3 `Protocol` = instancia viva (ciclo de vida)

Archivos: `model/.../asset/agent/Protocol.java` (interfaz + javadoc del ciclo de vida), `agent/.../protocol/AbstractProtocol.java` (implementación base).

Ciclo de vida que el `AgentService` garantiza (javadoc de `Protocol.java`):

```
Agent creado/cargado:    start() → linkAttribute() × N
Agent modificado:        unlinkAttribute() × N → stop() → start() → linkAttribute() × N
Agent borrado/disabled:  unlinkAttribute() × N → stop()
Atributo linkado cambia: unlinkAttribute() → linkAttribute()
```

`AbstractProtocol` (`agent/.../AbstractProtocol.java`) implementa la mecánica común y deja 5 ganchos abstractos:

```java
protected abstract void doStart(Container c);
protected abstract void doStop(Container c);
protected abstract void doLinkAttribute(String assetId, Attribute<?> attr, U agentLink);
protected abstract void doUnlinkAttribute(String assetId, Attribute<?> attr, U agentLink);
protected abstract void doLinkedAttributeWrite(U agentLink, AttributeEvent event, Object processedValue);
```

Y dos métodos finales que **aplican el pipeline de valor**:

```java
// ENTRADA: el protocolo recibió algo para un atributo
public final void updateLinkedAttribute(AttributeRef ref, Object value, long ts) {
  Attribute<?> attribute = linkedAttributes.get(ref);
  Pair<Boolean,Object> r = ProtocolUtil.doInboundValueProcessing(ref.getId(), attribute, agent.getAgentLink(attribute), value);
  if (r.key) return;                                   // @IGNORE
  assetService.sendAttributeEvent(new AttributeEvent(ref, r.value, ts));
}
// SALIDA: alguien escribió el atributo (UI, regla, API, MQTT)
public void processLinkedAttributeWrite(AttributeEvent event) {
  Pair<Boolean,Object> r = ProtocolUtil.doOutboundValueProcessing(event.getRef(), agentLink, value, dynamicAttributes.contains(ref), now);
  if (r.key) return;
  doLinkedAttributeWrite(agentLink, event, r.value);
  if (agent.isUpdateOnWrite() || agentLink.getUpdateOnWrite()) updateLinkedAttribute(event.getRef(), r.value);
}
```

### 1.4 `AgentService` (manager) — quién arranca/para protocolos

Archivo: `manager/src/main/java/org/openremote/manager/agent/AgentService.java`

- `startAgent(agent)` (l.434): `agent.getProtocolInstance()` → `protocol.start(container)` → busca con un `AssetQuery` **todos los assets con atributos cuya meta `agentLink.id == agent.id`** → `linkAttributes`.
- `onAttributeEventIntercepted` (l.616): interceptor de la cadena de procesamiento de eventos. Si el `AttributeEvent` tiene meta `agentLink`, lo desvía a `protocol.processLinkedAttributeWrite(event)` y **corta la cadena** (el atributo no se escribe en BD hasta que el protocolo lo confirme con `updateLinkedAttribute`, salvo `updateOnWrite`).
- `AgentProtocolAssetService` (clase interna, l.94): implementación de `ProtocolAssetService` (`model/.../protocol/ProtocolAssetService.java`) que da a los protocolos un API acotado al realm del agente: `mergeAsset`, `deleteAssets`, `findAsset`, `findAssets(AssetQuery)`, `sendAttributeEvent`, `subscribeChildAssetChange`.
- Descubrimiento/importación: `doProtocolInstanceDiscovery` (l.861), `doProtocolAssetDiscovery` (l.900), `doProtocolAssetImport` (l.946). Los protocolos que lo soportan implementan `ProtocolAssetDiscovery` (`Future<Void> startAssetDiscovery(Consumer<AssetTreeNode[]>)`) o `ProtocolAssetImport` (`startAssetImport(byte[] fileData, Consumer<AssetTreeNode[]>)`), ambos en `model/.../protocol/`. Se garantiza un solo discovery/import a la vez por agente.

### 1.5 `ProtocolDatapointService` y `ProtocolPredictedDatapointService`

Archivos: `agent/.../protocol/ProtocolDatapointService.java`, `ProtocolPredictedDatapointService.java`.

```java
public interface ProtocolDatapointService extends ContainerService {
  void upsertValue(String assetId, String attributeName, Object value, long timestamp);
  void upsertValues(String assetId, String attributeName, List<ValueDatapoint<?>> values);
  List<ValueDatapoint<?>> queryDatapoints(String assetId, String attributeName, AssetDatapointQuery q);
}
```

Permite a un protocolo escribir **historial directamente** (p.ej. una API devuelve 24 h de datos) sin pasar por eventos en tiempo real, y el *predicted* es lo mismo para **pronósticos** (OpenWeatherMap inserta 48 h de temperatura futura; el simulador inserta la siguiente ventana de replay). En Talentus equivale a insertar en la tabla de posiciones/sensores históricos con `timestamp` arbitrario, más una tabla `predicted_datapoints` nueva.

---

## 2. Pipeline de valor: filters, converters, writeValue (lo más portable)

Archivo central: `model/src/main/java/org/openremote/model/protocol/ProtocolUtil.java`.

### 2.1 Orden exacto de procesamiento

**Entrada (protocolo → atributo)** — `doInboundValueProcessing`:
1. `valueFilters[]` en orden (cada filtro recibe la salida del anterior; `null` se propaga).
2. `valueConverter` (`applyValueConverter`): clave = `String(valor).toUpperCase()`, o `"NULL"`. Si hay match: `"@IGNORE"` → descartar mensaje; `"@NULL"` → escribir null; otro → ese valor. Si no hay match y existe `"*"`: `"@NEGATE"` invierte número/booleano, otro valor literal. Si no hay match ni `"*"` → **ignorar** (`Pair(true, value)`).
3. Coerción automática al tipo del atributo (`ValueUtil.getValueCoerced(value, attribute.getTypeClass())`); si falla → ignorar y log.

**Salida (atributo → protocolo)** — `doOutboundValueProcessing`:
1. `writeValueFilters[]`.
2. `writeValueConverter` (misma semántica).
3. Si hay `writeValue`: reemplazo de placeholders y **el string resultante sustituye al valor**.

### 2.2 Los 4 filtros

Archivos: `model/src/main/java/org/openremote/model/value/{ValueFilter,JsonPathFilter,RegexValueFilter,SubStringValueFilter,MathExpressionValueFilter}.java`

| `type` | Campos | Semántica |
|---|---|---|
| `jsonPath` | `path`, `returnFirst`, `returnLast` | Jayway JsonPath con `DEFAULT_PATH_LEAF_TO_NULL`; si el resultado es array y `returnFirst/Last` → devuelve ese elemento |
| `regex` | `pattern`, `dotAll`(def. true), `multiline`, `matchGroup`, `matchIndex` | `matcher.find()` repetido `matchIndex` veces; devuelve `group(matchGroup)` o null |
| `substring` | `beginIndex`, `endIndex` (opcional) | Índices negativos cuentan desde el final; fuera de rango → null |
| `mathExpression` | `expression` (exp4j, variable `x`) | `x` = valor coercionado a double; devuelve Double |

Nota del propio código (`ValueFilter.java`): *"TODO: Standardise inbound/outbound value processing as ordered list of filters and/or converters"* — es decir, OpenRemote mismo reconoce que lo ideal es **una sola lista ordenada de pasos**. Para Talentus conviene diseñarlo así desde el inicio.

### 2.3 Placeholders dinámicos

`model/.../Constants.java:99-100` y `ValueUtil.doDynamicValueReplace / doDynamicTimeReplace` (l.1398-1470):

```
%VALUE%                  → String(valor)        | %VALUE:%.2f%  → String.format("%.2f", valor)
%TIME%                   → ISO-8601 instant
%TIME:EPOCH_SECONDS%     %TIME:EPOCH_MILLIS%    %TIME:yyyy-MM-dd HH:mm%   (DateTimeFormatter)
%TIME+PT1H%  %TIME-P1D:EPOCH_SECONDS%           (Duration ISO-8601 sumado antes de formatear)
```

Se detecta en el `linkAttribute` si la plantilla contiene placeholders (`hasDynamicPlaceholders`) para no escanear en cada escritura.

### 2.4 Demultiplexado de mensajes (`messageMatchFilters` + `messageMatchPredicate`)

`ProtocolUtil.createGenericAttributeMessageConsumer`: para protocolos donde **un solo stream** trae mensajes de muchos atributos (TCP, UDP, serial, WebSocket, MQTT con wildcard). Flujo: `mensaje → messageMatchFilters → messageMatchPredicate.test()`; si pasa, **el mensaje original completo** se entrega a `updateLinkedAttribute`, que a su vez aplica `valueFilters`. Ejemplo ChirpStack: `messageMatchFilters=[jsonPath "$.fPort"]`, `messageMatchPredicate = NumberPredicate(port)`.

Los `ValuePredicate` disponibles están en `model/.../query/filter/` (`StringPredicate`, `NumberPredicate`, `BooleanPredicate`, `ArrayPredicate`, `DateTimePredicate`, `RadialGeofencePredicate`, `RectangularGeofencePredicate`, `ValueEmptyPredicate`…).

### 2.5 Propuesta de porte a Laravel/Node

**Tablas (MySQL):**

```sql
CREATE TABLE agents (
  id BIGINT PK, user_id BIGINT (cliente/realm), name VARCHAR(120),
  type ENUM('http','mqtt','chirpstack','modbus_tcp','simulator','websocket','tcp_server','udp_server'),
  config JSON,               -- host, port, baseURL, headers, credenciales (cifradas), qos…
  disabled TINYINT(1), status ENUM('CONNECTING','CONNECTED','DISCONNECTED','ERROR','WAITING'),
  status_message VARCHAR(255), last_status_at DATETIME, created_at, updated_at
);
CREATE TABLE agent_links (
  id BIGINT PK, agent_id FK, 
  target_type ENUM('device_sensor','device_attribute','asset_attribute'), -- a qué se mapea en Talentus
  target_id BIGINT,                 -- device_sensors.id, etc.
  link JSON,                        -- campos propios del protocolo: topic, path, method, oid, registro…
  value_filters JSON,               -- [{type:'jsonPath',path:'$.temp'},{type:'math',expression:'x/10'}]
  value_converter JSON,
  write_value_filters JSON, write_value_converter JSON, write_value TEXT,
  message_match_filters JSON, message_match_predicate JSON,
  update_on_write TINYINT(1), polling_ms INT NULL,
  last_value JSON, last_value_at DATETIME, last_error VARCHAR(255),
  UNIQUE(agent_id, target_type, target_id)
);
```

**PHP (`app/Services/ValuePipeline/`)** — una interfaz y 4 clases:

```php
interface ValueFilter { public function filter(mixed $value): mixed; }

final class JsonPathFilter implements ValueFilter {      // composer: softcreatr/jsonpath o galbar/jsonpath
    public function __construct(public string $path, public bool $returnFirst=false, public bool $returnLast=false) {}
    public function filter(mixed $v): mixed {
        $data = is_string($v) ? json_decode($v, true) : $v;
        if ($data === null) return null;
        $r = (new JSONPath($data))->find($this->path)->getData();
        if (($this->returnFirst || $this->returnLast) && is_array($r)) return $this->returnFirst ? ($r[0] ?? null) : end($r) ?: null;
        return count($r) === 1 ? $r[0] : $r;
    }
}
final class RegexFilter implements ValueFilter { /* preg_match_all + matchIndex + matchGroup */ }
final class SubstringFilter implements ValueFilter { /* mb_substr con índices negativos */ }
final class MathFilter implements ValueFilter { /* composer: mossadal/math-parser o symfony/expression-language con x */ }

final class ValuePipeline {
    public static function inbound(AgentLink $link, mixed $value, string $targetType): array /* [ignore, value] */ {
        foreach ($link->valueFilters() as $f) { $value = $f->filter($value); }
        [$ignore, $value] = self::convert($value, $link->value_converter);
        if ($ignore) return [true, null];
        return [false, self::coerce($value, $targetType)];   // 'number'|'boolean'|'string'|'json'
    }
    public static function convert(mixed $value, ?array $map): array {
        if (!$map) return [false, $value];
        $key = $value === null ? 'NULL' : mb_strtoupper(is_scalar($value) ? (string)$value : json_encode($value));
        if (array_key_exists($key, $map)) { $r = $map[$key]; }
        elseif (array_key_exists('*', $map)) { $r = $map['*']; if ($r === '@NEGATE') $r = is_bool($value) ? !$value : -1 * (float)$value; }
        else return [true, $value];
        if ($r === '@IGNORE') return [true, null];
        if ($r === '@NULL')   return [false, null];
        return [false, $r];
    }
    public static function outbound(AgentLink $link, mixed $value): array { /* writeValueFilters → writeValueConverter → template */ }
    public static function template(string $tpl, mixed $value, \DateTimeImmutable $now): string {
        $tpl = preg_replace_callback('/%VALUE(?::([^:%]+))?%/', fn($m) => isset($m[1]) ? sprintf($m[1], $value) : (is_scalar($value) ? (string)$value : json_encode($value)), $tpl);
        return preg_replace_callback('/%TIME([+-]P\w*)?(?::(.+?))?%/', function ($m) use ($now) {
            $t = isset($m[1]) && $m[1] !== '' ? ($m[1][0] === '-' ? $now->sub(new \DateInterval(substr($m[1],1))) : $now->add(new \DateInterval(substr($m[1],1)))) : $now;
            return match ($m[2] ?? '') { '' => $t->format(DATE_ATOM), 'EPOCH_SECONDS' => (string)$t->getTimestamp(), 'EPOCH_MILLIS' => (string)($t->getTimestamp()*1000), default => $t->format(self::javaToPhpFormat($m[2])) };
        }, $tpl);
    }
}
```

**Node (workers de protocolo)**: la misma librería en TS (`jsonpath-plus`, `mathjs` con scope `{x}`), compartida por los workers MQTT/Modbus/ChirpStack. Regla de oro: **el JSON de `value_filters` es idéntico en PHP y Node**, así la UI (Vue/Blade) edita una sola estructura y ambos runtimes la interpretan.

**Dónde encaja en Talentus:** hoy los sensores de un dispositivo se calculan a partir de los `io_*`/`params` que Traccar entrega. Un `agent_link` con `target_type='device_sensor'` permite que el valor del sensor venga de **otra fuente** (MQTT, HTTP, Modbus) y pase por el mismo pipeline; y para los datos de Traccar se puede ofrecer el pipeline como "fórmula avanzada" del sensor (`jsonPath` sobre el JSON de `params` + `math`), reemplazando las fórmulas rígidas heredadas de GPSWox.

---

## 3. HTTP Agent con polling (integrar APIs externas)

Archivos: `agent/src/main/java/org/openremote/agent/protocol/http/{HTTPAgent,HTTPAgentLink,HTTPProtocol,PaginationFilter}.java` (739 líneas el protocolo).

### 3.1 Modelo

`HTTPAgent`: `baseURL` (obligatorio), `requestHeaders`, `requestQueryParameters`, `requestTimeoutMillis` (def. 10 000), `followRedirects`, más `oAuthGrant` (client_credentials/password/refresh) o `usernamePassword` (Basic) heredados de `Agent`.

`HTTPAgentLink`:

```java
Map<String,List<String>> headers;         // se suman a las del agente
Map<String,List<String>> queryParameters; // idem; pueden contener %VALUE% / %TIME%
Integer pollingMillis;                    // si está → este link es de LECTURA periódica (mín. 5000 ms)
Boolean pagingMode;                       // sigue cabecera Link rel="next" y concatena entidades
String path;                              // se añade al baseURL; admite %VALUE%
HTTPMethod method;                        // GET por defecto; con POST/PUT el valor viaja como body
String contentType;                       // def. text/plain
String pollingAttribute;                  // reutiliza la respuesta de OTRO atributo del mismo asset
boolean messageConvertBinary / messageConvertHex;
```

### 3.2 Lógica clave (`HTTPProtocol`)

- `doLinkAttribute` (l.~270): construye un `HttpClientRequest` (combina headers/query del agente + link, detecta si hay placeholders) y, si hay `pollingMillis`, programa `scheduleWithFixedDelay(0, pollingMillis)`.
- `schedulePollingRequest`: antes de cada poll ejecuta `doOutboundValueProcessing(agentLink.writeValue)` → eso permite que **el body/path/query de la petición tengan `%TIME%`** (p.ej. `?from=%TIME-PT1H:EPOCH_SECONDS%&to=%TIME:EPOCH_SECONDS%`).
- `onPollingResponse`: si 2xx lee el body como string (o hex/binario) → `updateLinkedAttribute(ref, body)` → el `valueFilters` del link extrae el campo. Además, para cada `AttributeRef` registrado en `pollingLinkedAttributeMap` (los links que declararon `pollingAttribute = <este atributo>`) reutiliza **la misma respuesta**: **una petición HTTP alimenta N atributos**.
- `executePagingRequest`: mientras `response.hasLink("next")`, pide la siguiente página y acumula.
- `doLinkedAttributeWrite`: una escritura al atributo → `request.invoke(valorProcesado)` (GET con placeholders o POST/PUT con body).
- Un `WebTargetBuilder` (`container/.../web/WebTargetBuilder.java`) gestiona OAuth2 (refresca token) y Basic.

### 3.3 `OpenWeatherMapAgent` como ejemplo de agente "de dominio"

`agent/.../openweathermap/OpenWeatherMapProtocol.java`: no usa `HTTPAgent`; hace su propio `scheduleAtFixedRate` (l.121) contra `https://api.openweathermap.org/data/3.0/onecall?lat=..&lon=..` (l.396), agrupa atributos por asset (`updateAssetWeatherData`), usa la **ubicación del asset** para la consulta, escribe valores actuales con `updateLinkedAttribute` y **pronósticos** con `predictedDatapointService.upsertValues` (l.308). El `OpenWeatherMapAgentLink` sólo tiene `weatherProperty` (enum: temperatura, humedad, viento, lluvia…). `ProvisionWeatherAsset=true` crea automáticamente un `WeatherAsset` hijo del agente (l.428). La API key puede venir por env `OR_OPEN_WEATHER_API_APP_ID` (ver `docker-compose.yml`).

### 3.4 Porte a Laravel

Un **job programado** (`php artisan schedule:work`/Horizon) es suficiente; no hace falta Node:

```php
// app/Console/Kernel.php
$schedule->job(new DispatchHttpAgentPolls)->everyMinute();   // mínimo real 60 s; para <60 s usar un worker loop

// app/Jobs/DispatchHttpAgentPolls.php
AgentLink::query()->whereHas('agent', fn($q) => $q->where('type','http')->where('disabled',0))
    ->whereNotNull('polling_ms')
    ->where(fn($q) => $q->whereNull('next_poll_at')->orWhere('next_poll_at','<=',now()))
    ->chunkById(200, fn($links) => $links->groupBy(fn($l) => $l->requestSignature())   // misma URL+headers+body ⇒ una sola petición
        ->each(fn($group) => PollHttpAgentLink::dispatch($group->pluck('id'))->onQueue('agents')));

// app/Jobs/PollHttpAgentLink.php
$req = Http::withOptions(['timeout' => $agent->config['timeoutMs']/1000])->withHeaders($headers);
if ($oauth = $agent->config['oauth'] ?? null) $req = $req->withToken(OAuthTokenCache::get($agent));   // Cache::remember con expires_in
$resp = $req->send($link->link['method'] ?? 'GET', $url, ['body' => ValuePipeline::template($link->write_value, null, now())]);
if ($link->link['pagingMode'] ?? false) { /* seguir Link: <...>; rel="next" */ }
foreach ($group as $l) {
    [$ignore, $value] = ValuePipeline::inbound($l, $resp->body(), $l->targetType());
    if (!$ignore) SensorValueWriter::write($l, $value, now());   // → device_sensors / history / Redis → Socket.io
    $l->forceFill(['next_poll_at' => now()->addMilliseconds($l->polling_ms)])->save();
}
```

Casos de uso inmediatos para Talentus:
- **Clima** por ubicación de flota/base (OpenWeatherMap one-call) → atributo del asset "Base Trujillo"; alerta de lluvia en rutas.
- **Precio de combustible** (Osinergmin Facilito / APIs de grifos) → coste por km real.
- **Tipo de cambio** (BCRP/SUNAT), **feriados**, **estado de vías** (Sutran/MTC) → reglas.
- **APIs de clientes** (ERP: órdenes de despacho) → atributos del vehículo (`ordenActual`).
- Escritura: un atributo "estadoPuerta" que al cambiar hace `POST` al webhook del cliente con `writeValue` JSON plantilla — complementa los *forwards* existentes pero **por atributo y con plantilla**.

---

## 4. MQTT: dos caras

### 4.1 Cara A — `MQTTAgent` (cliente): suscribirse a brokers externos

Archivos: `agent/.../mqtt/{MQTTAgent,MQTTAgentLink,MQTTProtocol,AbstractMQTT_IOClient,MQTT_IOClient}.java` (cliente HiveMQ).

`MQTTAgentLink`: `subscriptionTopic`, `publishTopic`, `qos` (0-2). `MQTTProtocol.doLinkAttribute` (l.~60):

```java
Consumer<String> genericConsumer = ProtocolUtil.createGenericAttributeMessageConsumer(assetId, attribute, agentLink, now, this::updateLinkedAttribute);
Consumer<MQTTMessage<String>> messageConsumer = msg -> genericConsumer != null ? genericConsumer.accept(msg.payload) : updateLinkedAttribute(ref, msg.payload);
Topic matchedWildcard = findMatchingWildcardTopic(topic);          // si el agente declaró wildcardSubscriptionTopics
if (matchedWildcard != null) addWildcardMessageConsumer(...)        // UNA suscripción "#"/"+" en el broker, enrutado local por topic exacto
else client.addMessageConsumer(topicStr, qos, messageConsumer);     // una suscripción por atributo
```

Detalles útiles: `wildcardSubscriptionTopics[]` del agente evita N suscripciones (clave para ChirpStack: `application/{id}/device/+/event/up`); `lastWill*`; `websocketMode` (MQTT over WS, puertos 80/443); TLS mutuo con `certificateAlias` resuelto en el `KeyStoreService` del manager (`realm.alias`); `resumeSession` (clean session invertido).

`createWriteMessage`: `new MQTTMessage<>(publishTopic, String(processedValue), qos)` — el `writeValue` del link construye el payload.

**Porte**: worker Node (`mqtt` npm) por agente MQTT activo, leyendo `agents`/`agent_links` desde MySQL (o un endpoint interno Laravel) y escuchando cambios vía Redis pub/sub (`agents:changed`). Al recibir mensaje → `pipeline.inbound` → `POST /internal/sensor-values` (o escribir directo en Redis stream que ya consume Socket.io). Las escrituras desde la plataforma se publican vía Redis `agent:{id}:write` → el worker hace `client.publish(publishTopic, pipeline.outbound(...))`.

### 4.2 Cara B — Broker embebido y "MQTT API" (`manager/mqtt`)

Archivos: `manager/src/main/java/org/openremote/manager/mqtt/{MQTTBrokerService,ActiveMQORSecurityManager,MQTTHandler,DefaultMQTTHandler,ConnectionMonitorHandler,UserAssetProvisioningMQTTHandler}.java`.

**Broker**: ActiveMQ Artemis embebido (`EmbeddedActiveMQ`), acceptor `tcp://0.0.0.0:1883?protocols=MQTT`, sin persistencia (`setPersistenceEnabled(false)`), **sin retained**, colas no durables auto-borradas, sesión 5 min, caches de auth a 0 porque los clientes de provisioning entran anónimos. El proxy (HAProxy) expone **8883 TLS** y termina TLS hacia 1883 (`docker-compose.yml`: `"8883:8883"`). Los handlers se cargan por `ServiceLoader<MQTTHandler>` ordenados por prioridad.

**Autenticación** (`ActiveMQORSecurityManager.authenticate`, l.~45):

```
username = "{realm}:{clientId_keycloak}"      ← OBLIGATORIO el prefijo realm
password = client secret (service user)       ← vacío ⇒ anónimo (solo para provisioning)
→ identityService.authenticate(realm, user, password)  (Keycloak client_credentials, timeout 10 s)
→ TokenPrincipal (JWT verificado) añadido al Subject de la conexión
```

**Autorización** (`verifyRights`): el topic se parsea (`Topic.parse`), **el 2º token debe ser el clientId MQTT de la conexión**, publicar con wildcard prohibido, y se delega al primer `MQTTHandler` cuyo `handlesTopic` acepte → `checkCanPublish/checkCanSubscribe` (que a su vez exigen `topicRealmAllowed` — el realm del token = 1er token — y `topicClientIdMatches`).

**Diseño de topics del `DefaultMQTTHandler`** (constantes l.~30 y comentarios l.517-560):

```
Suscribir (eventos salientes, QoS 0 siempre):
  {realm}/{clientId}/asset/#                          → AssetEvent de cualquier asset
  {realm}/{clientId}/asset/+                          → assets raíz
  {realm}/{clientId}/asset/{assetId}                  → ese asset
  {realm}/{clientId}/asset/{assetId}/#  | /+          → descendientes / hijos directos
  {realm}/{clientId}/attribute/{attrName|+}/{assetId|+|#}       → AttributeEvent completo (JSON con ref, value, timestamp, oldValue…)
  {realm}/{clientId}/attribute/{attrName|+}/{assetId}/{+|#}     → en descendientes
  {realm}/{clientId}/attributevalue/{attrName|+}/{assetId|+|#}  → SOLO el valor (payload = JSON del valor)
Publicar (escrituras entrantes):
  {realm}/{clientId}/writeattributevalue/{attrName}/{assetId}   payload = valor JSON crudo (timestamp = now)
  {realm}/{clientId}/writeattribute/{attrName}/{assetId}        payload = {"value":..., "timestamp": epochMillis}
```

Cómo se implementa: `canSubscribe` traduce el topic a un `AssetFilter` (`buildAssetFilter`: assetIds/parentIds/paths/attributeNames) y crea una `EventSubscription` **autorizada contra el `ClientEventService`** (misma autorización que WebSocket/REST: usuario restringido solo ve assets con `UserAssetLink`); `onSubscribe` registra el consumer que **expande los wildcards** con el id/nombre real al publicar (`getSubscriptionEventConsumer`, l.587). `canPublish` autoriza con `clientEventService.authorizeEventWrite` y cachea 5 min por conexión. `onPublish` construye un `AttributeEvent` (`buildAttributeEvent`: tokens[3]=attr, tokens[4]=assetId) y lo envía **asíncrono** al `ATTRIBUTE_EVENT_PROCESSOR` — el comentario (l.~470) explica que la saturación del executor hace *back-pressure* natural sobre el cliente MQTT.

`ConnectionMonitorHandler`: mantiene atributos con meta `USER_CONNECTED=<username>` en true/false según haya conexiones MQTT de ese service user (presencia del dispositivo). `MQTTBrokerService.configure()`: si cambian `User` (secret/enabled) o `UserAssetLink` → desconexión forzada (debounce 5 s) para invalidar permisos.

### 4.3 ¿Portar el broker o integrar uno externo? → **Externo (EMQX o Mosquitto) + bridge Node**

Razones: Artemis embebido es Java puro; Talentus no tiene JVM en su stack; EMQX ya trae auth HTTP, ACL HTTP, rule engine, webhooks, MQTT-over-WS, TLS/mTLS, métricas y clustering. Mosquitto es más liviano pero su auth dinámica requiere `mosquitto-go-auth` (plugin). Recomendación: **EMQX 5 (open source)** para producción; Mosquitto + go-auth si se quiere algo mínimo en el mismo VPS.

**Diseño equivalente en Talentus:**

```
                 ┌───────────── EMQX (1883/8883/8083 ws) ─────────────┐
  cliente ──────►│ auth  → POST https://talentus/api/mqtt/auth          │
  integrador     │ acl   → POST https://talentus/api/mqtt/acl           │
                 │ rule: topic "+/+/write/#"  → webhook /api/mqtt/ingest│
                 └───────────────┬────────────────────────────────────┘
                                 │  (bridge Node, 1 proceso, suscrito a "$share/g/+/+/write/#"
                                 │   y a Redis "positions"/"sensor_values" que ya alimenta Socket.io)
                                 ▼
   Laravel API + MySQL + Redis  ◄──────────────────────────► Node bridge publica:
                                                              {account}/{clientId}/device/{imei}/position
                                                              {account}/{clientId}/sensor/{name}/{deviceId}
                                                              {account}/{clientId}/sensorvalue/{name}/{deviceId}
                                                              {account}/{clientId}/event/{type}/{deviceId}
```

*Mapeo de conceptos:* `realm` → **cuenta/empresa cliente** (tenant) en Talentus; `clientId` MQTT → **credencial de integración** (`mqtt_credentials`: `client_id`, `username`, `secret_hash`, `account_id`, `scopes`, `device_ids` o `group_ids` permitidos, `enabled`); `assetId` → `device_id`/IMEI; `attribute` → `sensor`/`attribute` del dispositivo.

*Auth HTTP de EMQX* (Laravel):

```php
// POST /api/mqtt/auth  {clientid, username, password}
Route::post('mqtt/auth', function (Request $r) {
    [$account, $user] = array_pad(explode(':', $r->username, 2), 2, null);   // igual que OR: "{realm}:{user}"
    $cred = MqttCredential::where('client_id', $r->clientid)->whereRelation('account','slug',$account)->where('username',$user)->where('enabled',1)->first();
    if (!$cred || !Hash::check($r->password, $cred->secret_hash)) return response()->json(['result'=>'deny'], 200);
    return response()->json(['result'=>'allow', 'is_superuser'=>false]);
});
// POST /api/mqtt/acl  {clientid, username, topic, action: publish|subscribe}
Route::post('mqtt/acl', function (Request $r) {
    $t = explode('/', $r->topic);                               // [account, clientId, kind, name, deviceId, ...]
    if (count($t) < 4 || $t[1] !== $r->clientid) return ['result'=>'deny'];
    $cred = MqttCredential::findByClientId($r->clientid);
    if (!$cred || $cred->account->slug !== $t[0]) return ['result'=>'deny'];
    if ($r->action === 'publish' && (str_contains($r->topic,'+') || str_contains($r->topic,'#'))) return ['result'=>'deny'];
    $ok = match ($t[2]) {
        'device','sensor','sensorvalue','event' => $r->action === 'subscribe' && $cred->canReadDevice($t[4] ?? null),
        'write'                                 => $r->action === 'publish'   && $cred->canWriteDevice($t[4] ?? null) && $cred->hasScope('write'),
        'command'                               => $r->action === 'publish'   && $cred->hasScope('commands'),
        default => false,
    };
    return Cache::remember("acl:{$r->clientid}:{$r->action}:{$r->topic}", 300, fn() => ['result' => $ok ? 'allow' : 'deny']);
});
```

*Bridge Node* (reusa lo que ya publica Talentus en Redis para Socket.io):

```js
const mqtt = require('mqtt'); const Redis = require('ioredis');
const client = mqtt.connect(process.env.EMQX_URL, { username: 'system:bridge', password: process.env.BRIDGE_SECRET, clientId: 'bridge' });
const sub = new Redis(); sub.subscribe('positions', 'sensor_values', 'events');
sub.on('message', (ch, raw) => {
  const m = JSON.parse(raw);                                  // {account, deviceId, imei, lat, lng, speed, sensors:{...}, ts}
  const creds = credsByAccount.get(m.account) || [];          // credenciales con permiso sobre ese device (cache refrescada por Redis "mqtt_credentials:changed")
  for (const c of creds) {
    if (ch === 'positions') client.publish(`${m.account}/${c.clientId}/device/${m.imei}/position`, JSON.stringify(m), { qos: 0 });
    if (ch === 'sensor_values') for (const [name, v] of Object.entries(m.sensors)) {
      client.publish(`${m.account}/${c.clientId}/sensor/${name}/${m.deviceId}`, JSON.stringify({ name, deviceId: m.deviceId, value: v, timestamp: m.ts }));
      client.publish(`${m.account}/${c.clientId}/sensorvalue/${name}/${m.deviceId}`, JSON.stringify(v));
    }
  }
});
// Escrituras / comandos entrantes
client.subscribe('$share/bridge/+/+/write/#'); client.subscribe('$share/bridge/+/+/command/#');
client.on('message', async (topic, payload) => {
  const [account, clientId, kind, name, deviceId] = topic.split('/');
  await axios.post(`${API}/internal/mqtt/ingest`, { account, clientId, kind, name, deviceId, payload: payload.toString() }, { headers: { 'X-Internal-Token': TOKEN } });
  // Laravel: kind=write → agent_link/sensor manual → ValuePipeline; kind=command → cola existente de comandos GPRS/SMS
});
```

Importante: la suscripción *por credencial* con prefijo `{account}/{clientId}` se mantiene como en OpenRemote porque permite ACL con un `prefix` fijo y evita que un cliente se suscriba a datos de otro. El coste es publicar N copias si hay N credenciales con acceso al mismo dispositivo; para la mayoría de cuentas N≤3. Alternativa más barata: publicar **una** vez en `{account}/device/{imei}/position` y hacer que la ACL valide `account` contra la credencial (pierdes el aislamiento por credencial pero no por cuenta).

Extras a replicar: **presencia** (equivalente a `ConnectionMonitorHandler`): EMQX webhook `client.connected/disconnected` → actualizar `mqtt_credentials.connected`/`devices.mqtt_online`; **Last Will**: dejar que el cliente publique en `{account}/{clientId}/write/online/{deviceId}` con payload `false`.

---

## 5. Provisioning automático por MQTT con X.509

Archivos: `manager/src/main/java/org/openremote/manager/mqtt/UserAssetProvisioningMQTTHandler.java` (763 líneas), `manager/.../provisioning/{ProvisioningService,ProvisioningResourceImpl}.java`, modelo `model/.../provisioning/{ProvisioningConfig,X509ProvisioningConfig,X509ProvisioningData,ProvisioningMessage,X509ProvisioningMessage,SuccessResponseMessage,ErrorResponseMessage,ProvisioningUtil}.java`.

### 5.1 Modelo `ProvisioningConfig` (tabla `provisioning_config`)

```
id, name, type ("x509"), realm, assetTemplate (JSON del asset a crear con %UNIQUE_ID%),
restrictedUser (bool), userRoles[] (ClientRole: read:assets, write:attributes…), disabled,
data: X509ProvisioningData { CACertPEM, ignoreExpiryDate }
```

### 5.2 Protocolo (topics y flujo), l.341-560

```
1. Dispositivo conecta ANÓNIMO (sin password) con clientId arbitrario.
2. SUBSCRIBE  provisioning/{uniqueId}/response
3. PUBLISH    provisioning/{uniqueId}/request    payload: {"type":"x509","cert":"-----BEGIN CERTIFICATE-----..."}
4. Servidor:
   a. parsea el cert (ProvisioningUtil.getX509Certificate)
   b. busca una X509ProvisioningConfig cuya CA: issuer == subject de la CA, cert.verify(caPublicKey) OK, validez (salvo ignoreExpiryDate)
   c. exige CN del certificado == {uniqueId} del topic           (UNIQUE_ID_MISMATCH si no)
   d. getCreateClientServiceUser: service user "ps-{uniqueId}" en Keycloak con secret aleatorio + roles de la config (+ rol restricted)
   e. getCreateClientAsset: assetId = hash(realm + uniqueId); si no existe, deserializa assetTemplate.replace("%UNIQUE_ID%", uniqueId) y lo guarda; si restrictedUser → UserAssetLink(user, asset)
   f. mqttBrokerService.authenticateConnection(connection, realm, username, secret)  ← la MISMA conexión queda autenticada
   g. PUBLISH provisioning/{uniqueId}/response  {"type":"success","realm":"...","asset":{...asset completo con id...}}
      o {"type":"error","error":"CERTIFICATE_INVALID|UNAUTHORIZED|UNIQUE_ID_MISMATCH|CONFIG_DISABLED|USER_DISABLED|SERVER_ERROR|ASSET_ERROR|MESSAGE_INVALID|SERVER_BUSY"}
5. Dispositivo ya puede usar {realm}/{clientId}/writeattributevalue/... en la misma sesión.
```

Protecciones: la cola del topic de provisioning se limita a 1000 mensajes (`getPublishTopicAddressSettings`, l.~105) y al borrar/deshabilitar una config se desconectan todos los clientes autenticados por ella (`forceClientDisconnects`). El propio código marca como TODO pasar a mTLS nativo en el broker.

### 5.3 Porte a Talentus (simplificado y práctico)

Para rastreadores GPS clásicos (GT06/Teltonika) **no aplica**: ya se autoprovisionan por IMEI en Traccar (el flujo actual "dispositivo desconocido → aparece en lista pendiente" es el equivalente). Aplica a **dispositivos IoT propios/terceros con MQTT** (ESP32 en tanques, gateways BLE, cámaras, dashcams con MQTT, PLCs).

Tablas: `provisioning_configs (id, account_id, name, type ENUM('x509','psk'), ca_cert_pem TEXT, ignore_expiry, device_template JSON, credential_scopes JSON, disabled)`.

Flujo con EMQX:
- **Opción A (fiel a OR, sin mTLS):** EMQX permite conexión anónima **solo** si el clientId empieza por `prov-` (auth HTTP devuelve `allow` con ACL restringida a `provisioning/{uniqueId}/#`). El bridge Node escucha `provisioning/+/request`, llama `POST /api/provisioning/x509` en Laravel, que verifica la cadena con `openssl_x509_verify()`/`openssl_x509_checkpurpose()` y el CN (`openssl_x509_parse($cert)['subject']['CN']`), crea `devices` desde `device_template` (reemplazando `%UNIQUE_ID%`), crea `mqtt_credentials` (`ps-{uniqueId}` + secret aleatorio), y responde en `provisioning/{uniqueId}/response` con `{realm, device, mqtt:{username, password}}`. El dispositivo **se reconecta** con esas credenciales (EMQX no permite re-autenticar una sesión viva; esto es la diferencia respecto a OR que lo hace in-session).
- **Opción B (mejor):** mTLS en EMQX 8883 con la CA del cliente; EMQX pasa `cert_common_name` al auth HTTP → Laravel aprovisiona en el primer `auth` y responde `allow`. Cero mensajes de provisioning; el CN es la identidad.

---

## 6. LoRaWAN (ChirpStack / The Things Stack) y Modbus

### 6.1 LoRaWAN: `AbstractLoRaWANProtocol` = MQTTProtocol envuelto + importación/descubrimiento

Archivos: `agent/.../lorawan/{LoRaWANAgent,AbstractLoRaWANProtocol,DeviceRecord,LoRaWANConstants}.java`, `lorawan/chirpstack/{ChirpStackAgent,ChirpStackProtocol}.java` (569 l.), `lorawan/tts/TheThingsStackProtocol.java` (1233 l., con máquina de estados gRPC+MQTT).

`LoRaWANAgent` reutiliza **todos** los atributos del `MQTTAgent` (`MQTTHost`, `MQTTPort`, qos, TLS, lastWill…) y añade `applicationId`, `apiKey` (y `ChirpStackAgent` añade `host/port/secureGRPC` del API gRPC). Los links de atributos son **`MQTTAgentLink` normales**; lo que aporta el protocolo es *rellenarlos automáticamente* a partir de la meta `agentLinkConfig` del atributo (`LoRaWANConstants`): `uplinkPort`, `downlinkPort`, `valueFilterJsonPath`, `valueFilterRegex`, `valueConverter`, `writeValueConverter`, `writeObjectValueTemplate`.

`ChirpStackProtocol` (topics y generación de links):

```java
createWildcardSubscriptionTopicList():  "application/{applicationId}/device/+/event/up"     // 1 sola suscripción
configureMQTTSubscriptionTopic():       agentLink.setSubscriptionTopic("application/{appId}/device/{devEUI}/event/up")
configureMQTTPublishTopic():            agentLink.setPublishTopic   ("application/{appId}/device/{devEUI}/command/down")
configureMQTTMessageMatchFilterAndPredicate():  messageMatchFilters=[jsonPath "$.fPort"], messageMatchPredicate=NumberPredicate(uplinkPort)
configureMQTTValueFilter():             valueFilters=[jsonPath(valueFilterJsonPath)]  o  [regex(valueFilterRegex, group 1)]
configureMQTTWriteValueTemplate():      writeValue = {"devEui":"<EUI>","confirmed":true,"data":"%VALUE%" | "object":{<template>},"fPort":<downlinkPort>}
```

Descubrimiento (`discoverDevices`, l.~290): gRPC a ChirpStack (`DeviceServiceGrpc.list(applicationId)` paginado de 100, `DeviceProfileServiceGrpc.get`), filtra perfiles con tag `openremote-asset-type=<NombreClaseAsset>` y crea un asset de esa clase por `devEUI` (id determinista `hash("ChirpStack_"+agentId+devEUI)`). Importación: CSV `devEUI,name,assetTypeName,vendor_id,model_id,firmwareVersion` (`startAssetImport`). El set `devEuiSet` evita duplicados.

**Porte:** ChirpStack v4 expone la **misma API por REST** (`/api/devices?applicationId=…&limit=100&offset=…`, `/api/device-profiles/{id}`, header `Grpc-Metadata-Authorization: Bearer <apiKey>`) así que en Laravel basta `Http::withToken()`; no se necesita gRPC. El worker MQTT de §4.1 ya cubre la suscripción (`application/{id}/device/+/event/up` con `$share`), el `devEUI` del topic identifica el `device`, `fPort` demultiplexa, y `object` (payload ya decodificado por el codec de ChirpStack) se mapea con `jsonPath`. Downlink = `publish("application/{id}/device/{devEUI}/command/down", {"devEui","confirmed","fPort","data"(base64)|"object"})`. Modelo Talentus: `devices.protocol='chirpstack'`, `devices.uniqueid=devEUI`, `agent_links.link={"uplinkPort":1,"downlinkPort":2,"devEui":...}`; perfiles de dispositivo con plantilla de sensores (tag `talentus-device-type` en el device profile de ChirpStack para auto-crear sensores). Uso Perú: sensores de nivel de tanque, temperatura de cadena de frío, contadores de agua, agro — redes LoRaWAN privadas de minas o municipales.

### 6.2 Modbus TCP/RTU

Archivos: `agent/.../modbus/{ModbusAgent,ModbusTcpAgent,ModbusSerialAgent,ModbusAgentLink,AbstractModbusProtocol(976 l.),ModbusTcpProtocol,ModbusSerialProtocol}.java`, `modbus/util/{BatchReadRequest,RegisterRange,ModbusDataConverter,ModbusPduBuilder,…}`.

`ModbusAgentLink`:

```java
Integer unitId;                 // 1-255 (TCP def. 1)
Long requestInterval;           // ms, mín 1000; sin él → lectura única al conectar, escrituras on-demand
ReadMemoryArea readMemoryArea;  // COIL | DISCRETE | HOLDING | INPUT
ModbusDataType readValueType;   // BOOL,SINT,USINT,BYTE,INT,UINT,WORD,DINT,UDINT,DWORD,LINT,ULINT,LWORD,REAL,LREAL,CHAR,WCHAR (con registerCount)
Integer readAddress;            // 1-based (HOLDING 1 = 40001)
WriteMemoryArea writeMemoryArea;// COIL | HOLDING
Integer writeAddress; Integer registersAmount;
```

`ModbusAgent.deviceConfig` (mapa `unitId → {endianFormat: BIG_ENDIAN|LITTLE_ENDIAN|BIG_ENDIAN_BYTE_SWAP|LITTLE_ENDIAN_BYTE_SWAP, illegalRegisters: "100-110,205", maxRegisterLength}`).

Lo valioso para portar es `createBatchRequests` (l.441): agrupa los links por `(unitId, readMemoryArea, requestInterval)`, ordena por dirección y **fusiona en una sola lectura contigua** mientras `address+count−start ≤ maxRegisterLength` y no haya registros ilegales en el hueco; cada atributo guarda su `offset` dentro del batch. Luego `scheduleBatchedReadIntervalTask` hace una petición por batch y `ModbusDataConverter` extrae cada valor con el endianness configurado. Hay `requestLock` para serializar peticiones (Modbus es request/response) y reconexión gestionada por `AbstractNettyIOClient`.

**Porte:** worker Node con `jsmodbus` o `modbus-serial`; copiar el algoritmo de batching tal cual (es puro, ~60 líneas). Para RTU se requiere hardware en sitio → mejor un **gateway Modbus→MQTT** (p.ej. ESP32/Teltonika TRB) y entrar por §4.1. Casos: tanques de combustible de grifos/flotas, generadores, balanzas de camiones, sensores de temperatura industriales.

### 6.3 Otros agentes rápidos de mencionar

- **TCP/UDP/Serial cliente** (`tcp/TCPProtocol`, `udp/UDPProtocol`, `serial/SerialProtocol` sobre `io/AbstractIOClientProtocol`): framing configurable por delimitadores/longitud fija/hex (`getGenericStringEncodersAndDecoders`) + demultiplexado por `messageMatchPredicate`. **No portar**: Traccar ya hace esto mucho mejor para GPS.
- **TCP/UDP servidor** (`tcp/AbstractTCPServerProtocol`, `udp/AbstractUDPServer`): escucha en `bindPort`. Idem.
- **WebSocket cliente** (`websocket/WebsocketAgentProtocol`): `connectURL`, `connectHeaders`, `connectSubscriptions[]` (mensajes a enviar al conectar: tipo `websocket` con body, o tipo `http` con `method/uri/headers/body` — para "registrar" la suscripción vía REST) y `WebsocketAgentLink.websocketSubscriptions[]`. Útil para APIs de terceros que empujan por WS (p.ej. otro servidor de rastreo). Porte: worker Node `ws`.
- **SNMP** (`snmp/SNMPProtocol`): receptor de **traps** (bind host/port, v1/v2c/v3) → `oid → AttributeRef`. Nicho (routers de las bases).
- **Mail** (`mail/MailProtocol`): IMAP/POP3 polling, `subjectMatchPredicate`/`fromMatchPredicate`, `useSubject` → el cuerpo/asunto es el valor. Nicho pero barato: alertas que llegan por correo de proveedores.

---

## 7. Gateway / retransmisión entre instancias

Archivos: `manager/src/main/java/org/openremote/manager/gateway/{GatewayService(1057),GatewayConnector(938),GatewayClientService,GatewayClientConnector(956),GatewayTunnelFactory,MINAGatewayTunnelFactory}.java`; modelo `model/.../gateway/{GatewayConnection,GatewayAttributeFilter,GatewayAssetSyncRule,GatewayTunnelInfo,Gateway*Event}.java`; asset `model/.../asset/impl/GatewayAsset.java`.

### 7.1 Cómo funciona

- **Lado central**: crear un `GatewayAsset` genera un **service user Keycloak** `gateway-{assetIdLower}` con secret (`createUpdateGatewayServiceUser`, l.955). El gateway (una instancia OpenRemote completa en el edge) se conecta al **WebSocket de eventos normal** (`/websocket/events`) con ese client_credentials.
- `GatewayConnector.startSync` (l.521): envía `GatewayInitStartEvent` → el gateway responde con la lista de ids de assets → se piden en **lotes de `SYNC_ASSET_BATCH_SIZE`** (`ReadAssetsEvent`) con timeouts/retries → se guardan localmente **como hijos del GatewayAsset** con ids remapeados determinísticamente (`mapAssetId`, l.905: rota los 2 primeros caracteres del id con un desplazamiento derivado del gatewayId, invertible) → `deleteObsoleteLocalAssets`. A partir de ahí todo `AssetEvent`/`AttributeEvent` del gateway se replica; y toda escritura en el central hacia un descendiente del gateway se **intercepta** (`onAttributeEventIntercepted`, l.404) y se reenvía al gateway, que la aplica y confirma.
- **Lado edge** (`GatewayClientConnector`): `GatewayConnection {localRealm, host, port, realm, clientId, clientSecret, secured, disabled, attributeFilters[], assetSyncRules{}}`. Filtro de salida (`getOutboundAttributeEventFilter`, l.742):

```java
GatewayAttributeFilter { AssetQuery matcher; String duration ("PT5M"); Double delta; Boolean valueChange; Boolean skipAlways; }
// primer filtro cuyo matcher coincide decide: allow (sin condiciones) | skipAlways | valueChange (value≠oldValue) | delta (|v−old|>delta) | duration (ha pasado ≥duration desde el último envío de ese atributo)
GatewayAssetSyncRule { List<String> excludeAttributes; Map<String,List<String>> excludeAttributeMeta; Map<String,MetaMap> addAttributeMeta; Boolean accessPublicRead; }  // por tipo de asset o "*"
```

- **Túneles** (`GatewayTunnelInfo {type HTTPS|HTTP|TCP, target, targetPort, assignedPort, hostname, autoCloseTime}`): el central pide `GatewayTunnelStartRequestEvent`; el edge abre un **SSH reverse port-forward** (Apache MINA SSHD, `MINAGatewayTunnelFactory`) hacia `OR_GATEWAY_TUNNEL_SSH_HOSTNAME:PORT`; el central asigna un puerto desde `OR_GATEWAY_TUNNEL_TCP_START=9000` y el proxy lo publica como `https://{tunnelId}.{hostname}`. Auto-cierre configurable (`OR_GATEWAY_TUNNEL_AUTO_CLOSE_MINUTES`).

### 7.2 Comparación con los *forwards* de Talentus

| Aspecto | OpenRemote Gateway | Talentus forwards/retransmisión |
|---|---|---|
| Dirección | Bidireccional (sync assets + eventos + escrituras + túneles) | Unidireccional (posiciones → endpoint externo HTTP/JSON/NMEA; SUTRAN/OSINERGMIN) |
| Modelo | Instancia completa en el edge, assets espejo en central | Un solo servidor; "retransmisión" = reenvío de tramas/posiciones |
| Filtros | `matcher` + delta/duration/valueChange/skipAlways, exclusión de atributos | Por dispositivo/grupo; sin delta/duration (verificar) |
| Transporte | WebSocket + JWT client_credentials | HTTP POST con colas |
| Acceso remoto | Túneles SSH inversos | No |

**Veredicto:** no portar el gateway (requiere instancia Talentus en el edge y es la pieza más compleja). Sí tomar **tres ideas** para los forwards existentes:
1. `GatewayAttributeFilter` → añadir a cada forward `min_interval_seconds`, `only_on_change`, `min_delta` por sensor (reduce 60-90 % del tráfico hacia APIs de clientes y evita límites de cuota).
2. `GatewayAssetSyncRule.excludeAttributes` → lista de sensores/campos a excluir del JSON reenviado por forward.
3. Para el caso "retransmitir a **otra instancia Talentus/GPSWox** de un cliente" (que Jhamner ya hace con VPS de clientes), el patrón ids-remapeados + sync inicial por lotes + confirmación de escrituras es el diseño correcto si algún día se quiere bidireccional; la forma barata hoy: cada instancia cliente expone el **MQTT API de §4.3** y la instancia central se suscribe como un `MQTTAgent` (§4.1). Es decir, el MQTT API ya resuelve el 80 % del "gateway".

---

## 8. Simulador

Archivos: `agent/.../simulator/{SimulatorAgent,SimulatorAgentLink,SimulatorProtocol(610)}.java`, `model/.../simulator/{SimulatorReplayDatapoint,SimulatorState,RequestSimulatorState}.java`, `manager/.../simulator/SimulatorService.java`.

`SimulatorAgentLink`:

```java
SimulatorReplayDatapoint[] replayData;   // [{timestamp: segundos desde inicio de ocurrencia, value}]
SimulatorProtocol.Schedule schedule;     // {start, end, recurrence: RRULE RFC 5545 (ical4j Recur)} — por defecto 1 día desde hoy 00:00 UTC, una sola vez
TimeZone timezone;
```

`SimulatorProtocol.scheduleReplay` (l.202): calcula `timeSinceOccurrenceStart` según el `Schedule` (`tryAdvanceActive` avanza a la ocurrencia activa según RRULE), busca el siguiente datapoint con `timestamp*1000 > timeSinceOccurrenceStart`, programa **un** `schedule(delay)` que al disparar hace `updateLinkedAttribute(ref, value)` y vuelve a programar el siguiente (sin timers por datapoint). Si el atributo tiene meta `HAS_PREDICTED_DATA_POINTS`, inserta además los valores futuros de la ventana actual/siguiente como *predicted datapoints* (`calculatePredictedDatapoints`). Sin `replayData`, el link simplemente **hace eco de las escrituras** (`doLinkedAttributeWrite → updateSensor`) — un "dispositivo" ficticio que acepta comandos. `SimulatorService` expone `RequestSimulatorState/SimulatorState` por WebSocket para que la UI muestre/edite valores simulados en vivo.

**Porte a Talentus:** muy útil para **demos comerciales** y QA sin hardware. Dos niveles:
1. *Sensores simulados*: `agent_links` con `agent.type='simulator'` y `link={"replayData":[{"t":0,"v":18.5},{"t":600,"v":19.2},…],"schedule":{"rrule":"FREQ=DAILY","start":"2026-11-01T00:00","tz":"America/Lima"}}`; un job de Laravel cada minuto (o worker Node para segundos) avanza el replay. Lib: `rlanvin/php-rrule` (PHP) o `rrule` (npm).
2. *Posiciones simuladas*: Talentus ya tiene Traccar; lo más efectivo es un **script Node que reproduce un GPX/ruta** y envía tramas al puerto OsmAnd (`http://traccar:5055/?id=IMEI&lat=&lon=&speed=`) o al protocolo GT06 — así todo el pipeline (Traccar→eventos→geocercas→Socket.io) se ejercita de verdad. La UI del simulador de OR (replay de dataset con cronograma) es el modelo a copiar.

---

## 9. Stack de despliegue (para entender dependencias)

`docker-compose.yml`:

| Servicio | Imagen | Rol | Equivalente Talentus |
|---|---|---|---|
| `proxy` | `openremote/proxy` (HAProxy + Let's Encrypt) | 80/443 → manager; **8883 → 1883 MQTT TLS**; `*.hostname` → túneles gateway; métricas 8404 | Nginx; añadir stream TLS 8883 → EMQX o dejar TLS a EMQX |
| `postgresql` | `openremote/postgresql` (PostGIS + TimescaleDB) | assets (JSONB), datapoints, reglas | MySQL (+ Redis). PostGIS no es necesario; JSON en MySQL 8 funciona para `config`/`link` |
| `keycloak` | `openremote/keycloak` | Identidad: realms (tenants), usuarios, **service users** (client_credentials) para MQTT/gateway, roles `read:assets`/`write:attributes`… | Laravel auth + tabla `mqtt_credentials` + tokens API existentes |
| `manager` | `openremote/manager` | Todo lo anterior en un proceso JVM (REST, WS, MQTT 1883, agentes, reglas) | Laravel + Node (Socket.io) + Traccar + **nuevo: EMQX + workers Node de agentes** |
| `map` (perfil dev) | `tileserver-gl` con `mapsettings.json` | Tiles vectoriales offline | Leaflet con OSM/Mapbox (ya existe) |
| `query-exporter` | Prometheus exporter SQL | métricas | Opcional |

Variables relevantes: `MQTT_SERVER_LISTEN_HOST/PORT`, `OR_GATEWAY_TUNNEL_*`, `OR_OPEN_WEATHER_API_APP_ID`, `OR_FORECAST_SOLAR_API_KEY`. Keycloak es **requisito duro** del broker MQTT (`MQTTBrokerService.init`: "MQTT connections are not supported when not using Keycloak") — otra razón para no portar el broker sino integrar EMQX con auth HTTP contra Laravel.

---

## 10. Plan de porte propuesto (fases)

**Fase 1 — Núcleo (2-3 semanas):** tablas `agents`, `agent_links`; `ValuePipeline` PHP + paquete TS equivalente con tests compartidos (mismos JSON de casos); UI de edición de filtros (lista ordenada, vista previa con payload de ejemplo); integrar el pipeline como "fórmula avanzada" de sensores Traccar/BLE.

**Fase 2 — HTTP Agent (1 semana):** jobs `DispatchHttpAgentPolls`/`PollHttpAgentLink`, OAuth2 client_credentials cacheado, paginación `Link`, `pollingAttribute` (una respuesta → N sensores), escritura por atributo. Plantillas listas: OpenWeatherMap one-call, tipo de cambio.

**Fase 3 — MQTT (2-3 semanas):** EMQX en Docker junto a Nginx; endpoints `/api/mqtt/auth|acl`; tabla `mqtt_credentials` + UI; bridge Node (Redis → topics; `$share` write/command → Laravel); documentación pública del MQTT API (topics §4.3) para integradores; presencia por webhook EMQX. Luego worker `mqtt-agent` (§4.1) reutilizando el pipeline.

**Fase 4 — IoT (2 semanas):** ChirpStack (REST discovery + MQTT uplink/downlink + perfiles → sensores), provisioning X.509/mTLS (opción B), Modbus TCP worker con batching.

**Fase 5 — Simulador y refinamiento de forwards (1 semana):** replay RRULE para sensores; replay GPX hacia Traccar; filtros `only_on_change/min_delta/min_interval` y `exclude_fields` en forwards.

---

## 11. Índice de archivos clave

```
model/src/main/java/org/openremote/model/asset/agent/Agent.java            atributos comunes de agentes
model/src/main/java/org/openremote/model/asset/agent/AgentLink.java        filtros/convertidores/writeValue/matchPredicate
model/src/main/java/org/openremote/model/asset/agent/Protocol.java         ciclo de vida (javadoc) 
model/src/main/java/org/openremote/model/protocol/ProtocolUtil.java        doInbound/OutboundValueProcessing, applyValueConverter, createGenericAttributeMessageConsumer
model/src/main/java/org/openremote/model/value/{JsonPathFilter,RegexValueFilter,SubStringValueFilter,MathExpressionValueFilter}.java
model/src/main/java/org/openremote/model/util/ValueUtil.java:1398-1470     %TIME% / %VALUE%
model/src/main/java/org/openremote/model/Constants.java:99-100              regex de placeholders
model/src/main/java/org/openremote/model/protocol/{ProtocolAssetService,ProtocolAssetDiscovery,ProtocolAssetImport,ProtocolInstanceDiscovery}.java
model/src/main/java/org/openremote/model/protocol/mqtt/Topic.java
model/src/main/java/org/openremote/model/provisioning/*.java
model/src/main/java/org/openremote/model/gateway/*.java
agent/src/main/java/org/openremote/agent/protocol/AbstractProtocol.java    base de todos los protocolos
agent/src/main/java/org/openremote/agent/protocol/ProtocolDatapointService.java
agent/src/main/java/org/openremote/agent/protocol/io/AbstractIOClientProtocol.java   framing TCP/UDP/serial
agent/src/main/java/org/openremote/agent/protocol/http/{HTTPAgent,HTTPAgentLink,HTTPProtocol}.java
agent/src/main/java/org/openremote/agent/protocol/mqtt/{MQTTAgent,MQTTAgentLink,MQTTProtocol}.java
agent/src/main/java/org/openremote/agent/protocol/websocket/{WebsocketAgent,WebsocketAgentProtocol,WebsocketHTTPSubscription}.java
agent/src/main/java/org/openremote/agent/protocol/lorawan/{LoRaWANAgent,AbstractLoRaWANProtocol,LoRaWANConstants}.java
agent/src/main/java/org/openremote/agent/protocol/lorawan/chirpstack/ChirpStackProtocol.java
agent/src/main/java/org/openremote/agent/protocol/modbus/{ModbusAgent,ModbusAgentLink,AbstractModbusProtocol}.java (createBatchRequests l.441)
agent/src/main/java/org/openremote/agent/protocol/openweathermap/OpenWeatherMapProtocol.java
agent/src/main/java/org/openremote/agent/protocol/simulator/{SimulatorAgentLink,SimulatorProtocol}.java
agent/src/main/java/org/openremote/agent/protocol/snmp/SNMPProtocol.java ; mail/MailProtocol.java
manager/src/main/java/org/openremote/manager/agent/AgentService.java       startAgent l.434, intercept l.616, discovery l.861-992
manager/src/main/java/org/openremote/manager/mqtt/MQTTBrokerService.java   Artemis embebido, 1883, sin retained
manager/src/main/java/org/openremote/manager/mqtt/ActiveMQORSecurityManager.java  auth "{realm}:{user}" + delegación a handlers
manager/src/main/java/org/openremote/manager/mqtt/MQTTHandler.java         topicRealm/topicClientID/topicTokenIndexToString
manager/src/main/java/org/openremote/manager/mqtt/DefaultMQTTHandler.java  topics asset/attribute/attributevalue/writeattribute(value)
manager/src/main/java/org/openremote/manager/mqtt/ConnectionMonitorHandler.java  presencia USER_CONNECTED
manager/src/main/java/org/openremote/manager/mqtt/UserAssetProvisioningMQTTHandler.java  X.509 provisioning (flujo l.341-560, user/asset l.637-740)
manager/src/main/java/org/openremote/manager/gateway/{GatewayService,GatewayConnector,GatewayClientConnector}.java
manager/src/main/java/org/openremote/manager/simulator/SimulatorService.java
docker-compose.yml, profile/*.yml, deployment/map/mapsettings.json
```
