<?php

namespace App\Services\Ai\Tools;

use Carbon\Carbon;

class DiagnosticoOfflineTool extends DeviceStateTool
{
    public function name(): string
    {
        return 'diagnostico_offline';
    }

    public function description(): string
    {
        return 'Diagnostica por que una unidad esta offline o no reporta: si esta desactivada o vencida, '
            . 'vencimiento de SIM, hora del ultimo dato y de la ultima conexion, tiempo sin reportar y '
            . 'valores de bateria/GSM si tiene esos sensores. Devuelve posibles causas.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidad' => ['type' => 'string', 'description' => 'Nombre, placa, IMEI o ID de la unidad.'],
            ],
            'required' => ['unidad'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Diagnostico · ' . ($input['unidad'] ?? '');
    }

    protected function run(array $input): array
    {
        $ids = $this->matchDevices($input['unidad']);

        if (empty($ids)) {
            throw new \App\Services\Ai\ToolException('No encontre la unidad "' . $input['unidad'] . '".');
        }
        if (count($ids) > 1) {
            throw new \App\Services\Ai\ToolException('Coinciden ' . count($ids)
                . ' unidades. Usa buscar_unidades y pregunta cual es.');
        }

        $device = $this->stateQuery($ids)->first();
        $state = $this->stateKey($device);

        $lastData = $device->getTime();
        $lastConnect = max($device->getAckTime(), $device->getServerTime());
        $timeoutMinutes = (int) settings('main_settings.default_object_online_timeout');

        $causes = [];

        if (!$device->active) {
            $causes[] = 'La unidad esta desactivada en la plataforma.';
        }
        if ($device->isExpired()) {
            $causes[] = 'La licencia de la unidad vencio el ' . $device->expiration_date . '.';
        }
        if ($device->sim_expiration_date && substr($device->sim_expiration_date, 0, 4) !== '0000'
            && Carbon::parse($device->sim_expiration_date)->isPast()) {
            $causes[] = 'El chip (SIM) vencio el ' . $device->sim_expiration_date . '.';
        }
        if (empty($lastConnect) || substr($lastConnect, 0, 4) === '0000') {
            $causes[] = 'El equipo nunca se conecto al servidor: revisar instalacion, APN y servidor/puerto configurados.';
        } elseif ($state === 'offline') {
            $causes[] = 'El equipo dejo de comunicarse: posibles causas son falta de energia/bateria, '
                . 'falta de senal o saldo del chip, o equipo desconectado.';
        }
        if ($lastData && $lastConnect && strtotime($lastConnect) - strtotime($lastData) > 3600) {
            $causes[] = 'El equipo se conecta pero sin posicion GPS valida reciente (posible falta de senal GPS o antena).';
        }

        $sensors = [];
        foreach (['battery', 'gsm', 'acc', 'ignition'] as $type) {
            $sensor = $device->getSensorByType($type);
            if ($sensor) {
                try {
                    $sensors[$sensor->name] = $sensor->getValueFormated($device->traccar, false);
                } catch (\Throwable $e) {
                    // sensor sin valor
                }
            }
        }

        return array_filter([
            'unidad' => $this->deviceLabel($device),
            'estado' => self::STATES[$state],
            'ultimo_dato_gps' => $this->localTime($lastData),
            'ultima_conexion' => $this->localTime($lastConnect),
            'sin_reportar' => $lastConnect && substr($lastConnect, 0, 4) !== '0000'
                ? $this->duration(max(0, time() - strtotime($lastConnect))) : null,
            'minutos_para_considerar_offline' => $timeoutMinutes ?: null,
            'licencia_vence' => $device->expiration_date ?: null,
            'sim_vence' => $device->sim_expiration_date ?: null,
            'sensores' => $sensors ?: null,
            'posibles_causas' => $causes ?: ['No se detectan problemas: la unidad esta reportando normalmente.'],
        ]);
    }
}
