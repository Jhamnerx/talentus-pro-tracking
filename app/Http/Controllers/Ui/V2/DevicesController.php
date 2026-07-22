<?php

namespace App\Http\Controllers\Ui\V2;

use App\Transformers\Device\DeviceMapTransformer;
use CustomFacades\GeoLocation;
use CustomFacades\Repositories\DeviceRepo;
use ModalHelpers\DeviceModalHelper;
use Tobuli\Entities\File\DeviceCameraMedia;
use FractalTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · DevicesController
 * -----------------------------------------------------------------
 * Owns every endpoint that lives under /talentus for the *device*
 * domain: the Live Ops map, the Fleet table, the data feeds that
 * back them, and the lightweight CRUD modal shared by Live, Fleet
 * and Admin → Fleet.
 *
 * The heavy CRUD logic is delegated to the legacy DeviceModalHelper
 * so we keep validation, permissions and side effects identical to
 * the legacy form. The controller only translates HTTP ↔ ModalHelper.
 * -----------------------------------------------------------------
 */
class DevicesController extends BaseController
{
    /**
     * Live Ops page (operational map + virtualized device list).
     */
    public function live(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403, 'No tienes permiso para ver dispositivos.');
        }

        return View::make('ui.v2.pages.live', [
            'pageTitle'     => 'Live Ops · Mapa',
            'socketPort'    => config('server.socket_port', env('SOCKET_PORT', 3000)),
            'socketSslPort' => config('server.socket_ssl_port', env('SOCKET_SSL_PORT', 3001)),
            'mapConfig'     => $this->buildMapConfig(),
            'canCreate'     => $this->user->perm('devices', 'edit'),
        ]);
    }

    /**
     * Live feed (polled by the JS client every ~15s).
     * Returns full device + last position payloads.
     */
    public function liveData(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['data' => [], 'meta' => ['pagination' => ['total' => 0]]], 403);
        }

        // Paginación estándar (length-aware): expone el TOTAL real en
        // meta.pagination.total (mismo conteo que /admin/users/objects) y
        // page links para que el cliente cargue TODAS las páginas (>1000).
        $perPage = min(2000, max(100, (int) $request->get('limit', 500)));
        $page    = max(1, (int) $request->get('page', 1));

        // NB: sin ->wasConnected() a propósito → el conteo refleja TODAS las
        // unidades del usuario (devices()->count(), igual que el total del
        // admin), incluidas las que aún no han reportado (se muestran offline).
        $devices = $this->user
            ->devices()
            ->filter($request->all())
            ->clearOrdersBy()
            ->paginate($perPage, ['*'], 'page', $page);

        $payload = FractalTransformer::paginate($devices, DeviceMapTransformer::class)->toArray();

        if (! empty($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as &$row) {
                if (isset($row['id'])) {
                    $row['channel'] = md5('device_' . $row['id']);
                }
            }
            unset($row);
        }

        // Grupos del usuario (id → título) para agrupar la lista como el legacy.
        // Solo en la primera página (no cambian entre páginas). El group_id de
        // cada device viene del pivot vía DeviceMapTransformer.
        if ($page === 1) {
            $payload['groups'] = $this->user->deviceGroups()
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(function ($g) {
                    return ['id' => (int) $g->id, 'title' => $g->title];
                })
                ->all();
        }

        return response()->json($payload);
    }

    /**
     * Fleet page (virtualized table).
     */
    public function fleet(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403, 'No tienes permiso para ver la flota.');
        }

        return View::make('ui.v2.pages.fleet', [
            'pageTitle' => 'Fleet',
            'canCreate' => $this->user->perm('devices', 'edit'),
        ]);
    }

    /**
     * Fleet data feed (paginated, filterable).
     */
    public function fleetData(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $limit  = min(500, max(25, (int) $request->get('limit', 100)));
        $page   = max(1, (int) $request->get('page', 1));
        $q      = trim((string) $request->get('q', ''));
        $status = (string) $request->get('status', '');

        $query = $this->user->devices()->wasConnected()->clearOrdersBy();

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('devices.name', 'like', '%' . $q . '%')
                    ->orWhere('devices.imei', 'like', '%' . $q . '%')
                    ->orWhere('devices.plate_number', 'like', '%' . $q . '%')
                    ->orWhere('devices.vin', 'like', '%' . $q . '%');
            });
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('devices.name', 'asc')
            ->forPage($page, $limit)
            ->get();

        $items = [];
        foreach ($rows as $device) {
            $st = $device->getStatus();
            if ($status !== '' && $this->groupStatus($st) !== $status) {
                continue;
            }

            $items[] = [
                'id'              => $device->id,
                'name'            => $device->name,
                'imei'            => $device->imei,
                'plate'           => $device->plate_number,
                'model'           => $device->device_model,
                'sim'             => $device->sim_number,
                'icon_color'      => $device->getStatusColor($st),
                'status'          => $st,
                'status_group'    => $this->groupStatus($st),
                'active'          => (bool) $device->active,
                'speed'           => (float) $device->getSpeed(),
                'engine_hours'    => (float) ($device->engine_hours ?? 0),
                'moved_at'        => $device->moved_at,
                'expiration_date' => $device->expiration_date,
                // Flags para el menú de acciones (template estático + data-need):
                'vt'              => (bool) $device->vehicle_type_id,
                'chat'            => (bool) $device->canChat(),
                'plans'           => (bool) $device->isPlanAble(),
                'app_uuid'        => ! empty($device->app_uuid),
                'general'         => (bool) $device->isGeneral(),
                'video'           => in_array($device->protocol, ['jt808', 'howen']) && $device->traccar_device_id,
            ];
        }

        return response()->json([
            'ok'    => true,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
            'items' => $items,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    /* =================================================================
     * CRUD modal (shared by Live, Fleet, Admin → Fleet)
     * =================================================================
     */


    /**
     * Render server-side form HTML (create + edit) reusing legacy
     * DeviceModalHelper::createData()/editData() catalogs. The JS
     * modal just injects this HTML and wires submit/tabs — no
     * templating duplicated client-side.
     */
    public function form(Request $request, $id = null): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        try {
            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setApi(false);

            if ($id) {
                $helper->setData(['id' => (int) $id]);
                $data = $helper->editData();

                // Tab Sensores: lista server-rendered (se re-hidrata por AJAX tras cada CRUD).
                $data['sensors_list'] = \Tobuli\Entities\DeviceSensor::where('device_id', (int) $id)
                    ->orderBy('id', 'desc')
                    ->get()
                    ->map(function ($s) {
                        return ['id' => (int) $s->id, 'name' => (string) $s->name, 'type' => (string) $s->type_title];
                    })
                    ->all();

                $data['services_list'] = $this->presentDeviceServices((int) $id);
                $data['cameras_list']  = $this->presentDeviceCameras((int) $id);
                $data['expenses_list'] = $this->presentDeviceExpenses((int) $id);
            } else {
                $helper->setData([]);
                $data = $helper->createData();
            }

            // Catálogo de tipos de gasto (para el form nativo del tab Gastos).
            // La columna es `name` (el legacy hace pluck('name','id')).
            $data['expenses_types_list'] = function_exists('expensesTypesExist') && expensesTypesExist()
                ? \Tobuli\Entities\DeviceExpensesType::orderBy('name')->get()
                    ->map(function ($t) {
                        return ['id' => (int) $t->id, 'title' => (string) $t->name];
                    })
                    ->all()
                : [];

            // Caps for conditional tabs (mirror Talentus permission gates).
            $data['caps'] = [
                'is_admin'             => (bool) $this->user->isAdmin(),
                'can_edit_users'       => (bool) ($this->user->isAdmin() && $this->user->perm('users', 'view')),
                'can_view_sensors'     => (bool) $this->user->isAdmin(),
                'can_view_cameras'     => (bool) $this->user->perm('device_camera', 'view'),
                'has_expenses'         => (bool) (function_exists('expensesTypesExist') && expensesTypesExist()),
                'can_view_webservices' => (bool) (
                    $this->user->perm('webservice.consatel',   'view')
                    || $this->user->perm('webservice.mininter', 'view')
                    || $this->user->perm('webservice.sutran',   'view')
                    || $this->user->perm('webservice.osinergmin', 'view')
                    || $this->user->perm('webservice.wisetrack', 'view')
                    || $this->user->perm('webservice.sidersales', 'view')
                    || $this->user->perm('webservice.epscenter', 'view')
                ),
            ];

            $html = View::make('ui.v2.modals.devices.form', $data)->render();

            return response()->json([
                'ok'   => true,
                'id'   => $id ? (int) $id : null,
                'html' => $html,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a device (mirrors legacy DeviceModalHelper::create()).
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso para crear vehículos.'], 403);
        }

        try {
            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setData($request->all());
            $helper->setApi(false);

            $result = $helper->create();

            return response()->json([
                'ok'   => true,
                'id'   => (int) ($result['id'] ?? 0),
                'item' => $this->presentDevice((int) ($result['id'] ?? 0)),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Update a device (mirrors legacy DeviceModalHelper::edit()).
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso para editar vehículos.'], 403);
        }

        try {
            $data = $request->all();
            $data['id'] = (int) $id;

            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setData($data);
            $helper->setApi(false);

            $result = $helper->edit();

            return response()->json([
                'ok'   => true,
                'id'   => (int) ($result['id'] ?? $id),
                'item' => $this->presentDevice((int) ($result['id'] ?? $id)),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a device.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'remove')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso para eliminar vehículos.'], 403);
        }

        try {
            $helper = app(DeviceModalHelper::class);
            $helper->setUser($this->user);
            $helper->setData(['id' => (int) $id]);
            $helper->setApi(false);

            $helper->destroy();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* =================================================================
     * Sensores del modal device (tab Sensores)
     * -----------------------------------------------------------------
     * CRUD delegado 100% en el SensorModalHelper legacy (createData /
     * editData / create / edit / destroy / preview): misma validación,
     * mismos permisos (checkException devices edit/update) y mismos
     * name= que el form legacy. Este bloque solo traduce HTTP ↔ helper.
     * ================================================================= */

    /**
     * Resuelve el device y verifica permiso de edición (el helper legacy
     * no valida ownership en create/list, así que lo hacemos aquí).
     */
    private function sensorDevice(int $deviceId)
    {
        $device = DeviceRepo::find($deviceId);
        if (! $device) {
            abort(404);
        }

        $this->checkException('devices', 'edit', $device);

        return $device;
    }

    private function sensorHelper(array $data): \ModalHelpers\SensorModalHelper
    {
        $helper = app(\ModalHelpers\SensorModalHelper::class);
        $helper->setUser($this->user);
        $helper->setData($data);
        $helper->setApi(false);

        return $helper;
    }

    /**
     * Lista de sensores del device (re-hidrata la tabla del tab).
     */
    public function sensorsList(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        $rows = \Tobuli\Entities\DeviceSensor::where('device_id', (int) $id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($s) {
                return ['id' => (int) $s->id, 'name' => (string) $s->name, 'type' => (string) $s->type_title];
            })
            ->all();

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    /**
     * HTML del form de sensor (crear). GET devices/{id}/sensors/form
     */
    public function sensorFormCreate(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        $data = $this->sensorHelper([])->createData((int) $id);
        $data['item'] = null;

        return response()->json([
            'ok'   => true,
            'html' => View::make('ui.v2.modals.devices.sensor-form', $data)->render(),
        ]);
    }

    /**
     * HTML del form de sensor (editar). GET sensors/{id}/form
     * El helper resuelve el device desde el sensor y valida el permiso.
     */
    public function sensorFormEdit(Request $request, $id): JsonResponse
    {
        $data = $this->sensorHelper(['sensor_id' => (int) $id])->editData();

        return response()->json([
            'ok'   => true,
            'html' => View::make('ui.v2.modals.devices.sensor-form', $data)->render(),
        ]);
    }

    /**
     * Crear sensor. POST devices/{id}/sensors
     */
    public function sensorStore(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        try {
            $data = $request->all();
            $data['device_id'] = (int) $id;

            $this->sensorHelper($data)->create();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * Actualizar sensor. PUT sensors/{id}
     */
    public function sensorUpdate(Request $request, $id): JsonResponse
    {
        try {
            $data = $request->all();
            $data['sensor_id'] = (int) $id;

            $this->sensorHelper($data)->edit();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * Eliminar sensor. DELETE sensors/{id}
     */
    public function sensorDestroy(Request $request, $id): JsonResponse
    {
        $this->sensorHelper(['sensor_id' => (int) $id])->destroy();

        return response()->json(['ok' => true]);
    }

    /**
     * Vista previa del valor del sensor con un input de prueba.
     * POST sensors/preview
     */
    public function sensorPreview(Request $request): JsonResponse
    {
        try {
            $res = $this->sensorHelper($request->all())->preview();

            return response()->json([
                'ok'        => true,
                'value'     => $res['value'] ?? null,
                'formatted' => $res['formatted'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /* =================================================================
     * Servicios / Cámaras / Gastos del modal device
     * ================================================================= */

    private function presentDeviceServices(int $deviceId): array
    {
        return \Tobuli\Entities\DeviceService::where('device_id', $deviceId)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($s) {
                $expiration = '';
                try {
                    $expiration = (string) $s->expiration();
                } catch (\Throwable $e) {
                }

                return ['id' => (int) $s->id, 'name' => (string) $s->name, 'expiration' => $expiration];
            })
            ->all();
    }

    private function presentDeviceCameras(int $deviceId): array
    {
        return \Tobuli\Entities\DeviceCamera::where('device_id', $deviceId)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($c) {
                return [
                    'id'           => (int) $c->id,
                    'name'         => (string) $c->name,
                    'show_widget'  => (bool) $c->show_widget,
                    'ftp_username' => (string) ($c->ftp_username ?? ''),
                ];
            })
            ->all();
    }

    private function presentDeviceExpenses(int $deviceId): array
    {
        return \Tobuli\Entities\DeviceExpense::where('device_id', $deviceId)
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($e) {
                return [
                    'id'        => (int) $e->id,
                    'name'      => (string) $e->name,
                    'type_id'   => (int) $e->type_id,
                    'quantity'  => (float) $e->quantity,
                    'unit_cost' => (float) $e->unit_cost,
                    'total'     => (float) $e->total,
                    'date'      => (string) $e->date,
                    'supplier'  => (string) ($e->supplier ?? ''),
                    'buyer'     => (string) ($e->buyer ?? ''),
                    'additional' => (string) ($e->additional ?? ''),
                ];
            })
            ->all();
    }

    /** Lista de cámaras del device. GET devices/{id}/cameras */
    public function camerasList(Request $request, $id): JsonResponse
    {
        $this->cameraDevice((int) $id);

        return response()->json(['ok' => true, 'items' => $this->presentDeviceCameras((int) $id)]);
    }

    private function cameraDevice(int $deviceId)
    {
        if (! $this->user->perm('device_camera', 'view')) {
            abort(403);
        }

        $device = DeviceRepo::find($deviceId);
        if (! $device) {
            abort(404);
        }

        $this->checkException('devices', 'edit', $device);

        return $device;
    }

    /** Crear cámara. POST devices/{id}/cameras */
    public function cameraStore(Request $request, $id): JsonResponse
    {
        $this->cameraDevice((int) $id);

        if (! $this->user->perm('device_camera', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $name = trim((string) $request->get('name'));
        if ($name === '') {
            return response()->json(['ok' => false, 'errors' => ['name' => trans('validation.required', ['attribute' => trans('validation.attributes.name')])]], 422);
        }

        \Tobuli\Entities\DeviceCamera::create([
            'device_id'   => (int) $id,
            'name'        => $name,
            'show_widget' => (int) (bool) $request->get('show_widget'),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Actualizar cámara. PUT cameras/{id} */
    public function cameraUpdate(Request $request, $id): JsonResponse
    {
        $camera = \Tobuli\Entities\DeviceCamera::find((int) $id);
        if (! $camera) {
            abort(404);
        }

        $this->cameraDevice((int) $camera->device_id);

        if (! $this->user->perm('device_camera', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $name = trim((string) $request->get('name'));
        if ($name === '') {
            return response()->json(['ok' => false, 'errors' => ['name' => trans('validation.required', ['attribute' => trans('validation.attributes.name')])]], 422);
        }

        $camera->update([
            'name'        => $name,
            'show_widget' => (int) (bool) $request->get('show_widget'),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Eliminar cámara. DELETE cameras/{id} */
    public function cameraDestroy(Request $request, $id): JsonResponse
    {
        $camera = \Tobuli\Entities\DeviceCamera::find((int) $id);
        if (! $camera) {
            abort(404);
        }

        $this->cameraDevice((int) $camera->device_id);

        if (! $this->user->perm('device_camera', 'remove') && ! $this->user->perm('device_camera', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $camera->delete();

        return response()->json(['ok' => true]);
    }

    /** Lista de gastos del device. GET devices/{id}/expenses */
    public function expensesList(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        return response()->json(['ok' => true, 'items' => $this->presentDeviceExpenses((int) $id)]);
    }

    private function expenseInput(Request $request): array
    {
        $data = $request->only(['name', 'type_id', 'quantity', 'unit_cost', 'supplier', 'buyer', 'additional', 'date']);

        $validator = \Validator::make($data, [
            'name'      => 'required|string|max:255',
            'type_id'   => 'required|integer',
            'quantity'  => 'required|numeric',
            'unit_cost' => 'required|numeric',
            'date'      => 'required|date',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages());
        }

        // datetime-local llega como "YYYY-MM-DDTHH:mm" → formato MySQL
        $data['date'] = date('Y-m-d H:i:s', strtotime($data['date']));

        return $data;
    }

    /** Crear gasto. POST devices/{id}/expenses */
    public function expenseStore(Request $request, $id): JsonResponse
    {
        $this->sensorDevice((int) $id);

        try {
            $data = $this->expenseInput($request);
            $data['device_id'] = (int) $id;
            $data['user_id'] = (int) $this->user->id;

            \Tobuli\Entities\DeviceExpense::create($data);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /** Actualizar gasto. PUT expenses/{id} */
    public function expenseUpdate(Request $request, $id): JsonResponse
    {
        $expense = \Tobuli\Entities\DeviceExpense::find((int) $id);
        if (! $expense) {
            abort(404);
        }

        $this->sensorDevice((int) $expense->device_id);

        try {
            $expense->update($this->expenseInput($request));

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /** Eliminar gasto. DELETE expenses/{id} */
    public function expenseDestroy(Request $request, $id): JsonResponse
    {
        $expense = \Tobuli\Entities\DeviceExpense::find((int) $id);
        if (! $expense) {
            abort(404);
        }

        $this->sensorDevice((int) $expense->device_id);

        $expense->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Lightweight presentation of a device for refreshing UI rows
     * after a create / update without re-fetching the whole list.
     */
    protected function presentDevice(int $id): array
    {
        $device = DeviceRepo::find($id);
        if (! $device) {
            return [];
        }
        $st = $device->getStatus();
        return [
            'id'           => (int) $device->id,
            'name'         => (string) $device->name,
            'imei'         => (string) $device->imei,
            'plate'        => (string) $device->plate_number,
            'model'        => (string) $device->device_model,
            'sim'          => (string) $device->sim_number,
            'icon_color'   => $device->getStatusColor($st),
            'status'       => $st,
            'status_group' => $this->groupStatus($st),
            'active'       => (bool) $device->active,
        ];
    }

    /**
     * Device detail widgets for the Live drawer.
     *
     * Server-renders the new ui/v2 widget cards reusing legacy data
     * sources (GeoLocation, events, cameras, services, driver, sensors)
     * and honoring the user's active-widget configuration
     * (getSettings('widgets'), same as the legacy bottombar).
     */
    public function widgets(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false, 'message' => 'Vehículo no encontrado.'], 404);
        }

        // Active widgets per user config (fallback to global settings) — mirrors Widgets/index.blade.
        $cfg = $this->user->getSettings('widgets');
        if (empty($cfg) || ! empty($cfg['default'])) {
            $cfg = settings('widgets');
        }
        $active = (! empty($cfg['list']) && is_array($cfg['list'])) ? array_values($cfg['list']) : [];

        $has = function ($key) use ($active) {
            return in_array($key, $active, true) && config("lists.widgets.$key");
        };

        $data = ['device' => $device, 'active' => $active];

        if ($has('location')) {
            try {
                $loc = GeoLocation::byCoordinates($device->lat, $device->lng);
                $data['location'] = $loc ? $loc->toArray() : null;
            } catch (\Throwable $e) {
                $data['location'] = null;
            }
        }

        if ($has('sensors')) {
            // Mismo cálculo que el legacy (DeviceSensorsTransformer): valor actual
            // desde la última posición (traccar), con fallback al valor almacenado.
            $rows = [];
            $traccar = $device->traccar;
            foreach ($device->sensors as $sensor) {
                $formatted = '-';
                try {
                    if ($traccar) {
                        $formatted = $sensor->getValueCurrent($traccar)->getFormatted();
                    }
                } catch (\Throwable $e) {
                    $formatted = '-';
                }
                $rows[] = [
                    'name'     => $sensor->formatName(),
                    'formated' => $formatted,
                ];
            }
            $data['sensors'] = $rows;
        }

        if ($has('image')) {
            $data['image'] = $device->image ? url($device->image) : null;
        }

        if ($has('services')) {
            try {
                $data['services'] = $device->services()->get();
            } catch (\Throwable $e) {
                $data['services'] = collect();
            }
        }

        if ($has('recent_events') && $this->user->perm('events', 'view')) {
            try {
                $data['recent_events'] = $device->events()
                    ->where('user_id', $this->user->id)
                    ->latest()->limit(10)->get();
            } catch (\Throwable $e) {
                $data['recent_events'] = collect();
            }
        }

        if ($has('streetview') && $device->lat && $device->lng) {
            $key = settings('main_settings.streetview_key') ?: settings('main_settings.google_maps_key');
            $data['streetview'] = $key
                ? 'https://maps.googleapis.com/maps/api/streetview?size=600x240&fov=90&location='
                    . $device->lat . ',' . $device->lng . '&key=' . $key
                : null;
        }

        if ($has('camera')) {
            try {
                $images = [];
                foreach ($device->deviceCameras()->showWidget()->get() as $camera) {
                    $img = DeviceCameraMedia::setEntity($camera)->findLatest();
                    if ($img) {
                        $images[$camera->id] = ['camera_name' => $camera->name, 'image' => $img];
                    }
                }
                $data['camera_images']       = $images;
                $data['camera_device_image'] = DeviceCameraMedia::setEntity($device)->setRecursiveSearch(false)->findLatest();
            } catch (\Throwable $e) {
                $data['camera_images']       = [];
                $data['camera_device_image'] = null;
            }
        }

        if ($has('driver')) {
            $data['driver'] = $device->driver; // hasOne UserDriver (current_driver_id)
        }

        if ($has('live_video')) {
            $data['video_live_url']     = url('/device_video/' . $device->id . '/live/modal');
            $data['video_playback_url'] = url('/device_video/' . $device->id . '/playback/modal');
        }

        $html = View::make('ui.v2.partials.device-widgets', $data)->render();

        return response()->json(['ok' => true, 'id' => (int) $device->id, 'html' => $html]);
    }

    // NOTA: el menú de acciones del kebab ya NO usa AJAX. Es un TEMPLATE
    // estático de sesión (ui.v2.partials.device-actions dentro de
    // <template> en live.blade): los permisos se resuelven al renderizar la
    // página y los flags por device (vt/chat/sim/plans/app_uuid/general/
    // video) viajan en el payload de liveData; live.js clona y filtra.

    /**
     * Incremental device updates (delta) — espeja el legacy objects/items_json.
     * Reutiliza DeviceModalHelper::itemsJson(): devuelve SOLO los dispositivos
     * que cambiaron desde ?time= (vía ->updatedAfter) + eventos nuevos + el
     * nuevo `time` del servidor. El JS hace polling y fusiona los deltas en el
     * mapa en vivo (posición, estado, engine, stop_duration, total_distance…).
     */
    public function updates(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['items' => [], 'events' => [], 'time' => time()], 403);
        }

        $helper = app(DeviceModalHelper::class);
        $helper->setUser($this->user);
        $helper->setApi(false);
        $helper->setData([
            'time'    => $request->get('time'),
            'filters' => $request->get('filters'),
        ]);

        return response()->json($helper->itemsJson());
    }
}
