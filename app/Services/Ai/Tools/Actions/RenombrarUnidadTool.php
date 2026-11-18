<?php

namespace App\Services\Ai\Tools\Actions;

use App\Services\Ai\ToolException;
use Tobuli\Entities\User;

class RenombrarUnidadTool extends ActionTool
{
    public function name(): string
    {
        return 'renombrar_unidad';
    }

    public function description(): string
    {
        return 'Prepara el cambio de nombre de UNA unidad. NO lo aplica: muestra una tarjeta que el usuario debe confirmar.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidad' => ['type' => 'string', 'description' => 'Nombre actual, placa, IMEI o ID.'],
                'nuevo_nombre' => ['type' => 'string', 'description' => 'Nombre nuevo (maximo 100 caracteres).'],
            ],
            'required' => ['unidad', 'nuevo_nombre'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Renombrar · ' . ($input['unidad'] ?? '');
    }

    public static function allowed(User $user, array $params): bool
    {
        return (bool) $user->perm('devices', 'edit');
    }

    protected function prepare(array $input): array
    {
        if (!self::allowed($this->user, [])) {
            throw new ToolException('El usuario no tiene permiso para editar unidades.');
        }

        $name = trim(strip_tags($input['nuevo_nombre']));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ToolException('El nombre nuevo debe tener entre 1 y 100 caracteres.');
        }

        $ids = $this->resolveDeviceIds([$input['unidad']], false);
        if (count($ids) > 1) {
            throw new ToolException('Coinciden ' . count($ids) . ' unidades. Usa buscar_unidades y pregunta cual es.');
        }

        $device = $this->user->devices()->find($ids[0]);

        return [
            'title' => 'Renombrar unidad',
            'details' => ['Nombre actual: ' . $device->name, 'Nombre nuevo: ' . $name],
            'confirm' => 'Renombrar',
            'params' => ['device_id' => $device->id, 'name' => $name, 'old' => $device->name],
        ];
    }

    public static function execute(User $user, array $params): string
    {
        $device = $user->devices()->find($params['device_id']);

        if (!$device) {
            throw new ToolException('La unidad ya no esta disponible.');
        }

        $device->name = $params['name'];
        $device->save();

        return "Unidad renombrada: \"{$params['old']}\" ahora se llama \"{$params['name']}\".";
    }
}
