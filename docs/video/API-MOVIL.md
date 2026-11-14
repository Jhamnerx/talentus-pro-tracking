# API de video para la app móvil

Mismos endpoints que la web, bajo `/api` y autenticados con `user_api_hash` (como el resto del API de la app).
Requieren el permiso `device_video` (ver) y que el equipo tenga cámaras activas en un protocolo con video (`VIDEO_PROTOCOLS`).

`get_devices` incluye `camera_channels` (`[]` si el equipo no tiene video o el usuario no tiene permiso): la app muestra
el botón de cámara solo cuando la lista no está vacía.

| Método | Ruta `api/devices/video/{id}/...` | Uso |
|---|---|---|
| GET | `config` | Canales (título, rol, URL HLS en vivo e historial, URLs firmadas de tiempo real), audio, conversación, límites |
| POST | `start` `channel, viewer, audio, quality=hd\|sd` | Pide el video al equipo (0x9101). Devuelve `seq` |
| POST | `ping` `channel, viewer` | Mantiene la sesión del espectador (cada ≤ `viewer_ttl`/3 s) |
| POST | `stop` `channel, viewer` | Libera la cámara cuando no quedan espectadores |
| GET | `status?channel=&type=live\|history` | `connected`, `codec`, `segments`, `rejectedTime` (rechazo si ≥ `seq`) |
| GET | `stream/{channel}/live.m3u8` (o `history.m3u8`) | HLS vía Laravel. La lista agrega `user_api_hash` a cada segmento |
| POST | `recordings/query` `channel (0 = todos), from, to` | Consulta la tarjeta del equipo (0x9205); fechas en hora del usuario `Y-m-d H:i` |
| GET | `recordings/list?seq=` | Lista recibida; `fresh` cuando llegó después de `seq`, `nak` si el equipo la rechazó |
| POST | `playback/start` `channel, from, to` (ISO de la lista) | Reproducción remota (0x9201); luego `status?type=history` y `history.m3u8` |
| POST | `playback/control` `channel, action (0 play, 1 pausa, 2 detener)` | 0x9202 |
| POST | `talk/start` · `talk/ping` · `talk/stop` `viewer` | Conversación (un operador a la vez); el audio va directo a `talk.urls.talk` |
| GET/POST | `downloads` | Lista / crea una descarga (`channel, from, to`, máx. `download_max_seconds`) |
| GET/DELETE | `downloads/{downloadId}` | Archivo (la URL de la lista ya trae el hash) / borrar o cancelar |

Flujo en vivo de la app (`lib/screens/video/video_live.dart`):

1. `config` → elegir canal → `start` (con `audio=1` si el audio está activo).
2. HLS: consultar `status` hasta `segments > 0` y reproducir `hls`. En Android, el modo "Tiempo real" reproduce `realtime.flv`
   directamente (ExoPlayer); iOS solo usa HLS.
3. `ping` periódico; `stop` al salir, al cambiar de canal o al pasar la app a segundo plano.
4. Audio G.711/G.726: la app lee `realtime.audio` (tramas `[tipo][largo 2B][datos]`) y lo decodifica a PCM 8 kHz.
   AAC solo viaja en el FLV (`&audio=1`), por eso en Android cambia a tiempo real.
5. Conversación: micrófono 8 kHz → códec del equipo → POST cada 200 ms a `talk.urls.talk` (409 = el equipo aún no
   abrió su canal de audio, 403 = token vencido).

Las URLs firmadas (`realtime`, `talk`) vencen a los `VIDEO_STREAM_TOKEN_TTL` segundos (600 por defecto) y solo se validan al
conectar; la app vuelve a pedir `config` poco antes del vencimiento.

El audio y la conversación necesitan el modo tiempo real (`VIDEO_STREAM_URL` y `VIDEO_STREAM_SECRET`); sin él la app
muestra solo video HLS.
