<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Reports\AiHistoryReport;
use Tobuli\History\Group;

/**
 * Base para herramientas que usan el historial GPS (excesos, paradas...).
 */
abstract class HistoryTool extends AbstractTool
{
    /** @var AiHistoryReport */
    protected $report;

    protected function makeReport(array $deviceIds, string $fromUtc, string $toUtc, array $actions): AiHistoryReport
    {
        $report = new AiHistoryReport();
        $report->setUser($this->user);
        $report->setFormat('json');
        $report->setRange($fromUtc, $toUtc);
        $report->setDevicesQuery($this->devicesQuery()->whereIn('devices.id', $deviceIds));
        $report->setActions($actions);

        return $this->report = $report;
    }

    protected function statValue(Group $group, string $key)
    {
        return $group->hasStat($key) ? $group->getStat($key)->value() : null;
    }

    protected function statHuman(Group $group, string $key): ?string
    {
        return $group->hasStat($key) ? $group->stats()->human($key) : null;
    }

    /** Estadisticas legibles de un grupo para las claves pedidas. */
    protected function statsHuman(Group $group, array $keys): array
    {
        $result = [];

        foreach ($keys as $key => $label) {
            $value = $this->statHuman($group, $key);

            if ($value !== null && $value !== '') {
                $result[$label] = $value;
            }
        }

        return $result;
    }

    protected function eventPlace($position): array
    {
        if (!$position) {
            return [];
        }

        return array_filter([
            'direccion' => $this->report ? $this->report->address($position) : null,
            'coordenadas' => $this->coords($position->latitude, $position->longitude),
        ]);
    }
}
