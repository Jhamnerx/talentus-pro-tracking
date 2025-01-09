<?php

namespace App\Jobs;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use App\Services\LogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\Sutran;

class SendDataSutranBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $offset;
    protected $batchSize;
    protected $logService;
    protected $service;
    protected $plates;

    public $tries = 3;
    public $timeout = 120;

    public function __construct($offset, $batchSize, $service, $plates)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->service = $service;
        $this->plates = $plates;
        $this->onQueue('web-services');
    }

    public function handle()
    {

        $this->logService = app(LogService::class); // Instanciar el servicio de logs

        $positions = Sutran::offset($this->offset)
            ->limit($this->batchSize)
            ->whereIn('plate', $this->plates)
            ->with('device')
            ->get();
        $tramas = [];

        foreach ($positions as $position) {

            if ($position->direction > 360) {
                $position->delete(); // Eliminar trama con dirección inválida
                continue;
            }

            $estado = $position['speed'] == 0 ? 'PA' : ($position['speed'] > 5 ? 'ER' : 'PA');
            $timestamp = strtotime($position['time_device']);
            $timezone = new DateTimeZone('America/Lima'); // Asegurar que es GMT-5
            $date = new DateTime();
            $date->setTimestamp($timestamp);
            $date->setTimezone($timezone);
            $gps_date = $date->format('Y-m-d H:i:s');
            $trama = [
                'id' => $position->id,
                'plate' => trim(str_replace('-', '', $position->plate)),
                'geo' => [$position->latitud, $position->longitud],
                'direction' => intval($position->direction ?? 0),
                'event' => $estado,
                'speed' => intval($position->speed), // Confirmar que el valor sea entero
                'time_device' => $gps_date,
                'imei' => intval($position->device->imei)
            ];

            $tramas[] = $trama;
        }

        $this->sendDataSutran($tramas);
    }

    public function sendDataSutran($tramas)
    {

        $ip_sutran = config('app.env') == 'local' ? 'https://ws03.sutran.ehg.pe/api/v1.0/transmisiones' : 'https://ws03.sutran.gob.pe/api/v1.0/transmisiones';
        $tramas_por_grupo = array_chunk($tramas, 150); // Limitar a 150 tramas como máximo según la documentación

        foreach ($tramas_por_grupo as $grupo) {
            $this->enviarTramas($grupo, $this->service['token'], $ip_sutran);
        }
    }

    public function enviarTramas($tramas, $token, $ip_sutran)
    {

        try {
            $client = new Client(['verify' => false]);
            $response = $client->request('POST', $ip_sutran, [
                'headers' => [
                    'access-token' => $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $tramas,
            ]);

            $responseSutran = $response->getBody()->getContents();
            $this->actionAfterSend($tramas, json_decode($responseSutran, true));
        } catch (RequestException $e) {

            if ($e->hasResponse()) {

                $response = $e->getResponse();
                $body = $response->getBody()->getContents();

                // Guardar log de error en la base de datos
                $this->logService->logToDatabase(
                    'Sutran',
                    'N/A',
                    'error',
                    $tramas,
                    ['message - token ' . $token . '' => $body],
                    [],
                    null,
                    null
                );
            }
        }
    }

    public function actionAfterSend($tramas, $response)
    {
        $ids = [];

        if ($response['status'] == 200 && empty($response['error_plates'])) {

            foreach ($tramas as $item) {
                $ids[] = $item['id'];
            }

            if ($this->service['logs']) {
                foreach ($tramas as $trama) {

                    $this->logService->logToDatabase(
                        'Sutran',
                        $trama['plate'],
                        'success',
                        $trama,
                        ['message' => 'Registrado correctamente'],
                        [],
                        $trama['time_device'],
                        $trama['imei']
                    );
                }
            }

            Sutran::destroy($ids);
        } else {

            $errored_rows = [];
            foreach ($response['error_plates'] as $error) {
                if (preg_match('/F:(\d+)/', $error['message'], $matches)) {
                    $errored_rows[intval($matches[1])] = $error['message'];
                }
            }

            if ($this->service['logs']) {

                foreach ($tramas as $index => $trama) {

                    if (array_key_exists($index, $errored_rows)) {

                        $this->logService->logToDatabase(
                            'Sutran',
                            $trama['plate'],
                            'error',
                            $trama,
                            ['message' => $errored_rows[$index]],
                            [],
                            $trama['time_device'],
                            $trama['imei']
                        );
                    } else {

                        $this->logService->logToDatabase(
                            'Sutran',
                            $trama['plate'],
                            'success',
                            $trama,
                            ['message' => 'Registrado correctamente'],
                            [],
                            $trama['time_device'],
                            $trama['imei']
                        );
                    }
                }
            }

            foreach ($tramas as $index => $item) {
                if (!in_array($index, $errored_rows)) {
                    $ids[] = $item['id'];
                }
            }

            Sutran::destroy($ids);
        }
    }
}
