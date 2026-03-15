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
use Tobuli\Entities\WiseTrack;
use Illuminate\Support\Arr;

class SendDataWisetrackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $offset;
    protected $batchSize;
    protected $logService;
    protected $service;
    protected $plates;
    protected $userId;

    public $tries = 3;
    public $timeout = 120;

    public function __construct($offset, $batchSize, $service, $plates, $userId = null)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->service = $service;
        $this->plates = $plates;
        $this->userId = $userId;
        $this->onQueue('web-services-wisetrack');
    }

    public function handle()
    {
        try {
            $this->logService = app(LogService::class);
            $this->logService->setUserId($this->userId);
            $this->logService->logStart('Wisetrack', 'Iniciando procesamiento de tramas Wisetrack', [
                'offset'    => $this->offset,
                'batchSize' => $this->batchSize,
                'plates'    => $this->plates,
            ]);
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
                    "odometro" => str_replace('.', ',', $position->odometro),
                    "transportista" => $transportista
                ];

                $tramas[] = $trama;
            }

            $this->sendDataWisetrack($tramas);
        } catch (\Exception $e) {
            if ($this->logService) {
                $this->logService->logError('Wisetrack', 'Error al enviar tramas: ' . $e->getMessage());
            }
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


                $this->logService->logError('Wisetrack', 'Error al enviar tramas', [
                    'status'   => $statusCode,
                    'response' => $body,
                    'endpoint' => $endpoint,
                ]);

                if ($statusCode == 403) {
                    $this->logService->logError('Wisetrack', 'Token inválido o sin permisos. Verifica la autenticación.');
                }
            }
        }
    }

    public function actionAfterSend($tramas, $response)
    {

        $this->logService->logStart('Wisetrack', 'Respuesta satisfactoria recibida', ['response' => $response]);

        $ids = []; // IDs de tramas a eliminar

        if (!isset($response['RespuestaServicioWeb']['RespuestaOperacion'])) {
            Log::error("❌ Respuesta de Wisetrack no válida o inesperada: " . json_encode($response));
            return;
        }

        $operaciones = $response['RespuestaServicioWeb']['RespuestaOperacion'];

        // ✅ Convertir a array si es un objeto
        if (!is_array($operaciones)) {
            $operaciones = [$operaciones];
        }

        foreach ($operaciones as $index => $result) {


            if (!isset($result['ResultadoTransaccion'])) {
                Log::error("❗ Error desconocido en Wisetrack: No se encontró 'ResultadoTransaccion' en la respuesta.", [
                    'index' => $index,
                    'result' => json_encode($result),
                ]);
                continue; // Evita que el job falle y continúa con la siguiente trama
            }

            $estado = Arr::get($result, 'ResultadoTransaccion.Estado', 'Desconocido');
            $detalle = Arr::get($result, 'ResultadoTransaccion.DetalleOperacion', '');

            // Tomar la fecha de la trama enviada en lugar de la respuesta
            $fecha_hora = $tramas[$index]['fecha_hora'] ?? null;
            $patente = $tramas[$index]['patente'] ?? "Desconocido";

            // ✅ Agregar TODAS las tramas al array de IDs para eliminar
            $ids[] = $tramas[$index]['id'] ?? null;

            if ($estado == 1) {
                // Log::info("✅ Posición enviada correctamente: " . $detalle);
            } elseif ($estado == 4) {
                // Log::warning("⚠ Registro duplicado en Wisetrack: " . $detalle);
            } elseif ($estado == 3) {
                Log::error("⛔ Token inválido en Wisetrack. Verifica la configuración.");
            } elseif ($estado == 5) {
                Log::error("❌ Error interno en Wisetrack: " . $detalle);
            } else {
                Log::error("❗ Error desconocido en Wisetrack: " . $detalle);
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

        // ✅ Eliminar TODAS las tramas, incluyendo las que tuvieron error
        if (!empty($ids)) {
            WiseTrack::destroy($ids);
        }
    }
}
