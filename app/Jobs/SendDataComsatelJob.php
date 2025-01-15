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

class SendDataComsatelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $logService;
    protected $offset;
    protected $batchSize;
    protected $plates;
    protected $service;

    public $tries = 3;
    public $timeout = 150;

    public function __construct($offset, $batchSize, $service, $plates)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->plates = $plates;
        $this->service = $service;

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


    protected function sendPositions()
    {
        // Obtenemos las posiciones
        $positions = Comsatel::offset($this->offset)
            ->limit($this->batchSize)->whereIn('placa', $this->plates)
            ->with('device')
            ->orderBy('created_at')->get();


        $url = config('app.url_comsatel_prod');

        if ($positions->isEmpty()) {
            Log::info('No hay posiciones para enviar a Comsatel.');
            return;
        }

        foreach ($positions as $position) {
            $json = [
                "posicionId" => $position->id,
                "vehiculoId" => str_replace('-', '', $position->placa),
                "velocidad" => intval($position->velocidad),
                "satelites" => intval($position->satelites),
                "rumbo" => intval($position->rumbo),
                "latitud" => doubleval($position->latitud),
                "longitud" => doubleval($position->longitud),
                "altitud" => floatval($position->altitud),
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

            $trama = [$json];
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
                    'Authorization' => 'Basic ' . base64_encode($this->service['user'] . ':' . $this->service['pass']),
                    'Aplicacion' => 'SERVICIO_RECOLECTOR',
                ],
                'body' => json_encode($trama, JSON_PRESERVE_ZERO_FRACTION),
            ]);

            $this->processResponse(json_decode($response->getBody()->getContents(), true), $trama[0]['posicionId'], $trama[0]['vehiculoId'], $trama[0]);
        } catch (RequestException $e) {
            $this->handleRequestException($e, $trama);
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
        $statusCode = $response['statusCode'];
        $mensaje = $response['mensaje'];
        $service = "Consatel";

        switch ($statusCode) {
            case 200:
                // Log para respuesta 200
                if ($this->service['logs'])
                    $this->logService->logToDatabase(
                        $service,
                        $plateNumber,
                        'success',
                        $json,
                        ['message' => $mensaje],
                        [],
                        Carbon::parse($json['gpsDateTime'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                        1,
                    );
                // Eliminar el registro si la respuesta fue exitosa
                DB::transaction(function () use ($posicionId) {
                    Comsatel::where('id', $posicionId)->delete();
                });
                break;

            case 400:
                // Log para respuesta 400

                if ($this->service['logs'])
                    $this->logService->logToDatabase(
                        $service,
                        $plateNumber,
                        'success',
                        $json,
                        ['message' => $mensaje],
                        [],
                        Carbon::parse($json['gpsDateTime'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                        1,
                    );
                break;

            case 500:
                // Log para respuesta 500
                if ($this->service['logs'])
                    $this->logService->logToDatabase(
                        $service,
                        $plateNumber,
                        'success',
                        $json,
                        ['message' => $mensaje],
                        [],
                        Carbon::parse($json['gpsDateTime'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                        1,
                    );
                break;

            default:
                // Log para otros casos
                if ($this->service['logs'])
                    $this->logService->logToDatabase(
                        $service,
                        $plateNumber,
                        'other',
                        $json,
                        ['message' => $mensaje],
                        [],
                        Carbon::parse($json['gpsDateTime'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                        1,
                    );
                break;
        }
    }

    /**
     * Log errores generales.
     *
     * @param \Exception $e
     */
    protected function logError(\Exception $e)
    {
        Log::error($e->getMessage());
        Log::error($e->getFile());
        Log::error($e->getLine());
        $errorData = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'time' => now(),
        ];

        // Guardar el error en la base de datos usando el servicio

    }

    /**
     * Manejar excepciones de Request.
     *
     * @param RequestException $e
     */
    protected function handleRequestException(RequestException $e, $trama)
    {
        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $this->logService->logToDatabase(
                'Comsatel',
                $trama[0]['vehiculoId'],
                'error',
                'Error en la solicitud: ' . $body,
                [],
                Carbon::parse($trama[0]['gpsDateTime'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                1,
            );
        }
    }
}
