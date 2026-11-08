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
