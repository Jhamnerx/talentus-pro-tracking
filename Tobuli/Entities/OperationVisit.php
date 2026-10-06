<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada y salida confirmadas de un vehiculo en un punto de la operacion. */
class OperationVisit extends AbstractEntity
{
    protected $table = 'operation_visits';

    protected $fillable = [
        'operation_id',
        'operation_point_id',
        'device_id',
        'role',
        'entered_at',
        'exited_at',
        'passing',
    ];

    protected $casts = [
        'passing' => 'bool',
    ];

    protected $dates = [
        'entered_at',
        'exited_at',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(OperationPoint::class, 'operation_point_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->entered_at || ! $this->exited_at) {
            return null;
        }

        return max(0, $this->exited_at->getTimestamp() - $this->entered_at->getTimestamp());
    }
}
