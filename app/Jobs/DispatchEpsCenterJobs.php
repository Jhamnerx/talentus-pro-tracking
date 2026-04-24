<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\EpsCenter;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class DispatchEpsCenterJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const URL = 'https://eps.center/api/transporte/rastreo/registro';

    public $tries = 3;

    public function handle()
    {
        $batchSize     = 500;
        $totalRecords  = EpsCenter::count();

        if ($totalRecords === 0) {
            return;
        }

        Log::channel('epscenter')->info('Iniciando dispatch de jobs EPS Center', [
            'total_records' => $totalRecords,
        ]);

        for ($i = 0; $i < $totalRecords; $i += $batchSize) {
            SendDataEpsCenter::dispatch($i, $batchSize, self::URL);
        }
    }
}
