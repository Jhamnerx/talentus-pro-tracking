<?php

namespace Tobuli\Helpers\Alerts\Check;

use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;
use Tobuli\Entities\Event;

class GeofenceAlertCheck extends AlertCheck
{
    protected $movingGeofence;

    public function __construct(Device $device, Alert $alert)
    {
        parent::__construct($device, $alert);

        $this->movingGeofence = settings('plugins.moving_geofence.status');
    }

    public function checkEvents($position, $prevPosition)
    {
        if (! $position)
            return null;

        if (! $position->isValid())
            return null;

        $prevPosition = $this->device->positionTraccar();

        if (! $this->preCheck($position, $prevPosition))
            return null;

        $events = [];

        foreach ($this->alert->geofences as $geofence) {
            if (! $type = $this->check($position, $prevPosition, $geofence))
                continue;

            $event = $this->getEvent();

            if ($type == 'geofence_in') {
                $type = Event::TYPE_ZONE_IN;
            } elseif ($type == 'geofence_out') {
                $type = Event::TYPE_ZONE_OUT;
            } elseif ($type == 'geofence_overspeed') {
                $type = Event::TYPE_ZONE_OVERSPEED;
            }

            $event->type = $type;
            $event->message = $type;
            $event->geofence_id = $geofence->id;
            $event->setAdditional('geofence', $geofence->name);

            if ($type == Event::TYPE_ZONE_OVERSPEED) {
                // $startTime = Cache::get('overspeed_start_' . $this->device->id);
                // $endTime = Cache::get('overspeed_end_' . $this->device->id);

                // if ($startTime && $endTime) {
                //     $duration = $startTime->diffInSeconds($endTime);
                //     $formattedDuration = $duration < 60 ? "{$duration} segundos" : round($duration / 60, 2) . " minutos";
                //     $event->setAdditional('overspeed_duration', $formattedDuration);
                //     Cache::forget('overspeed_start_' . $this->device->id);  // Clear the start time from cache
                //     Cache::forget('overspeed_end_' . $this->device->id);    // Clear the end time from cache
                // }

                $event->setAdditional('exceso', ($position->speed - $geofence->speed_limit));
            }

            $this->silent($event);

            $events[] = $event;
        }

        return $events;
    }

    protected function check($position, $prevPosition, $geofence)
    {
        if ($this->geofenceMovesWithDevice($geofence)) {
            return false;
        }

        switch ($this->alert->type) {
            case 'geofence_in':
                if (! $this->checkGeofenceWithSchedules($position, $geofence))
                    return false;
                if ($this->checkGeofenceWithSchedules($prevPosition, $geofence))
                    return false;

                return 'geofence_in';

            case 'geofence_out':
                //is current in
                if ($this->checkGeofence($position, $geofence))
                    return false;

                //is previous in
                if (! $this->checkGeofence($prevPosition, $geofence))
                    return false;

                return 'geofence_out';

            case 'geofence_inout':
                $isCurrentIn = $this->checkGeofence($position, $geofence);
                $isPreviousIn = $this->checkGeofence($prevPosition, $geofence);

                if ($isCurrentIn && ! $isPreviousIn)
                    return 'geofence_in';
                if (! $isCurrentIn && $isPreviousIn)
                    return 'geofence_out';

                return false;
            case 'geofence_overspeed':
                if (!$this->checkGeofenceWithSchedules($position, $geofence)) {
                    return false;
                }

                $isCurrentIn = $this->checkGeofence($position, $geofence);

                // Si la velocidad excede el límite y no hemos comenzado a rastrear, establecer la hora de inicio
                if ($this->checkSpeed($position, $geofence) &&  $isCurrentIn) {
                    return 'geofence_overspeed';
                }

                return false;
            default:
                return false;
        }
    }
    public function checkSpeed($position, $geofence)
    {
        if (!$position)
            return false;

        if (!$position->isValid())
            return false;

        if (!$geofence->speed_limit)
            return false;

        if ($position->speed <= $geofence->speed_limit)
            return false;

        return true;
    }

    protected function checkGeofence($position, $geofence)
    {
        if (! $position)
            return false;

        if (! $position->isValid())
            return false;

        return $geofence->pointIn($position);
    }

    protected function checkGeofenceWithSchedules($position, $geofence)
    {
        if (! $this->checkSchedules($position->time))
            return false;

        return $this->checkGeofence($position, $geofence);
    }

    protected function isPointEqual($position, $prevPosition)
    {
        if (! $position)
            return false;

        if (! $prevPosition)
            return false;

        if ($position->latitude != $prevPosition->latitude)
            return false;

        if ($position->longitude != $prevPosition->longitude)
            return false;

        return true;
    }

    protected function preCheck($position, $prevPosition)
    {
        if ($this->isPointEqual($position, $prevPosition))
            return false;

        switch ($this->alert->type) {
            case 'geofence_out':
            case 'geofence_inout':
                if (! $this->checkSchedules($position->time))
                    return false;
                break;
        }

        return true;
    }

    protected function geofenceMovesWithDevice($geofence)
    {
        if (! $this->movingGeofence) {
            return false;
        }

        return $geofence->device_id == $this->device->id;
    }
}
