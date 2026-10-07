<?php

namespace App\Http\Controllers\Ui\V2;

use Formatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\DeviceGroup;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationPoint;
use Tobuli\Entities\OperationRecalculation;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\Operations\OperationService;

/**
 * TalentusPro · OperationsController (ui/v2)
 * -----------------------------------------------------------------
 * Modulo de Operaciones: control de vueltas entre puntos de carga y
 * descarga. Tres pestañas sobre tablas ya calculadas (operation_*): En vivo,
 * Vueltas y Operaciones. Nada de aqui recorre posiciones; eso lo hacen
 * PositionsWriter (en vivo) y operations:process (tramos y recalculos).
 *
 * Permisos: modulo propio `operations`. Cada usuario ve solo sus operaciones
 * (patron de RouteSchedulesController).
 * -----------------------------------------------------------------
 */
class OperationsController extends BaseController
{
    private function gate(string $action): void
    {
        if (! $this->user->perm('operations', $action)) {
            abort(403);
        }
    }

    public function page()
    {
        $this->gate('view');

        return View::make('ui.v2.pages.operations');
    }

    // ------------------------------------------------------------------
    // Catalogo
    // ------------------------------------------------------------------

    public function meta(): JsonResponse
    {
        $this->gate('view');

        $counts = DB::table('user_device_pivot')
            ->where('user_id', $this->user->id)
            ->where('group_id', '>', 0)
            ->selectRaw('group_id, COUNT(*) as c')
            ->groupBy('group_id')
            ->pluck('c', 'group_id');

        $groups = DeviceGroup::where('user_id', $this->user->id)->orderBy('title')->get(['id', 'title'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->title, 'count' => (int) ($counts[$g->id] ?? 0)])
            ->all();

        $geofences = Geofence::userOwned($this->user)
            ->whereNull('device_id')
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name, 'category' => $g->category])
            ->all();

        return response()->json([
            'ok'         => true,
            'groups'     => $groups,
            'geofences'  => $geofences,
            'categories' => Geofence::categories(),
            'states'     => $this->stateLabels(),
            'statuses'   => [
                OperationCycle::STATUS_OPEN       => trans('ui.ops_status_open'),
                OperationCycle::STATUS_COMPLETED  => trans('ui.ops_status_completed'),
                OperationCycle::STATUS_INCOMPLETE => trans('ui.ops_status_incomplete'),
            ],
            // device_ids: para que "Exportar" mande al reporte solo los vehiculos
            // de la operacion (toda la flota desborda max_input_vars de PHP).
            'operations' => Operation::userOwned($this->user)->with('deviceStates:operation_id,device_id')->orderBy('name')->get(['id', 'name', 'active'])
                ->map(fn ($o) => [
                    'id'         => (int) $o->id,
                    'name'       => (string) $o->name,
                    'active'     => (bool) $o->active,
                    'device_ids' => $o->deviceStates->pluck('device_id')->map(fn ($d) => (int) $d)->values()->all(),
                ])->all(),
            'can_edit'   => (bool) $this->user->perm('operations', 'edit'),
            'can_remove' => (bool) $this->user->perm('operations', 'remove'),
            'devices'    => $this->user->devices()->clearOrdersBy()->orderBy('devices.name')
                ->get(['devices.id', 'devices.name'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => (string) $d->name])->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Operaciones (CRUD)
    // ------------------------------------------------------------------

    public function data(Request $request): JsonResponse
    {
        $this->gate('view');

        $q = Operation::userOwned($this->user)
            ->with(['deviceGroup:id,title', 'shifts', 'currentPoints'])
            ->withCount('deviceStates');

        if ($s = trim((string) $request->get('s'))) {
            $q->where('name', 'like', "%$s%");
        }

        $paginator = $q->orderBy('name')->paginate(max(5, min(100, (int) $request->get('limit', 20))));

        return response()->json([
            'ok'    => true,
            'items' => array_map(fn ($o) => $this->toRow($o), $paginator->items()),
            'page'  => $paginator->currentPage(),
            'pages' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate('edit');

        try {
            $operation = (new OperationService($this->user))->create($request->all());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true, 'item' => $this->toRow($operation->fresh(['deviceGroup', 'shifts', 'currentPoints']))]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->gate('edit');

        $operation = $this->find($id);

        if (! $operation) {
            abort(404);
        }

        try {
            (new OperationService($this->user))->edit($operation, $request->all());
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true, 'item' => $this->toRow($operation->fresh(['deviceGroup', 'shifts', 'currentPoints']))]);
    }

    public function toggle(Request $request, $id): JsonResponse
    {
        $this->gate('edit');

        $operation = $this->find($id);

        if (! $operation) {
            abort(404);
        }

        (new OperationService($this->user))->setActive($operation, (bool) $request->get('active'));

        return response()->json(['ok' => true, 'active' => (bool) $operation->active]);
    }

    public function destroy($id): JsonResponse
    {
        $this->gate('remove');

        $operation = $this->find($id);

        if (! $operation) {
            abort(404);
        }

        (new OperationService($this->user))->remove($operation);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    // En vivo
    // ------------------------------------------------------------------

    /**
     * Estado de cada vehiculo de la operacion y las vueltas del dia. Solo
     * lee lo ya guardado: no calcula nada. "Detenido" y "Ralenti" del tramo
     * en curso salen de traccar, que PositionsWriter mantiene.
     */
    public function live(Request $request): JsonResponse
    {
        $this->gate('view');

        $operation = $this->find($request->get('operation_id'));

        if (! $operation) {
            return response()->json(['ok' => false, 'message' => 'operation_id'], 422);
        }

        $date = trim((string) $request->get('date')) ?: Formatter::date()->convert(time(), 'Y-m-d');

        $states = OperationDeviceState::where('operation_id', $operation->id)
            ->with(['device.traccar', 'currentPoint:id,name,role', 'openLeg', 'openCycle'])
            ->get();

        $cycles = OperationCycle::where('operation_id', $operation->id)
            ->where('work_date', $date)
            ->with('legs')
            ->get()
            ->groupBy('device_id');

        $authorized = Geofence::userOwned($this->user)->category($operation->authorizedCategories())->get();
        $labels = $this->stateLabels();

        $rows = [];
        $totalCycles = 0;
        $totalDuration = 0;
        $completed = 0;
        $onCycle = 0;
        $unauthorized = 0;

        foreach ($states as $state) {
            $device = $state->device;

            if (! $device) {
                continue;
            }

            $own = $cycles->get($device->id, collect());
            $done = $own->where('status', OperationCycle::STATUS_COMPLETED);
            $legs = $own->flatMap->legs;

            $stopSeconds = (int) $legs->sum('stop_seconds');
            $idleSeconds = (int) $legs->sum('idle_seconds');
            $deviceUnauthorized = (int) $legs->sum('unauthorized_stops_count');

            $liveStop = null;
            $liveIdle = null;
            $outsideAuthorized = false;

            if ($state->isOnLeg() && $device->traccar) {
                $liveStop = (int) $device->getStopDuration();
                $liveIdle = (int) $device->getIdleDuration();

                if ($liveStop >= $operation->stop_threshold_seconds) {
                    $stopSeconds += $liveStop;
                    $idleSeconds += $liveIdle;

                    $outsideAuthorized = ! $authorized->contains(fn ($g) => $g->pointIn([
                        'latitude'  => $device->traccar->lastValidLatitude,
                        'longitude' => $device->traccar->lastValidLongitude,
                    ]));
                } else {
                    $liveStop = null;
                }
            }

            $last = $done->sortByDesc('ended_at')->first();

            $rows[] = [
                'device_id'    => (int) $device->id,
                'device'       => (string) $device->name,
                'state'        => $state->state,
                'state_label'  => $labels[$state->state] ?? $state->state,
                'point'        => $state->currentPoint ? $state->currentPoint->name : null,
                'cycles'       => $done->count(),
                'trips'        => $own->filter(fn ($c) => $c->legs->contains(fn ($l) => $l->isOutbound() && $l->status === OperationLeg::STATUS_COMPLETED))->count(),
                'last_cycle'   => $last ? Formatter::duration()->human($last->duration_seconds) : null,
                'stop'         => Formatter::duration()->human($stopSeconds),
                'idle'         => $state->openLeg && is_null($legs->first()->idle_seconds ?? null) && $legs->isNotEmpty() ? '—' : Formatter::duration()->human($idleSeconds),
                'unauthorized' => $deviceUnauthorized,
                'live_stop'    => $liveStop !== null ? Formatter::duration()->human($liveStop) : null,
                'stopped_outside' => $outsideAuthorized,
                'last_time'    => $device->traccar && $device->traccar->time ? Formatter::time()->human($device->traccar->time) : null,
            ];

            $totalCycles += $done->count();
            $completed += $done->count();
            $totalDuration += (int) $done->sum('duration_seconds');
            $unauthorized += $deviceUnauthorized;

            if ($state->isOnLeg() || $state->isInsidePoint()) {
                $onCycle++;
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['device'], $b['device']));

        return response()->json([
            'ok'   => true,
            'date' => $date,
            'kpis' => [
                'cycles'       => $totalCycles,
                'avg_cycle'    => $completed ? Formatter::duration()->human((int) round($totalDuration / $completed)) : '—',
                'on_cycle'     => $onCycle,
                'unauthorized' => $unauthorized,
            ],
            'rows'       => $rows,
            'updated_at' => Formatter::time()->human(date('Y-m-d H:i:s')),
        ]);
    }

    // ------------------------------------------------------------------
    // Vueltas
    // ------------------------------------------------------------------

    public function cycles(Request $request): JsonResponse
    {
        $this->gate('view');

        $from = trim((string) $request->get('from'));
        $to = trim((string) $request->get('to'));

        if (! $from || ! $to) {
            return response()->json(['ok' => false, 'message' => 'from/to'], 422);
        }

        $operationIds = Operation::userOwned($this->user)->withTrashed()->pluck('id');

        $q = OperationCycle::whereIn('operation_id', $operationIds)
            ->whereBetween('work_date', [$from, $to])
            ->with(['device:id,name', 'operation' => fn ($r) => $r->withTrashed(), 'legs.fromPoint:id,name', 'legs.toPoint:id,name']);

        if ($op = (int) $request->get('operation_id')) {
            $q->where('operation_id', $op);
        }

        if ($dev = (int) $request->get('device_id')) {
            $q->where('device_id', $dev);
        }

        if ($shift = $request->get('shift_id')) {
            $shift === 'none' ? $q->whereNull('shift_id') : $q->where('shift_id', (int) $shift);
        }

        if ($status = $request->get('status')) {
            $q->where('status', $status);
        }

        if ($request->get('only_unauthorized')) {
            $q->whereHas('legs', fn ($l) => $l->where('unauthorized_stops_count', '>', 0));
        }

        $paginator = $q->orderBy('started_at', 'desc')->paginate(max(5, min(100, (int) $request->get('limit', 25))));

        return response()->json([
            'ok'    => true,
            'items' => array_map(fn ($c) => $this->cycleRow($c), $paginator->items()),
            'page'  => $paginator->currentPage(),
            'pages' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function cycle($id): JsonResponse
    {
        $this->gate('view');

        $operationIds = Operation::userOwned($this->user)->withTrashed()->pluck('id');

        $cycle = OperationCycle::whereIn('operation_id', $operationIds)
            ->with(['device:id,name', 'legs.fromPoint:id,name', 'legs.toPoint:id,name', 'legs.stops'])
            ->find((int) $id);

        if (! $cycle) {
            abort(404);
        }

        $row = $this->cycleRow($cycle);

        $row['legs'] = $cycle->legs->map(function (OperationLeg $leg) {
            return [
                'id'           => (int) $leg->id,
                'direction'    => $leg->direction,
                'status'       => $leg->status,
                'from'         => $leg->fromPoint->name ?? null,
                'to'           => $leg->toPoint->name ?? null,
                'started_at'   => Formatter::time()->human($leg->started_at),
                'ended_at'     => $leg->ended_at ? Formatter::time()->human($leg->ended_at) : null,
                'started_local' => Formatter::time()->convert($leg->started_at, 'Y-m-d H:i'),
                'ended_local'   => $leg->ended_at ? Formatter::time()->convert($leg->ended_at, 'Y-m-d H:i') : null,
                'duration'     => Formatter::duration()->human((int) $leg->duration_seconds),
                'distance'     => $leg->distance_meters !== null ? Formatter::distance()->human($leg->distance_meters / 1000) : null,
                'calculated'   => ! is_null($leg->calculated_at),
                'gap'          => $leg->gap_seconds ? Formatter::duration()->human($leg->gap_seconds) : null,
                'stops'        => $leg->stops->map(function ($s) {
                    return [
                        'id'          => (int) $s->id,
                        'started_at'  => Formatter::time()->human($s->started_at),
                        'ended_at'    => Formatter::time()->human($s->ended_at),
                        'started_local' => Formatter::time()->convert($s->started_at, 'Y-m-d H:i'),
                        'ended_local'   => Formatter::time()->convert($s->ended_at, 'Y-m-d H:i'),
                        'duration'    => Formatter::duration()->human((int) $s->duration_seconds),
                        'engine_on'   => $s->engine_on_seconds !== null ? Formatter::duration()->human((int) $s->engine_on_seconds) : null,
                        'engine_off'  => $s->engine_off_seconds !== null ? Formatter::duration()->human((int) $s->engine_off_seconds) : null,
                        'latitude'    => $s->latitude,
                        'longitude'   => $s->longitude,
                        'geofence'    => $s->geofence_name,
                        'authorized'  => (bool) $s->authorized,
                        'doubtful'    => (bool) $s->doubtful,
                    ];
                })->all(),
            ];
        })->all();

        return response()->json(['ok' => true, 'item' => $row]);
    }

    // ------------------------------------------------------------------
    // Recalculo
    // ------------------------------------------------------------------

    /** Cuantas vueltas se reemplazarian. */
    public function recalculatePreview(Request $request): JsonResponse
    {
        $this->gate('edit');

        [$operation, $devices, $fromUtc] = $this->recalculateInput($request);

        if (! $operation) {
            return response()->json(['ok' => false, 'message' => 'operation_id / from'], 422);
        }

        $count = OperationCycle::where('operation_id', $operation->id)
            ->whereIn('device_id', $devices)
            ->where(fn ($q) => $q->where('started_at', '>=', $fromUtc)->orWhere('ended_at', '>=', $fromUtc)->orWhereNull('ended_at'))
            ->count();

        $minDays = (int) config('tobuli.min_database_clear_days', 30);
        $clear = settings('db_clear');
        $keptDays = ($clear['status'] ?? false) ? max($minDays, (int) ($clear['days'] ?? $minDays)) : null;

        return response()->json([
            'ok'        => true,
            'cycles'    => $count,
            'devices'   => count($devices),
            'kept_days' => $keptDays,
        ]);
    }

    public function recalculate(Request $request): JsonResponse
    {
        $this->gate('edit');

        [$operation, $devices, $fromUtc] = $this->recalculateInput($request);

        if (! $operation) {
            return response()->json(['ok' => false, 'message' => 'operation_id / from'], 422);
        }

        $n = (new OperationService($this->user))->requestRecalculation(
            $operation,
            $devices,
            $fromUtc,
            (bool) $request->get('use_current_points')
        );

        return response()->json(['ok' => true, 'queued' => $n]);
    }

    public function recalculations(Request $request): JsonResponse
    {
        $this->gate('view');

        $operationIds = Operation::userOwned($this->user)->withTrashed()->pluck('id');

        $items = OperationRecalculation::whereIn('operation_id', $operationIds)
            ->with('device:id,name')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id'         => (int) $r->id,
                'device'     => $r->device->name ?? ('#' . $r->device_id),
                'from'       => Formatter::time()->human($r->from_time),
                'status'     => $r->status,
                'error'      => $r->error,
                'created_at' => Formatter::time()->human($r->created_at),
            ])->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    private function recalculateInput(Request $request): array
    {
        $operation = $this->find($request->get('operation_id'));
        $from = trim((string) $request->get('from'));

        if (! $operation || ! $from) {
            return [null, [], null];
        }

        $devices = array_filter(array_map('intval', (array) $request->get('devices', [])));

        if (! $devices) {
            $devices = $operation->deviceIds()->all();
        }

        return [$operation, array_values($devices), Formatter::time()->reverse($from . ' 00:00:00')];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function find($id): ?Operation
    {
        return Operation::userOwned($this->user)->find((int) $id);
    }

    private function toRow(Operation $o): array
    {
        return [
            'id'                     => (int) $o->id,
            'name'                   => (string) $o->name,
            'device_group_id'        => $o->device_group_id ? (int) $o->device_group_id : null,
            'group'                  => $o->deviceGroup->title ?? null,
            'devices_count'          => (int) ($o->device_states_count ?? $o->deviceStates()->count()),
            'active'                 => (bool) $o->active,
            'min_dwell_seconds'      => (int) $o->min_dwell_seconds,
            'stop_threshold_seconds' => (int) $o->stop_threshold_seconds,
            'max_leg_hours'          => (int) $o->max_leg_hours,
            'authorized_categories'  => $o->authorizedCategories(),
            'points'                 => $o->currentPoints->map(fn (OperationPoint $p) => [
                'id'          => (int) $p->id,
                'geofence_id' => $p->geofence_id ? (int) $p->geofence_id : null,
                'role'        => $p->role,
                'name'        => (string) $p->name,
            ])->values()->all(),
            'shifts'                 => $o->shifts->map(fn ($s) => [
                'id'         => (int) $s->id,
                'name'       => (string) $s->name,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time'   => substr((string) $s->end_time, 0, 5),
            ])->values()->all(),
        ];
    }

    private function cycleRow(OperationCycle $c): array
    {
        $legs = $c->legs;
        $out = $legs->first(fn ($l) => $l->isOutbound());
        $in = $legs->first(fn ($l) => ! $l->isOutbound());

        $idle = $legs->sum('idle_seconds');
        $hasIdle = $legs->contains(fn ($l) => ! is_null($l->idle_seconds));

        return [
            'id'           => (int) $c->id,
            'operation'    => $c->operation->name ?? null,
            'device_id'    => (int) $c->device_id,
            'device'       => $c->device->name ?? ('#' . $c->device_id),
            'number'       => (int) $c->number,
            'work_date'    => (string) $c->work_date,
            'shift'        => $c->shift_name,
            'shift_date'   => $c->shift_date,
            'status'       => $c->status,
            'started_at'   => Formatter::time()->human($c->started_at),
            'ended_at'     => $c->ended_at ? Formatter::time()->human($c->ended_at) : null,
            'duration'     => $c->duration_seconds !== null ? Formatter::duration()->human((int) $c->duration_seconds) : null,
            'load'         => $c->load_seconds !== null ? Formatter::duration()->human((int) $c->load_seconds) : null,
            'unload'       => $c->unload_seconds !== null ? Formatter::duration()->human((int) $c->unload_seconds) : null,
            'outbound'     => $out && $out->duration_seconds !== null ? Formatter::duration()->human((int) $out->duration_seconds) : null,
            'inbound'      => $in && $in->duration_seconds !== null ? Formatter::duration()->human((int) $in->duration_seconds) : null,
            'stop'         => Formatter::duration()->human((int) $legs->sum('stop_seconds')),
            'idle'         => $hasIdle ? Formatter::duration()->human((int) $idle) : null,
            'engine_off'   => $hasIdle ? Formatter::duration()->human((int) $legs->sum('engine_off_seconds')) : null,
            'unauthorized' => (int) $legs->sum('unauthorized_stops_count'),
            'pending'      => $legs->contains(fn ($l) => ! $l->isOpen() && is_null($l->calculated_at)),
        ];
    }

    private function stateLabels(): array
    {
        return [
            OperationDeviceState::STATE_UNKNOWN   => trans('ui.ops_state_unknown'),
            OperationDeviceState::STATE_AT_LOAD   => trans('ui.ops_state_at_load'),
            OperationDeviceState::STATE_OUTBOUND  => trans('ui.ops_state_outbound'),
            OperationDeviceState::STATE_AT_UNLOAD => trans('ui.ops_state_at_unload'),
            OperationDeviceState::STATE_INBOUND   => trans('ui.ops_state_inbound'),
            OperationDeviceState::STATE_NO_DATA   => trans('ui.ops_state_no_data'),
        ];
    }
}
