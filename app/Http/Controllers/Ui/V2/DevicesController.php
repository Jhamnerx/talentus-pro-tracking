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
            try {
                $data['sensors'] = $device->sensors()->get();
            } catch (\Throwable $e) {
                $data['sensors'] = collect();
            }
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
}
