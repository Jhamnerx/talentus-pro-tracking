<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Tobuli\Entities\Sidersales;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Api\SidersalesTokenService;
use Illuminate\Support\Facades\Log;

class SendDataSidersales implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    protected $offset;
    protected $batchSize;
    protected $url;
    protected $service;
    protected $plates;
    protected $userId;
    protected $logService;

    public function __construct(int $offset, int $batchSize, string $url, array $service, array $plates, ?int $userId = null)
    {
        $this->offset    = $offset;
        $this->batchSize = $batchSize;
        $this->url       = $url;
        $this->service   = $service;
        $this->plates    = $plates;
        $this->userId    = $userId;
        $this->onQueue('web-services-sidersales');
    }

    public function handle()
    {
        $this->logService = app(LogService::class);
        $this->logService->setUserId($this->userId);

        try {
            $accessToken = app(SidersalesTokenService::class)->getToken($this->userId, $this->service);
        } catch (\RuntimeException $e) {
            Log::channel('sidersales')->error('Sin access_token para SendDataSidersales. Job abortado.', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        $this->logService->logStart('Sidersales', 'Iniciando procesamiento de tramas Sidersales', [
            'offset'    => $this->offset,
            'batchSize' => $this->batchSize,
        ]);

        $tramas = Sidersales::offset($this->offset)
            ->limit($this->batchSize)
            ->whereIn('placa', $this->plates)
            ->with('device')
            ->get();

        foreach ($tramas as $trama) {
            $payload = [
                'placa'            => $trama->placa,
                'latitud'          => (string) $trama->latitud,
                'longitud'         => (string) $trama->longitud,
                'velocidadKPH'     => (string) $trama->velocidadKPH,
                'fechaHoraGPS'     => $trama->fechaHoraGPS,
                'rucTransportista' => $trama->rucTransportista,
                'ProveedorGPS'     => 'TALENTUS TECHNOLOGY SAC',
            ];

            $this->enviarTrama($trama, $payload, $accessToken);
        }
    }

    protected function enviarTrama(Sidersales $trama, array $payload, string $accessToken): void
    {
        try {
            $client = new Client(['verify' => false]);
            $response = $client->request('POST', $this->url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $status = $response->getStatusCode();

            if ($status === 200 || $status === 201) {
                if (!empty($this->service['logs'])) {
                    $this->logService->logToDatabase(
                        'Sidersales',
                        $trama->placa,
                        'success',
                        $payload,
                        ['message' => 'Registrado correctamente'],
                        [],
                        $trama->fechaHoraGPS,
                        $trama->device->imei ?? null
                    );
                }
                $trama->delete();
            } else {
                if (!empty($this->service['logs'])) {
                    $this->logService->logToDatabase(
                        'Sidersales',
                        $trama->placa,
                        'error',
                        $payload,
                        ['message' => 'Respuesta inesperada: ' . $status],
                        [],
                        $trama->fechaHoraGPS,
                        $trama->device->imei ?? null
                    );
                }
            }
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            Log::channel('sidersales')->error('Error enviando trama Sidersales', [
                'placa' => $trama->placa,
                'error' => $body,
            ]);

            if (!empty($this->service['logs'])) {
                $this->logService->logToDatabase(
                    'Sidersales',
                    $trama->placa,
                    'error',
                    $payload,
                    ['message' => $body],
                    [],
                    $trama->fechaHoraGPS,
                    $trama->device->imei ?? null
                );
            }
        }
    }
}
