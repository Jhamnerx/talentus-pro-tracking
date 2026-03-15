<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Tobuli\Entities\Mininter;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\User;

class DispatchMininterJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $batchSize = 1000;
        $totalRecords = Mininter::count();
        Log::channel('mininter')->info('Iniciando dispatch de jobs Mininter', ['total_records' => $totalRecords]);

        $user = User::where('id', 1)->first();
        $service = $user->services['mininter'];

        $url_mininter = config('app.env') == 'local' ? config('app.url_mininter_beta') : config('app.url_mininter_prod');

        for ($i = 0; $i < $totalRecords; $i += $batchSize) {

            SendDataMininter::dispatch($i, $batchSize, $url_mininter, $service, $user->id);
        }
    }
}
