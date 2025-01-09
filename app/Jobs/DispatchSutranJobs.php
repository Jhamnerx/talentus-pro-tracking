<?php

namespace App\Jobs;

use Tobuli\Entities\Sutran;
use Illuminate\Bus\Queueable;
use App\Jobs\SendDataSutranBatch;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;


class DispatchSutranJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle()
    {

        $users = UserRepo::getActiveServiceManagers(1, 'sutran');

        foreach ($users as $user) {

            $plates = $user->devices()->where('mtc', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::info('No plates found for user: ' . $user->id . '. Job terminated.');
                continue;
            }

            Log::info('Dispatching Sutran Jobs for user: ' . $user->id . ' with plates: ' . implode(', ', $plates));
            $service = $user->services['sutran'];

            $batchSize = 2000; // Tamaño del lote
            $totalRecords = Sutran::whereIn('plate', $plates)->count();

            for ($i = 0; $i < $totalRecords; $i += $batchSize) {
                SendDataSutranBatch::dispatch($i, $batchSize, $service, $plates);
            }
        }
    }
}
