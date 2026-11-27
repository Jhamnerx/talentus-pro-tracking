<?php

namespace App\Services\Ai\Tools;

use Carbon\Carbon;

class VencimientosTool extends DeviceStateTool
{
    public function name(): string
    {
        return 'vencimientos';
    }

    public function description(): string
    {
        return 'Unidades con la licencia de la plataforma o el chip (SIM) vencidos o por vencer en los '
            . 'proximos N dias (30 por defecto), con su fecha de vencimiento.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dias' => ['type' => 'integer', 'description' => 'Ventana en dias hacia adelante (por defecto 30).'],
                'tipo' => ['type' => 'string', 'enum' => ['todos', 'licencia', 'sim'], 'description' => 'Por defecto "todos".'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Vencimientos · proximos ' . (int) ($input['dias'] ?? 30) . ' dias';
    }

    protected function run(array $input): array
    {
        $days = max(1, min(365, (int) ($input['dias'] ?? 30)));
        $type = $input['tipo'] ?? 'todos';
        $limit = Carbon::now()->addDays($days)->format('Y-m-d H:i:s');
        $now = Carbon::now();

        $lists = ['licencia' => [], 'sim' => []];

        $columns = ['licencia' => 'expiration_date', 'sim' => 'sim_expiration_date'];

        foreach ($columns as $key => $column) {
            if ($type !== 'todos' && $type !== $key) {
                continue;
            }

            $devices = $this->user->devices()
                ->whereNotNull("devices.$column")
                ->where("devices.$column", '>', '0001-01-01')
                ->where("devices.$column", '<=', $limit)
                ->orderBy("devices.$column")
                ->limit(60)
                ->get();

            foreach ($devices as $device) {
                $date = Carbon::parse($device->{$column});
                $lists[$key][] = [
                    'unidad' => $this->deviceLabel($device),
                    'fecha' => $date->format('Y-m-d'),
                    'estado' => $date->isPast() ? 'vencida' : 'vence en ' . $now->diffInDays($date) . ' dias',
                ];
            }
        }

        $summary = function ($list) {
            $expired = count(array_filter($list, function ($r) {
                return $r['estado'] === 'vencida';
            }));

            return ['vencidas' => $expired, 'por_vencer' => count($list) - $expired];
        };

        $result = ['ventana_dias' => $days];

        foreach ($lists as $key => $list) {
            if ($type !== 'todos' && $type !== $key) {
                continue;
            }
            $result[$key] = $summary($list) + ['unidades' => array_slice($list, 0, 30)];
        }

        return $result;
    }
}
