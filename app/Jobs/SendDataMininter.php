<?php

namespace App\Jobs;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Tobuli\Entities\Mininter;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class SendDataMininter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $logService;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
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

        $this->getTramas();
    }

    public function getTramas()
    {
        $ip = config('app.env') == 'local' ? config('app.url_mininter_beta') : config('app.url_mininter_prod');

        $tramas = Mininter::with('device', 'device.users')->get();

        foreach ($tramas as $trama) {

            $idMunicipalidad = $trama->idMunicipalidad;
            $ubigeo = $trama->ubigeo;

            // Verificar si el campo idMunicipalidad y ubigeo están vacíos
            if ($trama->idMunicipalidad == "") {

                $user = $trama->device->users->where('is_municipalidad', true)->first();

                if ($user->is_municipalidad) {
                    $idMunicipalidad = $user->token_muni;
                }
            }

            if ($trama->ubigeo == "") {
                $user = $trama->device->users->where('is_municipalidad', true)->first();

                if ($user->is_municipalidad) {
                    $ubigeo = $user->ubigeo_muni;
                }
            }

            $datetime = new DateTime('@' . $trama['timestamp']);
            $datetime->setTimezone(new DateTimeZone('America/Lima'));

            // Crear JSON para enviar a MININTER
            $json = [
                'id' => $trama->id,
                'alarma' => $trama->alarma,
                'altitud' => $trama->altitud,
                'angulo' => $trama->angulo,
                'distancia' => doubleval($trama->other['distance']),
                'fechaHora' => $datetime->format('d/m/Y H:i:s'),
                'horasMotor' => $trama->horasMotor,
                'idMunicipalidad' => $idMunicipalidad,
                'ignition' => $trama->ignition,
                'imei' => $trama->imei,
                'latitud' => $trama->latitud,
                'longitud' => $trama->longitud,
                'motion' => $trama->motion,
                'placa' => $trama->placa,
                'totalDistancia' => $trama->totalDistancia,
                'totalHorasMotor' => $trama->totalHorasMotor,
                'ubigeo' => $ubigeo,
                'valid' => $trama->valid,
                'velocidad' => $trama->velocidad,
            ];

            $this->enviarTramas($json, $ip);
        }
    }

    public function enviarTramas($json, $ip)
    {
        try {
            $client = new Client(['verify' => false]);
            $response = $client->request('POST', $ip, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($json),
            ]);

            // Procesar la respuesta de la API
            $this->processResponse($response, $json);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = $response->getBody()->getContents();

                // Guardar log de error en la base de datos
                // $this->logService->logToDatabase(
                //     'Mininter',
                //     $json['placa'],
                //     "Error en la respuesta: " . $body,
                //     'error',
                //     ['json' => $json]
                // );
            } else {
                // Guardar log de error en la base de datos
                $this->logService->logToDatabase(
                    'Mininter',
                    $json['placa'],
                    "Error en la solicitud: " . $e->getMessage(),
                    'error',
                    ['json' => $json]
                );
            }
        }
    }

    protected function processResponse($response, $json)
    {
        if ($response->getStatusCode() == 201) {
            // Guardar log de éxito en la base de datos
            // $this->logService->logToDatabase(
            //     'Mininter',
            //     $json['placa'],
            //     "Trama registrada con éxito: ID " . $json['id'],
            //     'info',
            //     $response
            // );

            // Eliminar el registro si la respuesta fue exitosa
            Mininter::where('id', $json['id'])->delete();
        } else {
            // Guardar log de error en la base de datos
            $this->logService->logToDatabase(
                'Mininter',
                $json['placa'],
                "Error en la respuesta: código de estado inesperado " . $response->getStatusCode(),
                'error',
                $response
            );
        }
    }
}
