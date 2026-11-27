<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationStop;
use Tobuli\Entities\User;
use Tobuli\Reports\DeviceReport;

/**
 * Paradas en operacion (modulo de Operaciones, fase 2).
 *
 * Una fila por parada detectada dentro de un tramo: vuelta, tramo, inicio,
 * duracion, ubicacion, motor, autorizada o no, motivo y quien la justifico.
 * Lee operation_stops ya calculadas; no recorre posiciones.
 */
class OperationStopsReport extends DeviceReport
{
    const TYPE_ID = 911;

    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'zones_instead'];

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
        return trans('front.report_operation_stops');
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
        $stops = OperationStop::where('device_id', $device->id)
            ->where('started_at', '>=', $this->date_from)
            ->where('started_at', '<=', $this->date_to)
            ->whereHas('leg.cycle', fn ($q) => $q->whereIn('operation_id', $this->operationIds))
            ->with(['leg.cycle.operation' => fn ($q) => $q->withTrashed(), 'reason', 'justifier:id,email'])
            ->orderBy('started_at')
            ->get();

        if (! empty($this->parameters['only_unauthorized'])) {
            $stops = $stops->filter(fn ($s) => ! $s->authorized);
        }

        if ($stops->isEmpty()) {
            return null;
        }

        $rows = [];
        $unauthorized = 0;

        foreach ($stops as $s) {
            $leg = $s->leg;
            $cycle = $leg ? $leg->cycle : null;

            if (! $s->authorized && ! $s->doubtful) {
                $unauthorized++;
            }

            $rows[] = [
                'operation'    => $cycle->operation->name ?? '',
                'cycle'        => $cycle ? '#' . $cycle->number . ' · ' . $cycle->work_date : '',
                'leg'          => $leg ? trans($leg->isOutbound() ? 'front.operation_outbound' : 'front.operation_inbound') : '',
                'started_at'   => Formatter::time()->human($s->started_at),
                'duration'     => Formatter::duration()->human((int) $s->duration_seconds),
                'location'     => $s->address ?: (($s->latitude !== null) ? round($s->latitude, 5) . ', ' . round($s->longitude, 5) : ''),
                'latitude'     => $s->latitude,
                'longitude'    => $s->longitude,
                'engine'       => $s->engine_on_seconds === null ? '—'
                    : trans('front.operation_engine_on_short') . ' ' . Formatter::duration()->human((int) $s->engine_on_seconds)
                    . ' / ' . trans('front.operation_engine_off_short') . ' ' . Formatter::duration()->human((int) $s->engine_off_seconds),
                'geofence'     => $s->geofence_name,
                'status'       => $s->doubtful ? trans('front.operation_doubtful')
                    : ($s->authorized ? trans('front.operation_authorized') : trans('front.operation_unauthorized')),
                'authorized'   => (bool) $s->authorized,
                'doubtful'     => (bool) $s->doubtful,
                'reason'       => $s->reasonLabel() ?: '',
                'note'         => $s->note ?: '',
                'justified_by' => $s->isJustified() ? ($s->justifier->email ?? '') : '',
                'justified_at' => $s->justified_at ? Formatter::time()->human($s->justified_at) : '',
            ];
        }

        $this->totals[trans('front.operation_unauthorized_stops')] = ($this->totals[trans('front.operation_unauthorized_stops')] ?? 0) + $unauthorized;

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => ['rows' => $rows],
            'summary' => ['stops' => count($rows), 'unauthorized' => $unauthorized],
        ];
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('front.object'),
            trans('front.operations'),
            trans('front.operation_cycle'),
            trans('front.operation_outbound') . '/' . trans('front.operation_inbound'),
            trans('front.operation_stop_start'),
            trans('front.operation_stop_duration'),
            trans('front.operation_stop_location'),
            trans('front.operation_stop_engine'),
            trans('front.operation_stop_geofence'),
            trans('front.status'),
            trans('front.operation_stop_reason'),
            trans('front.operation_stop_justified_by'),
        ]);

        foreach ($this->getItems() as $item) {
            $device = $item['meta']['device.name'] ?? '';

            foreach ($item['table']['rows'] ?? [] as $r) {
                fputcsv($file, [
                    $device, $r['operation'], $r['cycle'], $r['leg'], $r['started_at'], $r['duration'],
                    $r['location'], $r['engine'], $r['geofence'], $r['status'],
                    trim($r['reason'] . ($r['note'] ? ' — ' . $r['note'] : '')),
                    trim($r['justified_by'] . ' ' . $r['justified_at']),
                ]);
            }
        }
    }
}
