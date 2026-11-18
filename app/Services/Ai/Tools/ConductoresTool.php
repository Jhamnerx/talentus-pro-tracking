<?php

namespace App\Services\Ai\Tools;

use Formatter;
use Tobuli\Entities\UserDriver;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Drivers;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupDriver;
use Tobuli\History\Actions\Harsh;
use Tobuli\History\Actions\OverspeedStatic;
use Tobuli\History\Actions\Speed;

/**
 * Puntaje de conduccion por conductor con la misma formula del reporte RAG:
 * exceso (seg/10 por 100 km) + aceleraciones bruscas + frenadas bruscas por 100 km.
 */
class ConductoresTool extends HistoryTool
{
    public function name(): string
    {
        return 'conductores';
    }

    public function description(): string
    {
        return 'Ranking de conductores en un periodo con el puntaje del reporte RAG (mas alto = peor): '
            . 'km, tiempo en exceso de velocidad, aceleraciones y frenadas bruscas por conductor. '
            . 'Requiere un limite de velocidad. Solo hay conductores si las unidades los registran (RFID/iButton).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['unidades' => $this->unitsProperty()] + $this->periodProperties() + [
                'limite' => [
                    'type' => 'number',
                    'description' => 'Limite de velocidad para el puntaje (unidad del usuario).',
                ],
            ],
            'required' => ['desde', 'hasta', 'limite'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return implode(' · ', array_filter(['Conductores (RAG)', $this->periodLabel($input), $this->unitsLabel($input)]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            Duration::class,
            Distance::class,
            Speed::class,
            OverspeedStatic::class,
            Harsh::class,
            Drivers::class,
            GroupDriver::class,
        ]);
        $report->setSpeedLimit(Formatter::speed()->reverse((float) $input['limite']));

        $drivers = [];

        $report->each(function ($device, $data) use (&$drivers) {
            if (!$data) {
                return;
            }

            foreach ($data['groups']->all() as $group) {
                if ($group->getKey() !== 'driver' || empty($group->driver_id)) {
                    continue;
                }

                $d = &$drivers[$group->driver_id];
                $d['distance'] = ($d['distance'] ?? 0) + (float) $this->statValue($group, 'distance');
                $d['overspeed'] = ($d['overspeed'] ?? 0) + (int) $this->statValue($group, 'overspeed_duration');
                $d['ha'] = ($d['ha'] ?? 0) + (int) $this->statValue($group, 'harsh_acceleration_count');
                $d['hb'] = ($d['hb'] ?? 0) + (int) $this->statValue($group, 'harsh_breaking_count');
                $d['units'][$this->deviceLabel($device)] = true;
                unset($d);
            }
        });

        if (empty($drivers)) {
            return [
                'periodo' => "$fromLocal a $toLocal",
                'conductores' => [],
                'mensaje' => 'No hay conductores identificados en ese periodo (las unidades no registraron RFID/iButton).',
            ];
        }

        $names = UserDriver::whereIn('id', array_keys($drivers))->pluck('name', 'id')->all();
        $rows = [];

        foreach ($drivers as $id => $d) {
            $km = $d['distance'];
            $scoreOver = ($d['overspeed'] > 0 && $km > 0) ? $d['overspeed'] / 10 / $km * 100 : 0;
            $scoreHa = ($d['ha'] > 0 && $km > 0) ? $d['ha'] / $km * 100 : 0;
            $scoreHb = ($d['hb'] > 0 && $km > 0) ? $d['hb'] / $km * 100 : 0;

            $rows[] = [
                'conductor' => $names[$id] ?? "Conductor #$id",
                'puntaje_rag' => round($scoreOver + $scoreHa + $scoreHb, 2),
                'distancia' => $this->distance($km),
                'tiempo_en_exceso' => $this->duration($d['overspeed']),
                'aceleraciones_bruscas' => $d['ha'],
                'frenadas_bruscas' => $d['hb'],
                'unidades' => implode(', ', array_slice(array_keys($d['units']), 0, 3)),
            ];
        }

        usort($rows, function ($a, $b) {
            return $b['puntaje_rag'] <=> $a['puntaje_rag'];
        });

        return [
            'periodo' => "$fromLocal a $toLocal",
            'formula' => 'puntaje = exceso (seg/10 por 100 km) + aceleraciones y frenadas bruscas por 100 km; mayor = peor',
            'conductores' => array_slice($rows, 0, 20),
        ];
    }
}
