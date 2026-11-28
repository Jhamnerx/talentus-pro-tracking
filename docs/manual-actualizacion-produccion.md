# Manual de actualización de producción (con copia de `gpswox_web`)

Guía práctica para la ventana de mantenimiento. Lleva `master` a producción: UI v2, Laravel 9,
Operaciones, Seguridad, vídeo, IA y backups nuevos.
El detalle de cada paso está en `docs/manual-despliegue-ui-v2-produccion.md`, el manual completo,
y aquí se cita como [MC §n].

Servidor: AlmaLinux 9, Apache, MariaDB 10.5, Redis, supervisor con usuario `apache`.
Aplicación en `/var/www/html`. Los comandos se ejecutan como `root` (sin `sudo -u apache`); por eso
antes de abrir se devuelven los archivos a `apache` con `chown` (§9).

> **Qué rama se despliega:** `master` (`origin/master`). Desde el 27-nov-2026 incluye
> `fix/live-paridad-legacy` (paridad del mapa en vivo con el legacy, historial nuevo, comandos GPRS).

---

## 0. Días antes

```bash
cd /var/www/html
php -v                                   # >= 8.0.2
git status --short                       # lo esperable: resources/lang y original_lang modificados
ls -l bootstrap/cache/                   # ¿existe config.php? => se usa config:cache (anótalo)
grep -E '^(SOCKET_PORT|SOCKET_SSL_PORT|APPLY_NETWORK_DATA|web_database|DB_USERNAME)' .env
grep -rl 'program:laravel-worker-tracker' /etc/supervisord* /etc/supervisor* 2>/dev/null   # ruta del .ini
which mariadb-dump gzip
```

Tamaño de la base web y espacio libre: la copia comprimida ocupa en torno al 15–25 % del tamaño en disco.

```bash
mariadb -u root -p -e "SELECT ROUND(SUM(data_length+index_length)/1024/1024) AS MB FROM information_schema.tables WHERE table_schema='gpswox_web';"
df -h /root /var/www/html
```

`-p` sin valor hace que MySQL pida la clave de `root` (`Enter password:`). Así la clave no queda en el
historial ni en la lista de procesos, y su `|` no la interpreta el shell. Se usa igual en todo el manual.

Decidir antes [MC §2.4]: la migración `move_all_users_to_ui_v2` pasa a **todos** los usuarios a
la v2 y no tiene vuelta atrás automática. Avisar también a los resellers con color o CSS propio.

---

## 1. Abrir la ventana: parar la aplicación

No debe haber un backup de la plataforma en curso:

```sql
SELECT id, backup_id, reserved_at FROM backup_processes
WHERE completed_at IS NULL AND failed_at IS NULL
  AND DATE_ADD(created_at, INTERVAL duration_expire SECOND) > NOW();   -- sin filas
```

```bash
cd /var/www/html
php artisan down
php artisan queue:restart
sudo supervisorctl status                                 # ver los nombres reales de los grupos
sudo supervisorctl stop laravel-core:* laravel-webservices:*
sleep 70; pgrep -af 'artisan (insert:run|queue:work)'    # debe quedar vacío
```

Ejecutar cada línea por separado. Si `stop` responde `ERROR (no such group)`, usar los nombres que
muestra `status` (por ejemplo `sudo supervisorctl stop 'laravel-worker-tracker:*'`), y los mismos en §8 y §10.

Las posiciones que llegan mientras tanto esperan en Redis y se procesan al volver. La base de
Traccar (posiciones) no se toca.

---

## 2. Copia de `gpswox_web` (obligatoria)

> El backup automático de producción **no sirve** como respaldo: con el código actual,
> `gpswox_web` sale vacío (20 bytes) porque la clave de la base contiene `|`. Por eso esta copia
> se hace a mano, con el usuario `root` de MySQL. La clave la pide el comando (`Enter password:`).

### 2.1 Volcado

Un solo comando, en una línea:

```bash
mkdir -p /root/respaldos && STAMP=$(date +%F-%H%M) && mariadb-dump -u root -p --single-transaction --quick --routines --triggers --hex-blob --max-allowed-packet=512M --default-character-set=utf8mb4 gpswox_web | gzip -6 > /root/respaldos/gpswox_web-$STAMP.sql.gz; echo "mysqldump=${PIPESTATUS[0]} gzip=${PIPESTATUS[1]}"
```

Al ver `Enter password:`, pegar la clave de `root` y pulsar Enter. Los dos valores del final deben
ser `0`. No cerrar la sesión hasta terminar: los pasos siguientes usan `$STAMP`.

Si se prefiere escribir la clave en el comando, va pegada a `-p` y entre comillas simples
(`-p'CLAVE'`), porque el `|` rompería el comando sin comillas. Pero así queda en el historial de bash.

- `--single-transaction`: copia consistente sin bloquear tablas (InnoDB).
- `--hex-blob`: protege los polígonos de geocercas y demás columnas binarias.
- Usar el nombre real de la base si `web_database` del `.env` no es `gpswox_web`.

### 2.2 Verificar la copia (no seguir sin esto)

```bash
F=/root/respaldos/gpswox_web-$STAMP.sql.gz
ls -lh $F                                         # tamaño razonable (MB, nunca bytes)
gzip -t $F && echo "gzip OK"
zcat $F | tail -n 1                               # "-- Dump completed on ..."
zcat $F | grep -c '^CREATE TABLE'                 # nº de tablas del volcado
mariadb -u root -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='gpswox_web' AND table_type='BASE TABLE';"
sha256sum $F > $F.sha256
```

Los dos conteos de tablas deben coincidir. Si falta `Dump completed` o el archivo pesa unos pocos
bytes, **no continuar**: repetir el volcado.

### 2.3 Resto del respaldo

```bash
cd /var/www/html
cp -a .env                    /root/respaldos/env-$STAMP
cp -a storage/langs           /root/respaldos/langs-$STAMP
cp -a public/images/logo.png  /root/respaldos/logo-$STAMP.png
git rev-parse HEAD          > /root/respaldos/commit-$STAMP
```

### 2.4 Sacar la copia del servidor

Una copia que solo existe en el mismo disco no protege de un fallo del servidor. Llevarla a otra
máquina, por ejemplo al servidor de backup o a tu PC:

```bash
# desde tu PC o desde el otro servidor
scp root@<IP-PRODUCCION>:/root/respaldos/gpswox_web-<STAMP>.sql.gz* .
sha256sum -c gpswox_web-<STAMP>.sql.gz.sha256
```

Con rclone ya configurado (backups a Drive):
`rclone copy /root/respaldos/ <remoto>:respaldos-manuales/ --include 'gpswox_web-*'`.

---

## 3. Código

```bash
cd /var/www/html
git checkout -- resources/lang resources/original_lang     # los reescribe server:translations cada noche
git status --short                                          # nada versionado modificado (salvo logo.png, ya respaldado)
git fetch origin
git checkout master && git pull --ff-only
git log --oneline -1                                        # anotar el commit desplegado
```

## 4. Dependencias y cachés de arranque

Borrar las cachés **antes** de `composer install`: el `config.php` viejo apunta a una clase que ya no
existe y cualquier `artisan` fallaría [MC §5.4].

```bash
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/packages.php bootstrap/cache/services.php
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
php artisan view:clear
php artisan route:clear
```

**No** ejecutar `cache:clear` (la caché es Redis y guarda los cerrojos del scheduler), ni
`passport:install`, `vendor:publish`, `storage:link`, `npm install` o `gulp`: los compilados vienen en git.

## 5. `.env`

Añadir (ver todas las opciones en [MC §4] y `.env.example`):

```dotenv
APPLY_NETWORK_DATA=false
OPERATIONS_ENABLED=false
SECURITY_ENABLED=false
```

## 6. Migraciones

```bash
php artisan migrate --force --step
php artisan migrate:status | grep -c Pending     # 0
```

Si una migración falla, **no** reintentar a ciegas: varias no son idempotentes. En particular,
`alter_device_services_dates_to_datetimes` correría dos veces el paso a UTC. Revisar la tabla
de [MC §5.6]; en el peor caso, restaurar la copia (§10).

## 7. Después de migrar

```bash
mkdir -p storage/app/backup-tmp storage/app/backup-index
php artisan map-icons:refresh --dry-run     # revisar antes
php artisan map-icons:refresh --generate
php artisan server:translations
cp -a /root/respaldos/logo-$STAMP.png public/images/logo.png   # solo si había logo propio
sudo chown -R apache:apache storage bootstrap/cache public/images
php artisan config:cache                    # solo si en §0 existía config.php
```

## 8. Supervisor (worker nuevo de backups)

```bash
INI=<ruta del .ini que dio el grep de §0>
sudo cp -a $INI $INI.bak
diff $INI /var/www/html/laravel-worker-core.ini   # solo debe aparecer laravel-worker-backups
sudo cp /var/www/html/laravel-worker-core.ini $INI
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start laravel-core:* laravel-webservices:*
sudo supervisorctl status                                  # todo RUNNING, incluido laravel-worker-backups_00
```

Si el `diff` muestra ajustes propios del servidor (`numprocs`, rutas), pasarlos al archivo nuevo antes de copiar.

## 9. Abrir y verificar

```bash
chown -R apache:apache storage bootstrap/cache public/images   # lo creado como root (logs, cachés) vuelve a apache
sudo systemctl reload httpd
php artisan up
php artisan --version                      # Laravel Framework 9.52.x
php artisan uiv2:smoke
pgrep -af 'artisan (insert:run|queue:work)'                # aparecen al minuto siguiente
tail -n 50 storage/logs/laravel-$(date +%F).log
chown -R apache:apache storage bootstrap/cache          # de nuevo: smoke y --version pueden crear el log del día como root
```

En el navegador, con un usuario normal:

- entra a `/ui/v2/live` y los vehículos se mueven;
- «Versión anterior» lleva al legacy;
- historial, un reporte, un comando y un correo de prueba funcionan;
- en Admin, clientes, dispositivos y backups cargan.

Al día siguiente, comprobar que el backup nocturno trae `gpswox_web` con tamaño real (ya no 20 bytes).

---

## 10. Vuelta atrás (restaurar la copia)

```bash
cd /var/www/html
php artisan down
sudo supervisorctl stop laravel-core:* laravel-webservices:*
git checkout -- resources/lang resources/original_lang
git checkout $(cat /root/respaldos/commit-<STAMP>)
rm -f bootstrap/cache/*.php
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
cp -a /root/respaldos/env-<STAMP> .env

# Restaurar gpswox_web (reemplaza el contenido actual de la base)
zcat /root/respaldos/gpswox_web-<STAMP>.sql.gz | mariadb -u root -p --max-allowed-packet=512M gpswox_web

sudo cp $INI.bak $INI && sudo supervisorctl reread && sudo supervisorctl update
php artisan view:clear
php artisan config:cache                    # si se usaba
sudo supervisorctl start laravel-core:* laravel-webservices:*
php artisan up
```

El volcado recrea cada tabla (`DROP TABLE IF EXISTS` + `CREATE`), pero **no borra** las tablas que
crearon las migraciones nuevas (`operation_*`, `ai_*`, etc.). Quedan huérfanas y no molestan al
código viejo.

Se pierde lo que los usuarios cambiaron en la web después de la copia. Las posiciones no se pierden:
están en `gpswox_traccar`, que no se toca.

---

## 11. Al terminar

- Dejar la copia en `/root/respaldos` al menos una semana.
- Anotar el commit desplegado y la hora de fin.
