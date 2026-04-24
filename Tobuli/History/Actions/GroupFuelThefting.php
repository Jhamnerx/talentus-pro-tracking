<?php

namespace Tobuli\History\Actions;

use Formatter;
use Tobuli\Helpers\Formatter\Unit\Capacity;
use Tobuli\History\Group;
use Tobuli\History\Stats\StatValue;

class GroupFuelThefting extends ActionGroup
{
    static public function required()
    {
        return [
            AppendFuelTheft::class
        ];
    }

    public function boot() {}

    public function proccess($position)
    {
        if (empty($position->fuel_theft))
            return;

        $group = new Group('fuel_theft');
        $group->sensor_name = $position->fuel_theft['name'];

        $sensorUnit = $position->fuel_theft['unit'];
        $fuelFormatter = clone Formatter::capacity();
        $firstChar = utf8_strtolower(utf8_substr($sensorUnit, 0, 1));
        if (in_array($firstChar, ['l', 'g'])) {
            $sensorCapacity = (new Capacity())->setMeasure($sensorUnit);
            $fuelFormatter->setRatio($fuelFormatter->getRatio() / $sensorCapacity->getRatio());
        } else {
            $fuelFormatter->setRatio(1)->setUnit($sensorUnit);
        }

        $map = [
            'previous' => 'fuel_level_previous',
            'current'  => 'fuel_level_current',
            'diff'     => 'fuel_level_difference',
        ];

        foreach ($map as $key => $value) {
            $stat = (new StatValue())->setFormatUnit($fuelFormatter);
            $stat->apply($position->fuel_theft[$key]);

            $group->stats()->set($value, $stat);
        }

        $this->history->groupStart($group, $position);
        $this->history->groupEnd("fuel_theft", $position);
    }
}
