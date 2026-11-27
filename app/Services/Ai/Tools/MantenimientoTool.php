<?php

namespace App\Services\Ai\Tools;

use Tobuli\Entities\DeviceService;

class MantenimientoTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantenimiento';
    }

    public function description(): string
    {
        return 'Servicios de mantenimiento programados (Mantenimiento > servicios): cuales estan vencidos, '
            . 'cuales estan proximos (dentro del recordatorio) y cuanto falta por dias, km u horas de motor.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidades' => $this->unitsProperty(),
                'estado' => [
                    'type' => 'string',
                    'enum' => ['todos', 'vencidos', 'proximos'],
                    'description' => 'Filtro. Por defecto "todos".',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter(['Mantenimiento', $input['estado'] ?? null, $this->unitsLabel($input)]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        $filter = $input['estado'] ?? 'todos';

        $services = DeviceService::userAccessible($this->user)
            ->with('device')
            ->whereIn('device_id', $ids)
            ->get();

        $rows = [];
        $counts = ['vencido' => 0, 'proximo' => 0, 'al dia' => 0];

        foreach ($services as $service) {
            try {
                $status = $service->isExpired() ? 'vencido' : ($service->isExpiring() ? 'proximo' : 'al dia');
                $left = $service->left_formated();
                $expires = $service->expires_formated();
            } catch (\Throwable $e) {
                continue;
            }

            $counts[$status]++;

            if ($filter === 'vencidos' && $status !== 'vencido') {
                continue;
            }
            if ($filter === 'proximos' && $status !== 'proximo') {
                continue;
            }

            $rows[] = array_filter([
                'unidad' => $service->device ? $this->deviceLabel($service->device) : null,
                'servicio' => $service->name,
                'estado' => $status,
                'por' => ['days' => 'dias', 'odometer' => 'kilometraje', 'engine_hours' => 'horas de motor'][$service->expiration_by] ?? $service->expiration_by,
                'vence' => $expires,
                'falta' => $left,
            ]);
        }

        usort($rows, function ($a, $b) {
            $order = ['vencido' => 0, 'proximo' => 1, 'al dia' => 2];
            return $order[$a['estado']] <=> $order[$b['estado']];
        });

        return [
            'total_servicios' => $services->count(),
            'vencidos' => $counts['vencido'],
            'proximos' => $counts['proximo'],
            'al_dia' => $counts['al dia'],
            'servicios' => array_slice($rows, 0, 25),
            'lista_truncada' => count($rows) > 25,
        ];
    }
}
