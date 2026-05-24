<?php

namespace App\Jobs;

use App\Services\TalentusMaintenanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job de reintento: sincroniza todos los device_maintenances
 * que aún no tienen synced_at (el envío inmediato falló).
 *
 * Programar en el Kernel cada N minutos, ej.:
 *   $schedule->job(new PushMaintenanceToTalentus)->everyFiveMinutes();
 */
class PushMaintenanceToTalentus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(TalentusMaintenanceService $service): void
    {
        $service->syncPending();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushMaintenanceToTalentus: falló el job de sincronización pendiente', [
            'error' => $e->getMessage(),
        ]);
    }
}
