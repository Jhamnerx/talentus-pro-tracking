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
        [$type, $state] = $this->validate($user, $typeId, $stateId);

        if (!$type) {
            $this->clear($user, $device);

            return null;
        }

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
     * Resuelve y valida tipo y estado SIN escribir: el tipo debe ser del
     * usuario y el estado del tipo. Tipo vacio => [null, null]. Permite
     * validar antes de guardar el dispositivo para no dejar escrituras a
     * medias si la clasificacion es invalida.
     *
     * @return array [?VehicleType, ?VehicleState]
     * @throws ValidationException
     */
    public function validate(User $user, $typeId, $stateId): array
    {
        if (empty($typeId))
            return [null, null];

        $type = VehicleType::where('user_id', $user->id)->find((int) $typeId);

        if (!$type) {
            throw new ValidationException([
                'vehicle_type_id' => [trans('validation.exists', ['attribute' => trans('validation.attributes.vehicle_type_id')])],
            ]);
        }

        return [$type, $this->resolveState($type, $stateId)];
    }

    /**
     * Cambia solo el estado; exige que el usuario ya tenga clasificado el
     * dispositivo (hace falta un tipo al que pertenezca el estado).
     * Sin clasificacion y con estado vacio no hace nada y devuelve null (el
     * modal legacy muestra el select vacio y el usuario puede pulsar Guardar).
     *
     * @throws ValidationException si se envia un estado sin tener tipo, o de otro tipo
     */
    public function setState(User $user, Device $device, $stateId): ?DeviceVehicleClassification
    {
        $classification = DeviceVehicleClassification::where('device_id', $device->id)
            ->where('user_id', $user->id)
            ->with('vehicleType')
            ->first();

        if (!$classification || !$classification->vehicleType) {
            if (empty($stateId))
                return null;

            throw new ValidationException([
                'vehicle_state_id' => [trans('ui.vehicle_state_requires_type')],
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
