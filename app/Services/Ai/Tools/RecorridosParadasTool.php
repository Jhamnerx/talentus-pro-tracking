<?php

namespace App\Services\Ai\Tools;

use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\Speed;

class RecorridosParadasTool extends HistoryTool
{
    public function name(): string
    {
        return 'recorridos_y_paradas';
    }

    public function description(): string
    {
        return 'Recorridos y paradas por unidad en un periodo (mismo calculo que el reporte '
            . '"Recorridos y paradas"): numero de viajes y paradas, km recorridos, tiempo en movimiento, '
            . 'tiempo detenido, ralenti y velocidad maxima/promedio. Ordenado por distancia.';
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
        return implode(' · ', array_filter([
            'Recorridos y paradas', $this->periodLabel($input), $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            DriveStop::class,
            Duration::class,
            Distance::class,
            Speed::class,
            EngineHours::class,
        ]);

        $units = [];
        $totals = ['distance' => 0, 'drive' => 0, 'stop' => 0, 'idle' => 0];
        $withoutData = 0;

        $report->each(function ($device, $data) use (&$units, &$totals, &$withoutData) {
            if (!$data) {
                $withoutData++;
                return;
            }

            $root = $data['root'];

            $totals['distance'] += (float) $this->statValue($root, 'distance');
            $totals['drive'] += (int) $this->statValue($root, 'drive_duration');
            $totals['stop'] += (int) $this->statValue($root, 'stop_duration');
            $totals['idle'] += (int) $this->statValue($root, 'engine_idle');

            $units[] = ['unidad' => $this->deviceLabel($device)] + $this->statsHuman($root, [
                'drive_count'    => 'viajes',
                'stop_count'     => 'paradas',
                'distance'       => 'distancia',
                'drive_duration' => 'tiempo_en_movimiento',
                'stop_duration'  => 'tiempo_detenido',
                'engine_idle'    => 'ralenti',
                'speed_max'      => 'velocidad_maxima',
                'speed_avg'      => 'velocidad_promedio',
            ]) + ['_distance' => (float) $this->statValue($root, 'distance')];
        });

        usort($units, function ($a, $b) {
            return $b['_distance'] <=> $a['_distance'];
        });

        return [
            'periodo' => "$fromLocal a $toLocal",
            'unidades_consultadas' => count($ids),
            'unidades_sin_datos_gps' => $withoutData,
            'totales_flota' => [
                'distancia' => $this->distance($totals['distance']),
                'tiempo_en_movimiento' => $this->duration($totals['drive']),
                'tiempo_detenido' => $this->duration($totals['stop']),
                'ralenti' => $this->duration($totals['idle']),
            ],
            'por_unidad' => array_map(function ($u) {
                unset($u['_distance']);
                return $u;
            }, array_slice($units, 0, 20)),
            'lista_truncada' => count($units) > 20,
        ];
    }
}
