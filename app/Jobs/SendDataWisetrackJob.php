<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use App\Services\LogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    public $timeout = 300;
    public $failOnTimeout = true;

    public function __construct($offset, $batchSize, $service, $plates, $userId = null)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->service = $service;
        $this->plates = $plates;
        $this->userId = $userId;
        $this->onQueue('web-services-wisetrack');
    }

    public function failed(\Throwable $exception)
    {
        $logService = app(LogService::class);
        $logService->setUserId($this->userId);
        $logService->logError('Wisetrack', 'Job fallido definitivamente', [
            'offset'    => $this->offset,
            'batchSize' => $this->batchSize,
            'plates'    => $this->plates,
            'message'   => $exception->getMessage(),
        ]);
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
                    "direccion" => strval(intval($position->direccion) % 360), // Entero 0-359 (doc)
                    "velocidad" => strval($position->velocidad),
                    "estado_registro" => $position->estado_registro ? '1' : '0',
                    "estado_ignicion" => $position->estado_ignicion ? '1' : '0',
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
        // Se arma fuera del try para tenerlo disponible también en los catch (para el log)
        $payload = json_encode([
            "posicion" => $tramas
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Token enmascarado para verificar en el log que es el correcto, sin exponer el secreto
        $tokenMasked = strlen($token) > 12
            ? substr($token, 0, 6) . '...' . substr($token, -4)
            : '***';

        try {
            $client = new Client(['verify' => false]);

            // LOG: qué se está enviando. NO se vuelca el payload completo (300 posiciones =
            // línea gigante que tapa la respuesta); solo count + una muestra. El payload
            // completo queda en los logs de error de abajo, que es cuando se necesita.
            Log::channel('wisetrack')->info('➡️ Enviando tramas a Wisetrack', [
                'endpoint'  => $endpoint,
                'token'     => $tokenMasked,
                'token_len' => strlen($token),
                'count'     => count($tramas),
                'muestra'   => $tramas[0] ?? null,
            ]);

            $response = $client->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => $payload, // Se usa 'body' en lugar de 'json'
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->getBody()->getContents();

            // LOG: respuesta cruda tal cual la devuelve Wisetrack
            Log::channel('wisetrack')->info('⬅️ Respuesta de Wisetrack', [
                'status'   => $statusCode,
                'response' => $body,
            ]);

            $responseWisetrack = json_decode($body, true);
            $this->actionAfterSend($tramas, $responseWisetrack);
        } catch (RequestException $e) {

            if ($e->hasResponse()) {
                $response   = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $body       = $response->getBody()->getContents();

                // LOG: error HTTP — aquí viene el detalle real del rechazo,
                // ej. "cvc-type.3.1.3: The value '' of element 'patente' is not valid."
                Log::channel('wisetrack')->error('❌ Error HTTP al enviar tramas a Wisetrack', [
                    'status'   => $statusCode,
                    'response' => $body,
                    'endpoint' => $endpoint,
                    'token'    => $tokenMasked,
                    'payload'  => $payload,
                ]);

                if ($statusCode == 403) {
                    Log::channel('wisetrack')->error('⛔ Token inválido o sin permisos. Verifica la autenticación.', [
                        'token'     => $tokenMasked,
                        'token_len' => strlen($token),
                    ]);
                }
            } else {
                Log::channel('wisetrack')->error('🔌 Error de conexión al enviar tramas', [
                    'endpoint' => $endpoint,
                    'message'  => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('wisetrack')->error('💥 Error inesperado al enviar tramas', [
                'endpoint' => $endpoint,
                'message'  => $e->getMessage(),
                'payload'  => $payload,
            ]);
        }
    }

    public function actionAfterSend($tramas, $response)
    {

        $this->logService->logStart('Wisetrack', 'Respuesta satisfactoria recibida', ['response' => $response]);

        $ids = []; // IDs de tramas a eliminar

        if (!isset($response['RespuestaServicioWeb']['RespuestaOperacion'])) {
            Log::channel('wisetrack')->error('❌ Respuesta de Wisetrack no válida o inesperada', [
                'response' => $response,
            ]);
            return;
        }

        $operaciones = $response['RespuestaServicioWeb']['RespuestaOperacion'];

        // ✅ Convertir a array si es un objeto
        if (!is_array($operaciones)) {
            $operaciones = [$operaciones];
        }

        foreach ($operaciones as $index => $result) {


            if (!isset($result['ResultadoTransaccion'])) {
                Log::channel('wisetrack')->error("❗ Respuesta sin 'ResultadoTransaccion'", [
                    'index'  => $index,
                    'result' => $result,
                ]);
                continue; // Evita que el job falle y continúa con la siguiente trama
            }

            $estado = Arr::get($result, 'ResultadoTransaccion.Estado', 'Desconocido');
            $detalle = Arr::get($result, 'ResultadoTransaccion.DetalleEjecucion', '');

            // Tomar la fecha de la trama enviada en lugar de la respuesta
            $fecha_hora = $tramas[$index]['fecha_hora'] ?? null;
            $patente = $tramas[$index]['patente'] ?? "Desconocido";

            // ✅ Agregar TODAS las tramas al array de IDs para eliminar
            $ids[] = $tramas[$index]['id'] ?? null;

            // Contexto común para el log de cada posición
            $logContext = [
                'patente' => $patente,
                'estado'  => $estado,
                'detalle' => $detalle,
                'fecha'   => $fecha_hora,
            ];

            // Códigos de estado según la doc Buenaventura (pág. 12):
            // 1=exitosa, 2=error conversión, 3=token, 4=móvil no existe, 5=duplicado, 6=error interno
            if ($estado == 1) {
                Log::channel('wisetrack')->info('✅ Posición aceptada', $logContext);
            } elseif ($estado == 5) {
                Log::channel('wisetrack')->info('♻️ Registro duplicado (ya existía en Wisetrack)', $logContext);
            } elseif ($estado == 2) {
                Log::channel('wisetrack')->error('❌ Error de conversión en Wisetrack', $logContext);
            } elseif ($estado == 3) {
                Log::channel('wisetrack')->error('⛔ Token inválido o incorrecto en Wisetrack', $logContext);
            } elseif ($estado == 4) {
                Log::channel('wisetrack')->error('🚫 Móvil no existe o no está disponible en Wisetrack', $logContext);
            } elseif ($estado == 6) {
                Log::channel('wisetrack')->error('❌ Error interno en Wisetrack', $logContext);
            } else {
                Log::channel('wisetrack')->error('❗ Estado desconocido en Wisetrack', $logContext);
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
        $ids = array_filter($ids); // descarta posibles null
        if (!empty($ids)) {
            $eliminadas = WiseTrack::destroy($ids);
            Log::channel('wisetrack')->info('🗑️ Tramas procesadas y eliminadas', [
                'procesadas' => count($operaciones),
                'eliminadas' => $eliminadas,
            ]);
        }
    }
}
