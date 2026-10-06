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
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(OperationLeg::class, 'leg_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** No autorizada y sin sospecha de GPS congelado. */
    public function countsAsUnauthorized(): bool
    {
        return ! $this->authorized && ! $this->doubtful;
    }
}
