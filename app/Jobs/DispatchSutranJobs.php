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
        Log::channel('sutran')->info('Iniciando dispatch de jobs Sutran', ['user_count' => $users->count()]);

        foreach ($users as $user) {
            // Cada usuario se aisla: si uno falla, los demas se siguen despachando.
            try {
                $this->dispatchForUser($user);
            } catch (\Throwable $e) {
                Log::channel('sutran')->error('Error despachando jobs Sutran, se continua con el resto de usuarios', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        Log::channel('sutran')->info('Dispatch de jobs Sutran finalizado');
    }

    protected function dispatchForUser($user)
    {
        $plates = $user->devices()
            ->where('devices.mtc', 1)
            ->pluck('devices.plate_number')
            ->map(function ($plate) {
                return trim($plate);
            })
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($plates)) {
            Log::channel('sutran')->info('Sin placas para usuario: ' . $user->id . '. Job omitido.');
            return;
        }

        $service = $user->services['sutran'] ?? null;

        if (empty($service['token'])) {
            Log::channel('sutran')->warning('Servicio Sutran sin token para usuario: ' . $user->id . '. Job omitido.');
            return;
        }

        // 1500 filas = 10 POST de 150 tramas por job (SendDataSutranBatch trocea de a 150).
        // Con 5000 eran 34 POST secuenciales, imposibles de meter en el --timeout=120
        // del worker cuando SUTRAN responde lento.
        $batchSize = 1500;
        $batches   = 0;

        // Usar rangos de IDs en vez de offsets: los offsets se corrompen cuando
        // un job anterior borra registros durante el procesamiento paralelo.
        // chunkById avanza con "id > ultimo", asi que tampoco se corrompe con los
        // borrados, y a diferencia de pluck() no carga todos los ids en memoria.
        Sutran::whereIn('plate', $plates)
            ->select('id')
            ->chunkById($batchSize, function ($chunk) use ($service, $plates, $user, &$batches) {
                SendDataSutranBatch::dispatch(
                    $chunk->first()->id,
                    $chunk->last()->id,
                    $service,
                    $plates,
                    $user->id
                );
                $batches++;
            }, 'id');

        Log::channel('sutran')->info('Jobs Sutran despachados', [
            'user_id' => $user->id,
            'plates'  => count($plates),
            'batches' => $batches,
        ]);
    }
}
