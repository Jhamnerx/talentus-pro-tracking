# Plan de implementación — Integración UNIGIS (Pacasmayo / Geojavar)

Fecha: 20-oct-2026. Especificación: "UNIGIS Documento Especificacion Integracion Prestadores AVL GPS v4.1".

## 1. Decisiones tomadas

| Tema | Decisión |
| --- | --- |
| Protocolo | SOAP 1.1, método `LoginYInsertarEvento` (sin sesión), `NroSerie = -1`. Namespace `http://unisolutions.com.ar/`. |
| Volumen en movimiento | Se reenvían **todas** las posiciones recibidas del equipo (6 a 15 s). |
| Vehículo detenido | **Sin heartbeat** por ahora. Se reenvía solo lo que manda el equipo (cada 5 min). Se agrega si UNIGIS lo exige. |
| Credenciales | **Por cliente**, en Admin > Clientes > bloque UNIGIS (`services['unigis']` del usuario gestor), igual que WiseTrack. |
| Rechazos (retorno < 0) | Se **descarta** la fila y se registra en `storage/logs/unigis.log` con código y respuesta. |
| Retorno 0 (vehículo deshabilitado en UNIGIS) | Se descarta y se registra como aviso. |
| Fallo de red / SoapFault | La fila **se conserva** y se reintenta en el siguiente ciclo. |
| Fechas | `FechaHoraEvento` = `device_time` (hora del GPS). `FechaHoraRecepcion` = `server_time` (hora en que Traccar recibió la trama). Ambas **UTC**, formato `Y-m-d\TH:i:s`, sin offset. |
| Placa (Dominio) | Se normaliza al enviar: mayúsculas, sin espacios ni guiones (`BEP-887` → `BEP887`). |
| Eventos no mapeados | Cualquier `event` Teltonika que no esté en la tabla se envía como `POS`. |

Confirmado por UNIGIS (Luis Fletes, Integraciones GPS, 21-oct-2026): el intervalo de 5 minutos con vehículo detenido es aceptable (**no se implementa heartbeat**), **no** se usará el flujo de comandos (`ObtenerComando`, `EstadoComando`, `ComandoProcesado`) y **no** se requiere envío de sensores (`LoginYInsertarEvento2`). El único método a implementar es `LoginYInsertarEvento`. UNIGIS queda a la espera de nuestro envío de prueba.

## 2. Tabla de eventos (Teltonika → UNIGIS)

| `event` Teltonika | Condición | Código UNIGIS |
| --- | --- | --- |
| 0 | — | `POS` |
| 1 (DIN1) y 239 | `ignition == true` | `IGN_ON` |
| 1 (DIN1) y 239 | `ignition == false` | `IGN_OFF` |
| 240 y 303 | `motion == true` | `MOV_ON` |
| 240 y 303 | `motion == false` | `MOV_OFF` |
| 250 | `io250 == 1` | `TRIP_START` |
| 250 | `io250 == 0` | `TRIP_END` |
| 253 | `io253 == 1` | `HARSH_ACC` |
| 253 | `io253 == 2` | `HARSH_BRAKE` |
| 253 | `io253 == 3` | `HARSH_TURN` |
| cualquier otro | — | `POS` |

La tabla vive en una sola clase (`UnigisEventMapper`) para cambiarla sin tocar el resto.

## 3. Arquitectura (mismo patrón que WiseTrack)

```
Trama GPS → PositionsWriter::saveDataUnigis()  → tabla `unigis` (cola de pendientes)
                                                        ↓ cada minuto (Kernel)
                                           DispatchUnigisJobs → SendDataUnigisJob (cola web-services-unigis)
                                                        ↓ por cada fila
                                           UnigisSoapClient::loginYInsertarEvento() → SOAP UNIGIS
                                                        ↓ retorno >0 / 0 / <0 → borrar fila + log
```

## 4. Archivos a crear

### 4.1 Migraciones (`database/migrations/`, clase anónima, `dateTime` y no `timestamp` por MariaDB 10.5)

- `2026_10_21_000001_add_unigis_column_to_devices_table.php`
  `devices.unigis` boolean default 0, después de `epscenter`.
- `2026_10_21_000002_create_unigis_table.php` — tabla `unigis`:

| Columna | Tipo | Nota |
| --- | --- | --- |
| id | bigIncrements | |
| device_id | unsignedBigInteger, index | |
| dominio | string(20) | placa normalizada |
| codigo | string(20) | código UNIGIS |
| latitud / longitud | decimal(10,7) | |
| altitud | integer | metros, 0 si no hay |
| velocidad | unsignedSmallInteger | km/h |
| rumbo | unsignedSmallInteger | 0-359 |
| fecha_hora_evento | dateTime | UTC, `device_time` |
| fecha_hora_recepcion | dateTime | UTC, `server_time` |
| crudo | text nullable | se envía vacío (solo lo acepta `InsertarEvento`), se guarda para diagnóstico |
| created_at / updated_at | dateTime | índice en `created_at` (lo usa `IntegrationStatusService`) |

### 4.2 Entidad `Tobuli/Entities/Unigis.php`

Copia de `Sidersales`: `$table = 'unigis'`, `$fillable` con las columnas de arriba, `$casts` de fechas, `device()` belongsTo.

### 4.3 `Tobuli/Services/Unigis/UnigisEventMapper.php`

- `public static function codeFor(TraccarPosition $position): string` — aplica la tabla §2 leyendo `getParameter('event')`, `ignition`, `motion`, `io250`, `io253`.
- `public static function catalog(): array` — lista código → descripción (para documentar y para el comando de prueba).

### 4.4 `Tobuli/Services/Unigis/UnigisSoapClient.php`

Envoltorio de `\SoapClient` nativo (extensión `soap` ya activa en PHP).

- `__construct(string $wsdlUrl, string $user, string $password, int $timeout = 15)`
  Opciones: `soap_version => SOAP_1_1`, `trace => true`, `exceptions => true`, `connection_timeout`, `cache_wsdl => WSDL_CACHE_DISK`, `stream_context` con timeout.
- `loginYInsertarEvento(array $row): int`
  Arma el request con el orden exacto del WSDL:
  `SystemUser, Password, Dominio, NroSerie(-1), Codigo, Latitud, Longitud, Altitud, Velocidad, FechaHoraEvento, FechaHoraRecepcion`.
  Devuelve `(int) $response->LoginYInsertarEventoResult`.
- `lastRequest()` / `lastResponse()` — XML crudo para el log cuando hay error.
- Lanza `UnigisTransportException` (nueva, en el mismo namespace) cuando hay `SoapFault` o fallo de conexión, para que el job distinga "no se pudo enviar" de "UNIGIS respondió negativo".

Formato de valores: lat/lng con punto decimal y 7 decimales, fechas `Y-m-d\TH:i:s`, velocidad entera.

### 4.5 Jobs (`app/Jobs/`)

**`DispatchUnigisJobs`** (copia de `DispatchWisetrackJobs`):
1. `UserRepo::getActiveServiceManagers(1, 'unigis')`.
2. Por cada gestor: ids de `$user->devices()->where('unigis', 1)`.
3. Guard anti-duplicado: si ya hay un `SendDataUnigisJob` en vuelo para ese usuario (clave en `Cache` con TTL 120 s, como hace `DispatchSidersalesJobs`), no re-encolar.
4. Despacha `SendDataUnigisJob($service, $deviceIds, $userId)` en lotes de 200 filas.

**`SendDataUnigisJob`** (cola `web-services-unigis`, `tries = 3`, `timeout = 170`):
1. Lee `Unigis::whereIn('device_id', …)->orderBy('fecha_hora_evento')->limit(200)`.
2. Instancia `UnigisSoapClient` con `url`, `user`, `password` del servicio.
3. Por cada fila:
   - retorno `> 0` → borrar fila, log info.
   - retorno `== 0` → borrar fila, log warning "vehículo deshabilitado en UNIGIS".
   - retorno `< 0` → borrar fila, log error con código, dominio, request XML.
   - `UnigisTransportException` → **detener el lote** sin borrar, log error, relanzar para que la cola reintente.
4. `failed()` escribe en `LogService` como WiseTrack.

### 4.6 Comando de consola `app/Console/Commands/UnigisSendTest.php`

`php artisan unigis:test {plate} {--user=} {--url=} {--lat=} {--lng=} {--code=POS}`

Envía un único `LoginYInsertarEvento` con la placa indicada y muestra el request, la respuesta y el entero devuelto. Sirve para la prueba que pide UNIGIS antes de activar nada en producción. Toma usuario/contraseña del cliente (`--user` es el id del gestor) o de las opciones.

## 5. Archivos a modificar

| Archivo | Cambio |
| --- | --- |
| `Tobuli/Entities/Device.php` | `'unigis'` en `$fillable` (junto a `wisetrack`, línea ~178); relación `dataUnigis(): HasMany` junto a `dataWisetrack()` (línea ~2395). |
| `app/Console/PositionsWriter.php` | Tras el bloque `wisetrack` (línea ~730): `if ($this->device->unigis) $this->saveDataUnigis(...)`. Nuevo método `saveDataUnigis($device, $position)` que normaliza placa, calcula código con `UnigisEventMapper` y hace `$device->dataUnigis()->create([...])`. |
| `Tobuli/Entities/User.php` | `getServicesAttribute()`: añadir `'unigis' => ['active'=>0,'logs'=>0,'user'=>null,'password'=>null,'url'=>null]` al array por defecto **y** el `if (!isset($services['unigis']))` para cuentas existentes (línea ~267). |
| `app/Http/Controllers/Admin/ClientsController.php` | `$defaultServices['unigis']` (línea ~471) y, dentro del `foreach`, copiar `user`, `password`, `url` cuando `active` (como el bloque `wisetrack`). La contraseña se guarda tal cual en el JSON `services`, igual que el resto de integraciones. |
| `Tobuli/Views/Admin/Clients/edit.blade.php` | Bloque `<h4>UNIGIS</h4>` después de WISETRACK (línea ~660): checkboxes Activar / Logs y campos Usuario, Contraseña (`Form::password` con placeholder, no se repinta), URL WSDL (valor por defecto la URL TEST). |
| `config/permissions.php` | `'webservice.unigis' => ['view'=>1,'edit'=>1,'remove'=>1]` junto a `webservice.wisetrack` (línea ~382). Sin esto el admin no ofrece el permiso. |
| `ModalHelpers/DeviceModalHelper.php` | `getWebservices()`: `'unigis' => ['label'=>'UNIGIS','perm'=>'webservice.unigis']` (línea ~810). |
| `Tobuli/Views/Frontend/Devices/partials/webservices.blade.php` | Checkbox `unigis` con gate `perm('webservice.unigis','edit')`. |
| `Tobuli/Views/Frontend/Devices/create.blade.php` y `edit.blade.php` | Añadir `perm('webservice.unigis','view')` al `||` de la pestaña Webservices (líneas 23-25). |
| `app/Http/Controllers/Ui/V2/DevicesController.php` | `can_view_webservices`: añadir `webservice.unigis` (línea ~1394). |
| `Tobuli/Views/Admin/Objects/index.blade.php` | Opción `'unigis' => 'UNIGIS'` en `filter_webservices` (línea ~106). |
| `Tobuli/Views/Admin/Objects/table.blade.php` | Etiqueta `UNIGIS` cuando `$item->unigis` (línea ~94). |
| `config/logging.php` | Canal `unigis` (driver daily, `logs/unigis.log`, 3 días) junto a `wisetrack` (línea ~128). |
| `app/Services/LogService.php` | `'unigis' => 'unigis'` en `CHANNEL_MAP`. |
| `Tobuli/Services/IntegrationStatusService.php` | `'unigis' => 'unigis'` en `MAPA` (así aparece en el panel de atraso por integración). |
| `app/Console/Kernel.php` | `use App\Jobs\DispatchUnigisJobs;` y `$schedule->job(new DispatchUnigisJobs)->everyMinute()->withoutOverlapping();` (línea ~196). |
| `laravel-worker-webservices.ini` | Programa `[program:laravel-ws-unigis]` copiando el de wisetrack con `--queue=web-services-unigis`. En producción: `supervisorctl reread && update`. |

## 6. Dónde quedan las credenciales

- **Usuario, contraseña y URL del WSDL**: en la base de datos, columna `users.services` (JSON) del **usuario gestor** del cliente (cuenta de Geojavar, debe ser group_id 1 o 3 para que `getActiveServiceManagers` la encuentre). Se editan en **Admin > Clientes > editar > UNIGIS**.
- Para pasar a producción solo se cambia la URL y las credenciales en ese formulario; no hay nada en `.env`.
- Qué vehículos transmiten: flag `devices.unigis`, marcado desde la ficha del objeto (pestaña Webservices) por un usuario con permiso `webservice.unigis`.
- Logs: `storage/logs/unigis.log` (canal propio) y `webservices_logs` vía `LogService` cuando `services.unigis.logs = 1`.

## 7. Orden de ejecución

1. Migraciones + entidad + `Device` (fillable y relación).
2. `UnigisEventMapper` y `UnigisSoapClient` + excepción.
3. Comando `unigis:test` → **prueba real contra TEST con BEP887** y confirmar a UNIGIS fecha/hora del envío.
4. Credenciales: `User`, `ClientsController`, vista admin de clientes.
5. Flag de dispositivo: permisos, `DeviceModalHelper`, vistas legacy y v2, filtro admin.
6. `PositionsWriter::saveDataUnigis`.
7. Jobs + Kernel + logging + `LogService` + `IntegrationStatusService`.
8. Supervisor ini (producción).

## 8. Verificación manual (no se escriben pruebas automáticas)

- `php artisan migrate` en dev (MySQL 8) y revisar el SQL para MariaDB.
- `php artisan unigis:test BEP887 --user=<id gestor>` contra TEST: retorno `> 0` tras que UNIGIS mapee los códigos; `< 0` si aún no los mapeó (esperado).
- Marcar `unigis` en el device 219 (único con posiciones en la copia de dev, base `gpswox_traccar`), reinyectar posiciones y comprobar filas en `unigis` con `codigo` correcto para eventos 239/240/250/253.
- Correr `php artisan queue:work redis --queue=web-services-unigis --once` y revisar `unigis.log`: filas borradas con retorno positivo, errores conservados con fallo de red.
- Admin > Clientes: guardar y recargar el bloque UNIGIS; Admin > Objetos: filtro y etiqueta.
