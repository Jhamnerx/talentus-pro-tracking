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

        VehicleType::create([
            'user_id' => $this->user->id,
            'name'    => $this->data['name'],
        ]);

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

    private function syncStates(VehicleType $item): void
    {
        $kept = [];

        foreach ($this->data['edit_state'] ?? [] as $id => $name) {
            if (empty($name))
                continue;

            VehicleState::where('id', $id)->where('vehicle_type_id', $item->id)->update(['name' => $name]);

            $kept[] = $id;
        }

        $item->states()->whereNotIn('id', $kept)->delete();

        foreach ($this->data['add_state'] ?? [] as $name) {
            if (empty($name))
                continue;

            $item->states()->create(['name' => $name]);
        }
    }
}
