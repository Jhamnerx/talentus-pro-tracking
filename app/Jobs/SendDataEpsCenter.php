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
        $registros = EpsCenter::offset($this->offset)
            ->limit($this->batchSize)
            ->get();

        $client = new Client(['verify' => false]);

        foreach ($registros as $registro) {
            $this->enviarRegistro($client, $registro);
        }
    }

    protected function enviarRegistro(Client $client, EpsCenter $registro): void
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
            } else {
                Log::channel('epscenter')->warning('Respuesta inesperada de EPS Center', [
                    'device_id' => $registro->device_id,
                    'status'    => $status,
                    'body'      => (string) $response->getBody(),
                ]);
            }
        } catch (RequestException $e) {
            Log::channel('epscenter')->error('Error al enviar registro a EPS Center', [
                'device_id' => $registro->device_id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
