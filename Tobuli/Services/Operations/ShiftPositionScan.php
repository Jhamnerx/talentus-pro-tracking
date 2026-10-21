<?php

namespace Tobuli\Services\Operations;

use Tobuli\History\Actions\ActionAppend;
use Tobuli\History\Actions\AppendDistanceGPS;
use Tobuli\History\Actions\AppendDuration;
use Tobuli\History\Actions\AppendEngineStatus;
use Tobuli\History\Actions\AppendSpeed;

/**
 * Accion del pipeline de historial que, en UNA pasada por las tramas del
 * turno, acumula en ShiftScanContext todo lo que el resumen de turno saca de
 * las posiciones: primera/ultima trama en movimiento, horometro y odometro
 * en los cuatro instantes, tiempos de estado, distancias por sentido y las
 * estadisticas por hora local.
 *
 * Reglas:
 * - "En movimiento" = velocidad de la trama >= minSpeed (lo que pide el
 *   cliente: hora inicial/final = cuando se mueve). No se usa el suavizado de
 *   AppendMoveState para no arrastrar paradas cortas como movimiento.
 * - El intervalo entre la trama anterior y la actual (duration) se clasifica
 *   por el estado de la trama actual, como hacen EngineOnOff y EngineHours:
 *     sin datos (gap)        -> no cuenta en ningun estado;
 *     en movimiento          -> moving (READY);
 *     motor encendido parado -> idle (DELAY, ralenti);
 *     motor apagado parado   -> stop_engine_off (STANDBY).
 *   Un intervalo mayor que gapSeconds es "sin datos" solo si el vehiculo se
 *   desplazo (mas de STATIONARY_METERS entre las dos tramas o alguna de las
 *   dos en movimiento) o si supera STATIONARY_GAP_SECONDS. Un equipo parado
 *   con motor apagado reporta cada 2-5 minutos sin moverse: eso es STANDBY,
 *   no una falla de datos (OperationLegCalculator usa el criterio simple
 *   porque dentro de un tramo el vehiculo esta en marcha).
 *   El motor sale de AppendEngineStatus: con sensor de ignicion es el sensor,
 *   sin sensor lo deduce del GPS (parado => apagado => STANDBY).
 * - Un intervalo que cruza un cambio de hora se reparte proporcionalmente
 *   entre las horas; la velocidad maxima/minima se asigna a la hora del
 *   instante de la trama.
 * - Cada trama pertenece al tramo abierto en ese instante: outbound = cargado,
 *   inbound = vacio; fuera de tramo no suma a ningun sentido.
 */
class ShiftPositionScan extends ActionAppend
{
    /** Desplazamiento maximo (m) para considerar que el vehiculo no se movio. */
    const STATIONARY_METERS = 100;

    /** Intervalo maximo (s) de un vehiculo parado antes de contarlo como sin datos. */
    const STATIONARY_GAP_SECONDS = 3600;

    /** @var ShiftScanContext */
    protected $ctx;

    protected int $legIndex = 0;

    public static function required()
    {
        return [
            AppendDuration::class,
            AppendSpeed::class,
            AppendDistanceGPS::class,
            AppendEngineStatus::class,
        ];
    }

    public function boot()
    {
        $this->ctx = $this->history->config('shift_scan');
        $this->legIndex = 0;
    }

    public function proccess(&$position)
    {
        $ctx = $this->ctx;

        if (! $ctx instanceof ShiftScanContext) {
            return;
        }

        $ts = (int) $position->timestamp;

        if ($ts < $ctx->rangeStartTs || $ts >= $ctx->rangeEndTs) {
            return;
        }

        $ctx->positions++;

        $speed = (float) ($position->speed ?? 0);
        $moving = $speed >= $ctx->minSpeed;

        $this->readSensors($position, $ts);

        if ($moving) {
            if (is_null($ctx->firstMoveAt)) {
                $ctx->firstMoveAt = $position->time;
            }
            $ctx->lastMoveAt = $position->time;
        }

        $duration = (int) ($position->duration ?? 0);

        if ($duration <= 0 || $ts - $duration < $ctx->rangeStartTs) {
            // Primera trama del rango: no hay intervalo anterior dentro del turno.
            // Aun asi, la velocidad del instante cuenta para max/min de su hora.
            if ($moving) {
                $this->applySpeedPoint($speed, $ts, $position->time);
            }
            return;
        }

        $distance = (float) ($position->distance_gps ?? 0) * 1000; // getDistance() da km
        $ctx->distanceMeters += $distance;

        if ($duration > $ctx->gapSeconds && ! $this->isStationary($moving, $distance, $duration)) {
            $ctx->gapSecondsTotal += $duration;
            return;
        }

        $engineOn = ! empty($position->engine);

        if ($moving) {
            $ctx->movingSeconds += $duration;
        } elseif ($engineOn) {
            $ctx->idleSeconds += $duration;
        } else {
            $ctx->stopEngineOffSeconds += $duration;
        }

        $direction = $this->directionAt($ts);

        if ($direction === 'outbound') {
            $ctx->loadedDistanceM += $distance;
            if ($moving) {
                $ctx->loadedMovingS += $duration;
            }
        } elseif ($direction === 'inbound') {
            $ctx->emptyDistanceM += $distance;
            if ($moving) {
                $ctx->emptyMovingS += $duration;
            }
        }

        foreach ($this->splitByHour($ts - $duration, $ts) as [$hourKey, $seconds]) {
            $fraction = $seconds / $duration;
            $hour = $ctx->hour($hourKey);

            if ($moving) {
                $hour['moving'] += $seconds;
                $hour['speed_time'] += $speed * $seconds;
            }

            if ($direction === 'outbound') {
                $hour['loaded_d'] += $distance * $fraction;
                if ($moving) {
                    $hour['loaded_s'] += $seconds;
                }
            } elseif ($direction === 'inbound') {
                $hour['empty_d'] += $distance * $fraction;
                if ($moving) {
                    $hour['empty_s'] += $seconds;
                }
            }

            $ctx->hours[$hourKey] = $hour;
        }

        if ($moving) {
            $this->applySpeedPoint($speed, $ts, $position->time);
        }
    }

    /** Intervalo largo de un vehiculo que no se movio: no es "sin datos". */
    protected function isStationary(bool $moving, float $distanceMeters, int $duration): bool
    {
        if ($moving || $duration > self::STATIONARY_GAP_SECONDS) {
            return false;
        }

        if ($distanceMeters > self::STATIONARY_METERS) {
            return false;
        }

        $previous = $this->getPrevPosition();

        if ($previous && (float) ($previous->speed ?? 0) >= $this->ctx->minSpeed) {
            return false;
        }

        return true;
    }

    /** Horometro y odometro en la trama, segun la posicion del rango. */
    protected function readSensors($position, int $ts): void
    {
        $ctx = $this->ctx;

        if ($ctx->engineHoursSensor) {
            $value = $this->sensorValue($ctx->engineHoursSensor, $position);

            if (! is_null($value)) {
                if (is_null($ctx->engineHoursStart)) {
                    $ctx->engineHoursStart = $value;
                }

                if ($ctx->breakStartTs && $ts < $ctx->breakStartTs) {
                    $ctx->engineHoursBeforeBreak = $value;
                }

                if ($ctx->breakEndTs && $ts >= $ctx->breakEndTs && is_null($ctx->engineHoursAfterBreak)) {
                    $ctx->engineHoursAfterBreak = $value;
                }

                $ctx->engineHoursEnd = $value;
            }
        }

        if ($ctx->odometerSensor) {
            $value = $this->sensorValue($ctx->odometerSensor, $position);

            if (! is_null($value)) {
                if (is_null($ctx->odometerStart)) {
                    $ctx->odometerStart = $value;
                }

                $ctx->odometerEnd = $value;
            }
        }
    }

    /**
     * Valor numerico del sensor en la trama. El odometro virtual no viene en
     * `other` (getDataValue devolveria el acumulado actual del sensor), asi
     * que se lee del valor guardado por trama (sensors_values).
     */
    protected function sensorValue($sensor, $position): ?float
    {
        // getSensor() es protegido en DeviceSensor; el acceso publico al valor
        // guardado por trama es getValueStored() (fallaba en el servidor con
        // «Call to undefined method DeviceSensor::getSensor()»).
        if ($sensor->type === 'odometer' && $sensor->shown_value_by === 'virtual_odometer') {
            $value = $sensor->getValueStored($position);
        } else {
            $value = $this->getSensorValue($sensor, $position);
        }

        if (is_null($value) || $value === false || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** Sentido del tramo abierto en el instante, o null si no esta en tramo. */
    protected function directionAt(int $ts): ?string
    {
        $legs = $this->ctx->legs;
        $count = count($legs);

        while ($this->legIndex < $count) {
            $leg = $legs[$this->legIndex];

            if (! is_null($leg['end']) && $leg['end'] <= $ts) {
                $this->legIndex++;
                continue;
            }

            break;
        }

        if ($this->legIndex >= $count) {
            return null;
        }

        $leg = $legs[$this->legIndex];

        if ($leg['start'] > $ts) {
            return null;
        }

        return $leg['outbound'] ? 'outbound' : 'inbound';
    }

    /**
     * Reparte el intervalo [from, to] (UTC) entre horas locales.
     *
     * @return array<array{0:string,1:int}> [clave de hora local, segundos]
     */
    protected function splitByHour(int $from, int $to): array
    {
        $offset = $this->ctx->tzOffset;
        $pieces = [];

        $cursor = $from;

        while ($cursor < $to) {
            $local = $cursor + $offset;
            $hourStartLocal = $local - ($local % 3600);
            $nextBoundary = $hourStartLocal + 3600 - $offset;
            $end = min($to, $nextBoundary);

            $pieces[] = [gmdate('Y-m-d H:00:00', $hourStartLocal), $end - $cursor];

            $cursor = $end;
        }

        return $pieces;
    }

    protected function applySpeedPoint(float $speed, int $ts, string $time): void
    {
        $local = $ts + $this->ctx->tzOffset;
        $key = gmdate('Y-m-d H:00:00', $local - ($local % 3600));
        $hour = $this->ctx->hour($key);

        if (is_null($hour['max_speed']) || $speed > $hour['max_speed']) {
            $hour['max_speed'] = $speed;
            $hour['max_speed_at'] = $time;
        }

        if (is_null($hour['min_speed']) || $speed < $hour['min_speed']) {
            $hour['min_speed'] = $speed;
            $hour['min_speed_at'] = $time;
        }

        $this->ctx->hours[$key] = $hour;
    }
}
