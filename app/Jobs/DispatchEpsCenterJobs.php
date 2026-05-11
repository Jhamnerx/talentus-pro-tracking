<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\EpsCenter;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use CustomFacades\Repositories\UserRepo;

class DispatchEpsCenterJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const URL = 'https://eps.center/api/transporte/rastreo/registro';

    public $tries = 3;

    public function handle()
    {
        $queueName = 'web-services-epscenter';

        $pending = Queue::size($queueName);
        if ($pending > 0) {
            Log::channel('epscenter')->info("DispatchEpsCenterJobs omitido: {$pending} jobs aún en cola.");
            return;
        }

        $totalRecords = EpsCenter::count();
        if ($totalRecords === 0) {
            return;
        }

        $users = UserRepo::getActiveServiceManagers(1, 'epscenter');
        $service = [];
        $userId = null;
        foreach ($users as $user) {
            $service = $user->services['epscenter'] ?? [];
            $userId  = $user->id;
            break;
        }

        Log::channel('epscenter')->info('Despachando lote EPS Center', ['total_records' => $totalRecords]);

        SendDataEpsCenter::dispatch(0, 500, self::URL, $service, [], $userId);
    }
}
