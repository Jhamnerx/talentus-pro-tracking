<?php

namespace App\Console\Commands;

use App\Jobs\DevicePositionsImportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

/**
 * Importa posiciones desde una carpeta delta generada por backup:delta-positions.
 * Usa insert-ignore, es seguro ejecutar multiples veces.
 *
 * Uso:
 *   php artisan backup:delta-import
 *   php artisan backup:delta-import --device=42
 */
class DeltaPositionsImportCommand extends Command
{
    private const PAGE_SIZE = 20;

    protected $signature = 'backup:delta-import
                            {--device= : Importar solo el dispositivo con este ID (opcional)}
                            {--destination= : ftp o drive}';

    protected $description = 'Importa posiciones de una carpeta delta del FTP (generada por backup:delta-positions)';

    private BackupDestination $destination;
    private string $folder;

    public function handle(): int
    {
        $service = new BackupService($this->getFtpSettings());

        $this->destination = $service->destination(
            $this->option('destination')
                ?: $this->choice('Destino', array_keys($service->destinations()))
        );

        if (!$this->destination->check()) {
            $this->error('Destino inaccesible. Verifica las credenciales.');
            return 1;
        }

        $folder = $this->chooseDeltaFolder();

        if (!$folder) {
            $this->error('No se selecciono carpeta.');
            return 1;
        }

        $this->folder = $folder;

        $this->info("Importando desde: {$this->folder}");

        $deviceId = $this->option('device');
        $query    = Device::with('traccar')->whereHas('traccar');

        if ($deviceId) {
            $query->where('id', (int) $deviceId);
        }

        $total    = $query->count();
        $bar      = $this->output->createProgressBar($total);
        $bar->start();

        $imported = 0;
        $skipped  = 0;

        do {
            $device = (clone $query)
                ->where('id', '>', $device->id ?? 0)
                ->orderBy('id')
                ->first();

            if (!$device) {
                break;
            }

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
                $this->newLine();
                $this->warn("Device #{$device->id}: " . $e->getMessage());
                $skipped++;
            }

            $bar->advance();
        } while ($device);

        $bar->finish();
        $this->newLine();
        $this->info("Completado. Importados: {$imported} | Sin archivo en delta: {$skipped}");

        return 0;
    }

    private function chooseDeltaFolder(): ?string
    {
        $names = $this->destination->listFolders('delta_');

        if (empty($names)) {
            $this->error('No se encontraron carpetas delta_ en el destino.');
            return null;
        }

        usort($names, fn($a, $b) => strcmp($b, $a));

        $page   = 0;
        $choice = 'n';

        while (!is_numeric($choice)) {
            $pageItems = array_slice($names, $page * self::PAGE_SIZE, self::PAGE_SIZE);

            if ($page * self::PAGE_SIZE + count($pageItems) < count($names)) {
                $pageItems['n'] = 'Next';
            }

            if ($page > 0) {
                $pageItems['p'] = 'Previous';
            }

            $choice = $this->choice('Selecciona carpeta delta', $pageItems);

            if (!isset($pageItems[$choice])) {
                $choice = array_search($choice, $pageItems, true);
            }

            if ($choice === 'n') {
                $page++;
                $choice = 'n';
            } elseif ($choice === 'p') {
                $page--;
                $choice = 'n';
            }
        }

        return $names[$page * self::PAGE_SIZE + (int) $choice];
    }

    private function getFtpSettings(): array
    {
        $choice = $this->choice('Configuracion FTP', [
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
