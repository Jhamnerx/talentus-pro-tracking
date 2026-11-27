<?php

namespace Tobuli\Services\Operations;

use Tobuli\Entities\DeviceSensor;

/**
 * Entradas y acumuladores de ShiftPositionScan. OperationShiftSummaryService
 * lo rellena, lo pasa al pipeline por config('shift_scan') y lee los
 * resultados al terminar. Todo en segundos, metros y km/h (unidad base).
 */
class ShiftScanContext
{
    // ── Entradas ──────────────────────────────────────────────────────────

    /** Velocidad minima de movimiento (km/h). */
    public float $minSpeed = 6;

    /** Hueco entre tramas (s) a partir del cual el tiempo es "sin datos". */
    public int $gapSeconds = 90;

    /** Desplazamiento local - UTC del dueño, en segundos. */
    public int $tzOffset = 0;

    public int $rangeStartTs = 0;
    public int $rangeEndTs = 0;

    /** Descanso principal en UTC (timestamps) o null. */
    public ?int $breakStartTs = null;
    public ?int $breakEndTs = null;

    /**
     * Tramos del vehiculo que cruzan el rango, ordenados por inicio:
     * [['start' => ts, 'end' => ts|null, 'outbound' => bool], ...]
     */
    public array $legs = [];

    public ?DeviceSensor $engineHoursSensor = null;
    public ?DeviceSensor $odometerSensor = null;

    // ── Resultados ────────────────────────────────────────────────────────

    public ?string $firstMoveAt = null;
    public ?string $lastMoveAt = null;

    public ?float $engineHoursStart = null;
    public ?float $engineHoursBeforeBreak = null;
    public ?float $engineHoursAfterBreak = null;
    public ?float $engineHoursEnd = null;

    public ?float $odometerStart = null;
    public ?float $odometerEnd = null;

    public float $movingSeconds = 0;
    public float $idleSeconds = 0;
    public float $stopEngineOffSeconds = 0;
    public float $gapSecondsTotal = 0;

    public float $distanceMeters = 0;
    public float $loadedDistanceM = 0;
    public float $loadedMovingS = 0;
    public float $emptyDistanceM = 0;
    public float $emptyMovingS = 0;

    /**
     * Acumuladores por hora local ('Y-m-d H:00:00' => [...]).
     * speed_time = suma(velocidad x segundos en movimiento) para la media ponderada.
     */
    public array $hours = [];

    public int $positions = 0;

    public function hour(string $key): array
    {
        if (! isset($this->hours[$key])) {
            $this->hours[$key] = [
                'moving'       => 0.0,
                'loaded_d'     => 0.0,
                'loaded_s'     => 0.0,
                'empty_d'      => 0.0,
                'empty_s'      => 0.0,
                'speed_time'   => 0.0,
                'max_speed'    => null,
                'max_speed_at' => null,
                'min_speed'    => null,
                'min_speed_at' => null,
            ];
        }

        return $this->hours[$key];
    }
}
