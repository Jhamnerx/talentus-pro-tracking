<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\Validators\VehicleTypeFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · VehicleTypesController (ui/v2)
 * -----------------------------------------------------------------
 * Tipos de vehículo + estados anidados (para los límites de velocidad
 * por geocerca). CRUD equivalente al VehicleTypesController legacy:
 * validación VehicleTypeFormValidator + sync de estados (edit_state[id]
 * actualiza, add_state[] crea, los omitidos se borran). Se usa desde
 * el tab "Tipos de vehículo" del modal de cuenta.
 * -----------------------------------------------------------------
 */
class VehicleTypesController extends BaseController
{
    public function data(Request $request): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->get('per_page', 15)));

        $query = VehicleType::with('states')->where('user_id', $this->user->id);
        if ($s = trim((string) $request->get('s'))) {
            $query->where('name', 'like', "%{$s}%");
        }

        $items = $query->orderBy('name')->paginate($perPage);

        $rows = [];
        foreach ($items as $t) {
            $rows[] = [
                'id'     => (int) $t->id,
                'name'   => (string) $t->name,
                'states' => $t->states->map(function ($s) {
                    return [
                        'id'    => (int) $s->id,
                        'name'  => (string) $s->name,
                        'label' => $s->label,
                        'color' => $s->color,
                    ];
                })->all(),
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            VehicleTypeFormValidator::validate('create', $data);

            $type = VehicleType::create([
                'user_id' => $this->user->id,
                'name'    => $data['name'],
            ]);

            $this->syncStates($type, $data);

            return response()->json(['ok' => true, 'id' => (int) $type->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $type = VehicleType::where('user_id', $this->user->id)->find($id);
        if (! $type) {
            abort(404);
        }

        try {
            $data = $request->all();
            VehicleTypeFormValidator::validate('update', $data);

            $type->update(['name' => $data['name']]);
            $this->syncStates($type, $data);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $type = VehicleType::where('user_id', $this->user->id)->find($id);
        if (! $type) {
            abort(404);
        }

        $type->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * edit_state[id] actualiza/mantiene; add_state[] crea; el resto se borra.
     * Cada estado ahora es un objeto {name, label, color} (retro-compatible con
     * el string plano = solo name).
     */
    private function syncStates(VehicleType $type, array $data): void
    {
        $kept = [];
        foreach ((array) ($data['edit_state'] ?? []) as $stateId => $st) {
            $fields = $this->stateFields($st);
            if ($fields === null) {
                continue;
            }
            VehicleState::where('id', $stateId)->where('vehicle_type_id', $type->id)->update($fields);
            $kept[] = $stateId;
        }

        $type->states()->whereNotIn('id', $kept ?: [0])->delete();

        foreach ((array) ($data['add_state'] ?? []) as $st) {
            $fields = $this->stateFields($st);
            if ($fields === null) {
                continue;
            }
            $type->states()->create($fields);
        }
    }

    /** Normaliza un estado del payload → [name, label, color] o null si vacío. */
    private function stateFields($st): ?array
    {
        $name = is_array($st) ? trim((string) ($st['name'] ?? '')) : trim((string) $st);
        if ($name === '') {
            return null;
        }

        $label = is_array($st) ? trim((string) ($st['label'] ?? '')) : '';
        $color = is_array($st) ? trim((string) ($st['color'] ?? '')) : '';

        return [
            'name'  => mb_substr($name, 0, 255),
            'label' => $label === '' ? null : mb_substr($label, 0, 24),
            'color' => preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color) ? $color : null,
        ];
    }
}
