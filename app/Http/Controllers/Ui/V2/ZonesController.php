<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\GeofenceGroup;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Exporters\Util\ExportTypesUtil;
use Tobuli\Importers\Geofence\GeofenceImportManager;
use Tobuli\Services\GeofenceGroupService;
use Tobuli\Services\GeofenceUserService;
use Tobuli\Services\UserOpenGroupService;

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
        // Las geocercas ahora viven como panel del workspace Live (un solo mapa
        // Leaflet). La página dedicada queda deprecada → redirige al panel.
        return redirect('/ui/v2/live?panel=zones');
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
            ->with('vehicleSpeedLimits')
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
                'category'      => $g->category,
                'active'        => (bool) $g->active,
                'polygon_color' => (string) ($g->polygon_color ?: '#1e88e5'),
                'radius'        => $g->radius !== null ? (float) $g->radius : null,
                'center'        => $g->center,
                'coordinates'   => is_array($coords) ? $coords : [],
                // convert() (base → unidad usuario), como el legacy edit():
                // el service hace reverse() al guardar; devolver el crudo
                // multiplicaba el límite en cada edición si la unidad ≠ km.
                'device_id'     => $g->device_id ? (int) $g->device_id : null,
                'speed_limit'   => $g->speed_limit !== null ? round(\Formatter::speed()->convert($g->speed_limit)) : null,
                'vehicle_speed_limits' => $this->vslMap($g),
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
            'ok'                 => true,
            'total'              => count($items),
            'items'              => $items,
            'groups'             => $groups,
            // Uso de la geocerca en Operaciones (carga, descarga, grifo...).
            'categories'         => Geofence::categories(),
            'vehicle_types'      => $this->vehicleTypesPayload(),
            'speed_limit_plugin' => (bool) settings('plugins.geofences_speed_limit.status'),
            // Geocerca móvil (plugin moving_geofence): catálogo de vehículos
            // para el selector del modal. El feed lo consume live-zones.js
            // (meta() existía pero nadie lo llamaba).
            'moving_plugin'      => (bool) settings('plugins.moving_geofence.status'),
            // Intervalos horarios por geocerca: el validador los EXIGE cuando el
            // addon esta activo, asi que el form debe pintarlos (Geofences/edit.blade).
            'intervals_enabled'  => (bool) config('addon.reports_geofleet_in'),
            'time_range'         => config('addon.reports_geofleet_in') ? array_values(getSelectTimeRange()) : [],
            'devices'            => settings('plugins.moving_geofence.status')
                ? $this->user->devices()->orderBy('devices.name')->get(['devices.id', 'devices.name'])
                    ->map(function ($d) {
                        return ['id' => (int) $d->id, 'name' => (string) ($d->name ?: ('#' . $d->id))];
                    })->all()
                : [],
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
                ['key' => 'line',    'label' => trans('front.corridor')],
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
            ], $this->statusFor($e));
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
            ], $this->statusFor($e));
        }

        return response()->json([
            'ok'   => true,
            'item' => $this->presentZone($geofence->fresh()),
        ]);
    }

    /**
     * Visibilidad de la geocerca (paridad legacy GeofencesController@changeActive
     * → GroupModelService::changeActive). No pasa por el service porque
     * GeofenceUserService no expone active(); se replica el update acotado.
     */
    public function toggle(Request $request, $id): JsonResponse
    {
        $user = $this->user;
        if (! $user || ! $user->perm('geofences', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $geofence = Geofence::find((int) $id);
        if (! $geofence || ! $user->can('edit', $geofence)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $geofence->update(['active' => $request->boolean('active') ? 1 : 0]);

        return response()->json(['ok' => true, 'active' => (bool) $geofence->active]);
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
        } catch (ValidationException $e) {
            // P. ej. la geocerca es punto de una operacion: el motivo va al usuario.
            return response()->json([
                'ok'      => false,
                'error'   => 'validation',
                'message' => $e->getErrors() ? $e->getErrors()->first() : trans('ui.delete_failed'),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], $this->statusFor($e));
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
            ], $this->statusFor($e));
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

    /* =================================================================
     * Grupos de geocercas (paridad GeofencesGroupsController)
     * ================================================================= */

    /**
     * Ids de geocercas del usuario a asignar al grupo. Se filtra por
     * propiedad: GeofenceGroupService::syncItems() hace un whereIn crudo.
     */
    private function groupGeofenceIds(Request $request): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('geofences', []))));

        if (! $ids) {
            return [];
        }

        return Geofence::where('user_id', $this->user->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->checkException('geofences_groups', 'store');

        try {
            $service = new GeofenceGroupService();
            $group = $service->create([
                'title'   => $request->get('title'),
                'user_id' => $this->user->id,
            ]);

            $service->syncItems($group, $this->groupGeofenceIds($request));

            return response()->json(['ok' => true, 'id' => (int) $group->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function updateGroup(Request $request, $id): JsonResponse
    {
        $group = GeofenceGroup::find((int) $id);
        if (! $group) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('geofences_groups', 'edit', $group);

        try {
            $service = new GeofenceGroupService();
            $service->edit($group, ['title' => $request->get('title')]);
            $service->syncItems($group, $this->groupGeofenceIds($request));

            return response()->json(['ok' => true, 'id' => (int) $group->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * El legacy declara la ruta en el resource pero nunca implementó el método;
     * el servicio sí soporta delete(). Las geocercas del grupo quedan sin grupo.
     */
    public function destroyGroup(Request $request, $id): JsonResponse
    {
        $group = GeofenceGroup::find((int) $id);
        if (! $group) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('geofences_groups', 'remove', $group);

        $group->items()->update(['group_id' => null]);
        (new GeofenceGroupService())->delete($group);

        return response()->json(['ok' => true]);
    }

    /** Abrir/cerrar el grupo en el árbol (UserOpenGroupService, como el legacy). */
    public function toggleGroup(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);

        if ($id) {
            $group = GeofenceGroup::find($id);
            $this->checkException('geofences_groups', 'active', $group);
        }

        (new UserOpenGroupService($this->user->geofenceGroups()))
            ->changeStatus($id ?: null);

        return response()->json(['ok' => true]);
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
            'category'      => $g->category,
            'active'        => (bool) $g->active,
            'polygon_color' => (string) ($g->polygon_color ?: '#d000df'),
            'radius'        => $g->radius !== null ? (float) $g->radius : null,
            'center'        => $g->center,
            'coordinates'   => is_array($coords) ? $coords : [],
            // Misma conversión base→usuario que en data() (ver comentario allá).
            'speed_limit'   => $g->speed_limit !== null ? round(\Formatter::speed()->convert($g->speed_limit)) : null,
            'vehicle_speed_limits' => $this->vslMap($g),
            // Intervalos horarios con limite propio (addon reports_geofleet_in).
            // Se pintan en la TZ del usuario; GeofenceUserService::normalize()
            // hace el reverse() al guardar (igual que Geofences/edit.blade).
            'intervals'     => $this->intervalsMap($g),
        ];
    }

    /**
     * [ {from, to, speed_limit}, {…} ] para los 2 intervalos del legacy.
     * Array vacío si el addon está apagado.
     */
    private function intervalsMap($g): array
    {
        if (! config('addon.reports_geofleet_in')) {
            return [];
        }

        $stored = $g && is_array($g->additional ?? null) ? ($g->additional['intervals'] ?? []) : [];
        $out = [];

        foreach ([0, 1] as $i) {
            $iv = $stored[$i] ?? null;
            $out[] = [
                'from'        => isset($iv['from']) ? \Formatter::dtime()->convert($iv['from'], 'H:i') : '',
                'to'          => isset($iv['to']) ? \Formatter::dtime()->convert($iv['to'], 'H:i') : '',
                'speed_limit' => $iv['speed_limit'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Mapa de límites de velocidad por tipo/estado de vehículo de la geocerca,
     * en la unidad del usuario (igual que el legacy getVehicleSpeedLimits()).
     * Estructura: { vehicle_type_id: { vehicle_state_id|0: speed } }.
     */
    private function vslMap($g): array
    {
        $map = [];
        foreach ($g->vehicleSpeedLimits as $l) {
            $map[(int) $l->vehicle_type_id][(int) ($l->vehicle_state_id ?? 0)] =
                round(\Formatter::speed()->convert($l->speed_limit));
        }
        return $map;
    }

    /** Tipos de vehículo del usuario con sus estados (para la matriz del modal). */
    private function vehicleTypesPayload(): array
    {
        if (! settings('plugins.geofences_speed_limit.status')) {
            return [];
        }
        $out = [];
        $types = VehicleType::with('states')
            ->where('user_id', $this->user->id)
            ->orderBy('name')
            ->get();
        foreach ($types as $vt) {
            $states = [];
            foreach ($vt->states as $s) {
                $states[] = ['id' => (int) $s->id, 'name' => (string) $s->name];
            }
            $out[] = ['id' => (int) $vt->id, 'name' => (string) $vt->name, 'states' => $states];
        }
        return $out;
    }
}
