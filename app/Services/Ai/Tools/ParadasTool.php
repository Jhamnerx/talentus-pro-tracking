<?php

namespace App\Services\Ai\Tools;

use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupStop;

class ParadasTool extends HistoryTool
{
    const DEFAULT_MIN_MINUTES = 5;

    public function name(): string
    {
        return 'paradas';
    }

    public function description(): string
    {
        return 'Paradas de las unidades en un periodo (mismo calculo que el reporte "Paradas"). '
            . 'Devuelve por unidad el numero de paradas, el tiempo total detenido y la parada mas larga '
            . '(inicio, fin, duracion, lugar), y la parada mas larga de toda la consulta. '
            . 'Por defecto solo cuenta paradas de ' . self::DEFAULT_MIN_MINUTES . ' minutos o mas.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidades' => $this->unitsProperty(),
            ] + $this->periodProperties() + [
                'minutos_minimos' => [
                    'type' => 'integer',
                    'description' => 'Duracion minima de una parada en minutos (por defecto '
                        . self::DEFAULT_MIN_MINUTES . ').',
                ],
            ],
            'required' => ['desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter([
            'Paradas',
            $this->periodLabel($input),
            'min. ' . (int) ($input['minutos_minimos'] ?? self::DEFAULT_MIN_MINUTES) . ' min',
            $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $minMinutes = max(1, (int) ($input['minutos_minimos'] ?? self::DEFAULT_MIN_MINUTES));

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            DriveStop::class,
            Duration::class,
            GroupStop::class,
        ]);
        $report->setStopSeconds($minMinutes * 60);

        $units = [];
        $longest = null;
        $withoutData = 0;

        $report->each(function ($device, $data) use (&$units, &$longest, &$withoutData, $minMinutes) {
            if (!$data) {
                $withoutData++;
                return;
            }

            $count = 0;
            $total = 0;
            $unitLongest = null;

            foreach ($data['groups']->all() as $group) {
                if ($group->getKey() !== 'stop') {
                    continue;
                }

                $duration = (int) $this->statValue($group, 'duration');

                if ($duration < $minMinutes * 60) {
                    continue;
                }

                $count++;
                $total += $duration;

                if (!$unitLongest || $duration > $unitLongest['duration']) {
                    $unitLongest = ['duration' => $duration, 'group' => $group, 'device' => $device];
                }
            }

            if (!$count) {
                return;
            }

            if (!$longest || $unitLongest['duration'] > $longest['duration']) {
                $longest = $unitLongest;
            }

            $units[] = [
                'unidad' => $this->deviceLabel($device),
                'paradas' => $count,
                'tiempo_detenido' => $this->duration($total),
                'parada_mas_larga' => $this->describeStop($unitLongest, false),
                '_total' => $total,
            ];
        });

        usort($units, function ($a, $b) {
            return $b['_total'] <=> $a['_total'];
        });

        // La direccion se resuelve solo para la parada mas larga (geocodificar cuesta).
        foreach ($units as &$unit) {
            unset($unit['_total']);
        }
        unset($unit);

        return [
            'periodo' => "$fromLocal a $toLocal",
            'parada_minima' => "$minMinutes min",
            'unidades_consultadas' => count($ids),
            'unidades_sin_datos_gps' => $withoutData,
            'total_paradas' => array_sum(array_column($units, 'paradas')),
            'parada_mas_larga_de_todas' => $longest ? $this->describeStop($longest, true) : null,
            'por_unidad' => array_slice($units, 0, 20),
            'lista_truncada' => count($units) > 20,
        ];
    }

    private function describeStop(array $stop, bool $withPlace): array
    {
        $group = $stop['group'];
        $start = $group->getStartPosition();
        $end = $group->getEndPosition();

        $result = [
            'unidad' => $this->deviceLabel($stop['device']),
            'inicio' => $this->localTime($start->time ?? null),
            'fin' => $group->isOpen() ? 'sigue detenida' : $this->localTime($end->time ?? null),
            'duracion' => $this->duration($stop['duration']),
        ];

        return $withPlace ? $result + $this->eventPlace($start) : $result + array_filter([
            'coordenadas' => $start ? $this->coords($start->latitude, $start->longitude) : null,
        ]);
    }
}
