<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\DeviceExpense;
use Tobuli\Entities\DeviceExpensesType;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · ExpensesController (ui/v2)
 * -----------------------------------------------------------------
 * Gastos de flota (fleet-wide) — equivalente al Tools "Gastos" legacy
 * (DeviceExpensesController@index sin device_id). Reutiliza la entidad
 * DeviceExpense (total = quantity * unit_cost). CRUD por AJAX + JSON.
 * -----------------------------------------------------------------
 */
class ExpensesController extends BaseController
{
    private function gate(string $action): void
    {
        if (! $this->user->perm('device_expenses', $action)) {
            abort(403);
        }
    }

    public function page()
    {
        $this->gate('view');

        return View::make('ui.v2.pages.expenses');
    }

    /** Lista paginada de gastos de la flota, con filtros. */
    public function data(Request $request): JsonResponse
    {
        $this->gate('view');

        $q = DeviceExpense::userAccessible($this->user)
            ->with(['device:id,name', 'type:id,name']);

        if ($s = trim((string) $request->get('s'))) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%$s%")
                    ->orWhere('supplier', 'like', "%$s%")
                    ->orWhere('buyer', 'like', "%$s%");
            });
        }
        if ($deviceId = (int) $request->get('device_id')) {
            $q->where('device_id', $deviceId);
        }
        if ($from = $request->get('date_from')) {
            $q->where('date', '>=', date('Y-m-d 00:00:00', strtotime($from)));
        }
        if ($to = $request->get('date_to')) {
            $q->where('date', '<=', date('Y-m-d 23:59:59', strtotime($to)));
        }

        $paginator = $q->orderBy('date', 'desc')->paginate(20);

        $rows = [];
        foreach ($paginator->items() as $e) {
            $rows[] = [
                'id'          => (int) $e->id,
                'device_id'   => (int) $e->device_id,
                'device_name' => $e->device->name ?? ('#' . $e->device_id),
                'name'        => (string) $e->name,
                'type_id'     => $e->type_id ? (int) $e->type_id : null,
                'type'        => $e->type->name ?? null,
                'quantity'    => (float) $e->quantity,
                'unit_cost'   => (float) $e->unit_cost,
                'total'       => (float) $e->total,
                'supplier'    => (string) ($e->supplier ?? ''),
                'buyer'       => (string) ($e->buyer ?? ''),
                'additional'  => (string) ($e->additional ?? ''),
                'date'        => (string) $e->date,
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $paginator->currentPage(),
            'pages' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    /** Dropdowns: vehículos del usuario + tipos de gasto. */
    public function meta(): JsonResponse
    {
        $this->gate('view');

        $devices = $this->user->devices()
            ->clearOrdersBy()
            ->orderBy('devices.name')
            ->get(['devices.id', 'devices.name'])
            ->map(function ($d) {
                return ['id' => (int) $d->id, 'name' => (string) $d->name];
            })
            ->all();

        $types = DeviceExpensesType::orderBy('name')->get(['id', 'name'])
            ->map(function ($t) {
                return ['id' => (int) $t->id, 'name' => (string) $t->name];
            })
            ->all();

        return response()->json(['ok' => true, 'devices' => $devices, 'types' => $types]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate('edit');

        try {
            $data = $this->input($request);
            $data['user_id'] = (int) $this->user->id;

            DeviceExpense::create($data);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->gate('edit');

        $item = DeviceExpense::userAccessible($this->user)->find((int) $id);
        if (! $item) {
            return response()->json(['ok' => false, 'message' => trans('ui.expense_not_found')], 404);
        }

        try {
            $item->update($this->input($request));

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy($id): JsonResponse
    {
        $this->gate('remove');

        DeviceExpense::userAccessible($this->user)->where('id', (int) $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Valida y normaliza el input del formulario (device del form, no de la URL). */
    private function input(Request $request): array
    {
        $data = $request->only([
            'device_id', 'name', 'type_id', 'quantity', 'unit_cost', 'supplier', 'buyer', 'additional', 'date',
        ]);

        $validator = \Validator::make($data, [
            'device_id' => 'required|integer',
            'name'      => 'required|string|max:255',
            'type_id'   => 'nullable|integer',
            'quantity'  => 'required|numeric',
            'unit_cost' => 'required|numeric',
            'date'      => 'required|date',
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator->messages());
        }

        // El vehículo debe pertenecer al usuario.
        $owns = $this->user->devices()->where('devices.id', (int) $data['device_id'])->exists();
        if (! $owns) {
            $msg = new \Illuminate\Support\MessageBag(['device_id' => [trans('ui.invalid_vehicle')]]);
            throw new ValidationException($msg);
        }

        $data['device_id'] = (int) $data['device_id'];
        $data['type_id']   = ! empty($data['type_id']) ? (int) $data['type_id'] : null;
        $data['date']      = date('Y-m-d H:i:s', strtotime($data['date']));

        return $data;
    }
}
