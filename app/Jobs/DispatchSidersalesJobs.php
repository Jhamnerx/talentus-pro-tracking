<?php

namespace App\Jobs;

use App\Services\Api\SidersalesTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tobuli\Entities\Sidersales;

class DispatchSidersalesJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const URL_DEV = 'https://dev-experience-gerdau-sidersales-pe.us-e1.cloudhub.io/api/gps-tracking';
    const URL_QA  = 'https://qa-experience-gerdau-sidersales-pe.us-e1.cloudhub.io/api/gps-tracking';
    const URL_PRD = 'https://api.gerdau.com/experience-gerdau-sidersales-pe/api/gps-tracking';

    const QUEUE_NAME = 'web-services-sidersales';

    /** Filas por job. Cada trama es un POST independiente, asi que el lote debe
     *  caber en el --timeout del worker (ver deadline en SendDataSidersales). */
    const BATCH_SIZE = 200;

    /** Tope de jobs por ciclo: evita que un backlog historico (placa reactivada
     *  despues de meses) encole decenas de miles de jobs de una sola vez. */
    const MAX_BATCHES_PER_RUN = 100;

    public $tries = 3;

    public function handle()
    {
        // Backpressure. El scheduler corre cada minuto, pero las filas solo se
        // borran cuando Gerdau responde 200, asi que un lote tarda mucho mas de
        // 60s en drenarse. Sin este guard el dispatcher vuelve a encolar CADA
        // MINUTO las mismas filas pendientes y la cola crece sin limite.
        $pending = Queue::size(self::QUEUE_NAME);
        if ($pending > 0) {
            Log::channel('sidersales')->info("DispatchSidersalesJobs omitido: {$pending} jobs aun en cola.");
            return;
        }

        $users = UserRepo::getActiveServiceManagers(1, 'sidersales');
        Log::channel('sidersales')->info('Iniciando dispatch de jobs Sidersales', ['user_count' => $users->count()]);

        foreach ($users as $user) {
            $service = $user->services['sidersales'] ?? [];

            try {
                $accessToken = app(SidersalesTokenService::class)->getToken($user->id, $service);
            } catch (\RuntimeException $e) {
                Log::channel('sidersales')->error('No se pudo obtener access_token Sidersales. Job omitido.', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
                continue;
            }

            $plates = $user->devices()
                ->where('sidersales', 1)
                ->pluck('plate_number')
                ->map(function ($plate) {
                    return trim($plate);
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            if (empty($plates)) {
                Log::channel('sidersales')->info('Sin placas activas para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $url     = $this->resolveEndpoint($service['environment'] ?? 'prd');
            $batches = 0;
            $capped  = false;

            // Rangos de id en vez de offsets: con millones de filas pendientes
            // "offset 3000000" obliga a MySQL a escanear 3M filas por job, y los
            // offsets se corrompen porque los jobs borran filas en paralelo.
            // chunkById avanza con "id > ultimo", asi que resiste los borrados.
            Sidersales::whereIn('placa', $plates)
                ->select('id')
                ->chunkById(self::BATCH_SIZE, function ($chunk) use ($url, $service, $plates, $user, &$batches, &$capped) {
                    if ($batches >= self::MAX_BATCHES_PER_RUN) {
                        $capped = true;
                        return false; // corta el chunkById; el resto va en el siguiente ciclo
                    }

                    SendDataSidersales::dispatch(
                        $chunk->first()->id,
                        $chunk->last()->id,
                        $url,
                        $service,
                        $plates,
                        $user->id
                    );
                    $batches++;
                }, 'id');

            if ($batches === 0) {
                continue;
            }

            Log::channel('sidersales')->info('Despachando lotes Sidersales', [
                'user_id' => $user->id,
                'plates'  => count($plates),
                'batches' => $batches,
                'capped'  => $capped, // true = quedo backlog pendiente para el proximo ciclo
            ]);
        }
    }

    protected function resolveEndpoint(string $environment): string
    {
        return match ($environment) {
            'dev'   => self::URL_DEV,
            'qa'    => self::URL_QA,
            default => self::URL_PRD,
        };
    }
}
