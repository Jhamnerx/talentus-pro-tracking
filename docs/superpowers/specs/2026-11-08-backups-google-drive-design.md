# Backups en Google Drive junto al FTP — Diseño

**Fecha:** 2026-11-08
**Rama:** `nuevo-tema` (se fusionará en `master` para subir a producción)

## Objetivo

Guardar cada backup a la vez en el FTP actual y en Google Drive (cuenta personal de 2 TB), para tener
al menos 365 días de historial de posiciones recuperable desde la ventana «Posiciones de respaldo»,
sin ampliar lo que guarda la base principal y sin perder ninguna función del FTP.

## Contexto comprobado

- Cada carpeta diaria es una **copia completa**: un archivo por device con todo su historial (unos 160
  días). `positions_11` de la carpeta del 19-oct va del 11-may al 18-oct. El agrupado en subcarpetas
  (`…/1000/100`, `…/1000/400`) es solo por id de traccar, no por día.
- Peso aproximado por copia: 14 GB de posiciones + ~0,2 GB de `gpswox_web` comprimida (estimado, porque
  aún no existe un volcado real).
- El backup de posiciones tarda ~6,4 h.
- `server:autoclean` (01:00) borra posiciones y eventos más antiguos que `db_clear.days`.
- El volcado de `gpswox_web` en el FTP pesa 20 bytes por el `|` de la contraseña sin comillas en
  `origin/master`. El arreglo (`fbbe9254`) ya está en `nuevo-tema`.
- Las tablas de posiciones tienen índice en `time`, así que `MIN(time)`/`MAX(time)` son baratos.

## Decisiones

| Tema | Decisión |
|---|---|
| Destinos | FTP y Drive a la vez en cada backup |
| Conexión con Drive | `rclone` con OAuth de la cuenta personal (una *service account* no tiene cuota ahí) |
| Generación | Archivo temporal por device: se genera una vez, se valida y se sube a cada destino |
| Frecuencia | Una copia al día, como hoy |
| Retención en Drive | Posiciones: 30 diarias + una cada 2 meses guardada 365 días. `gpswox_web` e imágenes: 365 días |
| Espacio previsto | ~580 GB |
| Retención en FTP | Sin cambios; la aplicación no borra nada ahí |
| Consulta de datos antiguos | Restaurar con «Subir» y consultar antes de que el limpiador los borre a la 01:00 |

**Fuera de alcance:** ampliar la retención de la base principal, restaurar en una base aparte, otros
destinos además de FTP y Drive, tests automáticos (el proyecto no tiene suite funcional).

## Arquitectura

```
Backuper (Database / Files / DevicesPositions)
   └─ BackupUploaderInterface::process($command, $process, $item)
        └─ MultiDestinationUploader
             1. comando | gzip -9 > temporal          (bash -o pipefail)
             2. valida el temporal
             3. por cada destino activo: upload(temporal, ruta remota)
             4. registra el resultado por destino y borra el temporal
```

Los Backuper casi no cambian: siguen entregando un comando que escribe en stdout.

### Componentes

| Unidad | Responsabilidad |
|---|---|
| `BackupDestination` (interfaz) | `name`, `check`, `upload`, `listFolders`, `exists`, `download`, `read` (el borrado solo existe en `DriveDestination`, porque en el FTP la aplicación no borra) |
| `BackupFtp` (existente) | Implementa `BackupDestination` con `ncftpput`/`FtpHandler`. Conserva rutas y carpetas |
| `DriveDestination` (nueva) | Implementa `BackupDestination` con rclone (`copyto`, `lsjson`, `purge`, `about`) |
| `MultiDestinationUploader` (nueva) | Flujo temporal → validar → subir; estado por destino |
| `BackupPathResolver` (nueva) | Rutas remotas (estáticos que hoy están en `BackupFtp`), usadas por todos |
| `BackupIndex` (nueva) | Rangos por device, escritura y lectura de `_completo.json`, caché local |
| `BackupRetention` + `backup:prune` (nuevas) | Limpieza en Drive |
| `BackupService` (existente) | Construye los destinos activos; `ProcessManager` deja de exigir `BackupFtp` |

Si el FTP no se puede configurar y Drive está activo, el backup sigue solo con Drive. Sin ningún
destino válido se comporta como hoy (reprograma `next_backup` y sale). El tipo `auto` (Hive) solo
aporta las credenciales del FTP; Drive es independiente.

### Rutas

Las mismas en los dos destinos: `backup_<fecha>-<ts>/<db>.sql.gz`, `images.tar.gz`,
`…/1000000000/…/positions_<traccar_id>.sql.gz`.

## Validación

Antes de subir:
- Todos los archivos: el comando de volcado terminó bien (`pipefail`), `gzip -t` correcto y tamaño > 0.
- `gpswox_web`: además, la última línea descomprimida contiene `-- Dump completed`.

Si la validación falla, el archivo no se sube y se aplica el manejo de errores de cada Backuper.

El volcado de `gpswox_web` añade `--routines` (la función `ST_DISTANCE_SPHERE_2D` se usa en `Geofence`)
y `--no-tablespaces`.

## Errores por destino

- Cada subida se reintenta hasta 3 veces (`ncftpput`: en la aplicación; rclone: `--retries 3`).
- Tras **5 archivos seguidos fallidos**, ese destino se deja de usar durante ese backup y el otro sigue.
- Columna nueva `backups.destinations` (`text`, con cast a array; portable a MariaDB 10.5):
  `{"ftp":{"status":"ok|partial|failed","uploaded":0,"failed":0,"last_error":""},"drive":{...}}`.
  Sobrevive a las reanudaciones.
- Si los dos destinos fallan: excepción, backup fallido y se reanuda a la hora siguiente, como hoy.
- Si un backup termina con algún destino distinto de `ok`, se envía correo a los administradores con
  `MailHelper`. También si Drive tiene menos del 10 % libre.

Los temporales van en `storage/app/backup-tmp/`, se borran en `finally` y los restos se limpian al
arrancar el comando.

## Índice `_completo.json`

- Por cada device respaldado se guardan `from`/`to` (`MIN(time)`/`MAX(time)`) en un archivo local por
  backup, que sobrevive a una reanudación.
- Al terminar, se sube `_completo.json` a cada destino **cuyo estado sea `ok`**:
  `{"backup_id":514,"created_at":"…","devices":{"11":{"from":"…","to":"…"}}}`.
- Marca la copia como completa y alimenta la columna «Historial».

## Retención y limpieza en Drive

`backup:prune` corre al terminar cada backup, **solo si el estado de Drive de ese backup es `ok`**. Si
los backups fallan, no se borra nada.

La antigüedad se calcula con la fecha del nombre de la carpeta. Para cada carpeta de Drive:

1. Si tiene `.conservar`: se salta.
2. Si tiene más de `retention_max_days`: se borra entera.
3. Si tiene más de `retention_daily_days` y no es de largo plazo: se borra la subcarpeta de posiciones
   (`1000000000/`), quedan `gpswox_web` e imágenes, y la carpeta se renombra de `backup_…` a `soloweb_…`.
   Así la ventana de restauración solo lista carpetas con posiciones, sin consultar cada una de las
   ~330 carpetas de solo `gpswox_web`. `backup:import` lista las dos.

**Largo plazo:** la carpeta más antigua con `_completo.json` de cada mes tal que
`(mes - 1) % retention_long_term_months == 0` (con 2: enero, marzo, mayo…).

- Se borra con `--drive-use-trash=false`, para que lo borrado no siga ocupando cuota.
- `backup:prune --dry-run` lista lo que borraría sin tocar nada. Cada borrado real queda en el log.
- Los ajustes viven en `settings('backups')`, con valores por defecto 30, 2 y 365.

## Restauración

### Ventana «Posiciones de respaldo»

(`DevicesPositionsBackupsController` y vistas `Frontend/Devices/positions_backups*`.)

- Lista unificada de carpetas de los dos destinos, con columnas **Carpeta**, **Historial**, **Origen**
  y **Acciones**.
- **Historial:** rango exacto desde el índice; sin índice, `≈ 160 días` antes de la fecha de la copia
  (`db_clear.days` si está definido).
- **Filtro por fecha:** deja solo las copias que contienen ese día para ese device.
- **Origen:** usa el FTP si tiene la copia; si no, o si falla, Drive.
- **Aviso** al subir si hay posiciones más antiguas que `db_clear.days`: se borrarán a la 01:00.
- Solo las carpetas con índice evitan la consulta por carpeta. Los índices se guardan en
  `storage/app/backup-index/`, porque una copia completa no cambia.

### Flujo de «Subir»

`DevicePositionsRestoreJob` (nuevo): descarga en segundo plano desde el origen, comprueba el gzip y
llama a `DevicePositionsImportJob`. La petición web responde al momento y el usuario recibe el aviso
de éxito o error. «Descargar» sigue siendo una petición directa.

Corre en la conexión `redis_backups` (cola `backups`, `retry_after` 7500) con su propio worker
(`--timeout=7200 --tries=1`). El worker de `default` corta los jobs a los 90 s y `retry_after=300`
los relanzaría en paralelo, así que un import largo no terminaría ahí.

### Correcciones en `DatabaseImportJob`

- `MYSQL_PWD` y `escapeshellarg` en el comando y la ruta.
- Avisa también del fallo y borra el temporal en todos los casos.
- Nombre de temporal único en `BackupFtp::downloadFile()` (hoy dos restauraciones se pisan).

### Comandos

- `backup:import` y `backup:delta-import`: opción `--destination=ftp|drive`; si no se indica, se
  pregunta. Conservan la opción de FTP personalizado.
- `backup:delta-positions`: FTP por defecto, con la misma opción. La ruta pasa a usar el id de traccar
  (hoy usa `Device->id`, y el import busca por id de traccar).

## Panel de administración

**`/admin/backups`:** el formulario FTP sigue igual. Sección nueva **Google Drive**: activar, remoto
(`gdrive`), carpeta (`backup_plataforma/`), los tres valores de retención, botón **Probar Drive** (sube
y borra un archivo pequeño) y espacio usado/libre (`rclone about --json`).

**`/admin/backup` (Últimas subidas):** estado por destino, tamaño de `gpswox_web`, y botón para
proteger o desproteger una copia (`.conservar`).

La columna `message` solo muestra «Subido con éxito» si todos los destinos activos quedaron en `ok`.

## Correcciones incluidas

| Fallo | Corrección |
|---|---|
| Caducidad de procesos a ~6,7 h, con el backup ya en ~6,4 h | `duration_expire` pasa a 20 s por device |
| Contraseñas en `backups.details` | Se quitan antes de guardar; una migración vacía `details` en las filas existentes |
| `BackupService::auto()` sobrescribe ajustes guardados en el panel durante el backup | Solo guarda `next_backup` |
| `BackupsController` usa la clase antigua `Tobuli\Helpers\Backup` | Pasa a `BackupService` |
| `ErrorTranslator::translate(\Exception)` con un `\Throwable` provoca `TypeError` | Acepta `\Throwable` |

## Puesta en marcha

Manual nuevo para AlmaLinux 9 en `docs/backups-google-drive.md`, redactado sobre lo ya implementado
(instalación, SELinux, permisos con el usuario `apache`, worker de supervisor y problemas frecuentes):

1. Instalar `rclone` en producción.
2. Proyecto propio en Google Cloud con *client ID*, pantalla de consentimiento **«En producción»** (en
   «Pruebas» el token caduca a los 7 días) y permiso `drive.file`.
3. Autorizar desde un PC con `rclone authorize "drive"` y pegar el token en el servidor.
4. `rclone.conf` fuera del repositorio, permisos `600`, como dueño el usuario de cron y colas. Ruta por
   `BACKUP_RCLONE_CONFIG` en `.env`.
5. Comando de una sola vez `backup:drive-import-ftp <carpeta>`: pasa una carpeta del FTP a Drive y la
   marca con `.conservar`, para la copia más antigua disponible.

## Verificación

Sin tests automáticos. En local, con MySQL encendido, el device 219 y una carpeta de pruebas en Drive:

- Backup completo a los dos destinos y validación de archivos.
- Clave con `|` y volcado fallido: no se sube nada y queda el error en el panel.
- Destino caído: el otro sigue y el estado refleja el fallo.
- `backup:prune --dry-run` con carpetas de distintas fechas y con `.conservar`.
- Restauración y descarga desde cada origen, y aviso de fallo.

En producción, tras desplegar: un backup completo, revisar en el FTP y en Drive que `gpswox_web` pese
lo esperado, y restaurar ese volcado en una base aparte para comprobar que se puede recuperar.

## Riesgos

- **Hasta fusionar `nuevo-tema` en `master`, `gpswox_web` sigue saliendo vacío en producción.** Mientras
  tanto hay que sacar volcados manuales.
- **La copia más antigua del FTP puede desaparecer antes del despliegue,** porque hay una limpieza
  externa. Conviene frenarla o descargar la copia.
- **La primera ejecución real del volcado de `gpswox_web` puede mostrar otro error** que hoy queda
  oculto.
- **Lentitud de Drive:** ~1.230 subidas por noche con rutas de 9 niveles. Se debe medir en la primera
  ejecución; si alarga mucho el backup, se ajustan los parámetros de rclone.
- **El tiempo extra frente a hoy es una estimación;** no se ha medido.
