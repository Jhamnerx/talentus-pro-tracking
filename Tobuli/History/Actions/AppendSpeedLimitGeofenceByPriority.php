<?php

namespace Tobuli\History\Actions;

use Tobuli\Entities\Geofence;

class AppendSpeedLimitGeofenceByPriority extends ActionAppend
{
    private $limits = [];

    static public function required()
    {
        return [
            AppendGeofences::class,
        ];
    }

    public function boot()
    {
    }

    public function proccess(&$position)
    {
        $position->speed_limit = $this->getSpeedLimit($position);
    }

    protected function getSpeedLimit($position)
    {
        if (empty($position->geofences))
            return null;

        $device = $this->getDevice();

        $key = $this->candidatesKey($position->geofences) . ':' . $device->vehicle_type_id . ':' . $device->vehicle_state_id;

        if (array_key_exists($key, $this->limits))
            return $this->limits[$key];

        $resolved = collect($this->history->getGeofences())
            ->whereIn('id', $position->geofences)
            ->map(function (Geofence $geofence) use ($device) {
                return [
                    'geofence' => $geofence,
                    'speed_limit' => $geofence->speedLimitFor($device->vehicleType, $device->vehicleState),
                ];
            })
            ->filter(function (array $candidate) {
                return !empty($candidate['speed_limit']);
            });

        if ($resolved->isEmpty())
            return $this->limits[$key] = null;

        $winner = $resolved->sortBy(function (array $candidate) {
            $area = $candidate['geofence']->approxAreaSqMeters();

            return is_null($area) ? PHP_FLOAT_MAX : $area;
        })->first();

        return $this->limits[$key] = $winner['speed_limit'];
    }

    private function candidatesKey(array $geofenceIds): string
    {
        sort($geofenceIds);

        return implode(',', $geofenceIds);
    }
}
