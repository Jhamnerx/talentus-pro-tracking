<?php

namespace Tobuli\Services\Operations;

use Tobuli\History\Actions\ActionAppend;
use Tobuli\History\Actions\AppendDistanceGPS;
use Tobuli\History\Actions\AppendDuration;
use Tobuli\History\Actions\AppendGeofences;
use Tobuli\History\Actions\AppendSpeed;

/**
 * Accion del pipeline de historial para el reporte «Salidas del area de
 * operacion»: detecta cada vez que el vehiculo sale de las geocercas de
 * categoria area_operacion (la mina) y vuelve, y anota el nivel del tanque
 * al salir y al volver, la distancia recorrida fuera y la parada mas larga
 * fuera (donde suele estar el destino: Cajamarca, un grifo, etc.).
 *
 * Reglas:
 * - Dentro = la trama cae en alguna de las geocercas del area (varias
 *   geocercas cuentan como una sola zona).
 * - Nivel al salir = mediana de las ultimas FUEL_SAMPLES lecturas dentro;
 *   al volver = mediana de las primeras FUEL_SAMPLES lecturas ya dentro
 *   (el nivel oscila en marcha; la mediana quita picos sueltos).
 * - Salidas mas cortas que minOutsideSeconds no cuentan (rebote del GPS en
 *   el borde de la geocerca).
 * - Una salida sin regreso dentro del rango queda abierta (sin nivel de
 *   regreso). Si el rango empieza con el vehiculo fuera, esa vuelta no se
 *   cuenta porque no se vio la salida.
 *
 * Los resultados quedan en el array `trips` del contexto (config
 * 'area_scan'); los abastecimientos fuera los cruza el reporte con los
 * grupos de GroupFuelFilling.
 */
class OperationAreaExitScan extends ActionAppend
{
    const FUEL_SAMPLES = 5;

    /** @var object contexto compartido con el reporte */
    protected $ctx;

    protected ?bool $inside = null;

    /** Ultimas lecturas de tanque dentro del area (para la de salida). */
    protected array $insideFuel = [];

    /** Vuelta en curso (fuera) o recien vuelta esperando lecturas de regreso. */
    protected ?array $trip = null;

    protected ?array $returning = null;

    /** Parada en curso fuera del area. */
    protected ?array $stop = null;

    public static function required()
    {
        return [
            AppendDuration::class,
            AppendSpeed::class,
            AppendDistanceGPS::class,
            AppendGeofences::class,
        ];
    }

    public function boot()
    {
        $this->ctx = $this->history->config('area_scan');
    }

    public function proccess(&$position)
    {
        $ctx = $this->ctx;

        if (! $ctx) {
            return;
        }

        $inside = (bool) array_intersect((array) ($position->geofences ?? []), $ctx->areaIds);
        $fuel = $this->fuel($position);
        $ts = (int) $position->timestamp;

        // Lecturas de regreso: las primeras ya dentro tras volver.
        if ($this->returning && $inside) {
            if (! is_null($fuel)) {
                $this->returning['samples'][] = $fuel;
            }

            if (count($this->returning['samples']) >= self::FUEL_SAMPLES) {
                $this->closeReturn();
            }
        }

        if (is_null($this->inside)) {
            $this->inside = $inside;
        }

        if ($inside) {
            if ($this->trip) {
                $this->back($position);
            }

            if (! is_null($fuel)) {
                $this->insideFuel[] = $fuel;
                if (count($this->insideFuel) > self::FUEL_SAMPLES) {
                    array_shift($this->insideFuel);
                }
            }
        } else {
            if ($this->inside) {
                $this->leave($position);
            }

            if ($this->trip) {
                $this->trip['distance_km'] += (float) ($position->distance_gps ?? 0);
                $this->trackStop($position, $ts);
                if (! is_null($fuel)) {
                    $this->trip['min_fuel'] = is_null($this->trip['min_fuel']) ? $fuel : min($this->trip['min_fuel'], $fuel);
                }
            }
        }

        $this->inside = $inside;
    }

    /** Al terminar el rango (lo llama DeviceHistory): cierra lo que quede abierto. */
    public function lastProcess($position)
    {
        if (! $this->ctx) {
            return;
        }

        if ($this->returning) {
            $this->closeReturn();
        }

        if ($this->trip) {
            $this->closeStop();
            $this->trip['open'] = true;
            $this->ctx->trips[] = $this->trip;
            $this->trip = null;
        }
    }

    protected function leave($position): void
    {
        // Si aun esperaba lecturas de la vuelta anterior, se cierra con las que haya.
        if ($this->returning) {
            $this->closeReturn();
        }

        $this->trip = [
            'exit_at'      => $position->time,
            'exit_ts'      => (int) $position->timestamp,
            'exit_pos'     => $position,
            'fuel_exit'    => $this->median($this->insideFuel),
            'return_at'    => null,
            'return_ts'    => null,
            'return_pos'   => null,
            'fuel_return'  => null,
            'min_fuel'     => null,
            'distance_km'  => 0.0,
            'longest_stop' => null,
            'open'         => false,
        ];
        $this->stop = null;
    }

    protected function back($position): void
    {
        $this->closeStop();

        $trip = $this->trip;
        $this->trip = null;

        $trip['return_at'] = $position->time;
        $trip['return_ts'] = (int) $position->timestamp;
        $trip['return_pos'] = $position;

        // Rebote en el borde: no es una salida.
        if ($trip['return_ts'] - $trip['exit_ts'] < $this->ctx->minOutsideSeconds) {
            return;
        }

        $fuel = $this->fuel($position);
        $this->returning = ['trip' => $trip, 'samples' => is_null($fuel) ? [] : [$fuel]];
        $this->insideFuel = [];
    }

    protected function closeReturn(): void
    {
        $trip = $this->returning['trip'];
        $trip['fuel_return'] = $this->median($this->returning['samples']);
        $this->ctx->trips[] = $trip;
        $this->returning = null;
    }

    /** Parada = tramas seguidas por debajo de la velocidad minima. */
    protected function trackStop($position, int $ts): void
    {
        $stopped = (float) ($position->speed ?? 0) < $this->ctx->minSpeed;

        if ($stopped) {
            if (! $this->stop) {
                $this->stop = ['start_ts' => $ts, 'end_ts' => $ts, 'pos' => $position, 'at' => $position->time];
            } else {
                $this->stop['end_ts'] = $ts;
            }
            return;
        }

        $this->closeStop();
    }

    protected function closeStop(): void
    {
        if (! $this->stop || ! $this->trip) {
            $this->stop = null;
            return;
        }

        $seconds = $this->stop['end_ts'] - $this->stop['start_ts'];

        if ($seconds > 0 && (! $this->trip['longest_stop'] || $seconds > $this->trip['longest_stop']['seconds'])) {
            $this->trip['longest_stop'] = ['seconds' => $seconds, 'pos' => $this->stop['pos'], 'at' => $this->stop['at']];
        }

        $this->stop = null;
    }

    protected function fuel($position): ?float
    {
        $sensor = $this->ctx->fuelSensor;

        if (! $sensor) {
            return null;
        }

        $value = $this->getSensorValue($sensor, $position);

        if (is_null($value) || $value === false || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $this->ctx->fuelFull ? $value / $this->ctx->fuelFull * 100 : $value;
    }

    protected function median(array $values): ?float
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 2);
    }
}
