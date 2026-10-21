<?php

namespace App\Http\Controllers\Ui\V2;

use Formatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\DeviceGroup;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationPoint;
use Tobuli\Entities\OperationRecalculation;
use Tobuli\Entities\OperationShiftBreak;
use Tobuli\Entities\OperationStop;
use Tobuli\Entities\OperationStopReason;
use Tobuli\Entities\VehicleType;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\Operations\OperationService;
use Tobuli\Services\Operations\OperationShiftReport;
use Tobuli\Services\Operations\OperationShiftSummaryService;

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
            'kinds'      => Operation::kinds(),
            'deductions' => Operation::deductionLabels(),
            // Reporte de turno (fase A): tipos de descanso, marca de estado de
            // los motivos y catalogo de tipos/estados de vehiculo (Seguridad)
            // del usuario para el automatismo cargado/vacio.
            'break_kinds'   => OperationShiftBreak::kindLabels(),
            'reason_states' => OperationStopReason::stateLabels(),
            'vehicle_types' => VehicleType::where('user_id', $this->user->id)->with('states')->orderBy('name')->get()
                ->map(fn ($t) => [
                    'id'     => (int) $t->id,
                    'name'   => (string) $t->name,
                    'states' => $t->states->sortBy('name')->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->values()->all(),
                ])->values()->all(),
            // Etiquetas por tipo de operacion: roles de los puntos, categorias
            // que les corresponden y estados en vivo.
            'role_labels'     => [Operation::KIND_HAUL => Operation::roleLabels(Operation::KIND_HAUL), Operation::KIND_ROUTE => Operation::roleLabels(Operation::KIND_ROUTE)],
            'role_categories' => [Operation::KIND_HAUL => Operation::roleCategories(Operation::KIND_HAUL), Operation::KIND_ROUTE => Operation::roleCategories(Operation::KIND_ROUTE)],
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
            ->with(['deviceGroup:id,title', 'shifts.breaks', 'currentPoints'])
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

        return response()->json(['ok' => true, 'item' => $this->toRow($operation->fresh(['deviceGroup', 'shifts.breaks', 'currentPoints']))]);
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

        return response()->json(['ok' => true, 'item' => $this->toRow($operation->fresh(['deviceGroup', 'shifts.breaks', 'currentPoints']))]);
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

        $date = trim((string) $request->get('date')) ?: Formatter::time()->convert(date('Y-m-d H:i:s'), 'Y-m-d');

        $states = OperationDeviceState::where('operation_id', $operation->id)
            ->with(['device.traccar', 'currentPoint:id,name,role', 'openLeg', 'openCycle'])
            ->get();

        $cycles = OperationCycle::where('operation_id', $operation->id)
            ->where('work_date', $date)
            ->with('legs')
            ->get()
            ->groupBy('device_id');

        $authorized = Geofence::userOwned($this->user)->category($operation->authorizedCategories())->get();
        $labels = Operation::stateLabels($operation->kind);

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
            ->with(['device:id,name', 'legs.fromPoint:id,name', 'legs.toPoint:id,name', 'legs.stops.reason', 'legs.stops.justifier:id,email'])
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
                // Fin redondeado al minuto siguiente: Historial trabaja en HH:MM y
                // 15:46:54 quedaria fuera de un rango que termina en 15:46.
                'ended_local'   => $leg->ended_at ? Formatter::time()->convert($leg->ended_at->copy()->addSeconds(59), 'Y-m-d H:i') : null,
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
                        'ended_local'   => Formatter::time()->convert($s->ended_at->copy()->addSeconds(59), 'Y-m-d H:i'),
                        'duration'    => Formatter::duration()->human((int) $s->duration_seconds),
                        'engine_on'   => $s->engine_on_seconds !== null ? Formatter::duration()->human((int) $s->engine_on_seconds) : null,
                        'engine_off'  => $s->engine_off_seconds !== null ? Formatter::duration()->human((int) $s->engine_off_seconds) : null,
                        'latitude'    => $s->latitude,
                        'longitude'   => $s->longitude,
                        'geofence'    => $s->geofence_name,
                        'authorized'  => (bool) $s->authorized,
                        'doubtful'    => (bool) $s->doubtful,
                        // Fase 2: justificacion
                        'justified'    => $s->isJustified(),
                        'reason_id'    => $s->reason_id ? (int) $s->reason_id : null,
                        'reason'       => $s->reasonLabel(),
                        'note'         => $s->note,
                        'justified_by' => $s->justifier->email ?? null,
                        'justified_at' => $s->justified_at ? Formatter::time()->human($s->justified_at) : null,
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
    // Fase 2: motivos de parada y justificacion
    // ------------------------------------------------------------------

    public function reasons(): JsonResponse
    {
        $this->gate('view');

        OperationStopReason::seedFor($this->user);

        $items = OperationStopReason::userOwned($this->user)
            ->orderBy('position')->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id'         => (int) $r->id,
                'name'       => (string) $r->name,
                'authorizes' => (bool) $r->authorizes,
                'state'      => $r->state ?: null,
            ])
            ->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * Guarda el catalogo entero: [{id?, name, authorizes, state?}]. Los que
     * faltan se borran. `state` (null | maint | standby) es el estado del
     * reporte de turno que fuerza una parada justificada con el motivo.
     */
    public function saveReasons(Request $request): JsonResponse
    {
        $this->gate('edit');

        $rows = (array) $request->get('items', []);
        $keep = [];

        $validator = Validator::make(['items' => $rows], [
            'items.*.state' => 'nullable|in:' . implode(',', OperationStopReason::STATES),
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->messages()->toArray()], 422);
        }

        DB::transaction(function () use ($rows, &$keep) {
            foreach (array_values($rows) as $position => $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $state = $row['state'] ?? null;

                $attributes = [
                    'name'       => mb_substr($name, 0, 100),
                    'authorizes' => (bool) ($row['authorizes'] ?? false),
                    'state'      => in_array($state, OperationStopReason::STATES, true) ? $state : null,
                    'position'   => $position,
                ];

                $reason = ! empty($row['id'])
                    ? OperationStopReason::userOwned($this->user)->find((int) $row['id'])
                    : null;

                $reason = $reason
                    ? tap($reason)->update($attributes)
                    : OperationStopReason::create($attributes + ['user_id' => $this->user->id]);

                $keep[] = $reason->id;
            }

            OperationStopReason::userOwned($this->user)->whereNotIn('id', $keep ?: [0])->delete();
        });

        return $this->reasons();
    }

    /**
     * Justifica una parada. reason_id nulo = "Otro" (nota obligatoria). El
     * motivo SOLO explica; autorizar la parada es una casilla aparte
     * (`authorized`) que marca quien justifica (decision del cliente,
     * 19-oct-2026). Recalcula el contador del tramo y guarda quien y cuando.
     */
    public function justifyStop(Request $request, $id): JsonResponse
    {
        $this->gate('edit');

        $operationIds = Operation::userOwned($this->user)->withTrashed()->pluck('id');

        $stop = OperationStop::whereHas('leg.cycle', fn ($q) => $q->whereIn('operation_id', $operationIds))
            ->with('leg')
            ->find((int) $id);

        if (! $stop) {
            abort(404);
        }

        $reasonId = $request->get('reason_id');
        $note = trim((string) $request->get('note', ''));
        $reason = null;

        if ($reasonId) {
            $reason = OperationStopReason::userOwned($this->user)->find((int) $reasonId);

            if (! $reason) {
                return response()->json(['ok' => false, 'errors' => ['reason_id' => trans('validation.exists', ['attribute' => trans('ui.ops_reason')])]], 422);
            }
        } elseif ($note === '') {
            return response()->json(['ok' => false, 'errors' => ['note' => trans('ui.ops_note_required_other')]], 422);
        }

        $stop->reason_id = $reason ? $reason->id : null;
        $stop->note = $note !== '' ? mb_substr($note, 0, 2000) : null;
        $stop->justified_by = $this->user->id;
        $stop->justified_at = date('Y-m-d H:i:s');

        // La casilla "Autorizar esta parada" decide; el motivo solo explica.
        $stop->authorized = filter_var($request->get('authorized', false), FILTER_VALIDATE_BOOLEAN);
        $stop->save();

        if ($stop->leg) {
            $stop->leg->unauthorized_stops_count = $stop->leg->stops()
                ->where('authorized', 0)->where('doubtful', 0)->count();
            $stop->leg->save();
        }

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    // Reporte de turno (pestaña «Turno»)
    // ------------------------------------------------------------------

    /**
     * GET operations/shift-report?operation=ID&date=Y-m-d&shift=all|<id>
     *
     * `date` es el dia operativo (Operation::dayStart() a +24 h). Todo sale de
     * las tablas agregadas (OperationShiftReport); sin resumenes para ese dia
     * responde {ok, empty:true, hint} y la pantalla ofrece «Calcular ahora».
     */
    public function shiftReport(Request $request): JsonResponse
    {
        $this->gate('view');

        $operation = $this->find($request->get('operation'));

        if (! $operation) {
            abort(404);
        }

        $date = $this->operativeDate($request);

        if (! $date) {
            return response()->json(['ok' => false, 'message' => trans('ui.ops_shift_bad_date')], 422);
        }

        $shift = trim((string) $request->get('shift', 'all')) ?: 'all';

        if ($shift !== 'all' && ! ctype_digit($shift)) {
            $shift = 'all';
        }

        $report = new OperationShiftReport(app(\Tobuli\Helpers\Formatter\Formatter::class));

        return response()->json($report->build($operation, $date, $shift));
    }

    /**
     * POST operations/shift-report/build {operation, date}: calcula el dia
     * operativo (turnos de cada vehiculo + filas de flota). No admite fechas
     * futuras. Es sincrono: para un dia de pocos vehiculos tarda segundos.
     */
    public function shiftReportBuild(Request $request): JsonResponse
    {
        $this->gate('edit');

        $operation = $this->find($request->get('operation'));

        if (! $operation) {
            abort(404);
        }

        $date = $this->operativeDate($request);

        if (! $date) {
            return response()->json(['ok' => false, 'message' => trans('ui.ops_shift_bad_date')], 422);
        }

        if ($date > Formatter::time()->convert(date('Y-m-d H:i:s'), 'Y-m-d')) {
            return response()->json(['ok' => false, 'message' => trans('ui.ops_shift_future')], 422);
        }

        set_time_limit(600);

        (new OperationShiftSummaryService())->buildDay($operation, $date);

        return response()->json(['ok' => true]);
    }

    /** Fecha Y-m-d valida del request (hoy en hora del usuario si no viene). */
    private function operativeDate(Request $request): ?string
    {
        $date = trim((string) $request->get('date'));

        if ($date === '') {
            return Formatter::time()->convert(date('Y-m-d H:i:s'), 'Y-m-d');
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
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
            'kind'                   => (string) ($o->kind ?: Operation::KIND_HAUL),
            'device_group_id'        => $o->device_group_id ? (int) $o->device_group_id : null,
            'group'                  => $o->deviceGroup->title ?? null,
            'devices_count'          => (int) ($o->device_states_count ?? $o->deviceStates()->count()),
            'active'                 => (bool) $o->active,
            'min_dwell_seconds'      => (int) $o->min_dwell_seconds,
            'stop_threshold_seconds' => (int) $o->stop_threshold_seconds,
            'max_leg_hours'          => (int) $o->max_leg_hours,
            'day_start'              => $o->day_start ? substr((string) $o->day_start, 0, 5) : null,
            'vehicle_type_id'        => $o->vehicle_type_id ? (int) $o->vehicle_type_id : null,
            'loaded_state_id'        => $o->loaded_state_id ? (int) $o->loaded_state_id : null,
            'empty_state_id'         => $o->empty_state_id ? (int) $o->empty_state_id : null,
            'authorized_categories'  => $o->authorizedCategories(),
            'worked_hours_deductions' => $o->workedHoursDeductions(),
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
                'breaks'     => $s->breaks->map(fn ($b) => [
                    'id'         => (int) $b->id,
                    'name'       => (string) $b->name,
                    'kind'       => (string) $b->kind,
                    'start_time' => $b->start_time ? substr((string) $b->start_time, 0, 5) : null,
                    'end_time'   => $b->end_time ? substr((string) $b->end_time, 0, 5) : null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function cycleRow(OperationCycle $c): array
    {
        $legs = $c->legs;
        // El tramo que cuenta es el completado; si el vehiculo salio y volvio a
        // entrar al mismo punto hay tramos incompletos de por medio.
        $out = $legs->first(fn ($l) => $l->isOutbound() && $l->status === OperationLeg::STATUS_COMPLETED) ?? $legs->first(fn ($l) => $l->isOutbound());
        $in = $legs->first(fn ($l) => ! $l->isOutbound() && $l->status === OperationLeg::STATUS_COMPLETED) ?? $legs->last(fn ($l) => ! $l->isOutbound());

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
        return Operation::stateLabels(Operation::KIND_HAUL);
    }
}
