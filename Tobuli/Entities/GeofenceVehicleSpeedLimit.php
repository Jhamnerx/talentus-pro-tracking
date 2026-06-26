<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceVehicleSpeedLimit extends AbstractEntity
{
    protected $table = 'geofence_vehicle_speed_limits';

    protected $fillable = [
        'geofence_id',
        'vehicle_type_id',
        'vehicle_state_id',
        'speed_limit',
    ];

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function vehicleState(): BelongsTo
    {
        return $this->belongsTo(VehicleState::class);
    }
}
