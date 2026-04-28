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

            $plates = $user->devices()->where('mtc', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::channel('sutran')->info('Sin placas para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $service = $user->services['sutran'];

            $batchSize = 5000;

            // Usar rangos de IDs en vez de offsets: los offsets se corrompen cuando
            // un job anterior borra registros durante el procesamiento paralelo.
            $ids = Sutran::whereIn('plate', $plates)
                ->orderBy('id')
                ->select('id')
                ->pluck('id');

            foreach ($ids->chunk($batchSize) as $chunk) {
                SendDataSutranBatch::dispatch($chunk->first(), $chunk->last(), $service, $plates, $user->id);
            }
        }
    }
}
