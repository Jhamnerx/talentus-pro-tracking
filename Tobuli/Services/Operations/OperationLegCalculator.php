<?php

namespace Tobuli\Services\Operations;

use Tobuli\Entities\Geofence;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationStop;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\EngineOnOff;
use Tobuli\History\Actions\GeofencesIn;
use Tobuli\History\Actions\GroupStop;
use Tobuli\History\Actions\NoDataDuration;
use Tobuli\History\DeviceHistory;
use Tobuli\History\Group;

/**
 * Calcula, UNA vez por tramo cerrado, sus paradas, ralenti, motor apagado,
 * distancia y tiempo sin datos con el pipeline de historial. Nunca se llama
 * sobre un tramo abierto: lo que se muestra en vivo sale de `traccar`.
 *
 * Reglas del pipeline que hay que tener presentes:
 * - "En marcha" exige motor encendido y velocidad >= stop_speed
 *   (AppendMoveState). Sin sensor de ignicion el motor se deduce del GPS y el
 *   desglose ralenti / motor apagado no es fiable: se guarda en nulo.
 * - Una parada mas corta que stop_seconds queda dentro del tramo en marcha.
 * - Los huecos entre tramas cuentan como parada; NoDataDuration los separa.
 */
class OperationLegCalculator
{
    const GAP_SECONDS = 90;

    public function calculate(OperationLeg $leg): void
    {
        $leg->loadMissing('cycle.operation', 'device');

        $device = $leg->device;
        $operation = $leg->cycle->operation;

        if (! $device || ! $operation || ! $leg->ended_at) {
            return;
        }

        $authorized = Geofence::userOwned($operation->user)
            ->category($operation->authorizedCategories())
            ->get();

        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_seconds' => max(1, (int) $operation->stop_threshold_seconds),
            'stop_speed'   => $device->min_moving_speed,
            'gap_seconds'  => self::GAP_SECONDS,
        ]);
        $history->setGeofences($authorized);
        $history->registerActions([
            DriveStop::class,
            Duration::class,
            Distance::class,
            EngineHours::class,
            EngineOnOff::class,
            GeofencesIn::class,
            NoDataDuration::class,
            GroupStop::class,
        ]);
        $history->setRange(
            $leg->started_at->format('Y-m-d H:i:s'),
            $leg->ended_at->format('Y-m-d H:i:s')
        );

        try {
            $data = $history->get();
        } catch (\PDOException $e) {
            // Vehiculo sin tabla de posiciones: no hay nada que calcular.
            $leg->calculated_at = date('Y-m-d H:i:s');
            $leg->save();
            return;
        }

        $hasEngineSensor = ! is_null($device->getEngineSensor());

        /** @var Group $root */
        $root = $data['root'];

        $leg->stops()->delete();

        $stopSeconds = 0;
        $idleSeconds = 0;
        $engineOffSeconds = 0;
        $stopsCount = 0;
        $unauthorized = 0;
        $authorizedById = $authorized->keyBy('id');

        foreach ($data['groups']->all() as $group) {
            if ($group->getKey() !== 'stop') {
                continue;
            }

            $start = $group->getStartPosition();
            $end = $group->getEndPosition();

            if (! $start || ! $end) {
                continue;
            }

            $duration = (int) $this->stat($group, 'duration');

            if ($duration <= 0) {
                continue;
            }

            $engineOn = $hasEngineSensor ? (int) $this->stat($group, 'engine_on_duration') : null;
            $engineOff = $hasEngineSensor ? (int) $this->stat($group, 'engine_off_duration') : null;

            $geofenceName = null;
            $ids = $this->stat($group, 'geofences_in');

            foreach ((array) $ids as $id) {
                if ($authorizedById->has($id)) {
                    $geofenceName = $authorizedById->get($id)->name;
                    break;
                }
            }

            $isAuthorized = ! is_null($geofenceName);
            $doubtful = $this->endsWithJump($end);

            OperationStop::create([
                'leg_id'             => $leg->id,
                'device_id'          => $device->id,
                'started_at'         => $start->time,
                'ended_at'           => $end->time,
                'duration_seconds'   => $duration,
                'latitude'           => $start->latitude,
                'longitude'          => $start->longitude,
                'engine_on_seconds'  => $engineOn,
                'engine_off_seconds' => $engineOff,
                'geofence_name'      => $geofenceName,
                'authorized'         => $isAuthorized,
                'doubtful'           => $doubtful,
            ]);

            $stopsCount++;
            $stopSeconds += $duration;

            if (! $isAuthorized && ! $doubtful) {
                $unauthorized++;
            }
        }

        if ($hasEngineSensor) {
            $idleSeconds = (int) $this->stat($root, 'engine_idle');
            $engineOffSeconds = (int) $this->stat($root, 'engine_off_duration');
        }

        $leg->distance_meters = (int) round(((float) $this->stat($root, 'distance')) * 1000);
        $leg->stop_seconds = $stopSeconds;
        $leg->idle_seconds = $hasEngineSensor ? $idleSeconds : null;
        $leg->engine_off_seconds = $hasEngineSensor ? $engineOffSeconds : null;
        $leg->gap_seconds = (int) $this->stat($root, 'no_data_duration');
        $leg->stops_count = $stopsCount;
        $leg->unauthorized_stops_count = $unauthorized;
        $leg->calculated_at = date('Y-m-d H:i:s');
        $leg->save();
    }

    protected function stat(Group $group, string $key)
    {
        if (! $group->hasStat($key)) {
            return null;
        }

        $stat = $group->getStat($key);

        return $stat ? $stat->get() : null;
    }

    /** La parada termina con un salto desde parada: posible GPS congelado. */
    protected function endsWithJump($position): bool
    {
        if (! $position || ! method_exists($position, 'getParameter')) {
            return false;
        }

        return filter_var($position->getParameter('jump_from_stop'), FILTER_VALIDATE_BOOLEAN);
    }
}
