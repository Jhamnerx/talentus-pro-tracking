<?php

namespace Tobuli\History\Actions;


class AppendSpeedLimitGeofence extends ActionAppend
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

        foreach ($position->geofences as $geofence_id) {
            $speed_limit = $this->getSpeedLimitGeofence($geofence_id);

            if (!empty($speed_limit))
                break;
        }

        return empty($speed_limit) ? null : $speed_limit;
    }

    public function getSpeedLimitGeofence($geofence_id) {
        $speed_limit = $this->limits[$geofence_id] ?? null;

        if (!is_null($speed_limit))
            return $speed_limit;

        $geofences = $this->history->getGeofences();

        foreach ($geofences as $geofence) {
            if ($geofence->id != $geofence_id)
                continue;

            $device = $this->getDevice();

            $resolved = $geofence->speedLimitFor($device->vehicleType, $device->vehicleState);

            $speed_limit = empty($resolved) ? 0 : $resolved;
            $this->limits[$geofence_id] = $speed_limit;

            break;
        }

        return $speed_limit;
    }
}