<?php

namespace Tobuli\Services;

use Tobuli\Entities\Device;
use Tobuli\Entities\DeviceVehicleClassification;
use Tobuli\Entities\User;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;

/**
 * Clasificacion de vehiculo (tipo + estado) de un dispositivo POR usuario.
 *
 * Cada usuario clasifica el dispositivo con SUS tipos/estados; un dispositivo
 * compartido puede tener una clasificacion distinta por cuenta.
 */
class DeviceClassificationService
{
    /**
     * Asigna tipo (y opcionalmente estado) al dispositivo para el usuario.
     * Tipo vacio => se elimina la clasificacion del usuario y devuelve null.
     *
     * @throws ValidationException si el tipo no es del usuario o el estado no es del tipo
     */
    public function set(User $user, Device $device, $typeId, $stateId): ?DeviceVehicleClassification
    {
        if (empty($typeId)) {
            $this->clear($user, $device);

            return null;
        }

        $type = VehicleType::where('user_id', $user->id)->find((int) $typeId);

        if (!$type) {
            throw new ValidationException([
                'vehicle_type_id' => [trans('validation.exists', ['attribute' => trans('validation.attributes.vehicle_type_id')])],
            ]);
        }

        $state = $this->resolveState($type, $stateId);

        $classification = DeviceVehicleClassification::updateOrCreate(
            ['device_id' => $device->id, 'user_id' => $user->id],
            ['vehicle_type_id' => $type->id, 'vehicle_state_id' => $state ? $state->id : null]
        );

        $classification->setRelation('vehicleType', $type);
        $classification->setRelation('vehicleState', $state);

        $this->forgetCached($device);

        return $classification;
    }

    /**
     * Cambia solo el estado; exige que el usuario ya tenga clasificado el
     * dispositivo (hace falta un tipo al que pertenezca el estado).
     *
     * @throws ValidationException
     */
    public function setState(User $user, Device $device, $stateId): DeviceVehicleClassification
    {
        $classification = DeviceVehicleClassification::where('device_id', $device->id)
            ->where('user_id', $user->id)
            ->with('vehicleType')
            ->first();

        if (!$classification || !$classification->vehicleType) {
            throw new ValidationException([
                'vehicle_state_id' => [trans('front.no_vehicle_state')],
            ]);
        }

        $state = $this->resolveState($classification->vehicleType, $stateId);

        $classification->vehicle_state_id = $state ? $state->id : null;
        $classification->save();
        $classification->setRelation('vehicleState', $state);

        $this->forgetCached($device);

        return $classification;
    }

    /** Quita la clasificacion del usuario para el dispositivo. */
    public function clear(User $user, Device $device): void
    {
        DeviceVehicleClassification::where('device_id', $device->id)
            ->where('user_id', $user->id)
            ->delete();

        $this->forgetCached($device);
    }

    /**
     * Estado vacio => null; si viene, debe pertenecer al tipo.
     *
     * @throws ValidationException
     */
    private function resolveState(VehicleType $type, $stateId): ?VehicleState
    {
        if (empty($stateId))
            return null;

        $state = $type->states()->find((int) $stateId);

        if (!$state) {
            throw new ValidationException([
                'vehicle_state_id' => [trans('ui.invalid_state_for_vehicle_type')],
            ]);
        }

        return $state;
    }

    /** Invalida la relacion/cache cargada en la instancia para que relea la base. */
    private function forgetCached(Device $device): void
    {
        $device->unsetRelation('classifications');
        $device->forgetClassificationCache();
    }
}
