<?php

namespace App\Jobs;

use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Tobuli\Entities\Osinergmin;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SendDataOsinergmin extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $offset;
    protected $batchSize;
    protected $service;
    protected $plates;
    protected $logService;
    public $tries = 5; // Intentos permitidos


    public function __construct($offset, $batchSize, $service, $plates)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->service = $service;
        $this->plates = $plates;

        $this->onQueue('web-services-osinergmin');
    }

    public function handle()
    {
        $this->logService = app(LogService::class);
        $this->getTramas();
    }

    public function getTramas()
    {
        $positions = Osinergmin::offset($this->offset)
            ->limit($this->batchSize)->whereIn('plate', $this->plates)
            ->with('device')->orderBy('created_at')->get();

        $tramas = [];
        Log::channel('osinergmin')->info('Processing Osinergmin positions', [
            'count' => $positions->count(),
            'offset' => $this->offset,
            'batchSize' => $this->batchSize,
            'positions' => $positions->pluck('plate')->toArray(),
        ]);

        foreach ($positions as $position) {
            // Creación del evento (puedes ajustarlo según las reglas del manual)
            $event = 'none';

            $trama = [
                'event' => $event,
                'gpsDate' => gmdate('Y-m-d\TH:i:s.v\Z', $position->timestamp), // fomato y hora en UTC
                'plate' => $position->plate,
                'speed' => doubleval($position->speed),
                'position' => [
                    'latitude' => doubleval($position->latitud),
                    'longitude' => doubleval($position->longitud),
                    'altitude' => doubleval($position->altitude),
                ],
                'tokenTrama' => $this->service['token'],
                'odometer' => round($position->other['totaldistance']),
                'uuid' => $position->id,
                'imei' => intval($position->device->imei)
            ];

            $tramas[] = $trama;
        }

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

            $responseBody = json_decode($response->getBody()->getContents(), true);
            $this->actionAfterSend($responseBody, $tramas);
        } catch (RequestException $e) {
            Log::channel('osinergmin')->error('Request failed for Osinergmin API', [
                'error' => $e->getMessage(),
                'tramas_count' => count($tramas),
                'plates' => array_column($tramas, 'plate')
            ]);

            if ($this->service['logs']) {
                $this->logError($e, $tramas);
            }
        }
    }

    protected function actionAfterSend($response, $tramas)
    {
        $ids = [];
        $errorsId = [];

        // Iterar a través de la respuesta para manejar éxito y errores
        foreach ($response['data'] as $index => $data) {
            $trama = $tramas[$index];
            Log::channel('osinergmin')->info('Processing Osinergmin response', [
                'data' => $data,
                'trama' => $trama,
            ]);
            if ($data['uuid'] === $trama['uuid']) {

                if ($data['status'] === 'CREATED') {
                    $ids[] = $data['uuid'];
                    if ($this->service['logs'])
                        $this->handleSuccess($data, $trama);
                }

                if ($data['status'] === 'ERROR') {
                    $errorsId[] = $data['uuid'];

                    if ($this->service['logs'])
                        $this->handleError($data, $trama);
                }
            }
        }

        // Eliminar registros
        if (!empty($ids)) {
            Osinergmin::destroy($ids);
        }
        if (!empty($errorsId)) {
            Osinergmin::destroy($errorsId);
        }
    }

    protected function handleSuccess(array $response, array $trama): void
    {
        try {
            $this->logService->logToDatabase(
                'Osinergmin',
                $trama['plate'],
                'success',
                $trama,
                ['message' => $response],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $trama['imei']
            );
        } catch (\Throwable $th) {
            Log::channel('osinergmin')->error('Error logging Osinergmin success: ' . $th->getMessage(), [
                'trama' => $trama,
                'response' => $response,
            ]);
        }
    }

    protected function handleError(array $response, array $trama): void
    {
        try {
            $this->logService->logToDatabase(
                'Osinergmin',
                $trama['plate'],
                'error',
                $trama,
                ['message' => $response['message'] . ' - ' . ($response['suggestion'] ?? 'No suggestion')],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $trama['imei']
            );
        } catch (\Throwable $th) {
            Log::channel('osinergmin')->error('Error logging Osinergmin error to database: ' . $th->getMessage(), [
                'trama' => $trama,
                'response' => $response,
            ]);
        }
    }

    protected function logError(RequestException $e, array $tramas): void
    {
        // Tomar la primera trama para el log de error
        $trama = $tramas[0] ?? [];

        try {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = json_decode($response->getBody()->getContents(), true);

                $this->logService->logToDatabase(
                    'Osinergmin',
                    $trama['plate'] ?? 'unknown',
                    $body['status'] ?? 'error',
                    $trama,
                    ['message' => ($body['message'] ?? 'Unknown error') . ' - ' . ($body['suggestion'] ?? 'No suggestion')],
                    [],
                    isset($trama['gpsDate']) ? Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s') : null,
                    $trama['imei'] ?? null
                );
            } else {
                // Error sin respuesta del servidor
                $this->logService->logToDatabase(
                    'Osinergmin',
                    $trama['plate'] ?? 'unknown',
                    'connection_error',
                    $trama,
                    ['message' => 'Connection error: ' . $e->getMessage()],
                    [],
                    isset($trama['gpsDate']) ? Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s') : null,
                    $trama['imei'] ?? null
                );
            }
        } catch (\Throwable $th) {
            Log::channel('osinergmin')->error('Error logging Osinergmin error to database: ' . $th->getMessage(), [
                'original_exception' => $e->getMessage(),
                'trama' => $trama,
            ]);
        }
    }
}
