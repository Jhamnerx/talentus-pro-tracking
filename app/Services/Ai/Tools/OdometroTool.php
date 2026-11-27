<?php

namespace App\Services\Ai\Tools;

use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupDaily;
use Tobuli\History\Actions\Odometer;

class OdometroTool extends HistoryTool
{
    public function name(): string
    {
        return 'odometro';
    }

    public function description(): string
    {
        return 'Kilometraje por dia de las unidades en un periodo y lectura del odometro al final. '
            . 'Con una sola unidad devuelve el detalle dia por dia; con varias, el total y el promedio diario.';
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
        return implode(' · ', array_filter(['Kilometraje diario', $this->periodLabel($input), $this->unitsLabel($input)]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            Duration::class,
            Distance::class,
            Odometer::class,
            GroupDaily::class,
        ]);

        $days = max(1, (int) ceil((strtotime($toLocal) - strtotime($fromLocal)) / 86400));
        $single = count($ids) === 1;
        $units = [];

        $report->each(function ($device, $data) use (&$units, $single, $days) {
            if (!$data) {
                return;
            }

            $root = $data['root'];
            $distance = (float) $this->statValue($root, 'distance');

            $row = [
                'unidad' => $this->deviceLabel($device),
                'distancia_total' => $this->distance($distance),
                'promedio_diario' => $this->distance($distance / $days),
                'odometro_final' => $this->statHuman($root, 'odometer'),
                '_distance' => $distance,
            ];

            if ($single) {
                $daily = [];
                foreach ($data['groups']->all() as $group) {
                    $daily[] = [
                        'dia' => substr($this->localTime($group->getStartPosition()->time ?? null) ?? '', 0, 10),
                        'distancia' => $this->distance((float) $this->statValue($group, 'distance')),
                    ];
                }
                $row['por_dia'] = array_slice($daily, 0, 31);
            }

            $units[] = array_filter($row, function ($v) {
                return $v !== null;
            });
        });

        usort($units, function ($a, $b) {
            return $b['_distance'] <=> $a['_distance'];
        });

        return [
            'periodo' => "$fromLocal a $toLocal",
            'dias' => $days,
            'distancia_flota' => $this->distance(array_sum(array_column($units, '_distance'))),
            'por_unidad' => array_map(function ($u) {
                unset($u['_distance']);
                return $u;
            }, array_slice($units, 0, 20)),
        ];
    }
}
