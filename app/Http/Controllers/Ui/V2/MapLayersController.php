<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UI v2 · Capas de mapa de solo-lectura (POIs y Rutas) para los mapas
 * compartidos (Live + Eventos). Reutiliza $user->pois() / $user->routes()
 * y normaliza las coordenadas a [lng, lat] para MapLibre.
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
