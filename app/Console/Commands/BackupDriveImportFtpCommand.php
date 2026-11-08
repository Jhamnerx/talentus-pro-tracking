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
