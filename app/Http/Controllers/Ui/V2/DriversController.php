<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use ModalHelpers\UserDriverModalHelper;
use CustomFacades\Validators\UserDriverFormValidator;
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

        $groups = $this->user->deviceGroups()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(function ($g) {
                return ['id' => (int) $g->id, 'title' => (string) $g->title];
            })
            ->all();

        $devices = $this->user->devices()
            ->clearOrdersBy()
            ->orderBy('name')
            ->get()
            ->map(function ($d) {
                return [
                    'id'       => (int) $d->id,
                    'name'     => (string) $d->name,
                    'group_id' => (int) ($d->pivot->group_id ?? 0),
                ];
            })
            ->all();

        $out = ['ok' => true, 'groups' => $groups, 'devices' => $devices, 'item' => null];

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

    /**
     * Registro de actividad del conductor (paridad con el modal legacy
     * user_drivers.activity_log): MISMA consulta de
     * UserDriverModalHelper::activityLog() (pivot user_driver_position_pivot,
     * fin = siguiente cambio de conductor en ese device), mismo filtro por
     * inicio desde/hasta y la misma paginación (15 por página).
     */
    public function activityLog(Request $request, $id): JsonResponse
    {
        $filter = [];
        foreach (['start_from', 'start_to'] as $k) {
            $v = trim((string) $request->get($k, ''));
            if ($v !== '') {
                // datetime-local (YYYY-MM-DDTHH:MM) → formato del legacy
                $v = str_replace('T', ' ', $v);
                $filter[$k] = strlen($v) === 16 ? $v . ':00' : $v;
            }
        }

        $data = $this->helper(['filter' => $filter])->activityLog((int) $id);
        $logs = $data['logs'];

        $rows = [];
        foreach ($logs as $log) {
            $rows[] = [
                'device' => (string) ($log->device ?? ''),
                'start'  => \Formatter::time()->human($log->start),
                'end'    => \Formatter::time()->human($log->end),
            ];
        }

        return response()->json([
            'ok'     => true,
            'driver' => (string) ($data['driver']->name ?? ''),
            'items'  => $rows,
            'page'   => $logs->currentPage(),
            'last'   => $logs->lastPage(),
            'total'  => $logs->total(),
            'per'    => $logs->perPage(),
        ]);
    }

    /**
     * Edición múltiple: los campos del editMultiple.blade.php legacy
     * (nombre, RFID, teléfono, email, descripción; el device_id está
     * comentado allí y aquí tampoco se toca). Cada conductor pasa el
     * permiso 'drivers'/'update' y las reglas 'update' de
     * UserDriverFormValidator (nombre obligatorio, email, RFID único por
     * usuario). Todo o nada: si una fila falla no se guarda ninguna.
     * No toca dispositivos asignados ni conductor actual.
     */
    public function updateMultiple(Request $request): JsonResponse
    {
        // Mismo permiso que muestra el botón (canEdit); cada conductor pasa
        // además su checkException('drivers', 'update', $driver).
        if (! $this->user->perm('drivers', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $items = array_values(array_filter((array) $request->input('items', []), 'is_array'));
        $fields = ['name', 'rfid', 'phone', 'email', 'description'];

        if (! $items) {
            return response()->json(['ok' => false, 'message' => trans('front.nothing_found_request')], 422);
        }

        beginTransaction();
        try {
            foreach ($items as $i => $row) {
                $driver = UserDriver::find((int) ($row['id'] ?? 0));
                $this->checkException('drivers', 'update', $driver);

                $data = [];
                foreach ($fields as $f) {
                    // '' como en los formularios legacy: así la regla
                    // 'email' no salta con el campo vacío.
                    $data[$f] = isset($row[$f]) ? trim((string) $row[$f]) : '';
                }

                try {
                    UserDriverFormValidator::validate('update', $data, $driver->id);
                } catch (ValidationException $e) {
                    rollbackTransaction();
                    $errors = [];
                    foreach (($e->getErrors() ? $e->getErrors()->toArray() : []) as $k => $msgs) {
                        $errors["items.$i.$k"] = $msgs;
                    }

                    return response()->json(['ok' => false, 'errors' => $errors, 'row' => $i], 422);
                }

                $driver->update($data);
            }
        } catch (\Throwable $e) {
            rollbackTransaction();
            throw $e;
        }
        commitTransaction();

        return response()->json(['ok' => true, 'count' => count($items)]);
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
