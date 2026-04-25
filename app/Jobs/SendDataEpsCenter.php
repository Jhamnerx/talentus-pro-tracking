<?php

namespace App\Jobs;

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

    public function __construct(int $offset, int $batchSize, string $url)
    {
        $this->offset    = $offset;
        $this->batchSize = $batchSize;
        $this->url       = $url;
        $this->onQueue('web-services-epscenter');
    }

    public function handle()
    {
        $registros = EpsCenter::where('id_empresa', 22)
            ->whereNotNull('id_transporte')
            ->offset($this->offset)
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

                Log::channel('epscenter')->error('Error al enviar registro a EPS Center', [
                    'device_id' => $registro->device_id,
                    'status'    => $status,
                    'response'  => (string) $e->getResponse()->getBody(),
                ]);
            } else {
                Log::channel('epscenter')->error('Error al enviar registro a EPS Center', [
                    'device_id' => $registro->device_id,
                    'error'     => $e->getMessage(),
                ]);
            }

            return false;
        }
    }
}
