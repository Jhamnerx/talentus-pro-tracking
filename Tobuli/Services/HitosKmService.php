<?php

namespace Tobuli\Services;

use Illuminate\Support\Facades\DB;
use Tobuli\Entities\HitoKm;

/**
 * Hitos kilométricos del Perú (tabla hitos_km + nombres de rutas_viales), compartido
 * entre la UI v2 (capa y referencia en popups) y la app móvil.
 *
 * Sin SQL espacial (MariaDB 10.5): lat/lon DECIMAL con índices BTREE responde el
 * bbox en ms con ~32k filas; la distancia del más cercano se calcula con haversine.
 */
class HitosKmService
{
    /** Distancia máxima (m) para considerar que el punto está sobre la red vial nacional. */
    public const NEAREST_MAX_M = 2000;

    /** via = nombre legible ("Panamericana Norte") con fallback al código. */
    private const VIA_EXPR = "COALESCE(NULLIF(rv.nombre_comun,''), NULLIF(rv.trayecto_corto,''), hitos_km.ruta)";

    /** Cada cuántos km se devuelve un hito según el zoom: ≥12 cada 1 · 9–11 cada 10 · <9 cada 100. */
    public static function densityFor(int $zoom): int
    {
        return $zoom >= 12 ? 1 : ($zoom >= 9 ? 10 : 100);
    }

    /** Hitos dentro del rectángulo (oeste, sur, este, norte), con la densidad del zoom. */
    public function inBbox(float $w, float $s, float $e, float $n, int $zoom, int $limit = 4000): array
    {
        $mod = self::densityFor($zoom);

        $q = HitoKm::current()
            ->leftJoin('rutas_viales as rv', 'rv.ruta', '=', 'hitos_km.ruta')
            ->whereBetween('hitos_km.lat', [min($s, $n), max($s, $n)])
            ->whereBetween('hitos_km.lon', [min($w, $e), max($w, $e)]);

        if ($mod > 1) {
            $q->whereRaw('(hitos_km.km % ?) = 0', [$mod]);
        }

        return $q->limit($limit)
            ->get([
                'hitos_km.ruta',
                DB::raw(self::VIA_EXPR . ' as via'),
                'hitos_km.km',
                'hitos_km.lat',
                'hitos_km.lon',
                'hitos_km.estado',
            ])
            ->map(fn ($h) => [
                'ruta'   => $h->ruta,
                'via'    => $h->via,
                'km'     => (int) $h->km,
                'lat'    => (float) $h->lat,
                'lon'    => (float) $h->lon,
                'estado' => $h->estado,
            ])
            ->values()
            ->all();
    }

    /**
     * Hito más cercano (≤ NEAREST_MAX_M), o null si el punto está fuera de la red vial
     * nacional. Pre-filtro bbox ±0.05° (~5.5 km) y orden por distancia euclídea aproximada.
     *
     * @return array{ruta: string, via: string, km: int, distance: int}|null
     */
    public function nearest(float $lat, float $lng): ?array
    {
        $h = HitoKm::current()
            ->leftJoin('rutas_viales as rv', 'rv.ruta', '=', 'hitos_km.ruta')
            ->whereBetween('hitos_km.lat', [$lat - 0.05, $lat + 0.05])
            ->whereBetween('hitos_km.lon', [$lng - 0.05, $lng + 0.05])
            ->orderByRaw('(hitos_km.lat - ?) * (hitos_km.lat - ?) + (hitos_km.lon - ?) * (hitos_km.lon - ?) asc', [$lat, $lat, $lng, $lng])
            ->limit(1)
            ->get(['hitos_km.ruta', DB::raw(self::VIA_EXPR . ' as via'), 'hitos_km.km', 'hitos_km.lat', 'hitos_km.lon'])
            ->first();

        if (! $h) {
            return null;
        }

        $d = self::haversine($lat, $lng, (float) $h->lat, (float) $h->lon);

        if ($d > self::NEAREST_MAX_M) {
            return null;
        }

        return [
            'ruta'     => (string) $h->ruta,
            'via'      => (string) $h->via,
            'km'       => (int) $h->km,
            'distance' => (int) round($d),
        ];
    }

    /** Distancia en metros entre dos coordenadas. */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }
}
