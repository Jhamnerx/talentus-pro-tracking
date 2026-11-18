# 05 — Soporte Teltonika en OpenRemote (análisis en profundidad) y propuesta de porte a Talentus Pro Tracking

Bases analizadas:

| | Repo | Ruta local | Fecha | Tamaño |
|---|---|---|---|---|
| **A** | `openremote/openremote` rama `feature/teltonika` (merge de master 6-mar-2026, commit `4153703`) | `/home/claude/openremote/core-teltonika` | marzo 2026 | ~12.200 líneas Java/Groovy (5.975 solo en `TeltonikaParameters.java`) |
| **B** | `openremote/fleet-management` rama `main` (custom-project) | `/home/claude/openremote/fleet` | 2024 | ~2.100 líneas Java + `FMC003.json` (555 parámetros) |

> El clon de A es *shallow* (1 solo commit), por lo que no hay historia de commits que analizar. B tampoco tiene `.git` útil (último commit visible: "Fix CI/CD deployment profiles").

---

## 1. Arquitectura de cada versión y estado

### 1.1 Versión B (fleet-management, 2024) — "custom project" sólo MQTT

Es un *custom project* de OpenRemote (plantilla `openremote/custom-project`) que añade un `MQTTHandler` propio y assets personalizados. **Sólo recibe datos por MQTT (JSON del firmware MQTT de Teltonika). No decodifica Codec 8/8E binario.**

Componentes (todos en `/home/claude/openremote/fleet`):

| Capa | Clase | Qué hace |
|---|---|---|
| manager | `manager/src/main/java/telematics/teltonika/TeltonikaMQTTHandler.java` (746 líneas) | Handler MQTT del broker embebido (ActiveMQ Artemis). Suscribe a `+/+/teltonika/+/data` y `+/+/teltonika/+/commands`, crea/actualiza `CarAsset` por IMEI, envía comandos, detecta viajes (AVL 250 "Trip") y calcula `AssetStateDuration`. |
| manager | `TeltonikaDataPayload.java` (411) | Convierte `state.reported{...}` → `AttributeMap` usando el mapa de parámetros del modelo; aplica multiplicador, min/max, label, unidades. |
| manager | `TeltonikaResponsePayload.java` | Parsea `{"RSP":"OK"}` (respuesta a comando) hacia el atributo `response`. |
| manager | `TeltonikaPayloadFactory.java` | Si el JSON tiene `state` → DataPayload; si tiene `RSP` → ResponsePayload. |
| manager | `TeltonikaConfiguration.java` + `helpers/TeltonikaConfigurationFactory.java` | Singleton con TTL de 1 min que lee desde la BD el `TeltonikaConfigurationAsset` maestro y los `TeltonikaModelConfigurationAsset` hijos (realm `master`). Si no existen, los crea desde `FMC003.json`. |
| model | `model/src/main/java/org/openremote/model/teltonika/TeltonikaParameter.java` | POJO Jackson del parámetro AVL (11 campos, ver §4). |
| model | `TeltonikaModelConfigurationAsset.java` | Asset "configuración de modelo": `modelNumber`, `TeltonikaParameterData` (array), `TeltonikaParameterMap` (mapa id→param, read-only). |
| model | `TeltonikaConfigurationAsset.java` | Asset maestro: `Enabled`, `CheckForValidIMEI`, `StorePayloads`, `defaultModelNumber`, `command`, `response`, `deviceIMEIWhitelist`. |
| model | `State.java`, `TeltonikaDataPayloadModel.java`, `PayloadJsonObject.java`, `IMEIValidator.java` | Modelo del JSON `{"state":{"reported":{...}}}` y validador Luhn de IMEI. |
| model | `model/src/main/java/org/openremote/model/custom/VehicleAsset.java`, `CarAsset.java` | Asset del vehículo: `IMEI`, `lastContact`, `modelNumber`, `direction`, `location`; `CarAsset` añade `239` (ignition), `240` (movement), `16` (odometer), `sp`, `alt`, `sat`, `pr`, `evt`. |
| deployment | `deployment/manager/fleet/FMC003.json` | **Fichero de parámetros AVL del modelo FMC003: 555 entradas.** |
| test | `test/src/test/groovy/org/openremote/test/custom/TeltonikaMQTTProtocolTest.groovy` (10 casos) + `test/src/test/resources/teltonika/teltonikaValidPayload1.json`, `SortedPayloads.json` (762 payloads reales) | Pruebas de topics, parseo, comandos y timestamps. |
| agent | `agent/src/main/java/org/openremote/agent/teltonika/CustomAgentModelProvider.java` | Sólo `useAutoScan()=true`; no hay agente TCP/UDP. |
| ui | `ui/app/custom`, `ui/app/custom-react` | **Plantillas vacías del custom-project; `grep -ri teltonika ui/` no devuelve nada.** La "UI de fleet" vive en la rama `feature/fleet-management` del core (según README), no en este repo. |

Estado: funcional para MQTT con un único modelo (FMC003) y declaradamente *work-in-progress* ("until auto-provisioning is fully implemented", "TODO: Fix this, constraints for some reason are not being applied", `getModelParameterMap(modelNumber)` ignora el argumento y devuelve siempre el mapa del modelo por defecto). Publica MQTT anónimo (`canPublish()` devuelve siempre `true`). El código se basa en la rama `feature/fleet-management` del core, hoy obsoleta.

### 1.2 Versión A (core `feature/teltonika`, 2026) — framework "telematics" multi-vendor + decoder binario

Reescritura completa integrada en el core (no en un custom project). Introduce un paquete genérico `org.openremote.model.telematics` y la primera implementación: Teltonika, con **tres transportes: TCP AVL, UDP AVL y MQTT JSON**.

```
agent/…/protocol/teltonika/            (servidor Netty TCP/UDP + decoder Codec 8/8E/16/12/13/14/15/GH3000)
   TeltonikaAgent            Agent OpenRemote (atributos bindPort, bindHost, transport=TCP|UDP|BOTH)
   TeltonikaProtocol         Arranca TeltonikaTcpServer/TeltonikaUdpServer y publica DeviceMessage al TelematicsService
   TeltonikaProtocolDecoder  ByteToMessageDecoder: IMEI handshake, ACK, CRC-16/IBM, records, IO elements
   TeltonikaRecord           POJO de un record AVL (imei, timestamp, AttributeMap, codecName, transport)
   TeltonikaTcpServer / TeltonikaUdpServer   extienden AbstractTCPServer/AbstractUDPServer<TeltonikaRecord>

manager/…/manager/telematics/          (runtime)
   TelematicsService         ContainerService + RouteBuilder Camel: cola SEDA "telematics-device-messages"
                             (10.000 msgs, 4 consumidores), registro de vendors, mapa de DeviceConnection
   TelematicsVendor          Interfaz de vendor (id, protocolo, registry, assetMapper, sessionManager, commandMapper)
   TeltonikaVendor           Implementación: TeltonikaMqttJsonProtocol, TeltonikaTransportProtocol(TCP/UDP),
                             TeltonikaCommandMapper, TeltonikaSessionManager, TeltonikaSession
   TeltonikaMQTTHandler      MQTTHandler del broker: topics data/commands, decode JSON → submitMessage
   TeltonikaMessageHandler   Consumidor de la cola: find-or-create TeltonikaTrackerAsset, merge + AttributeEvents

model/…/model/telematics/
   core/      DeviceMessage, TelematicsMessageEnvelope, TelematicsMessageHandler, TelematicsMessagePublisher
   parameter/ ParameterParser, StandardParsers, ParseableValueDescriptor, DynamicParameter,
              ParameterRegistry, TelematicsParameterRegistry, ParameterParseException
   protocol/  DeviceProtocol, DeviceCommand, MessageContext, Protocol{Decode,Encode}Exception
   session/   DeviceSession, DeviceSessionManager, DeviceConnection
   asset/     TelematicsAssetMapper
   teltonika/ TeltonikaParameter, TeltonikaParameters (641 defs), TeltonikaRegistry, TeltonikaAttributeResolver,
              TeltonikaAssetMapper, TeltonikaTrackerAsset
```

Registro en el contenedor (ServiceLoader):
- `manager/src/main/resources/META-INF/services/org.openremote.model.ContainerService` línea 39: `org.openremote.manager.telematics.TelematicsService`
- `manager/src/main/resources/META-INF/services/org.openremote.manager.mqtt.MQTTHandler` línea 4: `org.openremote.manager.telematics.TeltonikaMQTTHandler`
- `model/src/main/java/org/openremote/model/StandardModelProvider.java` línea 45: `@ModelDescriptor(assetType = Asset.class, provider = TeltonikaParameters.class)` (expone los value descriptors al modelo/UI).
- `model/build.gradle` línea 10: `api "io.netty:netty-buffer:$nettyVersion"` → el **módulo `model` ahora depende de Netty** (los parsers reciben `ByteBuf`).

**Nota:** `container/src/main/java/org/openremote/container/util/CodecUtil.java` NO tiene relación con Teltonika: es un helper Base64 de 2018 (`decodeBase64` con soporte data-URL). La decodificación binaria está íntegramente en `TeltonikaProtocolDecoder` + `StandardParsers`.

Estado: *feature branch* sin mergear a master (a fecha del clon). Tiene 2 specs Spock con ~25 casos (vectores hex TCP/UDP Codec 8, 8E, 16, 12, 13, malformados, ping, IMEI, E2E MQTT con provisión de asset, agentes TCP/UDP reales con sockets). No se han ejecutado aquí (requiere Docker/Keycloak), pero los asserts son coherentes con el decoder. Hay un test `@Ignore` de carga ("publish same packet every 100ms"). No existe UI específica (`grep -ri teltonika ui/` → vacío) ni recursos JSON/CSV: **la lista de parámetros está compilada en Java**.

### 1.3 Cuál es la principal

**A es la versión principal y más madura**: integrada en el core, multi-transporte (incluye el binario que B no tiene), framework multi-vendor, decoder con CRC y tests con vectores reales. B es el prototipo de 2024 cuya idea clave (JSON de parámetros por modelo, configuración por modelo como asset, trip detection) inspiró A. Sin embargo, **B tiene dos cosas que A perdió**: (1) el fichero de parámetros en formato dato (`FMC003.json`) editable por el usuario desde la UI (atributo `TeltonikaParameterData`), y (2) la configuración por modelo de dispositivo (`TeltonikaModelConfigurationAsset`) con `defaultModelNumber` y `modelNumber` por asset. En A el registro es único/global y `hwSupport` es informativo.

---

## 2. Protocolo binario (sólo A): `TeltonikaProtocolDecoder`

Fichero: `/home/claude/openremote/core-teltonika/agent/src/main/java/org/openremote/agent/protocol/teltonika/TeltonikaProtocolDecoder.java` (650 líneas). Extiende `io.netty.handler.codec.ByteToMessageDecoder`; constructor `TeltonikaProtocolDecoder(boolean connectionless)` (UDP=true).

### 2.1 Códecs soportados (líneas 43-50)
`0x07` GH3000, `0x08` Codec 8, `0x8E` Codec 8 Extended, `0x0C` Codec 12 (serial/cámara), `0x0D` Codec 13, `0x0E` Codec 14, `0x0F` Codec 15, `0x10` Codec 16.

### 2.2 TCP (`decodeTcp`, líneas 83-120)
1. Ping: si llega 1 byte `0xFF` se consume y se ignora (l. 89).
2. **IMEI handshake**: si los 2 primeros bytes (longitud) son `0 < len < 20` se interpreta como `len + IMEI ASCII`. `parseIdentification` (l. 159) lee el IMEI, **responde `0x01`** (aceptar) y lo guarda en el decoder (estado por conexión).
3. Paquete de datos: preámbulo 4 bytes `0x00000000` (si no → `IllegalStateException`), luego `parseData`.

### 2.3 `parseData` (l. 176-268) — estructura común
```
[TCP] dataLength u32 → dataStartIndex
codec u8, count u8
count × record  (decodeLocation | decodeCodec12 | decodeCodec13 | decodeCodec14Or15)
count2 u8   (debe coincidir con count, l. 231)
[TCP] salta bytes sobrantes hasta dataLength, lee CRC u32 y valida CRC-16/IBM (poly 0xA001, l. 607)
[TCP] ACK = u32 count (sendTcpAck, l. 560) — no se envía para Codec 12/13/14/15
```
Observación: si el frame TCP aún no está completo lanza excepción (l. 192-195) en vez de `return` para esperar más bytes, lo que haría fallar frames fragmentados por TCP (Traccar usa un `TeltonikaFrameDecoder` previo que espera la trama completa).

### 2.4 Record AVL estándar (`decodeStandardLocation`, l. 337-396)
```
timestamp  i64 (ms epoch)          → record.timestamp + atributo TIMESTAMP
priority   u8                      → PRIORITY (0 low, 1 high, 2 panic)
longitude  i32 / 1e7, latitude i32 / 1e7 → GeoJSONPoint LOCATION
altitude   i16 → ALTITUDE (m);  direction u16 → DIRECTION (deg);  satellites u8 → SATELLITES (valid = sats>0)
speed      u16 → SPEED (km/h)
eventId    u8 (Codec8) | u16 (Codec8E, Codec16) → EVENT_TRIGGERED
[Codec16] generation u8
totalIoCount u8 | u16 (8E)
```
### 2.5 IO elements (`readIoElements`, l. 401-450)
Grupos por tamaño con máscara global (siempre `0x0F` salvo GH3000):
- 1 byte: `count` (u8/u16 en 8E) × (`id` u8 | u16 en 8E y 16; valor 1 B)
- 2 bytes, 4 bytes, 8 bytes igual
- **Codec 8E: grupo adicional variable** `count u16 × (id u16, length u16, bytes[length])` (l. 442-449) — necesario para ICCID, VIN, ISO6709 (AVL 387), BLE…

Cada IO se resuelve con `attributeResolver.resolveBinaryIo(id, slice, 0)` (l. 459) → ver §4.3. La función `readExtByte` (l. 547) elige 1 o 2 bytes según el códec.

### 2.6 GH3000 (`decodeGh3000Location`, l. 295-332)
Timestamp u32 & 0x3FFFFFFF + 1167609600 (epoch 2007-01-01), `globalMask`, `locationMask` con lat/lon float, altitud, curso (`*360/256`), speed satélite, satélites. Mismo algoritmo que Traccar.

### 2.7 Codec 12/13/14/15 (respuestas a comandos GPRS)
- Codec 12 (l. 475): `type u8, length i32, datos`; `type==0x0D` → `cameraData` (hex), si no `serialData` (UTF-8). Timestamp = ahora.
- Codec 13 (l. 502): `type, length-4, timestamp u32 s, texto` → atributo `textCommand`.
- Codec 14/15 (l. 517): payload bruto, extrae ASCII imprimible o hex; `codecMessageType`.
- **No implementa el *encode* de Codec 12 para enviar comandos por TCP**: `TeltonikaTransportProtocol.encodeCommand` (TeltonikaVendor.java l. 318-330) devuelve el JSON `{"CMD": "..."}` igual que MQTT, y `DeviceSession.queueCommand` devuelve `true` sin hacer nada (l. 464). Es decir, **en A los comandos sólo funcionan de verdad por MQTT**.

### 2.8 UDP (`decodeUdp`, l. 125-154)
`length u16, packetId u16, packetType u8, avlPacketId u8, imeiLen u16, IMEI ASCII, [codec, count, records, count]`. ACK (l. 569): `0005 packetId 01 avlPacketId count` (7 bytes). Sin CRC en UDP.

### 2.9 Comparación con Traccar (`org.traccar.protocol.TeltonikaProtocolDecoder`)
| Aspecto | Traccar | OpenRemote A | ¿Aporta A? |
|---|---|---|---|
| Codecs 7/8/8E/12/13/14/16, IMEI handshake, ACK, UDP | Sí (más Codec 12 **encode** de comandos GPRS vía `TeltonikaProtocolEncoder`) | Sí decode; encode TCP ausente | No |
| CRC-16 en TCP | Lo lee pero no lo valida | Lo valida y rechaza | Marginal |
| Framing TCP (frames parciales) | `TeltonikaFrameDecoder` | Excepción | Traccar mejor |
| Resolución de IO IDs | Tabla hardcodeada en `decodeOtherParameter`/`decodeParameter` (~60-80 IDs: 1-4 din, 9-10 adc, 16 odometer, 17-19 ejes, 21 rssi, 24 speed, 66 power, 67 battery, 68 batteryCurrent, 69, 72-75 temp, 78 driverUniqueId, 80, 179/180 dout, 181/182 pdop/hdop, 199, 200, 205/206 cell, 236 alarm, 239 ignition, 240 motion, 241 operator, 253, 255…, más bloques CAN/OBD por `decodeCan`). Lo demás → `io<ID>` crudo sin unidad ni multiplicador. | **641 definiciones** con nombre, unidad, multiplicador, min/max, descripción, grupo, hwSupport; desconocidos → `teltonika_<id>` tipado por longitud | **Sí: este es el valor diferencial** |
| Variable-length IO (8E) | Sí | Sí | Igual |
| Multi-modelo | Config `decoder.ignoreSessionCache`, etc., no por modelo | No por modelo (hwSupport informativo) | — |

Conclusión: para Talentus, que ya tiene Traccar, **el decoder binario de A no aporta nada que Traccar no haga mejor** (y Traccar ya envía Codec 12). Lo portable es el **registro de parámetros y la lógica de resolución/tipado**.

---

## 3. Recepción por MQTT

### 3.1 Topics (idénticos en A y B)
```
{realm}/{clientId}/teltonika/{IMEI}/data       ← dispositivo publica telemetría (state.reported)
{realm}/{clientId}/teltonika/{IMEI}/commands   → plataforma publica {"CMD": "..."}; dispositivo responde {"RSP": "..."} en data
```
- A: `TeltonikaMQTTHandler.java` l. 31-33 constantes `data`, `commands`, `teltonika`; `topicMatches` comprueba el token índice 2 == `teltonika`; `canSubscribe` exige ≥5 tokens e IMEI numérico (l. 74-88); `canPublish` → `true` (anónimo). `onSubscribe` → `telematicsService.markTrackerConnected(...)`; `onUnsubscribe` → `markTrackerDisconnected`.
- B: igual, además valida IMEI con Luhn si `CheckForValidIMEI` (`IMEIValidator.isValidIMEI`), y permite deshabilitar todo con `Enabled`.
- El **IMEI se obtiene del topic** (token índice 3, `getImeiFromTopic`), no del payload. El firmware MQTT de Teltonika permite configurar el topic en el configurador (Data Topic / Command Topic), por eso OpenRemote impone esa estructura. El `clientId` (token 1) se guarda como atributo `ClientId` en B.

### 3.2 Payload real (test A `TeltonikaMQTTClientProtocolTest.groovy` l. 126-172, y B `teltonikaValidPayload1.json`)
```json
{"state":{"reported":{
  "ts":1698922776030, "pr":1, "latlng":"0.000000,0.000000", "alt":0, "ang":0, "sat":0, "sp":0, "evt":250,
  "239":0, "240":1, "80":1, "21":4, "200":0, "69":2, "237":2, "113":100, "263":2, "303":1, "250":1,
  "181":0, "182":0, "66":11922, "24":0, "206":30, "67":4110, "68":0, "13":2840, "17":45, "18":-11, "19":988,
  "15":1000, "241":20416, "199":0, "16":6870, "12":244, "449":0, "636":53977651, "11":893116211,
  "238":"0x0000000000000000", "14":1680671168, "387":"+000000.0000+0000000.0000+000.000/"
}}}
```
Claves: `ts` (ms epoch), `pr`, `latlng` ("lat,lon"), `alt`, `ang`, `sat`, `sp`, `evt`, y luego **los AVL IDs como strings con el valor crudo (sin multiplicador)**: `"66":11922` = 11,922 V. El test A asegura que `BATTERY_VOLTAGE` (`67:3466`) acaba como `3.466`.

### 3.3 Decodificación
- **A**: `TeltonikaVendor.TeltonikaMqttJsonProtocol.decode` (l. 173-212) → `extractPayload` toma `state.reported` o, si hay `RSP`, `{"response": "..."}`; `extractTimestamp` usa `ts`; cada clave pasa por `TeltonikaAttributeResolver.resolveJson(id, valor, ts)`, que **convierte el valor JSON a bytes del tamaño del parámetro y reutiliza el mismo parser binario** (`convertJsonValueToBinary`, l. 221-291 del resolver). Todos los atributos se marcan `READ_ONLY`. Se construye `DeviceMessage` y se encola en `TelematicsService.submitMessage`.
- **B**: `TeltonikaDataPayload.getAttributesFromPayload` + `getAttributes` (ver §4.4).

### 3.4 Comandos
- A: `DeviceCommand` (`model/telematics/protocol/DeviceCommand.java`) es sólo un string; helpers `setOutput(n, state)` → `"setdigout n 1|0"`, `text(cmd)`. `TeltonikaCommandMapper.toOutboundPayload` → `Map.of("CMD", cmd)`; `TeltonikaMQTTHandler.sendCommand(realm, clientId, imei, cmd)` publica QoS 1 en `.../commands`. Test `TeltonikaMQTTHandlerCommandTest.groovy`: `getinfo` → `{"CMD":"getinfo"}`; `setOutput(1,true)` → `"setdigout 1 1"`; topic `master/clientA/teltonika/123456789012345/commands`; `{"RSP":"OK"}` → atributo `teltonika_response="OK"`.
- B: escribir el atributo `sendToDevice` del `CarAsset` (nombre configurable en el asset maestro) dispara `handleAttributeMessage` → `publishMessage(commandTopic, Map.of("CMD", command), QoS 2)` (l. 236). La respuesta `{"RSP":...}` se escribe en el atributo `response`. Sólo si el IMEI está en `connectionSubscriberInfoMap` (dispositivo suscrito).

---

## 4. Registro de parámetros AVL (lo más valioso)

### 4.1 Origen de los datos
- **B** `FMC003.json`: 555 objetos. El propio texto delata su origen: los valores de `hwSupport` son las listas de modelos de las tablas de la **wiki de Teltonika** (`wiki.teltonika-gps.com/view/FMC003_Teltonika_Data_Sending_Parameters_ID`), las `description` conservan guiones tipográficos "–" de la wiki, y campos como `"bytes":"Variable"`, `"max":"0xffffffffffffffff"`. No hay script de scraping en el repo; es un volcado manual/semiautomático de las tablas "Permanent I/O elements", "Eventual I/O elements", "OBD elements", "CAN adapters/ALLCAN300/LVCAN200/CANCONTROL", "Bluetooth Low Energy".
- **A** `TeltonikaParameters.java` l. 11-15: *"Generated from teltonikaAvlIds.csv plus JSON frame parameters."* El CSV no está en el repo. Es claramente una regeneración del mismo origen: aparecen residuos HTML del scraping en `hwSupport` (`"FMBXXX [Expand]"`, `"FMBXXX FMB001</br>FMB003</br>…"`, `"FMBXXX </span"`). Comparando IDs: A tiene 633 numéricos + 8 de trama (`pr, alt, ang, sat, sp, evt, ts, latlng`); B 555. **A ⊃ B salvo 2 IDs (410, 412)**; A añade 80 IDs nuevos (318, 383, 400, 450-462, 622-623, 859-867 "service", 1079-1082 "bale count", 1116-1117, 1148, 1205-1209 "road sign", 1412…), es decir, está scrapeado de una versión más reciente de la wiki (lista "FMB" genérica, de ahí `hwSupport("FMBXXX")` en 535 de 641).

Distribución por grupo en A: ALLCAN300 229, Permanent I/O 134, Eventual I/O 76, BLE 44, ALLCAN300+CANCONTROL 43, OBD 39, CAN adapters 22, LVCAN variantes ~35, OBD OEM 7.

### 4.2 Estructura de un parámetro

**B** (`model/teltonika/TeltonikaParameter.java`, POJO Jackson, todo String excepto el id):
```json
{"propertyIdInAvlPacket":66,"propertyName":"External Voltage","bytes":"2","type":"Unsigned",
 "min":"0","max":"65535","multiplier":"0.001","units":"V","description":"Voltage",
 "hwSupport":"FMBXXX FMB001 FMC001 FMB010 FMB002 FMB020 FMB003 FMB110 FMB120 FMB122 FMB125 FMB130 FMB140 FMB150 FMC150 FMM150 FMU125 FMB900 FMB920 FMB962 FMB964 FM3001 FMB202 FMB204 FMB206 FMT100 MTB100 FMP100 MSP500 FMC800 FMM800 FMM80A",
 "parameterGroup":"Permanent I/O elements"}
```
Ejemplos reales extraídos de `FMC003.json`:

| id | propertyName | bytes | type | min | max | multiplier | units | description (recortada) |
|---|---|---|---|---|---|---|---|---|
| 239 | Ignition | 1 | Unsigned | 0 | 1 | - | - | 0 – Ignition Off 1 – Ignition On |
| 240 | Movement | 1 | Unsigned | 0 | 1 | - | - | 0 – Movement Off 1 – Movement On |
| 21 | GSM Signal | 1 | Unsigned | 0 | 5 | - | - | Value in range 1-5 |
| 200 | Sleep Mode | 1 | Unsigned | 0 | 4 | - | - | 0 No Sleep 1 GPS Sleep 2 Deep Sleep 3 Online Sleep 4 Ultra Sleep |
| 181 | GNSS PDOP | 2 | Unsigned | 0 | 500 | 0.1 | null | Coefficient |
| 66 | External Voltage | 2 | Unsigned | 0 | 65535 | 0.001 | V | Voltage |
| 16 | Total Odometer | 4 | Unsigned | 0 | 2147483647 | - | - | Total Odometer value in meters |
| 1 | Digital Input 1 | 1 | Unsigned | 0 | 1 | - | - | Logic: 0/1 |
| 9 | Analog Input 1 | 2 | Unsigned | 0 | 65535 | 0.001 | V | Voltage |
| 72 | Dallas Temperature 1 | 4 | Signed | -550 | 1150 | 0.1 | °C | -55..+115; 850 not ready, 2000 read error, 3000 not connected, 4000 ID failed |
| 78 | iButton | 8 | Unsigned | 0 | 0xffffffffffffffff | - | - | iButton ID |

Tipos en B: `Unsigned` 482, `Signed` 34, `UNSIGNED LONG INT` 16, `HEX` 15, `ASCII` 6. Bytes: `1` 340, `2` 97, `4` 68, `8` 24, `Variable` 8, otros (17, 34, 35, 40, 1024).

**A** (`TeltonikaParameter<T> extends ParseableValueDescriptor<T> extends ValueDescriptor<T>`, builder, l. 27-243): campos `id` (String: "239" o "sp"), `displayName`, `description`, `units`, `parameterGroup`, `hwSupport`, `minValue`, `maxValue`, `multiplier`, más `type` (clase Java), `byteLength`, `parser`, `constraints`. El nombre del ValueDescriptor es `teltonika_<id>`. Ejemplo (l. 20-29):
```java
public static final TeltonikaParameter<Boolean> IGNITION = TeltonikaParameter.<Boolean>builder()
    .id(239).type(Boolean.class).parser(StandardParsers.BOOLEAN).byteLength(1)
    .displayName("Ignition").parameterGroup("Permanent I/O Elements").hwSupport("FMBXXX")
    .description("0 – Ignition Off 1 – Ignition On").minValue(0L).maxValue(1L).build();
public static final TeltonikaParameter<Double> EXTERNAL_VOLTAGE = TeltonikaParameter.<Double>builder()
    .id(66).type(Double.class).parser(StandardParsers.unsignedShortWithMultiplier(0.001)).byteLength(2)
    .displayName("External Voltage").units("V")....multiplier(0.001).build();
public static final TeltonikaParameter<Double> DALLAS_TEMPERATURE_1 = ...id(72).type(Double.class)
    .parser((buf, len) -> buf.readInt() * 0.1).byteLength(4).units("°C").minValue(-550L).maxValue(1150L).multiplier(0.1)
public static final TeltonikaParameter<String> IBUTTON = ...id(78).type(String.class).parser(StandardParsers.HEX_LONG).byteLength(8)
public static final TeltonikaParameter<GeoJSONPoint> ISO6709_COORDINATES = ...id(387).parser(parseIso6709).byteLength(34)
```
La ventaja de A sobre B: el tipo ya está decidido en compilación (Boolean para 0/1, Double con multiplicador, Long para u32, String hex para ICCID/iButton, GeoJSONPoint para 387), en lugar de inferirlo en runtime de `min/max/type` como hace B (`GetAttributeType`, siempre `NUMBER` o `TEXT`).

### 4.3 Conversión crudo → tipado en A
- `parameter/ParameterParser<T>`: `T parse(ByteBuf, int length)`; `getExpectedLength()`.
- `parameter/StandardParsers` (320 l.): `BOOLEAN`, `UNSIGNED_BYTE`, `SIGNED_BYTE`, `UNSIGNED_SHORT`, `SIGNED_SHORT`, `SIGNED_INT`, `UNSIGNED_INT`(→Long), `SIGNED_LONG`, `FLOAT`, `DOUBLE`, `unsignedShortWithMultiplier(m)`, `signedShortWithMultiplier(m)`, `unsignedIntWithMultiplier(m)`, `fixedLengthString(n)`, `VARIABLE_STRING`, `HEX_LONG` (`%016X`), `HEX_BYTES`, y fallbacks `inferFromLength(len)` (1→u8, 2→u16, 4→u32, 8→i64, otro→hex) e `inferTypeFromLength`.
  - Multiplicadores usados en las 641 defs: 0.001, 0.01, 0.1, 10.0 sobre u16/i16/u32.
- `parameter/DynamicParameter` (50 l.): para IDs desconocidos, `fromByteLength(prefix, id, len)` crea `teltonika_<id>` con tipo inferido. Garantiza "no perder datos".
- `teltonika/TeltonikaRegistry` (128 l.): singleton; **por reflexión** recorre los `static final TeltonikaParameter` de `TeltonikaParameters` y construye `parametersById` y `parametersByFullName`; `getOrCreateDynamic`; `findMatchingAttributeDescriptor(assetClass, valueDescriptor)` busca en `TeltonikaTrackerAsset` un `AttributeDescriptor` cuyo value type tenga el mismo nombre (`teltonika_66`) → así `66` se guarda como atributo `externalVoltage`.
- `teltonika/TeltonikaAttributeResolver` (299 l.):
  - `resolveBinaryIo(id, ByteBuf, ts)`: busca descriptor (`findDescriptor` acepta "66", "teltonika_66"); si la longitud no coincide con `byteLength` → warning y valor crudo; si existe → `parseKnownBinary` → `createKnownTypedAttribute`: (1) si `TeltonikaTrackerAsset` tiene descriptor equivalente, lo usa (nombre fijo p.ej. `ignition`, `totalOdometer`); (2) si no, nombre = camelCase del `displayName` (`toAttributeName("Dallas Temperature 1")` → `dallasTemperature1`) con tipo según clase Java; (3) desconocido → `teltonika_<id>` (`createRawAttribute`: 1B→INTEGER, 2B→INTEGER, 4B→LONG, 8B→LONG, otro→TEXT hex).
  - `resolveJson(id, Object, ts)`: idem pero convirtiendo primero el valor JSON a `ByteBuf` del tamaño del parámetro (`convertJsonValueToBinary`): Boolean admite `true/1/"1"`; para longitud ≤0 se escribe el string UTF-8 (así funcionan `latlng`, `ts`, `387`).
- `teltonika/TeltonikaAssetMapper` (89 l.): `createAsset(imei, realm)` → `TeltonikaTrackerAsset` con id determinista `UniqueIdentifierGenerator.generateId(imei)`, nombre "Teltonika Device <imei>", manufacturer "Teltonika"; `applyAttributes` añade/reemplaza atributos y devuelve los nuevos; `preProcessAttributes` convierte `gpsLocation` → `Asset.LOCATION` y `teltonika_ts` → `timestamp`.
- `manager/telematics/TeltonikaMessageHandler.applyMessage` (l. 60-71): `assetStorageService.merge(asset)` para persistir los atributos nuevos (descubrimiento automático) y `assetProcessingService.sendAttributeEvent` para los existentes (así pasan por reglas, datapoints, etc.).

**Pérdida en A**: unidades y min/max del parámetro NO se trasladan como meta al atributo generado por `createFallbackTypedAttribute` (sólo tipo). Sólo los parámetros con `units()` en el builder llevan `ValueDescriptor.units` (y `TeltonikaParameter.units` está `@JsonIgnore`). En `TeltonikaTrackerAsset` tampoco hay `withUnits`. Así que la UI mostrará `externalVoltage = 11.921` sin "V" salvo que el ValueDescriptor lo exponga (lo hace vía `super.units = builder.valueUnits`, l. 48-50 del parámetro → sí se serializa en el ValueDescriptor del modelo; en la práctica funciona para los ~200 params con `units`).

### 4.4 Conversión en B (`TeltonikaDataPayload.getAttributes`, l. 148-370)
Por cada clave del `reported`:
1. `latlng` → `Asset.LOCATION` (regex lat/lon, descarta `0.000000,0.000000`); `ts` → `VehicleAsset.LAST_CONTACT` y pone ese timestamp a todos los atributos; `ang` → `VehicleAsset.DIRECTION`.
2. Si el `CarAsset` tiene un `AttributeDescriptor` con ese nombre (`"239"`, `"240"`, `"16"`, `"sp"`, …) se usa ese descriptor (coerción al tipo).
3. Si no: tipo = `NUMBER` si `min`/`max` parsean como número o `type ∈ {Unsigned, Signed, UNSIGNED LONG INT}`, si no `TEXT`; valor × `multiplier` (si ≠ "-"); meta `LABEL = propertyName`, `CONSTRAINTS = [Min(min×mult), Max(max×mult)]` (sólo si el valor cae dentro; hay un TODO porque no se aplican bien), `UNITS` (comentado por un error de despliegue); a todos: `STORE_DATA_POINTS`, `RULE_STATE`, `READ_ONLY`.
4. **El nombre del atributo es el propio AVL ID** ("66"), y el `LABEL` el nombre humano. Desconocidos → `TeltonikaParameter(-2, id, …, "UNKNOWN")`.

### 4.5 Configuración por modelo (sólo B)
- `TeltonikaModelConfigurationAsset`: atributos `modelNumber`, `TeltonikaParameterData` (array editable), `TeltonikaParameterMap` (mapa id→param, read-only, regenerado al editar el array por `handleAssetConfigurationChange`). Icono `switch`.
- `TeltonikaConfigurationAsset` (padre, realm `master`): `Enabled`, `CheckForValidIMEI`, `StorePayloads`, `defaultModelNumber` ("FMC003"), `command` ("sendToDevice"), `response` ("response"), `deviceIMEIWhitelist`.
- `TeltonikaConfigurationFactory.initializeConfigurationAssets` crea ambos al arrancar si no existen, leyendo `/deployment/manager/fleet/FMC003.json` (`./deployment/...` en dev).
- Cada `VehicleAsset` tiene `modelNumber`; `onPublish` lo lee para elegir el mapa… pero `TeltonikaConfiguration.getModelParameterMap(modelNumber)` (l. 150) ignora el argumento y `TeltonikaDataPayload` hace `.get(config.getDefaultModelNumber())` → **en la práctica siempre se usa el modelo por defecto**. La idea está, la implementación no la completa.

---

## 5. Sesiones y diseño multi-vendor (A)

- `session/DeviceConnection` (107 l.): estado runtime por dispositivo: `vendorId, imei, realm, assetId, protocolId ("teltonika:tcp:avl" | "teltonika:udp:avl" | "teltonika:mqtt:json"), codecId ("codec_8", "codec_8e", "json"…), transport (TCP/UDP/MQTT/HTTP), connected, lastContact, messageCount, connectionCount`.
- `TelematicsService` (298 l.): `Map<"vendor:imei", DeviceConnection>`; `markTrackerConnected/Disconnected`, `submitMessage(vendorId, realm, transport, codecId, DeviceMessage)` → envuelve en `TelematicsMessageEnvelope` y lo envía a la cola Camel SEDA (`seda://telematics-device-messages?size=10000&concurrentConsumers=4&blockWhenFull=true`); la ruta `Telematics-ProcessDeviceMessage` llama al `TelematicsMessageHandler` del vendor y guarda el `assetId` en la conexión. Errores → log y descarte. Consultas: `getTrackerStates()`, `getTrackerStatesByVendor`, `isDeviceConnected`, `getConnectedDeviceCount`. **Todo en memoria, no persistido ni expuesto por REST/UI todavía.**
- `session/DeviceSession` + `DeviceSessionManager` (interfaces): estados `CONNECTING/CONNECTED/IDLE/DISCONNECTED`, `queueCommand`, `cleanupTimedOut`, listeners `onSessionCreated/Message/Removed`. Implementadas en `TeltonikaVendor.TeltonikaSessionManager/TeltonikaSession` pero **nadie las invoca** (el flujo real usa `DeviceConnection`); son el esqueleto de la fase siguiente.
- Multi-vendor: `TelematicsVendor` (id, nombre, `DeviceProtocol`, `TelematicsParameterRegistry`, `TelematicsAssetMapper`, `DeviceSessionManager`, `CommandMapper`, transports) + `DeviceProtocol` (`canHandle`, `decode`, `encodeCommand`, `getAcknowledgment`, `handleIdentification`). Los Javadoc mencionan explícitamente "Teltonika, Queclink, etc." (`ParameterRegistry.java` l. 14, `DeviceProtocol.java` l. 19). Sólo existe Teltonika; el registro es `telematicsService.registerVendor(TeltonikaVendor.getInstance())` en `init()`. Preparado, no implementado para otros.

---

## 6. Asset del tracker

**A — `TeltonikaTrackerAsset`** (`model/telematics/teltonika/TeltonikaTrackerAsset.java`, icono `crosshairs-gps`, color `00a0ff`), todos opcionales salvo identificación:

| Grupo | Atributos (nombre → parámetro) |
|---|---|
| Trama | `priority`(pr), `altitude`(alt), `direction`(ang), `satellites`(sat), `speedSatellite`(sp), `eventTriggered`(evt), `timestamp`, `gpsLocation`(latlng → se mueve a `location` estándar), `speed`(24) |
| Digitales | `digitalInput1`(1), `digitalOutput1`(179), `ignition`(239), `movement`(240), `instantMovement`(303) |
| Analógicas | `analogInput1`(9), `externalVoltage`(66), `batteryVoltage`(67), `batteryCurrent`(68) |
| SIM/red | `iccid1`(11), `iccid2`(14), `gsmSignal`(21), `gsmAreaCode`(206), `networkType`(237), `activeGsmOperator`(241), `umtsLteCellId`(636) |
| Combustible/eco | `fuelUsedGps`(12), `fuelRateGps`(13), `ecoScore`(15) |
| Odómetro/viaje | `totalOdometer`(16), `tripOdometer`(199), `trip`(250) |
| Acelerómetro | `axisX`(17), `axisY`(18), `axisZ`(19) |
| Estado | `batteryLevel`(113), `sleepMode`(200), `dataMode`(80), `btStatus`(263), `gnssStatus`(69), `gnssPdop`(181), `gnssHdop`(182) |
| Identidad | `userId`(238), `iButton`(78), `ignitionOnCounter`(449), `iso6709Coordinates`(387) |
| Dispositivo | `imei`, `manufacturer`, `model`, `protocol` (TCP/UDP/MQTT), `codec`, `attributeDescriptors` (array de ValueDescriptor para dinámicos) |

Cualquier otro AVL conocido se crea con nombre camelCase (`dallasTemperature1`, `obdOemTotalMileage`…) y los desconocidos como `teltonika_<id>`.

**B — `VehicleAsset`/`CarAsset`**: `location`, `IMEI`, `lastContact`, `modelNumber`, `direction`, `color`, `modelYear`, `licensePlate`, `239` (Ignition status, Boolean), `240`, `16` (odometer, m), `evt`, `alt`, `sat`, `sp` (km/h), `pr`, más `sendToDevice`, `response`, `ClientId`, `payload` (si `StorePayloads`), `LastTripStartedAndEndedAt` (`AssetStateDuration` calculado al pasar `250` de 1→0 con >10 s de duración; usa el histórico de datapoints para hallar el inicio).
**`State.java`** (B): simple `Map<String,Object> reported` del JSON Teltonika.

---

## 7. Propuesta de porte a Talentus Pro Tracking (Laravel 9 + Traccar)

Lo que vale la pena portar, en orden: (a) catálogo de parámetros AVL con auto-creación de sensores; (b) mapeo por modelo; (c) ingesta MQTT opcional; (d) comandos. El decoder binario **no** (Traccar ya lo hace).

### 7.0 Qué entrega hoy Traccar y cómo encaja
Traccar expone en `tc_positions.attributes` (JSON) para Teltonika: claves normalizadas (`ignition`, `power`, `battery`, `odometer`, `rssi`, `sat`, `motion`, `operator`, `pdop`, `hdop`, `driverUniqueId`, `in1..`, `adc1`, `temp1..`, `out1..`, `event`, `priority`…) **y** `io<ID>` para todo lo que su tabla no conoce. Es decir, en Talentus el `tag_name` del sensor es hoy `io72`, `io66`, `power`, etc. El catálogo permite, dado un `io<ID>` desconocido, saber nombre, unidad, multiplicador, rango y descripción y crear el `DeviceSensor` correcto automáticamente.

Cuidado con dobles multiplicadores: para los IDs que Traccar ya normaliza aplica su propia conversión (p.ej. `power` ya está en V, `odometer` en m, `temp1` en °C). La tabla debe tener una columna `traccar_key` para esos casos y `apply_multiplier=false` cuando Traccar ya lo aplicó.

### 7.1 Esquema de tablas (migraciones Laravel)

```sql
-- Catálogo de parámetros (origen: FMC003.json / TeltonikaParameters.java / wiki)
CREATE TABLE teltonika_parameters (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  avl_id          INT UNSIGNED NOT NULL,            -- 66
  name            VARCHAR(120) NOT NULL,            -- "External Voltage"
  slug            VARCHAR(120) NOT NULL,            -- "external_voltage"
  bytes           VARCHAR(16)  NULL,                -- "2" | "Variable"
  data_type       ENUM('unsigned','signed','hex','ascii','boolean','unknown') DEFAULT 'unsigned',
  min_value       DECIMAL(20,4) NULL,
  max_value       DECIMAL(20,4) NULL,
  multiplier      DECIMAL(12,6) NULL,               -- 0.001 ; NULL = 1
  units           VARCHAR(16)  NULL,                -- "V","°C","km/h","%","m"
  description     TEXT NULL,
  parameter_group VARCHAR(64)  NULL,                -- "Permanent I/O elements"
  hw_support      TEXT NULL,                        -- "FMBXXX FMB920 FMC150 ..."
  traccar_key     VARCHAR(40)  NULL,                -- "power" si Traccar ya lo normaliza; NULL => "io66"
  traccar_applies_multiplier TINYINT(1) DEFAULT 0,
  sensor_type     VARCHAR(40)  NULL,                -- tipo Tobuli sugerido: ignition|battery|fuel_tank|temperature|odometer|numerical|logical|...
  enum_values     JSON NULL,                        -- {"0":"Home On Stop","1":"Home On Moving",...} parseado de description
  source          VARCHAR(32) DEFAULT 'openremote-fmc003',
  UNIQUE KEY uq_avl (avl_id)
);

-- Modelos de dispositivo y qué parámetros soporta cada uno (derivado de hw_support o curado a mano)
CREATE TABLE teltonika_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(16) NOT NULL UNIQUE,                 -- "FMB920","FMC150","FMC003"
  family VARCHAR(16) NULL,                           -- "FMB9","FMC1","FMC0"
  default_codec VARCHAR(8) DEFAULT '8E'
);
CREATE TABLE teltonika_model_parameters (
  model_id INT UNSIGNED NOT NULL,
  parameter_id INT UNSIGNED NOT NULL,
  name_override VARCHAR(120) NULL,                   -- p.ej. en FMC150 el AVL 9 es "Analog Input 1" pero se cablea a "Nivel combustible"
  multiplier_override DECIMAL(12,6) NULL,
  units_override VARCHAR(16) NULL,
  auto_create TINYINT(1) DEFAULT 1,                  -- si al detectar este io crear sensor automáticamente
  PRIMARY KEY (model_id, parameter_id)
);

-- Enlazar dispositivo con modelo (Traccar ya guarda tc_devices.model si el equipo lo reporta; en Tobuli: devices.device_model)
ALTER TABLE devices ADD COLUMN teltonika_model_id INT UNSIGNED NULL;

-- Registro de IO vistos por dispositivo (para la UI "sensores detectados")
CREATE TABLE device_detected_ios (
  device_id INT UNSIGNED NOT NULL,
  tag_name VARCHAR(40) NOT NULL,                     -- "io72" | "power"
  avl_id INT UNSIGNED NULL,
  last_raw_value VARCHAR(64) NULL,
  first_seen_at DATETIME, last_seen_at DATETIME,
  sensor_id INT UNSIGNED NULL,                       -- device_sensors.id si ya se creó
  PRIMARY KEY (device_id, tag_name)
);

-- (opcional, para c/d) estado de conexión tipo DeviceConnection de OpenRemote
CREATE TABLE device_connections (
  device_id INT UNSIGNED PRIMARY KEY,
  transport ENUM('TCP','UDP','MQTT') NULL, codec VARCHAR(16) NULL,
  connected TINYINT(1) DEFAULT 0, last_contact DATETIME NULL,
  message_count BIGINT DEFAULT 0, connection_count INT DEFAULT 0, client_id VARCHAR(64) NULL
);
```

### 7.2 Carga del catálogo (seeder)
Fuente recomendada: **`FMC003.json` de B** (formato dato, 555 params) y completarlo con los 80 IDs extra de A exportándolos de `TeltonikaParameters.java` con un script (regex sobre `.id(n)…`, como el usado en este análisis). Alternativa: scrapear la wiki (tablas `wikitable` de `https://wiki.teltonika-gps.com/view/<MODELO>_Teltonika_Data_Sending_Parameters_ID`) con una `artisan command` que recorra los modelos que usa Talentus (FMB920, FMB130, FMC150, FMC003, FMC130, FMB140, FMC920…) y rellene `teltonika_model_parameters`. Mantener `source` para trazabilidad.

```php
// database/seeders/TeltonikaParametersSeeder.php (pseudocódigo)
foreach (json_decode(file_get_contents(storage_path('teltonika/FMC003.json')), true) as $p) {
    $mult = $p['multiplier'] === '-' ? null : (float)$p['multiplier'];
    $type = match(strtolower($p['type'])) {
        'unsigned','unsigned long int' => ($p['min']==='0' && $p['max']==='1') ? 'boolean' : 'unsigned',
        'signed' => 'signed', 'hex' => 'hex', 'ascii' => 'ascii', default => 'unknown' };
    TeltonikaParameter::updateOrCreate(['avl_id' => $p['propertyIdInAvlPacket']], [
        'name' => $p['propertyName'], 'slug' => Str::snake($p['propertyName']),
        'bytes' => $p['bytes'], 'data_type' => $type,
        'min_value' => is_numeric($p['min']) ? $p['min'] : null,
        'max_value' => is_numeric($p['max']) ? $p['max'] : null,
        'multiplier' => $mult, 'units' => in_array($p['units'], ['-', null]) ? null : $p['units'],
        'description' => $p['description'], 'parameter_group' => $p['parameterGroup'],
        'hw_support' => $p['hwSupport'],
        'enum_values' => TeltonikaEnumParser::fromDescription($p['description']), // "0 – X 1 – Y" → {0:X,1:Y}
        'traccar_key' => TraccarKeyMap::for($p['propertyIdInAvlPacket']),         // 66=>'power',67=>'battery',16=>'odometer',239=>'ignition',240=>'motion',21=>'rssi',24=>'speed',78=>'driverUniqueId',72=>'temp1',1=>'in1',9=>'adc1',179=>'out1',181=>'pdop',182=>'hdop',199=>'tripOdometer',200=>'sleepMode'(?)...
        'traccar_applies_multiplier' => in_array($p['propertyIdInAvlPacket'], [66,67,68,9,72,73,74,75,16,199]),
        'sensor_type' => TobuliTypeMap::for($p),   // 239=>'ignition', 66=>'battery'(voltaje ext), 67=>'battery', 72..75=>'temperature', 16=>'odometer', 9=>'fuel_tank'|'numerical', boolean=>'logical', default 'numerical'
    ]);
}
```
El mapa `TraccarKeyMap` se saca directamente de `org.traccar.protocol.TeltonikaProtocolDecoder#decodeOtherParameter` (es la única parte de Traccar a mirar), y debe revisarse en cada actualización de Traccar.

### 7.3 Servicio de auto-detección y auto-creación de sensores (encaje con `Tobuli\Sensors` / `DeviceSensor`)

En GPSWox/Tobuli un sensor (`device_sensors`) tiene esencialmente: `device_id, user_id, name, type, tag_name, add_to_history, on_value/off_value, shown_value_by, formula, min_value, max_value, odometer_value_unit, temperature_max/min, calibrations (JSON), full_tank_value…`, y se instancia vía `Tobuli\Sensors\SensorsManager` / clases `Tobuli\Sensors\Types\{Ignition,FuelTank,Temperature,Odometer,Battery,Numerical,Logical,...}`. (Verificar nombres exactos en el código de Talentus; aquí se asumen los de GPSWox.)

```php
// app/Services/Teltonika/IoDiscoveryService.php
class IoDiscoveryService
{
    public function scan(Device $device, array $positionAttributes): array   // $positionAttributes = json_decode(tc_positions.attributes)
    {
        $existing = $device->sensors()->pluck('tag_name')->all();
        $suggestions = [];
        foreach ($positionAttributes as $key => $raw) {
            if (in_array($key, $existing)) continue;
            $param = $this->resolve($key);                 // io72 → avl 72 ; power → avl 66 (por traccar_key)
            if (!$param) { $suggestions[] = ['tag'=>$key,'known'=>false,'raw'=>$raw]; continue; }
            $value = $param->traccar_applies_multiplier || !$param->multiplier ? $raw : $raw * $param->multiplier;
            DeviceDetectedIo::updateOrCreate(['device_id'=>$device->id,'tag_name'=>$key],
                ['avl_id'=>$param->avl_id,'last_raw_value'=>$raw,'last_seen_at'=>now()]);
            $suggestions[] = ['tag'=>$key,'known'=>true,'param'=>$param,'value'=>$value,
                              'label'=>"{$param->name}".($param->units ? " ({$param->units})" : ''),
                              'sensor_type'=>$param->sensor_type, 'enum'=>$param->enum_values];
        }
        return $suggestions;
    }

    public function resolve(string $tag): ?TeltonikaParameter
    {
        if (preg_match('/^io(\d+)$/', $tag, $m)) return TeltonikaParameter::where('avl_id', (int)$m[1])->first();
        return TeltonikaParameter::where('traccar_key', $tag)->first();
    }

    public function createSensor(Device $device, TeltonikaParameter $p, string $tag, ?TeltonikaModelParameter $override = null): DeviceSensor
    {
        $mult = $p->traccar_applies_multiplier ? 1 : ($override?->multiplier_override ?? $p->multiplier ?? 1);
        $data = [
            'device_id' => $device->id, 'user_id' => $device->user_id,
            'name'      => $override?->name_override ?? $p->name,
            'type'      => $override?->sensor_type ?? $p->sensor_type,           // 'numerical','logical','ignition','temperature','battery','odometer','fuel_tank'
            'tag_name'  => $tag,
            'add_to_history' => 1,
            'formula'   => $mult != 1 ? "[value]*{$mult}" : null,                 // GPSWox evalúa formula con [value]
            'shown_value_by' => $p->units ? 'formula' : null,                     // o 'calibration' si el usuario luego calibra
            'min_value' => $p->min_value !== null ? $p->min_value * $mult : null,
            'max_value' => $p->max_value !== null ? $p->max_value * $mult : null,
            'on_value'  => $p->data_type === 'boolean' ? '1' : null,
            'off_value' => $p->data_type === 'boolean' ? '0' : null,
            'odometer_value_unit' => $p->avl_id === 16 ? 'km' : null,
            'parameters' => ['units' => $p->units, 'avl_id' => $p->avl_id, 'description' => $p->description, 'enum' => $p->enum_values],
        ];
        return app(SensorsManager::class)->create($data);   // o DeviceSensor::create + limpiar caché de sensores del device
    }
}
```
Equivalencias con OpenRemote A: `resolve()` ≡ `TeltonikaRegistry.getById` + `findDescriptor`; `createSensor()` ≡ `TeltonikaAttributeResolver.createKnownTypedAttribute` + `TeltonikaAssetMapper.applyAttributes` (sensor = atributo); `DeviceDetectedIo` ≡ `DynamicParameter` ("no perder datos").

Endpoints y UI (Laravel):
```
GET  /api/devices/{id}/teltonika/discover         → scan() sobre la última posición (tc_positions o la tabla positions_x de Tobuli)
POST /api/devices/{id}/teltonika/sensors/auto     body: {tags:["io72","io66",...]}  → createSensor por cada tag
GET  /api/teltonika/parameters?model=FMC150&q=temp → catálogo filtrable (grupo, unidades)
PUT  /api/teltonika/models/{code}/parameters/{avl} → overrides por modelo (name/multiplier/units/auto_create)
GET  /api/devices/{id}/connection                  → device_connections (c)
```
Pantalla "Sensores detectados" en la ficha del dispositivo: tabla tag / nombre / valor actual convertido / unidad / rango / descripción / botón "Crear sensor" y "Crear todos los recomendados" (sólo `parameter_group='Permanent I/O elements'` + `auto_create=1` del modelo). Un job/listener en la ingesta de posiciones (`PositionReceived`) puede llamar a `scan()` con throttling (una vez por hora por dispositivo) para poblar `device_detected_ios` y mostrar un badge "N nuevos IO detectados".

### 7.4 Mapeo por modelo (b)
- Rellenar `teltonika_models` con los modelos de Talentus y `teltonika_model_parameters` cruzando `hw_support LIKE '%FMC150%'` (la columna de la wiki) + ajustes manuales (p.ej. en FMC150 el `io9`/adc1 suele ser nivel de combustible por calibración; `io72` Dallas temperatura; FMB920 sin CAN → excluir grupo ALLCAN300).
- `devices.teltonika_model_id` se fija: manual en la UI, o automático con el modelo que reporta el propio equipo (Traccar guarda `tc_devices.model` cuando el firmware lo envía en `getinfo`/AVL 10xx; en Codec 8E no viene el modelo, así que mejor inferir por prefijo de IMEI/TAC desde una tabla `imei_tac → modelo` propia).
- Al crear sensores automáticos aplicar `name_override/multiplier_override/units_override`. Esto es exactamente lo que `TeltonikaModelConfigurationAsset` pretendía en B y que A no tiene.

### 7.5 Ingesta MQTT Teltonika (c) — opcional
El firmware MQTT de Teltonika (FMB/FMC con "MQTT" en Data Protocol) publica el JSON `state.reported` del §3.2. Dos rutas:

1. **EMQX → Traccar OsmAnd** (recomendada, sin tocar Talentus): regla EMQX en `+/+/teltonika/+/data` → *Bridge HTTP* a `http://traccar:5055/?id={IMEI}&timestamp={ts/1000}&lat=…&lon=…&altitude={alt}&speed={sp/1.852}&bearing={ang}&io66={66}&…`. Hay que mapear `latlng` (split por coma) y pasar los AVL como `ioNN`. Limitación: OsmAnd no distingue tipos y las claves llegan como strings; Traccar las guarda en `attributes` igual que las del binario, así que el servicio §7.3 funciona sin cambios.
2. **EMQX → Laravel** (`POST /api/ingest/teltonika-mqtt` con `imei` del topic + payload): un `TeltonikaMqttPayloadParser` que replica `TeltonikaMqttJsonProtocol.decode`: toma `state.reported`, `ts` como timestamp, `latlng`→lat/lon, `sp/ang/alt/sat/pr/evt`, resto por `IoDiscoveryService::resolve('io'.$k)` aplicando multiplicador, y escribe la posición en la tabla de posiciones de Tobuli (o la reenvía a Traccar por OsmAnd). Si llega `{"RSP":"..."}` → guardar en `device_command_responses`.
Topics: el configurador de Teltonika permite fijar "Data Topic"/"Command Topic"; usar el mismo esquema `talentus/{clientId}/teltonika/{imei}/data|commands` para reutilizar las reglas. Autenticación: el firmware admite usuario/contraseña MQTT (en 2024 OpenRemote decía que no; hoy sí), así que no es necesario permitir anónimos como hace OpenRemote.
`device_connections` se actualiza con los eventos `client.connected/disconnected` de EMQX (webhook) → equivalente a `markTrackerConnected/Disconnected`.

### 7.6 Comandos (d)
- TCP/UDP: seguir con Traccar (`POST /api/commands/send` tipo `custom` → Codec 12). OpenRemote A no aporta nada aquí.
- MQTT: publicar `{"CMD":"setdigout 1 1"}` en `.../commands` (QoS 1) vía cliente MQTT PHP (php-mqtt/client) o la API REST de EMQX (`POST /api/v5/publish`); la respuesta `{"RSP":"..."}` llega al topic `data`. Catálogo de comandos útil (de `DeviceCommand`): `getinfo`, `getver`, `getstatus`, `getgps`, `setdigout <n> <0|1> [timeout]`, `cpureset`, `setparam <id>:<valor>`, `getparam <id>`. Tabla `device_commands(id, device_id, command, transport, sent_at, response, responded_at)`.

### 7.7 Hoja de ruta
1. Migraciones + seeder desde `FMC003.json` (+ merge de los 80 IDs de A). 1-2 días.
2. `IoDiscoveryService` + endpoints + pantalla "Sensores detectados". 3-4 días.
3. Tabla de modelos y overrides; asignación de modelo por dispositivo. 2 días.
4. (Opc.) MQTT con EMQX→OsmAnd. 1-2 días. Directo a Laravel: +3 días.
5. (Opc.) Comandos MQTT y `device_connections`. 2 días.

---

## 8. Licencia y procedencia de los datos

- **Código**: ambos repos son **AGPL-3.0** (cabeceras "GNU Affero General Public License… either version 3" en `CodecUtil.java`, tests, `fleet/LICENSE.txt` "Copyright 2016, OpenRemote Inc."). Portar código Java a PHP de forma literal arrastraría la obligación AGPL (publicar el código de Talentus a los usuarios del servicio). **Recomendación**: no copiar código; reimplementar la lógica (que es trivial: lookup + multiplicador + tipo) a partir de esta descripción. Lo que se quiera reutilizar tal cual es el **dato**, no el código.
- **Datos de parámetros**: `FMC003.json` (B) y la tabla embebida en `TeltonikaParameters.java` (A, "Generated from teltonikaAvlIds.csv") son transcripciones de las tablas públicas de **wiki.teltonika-gps.com** (se ve en `hwSupport` con listas de modelos y restos HTML `[Expand]`, `</br>`, `</span`). Ni A ni B indican licencia específica para esos datos; al ser una compilación de hechos técnicos (ID, bytes, multiplicador, unidad) publicados por el fabricante, lo prudente es **regenerar el catálogo directamente desde la wiki de Teltonika** (atribuyendo la fuente y revisando sus términos de uso) en lugar de embarcar el JSON de OpenRemote; así además se obtienen las tablas específicas por modelo (FMB920, FMC150, FMC130…) que OpenRemote no tiene. El JSON de OpenRemote sirve como *referencia de formato* y para contrastar.
- Las descripciones (`description`) son texto de Teltonika; mantenerlas como referencia interna/UI de soporte es un uso razonable, pero no republicarlas como propias.

---

## Anexo — Referencias rápidas de ficheros

A (`/home/claude/openremote/core-teltonika`):
- `agent/src/main/java/org/openremote/agent/protocol/teltonika/TeltonikaProtocolDecoder.java` (decoder, 650 l.)
- `agent/.../TeltonikaProtocol.java` (254), `TeltonikaAgent.java` (46), `TeltonikaRecord.java` (110), `TeltonikaTcpServer.java`/`TeltonikaUdpServer.java` (23)
- `manager/src/main/java/org/openremote/manager/telematics/TelematicsService.java` (298), `TeltonikaVendor.java` (490), `TeltonikaMQTTHandler.java` (196), `TeltonikaMessageHandler.java` (72), `TelematicsVendor.java` (53)
- `model/src/main/java/org/openremote/model/telematics/teltonika/TeltonikaParameters.java` (5.975; 641 params), `TeltonikaParameter.java` (243), `TeltonikaAttributeResolver.java` (299), `TeltonikaRegistry.java` (128), `TeltonikaTrackerAsset.java` (125), `TeltonikaAssetMapper.java` (89)
- `model/.../telematics/parameter/StandardParsers.java` (320), `ParseableValueDescriptor.java` (145), `DynamicParameter.java` (50)
- `model/.../telematics/session/DeviceConnection.java` (107), `DeviceSession.java` (115), `DeviceSessionManager.java` (127)
- `test/src/test/groovy/org/openremote/test/protocol/mqtt/TeltonikaMQTTClientProtocolTest.groovy` (1.044; vectores hex en l. 983-1030), `test/src/test/groovy/org/openremote/manager/mqtt/TeltonikaMQTTHandlerCommandTest.groovy` (255)

B (`/home/claude/openremote/fleet`):
- `deployment/manager/fleet/FMC003.json` (555 params)
- `manager/src/main/java/telematics/teltonika/TeltonikaMQTTHandler.java` (746), `TeltonikaDataPayload.java` (411), `TeltonikaConfiguration.java` (169), `helpers/TeltonikaConfigurationFactory.java` (114), `TeltonikaResponsePayload.java` (75)
- `model/src/main/java/org/openremote/model/teltonika/TeltonikaParameter.java` (159), `TeltonikaModelConfigurationAsset.java` (87), `TeltonikaConfigurationAsset.java` (71), `State.java` (35), `IMEIValidator.java` (48)
- `model/src/main/java/org/openremote/model/custom/VehicleAsset.java`, `CarAsset.java`, `CustomValueTypes.java`, `AssetStateDuration.java`
- `test/src/test/groovy/org/openremote/test/custom/TeltonikaMQTTProtocolTest.groovy` (10 casos), `test/src/test/resources/teltonika/teltonikaValidPayload1.json`, `SortedPayloads.json` (762 payloads)
