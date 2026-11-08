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
    protected $signature = 'backup:drive-import-ftp {folder : Ejemplo: backup_2026-10-19-1792368061}
        {--allow-invalid-database : Protege la carpeta aunque el volcado de la base falte o no sea valido (copias antiguas vacias)}';

    protected $description = 'Copia una carpeta de backup del FTP a Google Drive y la marca como protegida';

    public function handle(): int
    {
        $folder = $this->argument('folder');

        if (!preg_match('/^backup_[0-9-]+$/D', $folder)) {
            $this->error('Nombre de carpeta no válido');

            return 1;
        }

        $service = new BackupService(settings('backups'));
        $ftp = $service->destination(BackupDestination::FTP);
        $drive = $service->destination(BackupDestination::DRIVE);

        if (!$ftp->exists($folder)) {
            $this->error("La carpeta $folder no existe en el FTP");

            return 1;
        }

        $copied = 0;
        $failed = 0;
        $allowInvalidDatabase = (bool)$this->option('allow-invalid-database');
        $databaseFile = BackupPathResolver::databaseFile(DB::getConfig());
        $databaseOk = false;

        try {
            $databaseCopied = $this->copy($ftp, $drive, $folder, $databaseFile, true);
            $copied += $databaseCopied ? 1 : 0;
            $databaseOk = $databaseCopied;

            if (!$databaseOk && $drive->exists($folder . '/' . $databaseFile)) {
                // Ya estaba en Drive: se valida igual descargandolo.
                $local = BackupTempFile::path(basename($databaseFile));

                try {
                    $drive->download($folder . '/' . $databaseFile, $local);
                    BackupTempFile::assertValid($local, true);
                    $databaseOk = true;
                } finally {
                    @unlink($local);
                }
            }
        } catch (\Throwable $e) {
            $this->warn("$databaseFile: " . \Tobuli\Helpers\Backup\BackupSecrets::scrub($e->getMessage()));
        }

        if (!$databaseOk) {
            if ($allowInvalidDatabase) {
                $this->warn("AVISO: el volcado de la base ($databaseFile) falta o no es valido; la carpeta se protegera solo por sus posiciones.");
            } else {
                $failed++;
                $this->warn("$databaseFile ausente o invalido. Usa --allow-invalid-database si solo te interesan las posiciones.");
            }
        }

        // images.tar.gz ausente solo avisa; si existe pero falla la copia, cuenta como fallo.
        try {
            if ($this->copy($ftp, $drive, $folder, 'images.tar.gz')) {
                $copied++;
            } elseif (!$ftp->exists($folder . '/images.tar.gz')) {
                $this->warn('AVISO: images.tar.gz no esta en el FTP.');
            }
        } catch (\Throwable $e) {
            $failed++;
            $this->warn('images.tar.gz: ' . \Tobuli\Helpers\Backup\BackupSecrets::scrub($e->getMessage()));
        }

        $bar = $this->output->createProgressBar(TraccarDevice::count());

        TraccarDevice::orderBy('id')->chunkById(200, function ($devices) use ($ftp, $drive, $folder, $bar, &$copied, &$failed) {
            foreach ($devices as $device) {
                $relative = BackupPathResolver::devicePositionsFile($device);

                try {
                    $copied += $this->copy($ftp, $drive, $folder, $relative) ? 1 : 0;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("$relative: " . \Tobuli\Helpers\Backup\BackupSecrets::scrub($e->getMessage()));
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        if ($failed > 0) {
            $this->error("Fallaron $failed archivos; la carpeta NO se protegió. Vuelve a ejecutar el comando: se saltan los que ya están en Drive.");

            return 1;
        }

        $drive->put($folder . '/' . BackupRetention::PROTECT_FILE, date('c'));

        if ($copied === 0) {
            $this->warn('AVISO: no se copio ningun archivo (todo estaba ya en Drive o no habia nada que copiar en el FTP). Revisa que sea lo esperado.');
        }

        $this->info("Copiados: $copied. Carpeta protegida.");

        return 0;
    }

    private function copy(BackupDestination $from, BackupDestination $to, string $folder, string $relative, bool $isDatabase = false): bool
    {
        $remote = $folder . '/' . $relative;

        if (!$from->exists($remote) || $to->exists($remote)) {
            return false;
        }

        $local = BackupTempFile::path(basename($relative));

        try {
            $from->download($remote, $local);
            BackupTempFile::assertValid($local, $isDatabase);
            $to->upload($local, $remote);
        } finally {
            @unlink($local);
        }

        return true;
    }
}
