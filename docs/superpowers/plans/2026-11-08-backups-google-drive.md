# Backups en Google Drive junto al FTP — Plan de implementación

> **Para agentes:** SUB-SKILL REQUERIDA: usar superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para ejecutar este plan tarea por tarea. Los pasos usan casillas (`- [ ]`).

**Objetivo:** subir cada backup a la vez al FTP y a Google Drive, con retención automática en Drive y restauración desde cualquiera de los dos, sin perder ninguna función del FTP.

**Arquitectura:** cada archivo se genera una vez en un temporal local, se valida y se sube a cada destino mediante la interfaz `BackupDestination` (`BackupFtp` con `ncftpput`, `DriveDestination` con rclone). Los Backuper siguen entregando un comando a `BackupUploaderInterface`, ahora implementada por `MultiDestinationUploader`. Al terminar, `BackupFinalizer` publica el índice `_completo.json`, avisa de errores y aplica la retención.

**Stack:** Laravel 8, PHP 8, Symfony Process, rclone, jQuery + Bootstrap 3 (admin clásico).

**Especificación:** `docs/superpowers/specs/2026-11-08-backups-google-drive-design.md`

## Reglas para ejecutar este plan

- **Rama:** `nuevo-tema`. No hacer push hasta terminar la Tarea 15: las tareas 3 a 12 dejan consumidores a medio migrar entre commits.
- **Sin tests automáticos:** es preferencia del proyecto y no hay suite funcional. Cada tarea se comprueba con `php -l` y los pasos manuales indicados. No crear archivos en `tests/`.
- **Windows:** el flujo de volcado usa `bash -o pipefail`, `mysqldump`, `gzip` y `ncftpput`, y `escapeshellarg` en Windows no produce comillas válidas para bash. El flujo completo se prueba en Linux (producción o WSL). En Windows sí se puede probar todo lo de rclone, porque se invoca con arrays y sin shell.
- **No usar `php artisan tinker`** en Windows: se cuelga.
- **Producción usa MariaDB 10.5:** la migración usa tipos portables.
- **Todo commit termina con** `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Mapa de archivos

**Crear:**

| Archivo | Responsabilidad |
|---|---|
| `database/migrations/2026_11_08_000001_add_destinations_to_backups_table.php` | Columnas nuevas y vaciado de `details` |
| `resources/lang/es/backups.php`, `resources/lang/en/backups.php` | Textos nuevos |
| `Tobuli/Helpers/Backup/BackupPathResolver.php` | Rutas remotas |
| `Tobuli/Helpers/Backup/BackupCommandRunner.php` | Ejecutar procesos manteniendo viva la reserva |
| `Tobuli/Helpers/Backup/BackupSecrets.php` | Quitar contraseñas de los mensajes |
| `Tobuli/Helpers/Backup/BackupTempFile.php` | Generar y validar temporales |
| `Tobuli/Helpers/Backup/Destination/BackupDestination.php` | Interfaz de destino |
| `Tobuli/Helpers/Backup/Destination/DriveDestination.php` | Destino rclone |
| `Tobuli/Helpers/Backup/BackupIndex.php` | Rangos y `_completo.json` |
| `Tobuli/Helpers/Backup/Uploader/MultiDestinationUploader.php` | Temporal → validar → subir, estado por destino |
| `Tobuli/Helpers/Backup/BackupNotifier.php` | Correos a administradores |
| `Tobuli/Helpers/Backup/BackupRetention.php` | Reglas de limpieza en Drive |
| `Tobuli/Helpers/Backup/BackupFinalizer.php` | Cierre del backup |
| `Tobuli/Helpers/Backup/BackupCatalog.php` | Copias disponibles por device y descarga |
| `app/Console/Commands/BackupPruneCommand.php` | `backup:prune` |
| `app/Console/Commands/BackupDriveImportFtpCommand.php` | `backup:drive-import-ftp` |
| `app/Jobs/DevicePositionsRestoreJob.php` | Descargar e importar en segundo plano |
| `docs/backups-google-drive.md` | Guía de puesta en marcha |

**Modificar:** `Tobuli/Entities/Backup.php`, `config/tobuli.php`, `Tobuli/Helpers/Backup/Uploader/BackupFtp.php`, `Tobuli/Helpers/Backup/Uploader/BackupUploaderInterface.php` (sin cambios de firma), `Tobuli/Helpers/Backup/Process/{DatabaseBackuper,DevicesPositionsBackuper,FilesBackuper}.php`, `Tobuli/Helpers/Backup/BackupService.php`, `Tobuli/Helpers/Backup/ProcessManager.php`, `Tobuli/Helpers/Backup/ErrorTranslator.php`, `app/Console/Kernel.php`, `app/Console/Commands/{BackupMysqlCommand,BackupImportCommand,DeltaPositionsBackupCommand,DeltaPositionsImportCommand}.php`, `app/Jobs/{DatabaseImportJob,DevicePositionsImportJob}.php`, `app/Http/Controllers/Admin/{DevicesPositionsBackupsController,BackupsController,BackupController}.php`, `Tobuli/Validation/AdminBackupsFormValidator.php`, `routes/web.php`, `Tobuli/Views/Frontend/Devices/positions_backups{,_table}.blade.php`, `Tobuli/Views/Admin/Backups/panel.blade.php`, `Tobuli/Views/Admin/Backup/table.blade.php`.

---

### Tarea 1: Migración, entidad, ajustes y textos

**Archivos:**
- Crear: `database/migrations/2026_11_08_000001_add_destinations_to_backups_table.php`
- Crear: `resources/lang/es/backups.php`, `resources/lang/en/backups.php`
- Modificar: `Tobuli/Entities/Backup.php`, `config/tobuli.php`

- [ ] **Paso 1: Crear la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('backups', function (Blueprint $table) {
            if (!Schema::hasColumn('backups', 'destinations')) {
                $table->text('destinations')->nullable()->after('details');
            }

            if (!Schema::hasColumn('backups', 'database_bytes')) {
                $table->unsignedBigInteger('database_bytes')->nullable()->after('details');
            }

            if (!Schema::hasColumn('backups', 'protected')) {
                $table->boolean('protected')->default(false)->after('details');
            }
        });

        // Los detalles antiguos contienen el comando completo, con claves de la base y del FTP.
        DB::table('backups')->whereNotNull('details')->update(['details' => null]);
    }

    public function down()
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['destinations', 'database_bytes', 'protected']);
        });
    }
};
```

- [ ] **Paso 2: Actualizar `Tobuli/Entities/Backup.php`**

Reemplazar `$fillable` y añadir `$casts`:

```php
    protected $fillable = [
        'name',
        'message',
        'details',
        'launcher',
        'destinations',
        'database_bytes',
        'protected',
    ];

    protected $casts = [
        'destinations' => 'array',
        'protected'    => 'boolean',
    ];
```

Añadir al final de la clase, antes de la última llave:

```php
    public const DESTINATION_OK = 'ok';
    public const DESTINATION_PARTIAL = 'partial';
    public const DESTINATION_FAILED = 'failed';

    public function destinationStatus(string $name): ?string
    {
        $state = $this->destinations[$name] ?? null;

        if ($state === null) {
            return null;
        }

        if (empty($state['failed']) && empty($state['disabled'])) {
            return self::DESTINATION_OK;
        }

        return empty($state['uploaded']) ? self::DESTINATION_FAILED : self::DESTINATION_PARTIAL;
    }
```

- [ ] **Paso 3: Añadir ajustes en `config/tobuli.php`**

Reemplazar el bloque `'backups' => [ ... ]` (línea ~1469) por:

```php
    'backups' => [
        'type'         => 'auto',
        'period'       => 1,
        'hour'         => '00:00',
        'ftp_server'   => null,
        'ftp_username' => null,
        'ftp_password' => null,
        'ftp_port'     => null,
        'ftp_path'     => null,
        'drive_enabled'              => false,
        'drive_remote'               => 'gdrive',
        'drive_path'                 => 'backup_plataforma/',
        'retention_daily_days'       => 30,
        'retention_long_term_months' => 2,
        'retention_max_days'         => 365,
    ],

    'backups_rclone_config' => env('BACKUP_RCLONE_CONFIG'),
```

- [ ] **Paso 4: Crear `resources/lang/es/backups.php`**

```php
<?php

return [
    'destinations'          => 'Destinos',
    'database_size'         => 'Tamaño BD',
    'history'               => 'Historial',
    'origin'                => 'Origen',
    'filter_by_date'        => 'Copias que contienen el día',
    'approximate'           => 'aprox.',
    'restore_warning'       => 'Esta copia tiene posiciones anteriores al :date. Se borrarán esta noche a las 01:00. ¿Continuar?',
    'completed_with_errors' => 'Completado con errores: :list',
    'status_ok'             => 'correcto',
    'status_partial'        => 'parcial',
    'status_failed'         => 'falló',
    'protect'               => 'Proteger',
    'unprotect'             => 'Quitar protección',
    'protected'             => 'Protegida',
    'drive_title'           => 'Google Drive',
    'drive_enabled'         => 'Activar copia en Google Drive',
    'drive_remote'          => 'Remoto de rclone',
    'drive_path'            => 'Carpeta en Drive',
    'retention_daily_days'  => 'Días de copias diarias',
    'retention_long_term_months' => 'Guardar una copia de largo plazo cada (meses)',
    'retention_max_days'    => 'Días máximos de conservación',
    'test_drive'            => 'Probar Drive',
    'drive_ok'              => 'Drive correcto. Usado: :used GB. Libre: :free GB.',
    'drive_failed'          => 'No se pudo usar Google Drive: :error',
    'drive_unavailable'     => 'Google Drive no está activado.',
    'mail_problems_subject' => 'Backup con errores: :name',
    'mail_problems_body'    => "El backup :name terminó con problemas:\n\n:list",
    'mail_failed_subject'   => 'Backup fallido: :name',
    'mail_failed_body'      => "Falló un paso del backup :name:\n\n:error",
    'mail_space_subject'    => 'Poco espacio en Google Drive',
    'mail_space_body'       => 'Quedan :free GB libres de :total GB en la cuenta de Google Drive de los backups.',
    'mail_prune_subject'    => 'Falló la limpieza de backups en Drive',
];
```

- [ ] **Paso 5: Crear `resources/lang/en/backups.php`**

```php
<?php

return [
    'destinations'          => 'Destinations',
    'database_size'         => 'DB size',
    'history'               => 'History',
    'origin'                => 'Origin',
    'filter_by_date'        => 'Backups containing day',
    'approximate'           => 'approx.',
    'restore_warning'       => 'This backup has positions older than :date. They will be deleted tonight at 01:00. Continue?',
    'completed_with_errors' => 'Completed with errors: :list',
    'status_ok'             => 'ok',
    'status_partial'        => 'partial',
    'status_failed'         => 'failed',
    'protect'               => 'Protect',
    'unprotect'             => 'Remove protection',
    'protected'             => 'Protected',
    'drive_title'           => 'Google Drive',
    'drive_enabled'         => 'Enable Google Drive backup',
    'drive_remote'          => 'rclone remote',
    'drive_path'            => 'Drive folder',
    'retention_daily_days'  => 'Days of daily backups',
    'retention_long_term_months' => 'Keep a long-term backup every (months)',
    'retention_max_days'    => 'Maximum retention days',
    'test_drive'            => 'Test Drive',
    'drive_ok'              => 'Drive OK. Used: :used GB. Free: :free GB.',
    'drive_failed'          => 'Could not use Google Drive: :error',
    'drive_unavailable'     => 'Google Drive is not enabled.',
    'mail_problems_subject' => 'Backup with errors: :name',
    'mail_problems_body'    => "Backup :name finished with problems:\n\n:list",
    'mail_failed_subject'   => 'Backup failed: :name',
    'mail_failed_body'      => "A step of backup :name failed:\n\n:error",
    'mail_space_subject'    => 'Low space on Google Drive',
    'mail_space_body'       => ':free GB free of :total GB left on the backups Google Drive account.',
    'mail_prune_subject'    => 'Drive backup cleanup failed',
];
```

- [ ] **Paso 6: Comprobar y ejecutar**

Con MySQL local encendido:

```bash
php -l Tobuli/Entities/Backup.php
php artisan migrate --force
```

Esperado: `No syntax errors` y `Migrated: 2026_11_08_000001_add_destinations_to_backups_table`.

- [ ] **Paso 7: Commit**

```bash
git add database/migrations/2026_11_08_000001_add_destinations_to_backups_table.php resources/lang/es/backups.php resources/lang/en/backups.php Tobuli/Entities/Backup.php config/tobuli.php
git commit -m "feat(backups): columnas de destinos, ajustes de Drive y textos"
```

---

### Tarea 2: Utilidades de rutas, procesos, secretos y temporales

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/BackupPathResolver.php`, `BackupCommandRunner.php`, `BackupSecrets.php`, `BackupTempFile.php` (en `Tobuli/Helpers/Backup/`)

- [ ] **Paso 1: Crear `BackupPathResolver.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\Backup;
use Tobuli\Entities\BackupProcess;
use Tobuli\Entities\TraccarDevice;
use Tobuli\Helpers\Backup\Process\DatabaseBackuper;
use Tobuli\Helpers\Backup\Process\DevicesPositionsBackuper;
use Tobuli\Helpers\Backup\Process\FilesBackuper;

class BackupPathResolver
{
    /** Primer nivel de la jerarquía de posiciones dentro de una carpeta de backup. */
    public const POSITIONS_ROOT = '1000000000';

    private static array $subfolders = [];

    /**
     * @param Backup|string $backupDate
     */
    public static function backupFolder($backupDate): string
    {
        if ($backupDate instanceof Backup) {
            $backupDate = $backupDate->created_at;
        }

        $date = \Carbon::parse($backupDate)->format('Y-m-d');
        $timestamp = \Carbon::parse($backupDate)->timestamp;

        return "backup_$date-$timestamp";
    }

    /**
     * Ruta del archivo de un elemento, relativa a la carpeta del backup.
     */
    public static function itemPath(BackupProcess $process, $item): string
    {
        switch ($process->type) {
            case FilesBackuper::class:
                return basename($item) . '.tar.gz';
            case DatabaseBackuper::class:
                return self::databaseFile($item);
            case DevicesPositionsBackuper::class:
                return self::devicePositionsFile($item);
            default:
                throw new \InvalidArgumentException('Unsupported backup process type: ' . $process->type);
        }
    }

    public static function databaseFile(array $config): string
    {
        return "{$config['host']}-{$config['database']}-db.sql.gz";
    }

    public static function devicePositionsFile(TraccarDevice $device): string
    {
        $table = $device->positions()->getRelated()->getTable();

        return self::devicePositionsDir($device->id) . $table . '.sql.gz';
    }

    /**
     * Si id = 312456: 1000000000/100000000/10000000/1000000/400000/20000/3000/500/
     */
    public static function devicePositionsDir($id): string
    {
        $path = '';

        foreach (self::subfolders() as $i) {
            $path .= (ceil($id / $i) % 10 * $i) . '/';
        }

        return $path;
    }

    private static function subfolders(): array
    {
        if (self::$subfolders) {
            return self::$subfolders;
        }

        $decimal = '100';
        $dirs = [];

        while (strlen($decimal) <= 10) { // evita números de 20 dígitos, que se guardan como 1.0E+19
            array_unshift($dirs, $decimal);
            $decimal .= '0';
        }

        return self::$subfolders = $dirs;
    }
}
```

- [ ] **Paso 2: Crear `BackupCommandRunner.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tobuli\Entities\BackupProcess;

class BackupCommandRunner
{
    /**
     * Ejecuta una línea de shell con pipefail: sin él, "mysqldump | gzip" devolvería el código
     * del último comando y un volcado fallido parecería correcto.
     */
    public static function shell(string $command, ?BackupProcess $keepAlive = null, array $env = []): Process
    {
        $process = Process::fromShellCommandline('bash -o pipefail -c ' . escapeshellarg($command), null, $env ?: null);

        return self::run($process, $keepAlive);
    }

    /**
     * Ejecuta un comando sin shell, con sus argumentos ya separados.
     */
    public static function args(array $command, ?BackupProcess $keepAlive = null): Process
    {
        return self::run(new Process($command), $keepAlive);
    }

    private static function run(Process $process, ?BackupProcess $keepAlive): Process
    {
        $process->setTimeout(null);
        $process->start();

        $timer = 0;

        while ($process->isRunning()) {
            sleep(1);
            $timer++;

            if ($keepAlive && ($timer % BackupProcess::DURATION_ALIVE === 0)) {
                $keepAlive->updateReservation();
            }
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }
}
```

- [ ] **Paso 3: Crear `BackupSecrets.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

class BackupSecrets
{
    private const MASK = '***';

    /**
     * Quita contraseñas de un mensaje antes de guardarlo o enviarlo.
     */
    public static function scrub(string $text): string
    {
        foreach (self::knownSecrets() as $secret) {
            $text = str_replace([$secret, escapeshellarg($secret)], self::MASK, $text);
        }

        $patterns = [
            "/MYSQL_PWD='[^']*'/"   => 'MYSQL_PWD=' . self::MASK,
            "/-p\s*'[^']*'/"        => '-p ' . self::MASK,
            "/--password=[^\s\"]*/" => '--password=' . self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    private static function knownSecrets(): array
    {
        $secrets = [
            config('database.connections.mysql.password'),
            config('database.connections.traccar_mysql.password'),
            settings('backups.ftp_password'),
        ];

        return array_unique(array_filter($secrets, fn ($secret) => is_string($secret) && $secret !== ''));
    }
}
```

- [ ] **Paso 4: Crear `BackupTempFile.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\BackupProcess;

class BackupTempFile
{
    private const STALE_SECONDS = 86400;

    public static function path(string $name): string
    {
        $dir = storage_path('app/backup-tmp');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/' . uniqid('', true) . '-' . basename($name);
    }

    /**
     * Ejecuta "comando | gzip -9 > temporal" y devuelve la ruta del temporal.
     */
    public static function dump(string $command, string $name, ?BackupProcess $keepAlive = null): string
    {
        $path = self::path($name);

        try {
            BackupCommandRunner::shell($command . ' | gzip -9 > ' . escapeshellarg($path), $keepAlive);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * @throws \RuntimeException si el archivo está vacío, dañado o el volcado quedó a medias
     */
    public static function assertValid(string $path, bool $requireDumpEnd = false): void
    {
        clearstatcache(true, $path);

        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException('Backup file is empty: ' . basename($path));
        }

        try {
            BackupCommandRunner::shell('gzip -t ' . escapeshellarg($path));

            if ($requireDumpEnd) {
                BackupCommandRunner::shell(
                    'zcat ' . escapeshellarg($path) . " | tail -n 3 | grep -q 'Dump completed'"
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Backup file failed validation: ' . basename($path), 0, $e);
        }
    }

    /**
     * Borra restos de ejecuciones interrumpidas.
     */
    public static function cleanStale(): void
    {
        foreach (glob(storage_path('app/backup-tmp/*')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::STALE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
```

- [ ] **Paso 5: Comprobar sintaxis**

```bash
php -l Tobuli/Helpers/Backup/BackupPathResolver.php
php -l Tobuli/Helpers/Backup/BackupCommandRunner.php
php -l Tobuli/Helpers/Backup/BackupSecrets.php
php -l Tobuli/Helpers/Backup/BackupTempFile.php
```

Esperado: `No syntax errors detected` en los cuatro.

- [ ] **Paso 6: Commit**

```bash
git add Tobuli/Helpers/Backup/BackupPathResolver.php Tobuli/Helpers/Backup/BackupCommandRunner.php Tobuli/Helpers/Backup/BackupSecrets.php Tobuli/Helpers/Backup/BackupTempFile.php
git commit -m "feat(backups): utilidades de rutas, procesos, secretos y temporales"
```

---

### Tarea 3: Interfaz de destino y `BackupFtp`

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/Destination/BackupDestination.php`
- Modificar: `Tobuli/Helpers/Backup/Uploader/BackupFtp.php` (reemplazo completo)

- [ ] **Paso 1: Crear la interfaz**

```php
<?php

namespace Tobuli\Helpers\Backup\Destination;

use Tobuli\Entities\BackupProcess;

interface BackupDestination
{
    public const FTP = 'ftp';
    public const DRIVE = 'drive';

    public function name(): string;

    public function check(): bool;

    /**
     * @throws \Throwable si no se pudo subir
     */
    public function upload(string $localPath, string $remotePath, ?BackupProcess $keepAlive = null): void;

    /**
     * @return string[] nombres de carpeta de primer nivel que empiezan por $prefix
     */
    public function listFolders(string $prefix): array;

    public function exists(string $remotePath): bool;

    /**
     * @throws \Throwable si no se pudo descargar
     */
    public function download(string $remotePath, string $localPath): void;

    /**
     * @return string|null contenido del archivo, o null si no existe
     */
    public function read(string $remotePath): ?string;
}
```

- [ ] **Paso 2: Reemplazar `Tobuli/Helpers/Backup/Uploader/BackupFtp.php`**

```php
<?php

namespace Tobuli\Helpers\Backup\Uploader;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Tobuli\Clients\FtpHandler;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\BackupCommandRunner;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\FileMeta;

class BackupFtp implements BackupDestination
{
    private const UPLOAD_ATTEMPTS = 3;
    private const MISSING_DIR_EXIT_CODE = 14;

    private FtpHandler $handler;

    public function __construct($host, $user, $pass, $port, $path)
    {
        $this->handler = new FtpHandler($host, $user, $pass, $port, rtrim($path, '/') . '/');
    }

    public function name(): string
    {
        return self::FTP;
    }

    public function getHost()
    {
        return $this->handler->getHost();
    }

    public function check(): bool
    {
        return $this->handler->getConn() !== false;
    }

    public function testCommand(): void
    {
        BackupCommandRunner::shell('echo "test" | ' . $this->uploadCommand(time() . 'test.txt'));
    }

    public function upload(string $localPath, string $remotePath, ?BackupProcess $keepAlive = null): void
    {
        $command = $this->uploadCommand($remotePath) . ' < ' . escapeshellarg($localPath);

        for ($attempt = 1; ; $attempt++) {
            try {
                BackupCommandRunner::shell($command, $keepAlive);

                return;
            } catch (ProcessFailedException $e) {
                if ($e->getProcess()->getExitCode() === self::MISSING_DIR_EXIT_CODE) {
                    $this->createRootFolder($remotePath);
                }

                if ($attempt >= self::UPLOAD_ATTEMPTS) {
                    throw $e;
                }

                sleep(5);
            }
        }
    }

    /**
     * La carpeta raíz del backup puede no existir todavía.
     */
    private function createRootFolder(string $remotePath): void
    {
        $root = strtok($remotePath, '/');
        $cache = Cache::store('array');

        if ($cache->has("backups.$root.root_create")) {
            return;
        }

        $cache->put("backups.$root.root_create", 1);

        BackupCommandRunner::shell('echo -n | ' . $this->uploadCommand("$root/.empty"));
    }

    private function uploadCommand(string $remotePath): string
    {
        return 'ncftpput -m -c'
            . ' -u ' . escapeshellarg($this->handler->getUser())
            . ' -p ' . escapeshellarg($this->handler->getPass())
            . ' -P ' . escapeshellarg($this->handler->getPort())
            . ' ' . escapeshellarg($this->handler->getHost())
            . ' ' . escapeshellarg($this->handler->getPath() . $remotePath);
    }

    public function listFolders(string $prefix): array
    {
        $rows = $this->handler->rawlist($prefix . '*') ?: [];

        return array_map(
            fn ($row) => FileMeta::fromFtpRaw($row, $this->handler->getPath())->getName(),
            is_string($rows) ? [$rows] : $rows
        );
    }

    public function exists(string $remotePath): bool
    {
        return !empty($this->handler->rawlist($remotePath));
    }

    public function download(string $remotePath, string $localPath): void
    {
        $result = $this->handler->get($localPath, $remotePath);

        if ($result === false || !is_file($localPath) || filesize($localPath) === 0) {
            @unlink($localPath);

            throw new \RuntimeException("FTP download returned no data: $remotePath");
        }
    }

    public function read(string $remotePath): ?string
    {
        if (!$this->exists($remotePath)) {
            return null;
        }

        $localPath = BackupTempFile::path(basename($remotePath));

        try {
            $this->download($remotePath, $localPath);

            return file_get_contents($localPath);
        } finally {
            @unlink($localPath);
        }
    }
}
```

- [ ] **Paso 3: Comprobar sintaxis**

```bash
php -l Tobuli/Helpers/Backup/Destination/BackupDestination.php
php -l Tobuli/Helpers/Backup/Uploader/BackupFtp.php
```

Esperado: `No syntax errors detected`.

- [ ] **Paso 4: Commit**

```bash
git add Tobuli/Helpers/Backup/Destination/BackupDestination.php Tobuli/Helpers/Backup/Uploader/BackupFtp.php
git commit -m "refactor(backups): BackupFtp implementa la interfaz de destino"
```

---

### Tarea 4: `DriveDestination`

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/Destination/DriveDestination.php`

- [ ] **Paso 1: Crear la clase**

```php
<?php

namespace Tobuli\Helpers\Backup\Destination;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\BackupCommandRunner;

class DriveDestination implements BackupDestination
{
    private const NOT_FOUND_EXIT_CODES = [3, 4];
    private const TEST_FILE = '.prueba-backup';

    private string $remote;
    private string $path;
    private ?string $configPath;

    public function __construct(string $remote, string $path, ?string $configPath = null)
    {
        $this->remote = rtrim($remote, ':');
        $this->path = trim($path, '/');
        $this->configPath = $configPath ?: null;
    }

    public function name(): string
    {
        return self::DRIVE;
    }

    public function check(): bool
    {
        try {
            $this->about();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Sube y borra un archivo pequeño. Lanza excepción si algo falla.
     */
    public function test(): void
    {
        $this->put(self::TEST_FILE, (string)time());
        $this->deleteFile(self::TEST_FILE);
    }

    /**
     * @return array{total: float, used: float, free: float} en bytes
     */
    public function about(): array
    {
        $output = $this->rclone(['about', $this->remote . ':', '--json'])->getOutput();
        $data = json_decode($output, true) ?: [];

        return [
            'total' => (float)($data['total'] ?? 0),
            'used'  => (float)($data['used'] ?? 0),
            'free'  => (float)($data['free'] ?? 0),
        ];
    }

    public function upload(string $localPath, string $remotePath, ?BackupProcess $keepAlive = null): void
    {
        $this->rclone(['copyto', $localPath, $this->full($remotePath), '--retries', '3'], $keepAlive);
    }

    public function listFolders(string $prefix): array
    {
        $folders = array_map(fn ($name) => rtrim($name, '/'), $this->list('', ['--dirs-only']));

        return array_values(array_filter($folders, fn ($name) => str_starts_with($name, $prefix)));
    }

    /**
     * @return string[] nombres de archivo directamente dentro de la carpeta
     */
    public function listFiles(string $folder): array
    {
        return $this->list($folder, ['--files-only']);
    }

    public function exists(string $remotePath): bool
    {
        return !empty($this->list($remotePath, []));
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->rclone(['copyto', $this->full($remotePath), $localPath, '--retries', '3']);
    }

    public function read(string $remotePath): ?string
    {
        try {
            return $this->rclone(['cat', $this->full($remotePath)])->getOutput();
        } catch (ProcessFailedException $e) {
            if ($this->isNotFound($e)) {
                return null;
            }

            throw $e;
        }
    }

    public function put(string $remotePath, string $content): void
    {
        $local = \Tobuli\Helpers\Backup\BackupTempFile::path(basename($remotePath));
        file_put_contents($local, $content);

        try {
            $this->upload($local, $remotePath);
        } finally {
            @unlink($local);
        }
    }

    /**
     * Sin papelera: lo borrado seguiría ocupando la cuota de la cuenta.
     */
    public function purge(string $remotePath): void
    {
        try {
            $this->rclone(['purge', $this->full($remotePath), '--drive-use-trash=false']);
        } catch (ProcessFailedException $e) {
            if (!$this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    public function deleteFile(string $remotePath): void
    {
        try {
            $this->rclone(['deletefile', $this->full($remotePath), '--drive-use-trash=false']);
        } catch (ProcessFailedException $e) {
            if (!$this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    public function move(string $fromFolder, string $toFolder): void
    {
        $this->rclone(['moveto', $this->full($fromFolder), $this->full($toFolder)]);
    }

    private function list(string $path, array $flags): array
    {
        try {
            $output = $this->rclone(array_merge(['lsf', $this->full($path), '--max-depth', '1'], $flags))->getOutput();
        } catch (ProcessFailedException $e) {
            if ($this->isNotFound($e)) {
                return [];
            }

            throw $e;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
    }

    private function isNotFound(ProcessFailedException $e): bool
    {
        return in_array($e->getProcess()->getExitCode(), self::NOT_FOUND_EXIT_CODES, true);
    }

    private function full(string $remotePath): string
    {
        return $this->remote . ':' . trim($this->path . '/' . ltrim($remotePath, '/'), '/');
    }

    private function rclone(array $args, ?BackupProcess $keepAlive = null)
    {
        $command = array_merge(['rclone'], $this->configPath ? ['--config', $this->configPath] : [], $args);

        return BackupCommandRunner::args($command, $keepAlive);
    }
}
```

- [ ] **Paso 2: Comprobar con Drive real (Windows sirve)**

Requiere `rclone` instalado y un remoto `gdrive` autorizado (ver Tarea 15). Crear un script temporal en el scratchpad, fuera del repositorio:

```php
<?php
require 'C:/laragon/www/talentus-pro-tracking/vendor/autoload.php';
$app = require 'C:/laragon/www/talentus-pro-tracking/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$d = new Tobuli\Helpers\Backup\Destination\DriveDestination('gdrive', 'backup_pruebas/');
var_dump($d->check());
print_r($d->about());
$d->test();
$d->put('backup_2020-01-01-1/a.txt', 'hola');
print_r($d->listFolders('backup_'));
print_r($d->listFiles('backup_2020-01-01-1'));
var_dump($d->exists('backup_2020-01-01-1/a.txt'), $d->exists('backup_2020-01-01-1/no.txt'));
var_dump($d->read('backup_2020-01-01-1/a.txt'), $d->read('backup_2020-01-01-1/no.txt'));
$d->move('backup_2020-01-01-1', 'soloweb_2020-01-01-1');
print_r($d->listFolders('soloweb_'));
$d->purge('soloweb_2020-01-01-1');
print_r($d->listFolders('soloweb_'));
```

Esperado: `check` verdadero, espacio de la cuenta, la carpeta listada, `exists` verdadero/falso, `read` `hola`/`NULL`, carpeta renombrada y lista final vacía.

- [ ] **Paso 3: Commit**

```bash
git add Tobuli/Helpers/Backup/Destination/DriveDestination.php
git commit -m "feat(backups): destino Google Drive con rclone"
```

---

### Tarea 5: Índice `_completo.json`

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/BackupIndex.php`

- [ ] **Paso 1: Crear la clase**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\Backup;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

class BackupIndex
{
    public const FILE = '_completo.json';

    /**
     * Guarda el rango de un device durante el backup. Es un archivo local por backup, que
     * sobrevive a una reanudación.
     */
    public function record(int $backupId, int $traccarId, ?string $from, ?string $to): void
    {
        $line = json_encode(['id' => $traccarId, 'from' => $from, 'to' => $to]) . "\n";

        file_put_contents($this->rangesPath($backupId), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Sube el índice a un destino y lo deja en la caché local.
     */
    public function publish(Backup $backup, BackupDestination $destination): void
    {
        $content = json_encode([
            'backup_id'  => $backup->id,
            'created_at' => (string)$backup->created_at,
            'devices'    => $this->ranges($backup->id),
        ]);

        $folder = BackupPathResolver::backupFolder($backup);
        $local = BackupTempFile::path(self::FILE);
        file_put_contents($local, $content);

        try {
            $destination->upload($local, $folder . '/' . self::FILE);
        } finally {
            @unlink($local);
        }

        $this->writeCache($destination, $folder, $content);
    }

    public function forgetRanges(int $backupId): void
    {
        @unlink($this->rangesPath($backupId));
    }

    /**
     * @return array|null el índice de una carpeta, o null si esa copia no tiene
     */
    public function read(BackupDestination $destination, string $folder): ?array
    {
        $cache = $this->cachePath($destination, $folder);

        if (is_file($cache)) {
            return json_decode(file_get_contents($cache), true) ?: null;
        }

        $content = $destination->read($folder . '/' . self::FILE);

        if ($content === null) {
            return null;
        }

        $this->writeCache($destination, $folder, $content);

        return json_decode($content, true) ?: null;
    }

    private function ranges(int $backupId): array
    {
        $ranges = [];

        foreach (@file($this->rangesPath($backupId), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $row = json_decode($line, true);

            if ($row) {
                $ranges[$row['id']] = ['from' => $row['from'], 'to' => $row['to']];
            }
        }

        return $ranges;
    }

    private function rangesPath(int $backupId): string
    {
        return $this->directory() . "/ranges-$backupId.jsonl";
    }

    private function writeCache(BackupDestination $destination, string $folder, string $content): void
    {
        file_put_contents($this->cachePath($destination, $folder), $content);
    }

    private function cachePath(BackupDestination $destination, string $folder): string
    {
        $dir = $this->directory() . '/' . $destination->name();

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/' . basename($folder) . '.json';
    }

    private function directory(): string
    {
        $dir = storage_path('app/backup-index');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
```

- [ ] **Paso 2: Comprobar sintaxis**

```bash
php -l Tobuli/Helpers/Backup/BackupIndex.php
```

- [ ] **Paso 3: Commit**

```bash
git add Tobuli/Helpers/Backup/BackupIndex.php
git commit -m "feat(backups): índice de rangos por device en cada copia"
```

---

### Tarea 6: `MultiDestinationUploader`

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/Uploader/MultiDestinationUploader.php`

- [ ] **Paso 1: Crear la clase**

```php
<?php

namespace Tobuli\Helpers\Backup\Uploader;

use Tobuli\Entities\Backup;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupSecrets;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\Process\DatabaseBackuper;

class MultiDestinationUploader implements BackupUploaderInterface
{
    /** Código de excepción: ningún destino aceptó el archivo. No es un fallo del device. */
    public const ALL_DESTINATIONS_FAILED = 9001;

    private const MAX_CONSECUTIVE_FAILURES = 5;
    private const MAX_ERROR_LENGTH = 500;

    /** @var array<string, BackupDestination> */
    private array $destinations;

    private array $prepared = [];

    /**
     * @param array<string, BackupDestination> $destinations
     */
    public function __construct(array $destinations)
    {
        $this->destinations = $destinations;
    }

    /**
     * @return array<string, BackupDestination>
     */
    public function destinations(): array
    {
        return $this->destinations;
    }

    public function destination(string $name): ?BackupDestination
    {
        return $this->destinations[$name] ?? null;
    }

    public function check(): bool
    {
        foreach ($this->destinations as $destination) {
            if ($destination->check()) {
                return true;
            }
        }

        return false;
    }

    public function process($commands, BackupProcess $process, $item)
    {
        $backup = $process->backup;
        $state = $this->loadState($backup);
        $relative = BackupPathResolver::itemPath($process, $item);
        $remote = BackupPathResolver::backupFolder($backup) . '/' . $relative;
        $isDatabase = $process->type === DatabaseBackuper::class;

        $command = is_array($commands) ? implode(' | ', $commands) : $commands;
        $local = BackupTempFile::dump($command, basename($relative), $process);

        try {
            BackupTempFile::assertValid($local, $isDatabase);

            if ($isDatabase) {
                $backup->database_bytes = filesize($local);
            }

            $accepted = 0;

            foreach ($this->destinations as $name => $destination) {
                if (!empty($state[$name]['disabled'])) {
                    $state[$name]['failed']++;
                    continue;
                }

                try {
                    $destination->upload($local, $remote, $process);

                    $state[$name]['uploaded']++;
                    $state[$name]['consecutive'] = 0;
                    $accepted++;
                } catch (\Throwable $e) {
                    $state[$name]['failed']++;
                    $state[$name]['consecutive']++;
                    $state[$name]['last_error'] = mb_substr(
                        BackupSecrets::scrub($e->getMessage()), 0, self::MAX_ERROR_LENGTH
                    );

                    if ($state[$name]['consecutive'] >= self::MAX_CONSECUTIVE_FAILURES) {
                        $state[$name]['disabled'] = true;
                    }
                }
            }
        } finally {
            @unlink($local);
        }

        $backup->destinations = $state;
        $backup->save();

        if ($accepted === 0) {
            throw new \RuntimeException('No destination accepted the file: ' . $relative, self::ALL_DESTINATIONS_FAILED);
        }
    }

    private function loadState(Backup $backup): array
    {
        $state = $backup->destinations ?: [];

        foreach ($this->destinations as $name => $destination) {
            $state[$name] = ($state[$name] ?? []) + [
                'uploaded'    => 0,
                'failed'      => 0,
                'consecutive' => 0,
                'disabled'    => false,
                'last_error'  => '',
            ];
        }

        // Al reanudar: si todos los destinos estaban fuera de servicio, se vuelven a intentar.
        if (empty($this->prepared[$backup->id])) {
            $this->prepared[$backup->id] = true;

            $active = array_intersect_key($state, $this->destinations);

            if (!array_filter($active, fn ($row) => empty($row['disabled']))) {
                foreach (array_keys($active) as $name) {
                    $state[$name]['disabled'] = false;
                    $state[$name]['consecutive'] = 0;
                }
            }
        }

        return $state;
    }
}
```

- [ ] **Paso 2: Comprobar sintaxis**

```bash
php -l Tobuli/Helpers/Backup/Uploader/MultiDestinationUploader.php
```

- [ ] **Paso 3: Commit**

```bash
git add Tobuli/Helpers/Backup/Uploader/MultiDestinationUploader.php
git commit -m "feat(backups): subida a varios destinos desde un temporal validado"
```

---

### Tarea 7: Backupers

**Archivos:**
- Modificar: `Tobuli/Helpers/Backup/Process/DatabaseBackuper.php`, `DevicesPositionsBackuper.php`, `FilesBackuper.php`

- [ ] **Paso 1: Añadir opciones al volcado en `DatabaseBackuper.php`**

Reemplazar:

```php
            . " --lock-tables=false"
```

por:

```php
            . " --lock-tables=false"
            . " --routines"
            . " --no-tablespaces"
```

- [ ] **Paso 2: Actualizar `DevicesPositionsBackuper.php`**

Añadir el import:

```php
use Tobuli\Helpers\Backup\BackupIndex;
use Tobuli\Helpers\Backup\Uploader\MultiDestinationUploader;
```

Reemplazar la constante:

```php
    private const GENERAL_ERROR_CODES = [
        1045, // Access denied for user
        2002, // Connection refused / Connection timed out
        MultiDestinationUploader::ALL_DESTINATIONS_FAILED,
    ];
```

Reemplazar las líneas finales de `backup()`:

```php
        $cmd = "$this->cmdBase " . $positions->getRelated()->getTable();

        $this->uploader->process($cmd, $this->process, $item);

        (new BackupIndex())->record(
            $this->process->backup_id,
            $item->id,
            $item->positions()->min('time'),
            $item->positions()->max('time')
        );

        return true;
```

En `makeProcess()`, reemplazar:

```php
            'duration_expire'   => 10 * $total,
```

por:

```php
            'duration_expire'   => 20 * $total,
```

- [ ] **Paso 3: Escapar la ruta en `FilesBackuper.php`**

Reemplazar:

```php
        $command = 'tar -cv ' . $item;
```

por:

```php
        $command = 'tar -c ' . escapeshellarg($item);
```

Se quita `-v` porque el listado de archivos iría al buffer de memoria del proceso sin que nadie lo use.

- [ ] **Paso 4: Comprobar sintaxis**

```bash
php -l Tobuli/Helpers/Backup/Process/DatabaseBackuper.php
php -l Tobuli/Helpers/Backup/Process/DevicesPositionsBackuper.php
php -l Tobuli/Helpers/Backup/Process/FilesBackuper.php
```

- [ ] **Paso 5: Commit**

```bash
git add Tobuli/Helpers/Backup/Process
git commit -m "feat(backups): rutinas en el volcado, rangos por device y más margen de caducidad"
```

---

### Tarea 8: Notificador, retención, cierre y `backup:prune`

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/BackupNotifier.php`, `BackupRetention.php`, `BackupFinalizer.php`
- Crear: `app/Console/Commands/BackupPruneCommand.php`
- Modificar: `app/Console/Kernel.php`

- [ ] **Paso 1: Crear `BackupNotifier.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use CustomFacades\MailHelper;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Backup;
use Tobuli\Entities\User;

class BackupNotifier
{
    private const ADMIN_GROUP_ID = 1;

    public function notify(string $subject, string $body): void
    {
        $emails = User::where('group_id', self::ADMIN_GROUP_ID)
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        try {
            MailHelper::send($emails, $subject, nl2br(e(BackupSecrets::scrub($body))));
        } catch (\Throwable $e) {
            logger()->error('Backup notification failed: ' . BackupSecrets::scrub($e->getMessage()));
        }
    }

    /**
     * Un solo aviso por backup y motivo: un paso fallido se reintenta cada hora.
     */
    public function notifyOnce(Backup $backup, string $reason, string $subject, string $body): void
    {
        if (Cache::add("backup_notified.{$backup->id}.$reason", 1, 86400 * 7)) {
            $this->notify($subject, $body);
        }
    }
}
```

- [ ] **Paso 2: Crear `BackupRetention.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Carbon\Carbon;
use Tobuli\Helpers\Backup\Destination\DriveDestination;

class BackupRetention
{
    public const PROTECT_FILE = '.conservar';
    public const ACTION_PURGE = 'purge';
    public const ACTION_STRIP = 'strip';

    private const FULL_PREFIX = 'backup_';
    private const WEB_ONLY_PREFIX = 'soloweb_';

    private DriveDestination $drive;
    private int $dailyDays;
    private int $longTermMonths;
    private int $maxDays;

    public function __construct(DriveDestination $drive, array $settings)
    {
        $this->drive = $drive;
        $this->dailyDays = max(1, (int)($settings['retention_daily_days'] ?? 30));
        $this->longTermMonths = max(1, (int)($settings['retention_long_term_months'] ?? 2));
        $this->maxDays = max($this->dailyDays, (int)($settings['retention_max_days'] ?? 365));
    }

    /**
     * @return array<int, array{action: string, folder: string}>
     */
    public function plan(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $folders = [];

        foreach ($this->drive->listFolders(self::FULL_PREFIX) as $name) {
            $date = self::folderDate($name);

            if ($date) {
                $files = $this->drive->listFiles($name);

                $folders[$name] = [
                    'date'      => $date,
                    'complete'  => in_array(BackupIndex::FILE, $files, true),
                    'protected' => in_array(self::PROTECT_FILE, $files, true),
                ];
            }
        }

        $longTerm = $this->longTermFolders($folders);
        $plan = [];

        foreach ($folders as $name => $folder) {
            if ($folder['protected']) {
                continue;
            }

            $age = $folder['date']->diffInDays($now);

            if ($age > $this->maxDays) {
                $plan[] = ['action' => self::ACTION_PURGE, 'folder' => $name];
            } elseif ($age > $this->dailyDays && !in_array($name, $longTerm, true)) {
                $plan[] = ['action' => self::ACTION_STRIP, 'folder' => $name];
            }
        }

        foreach ($this->drive->listFolders(self::WEB_ONLY_PREFIX) as $name) {
            $date = self::folderDate($name);

            if ($date && $date->diffInDays($now) > $this->maxDays
                && !in_array(self::PROTECT_FILE, $this->drive->listFiles($name), true)
            ) {
                $plan[] = ['action' => self::ACTION_PURGE, 'folder' => $name];
            }
        }

        return $plan;
    }

    public function apply(array $plan): void
    {
        foreach ($plan as $step) {
            $folder = $step['folder'];

            if ($step['action'] === self::ACTION_PURGE) {
                $this->drive->purge($folder);
            } else {
                $this->drive->purge($folder . '/' . BackupPathResolver::POSITIONS_ROOT);
                $this->drive->move($folder, self::WEB_ONLY_PREFIX . substr($folder, strlen(self::FULL_PREFIX)));
            }

            logger()->info("Backup retention: {$step['action']} $folder");
        }
    }

    /**
     * La copia completa más antigua de cada mes elegido (con 2 meses: enero, marzo, mayo…).
     */
    private function longTermFolders(array $folders): array
    {
        ksort($folders);

        $byMonth = [];

        foreach ($folders as $name => $folder) {
            $month = (int)$folder['date']->format('n');

            if ($folder['complete'] && ($month - 1) % $this->longTermMonths === 0) {
                $byMonth[$folder['date']->format('Y-m')] = $byMonth[$folder['date']->format('Y-m')] ?? $name;
            }
        }

        return array_values($byMonth);
    }

    public static function folderDate(string $folder): ?Carbon
    {
        if (!preg_match('/^(?:backup|soloweb)_(\d{4}-\d{2}-\d{2})-\d+$/', $folder, $matches)) {
            return null;
        }

        return Carbon::parse($matches[1])->startOfDay();
    }
}
```

- [ ] **Paso 3: Crear `BackupFinalizer.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\Backup;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\Destination\DriveDestination;
use Tobuli\Helpers\Backup\Uploader\MultiDestinationUploader;

class BackupFinalizer
{
    private const LOW_SPACE_RATIO = 0.10;
    private const BYTES_IN_GB = 1073741824;

    private MultiDestinationUploader $uploader;
    private BackupIndex $index;
    private BackupNotifier $notifier;

    public function __construct(MultiDestinationUploader $uploader)
    {
        $this->uploader = $uploader;
        $this->index = new BackupIndex();
        $this->notifier = new BackupNotifier();
    }

    public function finalize(Backup $backup): void
    {
        $problems = [];
        $healthy = [];

        foreach ($this->uploader->destinations() as $name => $destination) {
            $status = $backup->destinationStatus($name);

            if ($status !== Backup::DESTINATION_OK) {
                $problems[] = $name . ': ' . trans('backups.status_' . ($status ?: Backup::DESTINATION_FAILED))
                    . ' (' . ($backup->destinations[$name]['failed'] ?? 0) . ') '
                    . ($backup->destinations[$name]['last_error'] ?? '');
                continue;
            }

            try {
                $this->index->publish($backup, $destination);
                $healthy[] = $name;
            } catch (\Throwable $e) {
                $problems[] = $name . ': ' . BackupSecrets::scrub($e->getMessage());
            }
        }

        $this->index->forgetRanges($backup->id);

        $backup->update([
            'message' => $problems
                ? trans('backups.completed_with_errors', ['list' => implode('; ', array_map(fn ($p) => strtok($p, ':'), $problems))])
                : trans('front.successfully_uploaded'),
            'details' => $problems ? BackupSecrets::scrub(implode("\n", $problems)) : null,
        ]);

        if ($problems) {
            $this->notifier->notifyOnce(
                $backup,
                'problems',
                trans('backups.mail_problems_subject', ['name' => $backup->name]),
                trans('backups.mail_problems_body', ['name' => $backup->name, 'list' => implode("\n", $problems)])
            );
        }

        if (in_array(BackupDestination::DRIVE, $healthy, true)) {
            $this->afterDriveSuccess($backup);
        }
    }

    private function afterDriveSuccess(Backup $backup): void
    {
        /** @var DriveDestination $drive */
        $drive = $this->uploader->destination(BackupDestination::DRIVE);

        try {
            $retention = new BackupRetention($drive, settings('backups') ?: []);
            $retention->apply($retention->plan());

            $this->checkSpace($backup, $drive);
        } catch (\Throwable $e) {
            $this->notifier->notifyOnce(
                $backup,
                'prune',
                trans('backups.mail_prune_subject'),
                $e->getMessage()
            );
        }
    }

    private function checkSpace(Backup $backup, DriveDestination $drive): void
    {
        $about = $drive->about();

        if ($about['total'] > 0 && $about['free'] / $about['total'] < self::LOW_SPACE_RATIO) {
            $this->notifier->notifyOnce(
                $backup,
                'space',
                trans('backups.mail_space_subject'),
                trans('backups.mail_space_body', [
                    'free'  => round($about['free'] / self::BYTES_IN_GB, 1),
                    'total' => round($about['total'] / self::BYTES_IN_GB, 1),
                ])
            );
        }
    }
}
```

- [ ] **Paso 4: Crear `app/Console/Commands/BackupPruneCommand.php`**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Helpers\Backup\BackupRetention;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

class BackupPruneCommand extends Command
{
    protected $signature = 'backup:prune {--dry-run : Solo lista lo que borraría}';

    protected $description = 'Aplica la retención de backups en Google Drive';

    public function handle(): int
    {
        $settings = settings('backups') ?: [];
        $service = new BackupService($settings);
        $destinations = $service->destinations();

        if (!isset($destinations[BackupDestination::DRIVE])) {
            $this->error(trans('backups.drive_unavailable'));

            return 1;
        }

        $retention = new BackupRetention($destinations[BackupDestination::DRIVE], $settings);
        $plan = $retention->plan();

        foreach ($plan as $step) {
            $this->line("{$step['action']}  {$step['folder']}");
        }

        $this->info(count($plan) . ' carpeta(s)');

        if (!$this->option('dry-run')) {
            $retention->apply($plan);
            $this->info('Limpieza aplicada');
        }

        return 0;
    }
}
```

- [ ] **Paso 5: Registrar el comando en `app/Console/Kernel.php`**

Reemplazar:

```php
        'App\Console\Commands\DeltaPositionsBackupCommand',
```

por:

```php
        'App\Console\Commands\DeltaPositionsBackupCommand',
        'App\Console\Commands\BackupPruneCommand',
```

- [ ] **Paso 6: Comprobar sintaxis**

```bash
php -l Tobuli/Helpers/Backup/BackupNotifier.php
php -l Tobuli/Helpers/Backup/BackupRetention.php
php -l Tobuli/Helpers/Backup/BackupFinalizer.php
php -l app/Console/Commands/BackupPruneCommand.php
```

- [ ] **Paso 7: Commit**

```bash
git add Tobuli/Helpers/Backup/BackupNotifier.php Tobuli/Helpers/Backup/BackupRetention.php Tobuli/Helpers/Backup/BackupFinalizer.php app/Console/Commands/BackupPruneCommand.php app/Console/Kernel.php
git commit -m "feat(backups): retención en Drive, cierre del backup y avisos por correo"
```

---

### Tarea 9: `BackupService`, `ProcessManager` y errores

**Archivos:**
- Modificar: `Tobuli/Helpers/Backup/BackupService.php`, `Tobuli/Helpers/Backup/ProcessManager.php`, `Tobuli/Helpers/Backup/ErrorTranslator.php`, `app/Console/Commands/BackupMysqlCommand.php`

- [ ] **Paso 1: Reemplazar `ErrorTranslator.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

class ErrorTranslator
{
    public function translate(\Throwable $e): string
    {
        return trans('front.unexpected_error');
    }
}
```

- [ ] **Paso 2: Reemplazar `ProcessManager.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Illuminate\Database\Eloquent\Builder;
use Tobuli\Entities\Backup;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\Process\AbstractBackuper;
use Tobuli\Helpers\Backup\Uploader\BackupUploaderInterface;
use Tobuli\Helpers\Backup\Uploader\MultiDestinationUploader;

class ProcessManager
{
    private MultiDestinationUploader $uploader;
    private Backup $backup;

    public function __construct(MultiDestinationUploader $uploader)
    {
        $this->uploader = $uploader;
    }

    public function handle(): void
    {
        $processes = $this->backup->processes()->get();

        foreach ($processes as $process) {
            if ($this->isProcessRunnable($process) === false) {
                continue;
            }

            try {
                $this->resolveBackuper($process, $this->uploader)->run();
            } catch (\Throwable $e) {
                $error = BackupSecrets::scrub($e->getCode() . ': ' . $e->getMessage());

                $this->backup->update([
                    'message' => (new ErrorTranslator())->translate($e),
                    'details' => $error,
                ]);

                (new BackupNotifier())->notifyOnce(
                    $this->backup,
                    'failed-' . $process->id,
                    trans('backups.mail_failed_subject', ['name' => $this->backup->name]),
                    trans('backups.mail_failed_body', ['name' => $this->backup->name, 'error' => $error])
                );

                throw $e;
            }
        }

        if ($this->backup->isCompleted()) {
            (new BackupFinalizer($this->uploader))->finalize($this->backup->refresh());
        }
    }

    private function isProcessRunnable(BackupProcess $process): bool
    {
        if ($process->isRunnable() === false) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        return (bool)BackupProcess::where('id', $process->id)
            ->where(fn (Builder $query) => $query
                ->where('reserved_at', $process->reserved_at)
                ->orWhereNull('reserved_at')
            )
            ->toBase()
            ->update(['reserved_at' => $now]);
    }

    public static function resolveBackuper(BackupProcess $process, BackupUploaderInterface $uploader): AbstractBackuper
    {
        return new $process->type($process, $uploader);
    }

    public function setBackup(Backup $backup): self
    {
        $this->backup = $backup;

        return $this;
    }
}
```

- [ ] **Paso 3: Actualizar `BackupService.php`: imports y propiedades**

Reemplazar los imports de `BackupFtp`:

```php
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\Destination\DriveDestination;
use Tobuli\Helpers\Backup\Uploader\BackupFtp;
use Tobuli\Helpers\Backup\Uploader\MultiDestinationUploader;
```

Añadir la propiedad, debajo de `protected Hive $hive;`:

```php
    private ?array $destinations = null;
```

- [ ] **Paso 4: Añadir la construcción de destinos en `BackupService.php`**

Añadir después de `setupFtp()`:

```php
    /**
     * @return array<string, BackupDestination> los destinos configurados
     */
    public function destinations(): array
    {
        if ($this->destinations !== null) {
            return $this->destinations;
        }

        $destinations = [];

        try {
            $destinations[BackupDestination::FTP] = $this->ftp();
        } catch (NoHiveConfigException | InvalidFtpConfigException $e) {
            // Sin FTP configurado, el backup puede seguir solo con Drive.
        }

        if (!empty($this->settings['drive_enabled'])) {
            $destinations[BackupDestination::DRIVE] = new DriveDestination(
                $this->settings['drive_remote'] ?? 'gdrive',
                $this->settings['drive_path'] ?? 'backup_plataforma/',
                config('tobuli.backups_rclone_config')
            );
        }

        return $this->destinations = $destinations;
    }

    public function destination(string $name): BackupDestination
    {
        $destinations = $this->destinations();

        if (!isset($destinations[$name])) {
            throw new \InvalidArgumentException("Backup destination `$name` is not available");
        }

        return $destinations[$name];
    }

    public function testDrive(): DriveDestination
    {
        /** @var DriveDestination $drive */
        $drive = $this->destination(BackupDestination::DRIVE);
        $drive->test();

        return $drive;
    }
```

- [ ] **Paso 5: Reemplazar `canRestorePositions()` y `auto()`**

```php
    public function canRestorePositions(): bool
    {
        return !$this->isAutoSettings() || $this->hive->hasBackupServer() || !empty($this->settings['drive_enabled']);
    }
```

```php
    public function auto(): bool
    {
        if (!$this->canStartAutoBackup()) {
            return false;
        }

        $destinations = $this->destinations();

        if (empty($destinations)) {
            $this->setNextBackup();
            return false;
        }

        $backup = $this->getCurrentAutoBackup();

        if (!$backup) {
            $this->setNextBackup();
            $backup = $this->createBackup(self::LAUNCHER_AUTO);
        }

        $processManager = new ProcessManager(new MultiDestinationUploader($destinations));

        try {
            $processManager->setBackup($backup)->handle();
        } catch (\Exception $e) {
            if (Arr::get($this->settings, 'type') == 'auto') {
                $hive = new Hive();
                $hive->backupServerError([
                    'code' => $e->getCode(),
                    'error' => BackupSecrets::scrub($e->getMessage()),
                ]);
            }

            return false;
        }

        return true;
    }
```

Se quita el `finally { $this->writeSettings(); }`: guardaba los ajustes leídos al arrancar y borraba cualquier cambio hecho en el panel durante el backup. `setNextBackup()` ya guarda lo que cambia.

- [ ] **Paso 6: Reemplazar `force()` y borrar `writeSettings()`**

```php
    public function force()
    {
        $destinations = $this->destinations();
        $uploader = new MultiDestinationUploader($destinations);

        if (!$destinations || !$uploader->check()) {
            throw new \Exception(trans('front.login_failed'));
        }

        $this->kill(self::LAUNCHER_FORCE);

        $backup = $this->createBackup(self::LAUNCHER_FORCE);

        (new ProcessManager($uploader))
            ->setBackup($backup)
            ->handle();
    }
```

Borrar el método completo `protected function writeSettings($retry = 0)`.

- [ ] **Paso 7: Limpiar temporales en `BackupMysqlCommand::handle()`**

Reemplazar:

```php
		$settings = settings('backups');
		$backup = new Backup\BackupService($settings);
```

por:

```php
		Backup\BackupTempFile::cleanStale();

		$settings = settings('backups');
		$backup = new Backup\BackupService($settings);
```

- [ ] **Paso 8: Comprobar sintaxis y buscar restos**

```bash
php -l Tobuli/Helpers/Backup/BackupService.php
php -l Tobuli/Helpers/Backup/ProcessManager.php
php -l Tobuli/Helpers/Backup/ErrorTranslator.php
php -l app/Console/Commands/BackupMysqlCommand.php
```

Con la herramienta de búsqueda, buscar `writeSettings` en `Tobuli/Helpers/Backup/`. Esperado: sin resultados.

- [ ] **Paso 9: Commit**

```bash
git add Tobuli/Helpers/Backup/BackupService.php Tobuli/Helpers/Backup/ProcessManager.php Tobuli/Helpers/Backup/ErrorTranslator.php app/Console/Commands/BackupMysqlCommand.php
git commit -m "feat(backups): el servicio usa varios destinos y deja de sobrescribir ajustes"
```

---

### Tarea 10: Jobs de importación y restauración

**Archivos:**
- Modificar: `app/Jobs/DatabaseImportJob.php`, `app/Jobs/DevicePositionsImportJob.php`, `config/queue.php`, `laravel-worker-core.ini`
- Crear: `app/Jobs/DevicePositionsRestoreJob.php`

**Por qué una cola propia:** el worker de la cola `default` corre con `--timeout=90` y la conexión `redis` tiene `retry_after=300`. Un job más largo muere a los 90 s, y si superara los 300 s se volvería a lanzar en paralelo. Importar un device con mucho historial puede tardar más que eso, así que la restauración necesita su propia conexión y su propio worker.

- [ ] **Paso 1: Actualizar `DatabaseImportJob.php`**

Reemplazar `runProcess()`, `buildCommand()` y `buildDatabaseImportCommand()` por:

```php
    protected function runProcess(): Process
    {
        // La clave va por entorno: con -p'clave' una clave vacía pide contraseña por terminal.
        $process = Process::fromShellCommandline(
            'bash -o pipefail -c ' . escapeshellarg($this->buildCommand()),
            null,
            ['MYSQL_PWD' => (string)$this->config['password']]
        );
        $process->setTimeout(0);
        $process->run();

        if ($process->isSuccessful()) {
            return $process;
        }

        throw new ProcessFailedException($process);
    }

    protected function buildCommand(): string
    {
        return self::buildDatabaseImportCommand($this->file, $this->config);
    }
```

```php
    public static function buildDatabaseImportCommand(string $file, array $config): string
    {
        $command = 'mysql'
            . ' -h ' . escapeshellarg($config['host'])
            . ' -u ' . escapeshellarg($config['username'])
            . ' --port=' . escapeshellarg($config['port'])
            . ' ' . escapeshellarg($config['database']);

        if (str_ends_with($file, '.gz')) {
            return 'zcat ' . escapeshellarg($file) . ' | ' . $command;
        }

        return $command . ' < ' . escapeshellarg($file);
    }
```

`handle()` no cambia: en caso de fallo la excepción sale con el archivo intacto, porque `DevicePositionsImportJob` puede reintentar.

- [ ] **Paso 2: Avisar del fallo en `DevicePositionsImportJob.php`**

Añadir imports:

```php
use App\Events\NoticeEvent;
use Illuminate\Support\Facades\File;
```

Reemplazar `handle()`:

```php
    public function handle(): void
    {
        $config = $this->device->positions()->getConnection()->getConfig();

        try {
            $this->import($config);
        } catch (\Throwable $e) {
            File::delete($this->file);

            if ($this->actor) {
                event(new NoticeEvent($this->actor, NoticeEvent::TYPE_ERROR, trans('front.upload_failed')));
            }

            throw $e;
        }
    }
```

- [ ] **Paso 3: Crear `app/Jobs/DevicePositionsRestoreJob.php`**

```php
<?php

namespace App\Jobs;

use App\Events\NoticeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;
use Tobuli\Helpers\Backup\BackupCatalog;
use Tobuli\Helpers\Backup\BackupService;

class DevicePositionsRestoreJob implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 1;

    public Device $device;
    public string $folder;
    public ?User $actor;

    public function __construct(Device $device, string $folder, ?User $actor)
    {
        $this->device = $device;
        $this->folder = $folder;
        $this->actor = $actor;

        $this->onConnection('redis_backups');
        $this->onQueue('backups');
    }

    public function handle(): void
    {
        $catalog = new BackupCatalog(new BackupService(settings('backups')));

        try {
            $file = $catalog->fetch($this->device, $this->folder);

            if ($file === null) {
                throw new \RuntimeException("Backup file not found in {$this->folder}");
            }
        } catch (\Throwable $e) {
            if ($this->actor) {
                event(new NoticeEvent($this->actor, NoticeEvent::TYPE_ERROR, trans('front.upload_failed')));
            }

            throw $e;
        }

        dispatch_sync(new DevicePositionsImportJob($this->device, $file, $this->actor));
    }
}
```

- [ ] **Paso 4: Añadir la conexión en `config/queue.php`**

Añadir debajo del bloque `'redis' => [ ... ],`:

```php
		// Trabajos largos de backup. retry_after debe superar el --timeout de su worker.
		'redis_backups' => [
			'driver' => 'redis',
			'queue'  => 'backups',
			'retry_after' => 7500,
			'after_commit' => false,
		],
```

- [ ] **Paso 5: Añadir el worker en `laravel-worker-core.ini`**

Insertar antes de `[group:laravel-core]`:

```ini
[program:laravel-worker-backups]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/current/artisan queue:work redis_backups --queue=backups --sleep=5 --tries=1 --timeout=7200 --max-jobs=50 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=apache
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/current/storage/logs/worker-backups.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=3
stopwaitsecs=7210
startsecs=3

```

Reemplazar:

```ini
programs=laravel-worker-tracker,laravel-worker-default,laravel-worker-service,laravel-worker-send
```

por:

```ini
programs=laravel-worker-tracker,laravel-worker-default,laravel-worker-service,laravel-worker-send,laravel-worker-backups
```

- [ ] **Paso 6: Comprobar sintaxis**

```bash
php -l app/Jobs/DatabaseImportJob.php
php -l app/Jobs/DevicePositionsImportJob.php
php -l app/Jobs/DevicePositionsRestoreJob.php
php -l config/queue.php
```

- [ ] **Paso 7: Commit**

```bash
git add app/Jobs/DatabaseImportJob.php app/Jobs/DevicePositionsImportJob.php app/Jobs/DevicePositionsRestoreJob.php config/queue.php laravel-worker-core.ini
git commit -m "fix(backups): el import usa MYSQL_PWD y avisa de los fallos; restauración en segundo plano"
```

---

### Tarea 11: Catálogo y ventana «Posiciones de respaldo»

**Archivos:**
- Crear: `Tobuli/Helpers/Backup/BackupCatalog.php`
- Modificar: `app/Http/Controllers/Admin/DevicesPositionsBackupsController.php`, `Tobuli/Views/Frontend/Devices/positions_backups.blade.php`, `positions_backups_table.blade.php`

- [ ] **Paso 1: Crear `BackupCatalog.php`**

```php
<?php

namespace Tobuli\Helpers\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

class BackupCatalog
{
    private const DEFAULT_HISTORY_DAYS = 160;
    private const FOLDERS_CACHE_SECONDS = 300;
    private const FOLDER_PREFIX = 'backup_';

    private BackupService $service;
    private BackupIndex $index;

    public function __construct(BackupService $service)
    {
        $this->service = $service;
        $this->index = new BackupIndex();
    }

    /**
     * Copias que tienen posiciones de este device, de la más reciente a la más antigua.
     *
     * @return array<int, array{name: string, from: ?string, to: ?string, approximate: bool, origins: string[]}>
     */
    public function entries(Device $device, ?string $date = null): array
    {
        $traccar = $device->traccar;
        $file = BackupPathResolver::devicePositionsFile($traccar);
        $entries = [];

        foreach ($this->service->destinations() as $name => $destination) {
            try {
                $folders = Cache::remember(
                    "backup_folders.$name",
                    self::FOLDERS_CACHE_SECONDS,
                    fn () => $destination->listFolders(self::FOLDER_PREFIX)
                );

                foreach ($folders as $folder) {
                    $range = $this->range($destination, $folder, $traccar->id, $file);

                    if ($range === null) {
                        continue;
                    }

                    if (!isset($entries[$folder]) || ($entries[$folder]['approximate'] && !$range['approximate'])) {
                        $entries[$folder] = ['name' => $folder, 'origins' => $entries[$folder]['origins'] ?? []] + $range;
                    }

                    $entries[$folder]['origins'][] = $name;
                }
            } catch (\Throwable $e) {
                // Si un destino no responde, se muestran las copias del otro.
                logger()->warning("Backup catalog: $name unavailable: " . BackupSecrets::scrub($e->getMessage()));
            }
        }

        if ($date) {
            $entries = array_filter($entries, fn ($entry) => $this->contains($entry, $date));
        }

        krsort($entries);

        return array_values($entries);
    }

    /**
     * Descarga el archivo de un device. El FTP tiene prioridad; si falla, se usa el otro destino.
     *
     * @return string|null ruta local, o null si no está en ningún destino
     */
    public function fetch(Device $device, string $folder): ?string
    {
        $relative = BackupPathResolver::devicePositionsFile($device->traccar);
        $remote = $folder . '/' . $relative;
        $lastError = null;

        foreach ($this->service->destinations() as $name => $destination) {
            $local = BackupTempFile::path(basename($relative));

            try {
                if (!$destination->exists($remote)) {
                    continue;
                }

                $destination->download($remote, $local);
                BackupTempFile::assertValid($local);

                return $local;
            } catch (\Throwable $e) {
                @unlink($local);
                $lastError = $e;
                logger()->warning("Backup restore: $name failed: " . BackupSecrets::scrub($e->getMessage()));
            }
        }

        if ($lastError) {
            throw $lastError;
        }

        return null;
    }

    public static function historyDays(): int
    {
        return (int)settings('db_clear.days') ?: self::DEFAULT_HISTORY_DAYS;
    }

    private function range(BackupDestination $destination, string $folder, int $traccarId, string $file): ?array
    {
        $index = $this->index->read($destination, $folder);

        if ($index !== null) {
            $device = $index['devices'][$traccarId] ?? null;

            return $device
                ? ['from' => $device['from'], 'to' => $device['to'], 'approximate' => false]
                : null;
        }

        if (!$destination->exists($folder . '/' . $file)) {
            return null;
        }

        $date = BackupRetention::folderDate($folder);

        return [
            'from'        => $date ? $date->copy()->subDays(self::historyDays())->toDateTimeString() : null,
            'to'          => $date ? $date->toDateTimeString() : null,
            'approximate' => true,
        ];
    }

    private function contains(array $entry, string $date): bool
    {
        if (!$entry['from'] || !$entry['to']) {
            return false;
        }

        $day = Carbon::parse($date)->format('Y-m-d');

        return substr($entry['from'], 0, 10) <= $day && $day <= substr($entry['to'], 0, 10);
    }
}
```

- [ ] **Paso 2: Reemplazar `DevicesPositionsBackupsController.php`**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PermissionException;
use App\Jobs\DevicePositionsRestoreJob;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Device;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\Backup\BackupCatalog;
use Tobuli\Helpers\Backup\BackupService;

class DevicesPositionsBackupsController extends BaseController
{
    private const PAGE_SIZE = 20;
    private const FOLDER_RULE = 'required|regex:/^backup_[0-9-]+$/';

    private BackupService $service;

    public function __construct()
    {
        parent::__construct();

        $this->service = new BackupService(settings('backups'));

        $canRestore = Cache::remember(
            'backup_can_restore_positions',
            60,
            fn () => $this->service->canRestorePositions()
        );

        if ($canRestore === false) {
            throw new PermissionException();
        }

        if (empty($this->service->destinations())) {
            throw new ValidationException(['message' => trans('front.invalid_ftp_settings')]);
        }
    }

    public function index(int $id)
    {
        return $this->getList($id, 1);
    }

    public function table(int $id)
    {
        return $this->getList($id, request()->input('page', 1), '_table');
    }

    private function getList(int $id, int $page, string $viewPostfix = '')
    {
        $device = $this->getDevice($id);
        $date = $this->filterDate();

        $entries = (new BackupCatalog($this->service))->entries($device, $date);
        $total = count($entries);
        $slice = array_slice($entries, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $folders = (new LengthAwarePaginator($slice, $total, self::PAGE_SIZE, $page))
            ->setPath(route('admin.objects.positions_backups.table', $id));

        $cutoff = settings('db_clear.status')
            ? Carbon::now()->subDays(BackupCatalog::historyDays())->toDateTimeString()
            : null;

        return view('Frontend.Devices.positions_backups' . $viewPostfix)
            ->with(compact('id', 'folders', 'cutoff', 'date'));
    }

    public function upload(int $id)
    {
        $device = $this->getDevice($id);

        $this->validate(request(), ['folder' => self::FOLDER_RULE]);

        dispatch(new DevicePositionsRestoreJob($device, request('folder'), $this->user));

        return ['status' => 1];
    }

    public function download(int $id)
    {
        $device = $this->getDevice($id);

        $this->validate(request(), ['folder' => self::FOLDER_RULE]);

        set_time_limit(0);

        $path = (new BackupCatalog($this->service))->fetch($device, request('folder'));

        if ($path === null) {
            throw new ValidationException(['folder' => trans('global.not_found')]);
        }

        $name = substr(basename($path), strpos(basename($path), '-') + 1);

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    private function filterDate(): ?string
    {
        $date = request()->input('date');

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    private function getDevice(int $id): Device
    {
        $item = Device::findOrFail($id);

        $this->checkException('devices', 'view', $item);

        return $item;
    }
}
```

`BackupTempFile::path()` nombra el archivo como `<uniqid>-<nombre>`. `uniqid('', true)` no contiene guiones, así que el primer guion separa el nombre original.

- [ ] **Paso 3: Añadir el filtro en `positions_backups.blade.php`**

Reemplazar:

```blade
    <div id="positions_backups_table">
        <div data-table>
```

por:

```blade
    <div id="positions_backups_table">
        <div class="form-group">
            <label for="positions_backups_date">{{ trans('backups.filter_by_date') }}</label>
            <input type="date" id="positions_backups_date" name="date" class="form-control" data-filter>
        </div>

        <div data-table>
```

- [ ] **Paso 4: Reemplazar `positions_backups_table.blade.php`**

```blade
<table class="table table-list">
    <tr>
        {!! tableHeader('front.folder') !!}
        {!! tableHeader('backups.history') !!}
        {!! tableHeader('backups.origin') !!}
        {!! tableHeader('admin.actions') !!}
    </tr>
    <tbody>
    @forelse ($folders as $folder)
        @php
            $folderName = $folder['name'];
            $isOlderThanCleanup = $cutoff && $folder['from'] && $folder['from'] < $cutoff;
        @endphp
        <tr>
            <td>{{ $folderName }}</td>
            <td>
                @if ($folder['from'] && $folder['to'])
                    @if ($folder['approximate'])
                        {{ trans('backups.approximate') }}
                    @endif
                    {{ substr($folder['from'], 0, 10) }} → {{ substr($folder['to'], 0, 10) }}
                @endif
            </td>
            <td>{{ strtoupper(implode(' + ', $folder['origins'])) }}</td>
            <td>
                {!! Form::open(['url' => route('admin.objects.positions_backups.download', $id), 'method' => 'POST', 'id' => $folderName, 'style' => 'display:inline']) !!}
                {!! Form::hidden('folder', $folderName) !!}
                {!! Form::submit(trans('admin.download'), ['class' => 'btn btn-sm']) !!}
                {!! Form::close() !!}

                <button class="btn btn-sm btn-primary uploaders"
                        data-folder="{{ $folderName }}"
                        @if ($isOlderThanCleanup)
                            data-warning="{{ trans('backups.restore_warning', ['date' => substr($cutoff, 0, 10)]) }}"
                        @endif
                >
                    {{ trans('front.upload') }}
                </button>
            </td>
        </tr>
    @empty
        <tr>
            <td class="no-data" colspan="4">
                {{ trans('admin.no_data') }}
            </td>
        </tr>
    @endforelse
    </tbody>
</table>

<div class="nav-pagination">
    @if ($folders->total())
        {!! $folders->render() !!}
    @endif
</div>

<script>
    $(document).ready(function() {
        $('.uploaders').unbind().click(function () {
            var warning = $(this).data('warning');

            if (warning && !confirm(warning)) {
                return;
            }

            $(this).prop('disabled', true);

            $.ajax({
                type: 'POST',
                url: '{{ route('admin.objects.positions_backups.upload', $id) }}',
                data: {
                    folder: $(this).data('folder')
                },
                success: function () {
                    toastr.success('{{ trans('front.upload_initiated') }}');
                }
            });
        });
    });
</script>
```

- [ ] **Paso 5: Comprobar en el navegador**

```bash
php -l Tobuli/Helpers/Backup/BackupCatalog.php
php -l app/Http/Controllers/Admin/DevicesPositionsBackupsController.php
```

Con un destino configurado, abrir Admin → Vehículos → menú del device → Posiciones de respaldo. Esperado: carpetas con historial `aprox.` para copias antiguas, columna Origen, y el filtro de fecha reduce la lista.

- [ ] **Paso 6: Commit**

```bash
git add Tobuli/Helpers/Backup/BackupCatalog.php app/Http/Controllers/Admin/DevicesPositionsBackupsController.php Tobuli/Views/Frontend/Devices/positions_backups.blade.php Tobuli/Views/Frontend/Devices/positions_backups_table.blade.php
git commit -m "feat(backups): ventana de respaldo con historial, origen y filtro por fecha"
```

---

### Tarea 12: Comandos de import y delta

**Archivos:**
- Modificar: `app/Console/Commands/BackupImportCommand.php`, `DeltaPositionsImportCommand.php`, `DeltaPositionsBackupCommand.php`

- [ ] **Paso 1: `BackupImportCommand.php`: imports, firma y propiedad**

Reemplazar los imports de `BackupFtp` y `FileMeta` por:

```php
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
```

Reemplazar:

```php
    protected $signature = 'backup:import';
```

```php
    private BackupFtp $ftp;
```

por:

```php
    protected $signature = 'backup:import {--destination= : ftp o drive}';
```

```php
    private BackupService $service;
    private BackupDestination $destination;
```

- [ ] **Paso 2: `BackupImportCommand.php`: reemplazar `handle()`**

```php
    public function handle(): void
    {
        $this->service = new BackupService($this->getFtpSettings());

        $this->destination = $this->service->destination(
            $this->option('destination')
                ?: $this->choice('Destino', array_keys($this->service->destinations()))
        );

        if (!$this->destination->check()) {
            throw new \Exception('Invalid destination settings');
        }

        $this->getFolder();

        if (!$this->destination->exists($this->folder)) {
            throw new \Exception('Folder empty');
        }

        $restoreOption = $this->getRestoreOption();

        $this->restore($restoreOption);

        $this->info('Done');
    }
```

- [ ] **Paso 3: `BackupImportCommand.php`: reemplazar `restoreMain()` y `restoreDevice()`**

```php
    private function restoreMain(): void
    {
        $this->line('Main DB restore started');

        $relative = BackupPathResolver::databaseFile(DB::getConfig());
        $remote = $this->folder . '/' . $relative;

        if (!$this->destination->exists($remote)) {
            $this->error('Main DB file not found in backup folder. Expected: ' . $relative);
            throw new \RuntimeException('Main DB backup file not found. Check that DB_HOST in .env matches the backup filename prefix.');
        }

        $file = BackupTempFile::path(basename($relative));

        try {
            $this->destination->download($remote, $file);
            BackupTempFile::assertValid($file, true);

            dispatch_sync(new DatabaseImportJob(DB::getConfig(), $file, null));
        } finally {
            File::delete($file);
        }

        $this->line('Main DB successfully restored');
    }
```

```php
    private function restoreDevice(Device $device)
    {
        $relative = BackupPathResolver::devicePositionsFile($device->traccar);
        $remote = $this->folder . '/' . $relative;

        if (!$this->destination->exists($remote)) {
            return;
        }

        $file = BackupTempFile::path(basename($relative));
        $this->destination->download($remote, $file);
        BackupTempFile::assertValid($file);

        dispatch_sync(new DevicePositionsImportJob($device, $file, null));

        File::delete($file);
    }
```

- [ ] **Paso 4: `BackupImportCommand.php`: listar carpetas**

En `getFolder()`, reemplazar:

```php
        $items = $this->ftp->findBackupFolders();

        if (empty($items)) {
            throw new \RuntimeException('No backups found');
        }

        $items = array_map(fn(FileMeta $item) => $item->getName(), $items);
```

por:

```php
        $items = array_merge(
            $this->destination->listFolders('backup_'),
            $this->destination->listFolders('soloweb_')
        );

        if (empty($items)) {
            throw new \RuntimeException('No backups found');
        }
```

Y reemplazar el orden, porque ahora hay dos prefijos, para ordenar por fecha y no por prefijo:

```php
        usort($items, fn($a, $b) => strcmp(strstr($b, '_'), strstr($a, '_')));
```

- [ ] **Paso 5: `DeltaPositionsImportCommand.php`**

Reemplazar los imports:

```php
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
```

Añadir a la firma, debajo de `{--device= …}`:

```php
                            {--destination= : ftp o drive}
```

Reemplazar la propiedad `private BackupFtp $ftp;` por `private BackupDestination $destination;`.

Reemplazar el inicio de `handle()` hasta el `if (!$this->ftp->check())` incluido por:

```php
        $service = new BackupService($this->getFtpSettings());

        $this->destination = $service->destination(
            $this->option('destination')
                ?: $this->choice('Destino', array_keys($service->destinations()))
        );

        if (!$this->destination->check()) {
            $this->error('Destino inaccesible. Verifica las credenciales.');
            return 1;
        }
```

Reemplazar el bloque `try` del bucle por:

```php
            try {
                $relative = BackupPathResolver::devicePositionsFile($device->traccar);
                $remote = $this->folder . '/' . $relative;

                if (!$this->destination->exists($remote)) {
                    $skipped++;
                } else {
                    $file = BackupTempFile::path(basename($relative));
                    $this->destination->download($remote, $file);
                    BackupTempFile::assertValid($file);

                    dispatch_sync(new DevicePositionsImportJob($device, $file, null));
                    File::delete($file);
                    $imported++;
                }
            } catch (\Throwable $e) {
```

En `chooseDeltaFolder()`, reemplazar:

```php
        $items = $this->ftp->findFiles('delta_*');

        if (empty($items)) {
            $this->error('No se encontraron carpetas delta_ en el FTP.');
            return null;
        }

        $names = array_map(fn(FileMeta $f) => $f->getName(), $items);
```

por:

```php
        $names = $this->destination->listFolders('delta_');

        if (empty($names)) {
            $this->error('No se encontraron carpetas delta_ en el destino.');
            return null;
        }
```

- [ ] **Paso 6: `DeltaPositionsBackupCommand.php`**

Reemplazar los imports de `Process`, `ProcessFailedException` y `BackupFtp` por:

```php
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
```

Añadir a la firma:

```php
                            {--destination=ftp : ftp o drive}
```

Reemplazar la propiedad `private BackupFtp $ftp;` por `private BackupDestination $destination;`.

Reemplazar:

```php
        $service = new BackupService($ftpSettings);
        $service->setupFtp();
        $this->ftp = $service->ftp();

        if (!$this->ftp->check()) {
            $this->error('FTP inaccesible. Verifica las credenciales.');
            return 1;
        }
```

por:

```php
        $service = new BackupService($ftpSettings);
        $this->destination = $service->destination($this->option('destination'));

        if (!$this->destination->check()) {
            $this->error('Destino inaccesible. Verifica las credenciales.');
            return 1;
        }
```

Reemplazar desde `$remotePath = …` hasta el final de `backupDevice()`, y borrar `runShell()`:

```php
        // Se usa el id de traccar, igual que el import: Device->id podría ser distinto.
        $relative = BackupPathResolver::devicePositionsDir($traccar->id) . $table . '.sql.gz';
        $whereClause = "time >= '{$from}' AND time <= '{$to}'";

        $command = 'MYSQL_PWD=' . escapeshellarg((string)$config['password'])
            . ' mysqldump'
            . ' --insert-ignore'
            . ' --no-create-info'
            . ' --skip-add-drop-table'
            . ' -u ' . escapeshellarg($config['username'])
            . ' -h ' . escapeshellarg($config['host'])
            . ' --port=' . escapeshellarg($config['port'])
            . ' --where=' . escapeshellarg($whereClause)
            . ' ' . escapeshellarg($config['database'])
            . ' ' . escapeshellarg($table);

        $local = BackupTempFile::dump($command, basename($relative));

        try {
            BackupTempFile::assertValid($local);
            $this->destination->upload($local, $this->remoteFolder . '/' . $relative);
        } finally {
            @unlink($local);
        }

        return true;
    }
```

- [ ] **Paso 7: Comprobar sintaxis y buscar restos**

```bash
php -l app/Console/Commands/BackupImportCommand.php
php -l app/Console/Commands/DeltaPositionsImportCommand.php
php -l app/Console/Commands/DeltaPositionsBackupCommand.php
```

Con la herramienta de búsqueda, buscar en `app/` y `Tobuli/` estos nombres: `getTraccarFilename`, `getDatabaseFilename`, `getRootPath`, `getDevicePositionsRemoteDir`, `findBackupFolders`, `findFirstFile`, `getDeviceFile`, `getMainDbFile`, `buildPipeUploadCommand`, `\$this->ftp`. Esperado: solo `$this->ftp` en `BackupService.php` y en las clases antiguas sin uso `Tobuli/Helpers/Backup.php` y `Tobuli/Helpers/BackupFTP.php`, que no se tocan.

- [ ] **Paso 8: Commit**

```bash
git add app/Console/Commands/BackupImportCommand.php app/Console/Commands/DeltaPositionsImportCommand.php app/Console/Commands/DeltaPositionsBackupCommand.php
git commit -m "feat(backups): import y delta eligen destino; la ruta del delta usa el id de traccar"
```

---

### Tarea 13: `backup:drive-import-ftp`

**Archivos:**
- Crear: `app/Console/Commands/BackupDriveImportFtpCommand.php`
- Modificar: `app/Console/Kernel.php`

- [ ] **Paso 1: Crear el comando**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\TraccarDevice;
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupRetention;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

/**
 * Copia una carpeta del FTP a Drive y la protege. Se usa una sola vez, al poner en marcha Drive,
 * para no perder la copia más antigua que siga en el FTP.
 */
class BackupDriveImportFtpCommand extends Command
{
    protected $signature = 'backup:drive-import-ftp {folder : Ejemplo: backup_2026-10-19-1792368061}';

    protected $description = 'Copia una carpeta de backup del FTP a Google Drive y la marca como protegida';

    public function handle(): int
    {
        $folder = $this->argument('folder');

        if (!preg_match('/^backup_[0-9-]+$/', $folder)) {
            $this->error('Nombre de carpeta no válido');

            return 1;
        }

        $service = new BackupService(settings('backups'));
        $ftp = $service->destination(BackupDestination::FTP);
        $drive = $service->destination(BackupDestination::DRIVE);

        $copied = 0;
        $files = [BackupPathResolver::databaseFile(DB::getConfig()), 'images.tar.gz'];

        foreach ($files as $relative) {
            $copied += $this->copy($ftp, $drive, $folder, $relative) ? 1 : 0;
        }

        $bar = $this->output->createProgressBar(TraccarDevice::count());

        TraccarDevice::orderBy('id')->chunkById(200, function ($devices) use ($ftp, $drive, $folder, $bar, &$copied) {
            foreach ($devices as $device) {
                $relative = BackupPathResolver::devicePositionsFile($device);

                try {
                    $copied += $this->copy($ftp, $drive, $folder, $relative) ? 1 : 0;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("$relative: " . \Tobuli\Helpers\Backup\BackupSecrets::scrub($e->getMessage()));
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $drive->put($folder . '/' . BackupRetention::PROTECT_FILE, date('c'));

        $this->info("Copiados: $copied. Carpeta protegida.");

        return 0;
    }

    private function copy(BackupDestination $from, BackupDestination $to, string $folder, string $relative): bool
    {
        $remote = $folder . '/' . $relative;

        if (!$from->exists($remote) || $to->exists($remote)) {
            return false;
        }

        $local = BackupTempFile::path(basename($relative));

        try {
            $from->download($remote, $local);
            $to->upload($local, $remote);
        } finally {
            @unlink($local);
        }

        return true;
    }
}
```

- [ ] **Paso 2: Registrar en `app/Console/Kernel.php`**

Reemplazar:

```php
        'App\Console\Commands\BackupPruneCommand',
```

por:

```php
        'App\Console\Commands\BackupPruneCommand',
        'App\Console\Commands\BackupDriveImportFtpCommand',
```

- [ ] **Paso 3: Comprobar**

```bash
php -l app/Console/Commands/BackupDriveImportFtpCommand.php
php artisan list backup
```

Esperado: aparecen `backup:prune` y `backup:drive-import-ftp`.

- [ ] **Paso 4: Commit**

```bash
git add app/Console/Commands/BackupDriveImportFtpCommand.php app/Console/Kernel.php
git commit -m "feat(backups): comando para copiar una carpeta del FTP a Drive y protegerla"
```

---

### Tarea 14: Panel de administración

**Archivos:**
- Modificar: `app/Http/Controllers/Admin/BackupsController.php`, `app/Http/Controllers/Admin/BackupController.php`, `Tobuli/Validation/AdminBackupsFormValidator.php`, `routes/web.php`, `Tobuli/Views/Admin/Backups/panel.blade.php`, `Tobuli/Views/Admin/Backup/table.blade.php`

- [ ] **Paso 1: `BackupsController.php`: cambiar a `BackupService`**

Reemplazar el import:

```php
use Tobuli\Helpers\Backup;
```

por:

```php
use Tobuli\Helpers\Backup\BackupService;
```

Reemplazar las dos llamadas `(new Backup($settings))->check();`. En `save()`:

```php
            try {
                $service = new BackupService($settings);
                $service->check();

                if (!empty($settings['drive_enabled'])) {
                    $service->testDrive();
                }
            }
```

En `test()`:

```php
            (new BackupService($settings))->check();
```

- [ ] **Paso 2: `BackupsController.php`: guardar ajustes y probar Drive**

En `save()`, añadir debajo de `$settings['hour'] = $input['hour'];`:

```php
                $settings['drive_enabled'] = !empty($input['drive_enabled']);
                $settings['drive_remote'] = $input['drive_remote'] ?? 'gdrive';
                $settings['drive_path'] = $input['drive_path'] ?? 'backup_plataforma/';
                $settings['retention_daily_days'] = (int)($input['retention_daily_days'] ?? 30);
                $settings['retention_long_term_months'] = (int)($input['retention_long_term_months'] ?? 2);
                $settings['retention_max_days'] = (int)($input['retention_max_days'] ?? 365);
```

Añadir después de `test()`:

```php
    public function testDrive()
    {
        $settings = settings('backups') ?: [];

        if (empty($settings['drive_enabled'])) {
            return Response::json(['status' => 0, 'message' => trans('backups.drive_unavailable')]);
        }

        try {
            $about = (new BackupService($settings))->testDrive()->about();

            return Response::json(['status' => 1, 'message' => trans('backups.drive_ok', [
                'used' => round($about['used'] / 1073741824, 1),
                'free' => round($about['free'] / 1073741824, 1),
            ])]);
        } catch (\Exception $e) {
            return Response::json(['status' => 0, 'message' => trans('backups.drive_failed', ['error' => $e->getMessage()])]);
        }
    }
```

- [ ] **Paso 3: Validación en `AdminBackupsFormValidator.php`**

Añadir a `$rules['update']`, después de `'hour' => 'required',`:

```php
            'drive_remote' => 'required_with:drive_enabled|nullable|regex:/^[A-Za-z0-9_-]+$/',
            'drive_path' => 'required_with:drive_enabled|nullable|regex:/^[A-Za-z0-9_\/-]+$/',
            'retention_daily_days' => 'required_with:drive_enabled|nullable|integer|min:1',
            'retention_long_term_months' => 'required_with:drive_enabled|nullable|integer|min:1|max:12',
            'retention_max_days' => 'required_with:drive_enabled|nullable|integer|min:1',
```

- [ ] **Paso 4: Rutas en `routes/web.php`**

Reemplazar:

```php
    Route::get('backups/test', ['as' => 'backups.test', 'uses' => 'BackupsController@test']);
```

por:

```php
    Route::get('backups/test', ['as' => 'backups.test', 'uses' => 'BackupsController@test']);
    Route::get('backups/test-drive', ['as' => 'backups.test_drive', 'uses' => 'BackupsController@testDrive']);
```

Reemplazar:

```php
    Route::get('backup/{id}/processes', ['as' => 'backup.processes', 'uses' => 'BackupController@processes']);
```

por:

```php
    Route::get('backup/{id}/processes', ['as' => 'backup.processes', 'uses' => 'BackupController@processes']);
    Route::post('backup/{id}/protect', ['as' => 'backup.protect', 'uses' => 'BackupController@protect']);
```

- [ ] **Paso 5: `BackupController.php`: proteger copias**

Añadir imports:

```php
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupRetention;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
```

Añadir el método al final de la clase:

```php
    public function protect(int $id)
    {
        $backup = Backup::findOrFail($id);
        $drive = (new BackupService(settings('backups')))->destination(BackupDestination::DRIVE);

        // Las copias sin posiciones cambian de prefijo al aplicar la retención.
        $folder = BackupPathResolver::backupFolder($backup);

        if (!$drive->exists($folder)) {
            $folder = 'soloweb_' . substr($folder, strlen('backup_'));
        }

        if ($backup->protected) {
            $drive->deleteFile($folder . '/' . BackupRetention::PROTECT_FILE);
        } else {
            $drive->put($folder . '/' . BackupRetention::PROTECT_FILE, date('c'));
        }

        $backup->update(['protected' => !$backup->protected]);

        return redirect()->route('admin.backup.index');
    }
```

- [ ] **Paso 6: Sección Drive en `panel.blade.php`**

Insertar antes de `@if (isset($settings['ftp_server']))`:

```blade
        <hr>
        <h4>{{ trans('backups.drive_title') }}</h4>

        <div class="form-group">
            <div class="col-xs-12 col-sm-8 col-sm-offset-4">
                <label>
                    {!! Form::checkbox('drive_enabled', 1, !empty($settings['drive_enabled'])) !!}
                    {{ trans('backups.drive_enabled') }}
                </label>
            </div>
        </div>

        @foreach ([
            'drive_remote' => $settings['drive_remote'] ?? 'gdrive',
            'drive_path' => $settings['drive_path'] ?? 'backup_plataforma/',
            'retention_daily_days' => $settings['retention_daily_days'] ?? 30,
            'retention_long_term_months' => $settings['retention_long_term_months'] ?? 2,
            'retention_max_days' => $settings['retention_max_days'] ?? 365,
        ] as $field => $value)
            <div class="form-group">
                {!! Form::label($field, trans('backups.' . $field), ['class' => 'col-xs-12 col-sm-4 control-label']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::text($field, $value, ['class' => 'form-control']) !!}
                </div>
            </div>
        @endforeach

        <button class="btn btn-main" type="button" onClick="test_drive();">
            <i class="icon upload"></i> {{ trans('backups.test_drive') }}
        </button>
        <div class="test_drive_response alert alert-danger" style="display: none;"></div>
```

Dentro del `<script>`, añadir antes de `$(document).ready(`:

```js
        function test_drive() {
            $.ajax({
                type: 'GET',
                url: '{{ route('admin.backups.test_drive') }}',
                beforeSend: function() {
                    $('.test_drive_response').hide();
                },
                success: function(res) {
                    $('.test_drive_response')
                        .show()
                        .toggleClass('alert-success', res.status == 1)
                        .toggleClass('alert-danger', res.status != 1)
                        .text(res.message);
                }
            });
        }
```

- [ ] **Paso 7: Columnas en `Backup/table.blade.php`**

Añadir en la cabecera, antes de `{!! tableHeader('validation.attributes.message') !!}`:

```blade
            {!! tableHeader('backups.destinations') !!}
            {!! tableHeader('backups.database_size') !!}
```

Añadir en la fila, antes de `<td>{{ $item->message }}</td>`:

```blade
                <td>
                    @foreach (array_keys($item->destinations ?: []) as $destinationName)
                        <div>{{ strtoupper($destinationName) }}: {{ trans('backups.status_' . $item->destinationStatus($destinationName)) }}</div>
                    @endforeach
                </td>
                <td>{{ $item->database_bytes ? round($item->database_bytes / 1048576, 1) . ' MB' : '' }}</td>
```

Reemplazar la celda de acciones:

```blade
                <td class="actions">
                    @if (settings('backups.drive_enabled') && !empty($item->destinations['drive']))
                        {!! Form::open(['route' => ['admin.backup.protect', $item->id], 'method' => 'POST', 'style' => 'display:inline']) !!}
                        {!! Form::submit(trans($item->protected ? 'backups.unprotect' : 'backups.protect'), ['class' => 'btn btn-xs btn-default']) !!}
                        {!! Form::close() !!}
                    @endif
                    <i class="btn icon ico-arrow-down"
                       type="button"
                       data-url="{{ route('admin.backup.processes', $item->id) }}"
                       data-toggle="collapse"
                       data-target="#backup-processes-{{ $item->id }}">
                    </i>
                </td>
```

Reemplazar las dos apariciones de `colspan="7"` por `colspan="9"`.

- [ ] **Paso 8: Comprobar en el navegador**

```bash
php -l app/Http/Controllers/Admin/BackupsController.php
php -l app/Http/Controllers/Admin/BackupController.php
php -l Tobuli/Validation/AdminBackupsFormValidator.php
php artisan view:clear
```

Abrir `/admin/backups/index`: activar Drive, guardar y pulsar **Probar Drive**. Esperado: mensaje con espacio usado y libre. Abrir `/admin/backup`: aparecen las columnas nuevas, y las filas antiguas las muestran vacías sin error.

- [ ] **Paso 9: Commit**

```bash
git add app/Http/Controllers/Admin/BackupsController.php app/Http/Controllers/Admin/BackupController.php Tobuli/Validation/AdminBackupsFormValidator.php routes/web.php Tobuli/Views/Admin/Backups/panel.blade.php Tobuli/Views/Admin/Backup/table.blade.php
git commit -m "feat(backups): panel de Google Drive, estado por destino y copias protegidas"
```

---

### Tarea 15: Guía, documentación y verificación final

**Archivos:**
- Crear: `docs/backups-google-drive.md`
- Modificar: `CLAUDE.md`

- [ ] **Paso 1: Crear `docs/backups-google-drive.md` (manual de AlmaLinux 9)**

Este manual se redacta contra lo implementado: si durante la implementación cambió algún nombre, ruta o comando, actualizarlo. Las preguntas exactas de `rclone config` pueden variar con la versión.

````markdown
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
php artisan backup:drive-import-ftp backup_2026-10-19-1792368061
```

Usar la carpeta más antigua que siga en el FTP. Queda protegida hasta quitarle la protección.

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
````

- [ ] **Paso 2: Añadir una sección a `CLAUDE.md`**

Insertar antes de `## Roles y Permisos`:

```markdown
## Backups (FTP + Google Drive)

`Tobuli/Helpers/Backup/`. Cada archivo se genera en un temporal, se valida y se sube a cada destino
(`BackupDestination`: `BackupFtp` con ncftpput, `DriveDestination` con rclone) desde
`MultiDestinationUploader`. El estado por destino está en `backups.destinations`.

- **Índice:** `_completo.json` en cada copia terminada; guarda el rango de posiciones por device y
  marca la copia como completa.
- **Retención (solo Drive):** `backup:prune`, se ejecuta tras cada backup correcto en Drive. Las copias
  antiguas sin posiciones pasan de `backup_…` a `soloweb_…`. `.conservar` protege una carpeta.
- **Guía de puesta en marcha:** `docs/backups-google-drive.md`.

```

- [ ] **Paso 3: Revisar la especificación contra el resultado**

Leer `docs/superpowers/specs/2026-11-08-backups-google-drive-design.md` y comprobar cada sección de «Verificación».

- [ ] **Paso 4: Prueba en Windows (rclone)**

Con un remoto de pruebas y `drive_path = backup_pruebas/`:

1. Crear en Drive carpetas vacías con archivos de prueba, usando `DriveDestination::put()`: `backup_2020-01-01-1` y `backup_2020-03-01-1` con `_completo.json`, `backup_2020-02-10-1`, una con `.conservar`, y una del mes actual.
2. Ejecutar `php artisan backup:prune --dry-run`.

Esperado: `purge` en las de 2020 sin protección, nada en la protegida ni en la del mes actual. Repetir con `retention_max_days` alto: `strip` en `backup_2020-02-10-1`, y las de enero y marzo de 2020 no aparecen, porque son de largo plazo.

- [ ] **Paso 5: Prueba del flujo completo en Linux (WSL o producción)**

1. `php artisan backup:mysql --force=1` en un entorno con pocos devices (la copia de dev solo tiene posiciones en el device 219).
2. Comprobar en `/admin/backup` el estado por destino y el tamaño de la base.
3. Poner una contraseña con `|` en la base de pruebas y repetir: el volcado debe ser correcto.
4. Poner una contraseña incorrecta: no debe subirse nada, y el error debe verse en el panel sin contraseñas (`select details from backups order by id desc limit 1`).
5. Configurar mal el remoto de Drive: el FTP debe terminar `correcto`, Drive `falló` y debe llegar el correo.
6. Restaurar con **Subir** y comprobar que llega el aviso. Probar también con un archivo dañado: debe llegar el aviso de error y no quedar temporales en `storage/app/backup-tmp`.

- [ ] **Paso 6: Commit**

```bash
git add docs/backups-google-drive.md CLAUDE.md
git commit -m "docs(backups): guía de Google Drive y arquitectura de backups"
```

- [ ] **Paso 7: Dejar los assets como están**

No se tocaron SCSS ni JS de `resources/assets`. No hace falta ejecutar Gulp.

## Después de desplegar en producción

Seguir `docs/backups-google-drive.md`. Hasta fusionar `nuevo-tema` en `master`, el volcado de `gpswox_web` sigue saliendo vacío en producción y hay que sacar volcados manuales.
