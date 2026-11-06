# Plan — Integración Securitas GlobalAVL (Inbound Event Messaging v2.0)

> Fuente: `Developer Guide for Inbound Event Messaging v2.0_EN.pdf` (Securitas, rev. 2.0.0 del 22-abr-2025).
> Análisis: 4-nov-2026. Respuestas de Securitas recibidas el 6-nov-2026 (§2.1).
> Estado: **faltan identificador de device y credenciales** (§4). Datos a enviar: decididos (§3.2).

---

## 1. Qué pide el documento

Integración **de salida**: Talentus es el proveedor GPS y **envía** las posiciones a GlobalAVL
(Securitas). Misma familia que UNIGIS / Wisetrack / Sidersales.

| Aspecto | Especificación |
|---|---|
| Transporte | `POST https://{client-domain}/gateway/lipv10` (HTTPS) |
| Autenticación | Cabecera `X-API-Key: <token estático>` (lo entrega el proveedor) |
| Content-Type | `application/json` |
| Cuerpo | `{"version": "...", "events": [ ... ]}` — **admite varios eventos por request** |
| Respuesta | `200` = éxito; cualquier otro código = fallo. **No hay resultado por evento** |
| Frecuencia | "Cada posición recibida del equipo debe informarse" → envío continuo, no solo alarmas |

### Evento

| Campo | Tipo | Req. | Notas |
|---|---|---|---|
| `device` | string | Sí | Identificador numérico (el ejemplo `100000000000002` parece un IMEI) |
| `type` | number | Sí | Id de evento (§1.1). `> 1000` = personalizado, debe existir en su organización |
| `created` | datetime | Sí | ISO 8601 UTC con ms (`2025-04-23T14:18:03.417Z`) |
| `value` | string | No | Info extra |
| `location` | object | No | `latitude`, `longitude` (WGS84), `altitude` (m), `course` (°), `speed` (**km/h**), `satellites`, `precision`, `type`, `valid`. Rellenar con 0 si no hay fix |
| `status` | object | No | `ignition` (**1 = ON, 2 = OFF**), `battery` (%) |
| `infos[]` | `{type, value}` | No | value entero |
| `inputs` | object | No | `digital[]`, `analog[]`, `canbus[]`, `serial[]`, cada uno `{index, type, value}`; `index` 0 salvo varios del mismo tipo |

### 1.1 Catálogos

- **Eventos:** 0 Undefined (posición periódica), 1 Enter Area, 2 Leave Area, 11/21 Panic ON/OFF,
  13/23 Ignition ON/OFF, 47/48 temperatura dentro/fuera de rango, 55/56 Power On/Off,
  57 Main Power Low, 59/60 Main Power Lost/Restored, 64/65 Battery Connected/Disconnected,
  67 Battery Low, 187/188 Harness ON/OFF, 93 Falling, 196 Harsh Turn, 266/267 temperatura sobre/bajo
  límite, 270 Fuel Theft, 271 Fuel Load, 508/509 exceso de RPM inicio/fin, 512 Harsh braking,
  513 Harsh acceleration, 514/515 Overspeeding start/end, 516/517 Harsh Turn Left/Right.
- **Digital (bool):** 1 Tow, 10 Door, 11 Panic, 12 Engine Running, 13 Ignition, 207 Fuel Cap,
  244 Anti-Theft, 187 Harness, 199 Jamming, 506 Lock (true = cerrado), 213 Tamper, 197 Blinkers,
  251/253/255/194 puertas conductor/pasajero/portón/carga, 500 Hood. Puertas: true = abierta.
- **Analog (double):** 1 Temperatura °C, 2 Combustible %, 3 Batería principal **mV**,
  4 Batería de respaldo **mV**, 7 Humedad %.
- **Canbus (string):** 1 Velocidad km/h, 2 Embrague, 3 Freno, 5 Acelerador %, 6 Consumo total ml,
  7 Combustible %, 8 RPM, 15 Kilometraje total **m**, 17 Temp. motor °C, 18 Consumo ml,
  24 Batería mV, 25 Refrigerante %, 26 Aceite %, 27 Presión de aceite Pa, 47 Cargando.
- **Serial (string):** 2 Driver Id (iButton/tarjeta).
- **Infos (int):** 1 Batería %, 2 Cobertura %, 3 En carga (1/0), 4 Sleep mode (1/0).

## 2. Inconsistencias del documento

1. `version`: los ejemplos dicen `"1.0"`; el ejemplo HTTP y el título dicen `"2.0"`.
2. `valid`: la tabla dice Number (1/0), los ejemplos usan boolean `true`.
3. `ignition`: 1 = ON / **2** = OFF (no 0). Atípico.
4. Eventos 64 y 65 duplicados en la tabla.
5. No dice si `device` es IMEI o un id suyo, ni si el equipo debe estar dado de alta antes.
6. No define tamaño máximo de lote, rate limit, cuerpo de error, idempotencia, ni si acepta
   posiciones atrasadas/desordenadas (buffer del equipo).

### 2.1 Respuestas de Securitas (6-nov-2026) — prevalecen sobre el PDF

| Tema | Respuesta | Consecuencia en el diseño |
|---|---|---|
| `version` | `"2.0"` (el PDF está desactualizado) | Constante `"2.0"` |
| `valid` | boolean JSON (`true`/`false`) | Enviar boolean, no 1/0 |
| `ignition` | `1` = encendida; `0` o `2` = apagada. Va en `status.ignition` **o** como digital `index 0, type 13` (boolean); basta con uno | Enviar solo `status.ignition` (1/0); no duplicar en `inputs.digital` |
| Rate limit | 10 peticiones/s (individuales o en bloque) | Pausa ≥ 100 ms entre POST; un solo proceso de worker en la cola |
| Tamaño de lote | Máx. 300 mensajes por bloque | `BATCH_SIZE = 300` |
| Volumen típico | ~1 mensaje por device cada 30 s | Solo referencia; se envían todas las posiciones (decisión §3.3) |
| Atrasados | Se aceptan | Sin descartar por antigüedad; enviar ordenado por fecha |
| Duplicados | **No deduplican**: lo repetido aparece repetido | Borrar filas en cuanto llega el 200; reintentos mínimos (§3.3) |
| Respuesta | `200` + cuerpo `"OK"`; error = `500` sin detalle (lo están mejorando) | No se distingue error de datos de caída: estrategia §3.3 |
| Áreas (1/2) | Solo si enviamos áreas propias; las suyas las calculan ellos | **No enviar** tipos 1/2 ni nuestras geocercas |
| Inputs | Depende de lo que tengan los equipos y lo que quiera ver el cliente | Definir con el cliente (§4) |

## 3. Diseño propuesto

**Copiar el patrón UNIGIS** (cola en BD → dispatcher programado → job por lote). **No** usar
`Tobuli/Forwards`: envía en línea dentro de `PositionsWriter` con timeout de 5 s y depende del addon
`ENABLE_FORWARDS`; si GlobalAVL se cae frena la escritura de posiciones y pierde datos.

### 3.1 Piezas

| Pieza | Equivalente UNIGIS |
|---|---|
| Migración: columna `devices.securitas` (tinyint) | `2026_10_21_000001_add_unigis_column_to_devices_table` |
| Migración: tabla cola `securitas` — `id`, `device_id`, `type` (evento), `created_at_event` (`dateTime`, UTC), `payload` (text, JSON del evento ya armado), `created_at`/`updated_at`; índices `device_id`, `created_at`. `dateTime`, no `timestamp` (MariaDB 10.5) | `2026_10_21_000002_create_unigis_table` |
| Entidad `Tobuli\Entities\Securitas`; `Device::dataSecuritas()`; `securitas` en `Device::$fillable` | `Unigis`, `Device::dataUnigis()` |
| `Tobuli/Services/Securitas/SecuritasEventMapper` — tipo de evento, `status`, `inputs`, `infos` | `UnigisEventMapper` |
| `PositionsWriter::saveDataSecuritas()` — arma el JSON del evento y lo encola (try/catch, log en canal propio) | `saveDataUnigis()` |
| `App\Jobs\DispatchSecuritasJobs` — cada minuto, por gestor con servicio activo, lotes por rango de id, backpressure por tamaño de cola | `DispatchUnigisJobs` |
| `App\Jobs\SendDataSecuritasJob` — un POST con N eventos (Guzzle, `X-API-Key`) | `SendDataUnigisJob` |
| Config por gestor: `services[securitas][active, logs, url, api_key]` en `Admin/Clients/edit.blade.php` (api_key como password, vacío = no cambiar) | bloque `unigis` |
| Permiso `webservice.securitas` (`config/permissions.php`) + migración que lo concede a admins | `webservice.unigis` |
| Checkbox del device en `Devices/partials/webservices.blade.php`, `DeviceFormValidator`, `DeviceModalHelper`, `Ui\V2\DevicesController` | `unigis` |
| Admin `Objects/index` + `table`, `ObjectsController`, `ClientsController` | `unigis` |
| `IntegrationStatusService`, canal `securitas` en `config/logging.php`, `LogService`, enum `WebServices` | `unigis` |
| Cola `web-services-securitas` en `laravel-worker-webservices.ini`; `$schedule->job(new DispatchSecuritasJobs)->everyMinute()->withoutOverlapping()` en `Kernel` | UNIGIS |
| Comando `securitas:test` (envía un evento de prueba y muestra la respuesta) | `UnigisTestCommand` |
| Textos en `resources/lang/es/validation.php` | UNIGIS |

### 3.2 Mapeo Teltonika/Traccar → GlobalAVL (verificar con device 219)

| Origen | Destino |
|---|---|
| `device_time` (UTC) | `created` (`Y-m-d\TH:i:s.v\Z`) |
| `$device->imei` (a confirmar) | `device` |
| lat/lng/altitude/course/speed (km/h), `sat`, `valid` | `location` |
| event 239 / 1 + `ignition` | type 13 / 23; `status.ignition` 1/0 (no digital 13) |
| io253 = 1 / 2 / 3 | type 513 / 512 / 196 (no hay lado del giro → no 516/517) |
| io255 (overspeeding) | type 514 / 515 |
| pánico / SOS (según cableado) | type 11 / 21; digital 11 |
| io252 (unplug) | type 59 / 60 |
| `power` (V) × 1000 | analog 3 (mV) |
| `battery` (V) × 1000 | analog 4 (mV) |
| sensor de temperatura / combustible del device (`DeviceSensor`) | analog 1 / analog 2 |
| `totaldistance` (m) | canbus 15 |
| `driverUniqueId` (io78 iButton) | serial 2 |
| io21 (GSM 0–5) → % | info 2 |
| io200 (sleep) | info 4 |
| resto | type 0 |

**Datos a enviar (decidido 6-nov-2026):** los esenciales, igual que en las demás integraciones
(Wisetrack: ubicación, rumbo, velocidad, validez, ignición, evento, odómetro). Cada campo se incluye
**solo si la trama lo trae**; nunca se inventan valores ni se rellenan con 0.

| Siempre | Si la trama lo trae |
|---|---|
| `device`, `type`, `created` | `status.battery` / `infos` 1 (`batterylevel` %) |
| `location`: lat, lng, altitude, course, speed, satellites (`sat`), valid | `analog` 3 — alimentación principal (`power` V × 1000) |
| `status.ignition` (1/0, de `ignition`) | `analog` 4 — batería de respaldo (`battery` V × 1000) |
| | `canbus` 15 — odómetro (`totaldistance`, m) |
| | `serial` 2 — conductor (`driverUniqueId`) |

Temperatura, combustible, puertas, CAN avanzado: **fuera de alcance** por ahora; se añaden al mapper
si un cliente lo pide. Omitir `inputs`/`infos` vacíos.

### 3.3 Envío y reintentos

Securitas no deduplica y ante cualquier error responde `500` sin detalle, así que no se puede
distinguir "dato mal formado" de "servidor caído". Reglas:

- Lote de hasta **300** eventos, ordenado por `created_at_event, id`; pausa ≥ **100 ms** entre POST.
  Un solo proceso de worker en `web-services-securitas` para no superar 10 peticiones/s.
- `200` → borrar **en el acto** las filas enviadas (antes de seguir con otro lote), para que un
  timeout del job posterior no las reenvíe.
- `500` / timeout / error de red → conservar filas y reintentar el lote (`tries = 3`, `backoff`).
  Si el mismo lote agota los reintentos y el servicio responde a otras peticiones, partirlo en
  mitades (bisección) para aislar el evento que falla; ese evento se loguea con su JSON y se descarta.
- `401/403` → log de credenciales (API key enmascarada), no borrar, no bisecar.
- Timeout de Guzzle corto (≈ 15 s) y `$timeout` del job holgado para no matar el job a mitad de un POST.
- **Riesgo de duplicados:** si un `500` llega después de que guardaron parte del lote, el reintento
  duplicará eventos. Preguntar si el bloque es atómico (§4.3).
- No se submuestrean posiciones: se envía cada posición recibida (lo pide el PDF). Si el volumen
  supera el rate limit, añadir `services[securitas][min_interval]` (segundos) para filtrar
  posiciones periódicas (type 0) de un mismo device; los eventos se envían siempre.

## 4. Pendientes

Respondidas el 6-nov-2026 (§2.1): versión, `valid`, `ignition`, rate limit, lote, atrasados,
duplicados, respuesta de error y áreas.

**Siguen abiertas con Securitas:**

1. `client-domain` y API key de **pruebas** y **producción**.
2. ¿`device` es el IMEI? ¿Hay que dar de alta los equipos antes en GlobalAVL?
3. Si un bloque devuelve `500`, ¿se descarta entero o pudo guardarse una parte? (define si
   reintentar el bloque duplica eventos).
4. ¿Necesitan tipos personalizados (> 1000)?

~~5. Datos a enviar~~ — decidido 6-nov-2026: los esenciales que traiga la trama (§3.2).

## 5. Esfuerzo

Similar a UNIGIS: ~1–2 días incluidas pruebas contra su entorno TEST. Más simple (REST/JSON, no
SOAP); la parte variable es el armado de `inputs` según los sensores de cada device.

## 6. Pasos

1. ~~Enviar preguntas a Securitas~~ (respondidas 6-nov-2026). Pedir lo abierto en §4.1–4.4.
   Se puede avanzar con 2–5 mientras tanto.
2. Migraciones + entidad + flag en device + permiso.
3. Mapper + `saveDataSecuritas()` en `PositionsWriter`.
4. Jobs + Kernel + worker + canal de log.
5. UI: config del gestor, checkbox del device, admin, `IntegrationStatusService`.
6. `securitas:test` contra TEST; validar mapeo con posiciones reales del device 219.
7. Producción: URL/API key reales, activar gestores y devices.
