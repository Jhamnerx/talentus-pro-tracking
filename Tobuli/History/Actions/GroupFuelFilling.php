<?php

namespace Tobuli\History\Actions;

use Formatter;
use Tobuli\Helpers\Formatter\Unit\Capacity;
use Tobuli\History\Group;
use Tobuli\History\Stats\StatValue;

class GroupFuelFilling extends ActionGroup
{
    static public function required()
    {
        return [
            AppendFuelFilling::class
        ];
    }

    public function boot() {}

    public function proccess($position)
    {
        if (empty($position->fuel_filling))
            return;

        $group = new Group('fuel_filling');
        $group->sensor_name = $position->fuel_filling['name'];
        // La recarga se marca en una sola trama (el pico); aqui va la hora en
        // que empezo a subir el nivel, para quien necesite la duracion.
        $group->fuel_started_at = $position->fuel_filling['start_time'] ?? null;

        $sensorUnit = $position->fuel_filling['unit'];
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
            $stat->apply($position->fuel_filling[$key]);

            $group->stats()->set($value, $stat);
        }

        $this->history->groupStart($group, $position);
        $this->history->groupEnd("fuel_filling", $position);
    }
}
