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

    /** Margen antes del $timeout: al agotarse el job corta y deja las tramas
     *  restantes en la tabla (solo se borran al enviarse OK), asi no muere a
     *  medias por SIGKILL del worker ni se reintenta el lote completo. */
    const DEADLINE_MARGIN = 20;

    protected $minId;
    protected $maxId;
    protected $url;
    protected $service;
    protected $plates;
    protected $userId;
    protected $logService;

    public function __construct(int $minId, int $maxId, string $url, array $service, array $plates, ?int $userId = null)
    {
        $this->minId   = $minId;
        $this->maxId   = $maxId;
        $this->url     = $url;
        $this->service = $service;
        $this->plates  = $plates;
        $this->userId  = $userId;
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
            'minId' => $this->minId,
            'maxId' => $this->maxId,
        ]);

        // Rango de ids (no offset): resiste los borrados de los jobs en paralelo
        // y usa la PK en vez de escanear millones de filas.
        $tramas = Sidersales::whereBetween('id', [$this->minId, $this->maxId])
            ->whereIn('placa', $this->plates)
            ->with('device')
            ->get();

        // Un solo cliente para todo el lote (antes se instanciaba uno por trama).
        // Sin timeout explicito Guzzle usa 0 = infinito: si Gerdau deja el socket
        // colgado el worker se bloquea para siempre y no lo saca ni queue:restart.
        $client = new Client([
            'verify'          => false,
            'connect_timeout' => 10,
            'timeout'         => 30,
        ]);

        $deadline = microtime(true) + ($this->timeout - self::DEADLINE_MARGIN);
        $enviadas = 0;

        foreach ($tramas as $trama) {
            if (microtime(true) >= $deadline) {
                Log::channel('sidersales')->warning('Lote Sidersales cortado por deadline', [
                    'user_id'   => $this->userId,
                    'minId'     => $this->minId,
                    'maxId'     => $this->maxId,
                    'enviadas'  => $enviadas,
                    'restantes' => $tramas->count() - $enviadas,
                ]);
                break;
            }

            $payload = [
                'placa'            => $trama->placa,
                'latitud'          => (string) $trama->latitud,
                'longitud'         => (string) $trama->longitud,
                'velocidadKPH'     => (string) $trama->velocidadKPH,
                'fechaHoraGPS'     => $trama->fechaHoraGPS,
                'rucTransportista' => $trama->rucTransportista,
                'ProveedorGPS'     => 'TALENTUS TECHNOLOGY SAC',
            ];

            $this->enviarTrama($client, $trama, $payload, $accessToken);
            $enviadas++;
        }
    }

    protected function enviarTrama(Client $client, Sidersales $trama, array $payload, string $accessToken): void
    {
        try {
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
