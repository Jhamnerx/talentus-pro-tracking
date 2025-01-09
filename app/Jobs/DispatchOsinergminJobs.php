<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\Osinergmin;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class DispatchOsinergminJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle()
    {

        $users = UserRepo::getActiveServiceManagers(1, 'osinergmin');

        foreach ($users as $user) {

            $plates = $user->devices()->where('osinergmin', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                continue;
            }

            $service = $user->services['osinergmin'];

            $batchSize = 2000; // Tamaño del lote
            $totalRecords = Osinergmin::whereIn('plate', $plates)->count();

            for ($i = 0; $i < $totalRecords; $i += $batchSize) {
                SendDataOsinergmin::dispatch($i, $batchSize, $service, $plates);
            }
        }
    }
}
