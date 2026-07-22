<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use ModalHelpers\UserDriverModalHelper;
use Tobuli\Entities\UserDriver;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · DriversController (ui/v2)
 * -----------------------------------------------------------------
 * Conductores (UserDrivers). CRUD delegado 100% en el
 * UserDriverModalHelper legacy (validación, sync del pivot
 * user_driver_devices vía UserDevicesGroupLoader — la selección viaja
 * como secuencia selected_devices[] + devices[] ints para el
 * validador — y "vehículo actual" con current + device_id →
 * Device::changeDriver / UserDriver::changeDevice).
 * Permisos: módulo 'drivers' (igual que el legacy).
 * -----------------------------------------------------------------
 */
class DriversController extends BaseController
{
    public function page(Request $request)
    {
        if (! $this->user->perm('drivers', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.drivers', [
            'pageTitle' => trans('front.drivers'),
            'canEdit'   => (bool) $this->user->perm('drivers', 'edit'),
            'canRemove' => (bool) $this->user->perm('drivers', 'remove'),
        ]);
    }

    /** Lista paginada (búsqueda + filtro con/sin dispositivos asignados). */
    public function data(Request $request): JsonResponse
    {
        $this->checkException('drivers', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = UserDriver::userAccessible($this->user)->with(['device', 'devices']);

        if ($s = trim((string) $request->get('s'))) {
            $query->search($s);
        }

        // Filtro por asignación (pivot user_driver_devices)
        switch ($request->get('assigned')) {
            case 'with':
                $query->whereHas('devices');
                break;
            case 'without':
                $query->whereDoesntHave('devices');
                break;
        }

        $items = $query->orderBy('id', 'desc')->paginate($perPage);

        $rows = [];
        foreach ($items as $driver) {
            $rows[] = $this->present($driver);
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** Catálogo de devices (+ item si edit). */
    public function meta(Request $request): JsonResponse
    {
        $this->checkException('drivers', 'view');

        $devices = $this->user->devices()
            ->clearOrdersBy()
            ->orderBy('name')
            ->get(['devices.id', 'devices.name'])
            ->map(function ($d) {
                return ['id' => (int) $d->id, 'name' => (string) $d->name];
            })
            ->all();

        $out = ['ok' => true, 'devices' => $devices, 'item' => null];

        if ($id = (int) $request->get('id')) {
            $driver = UserDriver::userAccessible($this->user)->find($id);
            if (! $driver) {
                abort(404);
            }

            $out['item'] = [
                'id'          => (int) $driver->id,
                'name'        => (string) $driver->name,
                'rfid'        => (string) ($driver->rfid ?? ''),
                'phone'       => (string) ($driver->phone ?? ''),
                'email'       => (string) ($driver->email ?? ''),
                'description' => (string) ($driver->description ?? ''),
                'device_id'   => $driver->device_id ? (int) $driver->device_id : null,
                'device_ids'  => $driver->devices()->pluck('devices.id')->map(function ($v) {
                    return (int) $v;
                })->all(),
            ];
        }

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->helper($request->all())->create();

            return response()->json(['ok' => true, 'id' => (int) ($result['item']->id ?? 0)]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $data = $request->all();
            $data['id'] = (int) $id;

            $this->helper($data)->edit();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $this->helper(['id' => (int) $id])->destroy();

        return response()->json(['ok' => true]);
    }

    /* ----------------------------------------------------------------- */

    private function helper(array $data): UserDriverModalHelper
    {
        $helper = app(UserDriverModalHelper::class);
        $helper->setUser($this->user);
        $helper->setData($data);
        $helper->setApi(false);

        return $helper;
    }

    private function present(UserDriver $driver): array
    {
        return [
            'id'             => (int) $driver->id,
            'name'           => (string) $driver->name,
            'current_device' => (string) ($driver->device->name ?? ''),
            'rfid'           => (string) ($driver->rfid ?? ''),
            'phone'          => (string) ($driver->phone ?? ''),
            'email'          => (string) ($driver->email ?? ''),
            'description'    => (string) ($driver->description ?? ''),
            'devices'        => $driver->devices->pluck('name')->implode(', '),
        ];
    }
}
