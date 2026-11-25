<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Tobuli\Services\HitosKmService;

/**
 * Hitos kilométricos del Perú para la app (user_api_hash), igual que
 * Ui\V2\MapLayersController@hitos / hitoNearest.
 */
class HitosKmController extends Controller
{
    /** Tope por respuesta en el móvil (la web usa 4000). */
    private const MOBILE_LIMIT = 2000;

    /** Query: bbox=oeste,sur,este,norte y zoom. */
    public function apiBbox(HitosKmService $hitos)
    {
        $parts = array_map('floatval', explode(',', (string) request()->get('bbox', '')));

        if (count($parts) !== 4) {
            return ['status' => 0, 'items' => [], 'message' => 'bbox'];
        }

        [$w, $s, $e, $n] = $parts;
        $zoom = (int) request()->get('zoom', 10);

        return response()->json([
            'status'  => 1,
            'density' => HitosKmService::densityFor($zoom),
            'items'   => $hitos->inBbox($w, $s, $e, $n, $zoom, self::MOBILE_LIMIT),
        ])->header('Cache-Control', 'private, max-age=86400'); // datos semestrales
    }

    /** Query: lat, lng → hito más cercano (≤ 2 km) o item null. */
    public function apiNearest(HitosKmService $hitos)
    {
        $lat = (float) request()->get('lat');
        $lng = (float) request()->get('lng');

        if (! $lat || ! $lng) {
            return ['status' => 0, 'item' => null];
        }

        return ['status' => 1, 'item' => $hitos->nearest($lat, $lng)];
    }
}
