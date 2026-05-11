<?php

namespace App\Jobs;

use DateTime;
use DateTimeZone;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Tobuli\Entities\Mininter;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendDataMininter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $logService;
    protected $offset;
    protected $batchSize;
    protected $url_mininter;
    protected $service;
    protected $userId;


    public function __construct($offset, $batchSize, $url_mininter, $service, $userId = null)
    {

        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->url_mininter = $url_mininter;
        $this->service = $service;
        $this->userId = $userId;
        $this->onQueue('web-services-mininter');
    }

    public function handle()
    {
        // Instanciar el servicio de logging directamente aquí
        $this->logService = app(LogService::class);
        $this->logService->setUserId($this->userId);
        $this->logService->logStart('Mininter', 'Iniciando procesamiento de tramas Mininter', [
            'offset'    => $this->offset,
            'batchSize' => $this->batchSize,
        ]);

        $this->getTramas();
    }

    public function getTramas()
    {
        $ip = $this->url_mininter;
        $tramas = Mininter::offset($this->offset)
            ->limit($this->batchSize)
            ->with('device', 'device.users')->get();

        // Preparar payloads indexados por ID
        $payloads = [];
        foreach ($tramas as $trama) {
            $idMunicipalidad = $trama->idMunicipalidad;
            $ubigeo          = $trama->ubigeo;

            if ($idMunicipalidad == '' || $ubigeo == '') {
                $user = $trama->device->users->where('is_municipalidad', true)->first();
                if ($user && $user->is_municipalidad) {
                    if ($idMunicipalidad == '') $idMunicipalidad = $user->token_muni;
                    if ($ubigeo == '')          $ubigeo          = $user->ubigeo_muni;
                }
            }

            $datetime = new DateTime('@' . $trama['timestamp']);
            $datetime->setTimezone(new DateTimeZone('America/Lima'));

            $payloads[$trama->id] = [
                'id'              => $trama->id,
                'alarma'          => $trama->alarma,
                'altitud'         => $trama->altitud,
                'angulo'          => $trama->angulo,
                'distancia'       => doubleval($trama->other['distance']),
                'fechaHora'       => $datetime->format('d/m/Y H:i:s'),
                'horasMotor'      => $trama->horasMotor,
                'idMunicipalidad' => $idMunicipalidad,
                'ignition'        => $trama->ignition,
                'imei'            => $trama->imei,
                'latitud'         => $trama->latitud,
                'longitud'        => $trama->longitud,
                'motion'          => $trama->motion,
                'placa'           => $trama->placa,
                'totalDistancia'  => $trama->totalDistancia,
                'totalHorasMotor' => $trama->totalHorasMotor,
                'ubigeo'          => $ubigeo,
                'valid'           => $trama->valid,
                'velocidad'       => $trama->velocidad,
            ];
        }

        if (empty($payloads)) {
            return;
        }

        $this->enviarTramasConcurrente($payloads, $ip);
    }

    public function enviarTramasConcurrente(array $payloads, string $ip)
    {
        $client      = new Client(['verify' => false, 'timeout' => 15, 'connect_timeout' => 5]);
        $successIds  = [];
        $errorLogs   = [];
        $concurrency = 20; // peticiones paralelas simultáneas

        $ids      = array_keys($payloads);
        $requests = function () use ($ids, $payloads, $ip, $client) {
            foreach ($ids as $id) {
                $json = $payloads[$id];
                yield $id => function () use ($client, $ip, $json) {
                    return $client->requestAsync('POST', $ip, [
                        'headers' => ['Content-Type' => 'application/json'],
                        'body'    => json_encode($json),
                    ]);
                };
            }
        };

        $pool = new \GuzzleHttp\Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled'   => function ($response, $id) use ($payloads, &$successIds, &$errorLogs) {
                $json = $payloads[$id];
                if ($response->getStatusCode() == 201) {
                    $successIds[] = $id;
                    if ($this->service['logs']) {
                        $errorLogs[] = [
                            'service_name'        => 'Mininter',
                            'placa'               => $json['placa'],
                            'status'              => 'success',
                            'json'                => $json,
                            'response'            => ['status_code' => 201],
                            'fechaHora'           => $json['fechaHora'],
                            'imei'                => $json['imei'],
                        ];
                    }
                } else {
                    if ($this->service['logs']) {
                        $errorLogs[] = [
                            'service_name' => 'Mininter',
                            'placa'        => $json['placa'],
                            'status'       => 'error',
                            'json'         => $json,
                            'response'     => ['status_code' => $response->getStatusCode(), 'body' => (string) $response->getBody()],
                            'fechaHora'    => $json['fechaHora'],
                            'imei'         => $json['imei'],
                        ];
                    }
                    // Eliminar igual para no acumular registros que el API rechaza permanentemente
                    $successIds[] = $id;
                }
            },
            'rejected' => function ($reason, $id) use ($payloads, &$errorLogs) {
                $json = $payloads[$id];
                if ($this->service['logs']) {
                    $errorLogs[] = [
                        'service_name' => 'Mininter',
                        'placa'        => $json['placa'],
                        'status'       => 'error',
                        'json'         => $json,
                        'response'     => ['error' => $reason->getMessage()],
                        'fechaHora'    => $json['fechaHora'],
                        'imei'         => $json['imei'],
                    ];
                }
                // No eliminar en errores de red — se reintentará en el próximo ciclo
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        // Batch delete de registros exitosos (y rechazados por API)
        if (!empty($successIds)) {
            Mininter::whereIn('id', $successIds)->delete();
        }

        // Batch insert de logs
        if (!empty($errorLogs)) {
            foreach ($errorLogs as $log) {
                $this->logService->logToDatabase(
                    $log['service_name'],
                    $log['placa'],
                    $log['status'],
                    $log['json'],
                    $log['response'],
                    [],
                    Carbon::createFromFormat('d/m/Y H:i:s', $log['fechaHora']),
                    $log['imei'],
                );
            }
        }
    }
}
