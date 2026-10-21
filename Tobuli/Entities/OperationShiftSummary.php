<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resumen de un turno-fecha para un vehiculo de una operacion (reporte de
 * turno). Lo calcula OperationShiftSummaryService; nadie mas lo escribe.
 *
 * - Fechas-hora en UTC salvo `shift_date` (fecha local en que empezo el turno).
 * - Horometro y odometro: valor crudo del sensor; NULL sin sensor o sin tramas.
 * - Tiempos en segundos, acotados al rango del turno.
 */
class OperationShiftSummary extends AbstractEntity
{
    const BREAK_SCHEDULED = 'scheduled';
    const BREAK_NONE = 'none';

    protected $table = 'operation_shift_summaries';

    // Solo calculated_at: no hay created_at / updated_at en la tabla.
    public $timestamps = false;

    protected $fillable = [
        'operation_id',
        'device_id',
        'shift_id',
        'shift_name',
        'shift_date',
        'first_move_at',
        'last_move_at',
        'break_start_at',
        'break_end_at',
        'break_source',
        'engine_hours_start',
        'engine_hours_before_break',
        'engine_hours_after_break',
        'engine_hours_end',
        'odometer_start',
        'odometer_end',
        'moving_seconds',
        'idle_seconds',
        'stop_engine_off_seconds',
        'break_seconds',
        'gap_seconds',
        'workshop_seconds',
        'rest_seconds',
        'maint_seconds',
        'load_seconds',
        'unload_seconds',
        'cycles_count',
        'distance_meters',
        'loaded_distance_m',
        'loaded_moving_s',
        'empty_distance_m',
        'empty_moving_s',
        'calculated_at',
    ];

    protected $casts = [
        'engine_hours_start'        => 'float',
        'engine_hours_before_break' => 'float',
        'engine_hours_after_break'  => 'float',
        'engine_hours_end'          => 'float',
        'odometer_start'            => 'float',
        'odometer_end'              => 'float',
        'moving_seconds'            => 'int',
        'idle_seconds'              => 'int',
        'stop_engine_off_seconds'   => 'int',
        'break_seconds'             => 'int',
        'gap_seconds'               => 'int',
        'workshop_seconds'          => 'int',
        'rest_seconds'              => 'int',
        'maint_seconds'             => 'int',
        'load_seconds'              => 'int',
        'unload_seconds'            => 'int',
        'cycles_count'              => 'int',
        'distance_meters'           => 'int',
        'loaded_distance_m'         => 'int',
        'loaded_moving_s'           => 'int',
        'empty_distance_m'          => 'int',
        'empty_moving_s'            => 'int',
    ];

    protected $dates = [
        'first_move_at',
        'last_move_at',
        'break_start_at',
        'break_end_at',
        'calculated_at',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(OperationShift::class, 'shift_id');
    }

    public function refuelings(): HasMany
    {
        return $this->hasMany(OperationRefueling::class, 'summary_id')->orderBy('started_at');
    }

    /** VAR del horometro: final - inicial (NULL si falta alguno). */
    public function engineHoursDiff(): ?float
    {
        if (is_null($this->engine_hours_start) || is_null($this->engine_hours_end)) {
            return null;
        }

        return round($this->engine_hours_end - $this->engine_hours_start, 2);
    }

    /** VAR del odometro: final - inicial (NULL si falta alguno). */
    public function odometerDiff(): ?float
    {
        if (is_null($this->odometer_start) || is_null($this->odometer_end)) {
            return null;
        }

        return round($this->odometer_end - $this->odometer_start, 3);
    }
}
