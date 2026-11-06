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
use Tobuli\Entities\Securitas;
use Tobuli\Services\Securitas\SecuritasClient;

/**
 * Programado cada minuto (Kernel). Por cada gestor con Securitas activo, parte la
 * cola `securitas` de sus dispositivos en lotes de hasta 300 eventos (maximo de
 * GlobalAVL por peticion) y despacha un SendDataSecuritasJob por lote.
 */
class DispatchSecuritasJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'web-services-securitas';
    public const BATCH_SIZE = SecuritasClient::MAX_EVENTS_PER_REQUEST;
    public const MAX_BATCHES_PER_RUN = 40;

    public function handle()
    {
        // Backpressure: si aun quedan jobs en cola no se vuelven a encolar las
        // mismas filas (GlobalAVL no deduplica: reenviar = duplicar en su plataforma).
        $pending = Queue::size(self::QUEUE_NAME);
        if ($pending > 0) {
            Log::channel('securitas')->info("DispatchSecuritasJobs omitido: {$pending} jobs aun en cola.");
            return;
        }

        $users = UserRepo::getActiveServiceManagers(1, 'securitas');

        foreach ($users as $user) {
            $service = $user->services['securitas'] ?? [];

            if (empty($service['url']) || empty($service['api_key'])) {
                Log::channel('securitas')->error('Securitas activo pero sin URL/API key. Job omitido.', [
                    'user_id' => $user->id,
                ]);
                continue;
            }

            $deviceIds = $user->devices()
                ->where('securitas', 1)
                ->pluck('devices.id')
                ->all();

            if (empty($deviceIds)) {
                continue;
            }

            $batches = 0;
            $capped  = false;

            Securitas::whereIn('device_id', $deviceIds)
                ->select('id')
                ->chunkById(self::BATCH_SIZE, function ($chunk) use ($service, $deviceIds, $user, &$batches, &$capped) {
                    if ($batches >= self::MAX_BATCHES_PER_RUN) {
                        $capped = true;
                        return false; // el resto va en el siguiente ciclo
                    }

                    SendDataSecuritasJob::dispatch(
                        $chunk->first()->id,
                        $chunk->last()->id,
                        $service,
                        $deviceIds,
                        $user->id
                    );

                    $batches++;
                });

            if ($batches > 0) {
                Log::channel('securitas')->info('Jobs Securitas despachados', [
                    'user_id' => $user->id,
                    'devices' => count($deviceIds),
                    'batches' => $batches,
                    'capped'  => $capped,
                ]);
            }
        }
    }
}
