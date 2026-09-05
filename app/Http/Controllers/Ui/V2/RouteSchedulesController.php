<?php

namespace App\Http\Controllers\Ui\V2;

use Formatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Device;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Route;
use Tobuli\Entities\RouteCheckpoint;
use Tobuli\Entities\RouteSchedule;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\RouteAdherenceService;

/**
 * TalentusPro · RouteSchedulesController (ui/v2)
 * -----------------------------------------------------------------
 * Programaciones de ruta, puntos de control y cumplimiento. Es la misma
 * funcionalidad que el legacy (RouteSchedulesController + RouteCheckpoints
 * + reporte 909) y el mismo motor de calculo (RouteAdherenceService), para
 * que las dos interfaces no puedan discrepar sobre una misma vuelta.
 *
 * Permisos: los de "routes". Una programacion es la agenda de una ruta.
 * -----------------------------------------------------------------
 */
class RouteSchedulesController extends BaseController
{
    private function gate(string $action): void
    {
        if (! $this->user->perm('routes', $action)) {
            abort(403);
        }
    }

    public function page()
    {
        $this->gate('view');

        return View::make('ui.v2.pages.route-schedules');
    }

    public function data(Request $request): JsonResponse
    {
        $this->gate('view');

        $q = RouteSchedule::where('user_id', $this->user->id)->with(['route:id,name', 'devices:id,name']);

        if ($s = trim((string) $request->get('s'))) {
            $q->where('name', 'like', "%$s%");
        }

        $paginator = $q->orderBy('name')->paginate(max(5, min(100, (int) $request->get('limit', 20))));

        $rows = [];

        foreach ($paginator->items() as $item) {
            $rows[] = $this->toRow($item);
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $paginator->currentPage(),
            'pages' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    private function toRow(RouteSchedule $item): array
    {
        return [
            'id'                => (int) $item->id,
            'name'              => (string) $item->name,
            'route_id'          => (int) $item->route_id,
            'route'             => $item->route->name ?? ('#' . $item->route_id),
            'weekdays'          => $item->weekdays_list,
            'start_time'        => substr((string) $item->start_time, 0, 5),
            'return_start_time' => $item->return_start_time ? substr((string) $item->return_start_time, 0, 5) : null,
            'tolerance_early'   => (int) $item->tolerance_early,
            'tolerance_late'    => (int) $item->tolerance_late,
            'active'            => (bool) $item->active,
            'devices'           => $item->devices->map(function ($d) {
                return ['id' => (int) $d->id, 'name' => (string) $d->name];
            })->all(),
        ];
    }

    /** Desplegables del formulario y etiquetas de estado. */
    public function meta(): JsonResponse
    {
        $this->gate('view');

        return response()->json([
            'ok'      => true,
            'routes'  => Route::userOwned($this->user)->orderBy('name')->get(['id', 'name'])
                ->map(function ($r) {
                    return ['id' => (int) $r->id, 'name' => (string) $r->name];
                })->all(),
            'devices' => $this->user->devices()->clearOrdersBy()->orderBy('devices.name')
                ->get(['devices.id', 'devices.name'])
                ->map(function ($d) {
                    return ['id' => (int) $d->id, 'name' => (string) $d->name];
                })->all(),
            'weekdays' => [
                ['id' => 1, 'name' => trans('front.monday')],
                ['id' => 2, 'name' => trans('front.tuesday')],
                ['id' => 3, 'name' => trans('front.wednesday')],
                ['id' => 4, 'name' => trans('front.thursday')],
                ['id' => 5, 'name' => trans('front.friday')],
                ['id' => 6, 'name' => trans('front.saturday')],
                ['id' => 7, 'name' => trans('front.sunday')],
            ],
            'round_states' => RouteAdherenceService::estadosVuelta(),
            'point_states' => RouteAdherenceService::estadosPunto(),
            'can_edit'     => (bool) $this->user->perm('routes', 'edit'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate('edit');

        try {
            $data = $this->input($request);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        $devices = $data['devices'];
        unset($data['devices']);

        $item = RouteSchedule::create($data + ['user_id' => (int) $this->user->id]);
        $item->devices()->sync($devices);

        return response()->json(['ok' => true, 'item' => $this->toRow($item->fresh(['route', 'devices']))]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->gate('edit');

        $item = $this->find($id);

        if (! $item) {
            return response()->json(['ok' => false, 'message' => trans('front.route_schedule')], 404);
        }

        try {
            $data = $this->input($request);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        $devices = $data['devices'];
        unset($data['devices']);

        $item->update($data);
        $item->devices()->sync($devices);

        return response()->json(['ok' => true, 'item' => $this->toRow($item->fresh(['route', 'devices']))]);
    }

    public function destroy($id): JsonResponse
    {
        $this->gate('remove');

        $item = $this->find($id);

        if ($item) {
            $item->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Puntos de control de una ruta, mas las geocercas que se pueden elegir. */
    public function checkpoints($routeId): JsonResponse
    {
        $this->gate('view');

        $route = Route::userOwned($this->user)->find((int) $routeId);

        if (! $route) {
            return response()->json(['ok' => false, 'message' => trans('front.route')], 404);
        }

        $route->load('checkpoints.geofence');

        $porSentido = [];

        foreach ([RouteCheckpoint::DIRECTION_OUTBOUND, RouteCheckpoint::DIRECTION_INBOUND] as $sentido) {
            $porSentido[$sentido] = $route->checkpointsFor($sentido)->map(function (RouteCheckpoint $cp) {
                return [
                    'geofence_id'     => (int) $cp->geofence_id,
                    'name'            => $cp->name,
                    'planned_offset'  => (int) $cp->planned_offset,
                    'tolerance_early' => $cp->tolerance_early,
                    'tolerance_late'  => $cp->tolerance_late,
                ];
            })->all();
        }

        return response()->json([
            'ok'         => true,
            'route'      => ['id' => (int) $route->id, 'name' => (string) $route->name],
            'geofences'  => Geofence::userAccessible($this->user)->orderBy('name')->get(['geofences.id', 'geofences.name'])
                ->map(function ($g) {
                    return ['id' => (int) $g->id, 'name' => (string) $g->name];
                })->all(),
            'checkpoints' => $porSentido,
        ]);
    }

    public function saveCheckpoints(Request $request, $routeId): JsonResponse
    {
        $this->gate('edit');

        $route = Route::userOwned($this->user)->find((int) $routeId);

        if (! $route) {
            return response()->json(['ok' => false, 'message' => trans('front.route')], 404);
        }

        // Las geocercas se validan contra las del usuario: el id llega del
        // navegador y podria apuntar a la geocerca de otro cliente.
        $permitidas = Geofence::userAccessible($this->user)->pluck('geofences.id')->all();

        $nuevos = [];

        foreach ([RouteCheckpoint::DIRECTION_OUTBOUND, RouteCheckpoint::DIRECTION_INBOUND] as $sentido) {
            $posicion = 0;

            foreach ((array) $request->input('checkpoints.' . $sentido, []) as $fila) {
                $geofenceId = (int) ($fila['geofence_id'] ?? 0);

                if (! $geofenceId || ! in_array($geofenceId, $permitidas)) {
                    continue;
                }

                $nombre = trim((string) ($fila['name'] ?? ''));

                $nuevos[] = [
                    'route_id'        => (int) $route->id,
                    'geofence_id'     => $geofenceId,
                    'direction'       => $sentido,
                    'position'        => ++$posicion,
                    'name'            => $nombre !== '' ? $nombre : null,
                    'planned_offset'  => max(0, (int) ($fila['planned_offset'] ?? 0)),
                    // Vacio = usa la tolerancia de la programacion, no cero
                    'tolerance_early' => $this->opcional($fila['tolerance_early'] ?? null),
                    'tolerance_late'  => $this->opcional($fila['tolerance_late'] ?? null),
                ];
            }
        }

        DB::transaction(function () use ($route, $nuevos) {
            RouteCheckpoint::where('route_id', $route->id)->delete();

            foreach ($nuevos as $punto) {
                RouteCheckpoint::create($punto);
            }
        });

        return response()->json(['ok' => true, 'count' => count($nuevos)]);
    }

    /**
     * Cumplimiento en un rango: las vueltas de cada vehiculo con su estado.
     *
     * Mismo motor que el reporte 909. Las fechas llegan en la hora del usuario
     * y se pasan a UTC con el mismo Formatter que usan los reportes.
     */
    public function adherence(Request $request): JsonResponse
    {
        $this->gate('view');

        $desde = trim((string) $request->get('from'));
        $hasta = trim((string) $request->get('to'));

        if (! $desde || ! $hasta) {
            return response()->json(['ok' => false, 'message' => 'from/to'], 422);
        }

        $desdeUtc = Formatter::time()->reverse($desde . ' 00:00:00');
        $hastaUtc = Formatter::time()->reverse($hasta . ' 23:59:59');

        $ids = array_filter(array_map('intval', (array) $request->get('schedules', [])));

        $servicio     = new RouteAdherenceService();
        $programadas  = $servicio->schedules($this->user, $ids);
        $soloFallos   = (bool) $request->get('only_breaches');
        $vehiculoPide = (int) $request->get('device_id');

        $filas = [];

        foreach ($programadas as $schedule) {
            foreach ($schedule->devices as $device) {
                if ($vehiculoPide && $device->id !== $vehiculoPide) {
                    continue;
                }

                foreach ($servicio->forDevice($device, $desdeUtc, $hastaUtc, collect([$schedule])) as $vuelta) {
                    if ($soloFallos && $vuelta['status'] === RouteAdherenceService::ROUND_COMPLETED) {
                        continue;
                    }

                    $filas[] = [
                        'device'    => (string) $device->name,
                        'device_id' => (int) $device->id,
                        'schedule'  => (string) $schedule->name,
                        'route'     => $schedule->route->name ?? '',
                        'direction' => $vuelta['direction'],
                        'date'      => Formatter::date()->human($vuelta['start_utc']),
                        'start'     => substr($vuelta['start_local'], 11, 5),
                        'status'    => $vuelta['status'],
                        'summary'   => $vuelta['summary'],
                        'points'    => array_map(function ($p) {
                            return [
                                'label'     => $p['label'],
                                'planned'   => substr($p['planned_local'], 11, 5),
                                'actual'    => $p['actual_local'] ? substr($p['actual_local'], 11, 5) : null,
                                'deviation' => $p['deviation'],
                                'status'    => $p['status'],
                            ];
                        }, $vuelta['checkpoints']),
                    ];
                }
            }
        }

        usort($filas, function ($a, $b) {
            return [$a['date'], $a['start'], $a['device']] <=> [$b['date'], $b['start'], $b['device']];
        });

        return response()->json(['ok' => true, 'items' => $filas]);
    }

    private function opcional($valor): ?int
    {
        return ($valor === null || $valor === '') ? null : max(0, (int) $valor);
    }

    private function find($id): ?RouteSchedule
    {
        return RouteSchedule::where('user_id', $this->user->id)->find((int) $id);
    }

    /** Valida el formulario y lo deja listo para la entidad. */
    private function input(Request $request): array
    {
        $data = $request->only([
            'name', 'route_id', 'weekdays', 'start_time', 'return_start_time',
            'tolerance_early', 'tolerance_late', 'active', 'devices',
        ]);

        $validator = \Validator::make($data, [
            'name'              => 'required|string|max:255',
            'route_id'          => 'required|integer',
            'weekdays'          => 'required|array|min:1',
            'weekdays.*'        => 'integer|between:1,7',
            'start_time'        => 'required|date_format:H:i',
            'return_start_time' => 'nullable|date_format:H:i|after:start_time',
            'tolerance_early'   => 'required|integer|between:0,1440',
            'tolerance_late'    => 'required|integer|between:0,1440',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages());
        }

        if (! Route::userOwned($this->user)->where('id', (int) $data['route_id'])->exists()) {
            throw new ValidationException(new \Illuminate\Support\MessageBag([
                'route_id' => [trans('front.route')],
            ]));
        }

        $devices = array_filter(array_map('intval', (array) ($data['devices'] ?? [])));

        return [
            'name'              => $data['name'],
            'route_id'          => (int) $data['route_id'],
            'weekdays'          => implode(',', array_map('intval', $data['weekdays'])),
            'start_time'        => $data['start_time'] . ':00',
            'return_start_time' => ! empty($data['return_start_time']) ? $data['return_start_time'] . ':00' : null,
            'tolerance_early'   => (int) $data['tolerance_early'],
            'tolerance_late'    => (int) $data['tolerance_late'],
            'active'            => ! empty($data['active']),
            // Solo vehiculos del usuario
            'devices'           => $devices
                ? $this->user->devices()->whereIn('devices.id', $devices)->pluck('devices.id')->all()
                : [],
        ];
    }
}
