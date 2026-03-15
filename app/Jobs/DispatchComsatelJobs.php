<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\Comsatel;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class DispatchComsatelJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;


    public function handle()
    {
        $users = UserRepo::getActiveServiceManagers(1, 'consatel');
        Log::channel('comsatel')->info('Iniciando dispatch de jobs Comsatel', ['user_count' => $users->count()]);

        foreach ($users as $user) {

            $plates = $user->devices()->where('consatel', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::channel('comsatel')->info('Sin placas para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $service = $user->services['consatel'];
            $batchSize = 500; // Tamaño del lote
            $totalRecords = Comsatel::whereIn('placa', $plates)->count();

            for ($i = 0; $i < $totalRecords; $i += $batchSize) {
                SendDataComsatelJob::dispatch($i, $batchSize, $service, $plates, $user->id);
            }
        }
    }
}
