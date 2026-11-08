<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
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
                            {--device= : Limitar a un solo Device ID (opcional)}
                            {--destination=ftp : ftp o drive}';

    protected $description = 'Backup incremental de posiciones por rango de fechas en carpeta delta separada del FTP';

    private BackupDestination $destination;
    private string $remoteFolder;

    public function handle(): int
    {
        [$from, $to] = $this->resolveDates();

        $this->info("Rango: {$from}  →  {$to}");

        $ftpSettings = $this->getFtpSettings();

        $service = new BackupService($ftpSettings);
        $this->destination = $service->destination($this->option('destination'));

        if (!$this->destination->check()) {
            $this->error('Destino inaccesible. Verifica las credenciales.');
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
