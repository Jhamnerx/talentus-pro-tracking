<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\User;
use Tobuli\History\Actions\GroupFuelFilling;
use Tobuli\History\DeviceHistory;
use Tobuli\Reports\DeviceReport;
use Tobuli\Services\Operations\OperationAreaExitScan;

/**
 * Salidas del area de operacion (combustible).
 *
 * Por vehiculo: cada vez que sale de las geocercas de categoria «Area de
 * operacion» (la mina) y vuelve, con el % de tanque al salir y al volver,
 * lo recargado fuera (grifo), la caida (salida + recargado - regreso), los
 * km recorridos fuera y la parada mas larga fuera (normalmente el destino).
 * Una caida mayor que el umbral se marca «Revisar»: es la señal de un
 * posible robo de combustible en una bajada a la ciudad.
 *
 * La deteccion la hace OperationAreaExitScan en una pasada por las tramas.
 */
class OperationAreaExitsReport extends DeviceReport
{
    const TYPE_ID = 915;

    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'zones_instead'];

    /** @var \Illuminate\Support\Collection<Geofence> */
    protected $areas;

    protected int $flagged = 0;

    protected int $tripsTotal = 0;

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
        return trans('front.report_operation_area_exits');
    }

    public static function isUserEnabled(User $user): bool
    {
        return static::isEnabled() && $user->perm('operations', 'view');
    }

    public function getInputParameters(): array
    {
        return [
            \Field::number('min_outside_minutes', trans('front.operation_area_min_outside'), 10)
                ->setRequired()
                ->addValidation('numeric|min:1'),
            \Field::number('alert_drop_pct', trans('front.operation_area_alert_drop'), 15)
                ->setRequired()
                ->addValidation('numeric|min:1'),
        ];
    }

    public function alertDrop(): float
    {
        return (float) Arr::get($this->parameters, 'alert_drop_pct', 15);
    }

    public function areaNames(): string
    {
        return $this->areas ? $this->areas->pluck('name')->implode(', ') : '';
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $this->areas = Geofence::userOwned($this->user)
            ->category(Geofence::CATEGORY_OPERATION_AREA)
            ->get();
    }

    protected function precheckError($device)
    {
        if ($this->areas->isEmpty()) {
            return trans('front.operation_area_none');
        }

        return null;
    }

    protected function generateDevice($device)
    {
        if ($error = $this->precheckError($device)) {
            return ['meta' => $this->getDeviceMeta($device), 'error' => $error];
        }

        $sensor = $device->getFuelTankSensor();

        $ctx = (object) [
            'areaIds'           => $this->areas->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'minOutsideSeconds' => max(60, (int) round((float) Arr::get($this->parameters, 'min_outside_minutes', 10) * 60)),
            'minSpeed'          => (float) ($device->min_moving_speed ?: config('tobuli.device.min_moving_speed', 6)),
            'fuelSensor'        => $sensor,
            'fuelFull'          => null,
            'trips'             => [],
        ];

        if ($sensor) {
            // Se reporta en %: litros/galones se dividen entre la capacidad.
            $full = (float) $sensor->getMaxTankValue();
            $ctx->fuelFull = (trim((string) $sensor->unit_of_measurement) !== '%' && $full > 0) ? $full : null;
        }

        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_speed'        => $ctx->minSpeed,
            'min_fuel_fillings' => $device->min_fuel_fillings,
            'min_fuel_thefts'   => $device->min_fuel_thefts,
            'area_scan'         => $ctx,
        ]);
        $history->setGeofences($this->areas);
        $history->registerActions($sensor ? [OperationAreaExitScan::class, GroupFuelFilling::class] : [OperationAreaExitScan::class]);
        $history->setRange($this->date_from, $this->date_to);

        try {
            $data = $history->get();
        } catch (\PDOException $e) {
            return null;
        }

        if (! $ctx->trips) {
            return null;
        }

        // Recargas detectadas (pico de subida del nivel), para sumarlas a la vuelta.
        $fillings = [];
        foreach ($data['groups']->all() as $group) {
            if ($group->getKey() !== 'fuel_filling' || ! $group->getStartPosition()) {
                continue;
            }
            $diff = $group->hasStat('fuel_level_difference') ? $group->getStat('fuel_level_difference')->get() : null;
            if (is_numeric($diff)) {
                $fillings[] = [
                    'ts'   => strtotime($group->getStartPosition()->time),
                    'diff' => $ctx->fuelFull ? $diff / $ctx->fuelFull * 100 : (float) $diff,
                ];
            }
        }

        $rows = [];
        $loss = 0.0;

        foreach ($ctx->trips as $trip) {
            $endTs = $trip['return_ts'] ?? PHP_INT_MAX;
            $refilled = 0.0;
            foreach ($fillings as $f) {
                if ($f['ts'] >= $trip['exit_ts'] && $f['ts'] <= $endTs) {
                    $refilled += $f['diff'];
                }
            }

            $drop = (! is_null($trip['fuel_exit']) && ! is_null($trip['fuel_return']))
                ? round($trip['fuel_exit'] + $refilled - $trip['fuel_return'], 1)
                : null;

            $km = (float) $trip['distance_km'];
            $flag = ! is_null($drop) && $drop >= $this->alertDrop();

            if ($flag) {
                $this->flagged++;
                $loss += $drop;
            }

            $stop = $trip['longest_stop'];

            $rows[] = [
                'exit_at'      => Formatter::time()->human($trip['exit_at']),
                'return_at'    => $trip['return_at'] ? Formatter::time()->human($trip['return_at']) : null,
                'outside'      => $trip['return_ts'] ? Formatter::duration()->human($trip['return_ts'] - $trip['exit_ts']) : null,
                'distance'     => Formatter::distance()->human($km),
                'fuel_exit'    => $trip['fuel_exit'],
                'fuel_return'  => $trip['fuel_return'],
                'fuel_min'     => is_null($trip['min_fuel']) ? null : round($trip['min_fuel'], 1),
                'refilled'     => $refilled > 0 ? round($refilled, 1) : null,
                'drop'         => $drop,
                'drop_100km'   => (! is_null($drop) && $km >= 1) ? round($drop / $km * 100, 1) : null,
                'stop_at'      => $stop ? Formatter::time()->human($stop['at']) : null,
                'stop_for'     => $stop ? Formatter::duration()->human($stop['seconds']) : null,
                'stop_place'   => $stop ? $this->getLocation($stop['pos']) : null,
                'exit_place'   => $this->getLocation($trip['exit_pos']),
                'open'         => (bool) $trip['open'],
                'flag'         => $flag,
                'has_fuel'     => (bool) $sensor,
            ];
        }

        $this->tripsTotal += count($rows);
        $this->totals[trans('front.operation_area_trips')] = $this->tripsTotal;
        $this->totals[trans('front.operation_area_flagged')] = $this->flagged;

        return [
            'meta'    => $this->getDeviceMeta($device),
            'table'   => ['rows' => $rows],
            'summary' => [
                'trips'    => count($rows),
                'flagged'  => count(array_filter($rows, fn ($r) => $r['flag'])),
                'loss'     => round($loss, 1),
                'has_fuel' => (bool) $sensor,
            ],
        ];
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('front.object'),
            trans('front.operation_area_exit'),
            trans('front.operation_area_return'),
            trans('front.operation_area_outside'),
            trans('front.distance'),
            trans('front.operation_area_fuel_exit'),
            trans('front.operation_area_fuel_return'),
            trans('front.operation_area_refilled'),
            trans('front.operation_area_drop'),
            trans('front.operation_area_drop_100km'),
            trans('front.operation_area_longest_stop'),
            trans('front.operation_area_check'),
        ]);

        foreach ($this->getItems() as $item) {
            $device = $item['meta']['device.name']['value'] ?? ($item['meta']['device.name'] ?? '');
            $device = is_array($device) ? '' : $device;

            foreach ($item['table']['rows'] ?? [] as $r) {
                fputcsv($file, [
                    $device, $r['exit_at'], $r['return_at'] ?? '', $r['outside'] ?? '', $r['distance'],
                    $r['fuel_exit'], $r['fuel_return'], $r['refilled'], $r['drop'], $r['drop_100km'],
                    trim(($r['stop_at'] ?? '') . ' ' . ($r['stop_for'] ? '(' . $r['stop_for'] . ')' : '')),
                    $r['flag'] ? trans('front.operation_area_check') : '',
                ]);
            }
        }
    }
}
