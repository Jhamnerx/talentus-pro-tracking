<?php

namespace App\Http\Controllers\Talentus;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Device;

/**
 * TalentusPro · CopilotController
 * Insights deterministas (reglas) + intent matcher en español.
 */
class CopilotController extends BaseController
{
    public function index(Request $request)
    {
        return View::make('talentus.pages.copilot', [
            'pageTitle' => 'Co-Pilot IA',
        ]);
    }

    public function insights(Request $request): JsonResponse
    {
        $user     = $this->user;
        $insights = [];
        $now      = time();
        $stat     = ['total' => 0, 'moving' => 0, 'idle' => 0, 'stopped' => 0, 'offline' => 0];
        $longOffline = [];
        $longIdle    = [];
        $noFix       = [];

        try {
            $user->devices()->with(['traccarDevice', 'traccar'])->chunk(200, function ($chunk) use (&$stat, &$longOffline, &$longIdle, &$noFix, $now) {
                foreach ($chunk as $d) {
                    $stat['total']++;
                    $g = $this->groupStatus((string) ($d->online ?? 'offline'));
                    $stat[$g]++;

                    if ($g === 'offline' && ! empty($d->time)) {
                        $secs = $now - strtotime((string) $d->time);
                        if ($secs > 86400) $longOffline[] = ['id' => $d->id, 'name' => $d->name, 'hours' => round($secs / 3600)];
                    }
                    if ($g === 'idle' && ! empty($d->time)) {
                        $secs = $now - strtotime((string) $d->time);
                        if ($secs > 7200) $longIdle[] = ['id' => $d->id, 'name' => $d->name, 'hours' => round($secs / 3600, 1)];
                    }
                    if (empty($d->lat) || empty($d->lng)) {
                        $noFix[] = ['id' => $d->id, 'name' => $d->name];
                    }
                }
            });
        } catch (\Throwable $e) {
        }

        if (count($longOffline) > 0) {
            $insights[] = [
                'severity' => 'critical',
                'category' => 'fleet_health',
                'icon'     => 'alert',
                'title'    => count($longOffline) . ' vehículo(s) sin reportar > 24h',
                'detail'   => 'Posible falla de batería, fusible o módem GPS. Revisa: ' . implode(', ', array_slice(array_column($longOffline, 'name'), 0, 5)) . (count($longOffline) > 5 ? '…' : ''),
                'action'   => ['label' => 'Ver en Fleet', 'url' => url('/talentus/fleet?status=offline')],
                'count'    => count($longOffline),
            ];
        }
        if (count($longIdle) > 0) {
            $insights[] = [
                'severity' => 'warning',
                'category' => 'efficiency',
                'icon'     => 'idle',
                'title'    => count($longIdle) . ' vehículo(s) en ralentí prolongado',
                'detail'   => 'Consumen combustible sin avanzar. Revisa: ' . implode(', ', array_slice(array_column($longIdle, 'name'), 0, 5)) . (count($longIdle) > 5 ? '…' : ''),
                'action'   => ['label' => 'Ver en Fleet', 'url' => url('/talentus/fleet?status=idle')],
                'count'    => count($longIdle),
            ];
        }
        if (count($noFix) > 0) {
            $insights[] = [
                'severity' => 'info',
                'category' => 'data_quality',
                'icon'     => 'gps',
                'title'    => count($noFix) . ' vehículo(s) sin posición GPS válida',
                'detail'   => 'Sin coordenadas reportadas. Verifica antena GPS en: ' . implode(', ', array_slice(array_column($noFix, 'name'), 0, 5)) . (count($noFix) > 5 ? '…' : ''),
                'action'   => ['label' => 'Ver Live Ops', 'url' => url('/talentus/live')],
                'count'    => count($noFix),
            ];
        }

        // --- Events last 24h ---
        $events24 = [];
        try {
            $from = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $events24 = $user->events()
                ->where('time', '>=', $from)
                ->orderBy('time', 'desc')
                ->limit(500)
                ->get(['id', 'device_id', 'type', 'time'])
                ->toArray();
        } catch (\Throwable $e) {
            $events24 = [];
        }

        if (count($events24) > 0) {
            $byType = [];
            $byDevice = [];
            $sosCount = 0;
            $speedCount = 0;
            foreach ($events24 as $e) {
                $t = (string) $e['type'];
                $byType[$t] = ($byType[$t] ?? 0) + 1;
                $byDevice[$e['device_id']] = ($byDevice[$e['device_id']] ?? 0) + 1;
                if ($t === 'sos') $sosCount++;
                if (in_array($t, ['overspeed', 'geofence_overspeed'], true)) $speedCount++;
            }

            if ($sosCount > 0) {
                $insights[] = [
                    'severity' => 'critical',
                    'category' => 'safety',
                    'icon'     => 'sos',
                    'title'    => $sosCount . ' alerta(s) SOS en 24h',
                    'detail'   => 'Activaciones del botón de pánico. Revisa cada caso y contacta al conductor.',
                    'action'   => ['label' => 'Ver alertas', 'url' => url('/talentus/alerts?type=sos')],
                    'count'    => $sosCount,
                ];
            }
            if ($speedCount >= 10) {
                $insights[] = [
                    'severity' => 'warning',
                    'category' => 'safety',
                    'icon'     => 'speed',
                    'title'    => $speedCount . ' exceso(s) de velocidad en 24h',
                    'detail'   => 'Patrón recurrente. Considera capacitación o ajuste de límites por geocerca.',
                    'action'   => ['label' => 'Ver alertas', 'url' => url('/talentus/alerts')],
                    'count'    => $speedCount,
                ];
            }
            arsort($byDevice);
            $topDev = array_slice($byDevice, 0, 1, true);
            foreach ($topDev as $devId => $cnt) {
                if ($cnt >= 5) {
                    $name = '';
                    try {
                        $d = Device::find($devId);
                        $name = $d ? $d->name : ('#' . $devId);
                    } catch (\Throwable $e) {
                        $name = '#' . $devId;
                    }

                    $insights[] = [
                        'severity' => 'warning',
                        'category' => 'pattern',
                        'icon'     => 'pattern',
                        'title'    => 'Vehículo con eventos repetidos: ' . $name,
                        'detail'   => $cnt . ' eventos en 24h en este vehículo. Investiga causa raíz.',
                        'action'   => ['label' => 'Ver historial', 'url' => url('/talentus/history?device=' . $devId)],
                        'count'    => (int) $cnt,
                    ];
                }
            }
        }

        $util = $stat['total'] > 0 ? round((($stat['moving'] + $stat['idle']) / $stat['total']) * 100) : 0;
        if ($stat['total'] >= 5 && $util < 40) {
            $insights[] = [
                'severity' => 'info',
                'category' => 'opportunity',
                'icon'     => 'chart',
                'title'    => 'Utilización de flota baja (' . $util . '%)',
                'detail'   => 'Menos del 40% de tus vehículos están activos. Revisa asignaciones o disponibilidad.',
                'action'   => ['label' => 'Ver dashboard', 'url' => url('/talentus/dashboard')],
                'count'    => 0,
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'severity' => 'success',
                'category' => 'all_clear',
                'icon'     => 'check',
                'title'    => '¡Todo en orden!',
                'detail'   => 'No detecté anomalías significativas en las últimas 24 horas.',
                'action'   => null,
                'count'    => 0,
            ];
        }

        $suggestions = [
            ['icon' => 'rocket', 'label' => 'Genera reporte de ayer',      'url' => url('/talentus/reports')],
            ['icon' => 'map',    'label' => 'Revisa zonas activas',        'url' => url('/talentus/zones')],
            ['icon' => 'eye',    'label' => 'Monitorea vehículos en vivo', 'url' => url('/talentus/live')],
            ['icon' => 'bell',   'label' => 'Configura nuevas alertas',    'url' => url('/alerts/create')],
        ];

        return response()->json([
            'ok'           => true,
            'kpis'         => [
                'total'        => $stat['total'],
                'moving'       => $stat['moving'],
                'idle'         => $stat['idle'],
                'stopped'      => $stat['stopped'],
                'offline'      => $stat['offline'],
                'utilization'  => $util,
                'events_24h'   => count($events24),
                'insights'     => count($insights),
            ],
            'insights'     => $insights,
            'suggestions'  => $suggestions,
            'generated_at' => date('c'),
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || mb_strlen($q) > 200) {
            return response()->json(['ok' => false, 'error' => 'invalid_query'], 422);
        }
        $lower = mb_strtolower($q, 'UTF-8');

        $intents = [
            ['match' => ['/(parad|deten|sin mov)/u'],          'reply' => 'Aquí tienes los vehículos detenidos.',           'action' => ['label' => 'Abrir Fleet (Detenidos)',     'url' => url('/talentus/fleet?status=stopped')]],
            ['match' => ['/(en movim|circuland|activos?)/u'],  'reply' => 'Aquí tienes los vehículos en movimiento.',       'action' => ['label' => 'Abrir Fleet (En movimiento)', 'url' => url('/talentus/fleet?status=moving')]],
            ['match' => ['/(ralent|idle)/u'],                  'reply' => 'Vehículos en ralentí.',                          'action' => ['label' => 'Abrir Fleet (Ralentí)',       'url' => url('/talentus/fleet?status=idle')]],
            ['match' => ['/(offline|sin (señal|reportar|conexi))/u'], 'reply' => 'Vehículos sin señal recientes.',          'action' => ['label' => 'Abrir Fleet (Offline)',       'url' => url('/talentus/fleet?status=offline')]],
            ['match' => ['/(velocid|exceso|veloc)/u'],         'reply' => 'Alertas de exceso de velocidad de las últimas 24h.', 'action' => ['label' => 'Abrir Alertas',           'url' => url('/talentus/alerts?type=overspeed')]],
            ['match' => ['/(sos|panic|p[aá]nico)/u'],          'reply' => 'Alertas SOS activas.',                           'action' => ['label' => 'Abrir Alertas (SOS)',         'url' => url('/talentus/alerts?type=sos')]],
            ['match' => ['/(reporte|informe)/u'],              'reply' => 'Te llevo al generador de reportes.',             'action' => ['label' => 'Abrir Reportes',              'url' => url('/talentus/reports')]],
            ['match' => ['/(geocerca|zona)/u'],                'reply' => 'Tus zonas y geocercas.',                         'action' => ['label' => 'Abrir Zonas',                 'url' => url('/talentus/zones')]],
            ['match' => ['/(historial|recorrido|replay|hoy|ayer)/u'], 'reply' => 'Te llevo al reproductor de recorridos.', 'action' => ['label' => 'Abrir History',               'url' => url('/talentus/history')]],
            ['match' => ['/(dashboard|resumen|kpi)/u'],        'reply' => 'Aquí tienes el Command Center.',                 'action' => ['label' => 'Abrir Dashboard',             'url' => url('/talentus/dashboard')]],
        ];

        foreach ($intents as $it) {
            foreach ($it['match'] as $rx) {
                if (preg_match($rx, $lower)) {
                    return response()->json([
                        'ok'     => true,
                        'reply'  => $it['reply'],
                        'action' => $it['action'],
                        'query'  => $q,
                    ]);
                }
            }
        }

        // Fallback: device search
        try {
            $term = '%' . $q . '%';
            $hit = $this->user->devices()
                ->where(function ($w) use ($term) {
                    $w->where('name', 'like', $term)
                        ->orWhere('imei', 'like', $term)
                        ->orWhere('plate_number', 'like', $term);
                })
                ->limit(3)
                ->get(['devices.id', 'devices.name', 'devices.plate_number']);

            if ($hit->count() > 0) {
                $first = $hit->first();
                return response()->json([
                    'ok'      => true,
                    'reply'   => 'Encontré ' . $hit->count() . ' vehículo(s) que coinciden. Te llevo al primero: ' . $first->name . '.',
                    'action'  => ['label' => 'Ver en Live Ops', 'url' => url('/talentus/live?device=' . $first->id)],
                    'matches' => $hit->map(function ($d) {
                        return ['id' => $d->id, 'name' => $d->name, 'plate' => $d->plate_number];
                    })->all(),
                    'query'   => $q,
                ]);
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok'     => true,
            'reply'  => 'No entendí la pregunta exacta. Aquí tienes una búsqueda general.',
            'action' => ['label' => 'Buscar en plataforma', 'url' => url('/talentus/live?q=' . urlencode($q))],
            'query'  => $q,
        ]);
    }
}
