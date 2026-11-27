<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tramo de una vuelta: ida (carga -> descarga) o vuelta (descarga -> carga).
 * Las columnas de calculo (paradas, ralenti, distancia...) las llena
 * OperationLegCalculator una sola vez, al cerrarse el tramo.
 */
class OperationLeg extends AbstractEntity
{
    const DIRECTION_OUTBOUND = 'outbound';
    const DIRECTION_INBOUND = 'inbound';

    const STATUS_OPEN = 'open';
    const STATUS_COMPLETED = 'completed';
    const STATUS_INCOMPLETE = 'incomplete';

    protected $table = 'operation_legs';

    protected $fillable = [
        'cycle_id',
        'device_id',
        'direction',
        'from_point_id',
        'to_point_id',
        'started_at',
        'ended_at',
        'status',
        'calculated_at',
        'duration_seconds',
        'distance_meters',
        'stop_seconds',
        'idle_seconds',
        'engine_off_seconds',
        'gap_seconds',
        'stops_count',
        'unauthorized_stops_count',
    ];

    protected $casts = [
        'duration_seconds'         => 'int',
        'distance_meters'          => 'int',
        'stop_seconds'             => 'int',
        'idle_seconds'             => 'int',
        'engine_off_seconds'       => 'int',
        'gap_seconds'              => 'int',
        'stops_count'              => 'int',
        'unauthorized_stops_count' => 'int',
    ];

    protected $dates = [
        'started_at',
        'ended_at',
        'calculated_at',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OperationCycle::class, 'cycle_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function fromPoint(): BelongsTo
    {
        return $this->belongsTo(OperationPoint::class, 'from_point_id');
    }

    public function toPoint(): BelongsTo
    {
        return $this->belongsTo(OperationPoint::class, 'to_point_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(OperationStop::class, 'leg_id')->orderBy('started_at');
    }

    /** Tramos cerrados que aun no pasaron por el calculo. */
    public function scopePendingCalculation(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_OPEN)
            ->whereNull('calculated_at')
            ->whereNotNull('ended_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }
}
