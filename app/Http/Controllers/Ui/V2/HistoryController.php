<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * TalentusPro · HistoryController
 * Reproductor de recorridos: device → positions[] + stops[] + KPIs.
 */
class HistoryController extends BaseController
{
    public function index(Request $request)
    {
        if (! $this->user->perm('history', 'view')) {
            abort(403, 'No tienes permiso para ver historial.');
        }

        return View::make('ui.v2.pages.history', [
            'pageTitle' => 'History & Replay',
            'mapConfig' => $this->buildMapConfig(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('history', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $deviceId = (int) $request->get('device', 0);
        $from     = trim((string) $request->get('from', ''));
        $to       = trim((string) $request->get('to', ''));
        $max      = min(20000, max(500, (int) $request->get('max', 5000)));

        if (! $deviceId || ! $from || ! $to) {
            return response()->json(['ok' => false, 'error' => 'missing_params'], 422);
        }

        $maxDays = (int) config('tobuli.history_max_period_days', 30);
        try {
            $fromTs = strtotime($from);
            $toTs   = strtotime($to);
            if (! $fromTs || ! $toTs || $toTs <= $fromTs) {
                return response()->json(['ok' => false, 'error' => 'invalid_range'], 422);
            }
            if (($toTs - $fromTs) > $maxDays * 86400) {
                return response()->json(['ok' => false, 'error' => 'range_too_large', 'max_days' => $maxDays], 422);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'invalid_range'], 422);
        }

        $device = $this->user->devices()->where('devices.id', $deviceId)->first();
        if (! $device) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $rows = $device->positions()
            ->whereBetween('time', [$from, $to])
            ->orderBy('time', 'asc')
            ->limit($max)
            ->get(['id', 'latitude', 'longitude', 'speed', 'course', 'altitude', 'time']);

        $positions = [];
        $stops     = [];
        $minSpeed  = (float) ($device->min_moving_speed ?? 0);
        $current   = null;
        $totalDist = 0.0;
        $maxSpeed  = 0.0;
        $sumSpeed  = 0.0;
        $movingPts = 0;
        $prev      = null;

        foreach ($rows as $r) {
            $lat = (float) $r->latitude;
            $lng = (float) $r->longitude;
            $sp  = (float) $r->speed;
            $t   = (string) $r->time;
            $ts  = strtotime($t);

            $positions[] = [
                'lat' => $lat,
                'lng' => $lng,
                'spd' => $sp,
                'crs' => $r->course   !== null ? (float) $r->course   : null,
                'alt' => $r->altitude !== null ? (float) $r->altitude : null,
                't'   => $t,
                'ts'  => $ts,
            ];

            if ($sp > $maxSpeed) $maxSpeed = $sp;
            if ($sp > 0) {
                $sumSpeed += $sp;
                $movingPts++;
            }
            if ($prev) {
                $totalDist += $this->haversine($prev['lat'], $prev['lng'], $lat, $lng);
            }
            $prev = ['lat' => $lat, 'lng' => $lng];

            if ($sp < $minSpeed) {
                if (! $current) {
                    $current = ['startIdx' => count($positions) - 1, 'startTs' => $ts, 'lat' => $lat, 'lng' => $lng];
                }
                $current['endIdx'] = count($positions) - 1;
                $current['endTs']  = $ts;
            } else {
                if ($current) {
                    $dur = $current['endTs'] - $current['startTs'];
                    if ($dur >= 180) {
                        $stops[] = [
                            'idx'      => $current['startIdx'],
                            'lat'      => $current['lat'],
                            'lng'      => $current['lng'],
                            'duration' => $dur,
                            'from'     => date('Y-m-d H:i:s', $current['startTs']),
                            'to'       => date('Y-m-d H:i:s', $current['endTs']),
                        ];
                    }
                    $current = null;
                }
            }
        }
        if ($current) {
            $dur = $current['endTs'] - $current['startTs'];
            if ($dur >= 180) {
                $stops[] = [
                    'idx'      => $current['startIdx'],
                    'lat'      => $current['lat'],
                    'lng'      => $current['lng'],
                    'duration' => $dur,
                    'from'     => date('Y-m-d H:i:s', $current['startTs']),
                    'to'       => date('Y-m-d H:i:s', $current['endTs']),
                ];
            }
        }

        $durSeconds = !empty($positions) ? ($positions[count($positions) - 1]['ts'] - $positions[0]['ts']) : 0;

        return response()->json([
            'ok'        => true,
            'device'    => ['id' => $device->id, 'name' => $device->name],
            'from'      => $from,
            'to'        => $to,
            'positions' => $positions,
            'stops'     => $stops,
            'kpis'      => [
                'points'        => count($positions),
                'distance_km'   => round($totalDist / 1000, 2),
                'duration_sec'  => $durSeconds,
                'max_speed'     => round($maxSpeed, 1),
                'avg_speed'     => $movingPts > 0 ? round($sumSpeed / $movingPts, 1) : 0,
                'stops'         => count($stops),
            ],
            'truncated' => count($rows) >= $max,
        ]);
    }
}
