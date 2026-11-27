<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cola de recalculos de vueltas. Una fila pendiente por vehiculo y operacion:
 * las tramas atrasadas y los pedidos manuales se acumulan bajando `from_time`.
 */
class OperationRecalculation extends AbstractEntity
{
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE = 'done';
    const STATUS_FAILED = 'failed';

    protected $table = 'operation_recalculations';

    protected $fillable = [
        'operation_id',
        'device_id',
        'from_time',
        'use_current_points',
        'status',
        'error',
        'requested_by',
    ];

    protected $casts = [
        'use_current_points' => 'bool',
    ];

    protected $dates = [
        'from_time',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Encola o adelanta el recalculo de un vehiculo. Si ya hay una fila
     * pendiente se queda con el `from_time` menor.
     */
    public static function enqueue(int $operationId, int $deviceId, string $fromTimeUtc, bool $useCurrentPoints = false, ?int $requestedBy = null): self
    {
        $row = static::pending()
            ->where('operation_id', $operationId)
            ->where('device_id', $deviceId)
            ->first();

        if (! $row) {
            return static::create([
                'operation_id'       => $operationId,
                'device_id'          => $deviceId,
                'from_time'          => $fromTimeUtc,
                'use_current_points' => $useCurrentPoints,
                'status'             => self::STATUS_PENDING,
                'requested_by'       => $requestedBy,
            ]);
        }

        $changes = [];

        if (strtotime($fromTimeUtc) < $row->from_time->getTimestamp()) {
            $changes['from_time'] = $fromTimeUtc;
        }

        if ($useCurrentPoints && ! $row->use_current_points) {
            $changes['use_current_points'] = true;
        }

        if ($requestedBy && ! $row->requested_by) {
            $changes['requested_by'] = $requestedBy;
        }

        if ($changes) {
            $row->update($changes);
        }

        return $row;
    }
}
