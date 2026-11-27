<?php

namespace App\Services\Ai\Tools;

use Tobuli\Entities\Device;

/**
 * Base para herramientas sobre el estado actual de las unidades.
 */
abstract class DeviceStateTool extends AbstractTool
{
    const STATES = [
        'en_movimiento'   => 'en movimiento',
        'detenida'        => 'detenida (conectada)',
        'motor_encendido' => 'detenida con motor encendido',
        'offline'         => 'offline (sin conexion)',
        'vencida'         => 'vencida',
        'bloqueada'       => 'bloqueada',
        'desactivada'     => 'desactivada',
    ];

    private $groups;

    protected function stateKey(Device $device): string
    {
        if (!$device->active) {
            return 'desactivada';
        }

        if ($device->isExpired()) {
            return 'vencida';
        }

        switch ($device->getStatus()) {
            case Device::STATUS_ONLINE:  return 'en_movimiento';
            case Device::STATUS_ENGINE:  return 'motor_encendido';
            case Device::STATUS_ACK:     return 'detenida';
            case Device::STATUS_BLOCKED: return 'bloqueada';
            default:                     return 'offline';
        }
    }

    protected function lastConnection(Device $device): ?string
    {
        $time = max($device->getTime(), $device->getAckTime(), $device->getServerTime());

        return $this->localTime($time);
    }

    protected function groupName(Device $device): ?string
    {
        if (is_null($this->groups)) {
            $this->groups = \DB::table('device_groups')
                ->where('user_id', $this->user->id)
                ->pluck('title', 'id')
                ->all();
        }

        $groupId = $device->pivot->group_id ?? null;

        return $groupId ? ($this->groups[$groupId] ?? null) : null;
    }

    /** Unidades del usuario (incluye vencidas) con datos de posicion cargados. */
    protected function stateQuery(array $ids = null)
    {
        return $this->user->devices()
            ->with(['traccar', 'sensors'])
            ->when($ids !== null, function ($query) use ($ids) {
                $query->whereIn('devices.id', $ids);
            });
    }
}
