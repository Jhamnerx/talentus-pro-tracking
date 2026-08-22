<?php

namespace Tobuli\Helpers\Alerts\Check;


use Tobuli\Entities\Event;
use Tobuli\Services\ConditionService;

class SosAlertCheck extends AlertCheck
{
    public function checkEvents($position, $prevPosition)
    {
        if ( ! $this->check($position))
            return null;

        $event = $this->getEvent();

        $event->type = Event::TYPE_SOS;
        $event->message = 'SOS';

        $this->silent($event);

        return [$event];
    }

    protected function check($position)
    {
        if ( ! $position)
            return false;

        if ( ! $this->checkAlertPosition($position))
            return false;

        // Traccar acumula las alarmas separando por comas
        // (Position::addAlarm), asi que con una igualdad un "sos,powerCut" se
        // quedaba sin avisar. Se compara elemento a elemento.
        if ( ! ConditionService::check(
            ConditionService::TYPE_CONTAINS,
            $position->getParameter('alarm'),
            'sos'
        ))
            return false;

        return true;
    }
}