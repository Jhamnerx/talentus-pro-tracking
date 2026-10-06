<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\Uploader\BackupFtp;
use Tobuli\Services\DatabaseService;

/**
 * Backup incremental (delta) de posiciones de un rango de fechas hacia el FTP.
 * Se guarda en una carpeta separada: delta_FROM_to_TO-{timestamp}/
 * No interfiere con el backup/import normal.
 *
 * Uso:
 *   php artisan backup:delta-positions
 *   php artisan backup:delta-positions --from="2026-05-05 00:00:00" --to="2026-05-05 23:59:59"
 *   php artisan backup:delta-positions --from=2026-05-05 --to=2026-05-05 --device=42
 */
class DeltaPositionsBackupCommand extends Command
{
    protected $signature = 'backup:delta-positions
                            {--from=  : Fecha inicio (YYYY-MM-DD o YYYY-MM-DD HH:MM:SS)}
                            {--to=    : Fecha fin   (YYYY-MM-DD o YYYY-MM-DD HH:MM:SS)}
                            {--device= : Limitar a un solo Device ID (opcional)}';

    protected $description = 'Backup incremental de posiciones por rango de fechas en carpeta delta separada del FTP';

    private BackupFtp $ftp;
    private string $remoteFolder;

    public function handle(): int
    {
        [$from, $to] = $this->resolveDates();

        $this->info("Rango: {$from}  →  {$to}");

        $ftpSettings = $this->getFtpSettings();

        $service = new BackupService($ftpSettings);
        $service->setupFtp();
        $this->ftp = $service->ftp();

        if (!$this->ftp->check()) {
            $this->error('FTP inaccesible. Verifica las credenciales.');
            return 1;
        }

        $timestamp    = time();
        $fromSlug     = substr(str_replace([' ', ':'], '-', $from), 0, 10);
        $toSlug       = substr(str_replace([' ', ':'], '-', $to), 0, 10);
        $this->remoteFolder = "delta_{$fromSlug}_to_{$toSlug}-{$timestamp}";

        $this->info("Carpeta FTP: {$this->remoteFolder}");

        $deviceId = $this->option('device');
        $query    = Device::with('traccar')->whereHas('traccar');

        if ($deviceId) {
            $query->where('id', (int) $deviceId);
        }

        $total    = $query->count();
        $bar      = $this->output->createProgressBar($total);
        $bar->start();

        $exported = 0;
        $skipped  = 0;

        $query->chunk(200, function ($devices) use ($from, $to, $bar, &$exported, &$skipped) {
            foreach ($devices as $device) {
                if (!$device->traccar) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                try {
                    $this->backupDevice($device, $from, $to) ? $exported++ : $skipped++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("Device #{$device->id}: " . $e->getMessage());
                    $skipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Completado. Exportados: {$exported} | Sin posiciones en el rango: {$skipped}");
        $this->line("Para importar, usa: php artisan backup:delta-import");

        return 0;
    }

    private function backupDevice(Device $device, string $from, string $to): bool
    {
        $traccar = $device->traccar;
        $table   = $traccar->positions()->getRelated()->getTable();

        $config = (new DatabaseService())->getDatabaseConfig($traccar->database_id);

        if ($config === null) {
            return false;
        }

        // Saltar si no hay posiciones en el rango (evita subir archivos vacíos)
        $count = $traccar->positions()
            ->whereBetween('time', [$from, $to])
            ->count();

        if ($count === 0) {
            return false;
        }

        $remotePath  = $this->remoteFolder . '/' . BackupFtp::getDevicePositionsRemoteDir($device->id) . $table . '.sql.gz';
        $whereClause = "time >= '{$from}' AND time <= '{$to}'";

        // MYSQL_PWD evita que mysqldump pida contraseña interactivamente,
        // además de evitar problemas con caracteres especiales en la contraseña.
        $cmd = "mysqldump"
            . " --insert-ignore"
            . " --no-create-info"
            . " --skip-add-drop-table"
            . " -u " . escapeshellarg($config['username'])
            . " -h " . escapeshellarg($config['host'])
            . " --where=" . escapeshellarg($whereClause)
            . " " . escapeshellarg($config['database'])
            . " " . escapeshellarg($table)
            . " | gzip -9"
            . " | " . $this->ftp->buildPipeUploadCommand($remotePath);

        $process = Process::fromShellCommandline('bash -o pipefail -c ' . escapeshellarg($cmd));
        $process->setTimeout(300);
        $process->setEnv(['MYSQL_PWD' => $config['password']]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return true;
    }

    private function runShell(string $cmd): void
    {
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function resolveDates(): array
    {
        $from = $this->option('from');
        $to   = $this->option('to');

        if (!$from) {
            $from = $this->ask('Fecha inicio (YYYY-MM-DD ó YYYY-MM-DD HH:MM:SS)', now()->format('Y-m-d') . ' 00:00:00');
        }

        if (!$to) {
            $to = $this->ask('Fecha fin   (YYYY-MM-DD ó YYYY-MM-DD HH:MM:SS)', now()->format('Y-m-d') . ' 23:59:59');
        }

        if (strlen($from) === 10) {
            $from .= ' 00:00:00';
        }

        if (strlen($to) === 10) {
            $to .= ' 23:59:59';
        }

        return [$from, $to];
    }

    private function getFtpSettings(): array
    {
        $choice = $this->choice('Configuración FTP', [
            1 => 'From config',
            2 => 'Custom',
        ]);

        if ($choice === 'From config') {
            return settings('backups');
        }

        $this->line('Ingresa credenciales FTP:');

        return [
            'ftp_server'   => $this->ask('Server'),
            'ftp_username' => $this->ask('Username'),
            'ftp_password' => $this->ask('Password'),
            'ftp_port'     => $this->ask('Port', 21),
            'ftp_path'     => $this->ask('Base path', '/'),
        ];
    }
}
