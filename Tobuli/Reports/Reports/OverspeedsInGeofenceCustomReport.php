<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Tobuli\History\Group;
use Tobuli\Entities\Geofence;
use Illuminate\Database\QueryException;
use Tobuli\Reports\DeviceReport;

class OverspeedsInGeofenceCustomReport extends DeviceReport
{
    const TYPE_ID = 904;

    protected $disableFields = ['stops', 'geofences'];

    /** @var Collection */
    protected $speedLimitGeofences;

    protected $onlyViolations = true;

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.overspeeds') . ' / ' . trans('front.geofences') . ' - Talentus';
    }

    public function getInputParameters(): array
    {
        return [
            \Field::checkbox('only_violations', 'Mostrar solo excesos de velocidad', true),
        ];
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $this->speedLimitGeofences = Geofence::where('user_id', $this->user->id)
            ->where('active', 1)
            ->whereNotNull('speed_limit')
            ->get();
    }

    protected function isEmptyResult($data)
    {
        return empty($data['groups']) || empty($data['groups']->all());
    }

    /**
     * Para una posicion: si excede el limite de una o mas geocercas, retorna un
     * evento por cada una. Si no excede ninguna, retorna un solo evento usando
     * la geocerca mas especifica (area mas pequena) -- solo cuando
     * onlyViolations esta desactivado.
     *
     * @return array<int, array{geofence: Geofence, exceeded: bool}>
     */
    protected function resolveGeofenceEvents(array $point, float $speed): array
    {
        $matches = $this->speedLimitGeofences->filter(function (Geofence $geofence) use ($point) {
            return $geofence->pointIn($point);
        });

        if ($matches->isEmpty()) {
            return [];
        }

        $exceeded = $matches->filter(function (Geofence $geofence) use ($speed) {
            return $speed > $geofence->speed_limit;
        });

        if ($exceeded->isNotEmpty()) {
            return $exceeded->map(function (Geofence $geofence) {
                return ['geofence' => $geofence, 'exceeded' => true];
            })->values()->all();
        }

        if ($this->onlyViolations) {
            return [];
        }

        $primary = Geofence::pickBySpeedLimitPriority($matches);

        return $primary ? [['geofence' => $primary, 'exceeded' => false]] : [];
    }

    protected function buildRow($position, Geofence $geofence, bool $exceeded): array
    {
        $speedDifference = $exceeded ? round($position->speed - $geofence->speed_limit, 2) : '';

        return [
            'server_time' => Formatter::time()->human($position->server_time),
            'time'        => Formatter::time()->human($position->time),
            'speed'       => Formatter::speed()->human($position->speed),
            'altitude'    => Formatter::altitude()->human($position->altitude),
            'latitude'    => $position->latitude,
            'longitude'   => $position->longitude,
            'location'    => $this->getLocation($position, $this->getAddress($position)),
            'geofence_name' => $geofence->name,
            'speed_limit' => $geofence->speed_limit,
            'speed_difference' => $speedDifference,
            'violation_level' => $exceeded ? $this->getInfringementLevel($speedDifference) : '',
            'speed_limit_exceeded' => $exceeded,
        ];
    }

    protected function getInfringementLevel($speedDifference)
    {
        if ($speedDifference <= 10) {
            return 'Moderado';
        } elseif ($speedDifference <= 20) {
            return 'Grave';
        } else {
            return 'Muy Grave';
        }
    }

    protected function processPosition($position): array
    {
        $point = [
            'latitude'  => $position->latitude,
            'longitude' => $position->longitude,
        ];

        $events = $this->resolveGeofenceEvents($point, (float) $position->speed);

        return array_map(function (array $event) use ($position) {
            return $this->buildRow($position, $event['geofence'], $event['exceeded']);
        }, $events);
    }

    protected function generateDevice($device)
    {
        $this->onlyViolations = Arr::get($this->parameters, 'only_violations', 1) != 0;

        $rows = [];
        try {
            $device->positions()
                ->orderliness('asc')
                ->whereBetween('time', [$this->date_from, $this->date_to])
                ->where('speed', '>', 0)
                ->chunk(
                    2000,
                    function ($positions) use (&$rows) {
                        foreach ($positions as $position) {
                            array_push($rows, ...$this->processPosition($position));
                        }
                    }
                );
        } catch (QueryException $e) {
            return null;
        }

        if (empty($rows)) {
            return [
                'meta'  => $this->getDeviceMeta($device),
                'error' => trans('front.nothing_found_request'),
            ];
        }

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => [
                'rows' => $rows,
            ],
        ];
    }

    protected function getTotals(Group $group, array $only = [])
    {
        return parent::getTotals($group, ['overspeed_count']);
    }
}
