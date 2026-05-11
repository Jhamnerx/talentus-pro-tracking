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

class DispatchWisetrackJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle()
    {
        $users = UserRepo::getActiveServiceManagers(1, 'wisetrack');
        Log::channel('wisetrack')->info('Iniciando dispatch de jobs Wisetrack', ['user_count' => $users->count()]);

        foreach ($users as $user) {
            $plates = $user->devices()->where('wisetrack', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::channel('wisetrack')->info('Sin placas para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $total = WiseTrack::whereIn('patente', $plates)->count();
            if ($total === 0) {
                continue;
            }

            $service = $user->services['wisetrack'];

            SendDataWisetrackJob::dispatch(0, 500, $service, $plates, $user->id);
        }
    }
}
