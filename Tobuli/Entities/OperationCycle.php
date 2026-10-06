<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una vuelta: salida de carga, llegada a descarga, salida de descarga y
 * llegada a carga. Se cuenta como completa al regresar al punto de carga.
 */
class OperationCycle extends AbstractEntity
{
    const STATUS_OPEN = 'open';
    const STATUS_COMPLETED = 'completed';
    const STATUS_INCOMPLETE = 'incomplete';

    protected $table = 'operation_cycles';

    protected $fillable = [
        'operation_id',
        'device_id',
        'work_date',
        'shift_id',
        'shift_name',
        'shift_date',
        'number',
        'started_at',
        'ended_at',
        'status',
        'load_seconds',
        'unload_seconds',
        'duration_seconds',
    ];

    protected $casts = [
        'number'           => 'int',
        'load_seconds'     => 'int',
        'unload_seconds'   => 'int',
        'duration_seconds' => 'int',
    ];

    protected $dates = [
        'started_at',
        'ended_at',
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

    public function legs(): HasMany
    {
        return $this->hasMany(OperationLeg::class, 'cycle_id')->orderBy('started_at');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
