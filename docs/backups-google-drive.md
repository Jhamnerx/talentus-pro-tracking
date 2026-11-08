# Manual: rclone con Google Drive para backups en AlmaLinux 9

Pasos manuales, una sola vez, en el servidor de producción. Los comandos se ejecutan como `root` o
con `sudo`, salvo donde se indica. Los workers de `laravel-worker-*.ini` corren como `apache` y la
aplicación está en `/var/www/html/current`. Si tu servidor difiere, ajusta usuario y ruta.

## Despliegue obligatorio (aunque no uses Drive)

Esta versión cambia cómo se hacen los backups para todos los destinos, no solo para Drive. Ejecutar
en este orden:

1. **Espacio en disco.** Los temporales ahora se escriben a disco, hasta unos cientos de MB a la vez:

   ```bash
   df -h /var/www/html/current/storage
   ```

2. **Elegir el momento.** Desplegar fuera de la ventana del backup o sin ninguno en curso:

   ```sql
   select id, completed_at from backup_processes where completed_at is null;
   ```

   Un backup que se reanuda tras el despliegue queda como parcial.

3. **Migraciones y carpetas** (siempre como `apache`):

   ```bash
   cd /var/www/html/current
   sudo -u apache php artisan migrate --force
   sudo -u apache mkdir -p storage/app/backup-tmp storage/app/backup-index
   ```

4. **Worker nuevo `laravel-worker-backups`.** Localizar el `.ini` real que usa el servidor, instalar la
   versión nueva y recargar supervisor:

   ```bash
   grep -rl laravel-worker /etc/supervisord* /etc/supervisor* 2>/dev/null
   sudo cp laravel-worker-core.ini <ruta encontrada>
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl status | grep backups
   ```

   Esperado: `laravel-worker-backups_00` en `RUNNING`. **Sin ese worker el botón «Subir» deja de
   funcionar aunque Drive esté desactivado.**

5. **Caché de configuración:**

   ```bash
   sudo -u apache php artisan config:cache
   ```

   Ejecutar también `sudo -u apache php artisan route:cache` solo si producción usa rutas en caché.

## 1. Comprobar requisitos

```bash
cat /etc/almalinux-release
uname -m
which bash gzip zcat tar mysqldump ncftpput
```

Si falta `ncftpput`, instalar `ncftp` desde EPEL:

```bash
sudo dnf install -y epel-release
sudo dnf install -y ncftp
```

## 2. Instalar rclone

Paquete oficial `.rpm` para `x86_64` (si `uname -m` da `aarch64`, cambiar `amd64` por `arm64`):

```bash
sudo dnf install -y https://downloads.rclone.org/rclone-current-linux-amd64.rpm
rclone version
```

Se instala en `/usr/bin/rclone`. Para actualizarlo más adelante, repetir el `dnf install`.

## 3. Crear el cliente de Google

En https://console.cloud.google.com, con la cuenta de los 2 TB:

1. Crear un proyecto y habilitar **Google Drive API**.
2. En **Pantalla de consentimiento OAuth**, tipo «Externo». Publicar la aplicación para que quede
   **«En producción»**. En modo «Pruebas» el token caduca a los 7 días y los backups a Drive fallarían.
3. En **Credenciales**, crear un ID de cliente OAuth de tipo «Aplicación de escritorio».
   Guardar el *client ID* y el *client secret*.

Al autorizar, Google mostrará un aviso de aplicación no verificada. Es normal en una aplicación propia:
continuar desde «Configuración avanzada».

## 4. Instalar rclone en tu PC

El servidor no tiene navegador, así que la autorización se hace desde tu PC. Descargar rclone para
Windows de https://rclone.org/downloads/ y descomprimirlo. Conviene que sea una versión igual o cercana
a la del servidor.

## 5. Configurar el remoto

```bash
sudo mkdir -p /etc/rclone-backups
sudo rclone --config /etc/rclone-backups/rclone.conf config
```

Responder:

| Pregunta | Respuesta |
|---|---|
| `n/s/q` | `n` (remoto nuevo) |
| `name` | `gdrive` |
| `Storage` | `drive` |
| `client_id` / `client_secret` | Los del paso 3 |
| `scope` | `drive.file` |
| `service_account_file` | Vacío |
| `Edit advanced config?` | `n` |
| `Use web browser to automatically authenticate?` | `n` |

rclone mostrará un comando `rclone authorize "drive" "…"`. Copiarlo **tal cual** y ejecutarlo en el PC,
desde la carpeta donde descomprimiste rclone (en PowerShell, anteponer `.\`: `.\rclone authorize "drive" "…"`). Se abre el navegador: entrar con la cuenta de los 2 TB y
aceptar. Copiar el token que aparece en la consola del PC y pegarlo en el servidor.

Terminar con: `Configure this as a Shared Drive?` `n`, `Keep this "gdrive" remote?` `y`, y `q`.

## 6. Permisos

rclone reescribe el archivo al renovar el token, así que el usuario necesita escribir en él:

```bash
sudo chown -R apache:apache /etc/rclone-backups
sudo chmod 700 /etc/rclone-backups
sudo chmod 600 /etc/rclone-backups/rclone.conf
```

## 7. SELinux

```bash
getenforce
```

Si responde `Enforcing`, permitir que Apache use la configuración y salga a internet, necesario para
**Probar Drive** y **Descargar** desde el panel:

```bash
sudo dnf install -y policycoreutils-python-utils
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/etc/rclone-backups(/.*)?"
sudo restorecon -Rv /etc/rclone-backups
sudo setsebool -P httpd_can_network_connect 1
```

## 8. Comprobar la conexión

```bash
sudo -u apache rclone --config /etc/rclone-backups/rclone.conf about gdrive:
sudo -u apache rclone --config /etc/rclone-backups/rclone.conf mkdir gdrive:backup_plataforma
sudo -u apache rclone --config /etc/rclone-backups/rclone.conf lsf gdrive:
```

Esperado: el espacio de la cuenta y la carpeta `backup_plataforma/`.

## 9. Comprobar el usuario del cron

```bash
sudo grep -rl "schedule:run" /etc/crontab /etc/cron.d /var/spool/cron 2>/dev/null
```

En `/var/spool/cron/<usuario>` el usuario es el nombre del archivo; en `/etc/crontab` y `/etc/cron.d/*`
el usuario es una **columna de la propia línea** (la que va entre la hora y el comando). Lo
recomendable es que `schedule:run` corra como `apache`. Si corre como `root`, también
crea como `root` los temporales en `storage/app/`, y rclone podría dejar `rclone.conf` con otro dueño.
Después del primer backup, comprobar:

```bash
ls -l /etc/rclone-backups/rclone.conf
ls -ld /var/www/html/current/storage/app/backup-tmp /var/www/html/current/storage/app/backup-index
```

Si el dueño ya no es `apache`, mover `schedule:run` al usuario `apache` y arreglar el dueño de
`storage/app/backup-tmp`, `storage/app/backup-index` y `rclone.conf` (`chown -R apache:apache` sobre
cada uno; para `rclone.conf`, repetir el paso 6). Si no, **Probar Drive** y **Subir** fallarán por permisos.

## 10. Configurar la aplicación

Añadir a `.env`:

```
BACKUP_RCLONE_CONFIG=/etc/rclone-backups/rclone.conf
```

Recargar la configuración (el worker y las migraciones ya se hicieron en «Despliegue obligatorio»):

```bash
cd /var/www/html/current
sudo -u apache php artisan config:cache
```

## 11. Activar

En `/admin/backups/index`, marcar **Activar copia en Google Drive**, guardar y pulsar **Probar Drive**.

## 12. Conservar la copia más antigua del FTP

```bash
cd /var/www/html/current
sudo -u apache php artisan backup:drive-import-ftp backup_2026-10-19-1792368061
```

Usar la carpeta más antigua que siga en el FTP. Solo queda protegida (`.conservar`) si se copiaron todos
los archivos y pasaron la validación. Si el comando informa de fallos y devuelve error, no protege nada:
volver a ejecutarlo, ya que salta lo que ya está en Drive.

**Copias antiguas:** esas copias tienen el volcado de `gpswox_web` vacío (unos 20 bytes), que no pasa la
validación y cuenta como fallo. Para protegerlas igualmente por sus posiciones, usar
`--allow-invalid-database` (el comando muestra un aviso visible):

```bash
sudo -u apache php artisan backup:drive-import-ftp backup_2026-10-19-1792368061 --allow-invalid-database
```

Con esa opción, la copia en Drive solo sirve para recuperar **posiciones**, no la base de datos. Si
`images.tar.gz` no está en el FTP, el comando solo avisa. Los devices sin archivo de posiciones no cuentan
como fallo: no tenían posiciones.

## 13. Comprobar el primer backup

Tarda varias horas; conviene lanzarlo en una sesión que no se corte y como `apache`:

```bash
cd /var/www/html/current
sudo -u apache php artisan backup:mysql --force=1
```

Un backup forzado **no se reanuda solo**: si falla, hay que repetirlo o esperar al automático.

Al terminar:

- `/admin/backup` muestra `FTP: correcto` y `DRIVE: correcto`, y el tamaño de la base.
- `sudo -u apache rclone --config /etc/rclone-backups/rclone.conf ls gdrive:backup_plataforma/<carpeta> --max-depth 1`
  muestra `localhost-gpswox_web-db.sql.gz` con un tamaño real, no 20 bytes.

### Restaurar sin peligro

**El volcado incluye `CREATE DATABASE` y ``USE `gpswox_web` ``, así que `zcat archivo | mysql otra_base`
SOBRESCRIBE LA BASE REAL.** No lo ejecutes tal cual. Para probar una restauración, preferiblemente en
otro servidor, quitar esas dos líneas y cargar en una base nueva:

```bash
mysql -e 'CREATE DATABASE gpswox_web_prueba'
zcat localhost-gpswox_web-db.sql.gz | sed -e '/^CREATE DATABASE/d' -e '/^USE `/d' | mysql gpswox_web_prueba
mysql -e 'SELECT COUNT(*) FROM gpswox_web_prueba.users'
mysql -e 'DROP DATABASE gpswox_web_prueba'
```

Antes de ejecutarlo, comprobar con el archivo real que `sed` funciona; el resultado debe ser **0**:

```bash
zcat localhost-gpswox_web-db.sql.gz | sed -e '/^CREATE DATABASE/d' -e '/^USE `/d' | grep -c '^USE'
```

## Retención

Empezar siempre por `sudo -u apache php artisan backup:prune --dry-run`, que lista lo que borraría sin
tocar nada. La retención se ejecuta sola después de cada backup correcto en Drive que haya copiado
posiciones. El FTP no se limpia desde la aplicación.

**Nunca actives Drive con un `drive_path` compartido con otros datos:** la retención borra carpetas
`backup_*` y `soloweb_*` dentro de esa ruta. Mínimos aplicados: copias diarias 7 días y máximo 30 días.

## Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `invalid_grant` o token caducado a los 7 días | Pantalla de consentimiento en «Pruebas» | Publicarla «En producción» y repetir `rclone config reconnect gdrive:` con `--config` |
| `Failed to create file system` / `permission denied` en `rclone.conf` | Dueño o etiqueta incorrectos | Repetir los pasos 6 y 7 |
| **Probar Drive** falla, pero el comando con `sudo -u apache` funciona | SELinux bloquea a Apache | Paso 7; revisar `sudo ausearch -m avc -ts recent` |
| `rateLimitExceeded` | Límite de la API de Google | rclone reintenta solo; si persiste, confirmar que el remoto usa tu `client_id` |
| `storageQuotaExceeded` | Cuenta llena | Revisar `rclone about gdrive:` y la retención; vaciar la papelera de Drive si hay archivos de otras fuentes |
| No se ven en la web de Drive archivos de otras carpetas | Permiso `drive.file`: rclone solo ve lo que él creó | Es lo esperado |
