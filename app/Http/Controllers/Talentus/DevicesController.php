<?php

namespace App\Http\Controllers\Talentus;

use App\Transformers\Device\DeviceMapTransformer;
use CustomFacades\Repositories\DeviceRepo;
use ModalHelpers\DeviceModalHelper;
use FractalTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\DeviceGroup;
use Tobuli\Entities\DeviceIcon;
use Tobuli\Entities\DeviceType;
use Tobuli\Entities\Timezone;
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

        return View::make('talentus.pages.live', [
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
            return response()->json(['data' => [], 'meta' => ['total' => 0]], 403);
        }

        $limit  = min(2000, max(50, (int) $request->get('limit', 1000)));
        $cursor = $request->get('cursor');

        $devices = $this->user
            ->devices()
            ->filter($request->all())
            ->wasConnected()
            ->clearOrdersBy()
            ->when(! $cursor, function ($q) {
                $q->where('devices.id', '>', 0);
            })
            ->cursorPaginate($limit);

        $payload = FractalTransformer::cursorPaginate($devices, DeviceMapTransformer::class)->toArray();

        if (! empty($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as &$row) {
                if (isset($row['id'])) {
                    $row['channel'] = md5('device_' . $row['id']);
                }
            }
            unset($row);
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

        return View::make('talentus.pages.fleet', [
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
     * Lookups consumed by the create/edit modal:
     * device groups, types, icons, timezones, models.
     */
    public function meta(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $groups = DeviceGroup::where('user_id', $this->user->id)
            ->orderBy('title', 'asc')
            ->get(['id', 'title'])
            ->map(function ($g) {
                return ['id' => (int) $g->id, 'title' => (string) $g->title];
            })->values();

        $types = DeviceType::active()
            ->orderBy('title', 'asc')
            ->get(['id', 'title'])
            ->map(function ($t) {
                return ['id' => (int) $t->id, 'title' => (string) $t->title];
            })->values();

        $icons = DeviceIcon::query()
            ->where(function ($q) {
                $q->where('user_id', 0)->orWhere('user_id', $this->user->id);
            })
            ->where('type', '!=', 'arrow')
            ->orderBy('id', 'asc')
            ->limit(60)
            ->get(['id', 'path', 'type', 'width', 'height'])
            ->map(function ($i) {
                return [
                    'id'     => (int) $i->id,
                    'url'    => asset($i->path),
                    'type'   => (string) $i->type,
                    'width'  => (int) ($i->width  ?: 32),
                    'height' => (int) ($i->height ?: 32),
                ];
            })->values();

        // Group icons by type (arrow/rotating/icon) — same UX as legacy.
        $iconsGrouped = $icons->groupBy('type')->map(function ($group, $type) {
            return ['type' => $type, 'items' => $group->values()->all()];
        })->values();

        $timezones = Timezone::orderBy('id', 'asc')
            ->get(['id', 'title'])
            ->map(function ($t) {
                return [
                    'id'    => (int) $t->id,
                    'title' => str_replace('UTC ', '', (string) $t->title),
                ];
            })->values();

        // Static catalogs (mirror DeviceModalHelper::__construct constants).
        $iconTypes = [
            ['id' => 'arrow',    'title' => 'Flecha (color por estado)'],
            ['id' => 'rotating', 'title' => 'Ícono rotatorio'],
            ['id' => 'icon',     'title' => 'Ícono fijo'],
        ];

        $iconColors = [
            ['id' => 'green',  'title' => 'Verde'],
            ['id' => 'yellow', 'title' => 'Amarillo'],
            ['id' => 'red',    'title' => 'Rojo'],
            ['id' => 'blue',   'title' => 'Azul'],
            ['id' => 'orange', 'title' => 'Naranja'],
            ['id' => 'black',  'title' => 'Negro'],
            ['id' => 'gray',   'title' => 'Gris'],
            ['id' => 'cyan',   'title' => 'Cian'],
            ['id' => 'white',  'title' => 'Blanco'],
        ];

        $fuelDetectStops = [
            ['id' => 0,   'title' => 'Desactivado'],
            ['id' => 60,  'title' => '1 min'],
            ['id' => 120, 'title' => '2 min'],
            ['id' => 180, 'title' => '3 min'],
            ['id' => 300, 'title' => '5 min'],
        ];

        $operatorSim = [
            ['id' => '',         'title' => '-- Sin operador --'],
            ['id' => 'M2M',      'title' => 'M2M'],
            ['id' => 'CUY',      'title' => 'CUY'],
            ['id' => 'IOT',      'title' => 'IOT'],
            ['id' => 'MOVISTAR', 'title' => 'MOVISTAR'],
            ['id' => 'CLARO',    'title' => 'CLARO'],
            ['id' => 'ENTEL',    'title' => 'ENTEL'],
            ['id' => 'BITEL',    'title' => 'BITEL'],
        ];

        // Capabilities for the conditional tabs.
        $caps = [
            'is_admin'           => (bool) $this->user->isAdmin(),
            'can_edit_users'     => (bool) ($this->user->isAdmin() && $this->user->perm('users', 'view')),
            'can_view_sensors'   => (bool) $this->user->isAdmin(),
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

        return response()->json([
            'ok'              => true,
            'groups'          => $groups,
            'types'           => $types,
            'icons'           => $icons,
            'icons_grouped'   => $iconsGrouped,
            'icon_types'      => $iconTypes,
            'icon_colors'     => $iconColors,
            'fuel_detect_stops' => $fuelDetectStops,
            'operator_sim'    => $operatorSim,
            'timezones'       => $timezones,
            'caps'            => $caps,
            'defaults'        => [
                'active'           => true,
                'icon_id'          => (int) settings('device.icon_id'),
                'icon_type'        => 'arrow',
                'min_moving_speed' => (int) settings('device.min_moving_speed'),
                'min_fuel_fillings' => (int) settings('device.min_fuel_fillings'),
                'min_fuel_thefts'  => (int) settings('device.min_fuel_thefts'),
                'tail_length'      => (int) settings('device.tail.length'),
                'tail_color'       => (string) (settings('device.tail.color') ?: '#33b5e5'),
                'icon_colors'      => [
                    'moving'  => (string) (settings('device.status_colors.colors.moving')  ?: 'green'),
                    'stopped' => (string) (settings('device.status_colors.colors.stopped') ?: 'yellow'),
                    'offline' => (string) (settings('device.status_colors.colors.offline') ?: 'red'),
                    'engine'  => (string) (settings('device.status_colors.colors.engine')  ?: 'blue'),
                ],
            ],
        ]);
    }

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
            } else {
                $helper->setData([]);
                $data = $helper->createData();
            }

            // Caps for conditional tabs (mirror Talentus permission gates).
            $data['caps'] = [
                'is_admin'             => (bool) $this->user->isAdmin(),
                'can_edit_users'       => (bool) ($this->user->isAdmin() && $this->user->perm('users', 'view')),
                'can_view_sensors'     => (bool) $this->user->isAdmin(),
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

            $html = View::make('talentus.modals.devices.form', $data)->render();

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
     * Edit data for an existing device (used to prefill the modal).
     */
    public function edit(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $device = DeviceRepo::find((int) $id);
        if (! $device) {
            return response()->json(['ok' => false, 'message' => 'Vehículo no encontrado.'], 404);
        }

        $iconColors = (array) ($device->icon_colors ?? []);

        return response()->json([
            'ok'   => true,
            'item' => [
                'id'              => (int) $device->id,
                // Principal
                'active'          => (bool) $device->active,
                'name'            => (string) $device->name,
                'imei'            => (string) $device->imei,
                'device_model'    => (string) $device->device_model,
                'expiration_date' => $device->expiration_date,
                // Iconos
                'icon_id'         => $device->icon_id ? (int) $device->icon_id : null,
                'icon_type'       => (string) ($device->icon?->type ?? 'arrow'),
                'icon_colors'     => [
                    'moving'  => (string) ($iconColors['moving']  ?? 'green'),
                    'stopped' => (string) ($iconColors['stopped'] ?? 'yellow'),
                    'offline' => (string) ($iconColors['offline'] ?? 'red'),
                    'engine'  => (string) ($iconColors['engine']  ?? 'blue'),
                    'blocked' => (string) ($iconColors['blocked'] ?? 'gray'),
                ],
                // Información / Avanzado
                'group_id'             => $device->group_id ? (int) $device->group_id : null,
                'device_type_id'       => $device->device_type_id ? (int) $device->device_type_id : null,
                'timezone_id'          => $device->timezone_id ? (int) $device->timezone_id : null,
                'sim_number'           => (string) $device->sim_number,
                'operator_sim'         => (string) ($device->operator_sim ?? ''),
                'msisdn'               => (string) ($device->msisdn ?? ''),
                'sim_activation_date'  => $device->sim_activation_date,
                'sim_expiration_date'  => $device->sim_expiration_date,
                'vin'                  => (string) $device->vin,
                'plate_number'         => (string) $device->plate_number,
                'registration_number'  => (string) ($device->registration_number ?? ''),
                'object_owner'         => (string) ($device->object_owner ?? ''),
                'installation_date'    => $device->installation_date,
                'additional_notes'     => (string) ($device->additional_notes ?? ''),
                'comment'              => (string) ($device->comment ?? ''),
                'app_tracker_login'    => (string) ($device->app_tracker_login ?? ''),
                'gprs_templates_only'  => (bool) ($device->gprs_templates_only ?? false),
                'fuel_measurement_id'  => $device->fuel_measurement_id ? (int) $device->fuel_measurement_id : 1,
                'fuel_quantity'        => (float) ($device->fuel_quantity ?? 0),
                'fuel_price'           => (float) ($device->fuel_price ?? 0),
                // Exactitud
                'valid_by_avg_speed'   => (bool) ($device->valid_by_avg_speed ?? true),
                'engine_hours'         => (string) ($device->engine_hours ?? 'off'),
                'detect_engine'        => (string) ($device->detect_engine ?? 'off'),
                'detect_speed'         => (string) ($device->detect_speed ?? 'off'),
                'max_speed'            => (int) ($device->max_speed ?? 0),
                'min_moving_speed'     => (int) ($device->min_moving_speed ?? 6),
                'min_fuel_fillings'    => (float) ($device->min_fuel_fillings ?? 10),
                'min_fuel_thefts'      => (float) ($device->min_fuel_thefts ?? 10),
                'fuel_detect_sec_after_stop' => (int) ($device->fuel_detect_sec_after_stop ?? 0),
                'lbs'                  => (bool) ($device->lbs ?? false),
                // Cola
                'tail_color'           => (string) ($device->tail_color ?: '#33b5e5'),
                'tail_length'          => (int) ($device->tail_length ?? 5),
            ],
        ]);
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
}
