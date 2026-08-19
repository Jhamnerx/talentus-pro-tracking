<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * TalentusPro · DashboardController
 * Command Center: KPIs de flota + analítica de conducción (eventos).
 *
 * La analítica de conducción (frenados, aceleraciones, giros bruscos,
 * exceso de velocidad, SOS…) se calcula desde la tabla `events` (barata,
 * indexada por user_id + time) en vez del pipeline de History, que sería
 * demasiado costoso sobre toda la flota. Los eventos se clasifican en
 * categorías canónicas por su `type` nativo y, para los `custom`, por
 * palabras clave del `message` (así "Frenado Brusco", "Giro Brusco",
 * "Aceleración Brusca", "Exceso de velocidad Sutran"… caen en su bucket).
 */
class DashboardController extends BaseController
{
    /** Cache por request de las alertas compartidas (ver sharedAlertIds). */
    private ?array $sharedAlertIds = null;

    /** Categorías canónicas → etiqueta + color + si cuenta como riesgo de conducción. */
    private const CATEGORIES = [
        'harsh_braking'      => ['label' => 'Frenado brusco',        'color' => '#ef4444', 'risk' => true],
        'harsh_acceleration' => ['label' => 'Aceleración brusca',    'color' => '#f97316', 'risk' => true],
        'harsh_turning'      => ['label' => 'Giro brusco',           'color' => '#eab308', 'risk' => true],
        'overspeed'          => ['label' => 'Exceso de velocidad',   'color' => '#dc2626', 'risk' => true],
        'sos'                => ['label' => 'SOS / Pánico',          'color' => '#b91c1c', 'risk' => true],
        'jamming'            => ['label' => 'Interferencia (jammer)', 'color' => '#a21caf', 'risk' => true],
        'power'              => ['label' => 'Batería / energía',      'color' => '#7c3aed', 'risk' => false],
        'geofence'           => ['label' => 'Geocercas',             'color' => '#0ea5e9', 'risk' => false],
        'idle'               => ['label' => 'Ralentí',               'color' => '#f59e0b', 'risk' => false],
        'ignition'           => ['label' => 'Encendido / apagado',   'color' => '#64748b', 'risk' => false],
        'offline'            => ['label' => 'Desconexión',           'color' => '#94a3b8', 'risk' => false],
        'fuel'               => ['label' => 'Combustible',           'color' => '#16a34a', 'risk' => false],
        'driver'             => ['label' => 'Conductor',             'color' => '#2563eb', 'risk' => false],
        'other'              => ['label' => 'Otros',                 'color' => '#cbd5e1', 'risk' => false],
    ];

    public function index(Request $request)
    {
        if (! $this->user->perm('devices', 'view')) {
            abort(403, 'No tienes permiso para ver el panel.');
        }

        return View::make('ui.v2.pages.dashboard', [
            'pageTitle' => 'Command Center',
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('devices', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        // scope=all|live|period. Los cards "en vivo" (KPIs + donuts + top
        // velocidad + actividad) recorren TODA la flota (~1400 devices ≈ 850ms)
        // y NO dependen del rango; se piden solo en el boot y el polling. Al
        // cambiar de rango se pide solo scope=period (queries de events +
        // kilometraje, ~250ms) → los filtros responden rápido.
        $scope = (string) $request->get('scope', 'all');
        $out   = ['ok' => true];

        if ($scope === 'all' || $scope === 'live') {
            $out = array_merge($out, $this->fleetKpis());
            // Reparto por estado con los 6 grupos del legacy (incluye inactivo
            // y nunca conectado) y los colores que el usuario haya elegido.
            $out['overview'] = $this->deviceOverview();
        }

        if ($scope === 'all' || $scope === 'period') {
            [$range, $from, $to, $days] = $this->resolveRange($request);
            $out = array_merge(
                $out,
                ['range' => $range, 'from' => substr($from, 0, 10), 'to' => substr($to, 0, 10)],
                $this->drivingAnalytics($from, $days),
                [
                    'mileage'         => $this->mileageTop($from, $to),
                    'mileage_sampled' => $this->mileageSampled,
                    'notifications'   => $this->latestNotifications($from),
                    'latest_tasks'    => $this->latestTasks(),
                ],
            );
        }

        $out['fetchedAt'] = date('c');

        return response()->json($out);
    }

    /**
     * Últimas tareas (bloque latest_tasks del legacy). Vacío si el usuario no
     * tiene el permiso, para no filtrar nada.
     */
    private function latestTasks(): array
    {
        if (! $this->user->perm('tasks', 'view')) {
            return [];
        }

        try {
            $rows = \Tobuli\Entities\Task::whereIn('device_id', $this->user->devices()->pluck('devices.id'))
                ->with('device')
                ->orderBy('id', 'desc')
                ->limit(7)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $statuses = \Tobuli\Entities\TaskStatus::getList();

        return $rows->map(function ($t) use ($statuses) {
            return [
                'id'           => (int) $t->id,
                'title'        => (string) $t->title,
                'device'       => $t->device->name ?? null,
                'status'       => (int) $t->status,
                'status_label' => (string) ($statuses[$t->status] ?? ''),
                'priority'     => (int) $t->priority,
                // Ventana de entrega, ya en la zona del usuario.
                'delivery_to'  => $t->delivery_time_to ? \Formatter::time()->convert($t->delivery_time_to) : null,
            ];
        })->all();
    }

    /**
     * Reparto de la flota por estado para el donut, con los MISMOS seis grupos
     * y colores que el bloque device_overview del legacy (incluye "inactivo" y
     * "nunca conectado", que faltaban) — los colores salen de las opciones del
     * usuario y caen a los del catálogo si no los ha tocado.
     */
    private function deviceOverview(): array
    {
        $colors = (array) ($this->blockOptions('device_overview')['colors'] ?? []);
        $defaults = [
            'move' => '#52BE80', 'idle' => '#5DADE2', 'stop' => '#F7DC6F',
            'offline' => '#EC7063', 'inactive' => '#D7DBDD', 'never_connected' => '#AF7AC5',
        ];

        $devices = $this->user->devices();

        $rows = [
            ['key' => 'move',            'label' => trans('front.move'),            'count' => (clone $devices)->move()->count()],
            ['key' => 'idle',            'label' => trans('front.idle'),            'count' => (clone $devices)->idle()->count()],
            ['key' => 'stop',            'label' => trans('front.stop'),            'count' => (clone $devices)->park()->count()],
            ['key' => 'offline',         'label' => trans('front.offline'),         'count' => (clone $devices)->offline()->count()],
            ['key' => 'inactive',        'label' => trans('front.inactive'),        'count' => (clone $devices)->inactive()->count()],
            ['key' => 'never_connected', 'label' => trans('front.never_connected'), 'count' => (clone $devices)->neverConnected()->count()],
        ];

        // Cada grupo enlaza a la Flota filtrada, igual que el legacy enlazaba a
        // su tabla Lookup correspondiente.
        $filters = [
            'move' => 'moving', 'idle' => 'idle', 'stop' => 'stopped',
            'offline' => 'offline', 'inactive' => 'inactive', 'never_connected' => 'never_connected',
        ];

        foreach ($rows as $i => $r) {
            $rows[$i]['color'] = (string) ($colors[$r['key']] ?? $defaults[$r['key']]);
            $rows[$i]['url']   = url('/ui/v2/fleet') . '?status=' . $filters[$r['key']];
        }

        return $rows;
    }

    /** true cuando el top de kilometraje sale de una muestra, no de la flota entera. */
    private bool $mileageSampled = true;

    /** Opciones que el usuario guardó para un bloque del tablero. */
    private function blockOptions(string $block): array
    {
        $settings = (array) $this->user->getSettings('dashboard');

        return (array) ($settings['blocks'][$block]['options'] ?? []);
    }

    /** Presets tipo Wialon (hoy/ayer/semana/mes) o rango custom from/to. */
    private function resolveRange(Request $request): array
    {
        $range = (string) $request->get('range', 'week');
        // Base "ahora" en la ZONA HORARIA DEL USUARIO (no la del servidor),
        // igual que ReportsDailyCommand: Formatter::time()->now() ya viene
        // desplazado a la tz del usuario.
        $now = (int) \Formatter::time()->now() ?: time();

        // Fuente única de verdad: si el front manda from/to (siempre lo hace,
        // calculado ahí para los presets también), se consultan EXACTAMENTE
        // esas fechas. El 'range' queda solo como etiqueta. Solo se recalcula
        // aquí si no llegaran (compatibilidad / llamadas directas).
        $reqFrom = strtotime((string) $request->get('from'));
        $reqTo   = strtotime((string) $request->get('to'));
        if ($reqFrom && $reqTo) {
            $from = date('Y-m-d 00:00:00', $reqFrom);
            $to   = date('Y-m-d 23:59:59', $reqTo);
            $days = max(1, (int) ceil(($reqTo - $reqFrom) / 86400));

            return [$range, $from, $to, $days];
        }

        switch ($range) {
            case 'today':
                $from = date('Y-m-d 00:00:00', $now);
                $to   = date('Y-m-d 23:59:59', $now);
                break;
            case 'yesterday':
                $from = date('Y-m-d 00:00:00', strtotime('-1 day', $now));
                $to   = date('Y-m-d 23:59:59', strtotime('-1 day', $now));
                break;
            case 'month':
                $from = date('Y-m-d 00:00:00', strtotime('-29 days', $now));
                $to   = date('Y-m-d 23:59:59', $now);
                break;
            case 'custom':
                $f = strtotime((string) $request->get('from')) ?: strtotime('-6 days', $now);
                $t = strtotime((string) $request->get('to')) ?: $now;
                $from = date('Y-m-d 00:00:00', $f);
                $to   = date('Y-m-d 23:59:59', $t);
                break;
            case 'week':
            default:
                $range = 'week';
                $from  = date('Y-m-d 00:00:00', strtotime('-6 days', $now));
                $to    = date('Y-m-d 23:59:59', $now);
                break;
        }

        $days = max(1, (int) ceil((strtotime($to) - strtotime($from)) / 86400));

        return [$range, $from, $to, $days];
    }

    /* ================= Estado de flota (en vivo) ================= */

    private function fleetKpis(): array
    {
        $buckets    = ['moving' => 0, 'idle' => 0, 'stopped' => 0, 'offline' => 0];
        $health     = ['good' => 0, 'attention' => 0, 'bad' => 0];
        $conn       = ['connected' => 0, 'disconnected' => 0];
        $top        = [];
        $recent     = [];
        $total      = 0;
        $sumSpeed   = 0;
        $countSpeed = 0;
        $lastDay    = strtotime('-24 hours');

        $this->user->devices()
            ->clearOrdersBy()
            ->chunk(200, function ($chunk) use (&$buckets, &$health, &$conn, &$top, &$recent, &$total, &$sumSpeed, &$countSpeed, $lastDay) {
                foreach ($chunk as $device) {
                    $total++;
                    $status = $device->getStatus();
                    $group  = $this->groupStatus($status);
                    $buckets[$group]++;

                    // Estado de dispositivos (salud) — estilo Wialon:
                    //  buen estado = conectado/con datos · atención = offline ·
                    //  mal estado = expirado.
                    if ($status === 'expired') {
                        $health['bad']++;
                    } elseif ($group === 'offline') {
                        $health['attention']++;
                    } else {
                        $health['good']++;
                    }

                    // Conexión: conectado = no offline/expirado
                    if ($group === 'offline' || $status === 'expired') {
                        $conn['disconnected']++;
                    } else {
                        $conn['connected']++;
                    }

                    $speed = (float) $device->getSpeed();
                    if ($speed > 0) {
                        $sumSpeed += $speed;
                        $countSpeed++;
                    }

                    $top[] = ['id' => $device->id, 'name' => $device->name, 'speed' => $speed, 'status' => $status];

                    $movedAt = $device->moved_at ? strtotime($device->moved_at) : null;
                    if ($movedAt && $movedAt >= $lastDay) {
                        $hour = (int) date('G', $movedAt);
                        $recent[$hour] = ($recent[$hour] ?? 0) + 1;
                    }
                }
            });

        usort($top, fn ($a, $b) => $b['speed'] <=> $a['speed']);
        $top = array_slice($top, 0, 5);

        $hours = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour    = (int) date('G', strtotime("-{$i} hours"));
            $hours[] = ['hour' => sprintf('%02d:00', $hour), 'count' => $recent[$hour] ?? 0];
        }

        return [
            'kpis' => [
                'total'       => $total,
                'moving'      => $buckets['moving'],
                'idle'        => $buckets['idle'],
                'stopped'     => $buckets['stopped'],
                'offline'     => $buckets['offline'],
                'avgSpeed'    => $countSpeed ? round($sumSpeed / $countSpeed, 1) : 0,
                'utilization' => $total > 0 ? round(($buckets['moving'] / $total) * 100) : 0,
            ],
            'health' => [
                'total' => $total,
                'items' => [
                    ['name' => 'En buen estado',    'value' => $health['good'],      'color' => '#10b981'],
                    ['name' => 'Requieren atención', 'value' => $health['attention'], 'color' => '#f59e0b'],
                    ['name' => 'En mal estado',      'value' => $health['bad'],       'color' => '#ef4444'],
                ],
            ],
            'connection' => [
                'total' => $total,
                'items' => [
                    ['name' => 'Conectadas',    'value' => $conn['connected'],    'color' => '#10b981'],
                    ['name' => 'No conectadas', 'value' => $conn['disconnected'], 'color' => '#cbd5e1'],
                ],
            ],
            'distribution' => [
                ['name' => 'En movimiento', 'value' => $buckets['moving'],  'color' => '#10b981'],
                ['name' => 'Inactivo',      'value' => $buckets['idle'],    'color' => '#f59e0b'],
                ['name' => 'Detenido',      'value' => $buckets['stopped'], 'color' => '#3b82f6'],
                ['name' => 'Sin datos',     'value' => $buckets['offline'], 'color' => '#a855f7'],
            ],
            'topSpeed' => $top,
            'activity' => $hours,
        ];
    }

    /** Kilometraje por unidad en el período (top N). Usa getDistanceBetween
     *  sobre los dispositivos con actividad reciente (mismo enfoque acotado
     *  que el bloque legacy device_distance para no recorrer toda la flota). */
    private function mileageTop(string $from, string $to): array
    {
        // El bloque device_distance del legacy deja elegir vehículos
        // (options.devices) pero su Block los ignora y muestrea 10 por
        // updated_at. Aquí se respeta la elección: si hay vehículos elegidos el
        // cálculo es exacto sobre ESOS; si no, se muestrea y se avisa (sampled).
        $chosen = array_values(array_filter(array_map(
            'intval',
            (array) ($this->blockOptions('device_distance')['devices'] ?? [])
        )));

        $query = $this->user->devices()->clearOrdersBy();

        if ($chosen) {
            $query->whereIn('devices.id', $chosen);
            $this->mileageSampled = false;
        } else {
            $query->orderBy('updated_at', 'desc')->limit(15);
            $this->mileageSampled = true;
        }

        $devices = $query->get();

        $mileage = new \Tobuli\Services\DeviceMileageService();
        $rows = [];
        foreach ($devices as $device) {
            // Misma fuente y MISMA ventana que el Historial: las fechas del
            // rango vienen en la zona del usuario y las posiciones se guardan en
            // la de almacenamiento, así que se convierten antes de consultar.
            $km = $mileage->forRange(
                $device,
                \Formatter::time()->reverse(substr($from, 0, 10) . ' 00:00'),
                \Formatter::time()->reverse($to)
            );
            $rows[] = ['id' => (int) $device->id, 'name' => (string) $device->name, 'km' => round($km, 2)];
        }

        usort($rows, fn ($a, $b) => $b['km'] <=> $a['km']);

        return array_slice($rows, 0, 8);
    }

    /** Últimas notificaciones (eventos) del período. */
    private function latestNotifications(string $from): array
    {
        $rows = DB::table('events')
            ->join('devices', 'devices.id', '=', 'events.device_id')
            ->where('events.time', '>=', $from)
            ->tap(function ($q) { $this->scopeEvents($q, 'events.'); })
            ->orderByDesc('events.time')
            ->limit(12)
            ->get(['events.type', 'events.message', 'events.time', 'devices.name']);

        $out = [];
        foreach ($rows as $r) {
            $cat = $this->classify($r->type, (string) $r->message);
            $out[] = [
                'device'  => (string) $r->name,
                'message' => $r->message ?: (self::CATEGORIES[$cat]['label'] ?? $r->type),
                // UTC → TZ del usuario (la lista mostraba hora de servidor)
                'time'    => $r->time ? \Formatter::time()->convert($r->time) : '',
                'color'   => self::CATEGORIES[$cat]['color'] ?? '#cbd5e1',
            ];
        }

        return $out;
    }

    /* ================= Analítica de conducción (eventos) ================= */

    /**
     * Ids de alertas COMPARTIDAS con el usuario (pivote alert_user). Paridad
     * con Event::userAccessible: los eventos de esas alertas también son suyos
     * — sin esto, los sub-usuarios veían el dashboard vacío.
     */
    private function sharedAlertIds(): array
    {
        if ($this->sharedAlertIds === null) {
            try {
                $this->sharedAlertIds = DB::table('alert_user')
                    ->where('user_id', $this->user->id)
                    ->pluck('alert_id')->all();
            } catch (\Throwable $e) {
                $this->sharedAlertIds = [];
            }
        }

        return $this->sharedAlertIds;
    }

    /** Aplica el alcance de eventos accesibles (propios + alertas compartidas). */
    private function scopeEvents($query, string $prefix = '')
    {
        $col    = $prefix ? $prefix . 'user_id' : 'user_id';
        $alerts = $this->sharedAlertIds();
        $alertC = $prefix ? $prefix . 'alert_id' : 'alert_id';

        return $query->where(function ($q) use ($col, $alerts, $alertC) {
            $q->where($col, $this->user->id);
            if ($alerts) {
                $q->orWhereIn($alertC, $alerts);
            }
        });
    }

    private function drivingAnalytics(string $from, int $days): array
    {
        // 1) Eventos nativos por tipo + 2) custom por mensaje → clasificar
        $catCounts = array_fill_keys(array_keys(self::CATEGORIES), 0);

        $byType = DB::table('events')
            ->where('time', '>=', $from)
            ->tap(function ($q) { $this->scopeEvents($q); })
            ->where('type', '!=', 'custom')
            ->select('type', DB::raw('COUNT(*) c'))
            ->groupBy('type')->get();
        foreach ($byType as $r) {
            $catCounts[$this->classify($r->type, '')] += (int) $r->c;
        }

        $byMsg = DB::table('events')
            ->where('time', '>=', $from)
            ->tap(function ($q) { $this->scopeEvents($q); })
            ->where('type', 'custom')
            ->select('message', DB::raw('COUNT(*) c'))
            ->groupBy('message')->get();
        foreach ($byMsg as $r) {
            $catCounts[$this->classify('custom', (string) $r->message)] += (int) $r->c;
        }

        // Card de conducción: los 4 comportamientos clave
        $driving = [
            'harsh_braking'      => $catCounts['harsh_braking'],
            'harsh_acceleration' => $catCounts['harsh_acceleration'],
            'harsh_turning'      => $catCounts['harsh_turning'],
            'overspeed'          => $catCounts['overspeed'],
        ];

        // Desglose por categoría (todas las que tienen datos), ordenado
        $byCategory = [];
        $totalEvents = 0;
        foreach (self::CATEGORIES as $key => $meta) {
            $totalEvents += $catCounts[$key];
            if ($catCounts[$key] > 0) {
                $byCategory[] = [
                    'key'   => $key,
                    'label' => $meta['label'],
                    'color' => $meta['color'],
                    'count' => $catCounts[$key],
                    'risk'  => $meta['risk'],
                ];
            }
        }
        usort($byCategory, fn ($a, $b) => $b['count'] <=> $a['count']);

        // Índice de conducción (0-100): penaliza eventos de riesgo por vehículo
        // con datos en el período. Heurístico y transparente (se muestran los
        // conteos crudos); menos eventos de riesgo = mejor puntuación.
        $riskEvents = $driving['harsh_braking'] + $driving['harsh_acceleration']
            + $driving['harsh_turning'] + $driving['overspeed'] + $catCounts['sos'];

        $vehiclesWithData = (int) DB::table('events')
            ->where('time', '>=', $from)
            ->tap(function ($q) { $this->scopeEvents($q); })
            ->distinct()->count('device_id');

        $riskPerVehicle = $vehiclesWithData ? $riskEvents / $vehiclesWithData : 0;
        $ecoScore = max(0, min(100, (int) round(100 - $riskPerVehicle * 1.5)));

        // Top vehículos con más eventos de riesgo (frenado/aceleración/giro/
        // exceso/SOS) en el período. Se filtra en PHP por message/type.
        $topRisk = $this->topRiskDevices($from);

        // Tendencia diaria de eventos de riesgo (para mini-gráfico)
        $trend = $this->riskTrend($from, $days);

        return [
            'driving'    => $driving,
            'byCategory' => $byCategory,
            'totalEvents' => $totalEvents,
            'ecoScore'   => $ecoScore,
            'riskEvents' => $riskEvents,
            'vehiclesWithData' => $vehiclesWithData,
            'topRisk'    => $topRisk,
            'trend'      => $trend,
        ];
    }

    /** Top 5 dispositivos por eventos de riesgo en el período. */
    private function topRiskDevices(string $from): array
    {
        // Conjunto de mensajes/tipos de riesgo, clasificados en PHP tras un
        // group by (device_id, type, message) acotado al período.
        $rows = DB::table('events')
            ->join('devices', 'devices.id', '=', 'events.device_id')
            ->where('events.time', '>=', $from)
            ->tap(function ($q) { $this->scopeEvents($q, 'events.'); })
            ->select('events.device_id', 'devices.name', 'events.type', 'events.message', DB::raw('COUNT(*) c'))
            ->groupBy('events.device_id', 'devices.name', 'events.type', 'events.message')
            ->get();

        $byDevice = [];
        foreach ($rows as $r) {
            $cat = $this->classify($r->type, (string) $r->message);
            if (empty(self::CATEGORIES[$cat]['risk'])) {
                continue;
            }
            $id = (int) $r->device_id;
            if (! isset($byDevice[$id])) {
                $byDevice[$id] = ['id' => $id, 'name' => (string) $r->name, 'count' => 0];
            }
            $byDevice[$id]['count'] += (int) $r->c;
        }

        $byDevice = array_values($byDevice);
        usort($byDevice, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($byDevice, 0, 5);
    }

    /** Serie diaria de eventos de riesgo. */
    private function riskTrend(string $from, int $days): array
    {
        $rows = DB::table('events')
            ->where('time', '>=', $from)
            ->tap(function ($q) { $this->scopeEvents($q); })
            ->select(DB::raw('DATE(time) d'), 'type', 'message', DB::raw('COUNT(*) c'))
            ->groupBy(DB::raw('DATE(time)'), 'type', 'message')
            ->get();

        $perDay = [];
        foreach ($rows as $r) {
            $cat = $this->classify($r->type, (string) $r->message);
            if (empty(self::CATEGORIES[$cat]['risk'])) {
                continue;
            }
            $perDay[$r->d] = ($perDay[$r->d] ?? 0) + (int) $r->c;
        }

        // Rellena todos los días del rango (máx ~90 puntos visibles)
        $points = [];
        $step = $days > 90 ? 7 : 1; // en 1 año, agrupa por semana visualmente
        for ($i = $days - 1; $i >= 0; $i -= $step) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $sum = 0;
            for ($j = 0; $j < $step; $j++) {
                $dd = date('Y-m-d', strtotime("-" . ($i - $j) . " days"));
                $sum += $perDay[$dd] ?? 0;
            }
            $points[] = ['date' => $d, 'count' => $sum];
        }

        return $points;
    }

    /* ================= Clasificador ================= */

    /** Mapa type/message → categoría canónica. */
    private function classify(string $type, string $message): string
    {
        // Tipos nativos primero
        switch ($type) {
            case 'overspeed':
            case 'geofence_overspeed':
                return 'overspeed';
            case 'sos':
                return 'sos';
            case 'zone_in':
            case 'zone_out':
                return 'geofence';
            case 'idle_duration':
            case 'poi_idle_duration':
                return 'idle';
            case 'offline_duration':
                return 'offline';
            case 'ignition_on':
            case 'ignition_off':
                return 'ignition';
            case 'unplugged':
            case 'plugged_in':
                return 'power';
            case 'fuel_fill':
            case 'fuel_theft':
                return 'fuel';
            case 'driver':
            case 'driver_authorized':
            case 'driver_unauthorized':
                return 'driver';
        }

        // Custom: clasificar por palabras clave del mensaje (sin acentos)
        $m = $this->normalize($message);
        if ($m === '') {
            return 'other';
        }
        if (str_contains($m, 'frenad') || str_contains($m, 'brak')) return 'harsh_braking';
        if (str_contains($m, 'aceler') || str_contains($m, 'accel')) return 'harsh_acceleration';
        if (str_contains($m, 'giro') || str_contains($m, 'corner') || str_contains($m, 'turn')) return 'harsh_turning';
        if (str_contains($m, 'exceso') || str_contains($m, 'velocidad') || str_contains($m, 'overspeed') || str_contains($m, 'speed')) return 'overspeed';
        if (str_contains($m, 'sos') || str_contains($m, 'panic') || str_contains($m, 'boton')) return 'sos';
        if (str_contains($m, 'jamming') || str_contains($m, 'jammer') || str_contains($m, 'interferen')) return 'jamming';
        if (str_contains($m, 'bateria') || str_contains($m, 'desconexion de bat') || str_contains($m, 'power') || str_contains($m, 'energia')) return 'power';
        if (str_contains($m, 'encendid') || str_contains($m, 'apagad') || str_contains($m, 'ignition') || str_contains($m, 'motor')) return 'ignition';
        if (str_contains($m, 'geocerca') || str_contains($m, 'zona') || str_contains($m, 'zone')) return 'geofence';
        if (str_contains($m, 'ralent') || str_contains($m, 'idle')) return 'idle';
        if (str_contains($m, 'combustible') || str_contains($m, 'fuel')) return 'fuel';
        if (str_contains($m, 'conductor') || str_contains($m, 'driver') || str_contains($m, 'rfid')) return 'driver';

        return 'other';
    }

    /** minúsculas + sin acentos para clasificar por palabra clave. */
    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
