<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Services\LogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tobuli\Entities\Osinergmin;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class SendDataOsinergmin extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $offset;
    protected $batchSize;
    protected $token;
    protected $plates;
    protected $logService;
    public $tries = 5; // Intentos permitidos
    public $timeout = 300;


    public function __construct($offset, $batchSize, $token, $plates)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->token = $token;
        $this->plates = $plates;

        $this->onQueue('web-services');
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Instanciar el servicio de logging
        $this->logService = app(LogService::class);

        // Obtener y enviar las tramas
        $this->getTramas();
    }

    public function getTramas()
    {
        // Obtener las posiciones desde la entidad de Osinergmin
        $positions = Osinergmin::offset($this->offset)
            ->limit($this->batchSize)->whereIn('plate', $this->plates)
            ->with('device')->orderBy('created_at')->get();


        $tramas = [];

        foreach ($positions as $position) {
            // Creación del evento (puedes ajustarlo según las reglas del manual)
            $event = 'none';

            // Generar el objeto JSON para este dispositivo
            $trama = [
                'event' => $event,
                'gpsDate' => gmdate('Y-m-d\TH:i:s.v\Z', $position->timestamp), // Asegúrate del formato correcto de fecha y hora
                'plate' => $position->plate,
                'speed' => doubleval($position->speed),
                'position' => [
                    'latitude' => doubleval($position->latitud),
                    'longitude' => doubleval($position->longitud),
                    'altitude' => doubleval($position->altitude),
                ],
                'tokenTrama' => $this->token,
                'odometer' => round($position->other['totaldistance']),
                'uuid' => $position->id,
            ];

            // Agregar la trama al array de tramas
            $tramas[] = $trama;
        }

        // Enviar las tramas en lotes
        $this->sendDataOsinergmin($tramas);
    }

    public function sendDataOsinergmin($tramas)
    {
        // Dividir y enviar las tramas de acuerdo al tamaño permitido (150 en este caso, puedes ajustar según el manual)
        $tramas_por_grupo = array_chunk($tramas, 250);

        foreach ($tramas_por_grupo as $grupo) {
            $this->enviarTramas($grupo);
        }
    }

    public function enviarTramas($tramas)
    {
        $json = json_encode($tramas);

        try {
            $client = new Client(['verify' => false]);
            $response = $client->request('POST', 'https://prod.osinergmin-agent-2021.com/api/v1/trama-batch', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => $json,
            ]);

            $responseBody = $response->getBody()->getContents();
            $this->actionAfterSend(json_decode($responseBody, true));
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = $response->getBody()->getContents();

                // Guardar log de error en la base de datos
                $this->logService->logToDatabase(
                    'Osinergmin',
                    'N/A',
                    "Error en la respuesta: " . $body,
                    'error',
                    $tramas
                );
            } else {
                // Guardar log de error en la base de datos si no hay respuesta
                $this->logService->logToDatabase(
                    'Osinergmin',
                    'N/A',
                    "Error en la solicitud: " . $e->getMessage(),
                    'error',
                    ['json' => $tramas]
                );
            }
        }
    }

    public function actionAfterSend($response)
    {
        $ids = [];
        $errorsId = [];

        // Iterar a través de la respuesta para manejar éxito y errores
        foreach ($response['data'] as $item) {
            if ($item['status'] === 'CREATED') {
                $ids[] = $item['uuid'];
            }

            if ($item['status'] === 'ERROR') {
                $errorsId[] = $item['uuid'];

                // Guardar el error en la base de datos
                $this->logService->logToDatabase(
                    'Osinergmin',
                    'N/A',
                    "Error en la trama: " . $item['message'] . " - Sugerencia: " . $item['suggestion'],
                    'error',
                    $item
                );
            }
        }

        // Guardar logs de éxito y fallos en la base de datos
        // $this->logService->logToDatabase(
        //     'Osinergmin',
        //     'N/A',
        //     "Resultados: Correctos: " . $response['metadata']['succes'] . ", Fallos: " . $response['metadata']['failure'] . " - Total: " . $response['metadata']['total'],
        //     'info',
        //     $response['metadata']
        // );

        // Eliminar registros en caso de éxito
        if (!empty($ids)) {
            Osinergmin::destroy($ids);
        }
        if (!empty($errorsId)) {
            Osinergmin::destroy($errorsId);
        }
    }
}
