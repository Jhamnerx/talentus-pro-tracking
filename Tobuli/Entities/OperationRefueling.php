<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abastecimiento detectado en un turno (subida del nivel del tanque segun el
 * pipeline de combustible). Niveles tal cual los reporta el sensor (%).
 * `engine_off` = ocurrio dentro de una parada con motor apagado (`stop_id`).
 */
class OperationRefueling extends AbstractEntity
{
    protected $table = 'operation_refuelings';

    // Solo calculated_at: no hay created_at / updated_at en la tabla.
    public $timestamps = false;

    protected $fillable = [
        'operation_id',
        'device_id',
        'summary_id',
        'stop_id',
        'sensor_id',
        'started_at',
        'ended_at',
        'level_before_pct',
        'level_after_pct',
        'refuel_pct',
        'engine_off',
        'calculated_at',
    ];

    protected $casts = [
        'sensor_id'        => 'int',
        'level_before_pct' => 'float',
        'level_after_pct'  => 'float',
        'refuel_pct'       => 'float',
        'engine_off'       => 'bool',
    ];

    protected $dates = [
        'started_at',
        'ended_at',
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

    public function summary(): BelongsTo
    {
        return $this->belongsTo(OperationShiftSummary::class, 'summary_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(OperationStop::class, 'stop_id');
    }

    public function durationSeconds(): int
    {
        if (! $this->started_at || ! $this->ended_at) {
            return 0;
        }

        return max(0, $this->ended_at->getTimestamp() - $this->started_at->getTimestamp());
    }
}
