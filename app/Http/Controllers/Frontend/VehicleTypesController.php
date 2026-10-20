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
     * Mismo formato que la ui/v2 (Ui/V2/VehicleTypesController@syncStates):
     * cada estado es un objeto {name, label, color}. El formulario legacy
     * manda exactamente eso (state_row.blade + state_rows_script.blade).
     */
    private function syncStates(VehicleType $item): void
    {
        $kept = [];

        foreach ((array) ($this->data['edit_state'] ?? []) as $id => $st) {
            $fields = $this->stateFields($st);

            if ($fields === null)
                continue;

            VehicleState::where('id', $id)->where('vehicle_type_id', $item->id)->update($fields);

            $kept[] = $id;
        }

        $item->states()->whereNotIn('id', $kept ?: [0])->delete();

        foreach ((array) ($this->data['add_state'] ?? []) as $st) {
            $fields = $this->stateFields($st);

            if ($fields === null)
                continue;

            $item->states()->create($fields);
        }
    }

    /** Normaliza un estado {name, label, color} -> campos a guardar, o null si no hay nombre. */
    private function stateFields($st): ?array
    {
        $name  = is_array($st) ? trim((string) ($st['name'] ?? '')) : trim((string) $st);
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
