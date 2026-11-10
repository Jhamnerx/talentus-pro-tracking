<?php

namespace Tobuli\Services\Operations;

use Illuminate\Support\Facades\DB;
use Tobuli\Entities\DeviceGroup;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationPoint;
use Tobuli\Entities\OperationRecalculation;
use Tobuli\Entities\OperationShift;
use Tobuli\Entities\OperationShiftBreak;
use Tobuli\Entities\User;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\Formatter\Formatter;
use Tobuli\Services\ModelService;

/**
 * Alta, edicion y baja de operaciones, con sus turnos, puntos y la fila de
 * estado de cada vehiculo del grupo.
 *
 * Datos que acepta (ademas de los campos de la tabla):
 *   points:  [{geofence_id, role}]          (role: load | unload)
 *   shifts:  [{id?, name, start_time, end_time, breaks?: [{id?, name, kind, start_time?, end_time?}]}]
 *
 * Reporte de turno (fase A): day_start (H:i, nulo = inicio del primer turno)
 * y vehicle_type_id / loaded_state_id / empty_state_id (catalogo de Seguridad
 * del usuario; sin tipo los estados se guardan nulos).
 */
class OperationService extends ModelService
{
    protected User $user;

    public function __construct(User $user)
    {
        $this->user = $user;

        $this->setDefaults([
            'kind'                   => Operation::KIND_HAUL,
            'active'                 => true,
            'min_dwell_seconds'      => 30,
            'stop_threshold_seconds' => 60,
            'max_leg_hours'          => 6,
            'authorized_categories'  => [Geofence::CATEGORY_FUEL, Geofence::CATEGORY_WORKSHOP, Geofence::CATEGORY_REST],
            'worked_hours_deductions' => [],
        ]);

        $rules = [
            'name'                   => 'required|string|max:255',
            'kind'                   => 'in:haul,route',
            'device_group_id'        => 'required|integer',
            'active'                 => 'boolean',
            'min_dwell_seconds'      => 'integer|min:0|max:3600',
            'stop_threshold_seconds' => 'integer|min:10|max:3600',
            'max_leg_hours'          => 'integer|min:1|max:48',
            'day_start'              => 'nullable|date_format:H:i',
            'vehicle_type_id'        => 'nullable|integer',
            'loaded_state_id'        => 'nullable|integer',
            'empty_state_id'         => 'nullable|integer',
            'authorized_categories'  => 'array',
            'authorized_categories.*' => 'in:' . implode(',', Geofence::CATEGORIES),
            'worked_hours_deductions'   => 'array',
            'worked_hours_deductions.*' => 'in:' . implode(',', Operation::DEDUCTIONS),
            'points'                 => 'required|array|min:2',
            'points.*.geofence_id'   => 'required|integer',
            'points.*.role'          => 'required|in:load,unload',
            'shifts'                 => 'array',
            'shifts.*.name'          => 'required|string|max:60',
            'shifts.*.start_time'    => 'required|date_format:H:i',
            'shifts.*.end_time'      => 'required|date_format:H:i',
            'shifts.*.breaks'        => 'array',
            'shifts.*.breaks.*.name'       => 'required|string|max:60',
            'shifts.*.breaks.*.kind'       => 'required|in:' . implode(',', OperationShiftBreak::KINDS),
            'shifts.*.breaks.*.start_time' => 'nullable|date_format:H:i',
            'shifts.*.breaks.*.end_time'   => 'nullable|date_format:H:i',
        ];

        $this->setValidationRulesStore($rules);
        $this->setValidationRulesUpdate(array_merge($rules, [
            'name'            => 'string|max:255',
            'device_group_id' => 'integer',
            'points'          => 'array|min:2',
        ]));
    }

    protected function normalize(array $data)
    {
        if (array_key_exists('active', $data)) {
            $data['active'] = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('authorized_categories', $data) && ! is_array($data['authorized_categories'])) {
            $data['authorized_categories'] = $data['authorized_categories'] ? (array) $data['authorized_categories'] : [];
        }

        if (array_key_exists('worked_hours_deductions', $data) && ! is_array($data['worked_hours_deductions'])) {
            $data['worked_hours_deductions'] = $data['worked_hours_deductions'] ? (array) $data['worked_hours_deductions'] : [];
        }

        foreach (['shifts', 'points'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = json_decode($data[$key], true) ?: [];
            }
        }

        if (! empty($data['shifts']) && is_array($data['shifts'])) {
            foreach ($data['shifts'] as $i => $shift) {
                foreach (['start_time', 'end_time'] as $k) {
                    if (! empty($shift[$k]) && strlen($shift[$k]) > 5) {
                        $data['shifts'][$i][$k] = substr($shift[$k], 0, 5);
                    }
                }

                if (array_key_exists('breaks', $shift) && is_string($shift['breaks'])) {
                    $shift['breaks'] = json_decode($shift['breaks'], true) ?: [];
                    $data['shifts'][$i]['breaks'] = $shift['breaks'];
                }

                if (empty($shift['breaks']) || ! is_array($shift['breaks'])) {
                    continue;
                }

                foreach ($shift['breaks'] as $j => $break) {
                    $data['shifts'][$i]['breaks'][$j]['kind'] = $break['kind'] ?? OperationShiftBreak::KIND_SCHEDULED;

                    foreach (['start_time', 'end_time'] as $k) {
                        $value = $break[$k] ?? null;

                        // El de fatiga no lleva horario; el vacio queda nulo.
                        if (($break['kind'] ?? null) === OperationShiftBreak::KIND_FATIGUE || $value === '' || $value === null) {
                            $data['shifts'][$i]['breaks'][$j][$k] = null;
                            continue;
                        }

                        $data['shifts'][$i]['breaks'][$j][$k] = strlen($value) > 5 ? substr($value, 0, 5) : $value;
                    }
                }
            }
        }

        if (array_key_exists('day_start', $data)) {
            $data['day_start'] = $data['day_start'] === '' || $data['day_start'] === null
                ? null
                : (strlen($data['day_start']) > 5 ? substr($data['day_start'], 0, 5) : $data['day_start']);
        }

        foreach (['vehicle_type_id', 'loaded_state_id', 'empty_state_id'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = $data[$k] === '' || $data[$k] === null || (int) $data[$k] === 0 ? null : (int) $data[$k];
            }
        }

        // Sin tipo, los estados no tienen sentido: se guardan nulos.
        if (array_key_exists('vehicle_type_id', $data) && $data['vehicle_type_id'] === null) {
            $data['loaded_state_id'] = null;
            $data['empty_state_id'] = null;
        }

        return $data;
    }

    public function validateStore($data)
    {
        parent::validateStore($data);
        $this->validateRelations($data);
    }

    public function validateUpdate($data)
    {
        parent::validateUpdate($data);
        $this->validateRelations($data);
    }

    protected function validateRelations(array $data): void
    {
        $errors = [];

        if (array_key_exists('device_group_id', $data)) {
            $group = DeviceGroup::where('user_id', $this->user->id)->find($data['device_group_id']);

            if (! $group) {
                $errors['device_group_id'] = trans('validation.exists', ['attribute' => trans('validation.attributes.group_id')]);
            }
        }

        if (array_key_exists('points', $data)) {
            $ids = array_column($data['points'], 'geofence_id');
            $geofences = Geofence::userOwned($this->user)->whereIn('id', $ids)->get()->keyBy('id');
            $roles = array_unique(array_column($data['points'], 'role'));

            foreach ($ids as $id) {
                $g = $geofences->get($id);

                if (! $g) {
                    $errors['points'] = trans('front.operation_point_geofence_missing');
                    break;
                }

                // Una geocerca movil se guarda en cada trama: no sirve de punto.
                if ($g->device_id) {
                    $errors['points'] = trans('front.operation_point_geofence_moving', ['name' => $g->name]);
                    break;
                }
            }

            if (count($roles) < 2) {
                $errors['points'] = trans('front.operation_points_need_both_roles');
            }
        }

        if (! empty($data['shifts'])) {
            $shifts = array_map(fn ($s) => new OperationShift(array_diff_key($s, ['breaks' => 1, 'id' => 1])), $data['shifts']);

            foreach ($shifts as $i => $a) {
                foreach ($shifts as $j => $b) {
                    if ($j > $i && $a->overlaps($b)) {
                        $errors['shifts'] = trans('front.operation_shifts_overlap', ['a' => $a->name, 'b' => $b->name]);
                        break 2;
                    }
                }
            }

            foreach (array_values($data['shifts']) as $i => $shiftData) {
                if ($message = $this->breaksError($shifts[$i], $shiftData['breaks'] ?? [])) {
                    $errors['breaks'] = $message;
                    break;
                }
            }
        }

        $this->validateVehicleStates($data, $errors);

        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Descansos de un turno: el programado exige inicio y fin dentro del
     * rango del turno (puede cruzar la medianoche si el turno tambien), dos
     * programados no se solapan y hay como mucho uno de fatiga.
     *
     * @return string|null mensaje de error, o null si todo esta bien
     */
    protected function breaksError(OperationShift $shift, $breaks): ?string
    {
        if (empty($breaks) || ! is_array($breaks)) {
            return null;
        }

        $scheduled = [];
        $fatigue = 0;

        foreach ($breaks as $row) {
            $break = new OperationShiftBreak(array_diff_key((array) $row, ['id' => 1]));

            if ($break->isFatigue()) {
                $fatigue++;
                continue;
            }

            if (! $break->start_time || ! $break->end_time) {
                return trans('front.operation_break_times_required', ['name' => $break->name, 'shift' => $shift->name]);
            }

            // Todo medido desde el inicio del turno, asi un descanso que cruza
            // la medianoche (23:30-00:30) cabe en un turno que tambien la
            // cruza (18:30-06:30) y no en uno diurno. Un fin igual al inicio
            // del turno se lee como "hasta el final del dia del turno".
            $shiftStart = OperationShift::seconds($shift->start_time);
            $offset = fn (string $t) => (OperationShift::seconds($t) - $shiftStart + 86400) % 86400;
            $shiftLength = $offset($shift->end_time) ?: 86400;
            $breakStart = $offset($break->start_time);
            $breakEnd = $offset($break->end_time) ?: 86400;

            $inside = $breakStart < $breakEnd && $breakEnd <= $shiftLength;

            if (! $inside) {
                return trans('front.operation_break_outside_shift', ['name' => $break->name, 'shift' => $shift->name]);
            }

            foreach ($scheduled as $other) {
                if ($break->overlaps($other)) {
                    return trans('front.operation_breaks_overlap', ['a' => $other->name, 'b' => $break->name, 'shift' => $shift->name]);
                }
            }

            $scheduled[] = $break;
        }

        if ($fatigue > 1) {
            return trans('front.operation_break_fatigue_once', ['shift' => $shift->name]);
        }

        return null;
    }

    /**
     * Tipo y estados de vehiculo (Seguridad): el tipo es del usuario y los
     * dos estados son de ese tipo y distintos entre si. normalize() ya dejo
     * los estados nulos cuando no hay tipo.
     */
    protected function validateVehicleStates(array $data, array &$errors): void
    {
        if (! array_key_exists('vehicle_type_id', $data) || $data['vehicle_type_id'] === null) {
            return;
        }

        $type = VehicleType::where('user_id', $this->user->id)->find($data['vehicle_type_id']);

        if (! $type) {
            $errors['vehicle_type_id'] = trans('front.operation_vehicle_type_missing');

            return;
        }

        $stateIds = VehicleState::where('vehicle_type_id', $type->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (['loaded_state_id', 'empty_state_id'] as $key) {
            $id = $data[$key] ?? null;

            if ($id !== null && ! in_array((int) $id, $stateIds, true)) {
                $errors[$key] = trans('front.operation_vehicle_state_not_of_type', ['type' => $type->name]);
            }
        }

        $loaded = $data['loaded_state_id'] ?? null;
        $empty = $data['empty_state_id'] ?? null;

        if ($loaded !== null && $empty !== null && (int) $loaded === (int) $empty && empty($errors['empty_state_id'])) {
            $errors['empty_state_id'] = trans('front.operation_vehicle_states_same');
        }
    }

    public function store(array $data)
    {
        return DB::transaction(function () use ($data) {
            $operation = Operation::create(array_merge(
                $this->only($data),
                ['user_id' => $this->user->id]
            ));

            $this->syncShifts($operation, $data['shifts'] ?? []);
            $this->syncPoints($operation, $data['points'] ?? []);
            $this->syncDeviceStates($operation);

            return $operation;
        });
    }

    public function update($operation, array $data)
    {
        return DB::transaction(function () use ($operation, $data) {
            $operation->update($this->only($data));

            if (array_key_exists('shifts', $data)) {
                $this->syncShifts($operation, $data['shifts']);
            }

            if (array_key_exists('points', $data)) {
                $this->syncPoints($operation, $data['points']);
            }

            $this->syncDeviceStates($operation);

            return $operation;
        });
    }

    /** Borrado logico; las vueltas siguen en los reportes. */
    public function delete($operation)
    {
        return DB::transaction(function () use ($operation) {
            $this->releaseDevices($operation);

            return $operation->delete();
        });
    }

    public function setActive(Operation $operation, bool $active): void
    {
        $operation->active = $active;
        $operation->save();

        $this->syncDeviceStates($operation);
    }

    // ------------------------------------------------------------------
    // Turnos y puntos
    // ------------------------------------------------------------------

    protected function syncShifts(Operation $operation, array $shifts): void
    {
        $keep = [];

        foreach (array_values($shifts) as $position => $data) {
            $shift = ! empty($data['id'])
                ? $operation->shifts()->find($data['id'])
                : null;

            $attributes = [
                'name'       => $data['name'],
                'start_time' => $data['start_time'] . ':00',
                'end_time'   => $data['end_time'] . ':00',
                'position'   => $position,
            ];

            $shift = $shift ? tap($shift)->update($attributes) : $operation->shifts()->create($attributes);
            $keep[] = $shift->id;

            $this->syncBreaks($shift, is_array($data['breaks'] ?? null) ? $data['breaks'] : []);
        }

        // Los turnos quitados se borran; las vueltas conservan shift_name/shift_date.
        // Los descansos caen en cascada con su turno.
        $operation->shifts()->whereNotIn('id', $keep ?: [0])->delete();
        $operation->unsetRelation('shifts');
    }

    /** Descansos del turno: actualiza por id, crea los nuevos y borra los que no vienen. */
    protected function syncBreaks(OperationShift $shift, array $breaks): void
    {
        $keep = [];

        foreach (array_values($breaks) as $position => $data) {
            $break = ! empty($data['id'])
                ? $shift->breaks()->find($data['id'])
                : null;

            $fatigue = ($data['kind'] ?? null) === OperationShiftBreak::KIND_FATIGUE;

            $attributes = [
                'name'       => $data['name'],
                'kind'       => $fatigue ? OperationShiftBreak::KIND_FATIGUE : OperationShiftBreak::KIND_SCHEDULED,
                'start_time' => $fatigue || empty($data['start_time']) ? null : substr($data['start_time'], 0, 5) . ':00',
                'end_time'   => $fatigue || empty($data['end_time']) ? null : substr($data['end_time'], 0, 5) . ':00',
                'position'   => $position,
            ];

            $break = $break ? tap($break)->update($attributes) : $shift->breaks()->create($attributes);
            $keep[] = $break->id;
        }

        $shift->breaks()->whereNotIn('id', $keep ?: [0])->delete();
        $shift->unsetRelation('breaks');
    }

    /**
     * Puntos = lista deseada de (geofence_id, role). Los que faltan se
     * cierran (active_to = ahora), los nuevos se abren con la geometria
     * actual de la geocerca. Los que siguen no se tocan.
     */
    protected function syncPoints(Operation $operation, array $points): void
    {
        $now = date('Y-m-d H:i:s');
        $current = $operation->currentPoints()->get();
        $wanted = [];

        foreach ($points as $p) {
            $wanted[$p['geofence_id'] . ':' . $p['role']] = $p;
        }

        foreach ($current as $point) {
            $key = $point->geofence_id . ':' . $point->role;

            if (isset($wanted[$key])) {
                unset($wanted[$key]);
                continue;
            }

            $point->active_to = $now;
            $point->save();
        }

        if (! $wanted) {
            return;
        }

        $geofences = Geofence::whereIn('id', array_column($wanted, 'geofence_id'))->get()->keyBy('id');

        foreach ($wanted as $p) {
            $g = $geofences->get($p['geofence_id']);

            if (! $g) {
                continue;
            }

            $operation->points()->create([
                'geofence_id' => $g->id,
                'role'        => $p['role'],
                'name'        => $g->name,
                'geometry'    => OperationPoint::geometryFrom($g),
                'active_from' => $now,
            ]);
        }

        $operation->unsetRelation('currentPoints');
    }

    /**
     * La geocerca cambio de forma: cierra la version vigente de cada punto
     * que la usa y abre otra con la geometria nueva. Lo llama GeofenceService.
     *
     * @return int puntos versionados
     */
    public static function versionPointsForGeofence(Geofence $geofence): int
    {
        $now = date('Y-m-d H:i:s');
        $geometry = OperationPoint::geometryFrom($geofence);
        $count = 0;

        $points = OperationPoint::current()
            ->where('geofence_id', $geofence->id)
            ->whereHas('operation')
            ->get();

        foreach ($points as $point) {
            if (OperationPoint::sameGeometry($point->geometry ?: [], $geometry)) {
                continue;
            }

            $point->active_to = $now;
            $point->save();

            OperationPoint::create([
                'operation_id' => $point->operation_id,
                'geofence_id'  => $geofence->id,
                'role'         => $point->role,
                'name'         => $geofence->name,
                'geometry'     => $geometry,
                'active_from'  => $now,
            ]);

            $count++;
        }

        return $count;
    }

    /** Operaciones (no eliminadas) que usan la geocerca como punto vigente. */
    public static function operationsUsingGeofence(Geofence $geofence)
    {
        return Operation::whereHas('currentPoints', fn ($q) => $q->where('geofence_id', $geofence->id))->get();
    }

    /** Operaciones (no eliminadas) que usan el grupo. */
    public static function operationsUsingGroup(int $groupId)
    {
        return Operation::where('device_group_id', $groupId)->get();
    }

    // ------------------------------------------------------------------
    // Vehiculos
    // ------------------------------------------------------------------

    /**
     * Alinea operation_device_state con el grupo: crea la fila de los
     * vehiculos nuevos y libera la de los que salieron. Un vehiculo que ya
     * esta en otra operacion activa no se toma.
     *
     * @return array{added:int, removed:int}
     */
    public function syncDeviceStates(Operation $operation): array
    {
        if (! $operation->active || $operation->trashed()) {
            return ['added' => 0, 'removed' => $this->releaseDevices($operation)];
        }

        $wanted = $operation->deviceIds()->all();
        $have = $operation->deviceStates()->pluck('device_id')->all();

        $added = 0;
        $removed = 0;

        foreach (array_diff($wanted, $have) as $deviceId) {
            $taken = OperationDeviceState::where('device_id', $deviceId)->exists();

            if ($taken) {
                continue;
            }

            OperationDeviceState::create([
                'device_id'    => $deviceId,
                'operation_id' => $operation->id,
                'state'        => OperationDeviceState::STATE_UNKNOWN,
            ]);
            $added++;
        }

        foreach (array_diff($have, $wanted) as $deviceId) {
            $this->releaseDevice($operation, (int) $deviceId);
            $removed++;
        }

        return ['added' => $added, 'removed' => $removed];
    }

    protected function releaseDevices(Operation $operation): int
    {
        $n = 0;

        foreach ($operation->deviceStates()->pluck('device_id') as $deviceId) {
            $this->releaseDevice($operation, (int) $deviceId);
            $n++;
        }

        return $n;
    }

    /** Cierra lo abierto como incompleto y quita la fila de estado. */
    protected function releaseDevice(Operation $operation, int $deviceId): void
    {
        $state = OperationDeviceState::where('device_id', $deviceId)
            ->where('operation_id', $operation->id)
            ->with(['openLeg', 'openCycle', 'openVisit', 'currentPoint'])
            ->first();

        if (! $state) {
            return;
        }

        $formatter = new Formatter();
        $formatter->byUser($operation->user);

        $writer = new OperationCycleWriter($operation, $state, collect(), $formatter);
        $writer->abandon(date('Y-m-d H:i:s'));

        if ($state->openVisit && ! $state->openVisit->exited_at) {
            $state->openVisit->exited_at = date('Y-m-d H:i:s');
            $state->openVisit->save();
        }

        $state->delete();
    }

    /** Encola un recalculo manual de uno o varios vehiculos. */
    public function requestRecalculation(Operation $operation, array $deviceIds, string $fromUtc, bool $useCurrentPoints): int
    {
        $allowed = $operation->deviceIds()->all();
        $n = 0;

        foreach ($deviceIds as $id) {
            if (! in_array((int) $id, $allowed)) {
                continue;
            }

            OperationRecalculation::enqueue($operation->id, (int) $id, $fromUtc, $useCurrentPoints, $this->user->id);
            $n++;
        }

        return $n;
    }

    protected function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'kind', 'device_group_id', 'active', 'min_dwell_seconds',
            'stop_threshold_seconds', 'max_leg_hours', 'authorized_categories', 'worked_hours_deductions',
            'day_start', 'vehicle_type_id', 'loaded_state_id', 'empty_state_id',
        ]));
    }
}
