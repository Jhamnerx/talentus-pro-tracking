# Fase 4 — conversación, audio G.726/AAC y subida nativa de archivos

Fecha: 2026-10-29. Fuentes revisadas (solo equipos con video; XT40 y Howen descartados):
- `traccar/docs/ilide.info-jc400-command-list-for-custom-1-...pdf` (JC400, comandos de texto)
- `traccar/docs/ilide.info-jc450command-list-en-v1-3-2-...pdf` (JC450, comandos de texto)
- `traccar/docs/Jimi IoT_Communication Protocol for JC261 and JC400_V1.3.5_2025-11-28.pdf` (JC261/JC400, protocolo tipo GT06)
- `traccar/docs/PLAN-VIDEO-JC400-JC450.md`, `GUIA-PROTOCOLOS-JIMI-JC450-JC400P.md`, `GUIA-ALTA-EQUIPOS-JC400-JC450.md`, `GUIA-TURBOHIVE.md`
- https://docs.jimicloud.com/integration/integration.html (envuelve mensajes JT/T 1078 estándar: proNo 37121 = 0x9101,
  37122 = 0x9102, 37382 = 0x9206, 37383 = 0x9207, 34817 = 0x8801, 33027 = 0x8103)

## Conclusión principal

Los PDFs locales **no documentan** conversación, códecs de audio ni FTP. El JC450 habla JT808/JT1078, así que esas
funciones siguen el estándar JT/T 1078-2016 (confirmado por los nombres de campo de jimicloud). El JC400/JC261
habla el protocolo Jimi (tipo GT06): no tiene conversación documentada y sube archivos solo por HTTP.

| Función | JC450 (JT808/1078) | JC400 / JC261 (Jimi GT06) |
|---|---|---|
| Conversación | 0x9101 tipo de dato 2, audio de bajada por el mismo socket JT1078; cierre 0x9102 comando 4 | No documentada |
| Escucha sin hablar | 0x9101 tipo de dato 3 (monitor) | No |
| Mensaje de voz | 0x8300 TTS (ya existe `voiceMessage`), `HTTPMP3,<url>` | `VOICESW`/`VOLUME` solo avisos del equipo |
| Códec de audio | No configurable por comando; se descubre con 0x9003 → 0x1003 | No documentado (se asume AAC por RTMP) |
| Subida de archivos | 0x9206 FTP + 0x9207 control + 0x1206 fin | HTTP: `UPLOAD,<url>`, `HVIDEO`, `EVIDEO`, `UPLOADFILE`, `FILELIST` |

## Diseño propuesto (por orden)

### 4a. Capacidades del equipo (0x9003 → 0x1003) — base de todo lo demás
- Fork: comando `videoAttributes` (0x9003) y decodificación de 0x1003: códec de audio de entrada, canales, frecuencia,
  bits, largo de trama, **salida de audio soportada** (parlante), códec de video, máx. canales de audio/video.
- Guardar el último resultado por equipo (como `videoResources`) y exponerlo en `GET /api/stream/imei/{imei}/attributes`.
- Web: botón "Detectar" en la pestaña Video del equipo; muestra el códec y si tiene parlante, y precarga los canales.

### 4b. Audio G.726 y AAC
- **G.726 (PT 8)**: decodificador G.726 en el navegador (16/24/32/40 kbps). La tasa sale de 0x1003 (largo de trama) o,
  si no, del largo del cuerpo: trama de 40 ms = 80/120/160/200 bytes.
- **AAC (PT 19/24, ADTS)**: en vez de decodificar en JS, el fork lo agrega como pista de audio en el FLV (tags AAC con
  AudioSpecificConfig del encabezado ADTS); mpegts.js lo reproduce sincronizado con el video.
- G.711 sigue igual (WebAudio).

### 4c. Conversación bidireccional (JC450)
- Fork:
  - `talkStart`: 0x9101 con tipo de dato 2 en el canal lógico de audio (36 por defecto, configurable, se prueba el 1 si
    el equipo lo rechaza); `talkStop`: 0x9102 comando 4.
  - Canal de bajada: el equipo abre su conexión JT1078; el servidor escribe paquetes RTP JT1078 (cabecera `30316364`,
    PT del códec del equipo, tipo de dato 3, subpaquete atómico) **en ese mismo socket**. Requiere guardar el
    `Channel` de la sesión de conversación en `VideoStreamManager`.
  - Endpoint WebSocket con token firmado (`/api/stream/talk/{imei}/{canal}`): recibe PCM 8 kHz/16 bit del navegador,
    lo codifica a G.711A/U (o al códec que informe 0x1003) y lo envía en tramas de 20–40 ms, con la cabecera HiSilicon
    de 4 bytes si el equipo la usa al subir.
- Web: botón "Hablar" (pulsar para hablar) en el reproductor en vivo; `getUserMedia` + AudioWorklet que remuestrea a
  8 kHz; un operador por canal a la vez (aviso "otro operador está hablando").
- Solo se muestra si 0x1003 indica salida de audio.

### 4d. Subida nativa de grabaciones por FTP (JC450)
- Fork: `videoFileUpload` (0x9206: servidor, puerto, usuario, clave, ruta, canal, desde/hasta, alarmas, tipo de recurso,
  flujo, almacenamiento, condición de red), `videoFileUploadControl` (0x9207: pausa/continuar/cancelar) y decodificar
  0x1206 (resultado por número de serie). Guardar el estado por tarea y exponerlo en `GET .../uploads`.
- Web: las descargas usan FTP cuando el equipo lo soporta (más rápido y con el archivo original) y quedan con la
  grabación de la reproducción como alternativa.
  - Usuario FTP por equipo con el `FtpUserService` existente (el mismo de `device_cameras`), ruta
    `video-uploads/{imei}/{tarea}`.
  - Al recibir 0x1206 = éxito, el job busca el archivo en la carpeta FTP, lo mueve al disco de descargas y marca la
    descarga como lista. Botones pausa/continuar/cancelar → 0x9207.

### 4e. Ajustes de cámara (opcional)
- JC450: `VIDEORSL,<canal>,<480|720|1080>,<15|25>,<kbps>`, `RECORDVSW,<canal>,0|1` (grabar audio),
  `RECORDSW,<canal>,0|1` (grabar video), `RATATION`, `LOGOSW`, `TFMODE`, `FORMAT`, `PICQ`, `VOLUME`; 0x8103 0x0075/0x0077.
- JC400: `VIDEORESOLUTION`, `RECORDAUDIO`, `RECORDSW`, `MIRROR`, `RATATION`, `VIDEOPARAM`, `FORMAT`.
- Formulario en la pestaña Video que arma el comando y lo envía con `SendCommandService`.

## Pruebas obligatorias con un JC450 real (no documentado)
1. Respuesta 0x1003: códec, largo de trama y si tiene salida de audio.
2. Canal lógico de la conversación (36 o 1) y si la bajada necesita la cabecera HiSilicon.
3. Tasa de G.726 si el equipo usa PT 8.
4. Si el JC450 ejecuta 0x9206 directo o solo a través del hub de Jimi; nombre y formato del archivo FTP; modo pasivo.

## Errores en las notas del fork (para corregir)
- `GUIA-ALTA-EQUIPOS-JC400-JC450.md`: `SERVIDOR,...` debe ser `SERVER,<host>,6015,NA,NA,NA,NA` (la lista del JC450 está
  traducida a máquina; el equipo no acepta los nombres en español). Lo mismo con `VOLUMEN`, `ZONA HORARIA`, etc.
- `RECORDVSW` = grabar audio y `RECORDSW` = grabar video en el JC450; la guía usa `RECORDSW` para el audio.
- `PLAN-VIDEO` dice que el audio se descarta: ya no es así (fase 3).
- `PLAN-VIDEO` propone configurar AAC por comando: no existe ese comando ni campo en 0x8103.
- Puerto `jimiphoto`: 6264 en `PLAN-VIDEO` vs 6267 en `GUIA-ALTA`/`setup/traccar.xml`.

## Estado (2026-10-29)

| Parte | Estado | Dónde |
|---|---|---|
| 4a capacidades | Hecha | Fork: comando `videoAttributes` (0x9003), decodificación 0x1003, `GET /api/stream/imei/{imei}/attributes`. Web: botón "Detectar desde el equipo" en la pestaña Video; se guarda en `devices.camera_attributes` y, si no hay canales elegidos, marca los que informa el equipo |
| 4b G.726 | Hecha | `public/assets/js/video/g726.js` (16/24/32/40 kbit/s, codificador y decodificador; prueba ida y vuelta: 18/27/34/42 dB). Tasa según `audioFrameLength` de 0x1003 o el tamaño de trama. Empaquetado configurable `VIDEO_G726_PACKING` (`rfc3551` / `aal2`) |
| 4b AAC | Hecha | Fork: `live.flv?audio=1` agrega pista AAC (ADTS → FLV). El reproductor cambia solo al modo tiempo real cuando llega AAC |
| 4c conversación | Hecha | Fork: `videoStart` con `dataType=2`, `videoStop` con `control=4`, `POST /api/stream/live/{imei}/{canal}/talk` escribe paquetes RTP JT1078 de audio por la conexión que abre el equipo. Web: botón "Hablar" en el reproductor en vivo (`public/assets/js/video/talk.js`: micrófono → 8 kHz → G.711A/U o G.726 → tramas cada 200 ms), un operador por equipo (bloqueo en Redis), se escucha la cabina por el canal de conversación |
| 4d subida FTP | Hecha | Fork: `videoFileUpload` (0x9206, con número de secuencia propio por equipo), `videoFileUploadControl` (0x9207), 0x1206 y rechazo en 0x0001; `GET /api/stream/imei/{imei}/uploads`. Web: con `VIDEO_FTP_ENABLED` las descargas piden la subida al equipo en `/video-downloads/{id}/`; `FtpVideoDownload` se re-encola cada 15 s hasta el resultado, toma el archivo (MP4 con ffmpeg si viene H.264 crudo, ZIP si son varios) y permite cancelar (0x9207). Sin FTP sigue la grabación de la reproducción |

Configuración de la conversación (`.env`): `VIDEO_TALK_CHANNEL` (36), `VIDEO_TALK_HISILICON_HEADER` (true), `VIDEO_TALK_G726_BITS` (5).
El micrófono solo funciona con HTTPS; el iframe del reproductor ya incluye `allow="microphone"`.
Solo se ofrece con el modo tiempo real configurado y si 0x1003 no informó que el equipo no tiene parlante.

A validar con un equipo real: canal de conversación (36 o 1), si la bajada necesita la cabecera HiSilicon, respuesta a 0x9003 y empaquetado del G.726 (si suena a ruido, `VIDEO_G726_PACKING=aal2`).

### Despliegue de la subida FTP

1. `php artisan video:ftp-user` crea la cuenta vsftpd (home `VIDEO_FTP_ROOT`) y muestra las variables para `.env`.
2. `.env`: `VIDEO_FTP_ENABLED=true`, `VIDEO_FTP_HOST=<IP o dominio público>`, `VIDEO_FTP_PORT`, `VIDEO_FTP_USERNAME`, `VIDEO_FTP_PASSWORD`, `VIDEO_FTP_ROOT`, `VIDEO_FTP_TIMEOUT` (min, 60).
3. vsftpd con `write_enable=YES` y los puertos pasivos abiertos en el firewall: el equipo sube por la red móvil.
4. A validar con el JC450: si ejecuta 0x9206 directo (o solo vía el hub de Jimi), nombre y formato del archivo, y si crea la carpeta de la ruta.
