<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\WiseTrack;

use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class DispatchWisetrackJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function handle()
    {
        $users = UserRepo::getActiveServiceManagers(1, 'wisetrack');
        foreach ($users as $user) {

            $plates = $user->devices()->where('wisetrack', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::info('No plates Wisetrack found for user: ' . $user->id . '. Job terminated.');
                continue;
            }

            $service = $user->services['wisetrack'];
            $batchSize = 1800; // Tamaño del lote
            $totalRecords = WiseTrack::whereIn('patente', $plates)->count();

            for ($i = 0; $i < $totalRecords; $i += $batchSize) {
                SendDataWisetrackJob::dispatch($i, $batchSize, $service, $plates, $user->id);
            }
        }
    }
}
