<?php

namespace App\Jobs;

use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Tobuli\Entities\Comsatel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class SendDataComsatelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $logService;
    protected $offset;
    protected $batchSize;
    protected $plates;
    protected $user;
    protected $pass;

    public $tries = 3;
    public $timeout = 120;

    public function __construct($offset, $batchSize, $user, $pass, $plates)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->plates = $plates;
        $this->user = $user;
        $this->pass = $pass;

        $this->onQueue('web-services');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Instanciar el servicio de logging directamente aquí
        $this->logService = app(LogService::class);

        try {
            $this->sendPositions();
        } catch (\Exception $e) {
            $this->logError($e);
        }
    }

    /**
     * Send positions to the Comsatel API.
     */
    protected function sendPositions()
    {
        // Obtenemos las posiciones
        $positions = Comsatel::offset($this->offset)
            ->limit($this->batchSize)->whereIn('placa', $this->plates)
            ->with('device')
            ->get();


        $url = config('app.url_comsatel_prod');
        if ($positions->isEmpty()) {
            Log::info('No hay posiciones para enviar a Comsatel.');
            return;
        }

        foreach ($positions as $position) {
            // Generamos la trama siguiendo las especificaciones del PDF
            $json = [
                "posicionId" => $position->id,
                "vehiculoId" => str_replace('-', '', $position->placa),
                "velocidad" => intval($position->velocidad),
                "satelites" => intval($position->satelites),
                "rumbo" => intval($position->rumbo),
                "latitud" => doubleval($position->latitud),
                "longitud" => doubleval($position->longitud),
                "altitud" => floatval($position->altitud),
                // Convertir el timestamp a horario de Perú (UTC -5) y formatear a YmdHis
                "gpsDateTime" => Carbon::createFromTimestamp($position->timestamp)
                    ->format('YmdHis'),  // Formato YmdHis
                "sendDateTime" => gmdate('YmdHis'),
                "evento" => intval($position->evento),
                "ignition" => intval($position->ignition),
                "odometro" => intval($position->odometro),
                "horometro" => intval($position->horometro),
                "nivelBateria" => intval($position->batery_level),
                "valido" => intval($position->valid),
                "fuente" => $position->fuente
            ];

            $trama = [$json];  // Enviar la trama como un array de un solo elemento
            $this->sendToComsatel($trama, $url);
        }
    }

    /**
     * Enviar las tramas a Comsatel en lotes de 150.
     *
     * @param array $tramas
     */
    protected function sendToComsatel($trama, $url)
    {

        $client = new Client();

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json;charset=utf-8',
                    'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->pass),
                    'Aplicacion' => 'SERVICIO_RECOLECTOR',
                ],
                'body' => json_encode($trama, JSON_PRESERVE_ZERO_FRACTION),
            ]);

            $this->processResponse(json_decode($response->getBody()->getContents(), true), $trama[0]['posicionId'], $trama[0]['vehiculoId'], $trama);
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Procesar la respuesta de la API.
     *
     * @param array $response
     * @param int $posicionId
     * @param string $plateNumber
     */
    protected function processResponse(array $response, $posicionId, $plateNumber, $json)
    {
        $service = 'Comsatel'; // Define el nombre del servicio

        if ($response['statusCode'] == 200) {
            // Guardar log en la base de datos
            // $this->logService->logToDatabase($service, $plateNumber, "Envío exitoso: " . json_encode($json), 'info', $response);

            // Eliminar el registro si la respuesta fue exitosa
            DB::transaction(function () use ($posicionId) {
                Comsatel::where('id', $posicionId)->delete();
            });
        } else {
            // Guardar log en la base de datos para errores
            $this->logService->logToDatabase($service, $plateNumber, "Error en el envío", 'error', $response);
        }
    }

    /**
     * Log errores generales.
     *
     * @param \Exception $e
     */
    protected function logError(\Exception $e)
    {
        $errorData = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'time' => now(),
        ];

        // Guardar el error en la base de datos usando el servicio
        $this->logService->logToDatabase('Comsatel', 'N/A', 'Error general: ' . $e->getMessage(), 'error', $errorData);
    }

    /**
     * Manejar excepciones de Request.
     *
     * @param RequestException $e
     */
    protected function handleRequestException(RequestException $e)
    {
        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $this->logService->logToDatabase('Comsatel', 'N/A', "Error en la respuesta: " . $body, 'error');
        } else {
            $this->logService->logToDatabase('Comsatel', 'N/A', "Error en la solicitud: " . $e->getMessage(), 'error');
        }
    }
}
