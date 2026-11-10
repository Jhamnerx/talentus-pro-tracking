<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estadisticas de una hora local para un vehiculo de una operacion, o para
 * toda la flota (`device_id` NULL). `hour_at` es la hora local del dueño con
 * minuto 0 (no UTC); `max_speed_at` / `min_speed_at` son UTC.
 */
class OperationHourStat extends AbstractEntity
{
    protected $table = 'operation_hour_stats';

    // Solo calculated_at: no hay created_at / updated_at en la tabla.
    public $timestamps = false;

    protected $fillable = [
        'operation_id',
        'device_id',
        'hour_at',
        'moving_seconds',
        'loaded_distance_m',
        'loaded_moving_s',
        'empty_distance_m',
        'empty_moving_s',
        'max_speed',
        'min_speed',
        'avg_speed',
        'max_speed_device_id',
        'min_speed_device_id',
        'max_speed_at',
        'min_speed_at',
        'calculated_at',
    ];

    protected $casts = [
        'moving_seconds'      => 'int',
        'loaded_distance_m'   => 'int',
        'loaded_moving_s'     => 'int',
        'empty_distance_m'    => 'int',
        'empty_moving_s'      => 'int',
        'max_speed'           => 'float',
        'min_speed'           => 'float',
        'avg_speed'           => 'float',
        'max_speed_device_id' => 'int',
        'min_speed_device_id' => 'int',
    ];

    protected $dates = [
        'hour_at',
        'max_speed_at',
        'min_speed_at',
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

    public function maxSpeedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'max_speed_device_id');
    }

    public function minSpeedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'min_speed_device_id');
    }

    public function isFleet(): bool
    {
        return is_null($this->device_id);
    }

    public function scopeFleet(Builder $query): Builder
    {
        return $query->whereNull('device_id');
    }

    public function scopeDevices(Builder $query): Builder
    {
        return $query->whereNotNull('device_id');
    }
}
