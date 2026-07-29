<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleState extends AbstractEntity
{
    protected $table = 'vehicle_states';

    protected $fillable = [
        'vehicle_type_id',
        'name',
        'label',
        'color',
    ];

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
