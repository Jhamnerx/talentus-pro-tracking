<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\HitoKm;
use Tobuli\Entities\MapIcon;
use Tobuli\Entities\Poi;
use Tobuli\Entities\PoiGroup;
use Tobuli\Entities\Route;
use Tobuli\Entities\RouteGroup;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\PoiGroupService;
use Tobuli\Services\PoiUserService;
use Tobuli\Services\RouteGroupService;
use Tobuli\Services\RouteUserService;
use Tobuli\Services\UserOpenGroupService;

/**
 * UI v2 · Capas de mapa (POIs y Rutas) para los mapas compartidos
 * (Live + Eventos). Lectura reutiliza $user->pois() / $user->routes()
 * (coords normalizadas a [lng, lat] para MapLibre); el CRUD de POIs
 * reutiliza la lógica legacy vía PoiUserService (create/edit/remove).
 */
class MapLayersController extends BaseController
{
    public function pois(Request $request): JsonResponse
    {
        if (! $this->user->perm('poi', 'view')) {
            return response()->json(['items' => []], 403);
        }

        $groups = PoiGroup::where('user_id', $this->user->id)
            ->orderBy('title')->get(['id', 'title']);
        $gmap = $groups->pluck('title', 'id');

        $items = [];
        foreach ($this->user->pois()->with('mapIcon')->get() as $poi) {
            $c = (array) ($poi->coordinates ?? []);
            $lat = $c['lat'] ?? $c['latitude'] ?? ($c['y'] ?? null);
            $lng = $c['lng'] ?? $c['longitude'] ?? ($c['x'] ?? null);
            if ($lat === null || $lng === null) {
                continue;
            }
            $icon = null;
            if ($poi->mapIcon) {
                $icon = [
                    'path'   => asset($poi->mapIcon->path),
                    'width'  => (int) $poi->mapIcon->width,
                    'height' => (int) $poi->mapIcon->height,
                ];
            }
            $items[] = [
                'id'          => (int) $poi->id,
                'name'        => (string) $poi->name,
                'lat'         => (float) $lat,
                'lng'         => (float) $lng,
                'group_id'    => (int) ($poi->group_id ?? 0),
                'group'       => $poi->group_id ? ($gmap[$poi->group_id] ?? null) : null,
                'icon'        => $icon,
                // active/description faltaban: el mapa pintaba los POIs
                // desactivados como activos y el modal no traía la descripción.
                'active'      => (bool) $poi->active,
                'description' => (string) ($poi->description ?? ''),
            ];
        }

        return response()->json([
            'items'  => $items,
            'groups' => $groups->map(function ($g) {
                return ['id' => (int) $g->id, 'title' => $g->title];
            })->all(),
            // Catálogo de iconos: lo necesita el import (map_icon_id es
            // obligatorio en el importador legacy).
            'map_icons' => MapIcon::orderBy('id')->get(['id', 'path'])->map(function ($i) {
                return [
                    'id'   => (int) $i->id,
                    'name' => basename((string) $i->path),
                    'path' => asset($i->path),
                ];
            })->all(),
        ]);
    }

    /* =================================================================
     * POIs · CRUD (modal nativo v2, reutiliza PoiUserService)
     * =================================================================
     */

    /**
     * Visibilidad del POI (paridad legacy PoisController@changeActive →
     * GroupModelService::changeActive). Usa el service para que aplique la
     * policy (read-only/demo) igual que el resto del CRUD.
     */
    public function togglePoi(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('poi', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $poi = Poi::find((int) $id);
        if (! $poi) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        try {
            (new PoiUserService($this->user))->active($poi, $request->boolean('active') ? 1 : 0);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json(['ok' => true, 'active' => (bool) $poi->fresh()->active]);
    }

    /**
     * Importar POIs (csv/gexp/kml). Paridad PoisImportController@store: exige
     * map_icon_id además del fichero.
     */
    public function importPois(Request $request, \Tobuli\Importers\POI\POIImportManager $importManager): JsonResponse
    {
        if (! $this->user->perm('poi', 'edit')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'map_icon_id' => 'required',
            'file'        => 'required|file',
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
        }

        try {
            $importManager->import($request->file('file'), $request->only(['map_icon_id']));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }

        return response()->json(['ok' => true, 'message' => trans('front.successfully_updated_marker')]);
    }

    /**
     * Form HTML (create + edit) del POI: catálogo de iconos y grupos.
     */
    public function poiForm(Request $request, $id = null): JsonResponse
    {
        // 'edit' es el modo válido (perm() solo entiende view|edit|remove);
        // crear y editar POIs requieren permiso de edición.
        if (! $this->user->perm('poi', 'edit')) {
            return response()->json(['ok' => false, 'message' => trans('ui.no_permission')], 403);
        }

        $item = null;
        if ($id) {
            $item = Poi::find((int) $id);
            if (! $item || ! $this->user->can('edit', $item)) {
                return response()->json(['ok' => false, 'message' => trans('ui.poi_not_found')], 404);
            }
        }

        $html = View::make('ui.v2.modals.poi-form', [
            'item'      => $item,
            'mapIcons'  => MapIcon::all(),
            'poiGroups' => PoiGroup::where(['user_id' => $this->user->id])->get()
                ->pluck('title', 'id')
                ->prepend(trans('front.ungrouped'), '0')
                ->all(),
        ])->render();

        return response()->json(['ok' => true, 'id' => $id ? (int) $id : null, 'html' => $html]);
    }

    public function storePoi(Request $request): JsonResponse
    {
        try {
            $service = new PoiUserService($this->user);
            $poi = $service->create($request->all());

            return response()->json(['ok' => true, 'item' => $this->poiRow($poi)]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function updatePoi(Request $request, $id): JsonResponse
    {
        $poi = Poi::find((int) $id);
        if (! $poi) {
            return response()->json(['ok' => false, 'message' => trans('ui.poi_not_found')], 404);
        }

        try {
            $service = new PoiUserService($this->user);
            $service->edit($poi, $request->all());

            return response()->json(['ok' => true, 'item' => $this->poiRow($poi->fresh())]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function destroyPoi(Request $request, $id): JsonResponse
    {
        $poi = Poi::find((int) $id);
        if (! $poi) {
            return response()->json(['ok' => false, 'message' => trans('ui.poi_not_found')], 404);
        }

        try {
            (new PoiUserService($this->user))->remove($poi);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /** Fila ligera de un POI para refrescar el mapa sin recargar todo. */
    protected function poiRow(Poi $poi): array
    {
        $c = (array) ($poi->coordinates ?? []);
        return [
            'id'   => (int) $poi->id,
            'name' => (string) $poi->name,
            'lat'  => isset($c['lat']) ? (float) $c['lat'] : null,
            'lng'  => isset($c['lng']) ? (float) $c['lng'] : null,
        ];
    }

    public function routes(Request $request): JsonResponse
    {
        if (! $this->user->perm('routes', 'view')) {
            return response()->json(['items' => []], 403);
        }

        $groups = RouteGroup::where('user_id', $this->user->id)
            ->orderBy('title')->get(['id', 'title', 'open']);

        $canEdit = (bool) $this->user->perm('routes', 'edit');
        // Permiso de grupos = el mismo que el legacy: la gestión de grupos de
        // rutas se gatea bajo 'routes' edit (el tab de rutas y los botones de
        // grupo en Routes/groups.blade heredan ese gate; 'route_groups' NO es un
        // módulo en config/permissions.php, perm('route_groups',...) daría false).
        $canGroups = $canEdit;

        // NOTA: `coordinates` se mantiene como pares [lng, lat] — este endpoint
        // lo consumen también live.js y events.js (overlay de solo lectura).
        // El panel CRUD (live-routes.js) reinvierte a [lat, lng]. Solo se AÑADEN
        // campos (group_id, active), nunca se cambia el formato existente.
        $items = [];
        foreach ($this->user->routes()->get() as $route) {
            $coords = [];
            foreach ((array) ($route->coordinates ?? []) as $c) {
                $c = (array) $c;
                $lat = $c['lat'] ?? $c['latitude'] ?? ($c['y'] ?? null);
                $lng = $c['lng'] ?? $c['longitude'] ?? ($c['x'] ?? null);
                if ($lat === null || $lng === null) {
                    continue;
                }
                $coords[] = [(float) $lng, (float) $lat];
            }
            if (count($coords) < 2) {
                continue;
            }
            $items[] = [
                'id'          => (int) $route->id,
                'name'        => (string) $route->name,
                'color'       => $route->color ?: '#7c3aed',
                'group_id'    => (int) ($route->group_id ?? 0),
                'active'      => (bool) $route->active,
                'coordinates' => $coords,
            ];
        }

        return response()->json([
            'items'    => $items,
            'groups'   => $groups->map(function ($g) {
                return ['id' => (int) $g->id, 'title' => $g->title, 'open' => (bool) $g->open];
            })->all(),
            'can_edit'   => $canEdit,
            'can_groups' => $canGroups,
        ]);
    }

    /* =================================================================
     * Rutas · CRUD (panel nativo v2, reutiliza RouteUserService)
     * El form vive inline en live-routes.js (name, color, grupo); la
     * geometría llega como `coordinates` = [{lat,lng}, …] dibujada con
     * leaflet-draw. Reproducimos el contrato legacy: el validador exige
     * `polyline`, así que lo derivamos de las coordenadas.
     * =================================================================
     */

    public function storeRoute(Request $request): JsonResponse
    {
        if (! $this->user->perm('routes', 'edit')) {
            return response()->json(['ok' => false, 'message' => trans('front.dont_have_permission')], 403);
        }

        try {
            $data = $this->routeInput($request->all());
            $route = (new RouteUserService($this->user))->create($data);

            return response()->json(['ok' => true, 'item' => $this->routeRow($route)]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function updateRoute(Request $request, $id): JsonResponse
    {
        $route = Route::find((int) $id);
        if (! $route) {
            return response()->json(['ok' => false, 'message' => trans('front.route') . ' 404'], 404);
        }

        try {
            $data = $this->routeInput($request->all());
            (new RouteUserService($this->user))->edit($route, $data);

            return response()->json(['ok' => true, 'item' => $this->routeRow($route->fresh())]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function destroyRoute(Request $request, $id): JsonResponse
    {
        $route = Route::find((int) $id);
        if (! $route) {
            return response()->json(['ok' => false, 'message' => trans('front.route') . ' 404'], 404);
        }

        try {
            (new RouteUserService($this->user))->remove($route);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    /**
     * Normaliza la entrada del panel al contrato que espera RouteService:
     * `coordinates` = [{lat,lng}, …] y `polyline` (string, requerido por el
     * validador). El servicio hace floatval de cada punto al normalizar.
     */
    protected function routeInput(array $data): array
    {
        $coords = $data['coordinates'] ?? [];
        if (is_string($coords)) {
            $coords = json_decode($coords, true) ?: [];
        }

        $clean = [];
        foreach ((array) $coords as $p) {
            $p = (array) $p;
            if (! isset($p['lat'], $p['lng'])) {
                continue;
            }
            $clean[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
        }

        return [
            'name'        => $data['name'] ?? '',
            'color'       => $data['color'] ?? '#1938FF',
            'group_id'    => $data['group_id'] ?? null,
            'coordinates' => $clean,
            // El validador legacy exige `polyline` no vacío; lo derivamos.
            'polyline'    => $clean ? json_encode($clean) : '',
        ];
    }

    /** Fila ligera de una ruta para refrescar el mapa sin recargar todo. */
    protected function routeRow(Route $route): array
    {
        $coords = [];
        foreach ((array) ($route->coordinates ?? []) as $c) {
            $c = (array) $c;
            if (! isset($c['lat'], $c['lng'])) {
                continue;
            }
            $coords[] = [(float) $c['lng'], (float) $c['lat']];
        }

        return [
            'id'          => (int) $route->id,
            'name'        => (string) $route->name,
            'color'       => $route->color ?: '#7c3aed',
            'group_id'    => (int) ($route->group_id ?? 0),
            'active'      => (bool) $route->active,
            'coordinates' => $coords,
        ];
    }

    /** Activar / desactivar (visibilidad) de una ruta. */
    public function toggleRoute(Request $request, $id): JsonResponse
    {
        if (! $this->user->perm('routes', 'edit')) {
            return response()->json(['ok' => false, 'message' => trans('front.dont_have_permission')], 403);
        }

        $route = Route::find((int) $id);
        if (! $route || ! $this->user->can('edit', $route)) {
            return response()->json(['ok' => false], 404);
        }

        $route->update(['active' => $request->boolean('active') ? 1 : 0]);

        return response()->json(['ok' => true, 'active' => (bool) $route->active]);
    }

    /* =================================================================
     * Grupos de rutas · CRUD (reutiliza RouteGroupService legacy)
     * =================================================================
     */

    /* =================================================================
     * Grupos de POIs (paridad PoisGroupsController)
     * ================================================================= */

    /** Ids de POIs del usuario (PoiGroupService::syncItems hace whereIn crudo). */
    protected function groupPoiIds(Request $request): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('pois', []))));

        if (! $ids) {
            return [];
        }

        return Poi::where('user_id', $this->user->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    public function storePoiGroup(Request $request): JsonResponse
    {
        $this->checkException('pois_groups', 'store');

        try {
            $service = new PoiGroupService();
            $group = $service->create([
                'title'   => $request->get('title'),
                'user_id' => $this->user->id,
            ]);

            $service->syncItems($group, $this->groupPoiIds($request));

            return response()->json(['ok' => true, 'id' => (int) $group->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function updatePoiGroup(Request $request, $id): JsonResponse
    {
        $group = PoiGroup::find((int) $id);
        if (! $group) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('pois_groups', 'edit', $group);

        try {
            $service = new PoiGroupService();
            $service->edit($group, ['title' => $request->get('title')]);
            $service->syncItems($group, $this->groupPoiIds($request));

            return response()->json(['ok' => true, 'id' => (int) $group->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /**
     * El legacy excluye destroy del resource, pero PoiGroupService::delete()
     * existe. Los POIs del grupo quedan sin grupo (no se borran).
     */
    public function destroyPoiGroup(Request $request, $id): JsonResponse
    {
        $group = PoiGroup::find((int) $id);
        if (! $group) {
            return response()->json(['ok' => false], 404);
        }

        $this->checkException('pois_groups', 'remove', $group);

        $group->items()->update(['group_id' => null]);
        (new PoiGroupService())->delete($group);

        return response()->json(['ok' => true]);
    }

    /** Abrir/cerrar el grupo en el árbol (UserOpenGroupService, como el legacy). */
    public function togglePoiGroup(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);

        if ($id) {
            $group = PoiGroup::find($id);
            $this->checkException('pois_groups', 'active', $group);
        }

        (new UserOpenGroupService($this->user->poiGroups()))
            ->changeStatus($id ?: null);

        return response()->json(['ok' => true]);
    }

    public function storeRouteGroup(Request $request): JsonResponse
    {
        if (! $this->user->perm('routes', 'edit')) {
            return response()->json(['ok' => false, 'message' => trans('front.dont_have_permission')], 403);
        }

        try {
            $data = [
                'title'  => $request->get('title'),
                'open'   => true,
                'routes' => $this->groupRouteIds($request),
            ] + ['user_id' => $this->user->id];

            $service = new RouteGroupService();
            $group = $service->create($data);
            if (! empty($data['routes'])) {
                $service->syncItems($group, $data['routes']);
            }

            return response()->json(['ok' => true, 'id' => (int) $group->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function updateRouteGroup(Request $request, $id): JsonResponse
    {
        $group = RouteGroup::find((int) $id);
        if (! $group || $group->user_id != $this->user->id) {
            return response()->json(['ok' => false], 404);
        }
        if (! $this->user->perm('routes', 'edit')) {
            return response()->json(['ok' => false, 'message' => trans('front.dont_have_permission')], 403);
        }

        try {
            $data = [
                'title'  => $request->get('title'),
                'routes' => $this->groupRouteIds($request),
            ];

            $service = new RouteGroupService();
            $service->edit($group, $data);
            $service->syncItems($group, $data['routes']);

            return response()->json(['ok' => true, 'id' => (int) $group->id]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        }
    }

    public function destroyRouteGroup(Request $request, $id): JsonResponse
    {
        $group = RouteGroup::find((int) $id);
        if (! $group || $group->user_id != $this->user->id) {
            return response()->json(['ok' => false], 404);
        }
        if (! $this->user->perm('routes', 'edit')) {
            return response()->json(['ok' => false, 'message' => trans('front.dont_have_permission')], 403);
        }

        // Las rutas del grupo quedan sin grupo (no se borran).
        Route::where('group_id', $group->id)->update(['group_id' => null]);
        $group->delete();

        return response()->json(['ok' => true]);
    }

    /** Normaliza la lista de rutas a asignar a un grupo (ids del usuario). */
    protected function groupRouteIds(Request $request): array
    {
        $ids = (array) ($request->get('routes') ?? []);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }

        // Solo rutas del propio usuario.
        return $this->user->routes()->whereIn('routes.id', $ids)->pluck('routes.id')->all();
    }

    /* =================================================================
     * Hitos kilométricos (Perú) — capa por viewport + km más cercano.
     * Sin SQL espacial a propósito: lat/lon + BTREE es portable
     * (MySQL 8 local / MariaDB dedicado) y sobra para ~32k filas.
     * Ver docs/implementacion_hitos_km.md.
     * =================================================================
     */

    /** GET hitos?bbox=lonMin,latMin,lonMax,latMax&zoom=12 */
    public function hitos(Request $request): JsonResponse
    {
        $parts = array_map('floatval', explode(',', (string) $request->get('bbox', '')));
        if (count($parts) !== 4) {
            return response()->json(['ok' => false, 'items' => []], 422);
        }
        [$w, $s, $e, $n] = $parts;
        $zoom = (int) $request->get('zoom', 10);

        // Densidad según zoom: ≥12 cada 1 km · 9–11 cada 10 · <9 cada 100
        $mod = $zoom >= 12 ? 1 : ($zoom >= 9 ? 10 : 100);

        // via = nombre legible ("Panamericana Norte") con fallback al código
        $viaExpr = "COALESCE(NULLIF(rv.nombre_comun,''), NULLIF(rv.trayecto_corto,''), hitos_km.ruta)";

        $q = HitoKm::current()
            ->leftJoin('rutas_viales as rv', 'rv.ruta', '=', 'hitos_km.ruta')
            ->whereBetween('hitos_km.lat', [min($s, $n), max($s, $n)])
            ->whereBetween('hitos_km.lon', [min($w, $e), max($w, $e)]);
        if ($mod > 1) {
            $q->whereRaw('(hitos_km.km % ?) = 0', [$mod]);
        }

        $items = $q->limit(4000)
            ->get([
                'hitos_km.ruta',
                \DB::raw($viaExpr . ' as via'),
                'hitos_km.km',
                'hitos_km.lat',
                'hitos_km.lon',
                'hitos_km.estado',
            ])
            ->map(function ($h) {
                return [
                    'ruta'   => $h->ruta,
                    'via'    => $h->via,
                    'km'     => (int) $h->km,
                    'lat'    => (float) $h->lat,
                    'lon'    => (float) $h->lon,
                    'estado' => $h->estado,
                ];
            });

        // Dataset semestral → cachear fuerte en el cliente
        return response()->json(['ok' => true, 'items' => $items])
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** GET hitos/nearest?lat=&lng= → hito más cercano (≤2 km) para popups. */
    public function hitoNearest(Request $request): JsonResponse
    {
        $lat = (float) $request->get('lat');
        $lng = (float) $request->get('lng');
        if (! $lat || ! $lng) {
            return response()->json(['ok' => false], 422);
        }

        // bbox ±0.05° (~5.5 km) y orden por distancia euclídea aproximada;
        // la distancia real se calcula en PHP (haversine) para el corte de 2 km.
        $viaExpr = "COALESCE(NULLIF(rv.nombre_comun,''), NULLIF(rv.trayecto_corto,''), hitos_km.ruta)";
        $rows = HitoKm::current()
            ->leftJoin('rutas_viales as rv', 'rv.ruta', '=', 'hitos_km.ruta')
            ->whereBetween('hitos_km.lat', [$lat - 0.05, $lat + 0.05])
            ->whereBetween('hitos_km.lon', [$lng - 0.05, $lng + 0.05])
            ->orderByRaw('(hitos_km.lat - ?) * (hitos_km.lat - ?) + (hitos_km.lon - ?) * (hitos_km.lon - ?) asc', [$lat, $lat, $lng, $lng])
            ->limit(1)
            ->get(['hitos_km.ruta', \DB::raw($viaExpr . ' as via'), 'hitos_km.km', 'hitos_km.lat', 'hitos_km.lon']);

        $h = $rows->first();
        if (! $h) {
            return response()->json(['ok' => false]);
        }

        $d = $this->haversine($lat, $lng, (float) $h->lat, (float) $h->lon);
        if ($d > 2000) {
            // Fuera de la red vial nacional
            return response()->json(['ok' => false]);
        }

        return response()->json([
            'ok'       => true,
            'ruta'     => $h->ruta,
            'via'      => $h->via,
            'km'       => (int) $h->km,
            'distance' => (int) round($d),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /** Distancia en metros entre dos coordenadas (haversine). */
    protected function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }

    /* =================================================================
     * Página · tabla de rutas (☰ del panel) — lista completa con
     * buscador, crear/exportar/importar y kebab editar/eliminar.
     * =================================================================
     */
    public function routesPage(Request $request)
    {
        if (! $this->user->perm('routes', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.routes', [
            'pageTitle' => trans('front.routes'),
        ]);
    }
}
