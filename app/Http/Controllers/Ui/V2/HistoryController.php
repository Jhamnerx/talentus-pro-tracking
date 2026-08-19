<?php

namespace App\Http\Controllers\Ui\V2;

use App\Exceptions\PermissionException;
use App\Transformers\Device\DeviceIconTransformer;
use CustomFacades\ModalHelpers\HistoryModalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Exceptions\ValidationException;

/**
 * TalentusPro · HistoryController (UI v2)
 *
 * Reutiliza el PIPELINE legacy de historial (Tobuli\History vía
 * HistoryModalHelper) para tener paridad exacta en el timeline
 * (viajes/paradas/eventos con combustible GPS, horas motor, velocidades,
 * conductor + resumen) y en el registro de datos (getMessages), evitando
 * reimplementar la lógica de negocio.
 */
class HistoryController extends BaseController
{
    // Estados del historial (mismos valores que HistoryModalHelper).
    const S_DRIVE = 1;
    const S_STOP  = 2;
    const S_START = 3;
    const S_END   = 4;
    const S_EVENT = 5;

    public function index(Request $request)
    {
        if (! $this->user->perm('history', 'view')) {
            abort(403, 'No tienes permiso para ver historial.');
        }

        return View::make('ui.v2.pages.history', [
            'pageTitle'        => trans('front.history'),
            'mapConfig'        => $this->buildMapConfig(),
            'canViewGeofences' => $this->user->perm('geofences', 'view'),
            'canViewPois'      => $this->user->perm('poi', 'view'),
            'canViewRoutes'    => $this->user->perm('routes', 'view'),
            // Cadenas para el JS (mismos archivos de traducción que el legacy)
            'i18n'             => [
                'trip_start'       => trans('front.trip_start'),
                'trip_end'         => trans('front.trip_end'),
                // Títulos claros del evento (claves nuevas: los legacy
                // 'drive'→"Conducir" / 'stop'→"Estacionados" confundían)
                'drive'            => trans('front.driving'),
                'stop'             => trans('front.parked'),
                'event'            => trans('front.event'),
                'driver'           => trans('front.driver'),
                'route_length'     => trans('front.route_length'),
                'move_duration'    => trans('front.move_duration'),
                'stop_duration'    => trans('front.stop_duration'),
                'top_speed'        => trans('front.top_speed'),
                'fuel_consumption' => trans('front.fuel_consumption'),
                'engine_hours'     => trans('front.engine_hours'),
                'duration'         => trans('front.duration'),
                'speed'            => trans('front.speed'),
                'altitude'         => trans('front.altitude'),
                'time'             => trans('front.time'),
                'start'            => trans('front.start'),
                'end'              => trans('front.end'),
                'drive_start'      => trans('front.drive_start'),
                'drive_end'        => trans('front.drive_end'),
                'no_data'          => trans('front.no_data'),
                'page'             => trans('front.page'),
                'export'           => trans('front.export'),
                'download'         => trans('front.download'),
                'today'            => trans('front.today'),
                'select_device'    => trans('front.select_device'),
                'select_date'      => trans('front.select_date'),
                'generating_export' => trans('front.generating_export'),
                'export_failed'    => trans('front.export_failed'),
                'loading'          => trans('front.loading'),
            ],
        ]);
    }

    /** Lista ligera de vehículos para el selector searcheable. */
    public function devices(Request $request): JsonResponse
    {
        if (! $this->user->perm('history', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $rows = $this->user->devices()->orderBy('name', 'asc')->get(['id', 'name', 'plate_number']);
        $items = [];
        foreach ($rows as $d) {
            $items[] = [
                'id'    => (int) $d->id,
                'name'  => (string) ($d->name ?: ('#' . $d->id)),
                'plate' => (string) ($d->plate_number ?? ''),
            ];
        }

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('history', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        [$fromDate, $fromTime] = $this->splitDateTime((string) $request->get('from', ''));
        [$toDate, $toTime]     = $this->splitDateTime((string) $request->get('to', ''));
        $deviceId = (int) $request->get('device', 0);

        if (! $deviceId || ! $fromDate || ! $toDate) {
            return response()->json(['ok' => false, 'error' => 'missing_params'], 422);
        }

        $payload = [
            'device_id'    => $deviceId,
            'from_date'    => $fromDate,
            'from_time'    => $fromTime,
            'to_date'      => $toDate,
            'to_time'      => $toTime,
            'stops'        => (int) $request->get('stops', 180),
            'snap_to_road' => $request->boolean('snap') ? 'true' : 'false',
        ];

        try {
            HistoryModalHelper::setUser($this->user);
            HistoryModalHelper::setApi(true);
            HistoryModalHelper::setData($payload);
            $res = HistoryModalHelper::getApi();
        } catch (ValidationException $e) {
            return response()->json([
                'ok'     => false,
                'error'  => 'validation',
                'errors' => $e->getErrors() ? $e->getErrors()->toArray() : [],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
        }

        $showInvalid = $request->boolean('show_invalid', true);

        // Aplanar posiciones (solo de grupos drive/stop, contiguas) + timeline.
        $positions = [];
        $timeline  = [];
        $drives = 0; $stops = 0; $events = 0;

        foreach (($res['items'] ?? []) as $it) {
            $status   = $it['status'] ?? null;
            $isSeg    = in_array($status, [self::S_DRIVE, self::S_STOP], true);
            $startIdx = count($positions);

            if ($isSeg) {
                foreach (($it['items'] ?? []) as $p) {
                    if (! $showInvalid && isset($p['valid']) && ! $p['valid']) {
                        continue;
                    }
                    $t = (string) ($p['raw_time'] ?? $p['time'] ?? '');
                    // Valores de sensores con add_to_graph (positionToItem →
                    // sensors_data: speed/altitude + sensor_<id>).
                    $sv = [];
                    foreach (($p['sensors_data'] ?? []) as $sd) {
                        $sid = (string) ($sd['id'] ?? '');
                        if (strpos($sid, 'sensor_') === 0) {
                            $sv[$sid] = (float) ($sd['value'] ?? 0);
                        }
                    }
                    $positions[] = [
                        'id'  => (int) ($p['id'] ?? 0),
                        'lat' => (float) ($p['lat'] ?? $p['latitude'] ?? 0),
                        'lng' => (float) ($p['lng'] ?? $p['longitude'] ?? 0),
                        'spd' => (float) ($p['speed'] ?? 0),
                        'crs' => isset($p['course']) ? (float) $p['course'] : null,
                        'alt' => isset($p['altitude']) ? (float) $p['altitude'] : null,
                        't'   => $t,
                        'ts'  => $t ? strtotime($t) : null,
                        'color' => $p['color'] ?? null,
                        'v'   => isset($p['valid']) ? (int) (bool) $p['valid'] : 1,
                        'sv'  => $sv ?: null,
                    ];
                }
                if ($status === self::S_DRIVE) $drives++; else $stops++;
            } elseif ($status === self::S_EVENT) {
                $events++;
            }

            $first = ($it['items'][0] ?? null);
            $timeline[] = [
                'status'           => $status,
                'index'            => $it['index'] ?? null,
                'from'             => $it['show'] ?? null,
                'to'               => $it['left'] ?? null,
                'duration'         => $it['time'] ?? null,
                'distance'         => $it['distance'] ?? null,
                'top_speed'        => $it['top_speed'] ?? null,
                'average_speed'    => $it['average_speed'] ?? null,
                'engine_hours'     => $it['engine_hours'] ?? null,
                'fuel_consumption' => $it['fuel_consumption'] ?? null,
                'driver'           => isset($it['driver']['name']) ? $it['driver']['name'] : (is_string($it['driver'] ?? null) ? $it['driver'] : null),
                'message'          => $it['message'] ?? null,
                'start_idx'        => $isSeg ? $startIdx : ($status === self::S_END ? max(0, count($positions) - 1) : $startIdx),
                'lat'              => $first['lat'] ?? ($it['lat'] ?? null),
                'lng'             => $first['lng'] ?? ($it['lng'] ?? null),
            ];
        }

        // Eventos → índice de posición más cercano por timestamp.
        foreach ($timeline as &$t) {
            if ($t['status'] === self::S_EVENT && $t['lat'] !== null) {
                $ets = $t['from'] ? strtotime((string) $t['from']) : null;
                $t['start_idx'] = $this->nearestIdx($positions, $ets);
            }
        }
        unset($t);

        $device = $this->user->devices()->with(['icon', 'traccar'])->where('devices.id', $deviceId)->first();
        $iconT  = new DeviceIconTransformer();

        return response()->json([
            'ok'        => true,
            'device'    => $device ? [
                'id'         => (int) $device->id,
                'name'       => (string) $device->name,
                'plate'      => (string) ($device->plate_number ?? ''),
                'icon'       => $iconT->transform($device),
                'icon_color' => $device->getStatusColor(),
                // Colores del recorrido: propiedad del vehículo (estilo Wialon)
                'route'      => [
                    'type'   => (string) ($device->route_color_type ?: 'trips'),
                    'color'  => (string) ($device->route_color ?: '#2563eb'),
                    'ranges' => (function () use ($device) {
                        $r = json_decode((string) ($device->route_speed_ranges ?? ''), true);
                        return is_array($r) && $r ? $r : null;
                    })(),
                ],
            ] : null,
            'positions' => $positions,
            'timeline'  => $timeline,
            'sensors'   => $res['sensors'] ?? [],
            'summary'   => [
                // Km recorrido: fuente ÚNICA (DeviceMileageService) para que
                // coincida con el Tablero. La suma por tramos que se usaba antes
                // perdía el primer kilómetro del rango (ver el servicio).
                'distance'      => (new \Tobuli\Services\DeviceMileageService())
                    ->formatted($device, $fromDate . ' ' . $fromTime . ':00', $toDate . ' ' . $toTime . ':59'),
                // Distancia GPS del pipeline, informativa (haversine punto a punto).
                'distance_gps'  => $res['distance_sum'] ?? null,
                'top_speed'     => $res['top_speed'] ?? null,
                'move_duration' => $res['move_duration'] ?? null,
                'stop_duration' => $res['stop_duration'] ?? null,
                'engine_hours'  => $res['engine_hours'] ?? null,
                'fuel'          => $res['fuel_consumption'] ?? null,
                'drives'        => $drives,
                'stops'         => $stops,
                'events'        => $events,
                'points'        => count($positions),
            ],
        ]);
    }

    /**
     * Registro de datos (posiciones paginadas) — reusa getMessages() legacy.
     */
    public function messages(Request $request): JsonResponse
    {
        if (! $this->user->perm('history', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        [$fromDate, $fromTime] = $this->splitDateTime((string) $request->get('from', ''));
        [$toDate, $toTime]     = $this->splitDateTime((string) $request->get('to', ''));

        $payload = [
            'device_id' => (int) $request->get('device', 0),
            'from_date' => $fromDate,
            'from_time' => $fromTime,
            'to_date'   => $toDate,
            'to_time'   => $toTime,
            'limit'     => (int) $request->get('limit', 50),
            'page'      => (int) $request->get('page', 1),
            // El helper solo entiende sorting=='true' o sorting['sort']=='desc';
            // mandar la cadena 'desc' NO ordenaba (data-log siempre ascendente).
            'sorting'   => ['sort' => $request->get('sorting') === 'desc' ? 'desc' : 'asc'],
        ];

        try {
            HistoryModalHelper::setUser($this->user);
            HistoryModalHelper::setApi(true);
            HistoryModalHelper::setData($payload);
            $res = HistoryModalHelper::getMessages();
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation'], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
        }

        $messages = $res['messages'] ?? [];
        $data     = is_array($messages) ? ($messages['data'] ?? []) : [];

        // Columnas base fijas + TODOS los parámetros dinámicos del XML `other`
        // (ignition, status, sequence, distance, totaldistance, motion,
        // enginehours, immobilizer, accuracy, sat…), igual que el legacy que
        // descubre las claves con parseXMLToArray/$parameters.
        $columns = [
            ['key' => 'time',        'label' => trans('front.time')],
            ['key' => 'server_time', 'label' => trans('front.server_time')],
            ['key' => 'latitude',    'label' => trans('front.latitude')],
            ['key' => 'longitude',   'label' => trans('front.longitude')],
            ['key' => 'altitude',    'label' => trans('front.altitude')],
            ['key' => 'speed',       'label' => trans('front.speed')],
            ['key' => 'course',      'label' => trans('front.angle')],
            ['key' => 'valid',       'label' => trans('front.valid')],
        ];
        $baseKeys  = ['time', 'server_time', 'latitude', 'longitude', 'altitude', 'speed', 'course', 'valid'];
        $paramKeys = array_keys($res['parameters'] ?? []);
        foreach ($paramKeys as $k) {
            if ($k === '' || in_array($k, $baseKeys, true)) {
                continue;
            }
            $columns[] = ['key' => $k, 'label' => $k];
        }

        $rows = [];
        foreach ($data as $m) {
            $oa  = $m['other_array'] ?? ($m['other_arr'] ?? []);
            $row = [
                // id de la posición: no es columna visible, lo consume el
                // borrado de posiciones (perm history.remove).
                'id'          => isset($m['id']) ? (int) $m['id'] : null,
                'time'        => $m['time'] ?? null,
                'server_time' => $m['server_time'] ?? null,
                'latitude'    => $m['latitude'] ?? null,
                'longitude'   => $m['longitude'] ?? null,
                'altitude'    => $m['altitude'] ?? null,
                'speed'       => $m['speed'] ?? null,
                'course'      => $m['course'] ?? null,
                'valid'       => $m['valid'] ?? null,
            ];
            foreach ($paramKeys as $k) {
                if ($k === '' || in_array($k, $baseKeys, true)) {
                    continue;
                }
                $v = is_array($oa) ? ($oa[$k] ?? '') : '';
                $row[$k] = is_scalar($v) ? $v : json_encode($v);
            }
            $rows[] = $row;
        }

        return response()->json([
            'ok'      => true,
            'columns' => $columns,
            'rows'    => $rows,
            'page'    => is_array($messages) ? ($messages['current_page'] ?? 1) : 1,
            'last'    => is_array($messages) ? ($messages['last_page'] ?? 1) : 1,
            'total'   => is_array($messages) ? ($messages['total'] ?? count($rows)) : count($rows),
        ]);
    }

    /** Divide 'Y-m-d H:i[:s]' o 'Y-m-dTH:i' en [date, time]. */
    /**
     * Borrar posiciones seleccionadas del registro de datos.
     * DELETE history/positions  — delega en el helper legacy, que aplica
     * checkException('history','remove') + ('devices','own') y regenera el tail.
     */
    public function destroyPositions(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            (array) $request->input('ids', [])
        )));

        if (! $ids) {
            return response()->json(['ok' => false, 'error' => 'no_ids'], 422);
        }

        try {
            HistoryModalHelper::setUser($this->user);
            HistoryModalHelper::setApi(true);
            HistoryModalHelper::setData([
                'device_id' => (int) $request->input('device', 0),
                'ids'       => $ids,
            ]);
            HistoryModalHelper::deletePositions();
        } catch (PermissionException $e) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'deleted' => count($ids)]);
    }

    private function splitDateTime(string $dt): array
    {
        $dt = trim(str_replace('T', ' ', $dt));
        if ($dt === '') return ['', ''];
        $parts = explode(' ', $dt, 2);
        $date  = $parts[0];
        $time  = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : '00:00:00';
        if (preg_match('/^\d{2}:\d{2}$/', $time)) $time .= ':00';
        return [$date, $time];
    }

    /** Índice de la posición cuyo timestamp es el más cercano a $ts. */
    private function nearestIdx(array $positions, $ts): ?int
    {
        if (empty($positions) || ! $ts) return null;
        $best = null; $bestDiff = PHP_INT_MAX;
        foreach ($positions as $i => $p) {
            if ($p['ts'] === null) continue;
            $diff = abs($p['ts'] - $ts);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $i; }
            elseif ($p['ts'] > $ts) break;
        }
        return $best;
    }
}

/**
 * Constantes de estado del historial (mismos valores que HistoryModalHelper).
 */
function self_STATUS(string $k): int
{
    static $map = ['DRIVE' => 1, 'STOP' => 2, 'START' => 3, 'END' => 4, 'EVENT' => 5];
    return $map[$k] ?? 0;
}
