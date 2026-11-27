<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado en vivo de un vehiculo dentro de su operacion. Es lo que
 * OperationChecker lee al empezar cada lote de PositionsWriter y escribe solo
 * en transiciones: el estado NO vive en memoria entre lotes.
 */
class OperationDeviceState extends AbstractEntity
{
    const STATE_UNKNOWN = 'unknown';
    const STATE_AT_LOAD = 'at_load';
    const STATE_OUTBOUND = 'outbound';
    const STATE_AT_UNLOAD = 'at_unload';
    const STATE_INBOUND = 'inbound';
    const STATE_NO_DATA = 'no_data';

    protected $table = 'operation_device_state';

    protected $fillable = [
        'device_id',
        'operation_id',
        'state',
        'current_point_id',
        'candidate_point_id',
        'pending_since',
        'open_visit_id',
        'open_leg_id',
        'open_cycle_id',
        'last_time',
        'no_data_since',
    ];

    protected $dates = [
        'pending_since',
        'last_time',
        'no_data_since',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function currentPoint(): BelongsTo
    {
        return $this->belongsTo(OperationPoint::class, 'current_point_id');
    }

    public function candidatePoint(): BelongsTo
    {
        return $this->belongsTo(OperationPoint::class, 'candidate_point_id');
    }

    public function openVisit(): BelongsTo
    {
        return $this->belongsTo(OperationVisit::class, 'open_visit_id');
    }

    public function openLeg(): BelongsTo
    {
        return $this->belongsTo(OperationLeg::class, 'open_leg_id');
    }

    public function openCycle(): BelongsTo
    {
        return $this->belongsTo(OperationCycle::class, 'open_cycle_id');
    }

    public function isInsidePoint(): bool
    {
        return ! is_null($this->current_point_id);
    }

    public function isOnLeg(): bool
    {
        return ! is_null($this->open_leg_id);
    }
}
