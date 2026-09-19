<?php

namespace Tobuli\History\Actions;

class AppendFuelConsumptionSensors extends ActionAppend
{
    protected $sensors;

    static public function required()
    {
        return [];
    }

    public function boot()
    {
        $device = $this->getDevice();

        $this->sensors = $device->sensors
            ->filter(function($sensor) {
                return in_array($sensor->type, ['fuel_consumption']);
            });
    }

    public function proccess(& $position)
    {
        if (empty($position->consumptions))
            $position->consumptions = [];

        foreach ($this->sensors as $sensor) {
            switch ($sensor->shown_value_by) {
                case 'separate':
                    $value = $this->getConsumptionSeparateValue($sensor, $position);
                    break;
                default:
                    $value = $this->getConsumptionIncrementalValue($sensor, $position);
            }

            $position->consumptions[$sensor->id] = $value;
        }
    }

    protected function getConsumptionSeparateValue($sensor, & $position)
    {
        $value = $this->getSensorValue($sensor, $position);

        if (empty($value))
            return 0;

        return $value;
    }

    protected function getConsumptionIncrementalValue($sensor, & $position)
    {
        $prevPosition = $this->getPrevPosition();

        if ( ! $prevPosition)
            return null;

        $value     = $this->getSensorValue($sensor, $position);
        $prevValue = $this->getSensorValue($sensor, $prevPosition);

        if (empty($value) || empty($prevValue))
            return 0;

        // Un contador acumulativo (fuelused y compania) no puede bajar: si lo
        // hace es que se reinicio -- cambio de equipo, actualizacion de
        // firmware, reconfiguracion o desbordamiento del contador.
        //
        // Sin este corte entraba la resta en negativo, y como StatConsumption
        // solo recorta el TOTAL a cero, un reinicio de 9.990 L se comia el
        // consumo del periodo entero y el reporte salia en 0.
        //
        // Se devuelve 0 y no $value a proposito: tras el reinicio los saltos
        // siguientes ya se miden contra la nueva base y son correctos, asi que
        // lo unico que se pierde es el tramo justo del reinicio. Contar $value
        // habria sido mas exacto con un reinicio de verdad, pero convertiria
        // cualquier lectura baja pasajera en consumo inventado.
        if ($value < $prevValue)
            return 0;

        return $value - $prevValue;
    }
}