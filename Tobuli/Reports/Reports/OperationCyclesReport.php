<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\User;
use Tobuli\Reports\DeviceReport;

/**
 * Control de vueltas (modulo de Operaciones).
 *
 * Lee las tablas ya calculadas (operation_cycles / operation_legs); no
 * recorre posiciones. Por vehiculo: una fila por vuelta con horas de salida y
 * llegada en cada punto, tiempos en carga y descarga, ida, vuelta, ciclo,
 * detenido, ralenti, motor apagado y paradas no autorizadas; y totales por
 * dia calendario o por turno.
 *
 * Se muestra a quien tiene el permiso `operations`, tenga o no lista propia
 * de reportes: ver isUserEnabled().
 */
class OperationCyclesReport extends DeviceReport
{
    const TYPE_ID = 910;

    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'zones_instead'];

    /** @var \Illuminate\Support\Collection<int> ids de operaciones a incluir */
    protected $operationIds;

    public function __construct()
    {
        parent::__construct();

        $this->formats[] = 'csv';
    }

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.report_operation_cycles');
    }

    public static function isUserEnabled(User $user): bool
    {
        return static::isEnabled() && $user->perm('operations', 'view');
    }

    public function getInputParameters(): array
    {
        $operations = Operation::userOwned($this->user)->withTrashed()->orderBy('name')->pluck('name', 'id')->all();

        return [
            \Field::multiSelect('operation_id', trans('front.operations'))->setOptions($operations),
            \Field::select('totals_by', trans('front.operation_totals_by'), 'day')->setOptions([
                'day'   => trans('front.operation_totals_by_day'),
                'shift' => trans('front.operation_totals_by_shift'),
            ]),
            \Field::checkbox('only_unauthorized', trans('front.operation_only_unauthorized'), false),
        ];
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $ids = array_filter(array_map('intval', (array) Arr::get($this->parameters, 'operation_id', [])));

        $query = Operation::userOwned($this->user)->withTrashed();

        if ($ids) {
            $query->whereIn('id', $ids);
        }

        $this->operationIds = $query->pluck('id');
    }

    protected function generateDevice($device)
    {
        $cycles = OperationCycle::where('device_id', $device->id)
            ->whereIn('operation_id', $this->operationIds)
            ->where('started_at', '>=', $this->date_from)
            ->where('started_at', '<=', $this->date_to)
            ->with(['legs.fromPoint:id,name', 'legs.toPoint:id,name', 'operation' => fn ($q) => $q->withTrashed()])
            ->orderBy('started_at')
            ->get();

        if (! empty($this->parameters['only_unauthorized'])) {
            $cycles = $cycles->filter(fn ($c) => $c->legs->sum('unauthorized_stops_count') > 0);
        }

        if ($cycles->isEmpty()) {
            return null;
        }

        $byShift = Arr::get($this->parameters, 'totals_by') === 'shift';

        $rows = [];
        $groups = [];

        foreach ($cycles as $cycle) {
            $row = $this->row($cycle);
            $rows[] = $row;

            $key = $byShift
                ? ($cycle->shift_name ? $cycle->shift_name . ' · ' . $cycle->shift_date : trans('front.operation_off_shift') . ' · ' . $cycle->work_date)
                : (string) $cycle->work_date;

            if (! isset($groups[$key])) {
                $groups[$key] = ['title' => $key, 'cycles' => 0, 'trips' => 0, 'duration' => 0, 'unauthorized' => 0];
            }

            if ($cycle->status === OperationCycle::STATUS_COMPLETED) {
                $groups[$key]['cycles']++;
                $groups[$key]['duration'] += (int) $cycle->duration_seconds;
            }

            if ($cycle->legs->contains(fn ($l) => $l->isOutbound() && $l->status === OperationLeg::STATUS_COMPLETED)) {
                $groups[$key]['trips']++;
            }

            $groups[$key]['unauthorized'] += (int) $cycle->legs->sum('unauthorized_stops_count');
        }

        foreach ($groups as &$g) {
            $g['avg_cycle'] = $g['cycles'] ? Formatter::duration()->human((int) round($g['duration'] / $g['cycles'])) : '—';
            $g['duration'] = Formatter::duration()->human($g['duration']);

            $this->totals[trans('front.operation_cycles')] = ($this->totals[trans('front.operation_cycles')] ?? 0) + $g['cycles'];
            $this->totals[trans('front.operation_trips')] = ($this->totals[trans('front.operation_trips')] ?? 0) + $g['trips'];
        }
        unset($g);

        return [
            'meta'   => $this->getDeviceMeta($device),
            'groups' => array_values($groups),
            'table'  => ['rows' => $rows],
        ];
    }

    private function row(OperationCycle $c): array
    {
        $out = $c->legs->first(fn ($l) => $l->isOutbound());
        $in = $c->legs->first(fn ($l) => ! $l->isOutbound());
        $hasEngine = $c->legs->contains(fn ($l) => ! is_null($l->idle_seconds));

        $statuses = [
            OperationCycle::STATUS_OPEN       => trans('front.operation_status_open'),
            OperationCycle::STATUS_COMPLETED  => trans('front.operation_status_completed'),
            OperationCycle::STATUS_INCOMPLETE => trans('front.operation_status_incomplete'),
        ];

        return [
            'number'        => (int) $c->number,
            'work_date'     => (string) $c->work_date,
            'shift'         => $c->shift_name ?: trans('front.operation_off_shift'),
            'operation'     => $c->operation->name ?? '',
            'status'        => $statuses[$c->status] ?? $c->status,
            'load_point'    => $out->fromPoint->name ?? ($in->toPoint->name ?? ''),
            'unload_point'  => $out->toPoint->name ?? ($in->fromPoint->name ?? ''),
            'load_exit'     => Formatter::time()->human($c->started_at),
            'unload_enter'  => $out && $out->ended_at ? Formatter::time()->human($out->ended_at) : '',
            'unload_exit'   => $in ? Formatter::time()->human($in->started_at) : '',
            'load_enter'    => $c->ended_at ? Formatter::time()->human($c->ended_at) : '',
            'load_time'     => $c->load_seconds !== null ? Formatter::duration()->human((int) $c->load_seconds) : '',
            'outbound'      => $out && $out->duration_seconds !== null ? Formatter::duration()->human((int) $out->duration_seconds) : '',
            'unload_time'   => $c->unload_seconds !== null ? Formatter::duration()->human((int) $c->unload_seconds) : '',
            'inbound'       => $in && $in->duration_seconds !== null ? Formatter::duration()->human((int) $in->duration_seconds) : '',
            'cycle'         => $c->duration_seconds !== null ? Formatter::duration()->human((int) $c->duration_seconds) : '',
            'stop'          => Formatter::duration()->human((int) $c->legs->sum('stop_seconds')),
            'idle'          => $hasEngine ? Formatter::duration()->human((int) $c->legs->sum('idle_seconds')) : '—',
            'engine_off'    => $hasEngine ? Formatter::duration()->human((int) $c->legs->sum('engine_off_seconds')) : '—',
            'unauthorized'  => (int) $c->legs->sum('unauthorized_stops_count'),
        ];
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('front.object'),
            trans('global.date'),
            trans('front.operation_shift'),
            trans('front.operations'),
            '#',
            trans('front.status'),
            trans('front.operation_load_point'),
            trans('front.operation_unload_point'),
            trans('front.operation_load_exit'),
            trans('front.operation_unload_enter'),
            trans('front.operation_unload_exit'),
            trans('front.operation_load_enter'),
            trans('front.operation_load_time'),
            trans('front.operation_outbound'),
            trans('front.operation_unload_time'),
            trans('front.operation_inbound'),
            trans('front.operation_cycle'),
            trans('front.stopped'),
            trans('front.idle'),
            trans('front.operation_engine_off'),
            trans('front.operation_unauthorized_stops'),
        ]);

        foreach ($this->getItems() as $item) {
            $device = $item['meta']['device.name'] ?? '';

            foreach ($item['table']['rows'] ?? [] as $r) {
                fputcsv($file, [
                    $device, $r['work_date'], $r['shift'], $r['operation'], $r['number'], $r['status'],
                    $r['load_point'], $r['unload_point'], $r['load_exit'], $r['unload_enter'], $r['unload_exit'], $r['load_enter'],
                    $r['load_time'], $r['outbound'], $r['unload_time'], $r['inbound'], $r['cycle'],
                    $r['stop'], $r['idle'], $r['engine_off'], $r['unauthorized'],
                ]);
            }
        }
    }
}
