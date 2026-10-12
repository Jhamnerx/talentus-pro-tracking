<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parada detectada dentro de un tramo, con su desglose por motor. */
class OperationStop extends AbstractEntity
{
    protected $table = 'operation_stops';

    protected $fillable = [
        'leg_id',
        'device_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'latitude',
        'longitude',
        'address',
        'engine_on_seconds',
        'engine_off_seconds',
        'geofence_name',
        'authorized',
        'doubtful',
        'reason_id',
        'note',
        'justified_by',
        'justified_at',
    ];

    protected $casts = [
        'duration_seconds'   => 'int',
        'engine_on_seconds'  => 'int',
        'engine_off_seconds' => 'int',
        'latitude'           => 'float',
        'longitude'          => 'float',
        'authorized'         => 'bool',
        'doubtful'           => 'bool',
    ];

    protected $dates = [
        'started_at',
        'ended_at',
        'justified_at',
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(OperationLeg::class, 'leg_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(OperationStopReason::class, 'reason_id');
    }

    public function justifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }

    public function isJustified(): bool
    {
        return ! is_null($this->justified_at);
    }

    /** Motivo del catalogo, o "Otro" si se justifico con nota libre. */
    public function reasonLabel(): ?string
    {
        if (! $this->isJustified()) {
            return null;
        }

        return $this->reason ? $this->reason->name : trans('front.operation_reason_other');
    }

    /** No autorizada y sin sospecha de GPS congelado. */
    public function countsAsUnauthorized(): bool
    {
        return ! $this->authorized && ! $this->doubtful;
    }
}
