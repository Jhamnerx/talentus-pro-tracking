<?php

namespace App\Jobs;

use App\Services\LogService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\EpsCenter;

class SendDataEpsCenter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 3;
    public $timeout = 120;

    protected $offset;
    protected $batchSize;
    protected $url;
    protected $service;
    protected $imeis;
    protected $userId;
    protected $logService;

    public function __construct(int $offset, int $batchSize, string $url, array $service = [], array $imeis = [], ?int $userId = null)
    {
        $this->offset    = $offset;
        $this->batchSize = $batchSize;
        $this->url       = $url;
        $this->service   = $service;
        $this->imeis     = $imeis;
        $this->userId    = $userId;
        $this->onQueue('web-services-epscenter');
    }

    public function handle()
    {
        $this->logService = app(LogService::class);
        $this->logService->setUserId($this->userId);

        $query = EpsCenter::whereNotNull('id_transporte');

        if (!empty($this->imeis)) {
            $query->whereIn('id_transporte', $this->imeis);
        }

        $registros = $query->offset($this->offset)
            ->limit($this->batchSize)
            ->get();

        $client = new Client(['verify' => false]);

        foreach ($registros as $registro) {
            $rateLimited = $this->enviarRegistro($client, $registro);

            if ($rateLimited) {
                Log::channel('epscenter')->warning('Rate limit alcanzado (429), reencolar en 60s');
                $this->release(60);
                return;
            }
        }
    }

    /**
     * @return bool  true si se recibió 429 (rate limited), false en caso contrario
     */
    protected function enviarRegistro(Client $client, EpsCenter $registro): bool
    {
        $params = [
            'id_empresa'    => $registro->id_empresa,
            'id_transporte' => $registro->id_transporte,
            'telefono'      => $registro->telefono,
            'latitud'       => $registro->latitud,
            'longitud'      => $registro->longitud,
            'fecha'         => $registro->fecha,
            'hora'          => $registro->hora,
            'velocidad'     => $registro->velocidad,
            'bateria'       => $registro->bateria,
            'mensaje'       => $registro->mensaje,
        ];

        try {
            $response = $client->request('POST', $this->url, [
                'query'   => $params,
                'body'    => '',  // La API requiere body (aunque vacío) en POST
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();

            if ($status === 200 || $status === 201) {
                if (!empty($this->service['logs'])) {
                    $this->logService->logToDatabase(
                        'EpsCenter',
                        $registro->id_transporte,
                        'success',
                        $params,
                        ['status' => $status, 'message' => 'Registro exitoso.'],
                        [],
                        $registro->fecha . ' ' . $registro->hora,
                        $registro->id_transporte
                    );
                }

                Log::channel('epscenter')->info('Registro EPS Center enviado correctamente', [
                    'device_id'     => $registro->device_id,
                    'id_transporte' => $registro->id_transporte,
                    'fecha'         => $registro->fecha,
                    'hora'          => $registro->hora,
                    'status'        => $status,
                ]);

                $registro->delete();

                return false;
            } else {
                if (!empty($this->service['logs'])) {
                    $this->logService->logToDatabase(
                        'EpsCenter',
                        $registro->id_transporte,
                        'error',
                        $params,
                        ['status' => $status, 'message' => 'Respuesta inesperada'],
                        [],
                        $registro->fecha . ' ' . $registro->hora,
                        $registro->id_transporte
                    );
                }

                Log::channel('epscenter')->warning('Respuesta inesperada de EPS Center', [
                    'device_id' => $registro->device_id,
                    'status'    => $status,
                    'body'      => (string) $response->getBody(),
                ]);

                return false;
            }
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $status = $e->getResponse()->getStatusCode();

                if ($status === 429) {
                    return true; // rate limited — detener batch
                }

                $body = (string) $e->getResponse()->getBody();

                if (!empty($this->service['logs'])) {
                    $this->logService->logToDatabase(
                        'EpsCenter',
                        $registro->id_transporte,
                        'error',
                        $params,
                        ['status' => $status, 'message' => $body],
                        [],
                        $registro->fecha . ' ' . $registro->hora,
                        $registro->id_transporte
                    );
                }

                Log::channel('epscenter')->error('Error al enviar registro a EPS Center', [
                    'device_id' => $registro->device_id,
                    'status'    => $status,
                    'response'  => $body,
                ]);
            } else {
                $errorMsg = $e->getMessage();

                if (!empty($this->service['logs'])) {
                    $this->logService->logToDatabase(
                        'EpsCenter',
                        $registro->id_transporte,
                        'error',
                        $params,
                        ['message' => $errorMsg],
                        [],
                        $registro->fecha . ' ' . $registro->hora,
                        $registro->id_transporte
                    );
                }

                Log::channel('epscenter')->error('Error al enviar registro a EPS Center', [
                    'device_id' => $registro->device_id,
                    'error'     => $errorMsg,
                ]);
            }

            return false;
        }
    }
}
