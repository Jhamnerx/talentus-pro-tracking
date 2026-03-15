<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\WebservicesLogs;

class LogService
{
    /**
     * ID del usuario que disparó la transmisión (contexto del job).
     */
    protected ?int $userId = null;

    /**
     * Mapeo de nombre de servicio → canal de log.
     */
    private const CHANNEL_MAP = [
        'sutran'     => 'sutran',
        'osinergmin' => 'osinergmin',
        'comsatel'   => 'comsatel',
        'consatel'   => 'comsatel',
        'mininter'   => 'mininter',
        'wisetrack'  => 'wisetrack',
    ];

    /**
     * Establece el usuario que realizó el envío/retransmisión.
     */
    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Registra el inicio de un proceso en el archivo de log del servicio.
     */
    public function logStart(string $service, string $message, array $context = []): void
    {
        $this->logToFile($service, 'info', $message, $context);
    }

    /**
     * Registra un error de infraestructura (sin respuesta HTTP) en el archivo de log.
     */
    public function logError(string $service, string $message, array $context = []): void
    {
        $this->logToFile($service, 'error', $message, $context);
    }

    /**
     * Escribe en el archivo de log del canal correspondiente al servicio.
     */
    private function logToFile(string $service, string $level, string $message, array $context = []): void
    {
        $channel = self::CHANNEL_MAP[strtolower($service)] ?? null;

        try {
            if ($channel) {
                Log::channel($channel)->{$level}($message, $context);
            } else {
                Log::{$level}("[{$service}] {$message}", $context);
            }
        } catch (\Throwable $e) {
            Log::{$level}("[{$service}] {$message}", $context);
        }
    }

    /**
     * Guardar un log en la base de datos y, si es un error, también en el archivo de log.
     *
     * @param string $service El nombre del servicio web.
     * @param string $plate El número de la placa.
     * @param string $status El estado del log (success, error, etc.).
     * @param array $trama Datos enviados (request).
     * @param array $response Respuesta recibida.
     * @param array $additionalData Datos adicionales opcionales.
     * @param string|null $datePosicion Fecha/hora de la posición GPS.
     * @param mixed $imei IMEI del dispositivo.
     * @return void
     */
    public function logToDatabase($service, $plate, $status = '', $trama = [], $response = [], $additionalData = [], $datePosicion = null, $imei = null): void
    {
        WebservicesLogs::create([
            'service_name' => $service,
            'method'       => 'POST',
            'date'         => Carbon::now()->format('Y-m-d H:i:s'),
            'plate_number' => $plate,
            'request'      => json_encode($trama),
            'response'     => json_encode($response),
            'status'       => $status,
            'additional_data' => $additionalData,
            'fecha_hora_posicion' => $datePosicion,
            'imei'         => $imei,
            'user_id'      => $this->userId,
        ]);

        // Los errores también se escriben en el archivo de log del servicio
        if ($status !== 'success') {
            $this->logToFile($service, 'error', "[{$status}] Placa: {$plate}", [
                'plate'    => $plate,
                'status'   => $status,
                'response' => $response,
            ]);
        }
    }
}
