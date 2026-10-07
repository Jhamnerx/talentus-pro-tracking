<?php

namespace Tobuli\Helpers\Alerts\Check;

use Tobuli\Entities\Event;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\OperationDeviceState;

/**
 * Parada en operacion (modulo de Operaciones, fase 2).
 *
 * Se evalua cada minuto con alerts:check (scopeCheckByTime), como
 * stop_duration. Solo dispara para vehiculos con un tramo abierto en una
 * operacion, detenidos mas de `operation_stop_seconds` (segundos exactos, sin
 * redondeo a minutos) fuera de las geocercas autorizadas de la operacion. El
 * evento lleva la operacion, el tramo y el numero de vuelta.
 *
 * Una lectura indexada por device (operation_device_state es unique por
 * device_id); no toca la ruta de ingesta.
 */
class OperationStopAlertCheck extends AlertCheck
{
    /** @var OperationDeviceState|null */
    protected $state;

    public function checkEvents($position, $prevPosition)
    {
        if (! $this->check())
            return null;

        $leg = $this->state->openLeg;
        $cycle = $this->state->openCycle;
        $operation = $this->state->operation;

        $event = $this->getEvent();

        $event->type = Event::TYPE_OPERATION_STOP;
        $event->message = '';
        $event->setAdditional('stop_seconds', (int) $this->device->getStopDuration());
        $event->setAdditional('operation', $operation ? $operation->name : null);
        $event->setAdditional('direction', $leg ? $leg->direction : null);
        $event->setAdditional('cycle_number', $cycle ? (int) $cycle->number : null);
        $event->setAdditional('moved_at', $this->device->moved_at);

        $this->silent($event);

        return [$event];
    }

    public function check()
    {
        $threshold = (int) $this->alert->operation_stop_seconds;

        if ($threshold < 1)
            return false;

        $this->state = OperationDeviceState::where('device_id', $this->device->id)
            ->whereNotNull('open_leg_id')
            ->with(['openLeg', 'openCycle', 'operation.user'])
            ->first();

        if (! $this->state || ! $this->state->operation || ! $this->state->operation->active)
            return false;

        $stopDuration = $this->device->getStopDuration();

        if (is_null($stopDuration) || $stopDuration < $threshold)
            return false;

        $moved_at = $this->device->traccar->moved_at;

        if (! $moved_at)
            return false;

        if (! $this->checkOccurred($moved_at))
            return false;

        $position = $this->getPosition();

        if (! $position)
            return false;

        if (! $this->checkAlertPosition($position))
            return false;

        // Dentro de grifo, taller o descanso (segun la operacion) la parada es autorizada.
        $authorized = Geofence::userOwned($this->state->operation->user)
            ->category($this->state->operation->authorizedCategories())
            ->get();

        foreach ($authorized as $geofence) {
            if ($geofence->pointIn($position))
                return false;
        }

        return true;
    }
}
