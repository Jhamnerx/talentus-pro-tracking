<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\MapIcon;
use Tobuli\Entities\Poi;
use Tobuli\Entities\PoiGroup;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\PoiUserService;

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

        $items = [];
        foreach ($this->user->pois()->get() as $poi) {
            $c = (array) ($poi->coordinates ?? []);
            $lat = $c['lat'] ?? $c['latitude'] ?? ($c['y'] ?? null);
            $lng = $c['lng'] ?? $c['longitude'] ?? ($c['x'] ?? null);
            if ($lat === null || $lng === null) {
                continue;
            }
            $items[] = [
                'id'   => (int) $poi->id,
                'name' => (string) $poi->name,
                'lat'  => (float) $lat,
                'lng'  => (float) $lng,
            ];
        }

        return response()->json(['items' => $items]);
    }

    /* =================================================================
     * POIs · CRUD (modal nativo v2, reutiliza PoiUserService)
     * =================================================================
     */

    /**
     * Form HTML (create + edit) del POI: catálogo de iconos y grupos.
     */
    public function poiForm(Request $request, $id = null): JsonResponse
    {
        $action = $id ? 'edit' : 'store';
        if (! $this->user->perm('poi', $action === 'store' ? 'store' : 'edit')) {
            return response()->json(['ok' => false, 'message' => 'Sin permiso.'], 403);
        }

        $item = null;
        if ($id) {
            $item = Poi::find((int) $id);
            if (! $item || ! $this->user->can('edit', $item)) {
                return response()->json(['ok' => false, 'message' => 'POI no encontrado.'], 404);
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
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updatePoi(Request $request, $id): JsonResponse
    {
        $poi = Poi::find((int) $id);
        if (! $poi) {
            return response()->json(['ok' => false, 'message' => 'POI no encontrado.'], 404);
        }

        try {
            $service = new PoiUserService($this->user);
            $service->edit($poi, $request->all());

            return response()->json(['ok' => true, 'item' => $this->poiRow($poi->fresh())]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyPoi(Request $request, $id): JsonResponse
    {
        $poi = Poi::find((int) $id);
        if (! $poi) {
            return response()->json(['ok' => false, 'message' => 'POI no encontrado.'], 404);
        }

        try {
            (new PoiUserService($this->user))->remove($poi);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
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
                'coordinates' => $coords,
            ];
        }

        return response()->json(['items' => $items]);
    }
}
