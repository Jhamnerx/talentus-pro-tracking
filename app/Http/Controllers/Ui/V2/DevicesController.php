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
     * Monitoreo de grupo: grilla 3×3 de mini-mapas, cada celda sigue a un
     * vehículo elegido (selector agrupado). Reusa el feed de Live.
     */
    public function monitor(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403);
        }

        return \Illuminate\Support\Facades\View::make('ui.v2.pages.monitor', [
            'pageTitle' => 'Monitoreo de grupo',
            'mapConfig' => $this->buildMapConfig(),
        ]);
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
                // Campos personalizados: mismo gate que Devices/edit.blade del
                // legacy — can('view', $item, 'custom_fields') (que ya considera
                // el addon vía PermissionService) + que existan campos definidos.
                // Beacon: el modal reduce tabs/campos (paridad edit_beacon.blade)
                'is_beacon'            => (bool) (
                    isset($data['item'])
                    && $data['item'] instanceof \Tobuli\Entities\Device
                    && $data['item']->isBeacon()
                ) || $request->get('kind') === 'beacon',
                'beacons_plugin'       => (bool) settings('plugins.beacons.status'),
                'has_custom_fields'    => (bool) (
                    isset($data['item'])
                    && $data['item'] instanceof \Tobuli\Entities\Device
                    && $this->user->can('view', $data['item'], 'custom_fields')
                    && $data['item']->hasCustomFields()
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
        // Paridad legacy DevicesController@store: a los usuarios con el módulo
        // 'custom_device_add' se les PROHÍBE el alta estándar (deben pasar por
        // el flujo custom/pagos).
        if ($this->user->perm('custom_device_add', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

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

    /* =================================================================
     * Beacons (plugin beacons) — paridad BeaconsController
     * -----------------------------------------------------------------
     * Un beacon es un Device con kind = KIND_BEACON. No pasa por
     * DeviceModalHelper (su edit() rechaza beacons explícitamente): se
     * valida con BeaconFormValidator y se guarda con DeviceService.
     * ================================================================= */

    /** El plugin manda: sin él, el legacy responde 404 desde el constructor. */
    private function assertBeaconsEnabled(): void
    {
        if (! settings('plugins.beacons.status')) {
            abort(404);
        }
    }

    /** Colores admitidos por estado (mismo mapa que BeaconsController). */
    private function beaconIconColors(?\Tobuli\Entities\Device $item, array $data): array
    {
        $allowed = ['green', 'yellow', 'red', 'blue', 'orange', 'black'];

        $colors = $item
            ? $item->icon_colors + settings('device.status_colors.colors')
            : settings('device.status_colors.colors');

        foreach ($colors as $status => $color) {
            $key = "icon_$status";
            if (array_key_exists($key, $data) && in_array($data[$key], $allowed, true)) {
                $colors[$status] = $data[$key];
            }
        }

        return $colors;
    }

    public function storeBeacon(Request $request): JsonResponse
    {
        $this->assertBeaconsEnabled();
        $this->checkException('devices', 'store');

        // Mismo veto que el alta estándar y que BeaconsController@store.
        if ($this->user->perm('custom_device_add', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $data = $request->all();
            $data['group_id'] = empty($data['group_id']) ? null : $data['group_id'];
            $data['icon_colors'] = $this->beaconIconColors(null, $data);

            \CustomFacades\Validators\BeaconFormValidator::validate('create', $data);

            $data['kind'] = \Tobuli\Entities\Device::KIND_BEACON;

            $device = app(\Tobuli\Services\DeviceService::class)->create($data);

            return response()->json(['ok' => true, 'id' => (int) $device->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateBeacon(Request $request, $id): JsonResponse
    {
        $this->assertBeaconsEnabled();

        $beacon = \Tobuli\Entities\Device::kindBeacon()->find((int) $id);
        if (! $beacon) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('devices', 'edit', $beacon);

        try {
            $data = $request->all();
            $data['group_id'] = empty($data['group_id']) ? null : $data['group_id'];
            $data['icon_colors'] = $this->beaconIconColors($beacon, $data);

            \CustomFacades\Validators\BeaconFormValidator::validate('update', $data, $beacon->id);

            app(\Tobuli\Services\DeviceService::class)->update($beacon, $data);

            return response()->json(['ok' => true, 'id' => (int) $beacon->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
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

        // Candado anti-borrado. DIVERGE del legacy a propósito: el legacy compara contra la
        // clave FIJA del .env (addon.object_delete_pass) compartida por todos; la v2 valida la
        // CONTRASEÑA DE LOGIN del propio usuario (Hash::check contra su hash bcrypt). Aquí
        // addon.object_delete_pass funciona solo como interruptor on/off del candado.
        if (config('addon.object_delete_pass') && isAdmin()
            && ! \Hash::check((string) $request->input('password'), (string) $this->user->password)) {
            return response()->json(['ok' => false, 'message' => trans('front.login_failed')], 422);
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

    /**
     * Filtro avanzado del sidebar (paridad addon.object_filter + Objects/filters
     * legacy). Resuelve el CONJUNTO de IDs que pasan el filtro usando los MISMOS
     * scopes legacy (filterStatus/filterOnline/filterOffline vía ->filter(), igual
     * que DevicesSidebarController). El front lo aplica a la lista y/o al mapa
     * según filter_sidebar/filter_map — sin re-fetch de toda la lista.
     */
    public function filterIds(Request $request): JsonResponse
    {
        $filters = [];

        $status = array_values(array_filter(
            (array) $request->input('status', []),
            function ($s) { return $s !== '' && $s !== null; }
        ));
        if ($status) {
            $filters['status'] = $status;
        }
        if ($request->filled('online')) {
            $filters['online'] = (int) $request->input('online');
        }
        if ($request->filled('offline')) {
            $filters['offline'] = (int) $request->input('offline');
        }

        $ids = [];
        if ($filters) {
            $ids = $this->user->devices()
                ->filter($filters)
                ->pluck('devices.id')
                ->map(function ($id) { return (int) $id; })
                ->all();
        }

        return response()->json([
            'ok'       => true,
            'filtered' => (bool) $filters,
            'ids'      => $ids,
        ]);
    }

    /* =================================================================
     * Grupos de dispositivos (patrón espejo de los grupos de rutas v2)
     * -----------------------------------------------------------------
     * El grupo vive en user_device_pivot.group_id (POR USUARIO; 0 = sin
     * grupo). La "sincronización" es siempre un UPDATE del pivote
     * filtrado por user_id — nunca se insertan/borran filas (paridad con
     * DevicesGroupsController::syncDevices legacy). Gate: perm devices
     * edit (devices_groups no es módulo de permisos; solo ownership).
     * ================================================================= */

    public function storeDeviceGroup(Request $request): JsonResponse
    {
        // Gate legacy: 'devices_groups' está mapeado a DeviceGroup en el
        // Manager, así que resuelve por DeviceGroupPolicy (bloquea demo), no
        // por perm(). Antes se usaba perm('devices','edit') = gate distinto.
        $this->checkException('devices_groups', 'store');

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            return response()->json(['ok' => false, 'errors' => ['title' => trans('validation.required', ['attribute' => trans('validation.attributes.title')])]], 422);
        }

        $group = \Tobuli\Entities\DeviceGroup::create([
            'title'   => $title,
            'user_id' => $this->user->id,
            'open'    => 1,
        ]);

        $this->syncGroupDevices($group, $request->get('devices'));
        $this->syncGroupForwards($group, $request->get('forwards'));

        return response()->json(['ok' => true, 'id' => (int) $group->id]);
    }

    public function updateDeviceGroup(Request $request, $id): JsonResponse
    {
        $group = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)->find((int) $id);
        if (! $group) {
            abort(404);
        }

        $this->checkException('devices_groups', 'edit', $group);

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            return response()->json(['ok' => false, 'errors' => ['title' => trans('validation.required', ['attribute' => trans('validation.attributes.title')])]], 422);
        }

        $group->update(['title' => $title]);

        $this->syncGroupDevices($group, $request->get('devices'));
        $this->syncGroupForwards($group, $request->get('forwards'));

        return response()->json(['ok' => true]);
    }

    public function destroyDeviceGroup(Request $request, $id): JsonResponse
    {
        $group = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)->find((int) $id);
        if (! $group) {
            abort(404);
        }

        $this->checkException('devices_groups', 'remove', $group);

        // Paridad legacy: los devices vuelven a "Sin grupo" (0, no NULL)
        \DB::table('user_device_pivot')->where('group_id', $group->id)->update(['group_id' => 0]);
        $group->delete();

        return response()->json(['ok' => true]);
    }

    /** Ids del device del grupo (para precargar el modal de edición). */
    public function deviceGroupItems(Request $request, $id): JsonResponse
    {
        $group = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)->find((int) $id);
        if (! $group) {
            abort(404);
        }

        $ids = \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', $group->id)
            ->pluck('device_id')
            ->map(function ($v) {
                return (int) $v;
            })
            ->all();

        // Reenvíos asignados al grupo (pivote device_group_forward). Paridad
        // legacy: solo si el usuario tiene permiso 'forwards' view.
        $forwardIds = [];
        if ($this->user->perm('forwards', 'view')) {
            $forwardIds = $group->forwards()->pluck('forwards.id')
                ->map(function ($v) { return (int) $v; })
                ->all();
        }

        return response()->json([
            'ok'          => true,
            'title'       => (string) $group->title,
            'device_ids'  => $ids,
            'forward_ids' => $forwardIds,
        ]);
    }

    /** Lista de grupos del usuario con su conteo de vehículos (tab Grupos del modal de cuenta). */
    public function deviceGroupsList(Request $request): JsonResponse
    {
        $counts = \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', '>', 0)
            ->selectRaw('group_id, COUNT(*) as c')
            ->groupBy('group_id')
            ->pluck('c', 'group_id');

        $groups = \Tobuli\Entities\DeviceGroup::where('user_id', $this->user->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = [
                'id'    => (int) $g->id,
                'title' => (string) $g->title,
                'count' => (int) ($counts[$g->id] ?? 0),
            ];
        }

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    /* =================================================================
     * Estado de vehículo (tipo con estados: VACÍO/CARGADO…) — cambiador
     * rápido desde el drawer del device (botón/selector). El badge del
     * estado se pinta en la fila/marcador/drawer.
     * ================================================================= */

    /** Estados del TIPO del device + estado actual (para el selector). */
    public function vehicleStates(Request $request, $id): JsonResponse
    {
        $device = $this->user->devices()->find((int) $id);
        if (! $device) {
            abort(404);
        }

        $states   = [];
        $typeName = null;
        if ($device->vehicle_type_id && $device->vehicleType) {
            $typeName = (string) $device->vehicleType->name;
            $states = $device->vehicleType->states->map(function ($s) {
                return [
                    'id'    => (int) $s->id,
                    'name'  => (string) $s->name,
                    'label' => (string) ($s->label ?: $s->name),
                    'color' => (string) ($s->color ?: '#64748b'),
                ];
            })->all();
        }

        return response()->json([
            'ok'      => true,
            'type'    => $typeName,
            'states'  => $states,
            'current' => $device->vehicle_state_id ? (int) $device->vehicle_state_id : null,
        ]);
    }

    /** Cambia el estado (valida que pertenezca al tipo del device). */
    public function changeVehicleState(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $device = $this->user->devices()->find((int) $id);
        if (! $device) {
            abort(404);
        }

        $stateId = (int) $request->get('vehicle_state_id');
        $vs      = null;

        if ($stateId > 0) {
            $vs = \Tobuli\Entities\VehicleState::where('id', $stateId)
                ->where('vehicle_type_id', $device->vehicle_type_id)
                ->first();
            if (! $vs) {
                return response()->json(['ok' => false, 'message' => 'Estado inválido para el tipo del vehículo.'], 422);
            }
        }

        $device->vehicle_state_id = $stateId > 0 ? $stateId : null;
        $device->save();

        return response()->json([
            'ok'            => true,
            'vehicle_state' => $vs ? [
                'name'  => (string) $vs->name,
                'label' => (string) ($vs->label ?: $vs->name),
                'color' => (string) ($vs->color ?: '#64748b'),
            ] : null,
        ]);
    }

    /** UPDATE del pivote (attach → group_id; detach → 0), solo filas del usuario. */
    private function syncGroupDevices(\Tobuli\Entities\DeviceGroup $group, $deviceIds): void
    {
        if (! is_array($deviceIds)) {
            return; // sin clave devices → no tocar asignaciones
        }

        $ids = array_values(array_filter(array_map('intval', $deviceIds)));

        \DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', $group->id)
            ->when($ids, function ($q) use ($ids) {
                $q->whereNotIn('device_id', $ids);
            })
            ->update(['group_id' => 0]);

        if ($ids) {
            \DB::table('user_device_pivot')
                ->where('user_id', $this->user->id)
                ->whereIn('device_id', $ids)
                ->update(['group_id' => $group->id]);
        }
    }

    /**
     * Sincroniza los reenvíos del grupo (pivote device_group_forward).
     * Paridad legacy DevicesGroupsController: solo si el usuario tiene
     * permiso 'forwards' view; restringe a los Forward accesibles por el
     * usuario (seguridad — el sync no confía en los ids del cliente).
     */
    private function syncGroupForwards(\Tobuli\Entities\DeviceGroup $group, $forwardIds): void
    {
        if (! $this->user->perm('forwards', 'view')) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $forwardIds)));

        if ($ids) {
            $allowed = \Tobuli\Entities\Forward::userAccessible($this->user)
                ->pluck('id')
                ->map(function ($v) { return (int) $v; })
                ->all();
            $ids = array_values(array_intersect($ids, $allowed));
        }

        $group->forwards()->sync($ids);
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
    /**
     * Catálogo de iconos de sensor para el picker del mapeo de valores.
     * GET sensors/icons  — espeja SensorIconController::getBaseQuery():
     * los del sistema (user_id null) + los del propio usuario.
     */
    public function sensorIcons(Request $request): JsonResponse
    {
        $rows = \Tobuli\Entities\SensorIcon::where(function ($q) {
                $q->whereNull('user_id')->orWhere('user_id', $this->user->id);
            })
            ->orderBy('order', 'DESC')
            ->orderBy('id', 'ASC')
            ->get();

        return response()->json([
            'ok'    => true,
            'items' => $rows->map(function ($i) {
                return ['id' => (int) $i->id, 'url' => asset($i->path)];
            })->all(),
        ]);
    }

    /**
     * URL del SVG del tipo de sensor, o null si el tema no trae ese icono.
     * Espeja DeviceSensor::getIconAsset() pero sin devolver rutas rotas.
     */
    private function sensorTypeIcon(\Tobuli\Entities\DeviceSensor $sensor, $value): ?string
    {
        try {
            $url  = $sensor->getIconAsset($value);
            $file = public_path('assets/icons/' . basename((string) parse_url($url, PHP_URL_PATH)));

            return is_file($file) ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

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
        $this->expensesGate('view');
        $this->sensorDevice((int) $id);

        return response()->json(['ok' => true, 'items' => $this->presentDeviceExpenses((int) $id)]);
    }

    /** Gate del módulo de gastos (paridad legacy DeviceExpensesController:
     *  checkException('device_expenses', …) además del acceso al device). */
    private function expensesGate(string $action): void
    {
        if (! $this->user->perm('device_expenses', $action)) {
            abort(403);
        }
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
        $this->expensesGate('edit');
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

    /** Actualizar gasto. PUT devices/expenses/{id} */
    public function expenseUpdate(Request $request, $id): JsonResponse
    {
        $this->expensesGate('edit');

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

    /** Eliminar gasto. DELETE devices/expenses/{id} */
    public function expenseDestroy(Request $request, $id): JsonResponse
    {
        $this->expensesGate('remove');

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

        // Dirección: geocode compartido por los widgets Ubicación y Objeto
        // (evita pedir la dirección dos veces al mismo proveedor).
        $address = null;
        if (($has('location') || $has('device')) && $device->lat && $device->lng) {
            try {
                $loc = GeoLocation::byCoordinates($device->lat, $device->lng);
                $address = $loc ? $loc->toArray() : null;
            } catch (\Throwable $e) {
                $address = null;
            }
        }

        if ($has('location')) {
            $data['location'] = $address;
        }

        // Objeto: ficha UNIFICADA del vehículo (fusiona la antigua "Telemetría" del
        // drawer con "Objeto"). La duración de parada vive en la KPI "Detenido".
        if ($has('device')) {
            $engineStatus = $device->getEngineStatus();
            $engineText = ($engineStatus === true || $engineStatus === 'on')
                ? 'Encendido'
                : ($engineStatus !== null ? 'Apagado' : null);

            $data['device_info'] = [
                'status'      => $device->getStatus(),
                'status_text' => trans('front.' . $device->getStatus()),
                'address'     => $address['address'] ?? null,
                'time'        => $device->time ? \Formatter::time()->format($device->time) : null,
                'coords'      => ($device->lat && $device->lng)
                    ? number_format((float) $device->lat, 5) . ', ' . number_format((float) $device->lng, 5)
                    : null,
                'altitude'    => ($device->altitude !== null && $device->altitude !== '')
                    ? round((float) $device->altitude) . ' m'
                    : null,
                'engine'      => $engineText,
                'model'       => $device->device_model,
                'imei'        => $device->imei,
                'sim'         => $device->sim_number,
                'is_admin'    => (bool) $this->user->isAdmin(),
                'driver'      => $device->driver->name ?? null,
            ];
        }

        // Combustible: serie temporal (últimas 24 h) de los sensores fuel_tank.
        // Reutiliza el MISMO pipeline que el legacy (DeviceWidgetsController::
        // fuelGraph → DeviceHistory + SensorsValues): setSensors + get() y
        // getSensorsData() devuelve [id => {id,name,unit,values:{ts:{t,v}}}].
        if ($has('fuel_graph')) {
            $fuel = [];
            try {
                $fuelSensors = $device->sensors->filter(function ($s) {
                    return $s->type === 'fuel_tank';
                });
                if ($fuelSensors->isNotEmpty()) {
                    $history = new \Tobuli\History\DeviceHistory($device);
                    $history->setSensors($fuelSensors);
                    $history->setRange(\Carbon\Carbon::now()->subDay(1), \Carbon\Carbon::now());
                    $history->registerActions([]);
                    $history->get();

                    foreach ((array) $history->getSensorsData() as $s) {
                        $values = array_values($s['values'] ?? []);
                        $last = ! empty($values) ? end($values) : null;
                        $fuel[] = [
                            'name'   => $s['name'] ?? '',
                            'unit'   => $s['unit'] ?? '',
                            'values' => $values,                       // [{t,v}]
                            'last'   => $last ? $last['v'] : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $fuel = [];
            }
            $data['fuel'] = $fuel;
        }

        // Comando GPRS: solo se necesita el flag; el card abre el modal de
        // envío de comando (tp-commands) para este device.
        if ($has('gprs_command') && $this->user->perm('send_command', 'view')) {
            $data['gprs_command'] = true;
        }

        if ($has('sensors')) {
            // Mismo cálculo que el legacy (DeviceSensorsTransformer): valor actual
            // desde la última posición (traccar), con fallback al valor almacenado.
            $rows = [];
            $traccar = $device->traccar;
            foreach ($device->sensors as $sensor) {
                $formatted = '-';
                $icon = null;
                try {
                    if ($traccar) {
                        $value = $sensor->getValueCurrent($traccar);
                        $formatted = $value->getFormatted();
                        // Paridad legacy (DeviceMapFullTransformer::formatSensors +
                        // device.js dataSensorsDOM): 1) icono personalizado del mapeo
                        // de valores (admin → sensor_icons), 2) si no hay, el SVG del
                        // tipo de sensor (con escalón para battery/gsm).
                        $custom = $value->getIcon();
                        $icon = $custom ? asset($custom->path) : $this->sensorTypeIcon($sensor, $value->getValue());
                    }
                } catch (\Throwable $e) {
                    $formatted = '-';
                }
                $rows[] = [
                    'name'     => $sensor->formatName(),
                    'formated' => $formatted,
                    'type'     => (string) $sensor->type,
                    'icon'     => $icon,
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
                    // Con el plugin activo, Event::getTitleAttribute() lee
                    // $event->alert->name: eager-load para evitar N+1
                    // (idéntico a DeviceWidgetsController legacy).
                    ->when(settings('plugins.event_section_alert.status'), function ($query) {
                        $query->with('alert');
                    })
                    ->latest()->limit(10)->get();
            } catch (\Throwable $e) {
                $data['recent_events'] = collect();
            }
        }

        if ($has('streetview') && $device->lat && $device->lng) {
            $svApi     = settings('main_settings.streetview_api');
            $svLat     = $device->lat;
            $svLng     = $device->lng;
            $svHeading = (int) round($device->course ?? 0);

            if ($svApi) {
                // Imagen estática (proxy streetview.jpg → StreetviewService): fallback si
                // no aplica el embed. Aplica la key server-side y devuelve un placeholder.
                $data['streetview'] = route('streetview', [
                    'location' => $svLat . ',' . $svLng,
                    'size'     => '600x240',
                    'heading'  => $svHeading,
                ]);

                // Embed INTERACTIVO (paridad legacy street_view_frame): a diferencia de la
                // Static API (que exige panorámica en el punto EXACTO y devuelve "no imagery"
                // en zonas rurales), el embed encuentra la panorámica más cercana. Es el que
                // el legacy carga en el iframe (convertStreetViewUrl → maps/embed?pb=...).
                if (config('addon.street_view_frame')) {
                    $data['streetview_frame'] = 'https://www.google.com/maps/embed?pb=!4v' . time()
                        . '!6m8!1m7!!2m2!1d' . $svLat . '!2d' . $svLng
                        . '!3f' . $svHeading . '!4f0!5f0';
                }
            } else {
                // Sin proveedor configurado (Ajustes del servidor → Street View API).
                $data['streetview'] = null;
            }
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

        // Widget de bloqueo de motor (plugin locking_widget). Gateado por el plugin
        // (no por la config de widgets): al activarlo aparece por device. Lee el
        // estado del parámetro de la última posición (igual que LockStatusController
        // legacy) y expone los comandos preconfigurados para Cortar/Restablecer.
        if (settings('plugins.locking_widget.status')) {
            $lockStatus = null; // null = desconocido
            // 1) Fuente preferida: el sensor "blocked" del device (misma que el candado
            //    de la lista). Así se configura UNA vez en el sensor y sirve para todo.
            if (\Tobuli\Sensors\Types\Blocked::isEnabled() && ($bs = $device->getSensorByType('blocked'))) {
                $lockStatus = (bool) $bs->getValueCurrent($device)->getValue();
            } else {
                // 2) Fallback: parámetro crudo configurado en el plugin (parameter/value_on/off).
                try {
                    $lastPos = $device->positionTraccar();
                    $params  = $lastPos ? $lastPos->parameters : [];
                    $p    = settings('plugins.locking_widget.options.parameter');
                    $vOn  = settings('plugins.locking_widget.options.value_on');
                    $vOff = settings('plugins.locking_widget.options.value_off');
                    if (isset($params[$p]) && (string) $params[$p] === (string) $vOn) {
                        $lockStatus = true;
                    } elseif (isset($params[$p]) && (string) $params[$p] === (string) $vOff) {
                        $lockStatus = false;
                    }
                } catch (\Throwable $e) {
                    $lockStatus = null;
                }
            }

            $data['locking'] = [
                'status'        => $lockStatus, // true=bloqueado, false=libre, null=desconocido
                'can_sms'       => $this->user->canSendSMS() && ! empty($device->sim_number),
                'command_url'   => route('ui.v2.api.devices.lock_command', $device->id),
                'history_url'   => route('ui.v2.api.devices.lock_history', $device->id),
                // Comandos desde las Plantillas de GPRS adaptadas al equipo (por
                // protocolo/tipo): diferentes modelos → distinto mensaje.
                'templates_url' => route('ui.v2.api.devices.lock_templates', $device->id),
            ];
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

    /**
     * Widget de bloqueo (v2): envía el comando Cortar/Restablecer por GPRS o SMS,
     * reutilizando SendCommandService (misma tubería que el LockStatus legacy).
     */
    public function lockCommand(Request $request, $id): JsonResponse
    {
        if (! settings('plugins.locking_widget.status')) {
            return response()->json(['ok' => false, 'message' => 'Función no habilitada.'], 403);
        }
        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false, 'message' => 'Vehículo no encontrado.'], 404);
        }
        $this->checkException('devices', 'show', $device);

        $type    = $request->input('type', 'gprs');
        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return response()->json(['ok' => false, 'message' => 'El comando está vacío.'], 422);
        }

        $svc = app(\Tobuli\Services\Commands\SendCommandService::class);
        try {
            if ($type === 'sms') {
                if (! $this->user->canSendSMS() || empty($device->sim_number)) {
                    return response()->json(['ok' => false, 'message' => 'SMS no disponible para este vehículo.'], 422);
                }
                $result = $svc->sms($device, ['type' => 'custom', 'message' => $message], $this->user);
            } else {
                $result = $svc->gprs($device, ['type' => 'custom', 'data' => $message], $this->user);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $resp = is_array($result) ? ($result[0] ?? []) : [];
        if (! empty($resp['error'])) {
            return response()->json(['ok' => false, 'message' => $resp['error']], 422);
        }

        return response()->json(['ok' => true, 'message' => trans('front.command_sent')]);
    }

    /**
     * Plantillas de GPRS del usuario ADAPTADAS a este device (por protocolo /
     * tipo / dispositivo, vía isAdaptedFromDevice). El widget de bloqueo las
     * ofrece para Cortar/Restablecer — así cada modelo usa su propio comando.
     */
    public function lockTemplates(Request $request, $id): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false], 404);
        }
        $this->checkException('devices', 'show', $device);

        // Plantillas GPRS del usuario + las comunes (admin), adaptadas al equipo.
        $items = \Tobuli\Entities\UserGprsTemplate::userAccessible($this->user)
            ->get()
            ->filter(function ($t) use ($device) {
                return $t->isAdaptedFromDevice($device);
            })
            ->map(function ($t) {
                return [
                    'id'          => (int) $t->id,
                    'title'       => (string) $t->title,
                    'message'     => (string) $t->message,
                    'lock_action' => (string) ($t->lock_action ?? ''),
                ];
            })
            ->values()
            ->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** Historial de bloqueo del device (periodos), paridad LockStatusController legacy. */
    public function lockHistory(Request $request, $id): JsonResponse
    {
        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false], 404);
        }
        $this->checkException('devices', 'show', $device);

        $period = getPeriodByPhrase($request->get('period', 'today'));

        $history = new \Tobuli\History\DeviceHistory($device);
        $history->registerActions([
            \Tobuli\History\Actions\AppendLockStatus::class,
            \Tobuli\History\Actions\Duration::class,
            \Tobuli\History\Actions\GroupLockOffStatus::class,
        ]);
        $history->setRange($period['start'], $period['end']);

        $result = $history->get();
        $groups = isset($result['groups']) ? ($result['groups']->all() ?? []) : [];

        $rows = array_map(function ($g) {
            return [
                'time'     => $g->getStartAt(),
                'duration' => $g->stats()->human('duration'),
                'lat'      => $g->getStartPosition()->latitude,
                'lng'      => $g->getStartPosition()->longitude,
            ];
        }, $groups);

        return response()->json(['ok' => true, 'items' => $rows]);
    }
}
