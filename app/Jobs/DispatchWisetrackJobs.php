<?php

namespace App\Jobs;

use App\Jobs\SendDataWisetrackJob;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\WiseTrack;

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

            $batchSize = 500;
            $total = WiseTrack::whereIn('patente', $plates)->count();
            if ($total === 0) {
                continue;
            }

            $service = $user->services['wisetrack'];

            // Cada job procesa y ELIMINA su lote, por lo que el siguiente debe volver a
            // consultar desde el inicio (offset 0). Por eso despachamos ceil(total/batch)
            // jobs con offset 0 en vez de ir avanzando el offset (que se rompería al borrar).
            $jobCount = (int) ceil($total / $batchSize);

            Log::channel('wisetrack')->info('Despachando jobs Wisetrack', [
                'user_id' => $user->id,
                'total'   => $total,
                'jobs'    => $jobCount,
            ]);

            for ($i = 0; $i < $jobCount; $i++) {
                SendDataWisetrackJob::dispatch(0, $batchSize, $service, $plates, $user->id);
            }
        }
    }
}
