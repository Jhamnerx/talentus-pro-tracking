<?php

namespace App\Services\Ai\Tools;

use Formatter;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupOverspeed;
use Tobuli\History\Actions\OverspeedStatic;
use Tobuli\History\Actions\Speed;

class ExcesosVelocidadTool extends HistoryTool
{
    public function name(): string
    {
        return 'excesos_velocidad';
    }

    public function description(): string
    {
        return 'Excesos de velocidad de las unidades en un periodo (mismo calculo que el reporte '
            . '"Exceso de velocidad"). Devuelve el total de la flota, el ranking por unidad '
            . '(cantidad, velocidad maxima, tiempo en exceso) y los peores eventos con fecha y lugar. '
            . 'Requiere periodo y limite de velocidad; si el usuario no los dio, preguntale.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unidades' => $this->unitsProperty(),
            ] + $this->periodProperties() + [
                'limite' => [
                    'type' => 'number',
                    'description' => 'Limite de velocidad en la unidad del usuario (km/h o mph).',
                ],
                'duracion_minima_seg' => [
                    'type' => 'integer',
                    'description' => 'Opcional. Ignora excesos mas cortos que estos segundos.',
                ],
            ],
            'required' => ['desde', 'hasta', 'limite'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter([
            'Excesos de velocidad',
            $this->periodLabel($input),
            isset($input['limite']) ? $this->speed(Formatter::speed()->reverse($input['limite'])) : null,
            $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $limit = (float) $input['limite'];
        if ($limit <= 0) {
            throw new \App\Services\Ai\ToolException('El limite de velocidad debe ser mayor que 0.');
        }

        $minDuration = (int) ($input['duracion_minima_seg'] ?? 0);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            Duration::class,
            Distance::class,
            Speed::class,
            OverspeedStatic::class,
            GroupOverspeed::class,
        ]);
        $report->setSpeedLimit(Formatter::speed()->reverse($limit));

        $units = [];
        $events = [];
        $withoutData = 0;

        $report->each(function ($device, $data) use (&$units, &$events, &$withoutData, $minDuration) {
            if (!$data) {
                $withoutData++;
                return;
            }

            $count = 0;
            $maxSpeed = 0;
            $seconds = 0;

            foreach ($data['groups']->all() as $group) {
                if ($group->getKey() !== 'overspeed') {
                    continue;
                }

                $duration = (int) $this->statValue($group, 'duration');

                if ($minDuration && $duration < $minDuration) {
                    continue;
                }

                $speed = (float) $this->statValue($group, 'speed_max');

                $count++;
                $seconds += $duration;
                $maxSpeed = max($maxSpeed, $speed);

                $events[] = [
                    'device' => $device,
                    'speed' => $speed,
                    'duration' => $duration,
                    'position' => $group->getStartPosition(),
                ];
            }

            if ($count) {
                $units[] = [
                    'unidad' => $this->deviceLabel($device),
                    'excesos' => $count,
                    'velocidad_maxima' => $this->speed($maxSpeed),
                    'tiempo_en_exceso' => $this->duration($seconds),
                    '_max' => $maxSpeed,
                ];
            }
        });

        usort($units, function ($a, $b) {
            return [$b['excesos'], $b['_max']] <=> [$a['excesos'], $a['_max']];
        });
        usort($events, function ($a, $b) {
            return $b['speed'] <=> $a['speed'];
        });

        $total = array_sum(array_column($units, 'excesos'));

        $worst = [];
        foreach (array_slice($events, 0, 5) as $event) {
            $worst[] = [
                'unidad' => $this->deviceLabel($event['device']),
                'fecha' => $this->localTime($event['position']->time ?? null),
                'velocidad_maxima' => $this->speed($event['speed']),
                'duracion' => $this->duration($event['duration']),
            ] + $this->eventPlace($event['position']);
        }

        return [
            'periodo' => "$fromLocal a $toLocal",
            'limite' => $this->speed(Formatter::speed()->reverse($limit)),
            'unidades_consultadas' => count($ids),
            'unidades_sin_datos_gps' => $withoutData,
            'total_excesos' => $total,
            'unidades_con_excesos' => count($units),
            'ranking' => array_map(function ($u) {
                unset($u['_max']);
                return $u;
            }, array_slice($units, 0, 15)),
            'ranking_truncado' => count($units) > 15,
            'peores_eventos' => $worst,
        ];
    }
}
