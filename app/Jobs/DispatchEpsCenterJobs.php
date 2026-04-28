<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\EpsCenter;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use CustomFacades\Repositories\UserRepo;

class DispatchEpsCenterJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const URL = 'https://eps.center/api/transporte/rastreo/registro';

    public $tries = 3;

    public function handle()
    {
        $users = UserRepo::getActiveServiceManagers(1, 'epscenter');

        // Tomar el servicio del primer manager activo
        $service = [];
        foreach ($users as $user) {
            $service = $user->services['epscenter'] ?? [];
            $userId  = $user->id;
            break;
        }

        $batchSize    = 500;
        $totalRecords = EpsCenter::count();

        if ($totalRecords === 0) {
            return;
        }

        Log::channel('epscenter')->info('Despachando lotes EPS Center', [
            'total_records' => $totalRecords,
        ]);

        for ($i = 0; $i < $totalRecords; $i += $batchSize) {
            SendDataEpsCenter::dispatch($i, $batchSize, self::URL, $service, [], $userId ?? null);
        }
    }
}
