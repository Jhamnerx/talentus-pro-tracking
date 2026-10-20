<?php

namespace Tobuli\Services\Security;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tobuli\Entities\Device;
use Tobuli\Entities\SecuritySetting;

/**
 * Estado vigente por unidad (seccion "ESTADO ACTUAL" del Excel), calculado
 * a partir de la ultima posicion denormalizada en TraccarDevice.
 *
 * Los devices deben venir con `traccar` y la clasificacion del usuario
 * (`withClassificationFor($userId)`) cargadas para evitar N+1: el tipo de
 * vehiculo es el que ese usuario asigno al equipo.
 */
class SecurityLiveStatus
{
    const STATE_EXPIRED = 'expired';
    const STATE_MOVING = 'moving';
    const STATE_STANDBY = 'standby';
    const STATE_STOPPED = 'stopped';

    private SecuritySetting $settings;

    /** Usuario cuya clasificacion (tipo de vehiculo) se muestra. */
    private int $userId;

    public function __construct(SecuritySetting $settings, int $userId)
    {
        $this->settings = $settings;
        $this->userId = $userId;
    }

    /**
     * @param Collection<Device> $devices
     * @return array[] una fila por unidad
     */
    public function rows(Collection $devices): array
    {
        $now = Carbon::now('UTC');
        $rows = [];

        foreach ($devices as $device) {
            $rawTime = $device->traccar ? $device->traccar->time : null;
            $time = $rawTime ? Carbon::parse($rawTime, 'UTC') : null;
            $age = $time ? max(0, $time->diffInMinutes($now)) : null;
            $speed = $time ? (float) $device->traccar->speed : null;
            $engine = $device->getEngineStatus();

            $rows[] = [
                'device_id'           => $device->id,
                'unit'                => $device->name,
                'type'                => optional($device->vehicleTypeFor($this->userId))->name,
                'last_time'           => $time,
                'last_time_formatted' => $rawTime ? \Formatter::time()->human($rawTime) : null,
                'speed'               => $speed,
                'engine'              => $engine,
                'age_minutes'         => $age,
                'state'               => $this->state($age, $speed, $engine),
            ];
        }

        return $rows;
    }

    /**
     * Misma regla del Excel: vigencia > minutos => expirada; >= 1 km/h movimiento;
     * motor encendido => stand by; en otro caso detenida.
     */
    private function state(?int $age, ?float $speed, ?bool $engine): string
    {
        if ($age === null || $age > $this->settings->validity_minutes) {
            return self::STATE_EXPIRED;
        }

        if ($speed >= 1) {
            return self::STATE_MOVING;
        }

        if ($engine === true) {
            return self::STATE_STANDBY;
        }

        return self::STATE_STOPPED;
    }
}
