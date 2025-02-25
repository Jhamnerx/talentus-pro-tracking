<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use App\Services\LogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\WiseTrack;
use Illuminate\Support\Arr;
use DateTime;
use DateTimeZone;

class SendDataWisetrackJob implements ShouldQueue
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
        try {
            $this->logService = app(LogService::class);

            $positions = WiseTrack::whereIn('patente', $this->plates)
                ->offset($this->offset)
                ->limit($this->batchSize)
                ->with('device') // Se incluye la relación con el dispositivo
                ->orderBy('created_at')
                ->get();

            $tramas = [];

            foreach ($positions as $position) {
                $device = $position->device;
                $transportista = $device ? $device->object_owner : "Desconocido"; // Si no existe, asignar "Desconocido"

                $trama = [
                    "id" => $position->id,
                    "patente" => trim($position->patente),
                    "fecha_hora" => $position->fecha_hora, // Ya está en UTC en la BD
                    "latitud" => str_replace('.', ',', $position->latitud),
                    "longitud" => str_replace('.', ',', $position->longitud),
                    "direccion" => strval($position->direccion),
                    "velocidad" => strval($position->velocidad),
                    "estado_registro" => strval($position->estado_registro),
                    "estado_ignicion" => strval($position->estado_ignicion),
                    "numero_evento" => strval($position->numero_evento),
                    "odometro" => number_format($position->odómetro, 2, '.', ''),
                    "transportista" => $transportista
                ];

                $tramas[] = $trama;
            }

            $this->sendDataWisetrack($tramas);
        } catch (\Exception $e) {
            Log::error('Error al enviar tramas a Wisetrack: ' . $e->getMessage());
        }
    }

    public function sendDataWisetrack($tramas)
    {

        $endpoint = $this->service['url'];
        $token = $this->service['token'];



        $tramas_por_grupo = array_chunk($tramas, 300); // Máximo 300 posiciones por envío

        foreach ($tramas_por_grupo as $grupo) {
            $this->enviarTramas($grupo, $token, $endpoint);
        }
    }
    public function enviarTramas($tramas, $token, $endpoint)
    {
        try {
            $client = new Client(['verify' => false]);

            $payload = json_encode([
                "posicion" => $tramas
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $response = $client->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => $payload, // Se usa 'body' en lugar de 'json'
            ]);

            $responseWisetrack = json_decode($response->getBody()->getContents(), true);
            $this->actionAfterSend($tramas, $responseWisetrack);
        } catch (RequestException $e) {

            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();


                Log::error("Error al enviar tramas a Wisetrack", [
                    'status' => $statusCode,
                    'response' => $body,
                    'token' => $token,
                    'endpoint' => $endpoint,
                    'payload' => $payload // Registrar el payload para depuración
                ]);

                if ($statusCode == 403) {
                    Log::error("Token de Wisetrack inválido o sin permisos. Verifica la autenticación.");
                }
            }
        }
    }

    public function actionAfterSend($tramas, $response)
    {
        Log::info("Respuesta de Wisetrack: " . json_encode($response));
        $ids = [];
        $errored_rows = [];

        if (isset($response['RespuestaServicioWeb']['RespuestaOperacion'])) {
            foreach ($response['RespuestaServicioWeb']['RespuestaOperacion'] as $index => $result) {
                $estado = Arr::get($result, 'ResultadoTransaccion.Estado', 'Desconocido');
                $detalle = Arr::get($result, 'ResultadoTransaccion.DetalleEjecucion', '');

                // Tomar la fecha de la trama enviada en lugar de la respuesta
                $fecha_hora = $tramas[$index]['fecha_hora'] ?? null;
                $patente = $tramas[$index]['patente'] ?? "Desconocido";

                if ($estado == 1) {
                    Log::info("Posición enviada correctamente: " . $detalle);
                    $ids[] = $tramas[$index]['id'] ?? null;
                } elseif ($estado == 4) {
                    Log::warning("Registro duplicado en Wisetrack: " . $detalle);
                } elseif ($estado == 3) {
                    Log::error("Token inválido en Wisetrack. Verifica la configuración.");
                } elseif ($estado == 5) {
                    Log::error("Error interno en Wisetrack: " . $detalle);
                } else {
                    Log::error("Error desconocido en Wisetrack: " . $detalle);
                    $errored_rows[] = $tramas[$index];
                }

                if ($this->service['logs']) {
                    $this->logService->logToDatabase(
                        'Wisetrack',
                        $patente,
                        $estado == 1 ? 'success' : 'error',
                        $tramas[$index] ?? [],
                        ['message' => $detalle],
                        [],
                        $fecha_hora, // ✅ Guardar la fecha de la trama, no la respuesta
                        null
                    );
                }
            }
        } else {
            Log::error("Respuesta de Wisetrack no válida: " . json_encode($response));
        }

        if (!empty($ids)) {
            Log::info("Eliminando tramas enviadas correctamente: " . json_encode($ids));
            WiseTrack::destroy($ids);
        }
    }
}
