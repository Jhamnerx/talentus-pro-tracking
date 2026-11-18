<?php

namespace App\Services\Ai\Tools;

class EstadoUnidadesTool extends DeviceStateTool
{
    public function name(): string
    {
        return 'estado_unidades';
    }

    public function description(): string
    {
        return 'Estado actual de las unidades: cuantas estan en movimiento, detenidas, con motor encendido, '
            . 'offline, vencidas o bloqueadas, y la lista de las que cumplen un estado con su ultima conexion. '
            . 'No necesita periodo.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidades' => $this->unitsProperty(),
                'estado' => [
                    'type' => 'string',
                    'enum' => array_merge(['todos'], array_keys(self::STATES)),
                    'description' => 'Filtra la lista por estado. "todos" devuelve solo el conteo y un resumen.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        $state = $input['estado'] ?? 'todos';

        return implode(' · ', array_filter([
            'Estado de unidades',
            $state !== 'todos' ? (self::STATES[$state] ?? $state) : null,
            $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        $units = (array) ($input['unidades'] ?? ['todas']);
        $isAll = empty($units) || in_array(mb_strtolower($units[0] ?? ''), ['todas', 'todos', 'all']);

        $ids = $isAll ? null : $this->resolveIdsIncludingExpired($units);
        $filter = $input['estado'] ?? 'todos';

        $counts = array_fill_keys(array_keys(self::STATES), 0);
        $list = [];
        $total = 0;

        $this->stateQuery($ids)->chunk(500, function ($devices) use (&$counts, &$list, &$total, $filter) {
            foreach ($devices as $device) {
                $total++;
                $state = $this->stateKey($device);
                $counts[$state]++;

                if ($filter !== 'todos' && $state === $filter && count($list) < 40) {
                    $list[] = array_filter([
                        'unidad' => $this->deviceLabel($device),
                        'grupo' => $this->groupName($device),
                        'ultima_conexion' => $this->lastConnection($device),
                        'vence' => $state === 'vencida' ? $device->expiration_date : null,
                    ]);
                }
            }
        });

        $summary = [];
        foreach ($counts as $key => $count) {
            if ($count) {
                $summary[self::STATES[$key]] = $count;
            }
        }

        $result = ['total_unidades' => $total, 'por_estado' => $summary];

        if ($filter !== 'todos') {
            $result['estado_filtrado'] = self::STATES[$filter];
            $result['unidades'] = $list;
            $result['lista_truncada'] = $counts[$filter] > count($list);
        }

        return $result;
    }

    private function resolveIdsIncludingExpired(array $units): array
    {
        $ids = [];
        foreach ($units as $text) {
            $ids = array_merge($ids, $this->matchDevices((string) $text));
        }

        if (empty($ids)) {
            throw new \App\Services\Ai\ToolException('No encontre unidades que coincidan con: ' . implode(', ', $units));
        }

        return array_values(array_unique($ids));
    }
}
