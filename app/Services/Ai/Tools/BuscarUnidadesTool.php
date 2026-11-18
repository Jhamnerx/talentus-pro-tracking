<?php

namespace App\Services\Ai\Tools;

class BuscarUnidadesTool extends DeviceStateTool
{
    public function name(): string
    {
        return 'buscar_unidades';
    }

    public function description(): string
    {
        return 'Busca unidades del usuario por nombre, placa, IMEI o grupo y devuelve sus nombres exactos, '
            . 'grupo y estado. Usala cuando un nombre es ambiguo, para listar la flota o un grupo, '
            . 'o cuando otra herramienta no encontro la unidad.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'texto' => [
                    'type' => 'string',
                    'description' => 'Texto a buscar. Vacio o "todas" lista toda la flota.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        $text = trim($input['texto'] ?? '');

        return 'Busque unidades' . ($text && strtolower($text) !== 'todas' ? " · \"$text\"" : '');
    }

    protected function run(array $input): array
    {
        $text = trim($input['texto'] ?? '');
        $all = $text === '' || in_array(mb_strtolower($text), ['todas', 'todos', 'all', 'flota']);

        $ids = $all ? null : $this->matchDevices($text);

        if (!$all && empty($ids)) {
            return ['encontradas' => 0, 'mensaje' => "No hay unidades que coincidan con \"$text\"."];
        }

        $total = $all ? $this->user->devices()->count() : count($ids);
        $devices = $this->stateQuery($ids)->limit(50)->get();

        return [
            'encontradas' => $total,
            'mostradas' => $devices->count(),
            'unidades' => $devices->map(function ($device) {
                return array_filter([
                    'id' => $device->id,
                    'nombre' => $device->name,
                    'placa' => $device->plate_number ?: null,
                    'imei' => $device->imei,
                    'grupo' => $this->groupName($device),
                    'estado' => self::STATES[$this->stateKey($device)],
                ]);
            })->values()->all(),
        ];
    }
}
