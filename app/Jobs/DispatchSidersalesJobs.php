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
use Tobuli\Entities\Sidersales;

class DispatchSidersalesJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const URL_DEV = 'https://dev-experience-gerdau-sidersales-pe.us-e1.cloudhub.io/api/gps-tracking';
    const URL_QA  = 'https://qa-experience-gerdau-sidersales-pe.us-e1.cloudhub.io/api/gps-tracking';
    const URL_PRD = 'https://api.gerdau.com/experience-gerdau-sidersales-pe/api/gps-tracking';

    public $tries = 3;

    public function handle()
    {
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

            $plates = $user->devices()->where('sidersales', 1)->get()->pluck('plate_number')->map(function ($plate) {
                return trim($plate);
            })->toArray();

            if (empty($plates)) {
                Log::channel('sidersales')->info('Sin placas activas para usuario: ' . $user->id . '. Job omitido.');
                continue;
            }

            $url = $this->resolveEndpoint($service['environment'] ?? 'prd');
            $batchSize = 500;
            $totalRecords = Sidersales::whereIn('placa', $plates)->count();

            if ($totalRecords === 0) {
                continue;
            }

            Log::channel('sidersales')->info('Despachando lotes Sidersales', [
                'user_id'       => $user->id,
                'total_records' => $totalRecords,
                'plates'        => count($plates),
            ]);

            for ($i = 0; $i < $totalRecords; $i += $batchSize) {
                SendDataSidersales::dispatch($i, $batchSize, $url, $service, $plates, $user->id);
            }
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
