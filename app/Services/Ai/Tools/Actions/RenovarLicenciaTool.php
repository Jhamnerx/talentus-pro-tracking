<?php

namespace App\Services\Ai\Tools\Actions;

use App\Services\Ai\ToolException;
use Carbon\Carbon;
use Tobuli\Entities\User;

/**
 * Extiende la fecha de vencimiento de la licencia de unidades. Solo para
 * quien tiene permiso de editar "device.expiration_date" (distribuidores/admin).
 */
class RenovarLicenciaTool extends ActionTool
{
    public function name(): string
    {
        return 'renovar_licencia';
    }

    public function description(): string
    {
        return 'Prepara la renovacion de la licencia de una o varias unidades (maximo 20) por N meses, '
            . 'contando desde hoy si ya vencio o desde su vencimiento actual. NO la aplica: muestra una tarjeta '
            . 'que el usuario debe confirmar. Solo distribuidores y administradores.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidades' => [
                    'type' => 'array', 'items' => ['type' => 'string'],
                    'description' => 'Nombres, placas, IMEI o IDs (maximo 20).',
                ],
                'meses' => ['type' => 'integer', 'description' => 'Meses a agregar (1 a 24).'],
            ],
            'required' => ['unidades', 'meses'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Renovar licencia · ' . $this->unitsLabel($input) . ' · ' . (int) ($input['meses'] ?? 0) . ' meses';
    }

    public static function allowed(User $user, array $params): bool
    {
        return (bool) $user->perm('device.expiration_date', 'edit');
    }

    protected function prepare(array $input): array
    {
        if (!self::allowed($this->user, [])) {
            throw new ToolException('Solo un distribuidor o administrador puede renovar licencias.');
        }

        $months = (int) $input['meses'];
        if ($months < 1 || $months > 24) {
            throw new ToolException('Los meses deben estar entre 1 y 24.');
        }

        $ids = $this->resolveDeviceIds($input['unidades'], false);
        if (count($ids) > 20) {
            throw new ToolException('Maximo 20 unidades por renovacion.');
        }

        $devices = $this->user->devices()->whereIn('devices.id', $ids)->get();

        // Una unidad sin vencimiento no se "renueva": ponerle fecha la limitaria.
        $unlimited = $devices->filter(function ($device) {
            return !self::valid($device->expiration_date);
        });
        if ($unlimited->count()) {
            throw new ToolException('Estas unidades no tienen fecha de vencimiento (licencia sin limite) y no '
                . 'necesitan renovacion: ' . $unlimited->map(function ($d) {
                    return $this->deviceLabel($d);
                })->implode(', ') . '.');
        }

        $details = [];
        foreach ($devices as $device) {
            $details[] = $this->deviceLabel($device) . ': ' . self::describe($device->expiration_date)
                . ' → ' . self::newDate($device->expiration_date, $months)->format('Y-m-d');
        }

        return [
            'title' => 'Renovar licencia de ' . count($ids) . ' unidad(es) por ' . $months . ' meses',
            'details' => $details,
            'confirm' => 'Renovar',
            'params' => ['device_ids' => $ids, 'months' => $months],
        ];
    }

    public static function execute(User $user, array $params): string
    {
        $devices = $user->devices()->whereIn('devices.id', $params['device_ids'])->get();

        foreach ($devices as $device) {
            if (!self::valid($device->expiration_date)) {
                continue;
            }
            $device->expiration_date = self::newDate($device->expiration_date, (int) $params['months'])->format('Y-m-d H:i:s');
            $device->save();
        }

        return 'Licencia renovada para ' . $devices->count() . ' unidad(es) por ' . $params['months'] . ' meses.';
    }

    private static function valid($date): bool
    {
        return $date && substr($date, 0, 4) !== '0000';
    }

    private static function describe($date): string
    {
        return self::valid($date) ? 'vence ' . substr($date, 0, 10) : 'sin vencimiento';
    }

    private static function newDate($current, int $months): Carbon
    {
        $base = self::valid($current) ? Carbon::parse($current) : Carbon::now();

        if ($base->isPast()) {
            $base = Carbon::now();
        }

        return $base->copy()->addMonths($months);
    }
}
