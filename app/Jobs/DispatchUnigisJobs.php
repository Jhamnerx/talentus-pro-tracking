<?php

namespace App\Jobs;

use CustomFacades\Repositories\UserRepo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tobuli\Entities\Unigis;

/**
 * Programado cada minuto (Kernel). Por cada gestor con UNIGIS activo, parte la
 * cola `unigis` de sus dispositivos en lotes por rango de id y despacha un
 * SendDataUnigisJob por lote.
 */
class DispatchUnigisJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'web-services-unigis';
    public const BATCH_SIZE = 200;
    public const MAX_BATCHES_PER_RUN = 20;

    public function handle()
    {
        // Backpressure: UNIGIS se llama posicion por posicion (una llamada SOAP
        // cada una), asi que un lote puede tardar mas de 60 s. Si aun quedan
        // jobs en cola no se vuelven a encolar las mismas filas.
        $pending = Queue::size(self::QUEUE_NAME);
        if ($pending > 0) {
            Log::channel('unigis')->info("DispatchUnigisJobs omitido: {$pending} jobs aun en cola.");
            return;
        }

        $users = UserRepo::getActiveServiceManagers(1, 'unigis');
        Log::channel('unigis')->info('Iniciando dispatch de jobs UNIGIS', ['user_count' => $users->count()]);

        foreach ($users as $user) {
            $service = $user->services['unigis'] ?? [];

            if (empty($service['user']) || empty($service['password']) || empty($service['url'])) {
                Log::channel('unigis')->error('UNIGIS activo pero sin credenciales/URL completas. Job omitido.', [
                    'user_id' => $user->id,
                ]);
                continue;
            }

            $deviceIds = $user->devices()
                ->where('unigis', 1)
                ->pluck('devices.id')
                ->all();

            if (empty($deviceIds)) {
                Log::channel('unigis')->info('Sin dispositivos UNIGIS para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $batches = 0;
            $capped  = false;

            Unigis::whereIn('device_id', $deviceIds)
                ->select('id')
                ->chunkById(self::BATCH_SIZE, function ($chunk) use ($service, $deviceIds, $user, &$batches, &$capped) {
                    if ($batches >= self::MAX_BATCHES_PER_RUN) {
                        $capped = true;
                        return false; // el resto va en el siguiente ciclo
                    }

                    SendDataUnigisJob::dispatch(
                        $chunk->first()->id,
                        $chunk->last()->id,
                        $service,
                        $deviceIds,
                        $user->id
                    );

                    $batches++;
                });

            if ($batches > 0) {
                Log::channel('unigis')->info('Jobs UNIGIS despachados', [
                    'user_id' => $user->id,
                    'devices' => count($deviceIds),
                    'batches' => $batches,
                    'capped'  => $capped,
                ]);
            }
        }
    }
}
