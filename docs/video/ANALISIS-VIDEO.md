# Integración de video JT1078 — análisis y diseño

Fecha: 2026-10-28. Fuentes analizadas:
- `E:\Users\jamne\IdeaProjects\traccar` (fork, backend de video)
- `E:\Users\jamne\IdeaProjects\traccar\traccar-web` (UI upstream)
- `https://gps.trackerax.com` (GPSWOX 4.9.0 con módulo de video propio), ingeniería inversa del bundle `app.js`
  y de las páginas HTML. El material crudo está en `.playwright-mcp/` (no versionar).

## 1. Backend de video (fork de traccar)

| Pieza | Detalle |
|---|---|
| Señalización | JT808 (puerto 6015) — comandos por `POST /api/commands/send` con `uniqueId` |
| Media | JT1078 TCP (puerto 6263) → `VideoStreamManager` → HLS (5 segmentos, solo video, H.264/H.265) |
| Streams | `GET /api/stream/imei/{imei}/{canal}/live.m3u8`, `history.m3u8`, `{n}.ts`, `history-{n}.ts` |
| Comandos | `videoStart`/`videoStop` (`index`), `videoPlayback` (`index`,`from`,`to`,`speed`), `videoPlaybackControl` (`action` 0 play,1 pausa,2 stop,5 seek), `videoResourceQuery` (`from`,`to`) |
| Lista de grabaciones | Llega como atributo `videoResources` (JSON) en una posición (respuesta 0x1205) |

traccar-web solo implementa vivo de un canal (`StreamPage.jsx`, hls.js, índice escrito a mano).

## 2. Cómo lo hace TrackeraX (referencia)

### Configuración por device (pestaña principal del modal de edición)
- `has_camera` (checkbox, con `data-disabler="#camera-channels-group;hide-disable"`)
- `camera_channels[]` — checkboxes Canal 1..8
- `camera_channel_roles[N]` — select: `""` (por defecto), `front` (ADAS), `driver` (DMS), `cabin`, `rear`, `left`, `right`, `other`
- Se exponen en el JSON del device para JS: `options().camera_channels` (`[1,2]`) y `options().camera_channel_roles` (`{}`)

### Rutas web (todas con id interno del device salvo proxy/status que usan IMEI)
| Ruta | Método | Uso |
|---|---|---|
| `devices/live_stream_start/{id}` | POST `channel, media_type=1, viewer` | Envía `videoStart` (recuento de espectadores) → `{ok, error}` |
| `devices/live_stream_stop/{id}` | POST `channel, viewer` (también `sendBeacon` en `pagehide`) | Quita espectador; si no quedan, `videoStop` |
| `devices/live_stream_player/{id}?channel=&viewer=` | GET | Página del reproductor (se abre en iframe dentro de un jQuery UI dialog) |
| `devices/live_stream_proxy/{imei}/{ch}/live.m3u8` (+ `.ts`) | GET | Proxy autenticado hacia traccar `/api/stream/imei/...` |
| `devices/live_stream_status/{imei}/{ch}?viewer=` | GET | `{connected, codec, active, last_frame_at, segments, nak, mode}` |
| `devices/live_stream_control/{id}` | POST | Control (calidad HD/SD, audio) |
| `devices/video/recordings_modal/{id}` | GET | Modal de grabaciones (canal, rango, almacenamiento, tipo de flujo, línea de tiempo, tabla) |
| `devices/video/recording_list_start/{id}` | POST `channel, start_time, end_time, media_type, stream_type, storage_type` | Envía `videoResourceQuery` → `{ok, seq}` |
| `devices/video/recording_list/{id}?seq=` | GET (sondeo cada 3 s, máx 20) | `{fresh, items[], nak}`; item: `channel,start,end,avType,streamType,storage,size` |
| `devices/video/playback_start/{id}` | POST `channel,start_time,end_time,media_type,stream_type,storage_type` | Envía `videoPlayback` → `{ok, player_url}` |
| `devices/video/playback_control/{id}` | POST `channel, control` | `videoPlaybackControl` (2 = stop al cerrar) |
| `devices/video/file_download_start`, `downloads`, `downloads/cancel`, `downloads/control` | | Descarga de archivos al servidor (fase posterior) |

### Reproductor
- iframe con `<video data-stream-modes=[...]>`: modo **compatible** = HLS vía proxy (hls.js);
  modo **tiempo real** = FLV sobre `wss://host/live-camera-ws?deviceId={imei}&channel=&token=` (mpegts.js) — requiere un gateway propio.
- Sondea `status` cada ~4 s y traduce a mensajes: dispositivo rechazó (`nak`), H.265 no soportado, conectado sin imagen,
  sin frames > 15 s, timeout de arranque. Selector de canal, HD/SD, captura de foto, pantalla completa.
- Cuadrícula multicanal (`videoGrid`): 1/2/3 columnas según canales; cada celda es un iframe; solo arranca el primero, el resto con botón.
- Mantiene un `viewer` aleatorio por ventana y serializa start/stop por `device_canal` para no apagar el stream de otro espectador.

## 3. Diseño propuesto para master-tracking

### Fase 1 — vivo (HLS) multicanal
1. **Migración** `devices`: `has_camera` (bool), `camera_channels` (json), `camera_channel_roles` (json). Fillable, casts, validación
   (`camera_channels.* integer 1..8`, roles `in:` lista), normalización en `DeviceService::normalize()`, `DevicePropertiesPolicy`.
2. **Formulario**: bloque en `Frontend/Devices/partials/main.blade.php` (create y edit) con el mismo patrón de TrackeraX.
3. **Permiso** `device_video` (`view`) en `config/permissions.php` + migración `PermissionService::addToAll`.
4. **Protocolo**: `HuabaoProtocol`/JT808 agrega tipos `videoStart`, `videoStop`, `videoPlayback`, `videoPlaybackControl`, `videoResourceQuery`
   (con `index`, `from`, `to`, `speed`, `action`) en `Tobuli\Protocols`.
5. **`DeviceVideoController`** + `Tobuli\Services\DeviceVideoService`:
   - `start`/`stop` con recuento de espectadores en Redis (`video:viewers:{deviceId}:{ch}` set con TTL) → `Tracker::sendCommand`.
   - `player` (blade del iframe), `proxy` (stream de `.m3u8`/`.ts` desde `Tracker` con Basic auth, reescribe nada porque las rutas son relativas),
     `status` (Redis/estado del fork; ver fase 1b).
6. **JS** `resources/assets/js/controller/deviceVideo.js` (`app.deviceVideo`): `live(id, ch)`, `grid(id)`, cierre con `sendBeacon`.
   hls.js servido localmente en `public/assets/plugins/hls/`. Entrada en el menú del objeto (`Objects/item.blade.php`) y en el popup del mapa
   solo si `has_camera` y permiso.

### Fase 1b — endpoint de estado en el fork
Agregar `GET /api/stream/imei/{imei}/{ch}/status` → `{connected, codec, active, lastFrameAt, segments}` para que el reproductor
diferencie "esperando dispositivo", "sin imagen", "H.265".

### Fase 2 — grabaciones y reproducción
`videoResourceQuery` + lectura de `videoResources` (posición más reciente por `seq`/tiempo), modal con línea de tiempo,
`videoPlayback` → reproductor sobre `history.m3u8`, control pausa/seek/stop.

### Fase 3 (opcional)
Tiempo real (FLV/WebSocket o WebRTC), audio y conversación, descargas de archivos.

## 4. Estado de la implementación (2026-10-29)

| Fase | Estado | Dónde |
|---|---|---|
| 1 — vivo HLS multicanal | Hecha | `DeviceVideoController`, `DeviceVideoService`, `DeviceVideo/player.blade.php`, `deviceVideo.js` |
| 1b — estado del stream | Hecha | Fork: `GET /api/stream/imei/{imei}/{ch}/status` (`connected, codec, lastFrameTime, segments, rejectedTime`) |
| 2 — grabaciones y reproducción | Hecha | Fork: `GET /api/stream/imei/{imei}/resources`, horas ISO-8601 en `videoResourceQuery`/`videoPlayback`; web: modal `DeviceVideo/recordings.blade.php`, reproductor en modo `history` con pausa/stop |
| 3 — tiempo real (FLV/WebRTC), audio, descargas | Pendiente | |

Notas:
- `public/assets/js/app.js` se parchea a mano (el módulo `DeviceVideo` se copia desde `resources/assets/js/controller/deviceVideo.js`);
  no ejecutar `gulp scripts` hasta reconciliar fuentes y bundle.
- El fork necesita `jt1078.host` (IP/dominio público anunciado al equipo) y, detrás de NAT, `jt1078.announcePort`.
- `media.streamIdleTimeout` (60 s) corta streams sin espectadores aunque el navegador no haya enviado `videoStop`.

## 5. Fase 3 (2026-10-29): tiempo real, audio y descargas

### Tiempo real (HTTP-FLV) y audio
- El fork sirve `GET /api/stream/live/{imei}/{canal}/live.flv` (H.264 en FLV, arranca en el siguiente keyframe) y
  `live.audio` (tramas `[payloadType 1B][largo 2B][datos]`). No usan sesión: validan un token
  `{expira}.{hex HMAC-SHA256(secret, "imei:canal:expira")}` firmado por Laravel (`DeviceVideoService::realtimeUrls`).
- El navegador se conecta directo al tracker, sin pasar por PHP, a través de nginx en el mismo dominio:

```nginx
location /tracker-media/ {
    proxy_pass http://127.0.0.1:8082/api/;
    proxy_http_version 1.1;
    proxy_buffering off;          # entrega cada tag FLV apenas llega
    proxy_read_timeout 3600s;
}
```

- Configuración:
  - tracker: `media.streamSecret`
  - web (`.env`): `VIDEO_STREAM_URL=https://<dominio>/tracker-media/stream/live`, `VIDEO_STREAM_SECRET=<mismo secreto>`
  - Sin ambos valores el reproductor solo muestra el modo compatible (HLS).
- `videoStart` acepta `audio` (tipo de dato 0) y `streamType` (0 HD, 1 SD). El botón Audio vuelve a pedir el video con audio.
- Audio: el navegador decodifica G.711A (6) y G.711U (7) a 8 kHz con WebAudio; para otros códecs (G.726, AAC) muestra un aviso.
- H.265 no viaja en FLV: el reproductor vuelve solo al modo compatible.

### Descargas
- `POST devices/video/{id}/downloads` crea un `device_video_downloads` y encola `App\Jobs\RecordVideoDownload` en la cola `video`.
- El job reproduce la grabación (`videoPlayback`), junta los segmentos de `history.m3u8` hasta cubrir la duración y lo pasa a MP4
  con `ffmpeg -c copy` (si no hay ffmpeg queda `.ts`). El equipo transmite en tiempo real: la descarga tarda lo mismo que la grabación.
- Máximo 30 min por descarga y una a la vez por equipo (el stream de historial es único por canal).
- Worker: `Kernel` programa `queue:work redis --queue=video --timeout=3900`; `video:clean-downloads` borra a diario las de más de
  `VIDEO_DOWNLOADS_DAYS` (7) y marca como fallidas las que quedaron colgadas.

### Pendiente
- Conversación bidireccional (micrófono → equipo, JT1078 0x9101 tipo 2) y audio G.726/AAC.
- Subida nativa de archivos por FTP (0x9206), alternativa más rápida a grabar la reproducción.
