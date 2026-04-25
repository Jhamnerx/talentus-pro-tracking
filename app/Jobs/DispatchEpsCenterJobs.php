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

        foreach ($users as $user) {
            $service = $user->services['epscenter'] ?? [];

            $imeis = $user->devices()->where('epscenter', 1)->pluck('imei')->toArray();

            if (empty($imeis)) {
                continue;
            }

            $batchSize    = 500;
            $totalRecords = EpsCenter::whereIn('id_transporte', $imeis)->count();

            if ($totalRecords === 0) {
                continue;
            }

            Log::channel('epscenter')->info('Despachando lotes EPS Center', [
                'user_id'       => $user->id,
                'total_records' => $totalRecords,
            ]);

            for ($i = 0; $i < $totalRecords; $i += $batchSize) {
                SendDataEpsCenter::dispatch($i, $batchSize, self::URL, $service, $imeis, $user->id);
            }
        }
    }
}
