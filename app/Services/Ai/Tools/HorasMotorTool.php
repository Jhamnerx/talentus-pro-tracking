<?php

namespace App\Services\Ai\Tools;

use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;

class HorasMotorTool extends HistoryTool
{
    public function name(): string
    {
        return 'horas_motor';
    }

    public function description(): string
    {
        return 'Horas de motor en un periodo: tiempo con motor encendido, cuanto fue trabajando (en '
            . 'movimiento) y cuanto en ralenti, con el porcentaje de ralenti por unidad. Ordenado por '
            . 'horas de motor. Util para detectar unidades con mucho ralenti.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['unidades' => $this->unitsProperty()] + $this->periodProperties(),
            'required' => ['desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter(['Horas de motor', $this->periodLabel($input), $this->unitsLabel($input)]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            Duration::class,
            Distance::class,
            DriveStop::class,
            EngineHours::class,
        ]);

        $units = [];
        $total = ['engine' => 0, 'idle' => 0];

        $report->each(function ($device, $data) use (&$units, &$total) {
            if (!$data) {
                return;
            }

            $root = $data['root'];
            $engine = (int) $this->statValue($root, 'engine_hours');
            $idle = (int) $this->statValue($root, 'engine_idle');

            if (!$engine) {
                return;
            }

            $total['engine'] += $engine;
            $total['idle'] += $idle;

            $units[] = [
                'unidad' => $this->deviceLabel($device),
                'horas_motor' => $this->duration($engine),
                'trabajando' => $this->duration((int) $this->statValue($root, 'engine_work')),
                'ralenti' => $this->duration($idle),
                'porcentaje_ralenti' => round($idle * 100 / $engine) . '%',
                'distancia' => $this->statHuman($root, 'distance'),
                '_engine' => $engine,
            ];
        });

        usort($units, function ($a, $b) {
            return $b['_engine'] <=> $a['_engine'];
        });

        return [
            'periodo' => "$fromLocal a $toLocal",
            'unidades_consultadas' => count($ids),
            'unidades_con_motor_encendido' => count($units),
            'totales_flota' => [
                'horas_motor' => $this->duration($total['engine']),
                'ralenti' => $this->duration($total['idle']),
                'porcentaje_ralenti' => $total['engine'] ? round($total['idle'] * 100 / $total['engine']) . '%' : '0%',
            ],
            'por_unidad' => array_map(function ($u) {
                unset($u['_engine']);
                return $u;
            }, array_slice($units, 0, 20)),
        ];
    }
}
