<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clasificacion de un dispositivo (tipo + estado de vehiculo) para UN usuario.
 *
 * Un dispositivo compartido entre cuentas puede tener una clasificacion
 * distinta por cada usuario; los tipos/estados siempre pertenecen a ese usuario.
 */
class DeviceVehicleClassification extends AbstractEntity
{
    protected $table = 'device_vehicle_classifications';

    protected $fillable = [
        'device_id',
        'user_id',
        'vehicle_type_id',
        'vehicle_state_id',
    ];

    protected $casts = [
        'device_id'        => 'int',
        'user_id'          => 'int',
        'vehicle_type_id'  => 'int',
        'vehicle_state_id' => 'int',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
