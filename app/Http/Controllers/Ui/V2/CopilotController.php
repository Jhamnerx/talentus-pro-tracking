<?php

namespace App\Http\Controllers\Ui\V2;

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
        return View::make('ui.v2.pages.copilot', [
            'pageTitle' => trans('ui.copilot_title'),
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
            // 'traccarDevice' NO es una relacion de Device (es 'traccar'):
            // with() lanzaba y el catch de abajo dejaba todo a cero.
            $user->devices()->with(['traccar'])->chunk(200, function ($chunk) use (&$stat, &$longOffline, &$longIdle, &$noFix, $now) {
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
            // sin esto, un fallo aqui devolvia la flota a cero sin rastro
            \Log::warning('Co-Pilot: fallo al recorrer la flota', ['error' => $e->getMessage()]);
        }

        if (count($longOffline) > 0) {
            $insights[] = [
                'severity' => 'critical',
                'category' => 'fleet_health',
                'icon'     => 'alert',
                'title'    => trans('ui.cp_offline_title', ['count' => count($longOffline)]),
                'detail'   => trans('ui.cp_offline_detail', ['names' => implode(', ', array_slice(array_column($longOffline, 'name'), 0, 5)) . (count($longOffline) > 5 ? '…' : '')]),
                'action'   => ['label' => trans('ui.cp_open_fleet'), 'url' => url('/ui/v2/fleet?status=offline')],
                'count'    => count($longOffline),
            ];
        }
        if (count($longIdle) > 0) {
            $insights[] = [
                'severity' => 'warning',
                'category' => 'efficiency',
                'icon'     => 'idle',
                'title'    => trans('ui.cp_idle_title', ['count' => count($longIdle)]),
                'detail'   => trans('ui.cp_idle_detail', ['names' => implode(', ', array_slice(array_column($longIdle, 'name'), 0, 5)) . (count($longIdle) > 5 ? '…' : '')]),
                'action'   => ['label' => trans('ui.cp_open_fleet'), 'url' => url('/ui/v2/fleet?status=idle')],
                'count'    => count($longIdle),
            ];
        }
        if (count($noFix) > 0) {
            $insights[] = [
                'severity' => 'info',
                'category' => 'data_quality',
                'icon'     => 'gps',
                'title'    => trans('ui.cp_nofix_title', ['count' => count($noFix)]),
                'detail'   => trans('ui.cp_nofix_detail', ['names' => implode(', ', array_slice(array_column($noFix, 'name'), 0, 5)) . (count($noFix) > 5 ? '…' : '')]),
                'action'   => ['label' => trans('ui.cp_open_live_ops'), 'url' => url('/ui/v2/live')],
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
                    'title'    => trans('ui.cp_sos_title', ['count' => $sosCount]),
                    'detail'   => trans('ui.cp_sos_detail'),
                    'action'   => ['label' => trans('ui.cp_open_alerts'), 'url' => url('/ui/v2/alerts?type=sos')],
                    'count'    => $sosCount,
                ];
            }
            if ($speedCount >= 10) {
                $insights[] = [
                    'severity' => 'warning',
                    'category' => 'safety',
                    'icon'     => 'speed',
                    'title'    => trans('ui.cp_speed_title', ['count' => $speedCount]),
                    'detail'   => trans('ui.cp_speed_detail'),
                    'action'   => ['label' => trans('ui.cp_open_alerts'), 'url' => url('/ui/v2/alerts')],
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
                        'title'    => trans('ui.cp_repeat_title', ['name' => $name]),
                        'detail'   => trans('ui.cp_repeat_detail', ['count' => $cnt]),
                        'action'   => ['label' => trans('ui.cp_open_history'), 'url' => url('/ui/v2/history?device=' . $devId)],
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
                'title'    => trans('ui.cp_util_title', ['percent' => $util]),
                'detail'   => trans('ui.cp_util_detail'),
                'action'   => ['label' => trans('ui.cp_open_dashboard'), 'url' => url('/ui/v2/dashboard')],
                'count'    => 0,
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'severity' => 'success',
                'category' => 'all_clear',
                'icon'     => 'check',
                'title'    => trans('ui.cp_all_clear_title'),
                'detail'   => trans('ui.cp_all_clear_detail'),
                'action'   => null,
                'count'    => 0,
            ];
        }

        $suggestions = [
            ['icon' => 'rocket', 'label' => trans('ui.cp_suggest_report'),      'url' => url('/ui/v2/reports')],
            ['icon' => 'map',    'label' => trans('ui.cp_suggest_zones'),        'url' => url('/ui/v2/zones')],
            ['icon' => 'eye',    'label' => trans('ui.cp_suggest_live'), 'url' => url('/ui/v2/live')],
            ['icon' => 'bell',   'label' => trans('ui.cp_suggest_alerts'),    'url' => url('/alerts/create')],
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
            ['match' => ['/(parad|deten|sin mov)/u'],          'reply' => trans('ui.cp_reply_stopped'),           'action' => ['label' => trans('ui.cp_action_fleet_stopped'),     'url' => url('/ui/v2/fleet?status=stopped')]],
            ['match' => ['/(en movim|circuland|activos?)/u'],  'reply' => trans('ui.cp_reply_moving'),       'action' => ['label' => trans('ui.cp_action_fleet_moving'), 'url' => url('/ui/v2/fleet?status=moving')]],
            ['match' => ['/(ralent|idle)/u'],                  'reply' => trans('ui.cp_reply_idle'),                          'action' => ['label' => trans('ui.cp_action_fleet_idle'),       'url' => url('/ui/v2/fleet?status=idle')]],
            ['match' => ['/(offline|sin (señal|reportar|conexi))/u'], 'reply' => trans('ui.cp_reply_offline'),          'action' => ['label' => trans('ui.cp_action_fleet_offline'),       'url' => url('/ui/v2/fleet?status=offline')]],
            ['match' => ['/(velocid|exceso|veloc)/u'],         'reply' => trans('ui.cp_reply_overspeed'), 'action' => ['label' => trans('ui.cp_action_events'),           'url' => url('/ui/v2/events?type=overspeed')]],
            ['match' => ['/(sos|panic|p[aá]nico)/u'],          'reply' => trans('ui.cp_reply_sos'),                           'action' => ['label' => trans('ui.cp_action_alerts_sos'),         'url' => url('/ui/v2/alerts?type=sos')]],
            ['match' => ['/(reporte|informe)/u'],              'reply' => trans('ui.cp_reply_reports'),             'action' => ['label' => trans('ui.cp_action_reports'),              'url' => url('/ui/v2/reports')]],
            ['match' => ['/(geocerca|zona)/u'],                'reply' => trans('ui.cp_reply_zones'),                         'action' => ['label' => trans('ui.cp_action_zones'),                 'url' => url('/ui/v2/zones')]],
            ['match' => ['/(historial|recorrido|replay|hoy|ayer)/u'], 'reply' => trans('ui.cp_reply_history'), 'action' => ['label' => trans('ui.cp_action_history'),               'url' => url('/ui/v2/history')]],
            ['match' => ['/(dashboard|resumen|kpi)/u'],        'reply' => trans('ui.cp_reply_dashboard'),                 'action' => ['label' => trans('ui.cp_action_dashboard'),             'url' => url('/ui/v2/dashboard')]],
        ];

        foreach ($intents as $it) {
            foreach ($it['match'] as $rx) {
                if (preg_match($rx, $lower)) {
                    $accion = $it['action'];

                    // "historial del T6G-611" llevaba al historial SIN el
                    // vehiculo, asi que habia que volver a elegirlo a mano.
                    // Si la pregunta nombra uno, se pasa en la URL.
                    if (strpos($accion['url'], '/ui/v2/history') !== false) {
                        $encontrado = $this->buscaVehiculo($q);
                        if ($encontrado) {
                            $accion['url'] .= '?device=' . $encontrado->id;
                        }
                    }

                    return response()->json([
                        'ok'     => true,
                        'reply'  => $it['reply'],
                        'action' => $accion,
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
                    'reply'   => trans('ui.cp_found', ['count' => $hit->count(), 'name' => $first->name]),
                    'action'  => ['label' => trans('ui.cp_open_in_live_ops'), 'url' => url('/ui/v2/live?device=' . $first->id)],
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
            'reply'  => trans('ui.cp_not_understood'),
            'action' => ['label' => trans('ui.cp_search_platform'), 'url' => url('/ui/v2/live?q=' . urlencode($q))],
            'query'  => $q,
        ]);
    }

    /**
     * Primer vehiculo del usuario que casa con el texto de la pregunta.
     * Se usa para enriquecer el enlace de una intencion (p. ej. historial).
     */
    private function buscaVehiculo(string $q)
    {
        // Por PALABRAS, no por la frase entera: "historial del T6G-611"
        // nunca casaria con el nombre de un vehiculo. Se prueban de mas
        // larga a mas corta, que la placa suele ser el token mas especifico.
        $palabras = preg_split('/[^A-Za-z0-9-]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $palabras = array_filter($palabras, function ($p) {
            return mb_strlen($p) >= 4;
        });
        usort($palabras, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        try {
            foreach ($palabras as $palabra) {
                $term = '%' . $palabra . '%';
                $encontrado = $this->user->devices()
                    ->where(function ($w) use ($term) {
                        $w->where('name', 'like', $term)
                            ->orWhere('imei', 'like', $term)
                            ->orWhere('plate_number', 'like', $term);
                    })
                    ->first(['devices.id', 'devices.name']);

                if ($encontrado) {
                    return $encontrado;
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }
}
