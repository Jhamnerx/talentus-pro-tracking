<?php

namespace Tobuli\Helpers\Alerts\Check;


use App\Jobs\ConfirmUnpluggedAlert;
use Tobuli\Entities\DeviceSensor;
use Tobuli\Entities\Event;
use Tobuli\Services\ConditionService;

/**
 * Desenchufado: el equipo perdió la alimentación externa.
 *
 * Dos fuentes, en este orden:
 *  1. Sensor `plugged` del equipo (si lo tiene): se avisa en la transición
 *     enchufado → desenchufado. Es lo que había; sirve para cualquier lógica
 *     a medida (voltaje de `power`, una entrada digital…).
 *  2. Sin sensor: la alarma normalizada de Traccar `powerCut`. La emiten los
 *     decoders de casi todos los protocolos en uso (Teltonika io252, GT06,
 *     H02, Meitrack, Queclink, Suntech, TK103, GPS103, Watch, JT600…), así
 *     que la alerta funciona sin configurar nada en el equipo. Se avisa
 *     cuando aparece (la posición anterior no la traía), para no repetir
 *     mientras el equipo la siga reportando.
 */
class UnpluggedAlertCheck extends AlertCheck
{
    public const ALARM_CUT = 'powerCut';

    /** Alarmas Traccar que indican que la alimentación volvió. */
    public const ALARMS_RESTORED = ['powerRestored', 'powerOn'];

    protected $sensor = null;

    public function checkEvents($position, $prevPosition)
    {
        $this->sensor = $this->getSensor();

        $fired = $this->sensor
            ? $this->firedBySensor($position, $prevPosition)
            : $this->firedByAlarm($position, $prevPosition);

        if (!$fired)
            return null;

        $event = $this->getEvent();
        $event->type = Event::TYPE_UNPLUGGED;
        $event->message = '';

        if (!$this->hasContinuousDuration())
            return [$event];

        $delay = $this->getContinuousDuration();

        dispatch(
            (new ConfirmUnpluggedAlert($event->toArray(), $delay))->delay($delay)
        );

        return [];
    }

    protected function firedBySensor($position, $prevPosition): bool
    {
        $currentStatus = $this->check($position);

        if ($currentStatus)
            return false;

        if (is_null($currentStatus))
            return false;

        $previousStatus = $this->check($prevPosition);

        if (is_null($previousStatus))
            return false;

        return $currentStatus !== $previousStatus;
    }

    protected function firedByAlarm($position, $prevPosition): bool
    {
        if ( ! $position || ! $this->checkAlertPosition($position))
            return false;

        if ( ! self::isPowerCut($position))
            return false;

        return ! ($prevPosition && self::isPowerCut($prevPosition));
    }

    /**
     * La posición trae la alarma de corte de alimentación.
     */
    public static function isPowerCut($position): bool
    {
        return ConditionService::check(
            ConditionService::TYPE_CONTAINS,
            $position->getParameter('alarm'),
            self::ALARM_CUT
        );
    }

    /**
     * La posición indica que la alimentación volvió: alarma powerRestored /
     * powerOn, o (Teltonika) el evento 252 sin powerCut — el decoder no añade
     * alarma cuando io252 vuelve a 0.
     */
    public static function isPowerRestored($position): bool
    {
        $alarm = $position->getParameter('alarm');

        foreach (self::ALARMS_RESTORED as $restored) {
            if (ConditionService::check(ConditionService::TYPE_CONTAINS, $alarm, $restored))
                return true;
        }

        return (int) $position->getParameter('event') === 252 && ! self::isPowerCut($position);
    }

    /**
     * @param $position
     * @return bool|null
     */
    protected function check($position)
    {
        if ( ! $position)
            return null;

        if ( ! $this->checkAlertPosition($position))
            return null;

        $value = $this->sensor->getValuePosition($position);

        if (is_null($value))
            return null;

        return $value;
    }

    /**
     * @return DeviceSensor|null
     */
    protected function getSensor()
    {
        return $this->device->getSensorByType('plugged');
    }

    /**
     * @return bool
     */
    protected function hasContinuousDuration()
    {
        return $this->alert->continuous_duration > 0 ? true : false;
    }

    /**
     * @return int
     */
    protected function getContinuousDuration()
    {
        return $this->alert->continuous_duration;
    }
}
