<?php

namespace Tobuli\Services;

use Tobuli\Entities\Device;

/**
 * Fuente ÚNICA del kilometraje de un vehículo en un rango.
 * ---------------------------------------------------------------------
 * Antes cada pantalla lo calculaba a su manera y no cuadraban entre sí.
 * Medido con un día sintético de 20 posiciones y odómetro 100.000 →
 * 119.000 m (19 km reales):
 *
 *   · Device::getDistanceBetween()  → 19.0   ✔ exacto
 *   · History `distance_sum` (GPS)  → 18.99  ✔ (haversine, ~0,05 %)
 *   · History odómetro por tramo    → 18.0   ✘ pierde el primer kilómetro,
 *     porque suma solo los tramos de conducción y el punto inicial cae en
 *     el tramo de parada previo.
 *
 * Se toma como canónico el criterio del cuentakilómetros del vehículo
 * (última lectura − primera lectura del rango), que es el que coincide con
 * el dato real y el que el cliente contrasta contra su flota. Si el equipo
 * no reporta odómetro, `getDistanceBetween()` ya cae a `totaldistance`.
 */
class DeviceMileageService
{
    /**
     * Kilómetros recorridos en el rango, en la unidad del sistema.
     *
     * @param string $from 'Y-m-d' o 'Y-m-d H:i:s'
     * @param string $to   'Y-m-d H:i:s'
     */
    public function forRange(Device $device, string $from, string $to): float
    {
        try {
            return (float) $device->getDistanceBetween($from, $to);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** Igual que forRange() pero ya formateado con su unidad. */
    public function formatted(Device $device, string $from, string $to, int $decimals = 1): string
    {
        return round($this->forRange($device, $from, $to), $decimals)
            . ' ' . \Formatter::distance()->unit();
    }
}
