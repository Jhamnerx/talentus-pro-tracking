<?php

namespace Tobuli\Helpers\Alerts\Check;

use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;
use Tobuli\Entities\Event;
use Illuminate\Support\Facades\Cache;

class GeofenceAlertCheck extends AlertCheck
{
    protected $movingGeofence;

    /**
     * Duración real (segundos) del exceso de velocidad calculada por checkOverspeedDuration()
     * o checkOverspeedClosure() para la geocerca que acaba de disparar el evento. Se consume y
     * resetea en checkEvents() inmediatamente después de leerla, así que no se arrastra entre
     * geocercas del mismo loop.
     */
    protected $lastOverspeedDuration = null;

    /**
     * Cuando el episodio de exceso se cierra (el vehículo deja de superar el límite, sale de
     * la geocerca, o falla el horario) y la duración acumulada alcanza el mínimo configurado,
     * aquí se guarda el snapshot de la ÚLTIMA posición que sí estaba sobre el límite (velocidad,
     * ubicación, altitud, rumbo y hora), para que el evento quede registrado donde realmente
     * ocurrió el exceso y no en la posición de cierre (que ya está por debajo del límite, fuera
     * de la geocerca, o fuera de horario). Se consume y resetea en checkEvents() inmediatamente
     * después de leerlo.
     */
    protected $pendingClosureSnapshot = null;

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
                if ($this->pendingClosureSnapshot !== null) {
                    // Evento disparado en el cierre del episodio (ver checkOverspeedClosure()):
                    // se reemplazan ubicación/velocidad/hora del evento por las de la última
                    // posición que sí superaba el límite, en vez de las de la posición actual
                    // (que es la que cerró el episodio y ya no está en exceso).
                    $snapshot = $this->pendingClosureSnapshot;

                    $event->latitude  = $snapshot['latitude'];
                    $event->longitude = $snapshot['longitude'];
                    $event->altitude  = $snapshot['altitude'];
                    $event->course    = $snapshot['course'];
                    $event->speed     = $snapshot['speed_display'];
                    $event->time      = $snapshot['time_string'];

                    $event->setAdditional('exceso', ($snapshot['speed_raw'] - $this->resolveSpeedLimit($geofence)));

                    $this->pendingClosureSnapshot = null;
                } else {
                    $event->setAdditional('exceso', ($position->speed - $this->resolveSpeedLimit($geofence)));
                }

                if ($this->lastOverspeedDuration !== null) {
                    $event->setAdditional('overspeed_duration', $this->lastOverspeedDuration);
                    $this->lastOverspeedDuration = null;
                }
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
                    return $this->checkOverspeedClosure($position, $geofence);
                }

                $sat = $position->getParameter('sat');
                if (!is_null($sat) && $sat <= 10) {
                    return false;
                }

                $isCurrentIn = $this->checkGeofence($position, $geofence);

                if ($this->checkSpeed($position, $geofence) && $isCurrentIn) {
                    return $this->checkOverspeedDuration($position, $geofence);
                }

                return $this->checkOverspeedClosure($position, $geofence);
            default:
                return false;
        }
    }
    protected function getOverspeedTrackingKey($geofence): string
    {
        return 'geofence_overspeed_start_' . $this->alert->id . '_' . $this->device->id . '_' . $geofence->id;
    }

    protected function checkOverspeedDuration($position, $geofence)
    {
        $minDuration = $this->alert->getMinOverspeedDuration();

        if (!$minDuration) {
            return 'geofence_overspeed';
        }

        $positionTime = strtotime($position->time);
        $cacheKey     = $this->getOverspeedTrackingKey($geofence);
        $cached       = Cache::get($cacheKey);
        $startTime    = $cached['start'] ?? null;
        $maxGap       = (int) config('tobuli.overspeed_tracking_max_gap', 300);
        $snapshot     = $this->snapshotOverspeedPosition($position);

        // Sin marca, marca posterior a la posición (historial fuera de orden) o marca
        // demasiado antigua (el equipo dejó de reportar sobre el límite sin registro
        // intermedio): se reinicia el conteo desde esta posición.
        if (!$startTime || $positionTime < $startTime || ($positionTime - $startTime) > $maxGap) {
            Cache::put($cacheKey, ['start' => $positionTime, 'last' => $snapshot], now()->addHours(24));
            return false;
        }

        $duration = $positionTime - $startTime;

        if ($duration >= $minDuration) {
            Cache::forget($cacheKey);
            $this->lastOverspeedDuration = $duration;
            return 'geofence_overspeed';
        }

        // Aún no alcanza la duración mínima: se conserva el inicio del episodio, pero se
        // actualiza el snapshot de la última posición conocida sobre el límite, por si el
        // vehículo baja de velocidad (o sale de la geocerca/horario) antes de volver a
        // superar el límite, y checkOverspeedClosure() necesita cerrarlo con este dato.
        Cache::put($cacheKey, ['start' => $startTime, 'last' => $snapshot], now()->addHours(24));

        return false;
    }

    /**
     * Cierra un episodio de exceso que venía siendo trackeado por checkOverspeedDuration(),
     * para el caso en que la propia posición actual ya no califica para seguir midiéndolo
     * (bajó del límite, salió de la geocerca, o falla el horario). Sin esto, ese tipo de
     * posición simplemente limpiaba el cache y la duración acumulada se perdía sin evaluarse,
     * incluso cuando el exceso real (confirmado por el propio equipo, ej. io155) sí superaba
     * la duración mínima configurada.
     */
    protected function checkOverspeedClosure($position, $geofence)
    {
        $cacheKey = $this->getOverspeedTrackingKey($geofence);
        $minDuration = $this->alert->getMinOverspeedDuration();

        if (!$minDuration) {
            $this->clearOverspeedTracking($geofence);
            return false;
        }

        $cached = Cache::get($cacheKey);
        Cache::forget($cacheKey);

        if (!$cached || empty($cached['last'])) {
            return false;
        }

        $startTime = $cached['start'];
        $last      = $cached['last'];
        $maxGap    = (int) config('tobuli.overspeed_tracking_max_gap', 300);
        $closeTime = strtotime($position->time);

        // Si la posición de cierre llegó demasiado tiempo después de la última posición
        // conocida sobre el límite, el dato ya no es confiable (hueco grande de reporte):
        // se descarta en vez de calcular una duración artificial.
        if ($closeTime < $last['time'] || ($closeTime - $last['time']) > $maxGap) {
            return false;
        }

        $duration = $closeTime - $startTime;

        if ($duration < $minDuration) {
            return false;
        }

        $this->pendingClosureSnapshot = $last;
        $this->lastOverspeedDuration  = $duration;

        return 'geofence_overspeed';
    }

    protected function snapshotOverspeedPosition($position): array
    {
        return [
            'time'          => strtotime($position->time),
            'time_string'   => $position->time,
            'latitude'      => $position->latitude,
            'longitude'     => $position->longitude,
            'altitude'      => $position->altitude,
            'course'        => $position->course,
            'speed_raw'     => $position->speed,
            'speed_display' => $this->device->getSpeed($position),
        ];
    }

    protected function clearOverspeedTracking($geofence): void
    {
        Cache::forget($this->getOverspeedTrackingKey($geofence));
    }

    public function checkSpeed($position, $geofence)
    {
        $tolerance = $this->alert->getSpeedTolerance() ?: 0;

        if (!$position)
            return false;

        if (!$position->isValid())
            return false;

        $speedLimit = $this->resolveSpeedLimit($geofence);

        if (!$speedLimit)
            return false;

        // Sumar la tolerancia al límite de velocidad de la geofence
        $adjustedSpeedLimit = $speedLimit + $tolerance;

        if ($position->speed <= $adjustedSpeedLimit)
            return false;

        return true;
    }

    protected function resolveSpeedLimit($geofence): ?float
    {
        // El limite se resuelve con la clasificacion que el dueño de la geocerca
        // asigno al dispositivo (ver Geofence::speedLimitForDevice).
        return $geofence->speedLimitForDevice($this->device);
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
