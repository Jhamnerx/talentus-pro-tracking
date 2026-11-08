# Manual: rclone con Google Drive para backups en AlmaLinux 9

Pasos manuales, una sola vez, en el servidor de producción. Los comandos se ejecutan como `root` o
con `sudo`, salvo donde se indica. Los workers de `laravel-worker-*.ini` corren como `apache` y la
aplicación está en `/var/www/html/current`. Si tu servidor difiere, ajusta usuario y ruta.

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
desde la carpeta donde descomprimiste rclone. Se abre el navegador: entrar con la cuenta de los 2 TB y
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

La ruta `/var/spool/cron/<usuario>` indica el usuario. Si `schedule:run` corre como `root`, también
crea como `root` los temporales en `storage/app/`, y rclone podría dejar `rclone.conf` con otro dueño.
Después del primer backup, comprobar:

```bash
ls -l /etc/rclone-backups/rclone.conf
ls -ld /var/www/html/current/storage/app/backup-tmp /var/www/html/current/storage/app/backup-index
```

Si el dueño ya no es `apache`, repetir el `chown` del paso 6 y mover `schedule:run` al crontab de
`apache`, porque si no **Probar Drive** y **Subir** fallarán por permisos.

## 10. Configurar la aplicación

Añadir a `.env`:

```
BACKUP_RCLONE_CONFIG=/etc/rclone-backups/rclone.conf
```

Instalar el worker nuevo. La ruta de supervisor en AlmaLinux suele ser `/etc/supervisord.d/`:

```bash
cd /var/www/html/current
php artisan config:cache
php artisan migrate --force
sudo cp laravel-worker-core.ini /etc/supervisord.d/laravel-worker-core.ini
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status | grep backups
```

Esperado: `laravel-worker-backups_00` en `RUNNING`.

## 11. Activar

En `/admin/backups/index`, marcar **Activar copia en Google Drive**, guardar y pulsar **Probar Drive**.

## 12. Conservar la copia más antigua del FTP

```bash
cd /var/www/html/current
sudo -u apache php artisan backup:drive-import-ftp backup_2026-10-19-1792368061
```

Usar la carpeta más antigua que siga en el FTP. Solo queda protegida (`.conservar`) si se copiaron todos
los archivos. Si el comando informa de fallos y devuelve error, no protege nada: volver a ejecutarlo, ya
que salta lo que ya está en Drive.

## 13. Comprobar el primer backup

Tarda varias horas; conviene lanzarlo en una sesión que no se corte y como `apache`:

```bash
cd /var/www/html/current
sudo -u apache php artisan backup:mysql --force=1
```

Al terminar:

- `/admin/backup` muestra `FTP: correcto` y `DRIVE: correcto`, y el tamaño de la base.
- `sudo -u apache rclone --config /etc/rclone-backups/rclone.conf ls gdrive:backup_plataforma/<carpeta> --max-depth 1`
  muestra `localhost-gpswox_web-db.sql.gz` con un tamaño real, no 20 bytes.
- Restaurar ese volcado en una base aparte y comprobar que se puede leer.

## Retención

`php artisan backup:prune --dry-run` lista lo que borraría sin tocar nada. Se ejecuta sola después de
cada backup correcto en Drive. El FTP no se limpia desde la aplicación.

## Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `invalid_grant` o token caducado a los 7 días | Pantalla de consentimiento en «Pruebas» | Publicarla «En producción» y repetir `rclone config reconnect gdrive:` con `--config` |
| `Failed to create file system` / `permission denied` en `rclone.conf` | Dueño o etiqueta incorrectos | Repetir los pasos 6 y 7 |
| **Probar Drive** falla, pero el comando con `sudo -u apache` funciona | SELinux bloquea a Apache | Paso 7; revisar `sudo ausearch -m avc -ts recent` |
| `rateLimitExceeded` | Límite de la API de Google | rclone reintenta solo; si persiste, confirmar que el remoto usa tu `client_id` |
| `storageQuotaExceeded` | Cuenta llena | Revisar `rclone about gdrive:` y la retención; vaciar la papelera de Drive si hay archivos de otras fuentes |
| No se ven en la web de Drive archivos de otras carpetas | Permiso `drive.file`: rclone solo ve lo que él creó | Es lo esperado |
