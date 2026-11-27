# Manual de despliegue: rama `nuevo-tema` (UI v2) a producción

Revisado el 26-nov-2026 sobre `nuevo-tema` en `469ccf01` (más el arreglo `4e543f3c`, ver §2.1) contra `master` en `36f9d048`.
Servidor de referencia: AlmaLinux 9, Apache, MariaDB 10.5, Redis, supervisor con usuario `apache`,
aplicación en `/var/www/html/current`. Si tu servidor difiere, ajusta rutas y usuario.

Lo marcado con **(comprobar en el servidor)** no se puede saber desde el repositorio.

---

## 1. Qué entra

595 commits, 1857 archivos. No es solo un tema nuevo:

| Bloque | Exige en el servidor |
| ------ | -------------------- |
| **Laravel 8.83 → 9.52** (más activitylog 3 → 4, flysystem 1 → 3, swiftmailer → symfony/mailer, plivo 1 → 4, base GPSWOX 3.7.11 → 3.7.13) | `composer install`, borrar `bootstrap/cache/*.php`, PHP ≥ 8.0.2 |
| **UI v2** (`/ui/v2/*`) y admin con estilo nuevo | Nada que compilar. Una migración pasa a **todos** los usuarios a la v2 |
| **57 migraciones** | Respaldo, hora valle, `migrate --force --step` |
| **Backups nuevos** (FTP + Google Drive opcional) | Worker nuevo `laravel-worker-backups`, dos carpetas, `rclone` solo si se usa Drive |
| **Operaciones** y **Seguridad** | `.env` (`OPERATIONS_ENABLED`, `SECURITY_ENABLED`), permisos por usuario |
| **Vídeo JT1078** | Fork de Traccar, proxy en Apache, `.env`, `ffmpeg`. Sin esto queda oculto, no rompe |
| **Talentus IA CHAT** | Clave de API en Admin, permiso por usuario/plan. Apagado por defecto |
| **Clasificación de vehículo por usuario**, comandos en cola, hitos km, rutas programadas, tags, 2FA, verificación de teléfono | Solo migraciones; 2FA y teléfono quedan apagados |
| **Pipeline de posiciones** (filtro GNSS en sombra, `device_trips`, datos de red) | Una variable `.env` si no quieres el cambio de `APPLY_NETWORK_DATA` |

UNIGIS, Securitas y normas de comando **ya están en producción** con `master`; sus workers
(`laravel-ws-unigis`, `laravel-ws-securitas`) no cambian: `laravel-worker-webservices.ini` es idéntico
en las dos ramas.

---

## 2. Antes del día del despliegue (en desarrollo)

### 2.1 Arreglo de `master` que faltaba en `nuevo-tema` (hecho)

`1c169698 fix(devices): no congelar la duración de estacionado con un ack viejo` estaba en producción
y no en `nuevo-tema`. Se trajo con `cherry-pick` el 26-nov-2026 (commit `4e543f3c`, ya en `origin/nuevo-tema`). Desplegar desde un commit anterior a ese devuelve el fallo.

Los otros ocho commits propios de `master` ya estaban en `nuevo-tema` (cuatro idénticos; `dout`, UNIGIS,
normas y Securitas con otro hash y cambios posteriores encima).

### 2.2 Cómo llega el código a producción

Fusionar `nuevo-tema` en `master` da **6 conflictos**:

- `Tobuli/Services/Securitas/SecuritasEventMapper.php` y `Tobuli/Services/Unigis/UnigisEventMapper.php`
  (añadidos en las dos ramas; la versión de `nuevo-tema` es la más nueva, lee `alarm` del fork).
- `Tobuli/Views/Frontend/Devices/create.blade.php` y `edit.blade.php`.
- `app/Console/Kernel.php` y `app/Console/PositionsWriter.php`.

Dos caminos:

- **A (recomendado):** en desarrollo, `git merge master` dentro de `nuevo-tema`, resolver los seis,
  probar, y después `master` avanza a `nuevo-tema` sin conflicto. Producción sigue en `master`.
- **B:** producción hace `git checkout nuevo-tema` directamente (tras 2.1). Más rápido, pero el
  servidor queda en otra rama.

En ningún caso resolver conflictos en el servidor.

### 2.3 Limpieza opcional

Viajan seis copias `.bak-*` en `app/Console` y `app/Providers` (PHP no las carga: no terminan en
`.php`). `PositionsWriter.php:2469` sigue escribiendo `storage/logs/dout_trace_<imei>.log`.

### 2.4 Decisiones que hay que tomar antes

| Decisión | Por qué |
| -------- | ------- |
| ¿Todos los usuarios pasan a la v2 el mismo día? | La migración `2026_11_23_000002_move_all_users_to_ui_v2` lo hace sin vuelta atrás, incluidos los que eligieron «Versión anterior». Para desplegar sin ese cambio, apartar el archivo antes de migrar y correrlo otro día |
| Marca blanca | La v2 solo respeta nombre, favicon y logo. **No** aplica `template_color` ni el CSS/JS propio de Appearance. Los resellers con color o CSS propio lo pierden en la v2 (el legacy lo conserva). Avisarles |
| Redes de clientes con salida restringida | La v2 carga Leaflet, ECharts y fuentes desde `unpkg.com`, `cdn.jsdelivr.net` y `rsms.me` |
| Qué módulos se encienden el primer día | Operaciones, Seguridad, IA y vídeo se pueden encender después sin otro despliegue |

---

## 3. Comprobaciones en el servidor (días antes)

```bash
php -v                                   # >= 8.0.2 (el lock se generó y probó en 8.0)
php -m | grep -Ei 'soap|ftp|zip|sodium|gd|curl|mbstring|xml|pdo_mysql'
composer --version
cd /var/www/html/current
git status --short                       # ver qué hay modificado
ls -l bootstrap/cache/                   # ¿existe config.php? => producción usa config:cache
ls -ld /var/www/html/current             # ¿symlink a releases o clon directo?
grep -E '^(SOCKET_PORT|SOCKET_SSL_PORT|APPLY_NETWORK_DATA|CACHE_DRIVER|QUEUE_DRIVER|ACTIVITY_LOGGER_DB_CONNECTION)' .env
grep -rl 'program:laravel-worker-tracker' /etc/supervisord* /etc/supervisor* 2>/dev/null
grep -rl "schedule:run" /etc/crontab /etc/cron.d /var/spool/cron 2>/dev/null
ls /var/www/html/images 2>/dev/null | head   # ver nota de backups en §6.3
df -h /var/www/html/current/storage
which ffmpeg ncftpput mysqldump rclone
```

Qué mirar:

- **`git status` sucio en `resources/lang` y `resources/original_lang`.** Es lo esperable:
  `server:translations` corre cada noche a las 02:00 y reescribe esos archivos versionados. El
  `checkout` abortará si no se descartan (§5.3). Las traducciones personalizadas reales están en
  `storage/langs/`, que la rama no toca.
- **`public/images/logo.png` modificado.** La rama trae otro `logo.png`; si el servidor tiene el suyo,
  respaldarlo y restaurarlo después.
- **`SOCKET_PORT` / `SOCKET_SSL_PORT`.** La v2 usa 9001 / 9002 fijos, igual que el legacy
  (`controller/app.js`). Si el `.env` define otros puertos, `socket/socket.js` escucharía en ellos y
  ni el legacy ni la v2 conectarían: no deben estar definidos, o deben valer 9001 y 9002.
- **Despliegue por releases.** Si `current` es un symlink, `storage/`, `public/images/device_images`,
  `public/images/logos` y `public/images/deviceTypes` deben ser compartidos entre releases.

---

## 4. Variables de entorno

La plantilla completa está en `.env.example` (todas las variables que lee el código, con su valor
por defecto). Ninguna variable existente cambió de nombre (siguen `QUEUE_DRIVER`,
`CACHE_DRIVER`, `SESSION_DRIVER`; no hay `MAIL_MAILER` ni `FILESYSTEM_DISK`). El correo se configura
desde la base de datos como hasta ahora.

### 4.1 Añadir el día del despliegue

```dotenv
# Conserva el comportamiento actual. El valor por defecto pasó de false a true: sin esta línea,
# cada posición con datos de celda guarda mcc/mnc/lac/cellid/operator en sus parámetros.
APPLY_NETWORK_DATA=false

# Módulos: apagados no consultan nada. Encender cuando el cliente esté listo.
OPERATIONS_ENABLED=false
SECURITY_ENABLED=false
```

### 4.2 Según lo que se active

| Variable | Defecto | Para qué |
| -------- | ------- | -------- |
| `BACKUP_RCLONE_CONFIG` | vacío | Ruta de `rclone.conf`. Solo con backups a Drive |
| `VIDEO_STREAM_URL`, `VIDEO_STREAM_SECRET` | vacío | Vídeo en tiempo real, audio y Hablar. El secreto debe ser igual a `media.streamSecret` del tracker. Sin ellas solo hay modo HLS |
| `VIDEO_PROTOCOLS` | `jt808` | Protocolos con vídeo |
| `VIDEO_FFMPEG`, `VIDEO_DOWNLOADS_DAYS`, `VIDEO_DOWNLOADS_DISK`, `VIDEO_DOWNLOADS_QUEUE` | `ffmpeg`, 7, `local`, `video` | Descargas de vídeo |
| `VIDEO_FTP_ENABLED` y `VIDEO_FTP_*` | `false` | Subida nativa por FTP; las imprime `php artisan video:ftp-user` |
| `VIDEO_G726_PACKING`, `VIDEO_TALK_CHANNEL`, `VIDEO_TALK_HISILICON_HEADER`, `VIDEO_TALK_G726_BITS`, `VIDEO_STREAM_TOKEN_TTL` | `rfc3551`, 36, `true`, 5, 600 | Ajustes de audio y conversación |
| `ANTHROPIC_API_KEY` | vacío | Clave de IA. Mejor ponerla en Admin (queda cifrada y prevalece) |
| `AI_MONTHLY_BUDGET_USD`, `AI_MONTHLY_QUESTIONS` | 0, 100 | Tope de gasto y cuota. Fijar un tope si se activa la IA |
| `AI_ALERTS_DIGEST` | `false` | Resumen diario de alertas a las 07:00. Escribe a los clientes |
| `AI_ENABLED`, `AI_MODEL`, `AI_LIGHT_MODEL`, `AI_EFFORT`, `AI_EXPLAIN_REPORT`, `AI_REPORT_EMAIL`, `AI_DASHBOARD` | ver `config/ai.php` | Se gobiernan desde Admin |
| `OTP_ENABLED` | `false` | 2FA. Encendido, **todo** usuario web sin 2FA es enviado a configurarlo |
| `GNSS_GLITCH_ENABLED` / `GNSS_GLITCH_ENFORCE` | `true` / `false` | Filtro de saltos GNSS. Por defecto solo marca la posición y escribe `validaciones_tecnicas-*.log`. Con `ENFORCE=true` invalida la posición (no suma km ni dispara alertas). Dejar en `false` hasta revisar el log |
| `OVERSPEED_TRACKING_MAX_GAP` | 300 | Reinicia el cronómetro del exceso en geocerca si pasan más segundos. **Afecta alertas** |
| `FROM_STOP_MAX_JUMP_METERS`, `FROM_STOP_AVG_SPEED_MULTIPLIER`, `FROM_STOP_AVG_SPEED_MARGIN_KPH` | 150, 1.3, 5 | Salto desde parada. Antes ×1.1 sin margen: ahora se invalidan menos arranques |
| `NATIVE_GEOFENCE_SPEED_ENABLED` | `true` | Solo log del exceso nativo Teltonika |
| `ENABLE_TAGS`, `ENABLE_DEVICE_CUSTOM_DATA`, `ENABLE_FLIGHTS_INFO`, `DISTANCE_CALC_STRATEGY` | `true`, `false`, `false`, `false` | Addons de la base 3.7.13 |
| `CHECK_SERVICE_EXPIRE_LOG` | `false` | Log de `service:check_expire` |

La verificación de teléfono no va por `.env`: es el ajuste `main_settings.phone_verification` del admin
(nace apagado). No encenderlo sin plan: bloquea a todo usuario con teléfono hasta que verifique por SMS.

---

## 5. Despliegue

Ventana estimada: 20–40 minutos, en hora valle y **sin backup en curso**. Las posiciones que lleguen
durante la ventana se quedan en Redis y se procesan al volver.

```sql
-- no debe devolver filas
select id, backup_id, reserved_at from backup_processes
where completed_at is null and failed_at is null
  and DATE_ADD(created_at, INTERVAL duration_expire SECOND) > NOW();
```

### 5.1 Parar (con el código viejo todavía)

```bash
cd /var/www/html/current
sudo -u apache php artisan down
sudo -u apache php artisan queue:restart
sudo supervisorctl stop laravel-core:* laravel-webservices:*
sleep 70; pgrep -af 'artisan (insert:run|queue:work)'    # debe quedar vacío
```

`down` corta la web, hace que `insert:run` termine solo (vive 60 s) y que `schedule:run` no lance
nada, incluido el `systemctl restart supervisord` del minuto 3 de cada hora.

### 5.2 Respaldo

```bash
mysqldump --single-transaction --routines gpswox_web | gzip > /root/pre-uiv2-$(date +%F-%H%M).sql.gz
cp -a .env /root/env-pre-uiv2
cp -a storage/langs /root/langs-pre-uiv2
cp -a public/images/logo.png /root/logo-pre-uiv2.png
git rev-parse HEAD > /root/commit-pre-uiv2
```

Usar el nombre real de la base (`web_database` del `.env`). Este volcado es el plan de vuelta atrás.

### 5.3 Código

```bash
git checkout -- resources/lang resources/original_lang
git status --short                 # no debe quedar nada versionado modificado (salvo logo.png, ya respaldado)
git fetch origin
git checkout master && git pull    # camino A; en el camino B: git checkout nuevo-tema && git pull
```

### 5.4 Cachés de arranque y dependencias

Obligatorio **antes** de `composer install`: el `bootstrap/cache/config.php` viejo nombra
`Fideloper\Proxy\TrustedProxyServiceProvider`, que ya no existe, y cualquier `artisan` fallaría al
arrancar.

```bash
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/packages.php bootstrap/cache/services.php
sudo -u apache composer install --no-dev --optimize-autoloader
sudo -u apache php artisan view:clear
sudo -u apache php artisan route:clear
```

No ejecutar `php artisan cache:clear`: la caché es Redis y guarda los cerrojos del scheduler (se
duplicarían los workers `tracker` y `video`) y el seguimiento de excesos en geocerca.

No ejecutar `passport:install`, `passport:keys`, `vendor:publish` ni `storage:link`. Conservar
`storage/oauth-*.key`.

### 5.5 `.env`

Añadir lo de §4.1 (y lo de §4.2 que corresponda).

### 5.6 Migraciones

```bash
sudo -u apache php artisan migrate --force --step
sudo -u apache php artisan migrate:status | tail -70
```

`--step` deja cada migración en su propio lote. Son 57 pendientes (65 archivos nuevos; ocho ya se
corrieron con `master`). Sin SQL exclusivo de MySQL 8; no tocan las tablas de posiciones ni la base de
Traccar.

**Las que hay que conocer:**

| Migración | Qué hace | Si falla a medias |
| --------- | -------- | ----------------- |
| `2025_03_06_101142` y `_101143` | `event` y `batch_uuid` en `model_change_logs` (activitylog 4). Usan la conexión `ACTIVITY_LOGGER_DB_CONNECTION` | Sin guard: «Duplicate column» al reintentar |
| `2025_03_26_133746_add_unique_constraint_on_report_device` | En `report_device_pivot`: añade `id`, borra duplicados, quita `id`, crea índice único. Reconstruye la tabla dos veces | `ALTER TABLE report_device_pivot DROP COLUMN id` a mano y reintentar |
| `2025_03_13_134651_alter_device_services_dates_to_datetimes` | `expires_date` y `remind_date` de DATE a DATETIME y las pasa de hora local a UTC | **No reintentar a ciegas**: correría dos veces el desfase. Restaurar `device_services` del respaldo |
| `2025_01_31_093606_add_phone_verified_at_to_users` | Columna nueva y la rellena con «ahora» en todos los usuarios (pisa `updated_at`) | — |
| `2026_08_20_000001_add_device_id_index_to_wisetrack_table` | Índice sobre `wisetrack` (~694 mil filas) | Idempotente |
| `2026_10_06_000002_create_operations_tables` | 9 tablas `operation_*` bajo un solo guard | Borrar a mano las `operation_*` creadas antes de reintentar; si no, se marca como hecha con tablas faltantes |
| `2026_10_20_000001_create_device_vehicle_classifications_table` | Copia tipo/estado de cada vehículo al dueño del tipo | Idempotente |
| `2026_10_20_000002_move_poi_map_icons_to_poi_folder` | Reescribe `map_icons.path` a `images/map_icons/poi/`. Entre el checkout y esta migración los iconos de POI salen rotos | Reejecutable |
| `2026_11_08_000001_add_destinations_to_backups_table` | Columnas nuevas y pone `backups.details = NULL` en todas las filas (contenían comandos con claves) | — |
| `2026_11_14_000002_grant_device_video_permission` | `device_video` a admins y a dueños de equipos con cámara (en producción aún no hay ninguno: solo admins) | Idempotente |
| `2026_11_15_000000_create_ai_assistant_permission` | Fila `ai_assistant` en 0/0/0 por usuario sin plan y por plan | Idempotente |
| `2026_11_23_000002_move_all_users_to_ui_v2` | `ui_version = 'v2'` en `users.settings` de **todos** | `down()` vacío |

Sin guard (dan «Duplicate column» o «table exists» si se reintentan tras un fallo parcial):
`2026_07_02_000001`, `2026_07_22_000001`, `2026_07_23_000001`, `2026_07_29_000001`,
`2026_09_20_000001`, `2026_09_28_000001`, `2026_11_16_000001`, `2026_09_05_000001`,
`2026_10_15_000001`, `2026_11_15_000001`, `2026_11_16_000000`, `2026_11_23_000001`.

No usar `migrate --pretend` (varias consultan datos dentro de `up()`), ni `migrate:rollback` como
vuelta atrás (§9).

### 5.7 Después de migrar

```bash
sudo -u apache mkdir -p storage/app/backup-tmp storage/app/backup-index
sudo -u apache php artisan map-icons:refresh --dry-run     # revisar qué iconos quitaría
sudo -u apache php artisan map-icons:refresh --generate    # registra los 12 pines v2
sudo -u apache php artisan server:translations             # reaplica storage/langs sobre resources/lang
cp -a /root/logo-pre-uiv2.png public/images/logo.png       # solo si el servidor tenía logo propio
sudo chown -R apache:apache storage bootstrap/cache public/images
sudo -u apache php artisan config:cache                    # solo si producción ya lo usaba; si no, config:clear
```

`map-icons:refresh` borra las filas de iconos cuyo archivo no existe y reasigna sus POI: mirar el
`--dry-run` antes, por si hay iconos subidos a mano fuera de git.

`server:translations` no avisa si falla por permisos; por eso va como `apache`.

### 5.8 Supervisor: worker nuevo `laravel-worker-backups`

Solo cambia `laravel-worker-core.ini`: añade el programa `laravel-worker-backups` (conexión
`redis_backups`, cola `backups`, `--timeout=7200`) y lo suma al grupo `laravel-core`.

```bash
INI=<ruta que devolvió el grep de §3>
sudo cp -a $INI $INI.bak
diff $INI /var/www/html/current/laravel-worker-core.ini    # solo debe aparecer lo nuevo
sudo cp /var/www/html/current/laravel-worker-core.ini $INI
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-core:* laravel-webservices:*
sudo supervisorctl status
```

Si el `diff` muestra ajustes propios del servidor (`numprocs`, rutas), trasladarlos al archivo nuevo
antes de copiar. Esperado: todos en `RUNNING`, incluido `laravel-worker-backups_00`. Según
`docs/backups-google-drive.md`, sin ese worker el botón «Subir» de backups deja de funcionar aunque
Drive esté apagado.

### 5.9 Volver a abrir

```bash
sudo systemctl reload httpd        # o restart de php-fpm, para OPcache
sudo -u apache php artisan up
```

`insert:run`, el worker `tracker` y el worker `video` los relanza el cron de `schedule:run` en el
minuto siguiente. No hay que reiniciar el servidor de sockets (`socket/socket.js` no cambió) ni
ejecutar `npm install`.

### 5.10 Lo que NO hay que hacer

- `npm install`, `gulp`, `gulp ui-v2`, `gulp scripts`, `gulp sass-all`: todos los compilados vienen en
  git y están al día.
- `gulp assets` o `gulp minify-js`: minifican los bundles versionados y ensucian el repositorio.
- Subir un número de versión de assets: `asset_resource()` añade `?t=<fecha del archivo>` solo.
- Tocar `.htaccess`.

---

## 6. Procesos, colas y cron

### 6.1 Crontab

No hay líneas nuevas. Todo cuelga del `schedule:run` de cada minuto. Lo recomendable es que corra como
`apache` (si corre como `root`, crea como `root` los temporales de backup y los archivos de vídeo).

### 6.2 Entradas nuevas del scheduler

| Comando | Frecuencia | Nota |
| ------- | ---------- | ---- |
| `queue:work redis --queue=video --tries=1 --timeout=3900` | cada minuto, sin solaparse | Worker de descargas de vídeo |
| `commands:dispatch-pending` | cada minuto | Comandos GPRS «enviar cuando esté en línea» |
| `operations:process` | cada minuto | Sale sin hacer nada con `OPERATIONS_ENABLED=false` |
| `integrations:status` | cada 10 min | Resumen para el panel de cumplimiento |
| `security:aggregate` | cada hora, minuto 20 | Sale con `SECURITY_ENABLED=false` |
| `video:clean-downloads` | diario | Borra descargas viejas |
| `passport:purge` | diario | Borra tokens vencidos. La primera pasada puede ser un `DELETE` grande en `oauth_access_tokens` |
| `ai:alerts-digest` | diario 07:00 | Solo con `AI_ALERTS_DIGEST=true` |

`operations:clean` lo llama `server:autoclean`. `backup:prune` corre tras cada backup correcto a Drive.

### 6.3 Colas y quién las atiende

| Cola | Worker | Estado |
| ---- | ------ | ------ |
| `tracker`, `default`, `service`, `send` | `laravel-worker-core.ini` | Sin cambios |
| `backups` (conexión `redis_backups`) | `laravel-worker-backups` | **Nuevo** (§5.8) |
| `video` | Lo lanza el scheduler | **Nuevo**, sin supervisor |
| `web-services-*` | `laravel-worker-webservices.ini` | Sin cambios |

Avisos:

- **Un solo worker de `video`.** La conexión `redis` tiene `retry_after=300` y una descarga dura lo que
  la grabación. Con dos workers, el segundo toma el trabajo a los 5 minutos y lo marca como fallido.
  No añadir un programa de supervisor para `video` sin quitar la línea del scheduler y usar una
  conexión con `retry_after` mayor que 3900.
- **`SendAlertReport`** (reportes adjuntos a alertas) va a `default`, que corta a los 90 s. Un reporte
  con ventana grande puede morir ahí.
- El `systemctl restart supervisord` de cada hora (ya existía) también reinicia
  `laravel-worker-backups`; una restauración larga que cruce el minuto 3 puede quedar cortada.
- **Backups e imágenes.** `images_path()` pasó de `/var/www/html/images` fijo a `public/images` del
  proyecto. Si el servidor guardaba imágenes en la ruta vieja, el backup deja de incluirlas.

---

## 7. Configuración después del despliegue

Nada de esto bloquea el arranque. Orden sugerido:

1. **Permisos.** `operations`, `security`, `tags`, `device.tags` y `flights_info` no tienen filas:
   nadie los tiene hasta que el admin los marque (Admin → Usuarios / Planes → Permisos). `ai_assistant`
   nace en 0/0/0. `device_video` solo lo tienen los admins.
2. **Operaciones.** `OPERATIONS_ENABLED=true`, recargar configuración y seguir
   `docs/guia-actualizacion-operaciones.md` §3 y §10.4.
3. **Seguridad.** `SECURITY_ENABLED=true`; el usuario abre la pantalla una vez y luego
   `php artisan security:aggregate --user=<id> --from=AAAA-MM-DD --to=AAAA-MM-DD` para el histórico.
4. **Talentus IA CHAT.** Admin → Configuración → Talentus IA CHAT: clave, modelos, cuotas por plan y
   tope en USD. Después, el permiso `ai_assistant` por usuario o plan. Comprobar sin gastar:
   `php artisan ai:compare --user=<id> --show-tools`.
5. **Backups a Google Drive.** `docs/backups-google-drive.md` desde el §1 (rclone, SELinux,
   `BACKUP_RCLONE_CONFIG`). Empezar con una carpeta de Drive desechable: la retención borra carpetas
   `backup_*` y `soloweb_*` de la ruta configurada.
6. **Vídeo JT1078.** `docs/video/GUIA-DESPLIEGUE.md` (escrita para otro proyecto: ignorar sus rutas, su
   «no ejecutar gulp» y su §2.7 sobre permisos). Requiere el fork de Traccar rama `talentus`, puertos
   6015/6263/6267, `ProxyPass /tracker-media/` en el VirtualHost 443,
   `setsebool -P httpd_can_network_connect 1`, `VIDEO_STREAM_URL` y `VIDEO_STREAM_SECRET`, y `ffmpeg`.
   Luego marcar «Tiene cámara» y los canales en cada equipo y dar `device_video` a quien corresponda.
7. **Hitos km** (tablas vacías tras migrar): `php artisan hitos:import` (usa los CSV de `storage/`).
8. **Modelos en Traccar** (solo sirve con el fork): `php artisan tracker:sync-models`.

El código nuevo funciona con el Traccar estándar: posiciones, alertas e integraciones leen `alarm` del
fork y, si no está, `io{ID}`. Solo el vídeo exige el fork.

---

## 8. Verificación

```bash
cd /var/www/html/current
sudo -u apache php artisan --version              # Laravel Framework 9.52.x
sudo -u apache php artisan migrate:status | grep -c Pending    # 0
sudo -u apache php artisan uiv2:smoke             # pide cada página y endpoint de la v2
sudo -u apache php artisan schedule:list | grep -E 'video|operations|security|integrations|dispatch-pending|passport'
sudo supervisorctl status
pgrep -af 'artisan (insert:run|queue:work)'
tail -n 50 storage/logs/laravel-$(date +%F).log
```

En el navegador:

1. Entrar con un usuario normal: debe aterrizar en `/ui/v2/live` con vehículos moviéndose (las posiciones van por
   sondeo; el socket solo trae avisos, chat y fotos de cámara).
2. «Versión anterior» en el menú lateral lleva al legacy; «Nueva UI» en la cabecera legacy vuelve.
3. Historial, un reporte, una alerta de prueba y el envío de un comando.
4. Iconos de POI visibles en el mapa.
5. Admin: listado de clientes y dispositivos, pantalla de backups.
6. **Enviar un correo de prueba.** El transporte pasó a Symfony Mailer; un SMTP con certificado
   inválido o en un puerto distinto de 465 puede comportarse distinto.
7. Un envío de SMS si se usa Plivo (la librería subió de la 1 a la 4).
8. Un PDF de reporte (snappy subió de versión).
9. Reenvíos: `storage/logs/worker-ws-*.log` sin errores nuevos y colas bajando.
10. Al día siguiente: que el backup de la noche haya terminado y `resources/lang` no haya perdido
    textos personalizados.

Durante los primeros días mirar `storage/logs/validaciones_tecnicas-*.log` (filtro GNSS en sombra)
antes de pensar en `GNSS_GLITCH_ENFORCE=true`.

---

## 9. Vuelta atrás

`migrate:rollback` no sirve: varias migraciones no restauran datos (fechas de servicios, `details` de
backups, `ui_version`, permisos concedidos). La vuelta atrás es restaurar el respaldo de §5.2.

```bash
cd /var/www/html/current
sudo -u apache php artisan down
sudo supervisorctl stop laravel-core:* laravel-webservices:*
git checkout -- resources/lang resources/original_lang
git checkout $(cat /root/commit-pre-uiv2)
rm -f bootstrap/cache/*.php
sudo -u apache composer install --no-dev --optimize-autoloader
cp -a /root/env-pre-uiv2 .env
# restaurar la base web desde /root/pre-uiv2-*.sql.gz
sudo cp $INI.bak $INI && sudo supervisorctl reread && sudo supervisorctl update
sudo -u apache php artisan view:clear
sudo -u apache php artisan config:cache          # si se usaba
sudo supervisorctl start laravel-core:* laravel-webservices:*
sudo -u apache php artisan up
```

Se pierde lo que los usuarios hayan cambiado en la web desde el respaldo. Las posiciones no se
pierden: viven en la base de Traccar, que el despliegue no toca.

Vueltas atrás parciales, sin tocar código:

| Problema | Acción |
| -------- | ------ |
| Operaciones o Seguridad dan problemas | `OPERATIONS_ENABLED=false` / `SECURITY_ENABLED=false` y recargar configuración |
| La IA gasta o falla | Apagar el interruptor en Admin → Talentus IA CHAT |
| Un usuario no se adapta a la v2 | «Versión anterior» en su menú (queda guardado en su cuenta) |
| Devolver a todos al legacy | Actualizar `ui_version` a `legacy` en `users.settings` por SQL; no hay comando |

---

## 10. Resumen de una página

1. En desarrollo: resolver la fusión con `master`, probar.
2. En el servidor, días antes: PHP ≥ 8.0.2, `git status`, puertos de socket, ruta del `.ini`, usuario del cron.
3. `artisan down` → `queue:restart` → `supervisorctl stop` → esperar a `insert:run`.
4. Respaldo de la base web, `.env`, `storage/langs`, logo y commit actual.
5. `git checkout -- resources/lang resources/original_lang` → traer el código.
6. `rm bootstrap/cache/*.php` → `composer install --no-dev -o` → `view:clear`.
7. `.env`: `APPLY_NETWORK_DATA=false`, `OPERATIONS_ENABLED`, `SECURITY_ENABLED`.
8. `migrate --force --step`.
9. Carpetas de backup, `map-icons:refresh`, `server:translations`, `chown`, `config:cache` si se usaba.
10. `laravel-worker-core.ini` nuevo → `reread` → `update` → `start`.
11. `reload httpd` → `artisan up`.
12. `uiv2:smoke`, `supervisorctl status`, correo de prueba, revisión en navegador.
