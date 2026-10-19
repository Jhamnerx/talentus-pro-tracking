<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\ResourseNotFoundException;
use App\Http\Controllers\Controller;
use CustomFacades\Validators\VehicleTypeFormValidator;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;

class VehicleTypesController extends Controller
{
    public function index()
    {
        $items = $this->getList();

        return view('front::VehicleTypes.index')->with(compact('items'));
    }

    public function table()
    {
        $items = $this->getList();

        return view('front::VehicleTypes.table')->with(compact('items'));
    }

    private function getList()
    {
        return VehicleType::with('states')
            ->where('user_id', $this->user->id)
            ->orderBy('name')
            ->paginate(15);
    }

    public function create()
    {
        return view('front::VehicleTypes.create');
    }

    public function store()
    {
        VehicleTypeFormValidator::validate('create', $this->data);

        $item = VehicleType::create([
            'user_id' => $this->user->id,
            'name'    => $this->data['name'],
        ]);

        $this->syncStates($item);

        return ['status' => 1];
    }

    public function edit($id)
    {
        $item = $this->findOrFail($id);

        $item->load('states');

        return view('front::VehicleTypes.edit')->with(compact('item'));
    }

    public function update($id)
    {
        VehicleTypeFormValidator::validate('update', $this->data);

        $item = $this->findOrFail($id);

        $item->update(['name' => $this->data['name']]);

        $this->syncStates($item);

        return ['status' => 1];
    }

    public function destroy($id)
    {
        $item = $this->findOrFail($id);

        $item->delete();

        return ['status' => 1];
    }

    private function findOrFail($id): VehicleType
    {
        $item = VehicleType::where('user_id', $this->user->id)->find($id);

        if (!$item) {
            throw new ResourseNotFoundException(trans('front.vehicle_type'));
        }

        return $item;
    }

    /**
     * edit_state[id] actualiza/mantiene; add_state[] crea; el resto se borra.
     * Cada estado lleva name + label (badge) + color, igual que en ui/v2
     * (VehicleTypesController@syncStates). Se aceptan dos formas de payload:
     *  - objeto por estado: edit_state[id] = {name, label, color} (ui/v2)
     *  - arrays paralelos:  edit_state[id] / edit_state_label[id] /
     *    edit_state_color[id] y add_state[] / add_state_label[] /
     *    add_state_color[] (form legacy, que clona filas con .empty-input-add-new)
     */
    private function syncStates(VehicleType $item): void
    {
        $kept = [];

        $editLabels = (array) ($this->data['edit_state_label'] ?? []);
        $editColors = (array) ($this->data['edit_state_color'] ?? []);

        foreach ((array) ($this->data['edit_state'] ?? []) as $id => $st) {
            $fields = $this->stateFields($st, $editLabels[$id] ?? null, $editColors[$id] ?? null);

            if ($fields === null)
                continue;

            VehicleState::where('id', $id)->where('vehicle_type_id', $item->id)->update($fields);

            $kept[] = $id;
        }

        $item->states()->whereNotIn('id', $kept ?: [0])->delete();

        $addLabels = array_values((array) ($this->data['add_state_label'] ?? []));
        $addColors = array_values((array) ($this->data['add_state_color'] ?? []));

        foreach (array_values((array) ($this->data['add_state'] ?? [])) as $i => $st) {
            $fields = $this->stateFields($st, $addLabels[$i] ?? null, $addColors[$i] ?? null);

            if ($fields === null)
                continue;

            $item->states()->create($fields);
        }
    }

    /** Normaliza un estado del payload -> [name, label, color] o null si vacio. */
    private function stateFields($st, $label = null, $color = null): ?array
    {
        if (is_array($st)) {
            $label = $st['label'] ?? $label;
            $color = $st['color'] ?? $color;
            $st = $st['name'] ?? '';
        }

        $name = trim((string) $st);
        if ($name === '') {
            return null;
        }

        $label = trim((string) $label);
        $color = trim((string) $color);

        return [
            'name'  => mb_substr($name, 0, 255),
            'label' => $label === '' ? null : mb_substr($label, 0, 24),
            'color' => preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color) ? $color : null,
        ];
    }
}
