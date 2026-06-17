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

        $key = $this->candidatesKey($position->geofences);

        if (array_key_exists($key, $this->limits))
            return $this->limits[$key];

        $candidates = collect($this->history->getGeofences())
            ->whereIn('id', $position->geofences)
            ->filter(function (Geofence $geofence) {
                return !empty($geofence->speed_limit);
            });

        $geofence = Geofence::pickBySpeedLimitPriority($candidates);

        return $this->limits[$key] = $geofence->speed_limit ?? null;
    }

    private function candidatesKey(array $geofenceIds): string
    {
        sort($geofenceIds);

        return implode(',', $geofenceIds);
    }
}
