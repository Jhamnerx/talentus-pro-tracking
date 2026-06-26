<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Geofence;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Exporters\Util\ExportTypesUtil;
use Tobuli\Importers\Geofence\GeofenceImportManager;
use Tobuli\Services\GeofenceUserService;

/**
 * TalentusPro · ZonesController
 * Geocercas (visualización + CRUD + import/export delegados a servicios legacy).
 */
class ZonesController extends BaseController
{
    /**
     * Visualizer view.
     */
    public function index(Request $request)
    {
        if (! $this->user->perm('geofences', 'view')) {
            abort(403, 'No tienes permiso para ver geocercas.');
        }

        return View::make('ui.v2.pages.zones', [
            'pageTitle' => 'Zones & Routes',
            'mapConfig' => $this->buildMapConfig(),
            'canEdit'   => $this->user->perm('geofences', 'edit'),
            'canCreate' => $this->user->perm('geofences', 'edit'),
        ]);
    }

    /**
     * Zones data feed (geofences with geometry + groups).
     */
    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('geofences', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $rows = $this->user->geofences()
            ->orderBy('name', 'asc')
            ->get();

        $items = [];
        foreach ($rows as $g) {
            $coords = json_decode($g->coordinates, true);
            $items[] = [
                'id'            => (int) $g->id,
                'name'          => (string) $g->name,
                'group_id'      => $g->group_id ? (int) $g->group_id : null,
                'type'          => (string) $g->type,
                'active'        => (bool) $g->active,
                'polygon_color' => (string) ($g->polygon_color ?: '#1e88e5'),
                'radius'        => $g->radius !== null ? (float) $g->radius : null,
                'center'        => $g->center,
                'coordinates'   => is_array($coords) ? $coords : [],
                'speed_limit'   => $g->speed_limit,
            ];
        }

        $groups = [];
        try {
            $rawGroups = $this->user->geofence_groups()->orderBy('title', 'asc')->get();
            foreach ($rawGroups as $g) {
                $groups[] = ['id' => (int) $g->id, 'title' => (string) $g->title];
            }
        } catch (\Throwable $e) {
            // relation may not exist on every install
        }

        return response()->json([
            'ok'     => true,
            'total'  => count($items),
            'items'  => $items,
            'groups' => $groups,
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $groups = [];
        try {
            $rawGroups = $user->geofence_groups()->orderBy('title', 'asc')->get(['id', 'title']);
            foreach ($rawGroups as $g) {
                $groups[] = ['id' => (int) $g->id, 'title' => (string) $g->title];
            }
        } catch (\Throwable $e) {
        }

        $devices = [];
        if (settings('plugins.moving_geofence.status')) {
            try {
                $rows = $user->devices()->orderBy('name', 'asc')->get(['id', 'name']);
                foreach ($rows as $d) {
                    $devices[] = ['id' => (int) $d->id, 'name' => (string) ($d->name ?: ('#' . $d->id))];
                }
            } catch (\Throwable $e) {
            }
        }

        return response()->json([
            'ok'            => true,
            'types'         => [
                ['key' => 'polygon', 'label' => trans('front.polygon')],
                ['key' => 'circle',  'label' => trans('front.circle')],
            ],
            'groups'        => $groups,
            'devices'       => $devices,
            'default_color' => '#d000df',
            'plugins'       => [
                'moving_geofence' => (bool) settings('plugins.moving_geofence.status'),
                'speed_limit'     => (bool) settings('plugins.geofences_speed_limit.status'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        try {
            $service  = new GeofenceUserService($user);
            $geofence = $service->create($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'   => true,
            'item' => $this->presentZone($geofence),
        ]);
    }

    public function editData(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $geofence = Geofence::find((int) $id);
        if (! $geofence || ! $user->can('view', $geofence)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->json([
            'ok'   => true,
            'item' => $this->presentZone($geofence),
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $geofence = Geofence::find((int) $id);
        if (! $geofence || ! $user->can('view', $geofence)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        try {
            $service = new GeofenceUserService($user);
            $service->edit($geofence, array_merge($request->all(), ['id' => (int) $id]));
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'   => true,
            'item' => $this->presentZone($geofence->fresh()),
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'remove')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $geofence = Geofence::find((int) $id);
        if (! $geofence || ! $user->can('view', $geofence)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        try {
            $service = new GeofenceUserService($user);
            $service->remove($geofence);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true]);
    }

    public function import(Request $request, GeofenceImportManager $importManager): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $validator = Validator::make($request->all(), ['file' => 'required|file']);
        if ($validator->fails()) {
            return response()->json([
                'ok'     => false,
                'error'  => 'validation_failed',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        try {
            $importManager->import($request->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'import_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => trans('front.successfully_imported_geofence'),
        ]);
    }

    public function exportItems(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $type = (string) $request->input('type', ExportTypesUtil::EXPORT_TYPE_SINGLE);
        $data = (new GeofenceUserService($user))->getExportType($type);

        $items = [];
        foreach (($data['items'] ?? []) as $id => $label) {
            $items[] = ['id' => (string) $id, 'label' => (string) $label];
        }

        return response()->json([
            'ok'       => true,
            'type'     => $type,
            'items'    => $items,
            'selected' => array_values(array_map('strval', array_keys($data['selected'] ?? []))),
        ]);
    }

    private function presentZone($g): array
    {
        if (! $g) return [];
        $coords = json_decode($g->coordinates, true);
        return [
            'id'            => (int) $g->id,
            'name'          => (string) $g->name,
            'group_id'      => $g->group_id ? (int) $g->group_id : null,
            'device_id'     => $g->device_id ? (int) $g->device_id : null,
            'type'          => (string) $g->type,
            'active'        => (bool) $g->active,
            'polygon_color' => (string) ($g->polygon_color ?: '#d000df'),
            'radius'        => $g->radius !== null ? (float) $g->radius : null,
            'center'        => $g->center,
            'coordinates'   => is_array($coords) ? $coords : [],
            'speed_limit'   => $g->speed_limit,
        ];
    }
}
