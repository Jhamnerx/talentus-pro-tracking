<?php

namespace Tobuli\History\Actions;

use Tobuli\Entities\Geofence;

class AppendSpeedLimitGeofenceByPriority extends ActionAppend
{
    private $limits = [];

    /** @var array<int,int>|null id de geocerca => id del dueño */
    private $owners = null;

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

        // La clasificacion del dispositivo para un dueño dado no cambia durante el
        // recorrido, asi que la clave solo necesita (dueño, geocerca) por candidata.
        $key = $this->candidatesKey($position->geofences);

        if (array_key_exists($key, $this->limits))
            return $this->limits[$key];

        $resolved = collect($this->history->getGeofences())
            ->whereIn('id', $position->geofences)
            ->map(function (Geofence $geofence) use ($device) {
                return [
                    'geofence' => $geofence,
                    'speed_limit' => $geofence->speedLimitForDevice($device),
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
        $parts = [];

        foreach ($geofenceIds as $id) {
            $parts[] = $this->geofenceOwner($id) . ':' . $id;
        }

        sort($parts);

        return implode(',', $parts);
    }

    private function geofenceOwner($geofenceId): int
    {
        if (is_null($this->owners)) {
            $this->owners = [];

            foreach ($this->history->getGeofences() ?: [] as $geofence) {
                $this->owners[$geofence->id] = (int) $geofence->user_id;
            }
        }

        return $this->owners[$geofenceId] ?? 0;
    }
}
