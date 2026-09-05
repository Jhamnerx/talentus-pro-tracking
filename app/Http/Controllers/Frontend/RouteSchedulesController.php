<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\ResourseNotFoundException;
use App\Http\Controllers\Controller;
use CustomFacades\Validators\RouteScheduleFormValidator;
use Illuminate\Support\Arr;
use Tobuli\Entities\Device;
use Tobuli\Entities\Route;
use Tobuli\Entities\RouteSchedule;

/**
 * Programaciones de ruta: a que hora sale cada vuelta, que dias y con que
 * vehiculos. El cumplimiento se mira en el reporte 909.
 *
 * Va con los permisos de "routes": una programacion no es mas que la agenda de
 * una ruta, y quien puede tocar la ruta puede tocar su agenda. Inventar un
 * modulo de permisos nuevo obligaria a repasar los permisos de cada usuario ya
 * dado de alta para no dejarlos fuera de una pantalla que les toca.
 */
class RouteSchedulesController extends Controller
{
    public function index()
    {
        $this->checkException('routes', 'view');

        $items = $this->getList();

        return view('front::RouteSchedules.modal')->with(compact('items'));
    }

    public function table()
    {
        $this->checkException('routes', 'view');

        $items = $this->getList();

        return view('front::RouteSchedules.table')->with(compact('items'));
    }

    private function getList()
    {
        $sort = $this->data['sorting'] ?? [];

        return RouteSchedule::with(['route', 'devices'])
            ->where('user_id', $this->user->id)
            ->search($this->data['search_phrase'] ?? null)
            ->toPaginator(15, $sort['sort_by'] ?? 'name', $sort['sort'] ?? 'asc');
    }

    public function create()
    {
        $this->checkException('routes', 'store');

        return view('front::RouteSchedules.create')->with($this->formData());
    }

    public function store()
    {
        $this->checkException('routes', 'store');

        RouteScheduleFormValidator::validate('create', $this->data);

        $ruta = $this->findRoute($this->data['route_id']);

        $item = RouteSchedule::create($this->attributes() + [
            'user_id'  => $this->user->id,
            'route_id' => $ruta->id,
        ]);

        $item->devices()->sync($this->deviceIds());

        return ['status' => 1];
    }

    public function edit($id)
    {
        $item = $this->findOrFail($id);

        // El permiso se comprueba contra la RUTA: quien puede editarla puede
        // editar su agenda. Manager::check exige el modelo para 'edit'.
        $this->checkException('routes', 'edit', $item->route);

        $item->load('devices');

        return view('front::RouteSchedules.edit')->with($this->formData() + compact('item'));
    }

    public function update($id)
    {
        $item = $this->findOrFail($id);

        $this->checkException('routes', 'edit', $item->route);

        RouteScheduleFormValidator::validate('update', $this->data);

        $ruta = $this->findRoute($this->data['route_id']);

        $item->update($this->attributes() + ['route_id' => $ruta->id]);
        $item->devices()->sync($this->deviceIds());

        return ['status' => 1];
    }

    public function destroy($id = null)
    {
        foreach ((array) ($id ?? Arr::get($this->data, 'items', [])) as $one) {
            $item = $this->findOrFail($one);

            $this->checkException('routes', 'remove', $item->route);

            $item->delete();
        }

        return ['status' => 1];
    }

    /** Datos comunes de los dos formularios. */
    private function formData(): array
    {
        return [
            'routes'  => Route::userOwned($this->user)->orderBy('name')->pluck('name', 'id'),
            'devices' => $this->user->devices()->orderBy('name')->pluck('name', 'devices.id'),
            'days'    => self::weekdays(),
        ];
    }

    /** Dias ISO-8601, que es como los compara el resto del sistema. */
    public static function weekdays(): array
    {
        return [
            1 => trans('front.monday'),
            2 => trans('front.tuesday'),
            3 => trans('front.wednesday'),
            4 => trans('front.thursday'),
            5 => trans('front.friday'),
            6 => trans('front.saturday'),
            7 => trans('front.sunday'),
        ];
    }

    private function attributes(): array
    {
        $vuelta = Arr::get($this->data, 'return_start_time');

        return [
            'name'              => $this->data['name'],
            'weekdays'          => implode(',', array_map('intval', (array) $this->data['weekdays'])),
            'start_time'        => $this->data['start_time'] . ':00',
            // Vacio = programacion de solo ida, no "medianoche"
            'return_start_time' => $vuelta ? $vuelta . ':00' : null,
            'tolerance_early'   => (int) $this->data['tolerance_early'],
            'tolerance_late'    => (int) $this->data['tolerance_late'],
            'active'            => ! empty($this->data['active']),
        ];
    }

    /** Solo vehiculos del usuario: el id llega del formulario, no es de fiar. */
    private function deviceIds(): array
    {
        $pedidos = array_map('intval', (array) Arr::get($this->data, 'devices', []));

        if (! $pedidos) {
            return [];
        }

        return $this->user->devices()
            ->whereIn('devices.id', $pedidos)
            ->pluck('devices.id')
            ->all();
    }

    private function findRoute($id): Route
    {
        $ruta = Route::userOwned($this->user)->find($id);

        if (! $ruta) {
            throw new ResourseNotFoundException(trans('front.route'));
        }

        return $ruta;
    }

    private function findOrFail($id): RouteSchedule
    {
        $item = RouteSchedule::where('user_id', $this->user->id)->find($id);

        if (! $item) {
            throw new ResourseNotFoundException(trans('front.route_schedule'));
        }

        return $item;
    }
}
