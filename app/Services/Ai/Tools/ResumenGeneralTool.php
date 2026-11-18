<?php

namespace App\Services\Ai\Tools;

use Formatter;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\Fuel;
use Tobuli\History\Actions\Odometer;
use Tobuli\History\Actions\OverspeedStatic;
use Tobuli\History\Actions\Speed;

class ResumenGeneralTool extends HistoryTool
{
    public function name(): string
    {
        return 'resumen_general';
    }

    public function description(): string
    {
        return 'Resumen por unidad de un periodo (mismo calculo que el reporte "Informacion general"): '
            . 'km, horas de motor, ralenti, tiempo en movimiento y detenido, velocidad maxima y promedio, '
            . 'consumo de combustible si hay sensor y, si se da un limite, cantidad de excesos. '
            . 'Util para comparar unidades o resumir la actividad de la flota.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['unidades' => $this->unitsProperty()] + $this->periodProperties() + [
                'limite' => [
                    'type' => 'number',
                    'description' => 'Opcional. Limite de velocidad para contar excesos (unidad del usuario).',
                ],
            ],
            'required' => ['desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter([
            'Informacion general', $this->periodLabel($input), $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $actions = [
            DriveStop::class,
            Duration::class,
            Distance::class,
            Speed::class,
            Fuel::class,
            EngineHours::class,
            Odometer::class,
        ];

        $limit = isset($input['limite']) ? (float) $input['limite'] : 0;
        if ($limit > 0) {
            $actions[] = OverspeedStatic::class;
        }

        $report = $this->makeReport($ids, $fromUtc, $toUtc, $actions);
        if ($limit > 0) {
            $report->setSpeedLimit(Formatter::speed()->reverse($limit));
        }

        $units = [];
        $withoutData = [];
        $total = ['distance' => 0, 'engine' => 0, 'idle' => 0];

        $report->each(function ($device, $data) use (&$units, &$withoutData, &$total) {
            if (!$data) {
                $withoutData[] = $this->deviceLabel($device);
                return;
            }

            $root = $data['root'];
            $distance = (float) $this->statValue($root, 'distance');

            $total['distance'] += $distance;
            $total['engine'] += (int) $this->statValue($root, 'engine_hours');
            $total['idle'] += (int) $this->statValue($root, 'engine_idle');

            $units[] = ['unidad' => $this->deviceLabel($device)] + $this->statsHuman($root, [
                'distance'         => 'distancia',
                'drive_duration'   => 'tiempo_en_movimiento',
                'stop_duration'    => 'tiempo_detenido',
                'engine_hours'     => 'horas_motor',
                'engine_idle'      => 'ralenti',
                'speed_max'        => 'velocidad_maxima',
                'speed_avg'        => 'velocidad_promedio',
                'overspeed_count'  => 'excesos',
                'fuel_consumption' => 'consumo_combustible',
                'odometer'         => 'odometro',
            ]) + ['_distance' => $distance];
        });

        usort($units, function ($a, $b) {
            return $b['_distance'] <=> $a['_distance'];
        });

        return [
            'periodo' => "$fromLocal a $toLocal",
            'unidades_consultadas' => count($ids),
            'totales_flota' => [
                'distancia' => $this->distance($total['distance']),
                'horas_motor' => $this->duration($total['engine']),
                'ralenti' => $this->duration($total['idle']),
            ],
            'por_unidad' => array_map(function ($u) {
                unset($u['_distance']);
                return $u;
            }, array_slice($units, 0, 20)),
            'lista_truncada' => count($units) > 20,
            'sin_datos_gps' => array_slice($withoutData, 0, 20),
        ];
    }
}
