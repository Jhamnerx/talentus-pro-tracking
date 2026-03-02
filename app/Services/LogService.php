<?php

namespace App\Services;

use Carbon\Carbon;
use Tobuli\Entities\WebservicesLogs;

class LogService
{
    /**
     * ID del usuario que disparó la transmisión (contexto del job).
     */
    protected ?int $userId = null;

    /**
     * Establece el usuario que realizó el envío/retransmisión.
     */
    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Guardar un log en la base de datos.
     *
     * @param string $service El nombre del servicio web.
     * @param string $plate El número de la placa.
     * @param string $status El estado del log.
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
    }
}
