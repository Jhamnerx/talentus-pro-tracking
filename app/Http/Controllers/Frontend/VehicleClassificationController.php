<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\DeviceClassificationService;

/**
 * Clasificación de vehículos (tipo + estado) para la app móvil, con user_api_hash.
 * Misma lógica que Ui\V2\DevicesController@vehicleStates / changeVehicleState y que
 * la matriz de límites de Ui\V2\ZonesController.
 */
class VehicleClassificationController extends Controller
{
    private function stateArray(VehicleState $s): array
    {
        return [
            'id'    => (int) $s->id,
            'name'  => (string) $s->name,
            'label' => (string) ($s->label ?: $s->name),
            'color' => (string) ($s->color ?: '#64748b'),
        ];
    }

    /**
     * Tipos de vehículo del usuario con sus estados (para la matriz de límites de la
     * geocerca). `enabled` = plugin geofences_speed_limit.
     */
    public function apiTypes()
    {
        $types = VehicleType::with('states')
            ->where('user_id', $this->user->id)
            ->orderBy('name')
            ->get()
            ->map(fn (VehicleType $vt) => [
                'id'     => (int) $vt->id,
                'name'   => (string) $vt->name,
                'states' => $vt->states->map(fn ($s) => $this->stateArray($s))->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'status'  => 1,
            'enabled' => (bool) settings('plugins.geofences_speed_limit.status'),
            'items'   => $types,
        ];
    }

    /** Tipo del equipo (según el usuario), sus estados posibles y el actual. Query: device_id. */
    public function apiDeviceStates()
    {
        $device = $this->user->devices()->find((int) request()->get('device_id'));
        $this->checkException('devices', 'show', $device);

        $type  = $device->vehicleTypeFor($this->user->id);
        $state = $device->vehicleStateFor($this->user->id);

        return [
            'status'  => 1,
            'type'    => $type ? ['id' => (int) $type->id, 'name' => (string) $type->name] : null,
            'states'  => $type ? $type->states->map(fn ($s) => $this->stateArray($s))->values()->all() : [],
            'current' => $state ? (int) $state->id : null,
        ];
    }

    /** Cambia el estado del equipo (debe pertenecer a su tipo). Body: device_id, vehicle_state_id (0 = ninguno). */
    public function apiChangeState(DeviceClassificationService $service)
    {
        if (! $this->user->perm('devices', 'edit')) {
            return ['status' => 0, 'perm' => 0, 'message' => trans('front.dont_have_permission')];
        }

        $device = $this->user->devices()->find((int) request()->get('device_id'));
        $this->checkException('devices', 'show', $device);

        $stateId = (int) request()->get('vehicle_state_id');

        try {
            $classification = $service->setState($this->user, $device, $stateId > 0 ? $stateId : null);
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $message = $errors instanceof \Illuminate\Support\MessageBag ? (string) $errors->first() : (string) $errors;

            return ['status' => 0, 'message' => $message ?: trans('ui.invalid_state_for_vehicle_type')];
        }

        $state = $classification ? $classification->vehicleState : null;

        return [
            'status'           => 1,
            'vehicle_state_id' => $state ? (int) $state->id : null,
            'vehicle_state'    => $state ? $this->stateArray($state) : null,
        ];
    }
}
