<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;
use Tobuli\Entities\Geofence;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupGeofenceIn;

class GeocercasTool extends HistoryTool
{
    const MAX_GEOFENCES = 30;

    public function name(): string
    {
        return 'geocercas';
    }

    public function description(): string
    {
        return 'Entradas y salidas de geocercas en un periodo (mismo calculo que el reporte "Geocercas, '
            . 'entradas/salidas"): por geocerca, cuantas entradas hubo, tiempo total dentro y que unidades; '
            . 'y las visitas mas largas con hora de entrada y salida. Indica las geocercas por nombre; '
            . 'sin nombres usa todas las del usuario (maximo ' . self::MAX_GEOFENCES . ').';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['unidades' => $this->unitsProperty()] + $this->periodProperties() + [
                'geocercas' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Nombres (o parte del nombre) de las geocercas. Vacio = todas.',
                ],
            ],
            'required' => ['desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        $names = (array) ($input['geocercas'] ?? []);

        return implode(' · ', array_filter([
            'Geocercas', $names ? implode(', ', array_slice($names, 0, 2)) : null,
            $this->periodLabel($input), $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $geofences = $this->findGeofences((array) ($input['geocercas'] ?? []));
        $names = $geofences->pluck('name', 'id')->all();

        $report = $this->makeReport($ids, $fromUtc, $toUtc, [
            Duration::class,
            Distance::class,
            GroupGeofenceIn::class,
        ]);
        $report->setGeofences($geofences);

        $byGeofence = [];
        $visits = [];

        $report->each(function ($device, $data) use (&$byGeofence, &$visits, $names) {
            if (!$data) {
                return;
            }

            foreach ($data['groups']->all() as $group) {
                $geofenceId = $group->geofence_id ?? null;
                if (!$geofenceId || !isset($names[$geofenceId])) {
                    continue;
                }

                $seconds = (int) $this->statValue($group, 'duration');
                $unit = $this->deviceLabel($device);

                $entry = &$byGeofence[$geofenceId];
                $entry['entradas'] = ($entry['entradas'] ?? 0) + 1;
                $entry['_seconds'] = ($entry['_seconds'] ?? 0) + $seconds;
                $entry['_units'][$unit] = true;
                unset($entry);

                $visits[] = [
                    'geocerca' => $names[$geofenceId],
                    'unidad' => $unit,
                    'entrada' => $this->localTime($group->getStartPosition()->time ?? null),
                    'salida' => $group->isOpen() ? 'sigue dentro' : $this->localTime($group->getEndPosition()->time ?? null),
                    '_seconds' => $seconds,
                ];
            }
        });

        uasort($byGeofence, function ($a, $b) {
            return $b['entradas'] <=> $a['entradas'];
        });
        usort($visits, function ($a, $b) {
            return $b['_seconds'] <=> $a['_seconds'];
        });

        $summary = [];
        foreach (array_slice($byGeofence, 0, 15, true) as $id => $row) {
            $units = array_keys($row['_units']);
            $summary[] = [
                'geocerca' => $names[$id],
                'entradas' => $row['entradas'],
                'tiempo_dentro' => $this->duration($row['_seconds']),
                'unidades' => count($units) > 5 ? count($units) . ' unidades' : implode(', ', $units),
            ];
        }

        return [
            'periodo' => "$fromLocal a $toLocal",
            'geocercas_consultadas' => count($names),
            'total_entradas' => array_sum(array_column($byGeofence, 'entradas')),
            'por_geocerca' => $summary,
            'geocercas_sin_entradas' => array_values(array_slice(array_diff_key($names, $byGeofence), 0, 10)),
            'visitas_mas_largas' => array_map(function ($v) {
                $v['duracion'] = $this->duration($v['_seconds']);
                unset($v['_seconds']);
                return $v;
            }, array_slice($visits, 0, 8)),
        ];
    }

    private function findGeofences(array $names)
    {
        $query = Geofence::userAccessible($this->user);

        if ($names) {
            $query->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->orWhere('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], trim($name)) . '%');
                }
            });
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            throw new ToolException($names
                ? 'No encontre geocercas con esos nombres: ' . implode(', ', $names)
                : 'El usuario no tiene geocercas.');
        }

        if ($total > self::MAX_GEOFENCES) {
            $sample = (clone $query)->limit(10)->pluck('name')->implode(', ');
            throw new ToolException("Hay $total geocercas; el maximo por consulta es " . self::MAX_GEOFENCES
                . ". Pide al usuario cuales consultar. Algunas: $sample.");
        }

        return $query->get();
    }
}
