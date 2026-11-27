<?php

namespace Tobuli\Exporters\EntityManager\Geofence;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tobuli\Exporters\Downloader\JsonDownloader;
use Tobuli\Exporters\EntityManager\ExporterInterface;

class GexpExporter implements ExporterInterface
{
    public function generateReport(Builder $query, array $attributes, string $filename): BinaryFileResponse
    {
        $attributes = array_filter($attributes, fn ($attr) => !strpos($attr, '.'));

        $data = $query->get($attributes)->toArray();

        $this->decorate($data);

        return (new JsonDownloader())
            ->download($data, $filename);
    }

    private function decorate(array &$data)
    {
        $groupIds = [];
        $data = [
            'groups' => [],
            'geofences' => $data,
        ];

        foreach ($data['geofences'] as &$geofence) {
            $group = $this->formatGroup($geofence['group'] ?? null);
            $groupId = $group['id'];

            if (!in_array($groupId, $groupIds, true)) {
                $groupIds[] = $groupId;
                $data['groups'][] = $group;
            }

            unset($geofence['group']);

            // Limites por tipo/estado de vehiculo por NOMBRE: los ids no
            // sirven en otra cuenta; el importador los resuelve o los crea.
            if (array_key_exists('vehicle_speed_limits', $geofence)) {
                $geofence['vehicle_speed_limits'] = array_values(array_filter(array_map(function ($row) {
                    if (empty($row['vehicle_type']['name'])) {
                        return null;
                    }

                    return [
                        'vehicle_type'  => $row['vehicle_type']['name'],
                        'vehicle_state' => $row['vehicle_state']['name'] ?? null,
                        'speed_limit'   => $row['speed_limit'],
                    ];
                }, (array) $geofence['vehicle_speed_limits'])));
            }
        }
    }

    private function formatGroup($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        return ['id' => null];
    }
}