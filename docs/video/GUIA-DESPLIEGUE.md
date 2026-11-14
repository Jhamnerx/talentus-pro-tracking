# Guía de actualización: video JT1078 y nuevo diseño del mapa

Cubre todo lo que entró a `master` (commits `8a05fcc` → `a271b22`):
- video en vivo multicanal (HLS) y modo tiempo real (HTTP-FLV);
- audio G.711, G.726 y AAC;
- grabaciones, reproducción y descargas (por reproducción o por FTP);
- conversación bidireccional;
- detección de capacidades de la cámara;
- tema moderno del mapa.

El tracker (fork de traccar, rama `talentus`) ya está compilado y corriendo en el servidor. Esta guía indica qué
configurar en él y en la web, y cómo verificar cada función.

---

## 1. Tracker (traccar.xml)

| Clave | Valor | Para qué |
|---|---|---|
| `jt808.port` | `6015` | Señalización JT808 (ya existente) |
| `jt1078.port` | `6263` | Conexión de video/audio que abre el equipo |
| `jt1078.host` | IP o dominio **público** | Dirección que se le indica al equipo para enviar el video. Si falta, se usa el host de `web.url` |
| `jt1078.announcePort` | puerto público | Solo si hay NAT y el puerto público difiere de `jt1078.port` |
| `jimiphoto.port` / `jimiphoto.host` | `6267` / host público | Evidencias Jimi (VIDEOUPLOAD) |
| `media.streamSecret` | cadena aleatoria larga | Firma de los enlaces de tiempo real y conversación. **Igual** a `VIDEO_STREAM_SECRET` de la web |
| `media.streamIdleTimeout` | `60` | Segundos sin espectadores antes de cortar el video del equipo |
| `media.playbackRequestTimeout` | `30` | Espera de la conexión de reproducción tras pedirla |
| `web.port` | `8082` | API que usa la web (ya existente) |

Generar el secreto:

```bash
openssl rand -hex 32
```

Firewall del servidor del tracker: abrir TCP **6015, 6263 y 6267** hacia internet (los equipos se conectan por la red
móvil). El puerto 8082 **no** se expone; la web y Apache lo usan por localhost. En AlmaLinux 9 (firewalld):

```bash
firewall-cmd --permanent --add-port=6015/tcp --add-port=6263/tcp --add-port=6267/tcp && firewall-cmd --reload
```

Después de cambiar `traccar.xml`, reiniciar el tracker.

## 2. Web (master-tracking)

### 2.1 Código y base de datos

```bash
cd /var/www/html            # carpeta del proyecto
git pull origin master
php artisan config:clear && php artisan route:clear && php artisan view:clear   # ANTES de migrar
php artisan migrate --force
php artisan config:cache    # solo si producción usa caché de configuración
```

> Limpiar la caché de configuración **antes** de `migrate`: con la caché vieja, la migración no ve el permiso
> `device_video` nuevo de `config/permissions.php`. Desde el commit que corrige esto, la migración usa valores por
> defecto en ese caso. Si la migración ya falló a mitad, se puede volver a ejecutar: es idempotente.

Migraciones nuevas:
- `2026_10_28_000001`: columnas `has_camera`, `camera_channels` y `camera_channel_roles` en `devices`, más el
  permiso `device_video`.
- `2026_10_29_000001`: tabla `device_video_downloads`.
- `2026_10_29_000002`: columna `camera_attributes` en `devices`.
- `2026_10_29_000003`: columnas `method` y `upload_path` en las descargas.

No hace falta `composer install` ni `npm`. hls.js y mpegts.js ya van en `public/assets/plugins/`. Los JS del video
están en `public/assets/js/video/`.

> **No ejecutar `gulp scripts` ni `gulp assets`.** El `public/assets/js/app.js` publicado tiene código (HomeView,
> callActionCreate) que no está en `resources/assets/js`. El módulo de video se insertó a mano en el bundle, así que
> recompilarlo borraría esas funciones. Los CSS del tema nuevo ya están compilados en `public/assets/css/`.

### 2.2 Variables de entorno (`.env`)

```dotenv
# Tiempo real (FLV), audio y conversación: el navegador se conecta directo al tracker vía Apache (/tracker-media/)
VIDEO_STREAM_URL=https://TU-DOMINIO/tracker-media/stream/live
VIDEO_STREAM_SECRET=<mismo valor que media.streamSecret>
VIDEO_STREAM_TOKEN_TTL=600

# Protocolos del tracker con video JT1078 (gt06 = JC400/JC261 en protocolo Jimi, no soportado)
VIDEO_PROTOCOLS=jt808
# Talentus: cuando el fork implemente Howen con el mismo contrato, añadirlo aquí: VIDEO_PROTOCOLS=jt808,howen

# Audio
VIDEO_G726_PACKING=rfc3551          # si el G.726 suena a ruido: aal2

# Conversación
VIDEO_TALK_CHANNEL=36               # si el equipo rechaza la conversación: 1
VIDEO_TALK_HISILICON_HEADER=true
VIDEO_TALK_G726_BITS=5

# Descargas
VIDEO_DOWNLOADS_DISK=local
VIDEO_DOWNLOADS_DAYS=7
VIDEO_DOWNLOADS_QUEUE=video
VIDEO_FFMPEG=ffmpeg

# Subida nativa por FTP (opcional, ver 2.6)
VIDEO_FTP_ENABLED=false
VIDEO_FTP_HOST=
VIDEO_FTP_PORT=21
VIDEO_FTP_USERNAME=videoupload
VIDEO_FTP_PASSWORD=
VIDEO_FTP_ROOT=/home/videoupload
VIDEO_FTP_TIMEOUT=60
```

Sin `VIDEO_STREAM_URL` ni `VIDEO_STREAM_SECRET`, el video funciona igual en modo compatible (HLS). Sin embargo, no
aparecen el modo tiempo real, el botón de audio ni el botón Hablar.

También se usan las variables existentes `TRACKER_WEB_URL`, `TRACKER_WEB_PORT` y la clave de administración del
tracker: la web envía comandos y pide el HLS con la misma autenticación Basic que ya usaba para los comandos GPRS.

### 2.3 Apache httpd (AlmaLinux 9), mismo dominio de la web con HTTPS

1. Verificar que estén cargados los módulos de proxy (vienen en el paquete `httpd`):
   ```bash
   httpd -M | grep -E 'proxy_module|proxy_http_module'
   ```
   Si no aparecen, revisar que `/etc/httpd/conf.modules.d/00-proxy.conf` no los tenga comentados.

2. Dentro del `<VirtualHost *:443>` de la plataforma (`/etc/httpd/conf.d/*ssl*.conf` o el archivo del sitio), antes de
   cualquier `Rewrite` propio:
   ```apache
   # Video en tiempo real, audio y conversación: el navegador habla directo con el tracker
   ProxyRequests Off
   ProxyPreserveHost On
   ProxyPass        /tracker-media/ http://127.0.0.1:8082/api/ flushpackets=on timeout=3600 keepalive=On
   ProxyPassReverse /tracker-media/ http://127.0.0.1:8082/api/

   <Location /tracker-media/>
       # Sin compresión: mod_deflate retiene la respuesta y corta el streaming FLV/audio
       SetEnv no-gzip 1
       SetEnv dont-vary 1
       # Tramas de conversación (POST pequeños)
       LimitRequestBody 1048576
   </Location>
   ```
   - `flushpackets=on` entrega cada tag FLV apenas llega; es el equivalente a `proxy_buffering off` de nginx.
   - `ProxyPass` se resuelve antes que el `.htaccess` de Laravel, así que `/tracker-media/` no llega a `index.php`.

3. SELinux. Por defecto en AlmaLinux impide que httpd abra conexiones al puerto 8082, y el proxy devuelve 503:
   ```bash
   setsebool -P httpd_can_network_connect 1
   ```

4. Probar y recargar:
   ```bash
   apachectl configtest && systemctl reload httpd
   ```
   ```bash
   curl -sI "https://TU-DOMINIO/tracker-media/stream/live/IMEI/1/live.flv?token=x"
   ```
   Debe responder **403** (token inválido), es decir, que llega al tracker. Un 503 indica SELinux o tracker caído.
   Un 404 de Laravel indica que el `ProxyPass` no está en el VirtualHost correcto.

HTTPS es obligatorio para el micrófono (botón Hablar). El iframe del reproductor ya pide `allow="microphone"`.

### 2.4 Cola y tareas programadas

- El cron de Laravel debe estar activo:
  ```
  * * * * * php /var/www/html/artisan schedule:run >> /dev/null 2>&1
  ```
- El `Kernel` lanza `queue:work redis --queue=video --timeout=3900` cada minuto, sin solaparse, para las
  descargas.
- `video:clean-downloads` corre a diario: borra las descargas de más de `VIDEO_DOWNLOADS_DAYS` días y marca como
  fallidas las que quedaron colgadas.
- Si usas supervisor en lugar del scheduler, agrega un programa con
  `php artisan queue:work redis --queue=video --sleep=3 --tries=1 --timeout=3900`.
- Redis, en la conexión `process`, guarda los espectadores por canal y el bloqueo de la conversación (un operador a
  la vez).

### 2.5 ffmpeg (recomendado)

```bash
ffmpeg -version
```

Convierte las descargas a MP4 sin recodificar. Sin ffmpeg las descargas se entregan como `.ts` (o `.h264` en FTP).

### 2.6 Subida nativa por FTP (opcional, JC450)

1. Crear la cuenta en vsftpd y copiar al `.env` las variables que muestra el comando:
   ```bash
   php artisan video:ftp-user
   ```
2. `.env`: `VIDEO_FTP_ENABLED=true` y `VIDEO_FTP_HOST=<IP o dominio público>`.
3. vsftpd (`/etc/vsftpd/vsftpd.conf`): `write_enable=YES`, `pasv_enable=YES`, `pasv_min_port=40000`,
   `pasv_max_port=40100` y `pasv_address=<IP pública>`. Abrir esos puertos y el 21:
   ```bash
   firewall-cmd --permanent --add-service=ftp --add-port=40000-40100/tcp && firewall-cmd --reload
   ```
   SELinux, para que vsftpd escriba en el home de la cuenta:
   ```bash
   setsebool -P ftpd_full_access 1
   ```
   ```bash
   systemctl restart vsftpd
   ```
4. `php artisan config:clear`.

Con FTP habilitado, las descargas piden al equipo el archivo original (0x9206). Sin FTP, graban la reproducción
remota.

### 2.7 Permisos

- La migración da a todos los usuarios y planes el permiso `device_video` con ver = sí, editar = sí.
- Para restringirlo: Admin → Clientes / Planes → permisos → "Video del dispositivo".
- *Ver* permite ver el video. *Editar* permite configurar cámara y canales en el equipo.

## 3. Configurar un equipo (JC450)

1. Comandos al equipo (lista de comandos en inglés; la versión traducida al español tiene nombres que el equipo no
   acepta):
   - `SERVER,<host>,6015,NA,NA,NA,NA`
   - `RECORDVSW,<canal>,1`: grabar audio.
   - `RECORDSW,<canal>,1`: grabar video.
2. En la plataforma: Editar equipo → pestaña **Video**.
   1. **Detectar desde el equipo**. Muestra el códec de audio, la frecuencia, el largo de trama, si tiene parlante y
      la cantidad de canales.
   2. Marcar **Tiene cámara**, los canales y el rol de cada canal (frontal, conductor, etc.). Guardar.

## 4. Verificación

Verificar en este orden; cada paso depende del anterior. `IMEI` es el del equipo y `PASS` el valor de `admin_user`
del `.env` de la web (la misma credencial Basic `admin:PASS` que usa `Tracker::sendCommand`).

| # | Qué probar | Cómo | Resultado esperado |
|---|---|---|---|
| 1 | API del tracker | `curl -u admin:PASS http://localhost:8082/api/stream/imei/IMEI/1/status` | JSON con `connected:false` (o `true` si hay video) |
| 2 | Capacidades | Pestaña Video → Detectar | Resumen "Audio … \| Video …" en menos de 30 s. "No soporta la consulta" = el equipo no implementa 0x9003 (no impide el video) |
| 3 | Vivo (HLS) | Menú del objeto → Video en vivo | "Iniciando…" → "Esperando que el equipo envíe video…" → imagen en 5–15 s |
| 4 | Estado | Durante el vivo, repetir el curl del paso 1 | `connected:true`, `codec:"h264"`, `segments` > 0 |
| 5 | Cuadrícula | Equipo con 2+ canales → Video en vivo | Una celda por canal |
| 6 | Tiempo real | Reproductor → Modo "Tiempo real" | Imagen con ~1 s de demora. Si es H.265 vuelve solo a Compatible y avisa |
| 7 | Audio | Botón Audio | Se oye la cabina. "Códec no soportado (N)" indica qué tipo de carga envía |
| 8 | Conversación | Botón Hablar (HTTPS, permitir micrófono) | "Esperando canal…" → "Hablando: el conductor te escucha" |
| 9 | Grabaciones | Menú del objeto → Grabaciones → Consultar | Tabla de archivos del equipo en menos de 60 s |
| 10 | Reproducción | Grabaciones → Reproducir | Video de la grabación en el modal; pausa y continuar |
| 11 | Descarga | Grabaciones → icono de descarga | Pasa a "Descargando…" o "El equipo está subiendo…" y luego "Lista" con enlace |
| 12 | Cola | `php artisan queue:work redis --queue=video --once` (manual) | Procesa una descarga pendiente sin errores |
| 13 | Tema | Mapa | Barra azul con íconos, paneles flotantes y estado de flota |

## 5. Solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| "Este equipo usa el protocolo gt06, que no tiene video JT1078" | El equipo está conectado en protocolo Jimi (GT06), no JT808 | El video JT1078 requiere un equipo JT808 (JC450) enviando al puerto 6015. Un JC400/JC261 en gt06 no tiene video aquí |
| "No se puede conectar con el servidor de seguimiento" | La web no llega a `TRACKER_WEB_URL:TRACKER_WEB_PORT` | Revisar `.env` y que el tracker esté arriba |
| Se queda en "Esperando que el equipo envíe video" y luego da timeout | El equipo no abre la conexión a `jt1078.host:jt1078.port` | Revisar `jt1078.host` (público), el firewall del 6263 y el log del tracker (`jt1078`) |
| "El equipo rechazó la solicitud de video" | Canal inexistente u ocupado | Revisar los canales marcados en la pestaña Video |
| "El equipo se conectó pero no envía imagen" | Cámara desconectada en ese canal | Revisar el cableado o desmarcar el canal |
| Aviso de H.265 en modo compatible | El navegador no decodifica HEVC | Configurar la cámara en H.264 o usar un navegador con HEVC |
| No aparece el modo Tiempo real / Audio / Hablar | Faltan `VIDEO_STREAM_URL` o `VIDEO_STREAM_SECRET` | Completar `.env` y ejecutar `config:clear` |
| Tiempo real da 403 | El secreto difiere entre la web y el tracker, o el reloj está desfasado | Igualar `media.streamSecret` y `VIDEO_STREAM_SECRET`; revisar NTP |
| Tiempo real conecta pero no llega nada | Apache comprime o almacena en búfer la respuesta | `flushpackets=on` en `ProxyPass` y `SetEnv no-gzip 1` en `<Location /tracker-media/>` |
| `/tracker-media/` responde 503 | SELinux bloquea a httpd hacia el puerto 8082, o el tracker está caído | `setsebool -P httpd_can_network_connect 1`; revisar el tracker |
| `/tracker-media/` responde 404 de Laravel | `ProxyPass` no está en el VirtualHost :443 que atiende el dominio | Moverlo al VirtualHost correcto y recargar httpd |
| G.726 suena a ruido | Orden de bits | `VIDEO_G726_PACKING=aal2` |
| Hablar queda en "Esperando canal" | El canal de conversación no es 36 | `VIDEO_TALK_CHANNEL=1` |
| Hablar: "No se permitió usar el micrófono" | Sin HTTPS o permiso denegado | Usar HTTPS y permitir el micrófono en el navegador |
| Descargas quedan "En cola" | No corre el worker de la cola `video` | Revisar el cron o supervisor (2.4) |
| FTP: "El equipo rechazó la subida" | Sin grabación en el rango, o FTP inaccesible desde la red móvil | Probar el FTP desde fuera; revisar puertos pasivos |
| FTP: "no llegó ningún archivo" | El equipo subió a otra carpeta o vsftpd no permite escritura | Revisar el log de vsftpd y `write_enable` |

## 6. Pendiente de validar con un JC450 real

Ninguna de estas funciones está documentada por Jimi; se implementaron según el estándar JT/T 1078:
- respuesta a 0x9003 (capacidades);
- canal de conversación (36 o 1) y si el audio de bajada lleva la cabecera HiSilicon;
- orden de bits del G.726;
- ejecución de 0x9206 (FTP), con el nombre y formato del archivo.

Si algo de esto difiere, se ajusta con las variables `VIDEO_*` sin tocar código.

Detalle técnico: `docs/video/ANALISIS-VIDEO.md` (fases 1–3) y `docs/video/FASE-4.md`.
