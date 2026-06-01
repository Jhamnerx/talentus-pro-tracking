<?php

namespace App\Http\Controllers\Talentus;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * TalentusPro · DashboardController
 * Command Center: KPIs y telemetría agregada.
 */
class DashboardController extends BaseController
{
    public function index(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403, 'No tienes permiso para ver el panel.');
        }

        return View::make('talentus.pages.dashboard', [
            'pageTitle' => 'Command Center',
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $buckets    = ['moving' => 0, 'idle' => 0, 'stopped' => 0, 'offline' => 0];
        $top        = [];
        $recent     = [];
        $total      = 0;
        $sumSpeed   = 0;
        $countSpeed = 0;
        $lastDay    = strtotime('-24 hours');

        $this->user->devices()
            ->wasConnected()
            ->clearOrdersBy()
            ->chunk(200, function ($chunk) use (&$buckets, &$top, &$recent, &$total, &$sumSpeed, &$countSpeed, $lastDay) {
                foreach ($chunk as $device) {
                    $total++;
                    $status = $device->getStatus();
                    $bucket = $this->groupStatus($status);
                    $buckets[$bucket]++;

                    $speed = (float) $device->getSpeed();
                    if ($speed > 0) {
                        $sumSpeed += $speed;
                        $countSpeed++;
                    }

                    $top[] = [
                        'id'     => $device->id,
                        'name'   => $device->name,
                        'speed'  => $speed,
                        'status' => $status,
                    ];

                    $movedAt = $device->moved_at ? strtotime($device->moved_at) : null;
                    if ($movedAt && $movedAt >= $lastDay) {
                        $hour = (int) date('G', $movedAt);
                        $recent[$hour] = ($recent[$hour] ?? 0) + 1;
                    }
                }
            });

        usort($top, function ($a, $b) {
            return $b['speed'] <=> $a['speed'];
        });
        $top = array_slice($top, 0, 5);

        $hours = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour    = (int) date('G', strtotime("-{$i} hours"));
            $hours[] = [
                'hour'  => sprintf('%02d:00', $hour),
                'count' => $recent[$hour] ?? 0,
            ];
        }

        $avgSpeed = $countSpeed ? round($sumSpeed / $countSpeed, 1) : 0;

        return response()->json([
            'ok'   => true,
            'kpis' => [
                'total'       => $total,
                'moving'      => $buckets['moving'],
                'idle'        => $buckets['idle'],
                'stopped'     => $buckets['stopped'],
                'offline'     => $buckets['offline'],
                'avgSpeed'    => $avgSpeed,
                'utilization' => $total > 0 ? round(($buckets['moving'] / $total) * 100) : 0,
            ],
            'distribution' => [
                ['name' => 'En movimiento', 'value' => $buckets['moving'],  'color' => '#10b981'],
                ['name' => 'Inactivo',      'value' => $buckets['idle'],    'color' => '#f59e0b'],
                ['name' => 'Detenido',      'value' => $buckets['stopped'], 'color' => '#3b82f6'],
                ['name' => 'Offline',       'value' => $buckets['offline'], 'color' => '#94a3b8'],
            ],
            'topSpeed'  => $top,
            'activity'  => $hours,
            'fetchedAt' => date('c'),
        ]);
    }
}
