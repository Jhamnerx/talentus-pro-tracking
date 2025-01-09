<?php

namespace App\Services;

use Carbon\Carbon;
use Tobuli\Entities\WebservicesLogs;

class LogService
{
    /**
     * Guardar un log en la base de datos.
     *
     * @param string $service El nombre del servicio web.
     * @param string $plate El número de la placa.
     * @param string $message El mensaje del log.
     * @param string $level El nivel del log (por defecto: info).
     * @param array $additionalData Datos adicionales opcionales.
     * @return void
     */
    public function logToDatabase($service, $plate, $status = '', $trama = [], $response = [], $additionalData = [], $datePosicion = null, $imei = null): void
    {

        WebservicesLogs::create([
            'service_name' => $service,
            'method' => 'POST',
            'date' => Carbon::now()->format('Y-m-d H:i:s'),
            'plate_number' => $plate,
            'request' => json_encode($trama),
            'response' => json_encode($response),
            'status' => $status,
            'additional_data' => $additionalData,
            'fecha_hora_posicion' => $datePosicion,
            'imei' => $imei,
        ]);
    }
}
