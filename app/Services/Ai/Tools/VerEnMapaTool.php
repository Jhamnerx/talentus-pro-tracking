<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;

/**
 * Agrega un boton que muestra una unidad en el mapa: su posicion actual o,
 * si se da un periodo, su recorrido en la pestana Historial.
 */
class VerEnMapaTool extends AbstractTool
{
    public function name(): string
    {
        return 'ver_en_mapa';
    }

    public function description(): string
    {
        return 'Agrega un boton para ver UNA unidad en el mapa de la plataforma: sin periodo la centra en su '
            . 'posicion actual; con periodo abre su recorrido en la pestana Historial.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidad' => ['type' => 'string', 'description' => 'Nombre, placa, IMEI o ID.'],
            ] + $this->periodProperties(),
            'required' => ['unidad'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return '';
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds([$input['unidad']], false);
        if (count($ids) > 1) {
            throw new ToolException('Coinciden ' . count($ids) . ' unidades. Usa buscar_unidades y pregunta cual es.');
        }

        if (empty($input['desde'])) {
            $this->actions[] = ['type' => 'device', 'device_id' => $ids[0], 'label' => 'Ver en el mapa'];

            return ['resultado' => 'Boton "Ver en el mapa" agregado.'];
        }

        if (!$this->user->perm('history', 'view')) {
            throw new ToolException('El usuario no tiene permiso para el historial.');
        }

        [$fromLocal, $toLocal] = $this->parsePeriod($input);

        $this->actions[] = [
            'type' => 'history',
            'device_id' => $ids[0],
            'from' => substr($fromLocal, 0, 16),
            'to' => substr($toLocal, 0, 16),
            'label' => 'Ver recorrido en el mapa',
        ];

        return ['resultado' => 'Boton "Ver recorrido en el mapa" agregado.', 'periodo' => "$fromLocal a $toLocal"];
    }
}
