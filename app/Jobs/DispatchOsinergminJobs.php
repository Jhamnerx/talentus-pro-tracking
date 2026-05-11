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

class DispatchOsinergminJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle()
    {
        $users = UserRepo::getActiveServiceManagers(1, 'osinergmin');
        Log::channel('osinergmin')->info('Iniciando dispatch de jobs Osinergmin', ['user_count' => $users->count()]);

        foreach ($users as $user) {
            $plates = $user->devices()->where('osinergmin', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::channel('osinergmin')->info('Sin placas para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $total = Osinergmin::whereIn('plate', $plates)->count();
            if ($total === 0) {
                continue;
            }

            $service = $user->services['osinergmin'];

            SendDataOsinergmin::dispatch(0, 2000, $service, $plates, $user->id)->onQueue('web-services-osinergmin');
        }
    }
}
