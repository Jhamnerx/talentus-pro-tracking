<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationStop;
use Tobuli\Entities\User;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\Fuel;
use Tobuli\History\Actions\GroupGeofenceIn;
use Tobuli\History\DeviceHistory;
use Tobuli\Reports\DeviceReport;

/**
 * Horas trabajadas netas (modulo de Operaciones, fase 3).
 *
 * Por vehiculo y dia: horas de motor menos los descuentos que el usuario
 * activo en la operacion (abastecimiento, ralenti, paradas no autorizadas,
 * pala inactiva), y el consumo por hora (galones por hora de motor) en los
 * equipos con sensor de combustible.
 *
 * Reglas del plan (§8):
 * - Con sensor de horas de motor se usa ese dato; sin el, tiempo con
 *   ignicion encendida. Lo decide EngineHours::boot() por si solo.
 * - Con sensor de combustible (consumo o nivel) se usa la estadistica Fuel;
 *   sin el, las columnas de combustible salen vacias.
 * - Los galones salen de Formatter::capacity() con la unidad del usuario.
 */
class OperationWorkHoursReport extends DeviceReport
{
    const TYPE_ID = 912;

    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'zones_instead'];

    /** @var Operation|null */
    protected $operation;

    /** @var \Illuminate\Support\Collection<Geofence> */
    protected $fuelGeofences;

    /** @var int suma de horas netas de todos los vehiculos, para el total global */
    protected $netTotalSeconds = 0;

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
        return trans('front.report_operation_work_hours');
    }

    public static function isUserEnabled(User $user): bool
    {
        return static::isEnabled() && $user->perm('operations', 'view');
    }

    public function getInputParameters(): array
    {
        $operations = Operation::userOwned($this->user)->withTrashed()->orderBy('name')->pluck('name', 'id')->all();

        return [
            // Una sola operacion: de ella salen los descuentos activos.
            \Field::select('operation_id', trans('front.operations'))->setOptions($operations),
        ];
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $id = (int) Arr::get($this->parameters, 'operation_id');

        $this->operation = Operation::userOwned($this->user)->withTrashed()->find($id)
            ?: Operation::userOwned($this->user)->withTrashed()->orderBy('name')->first();

        $this->fuelGeofences = Geofence::userOwned($this->user)
            ->category(Geofence::CATEGORY_FUEL)
            ->get();
    }

    /** Descuentos activos en la operacion, en el orden del plan. */
    public function deductions(): array
    {
        return $this->operation ? $this->operation->workedHoursDeductions() : [];
    }

    public function hasDeduction(string $key): bool
    {
        return in_array($key, $this->deductions(), true);
    }

    protected function generateDevice($device)
    {
        $rows = [];
        $hasFuel = false;

        foreach ($this->localDays() as [$dayLabel, $from, $to]) {
            $history = new DeviceHistory($device);
            $history->setConfig([
                'stop_speed'        => $device->min_moving_speed,
                'min_fuel_fillings' => $device->min_fuel_fillings,
                'min_fuel_thefts'   => $device->min_fuel_thefts,
            ]);
            $history->setGeofences($this->fuelGeofences);
            $history->registerActions([Duration::class, EngineHours::class, Fuel::class, GroupGeofenceIn::class]);
            $history->setRange($from, $to);

            try {
                $data = $history->get();
            } catch (\PDOException $e) {
                continue;
            }

            $root = $data['root'];
            $engine = (int) $this->stat($root, 'engine_hours');

            if ($engine <= 0) {
                continue;
            }

            $idle = (int) $this->stat($root, 'engine_idle');

            $refueling = 0;
            foreach ($data['groups']->all() as $group) {
                if (strpos($group->getKey(), 'geofence_in_') === 0) {
                    $refueling += (int) ($group->hasStat('duration') ? $group->getStat('duration')->get() : 0);
                }
            }

            $unauthorized = (int) OperationStop::where('device_id', $device->id)
                ->where('authorized', 0)->where('doubtful', 0)
                ->where('started_at', '>=', $from)->where('started_at', '<=', $to)
                ->sum('duration_seconds');

            // Fase 4: sensor "Pala en movimiento". Hasta que exista, 0.
            $bucketIdle = 0;

            $deducted = 0;
            if ($this->hasDeduction(Operation::DEDUCTION_REFUELING)) $deducted += $refueling;
            if ($this->hasDeduction(Operation::DEDUCTION_IDLE)) $deducted += $idle;
            if ($this->hasDeduction(Operation::DEDUCTION_UNAUTHORIZED_STOPS)) $deducted += $unauthorized;
            if ($this->hasDeduction(Operation::DEDUCTION_BUCKET_IDLE)) $deducted += $bucketIdle;

            $net = max(0, $engine - $deducted);

            $fuel = $root->hasStat('fuel_consumption') ? $root->getStat('fuel_consumption')->get() : null;
            $fuelValue = ($fuel !== null && $fuel > 0) ? (float) $fuel : null;
            if ($fuelValue !== null) $hasFuel = true;

            $rows[] = [
                'date'         => $dayLabel,
                'engine'       => Formatter::duration()->human($engine),
                'engine_s'     => $engine,
                'refueling'    => Formatter::duration()->human($refueling),
                'idle'         => Formatter::duration()->human($idle),
                'unauthorized' => Formatter::duration()->human($unauthorized),
                'bucket_idle'  => '—',
                'deducted'     => Formatter::duration()->human($deducted),
                'net'          => Formatter::duration()->human($net),
                'net_s'        => $net,
                // Galones (o litros) y galones por hora de motor
                'fuel'         => $fuelValue !== null ? Formatter::capacity()->human($fuelValue) : '',
                'fuel_per_h'   => $fuelValue !== null ? Formatter::capacity()->human($fuelValue / ($engine / 3600)) . '/h' : '',
                'fuel_raw'     => $fuelValue,
            ];
        }

        if (! $rows) {
            return null;
        }

        $engineTotal = array_sum(array_column($rows, 'engine_s'));
        $netTotal = array_sum(array_column($rows, 'net_s'));
        $fuelTotal = array_sum(array_filter(array_column($rows, 'fuel_raw')));

        $this->netTotalSeconds += $netTotal;
        $this->totals[trans('front.operation_net_hours')] = Formatter::duration()->human($this->netTotalSeconds);

        return [
            'meta'    => $this->getDeviceMeta($device),
            'table'   => ['rows' => $rows],
            'summary' => [
                'engine'     => Formatter::duration()->human($engineTotal),
                'net'        => Formatter::duration()->human($netTotal),
                'fuel'       => $hasFuel ? Formatter::capacity()->human($fuelTotal) : '',
                'fuel_per_h' => ($hasFuel && $engineTotal > 0) ? Formatter::capacity()->human($fuelTotal / ($engineTotal / 3600)) . '/h' : '',
                'has_fuel'   => $hasFuel,
            ],
        ];
    }

    /**
     * Parte el rango del reporte en dias de la zona horaria del usuario.
     * @return array<array{0:string,1:string,2:string}> [etiqueta, desde UTC, hasta UTC]
     */
    protected function localDays(): array
    {
        $days = [];
        $localFrom = Formatter::time()->convert($this->date_from, 'Y-m-d');
        $localTo = Formatter::time()->convert($this->date_to, 'Y-m-d');

        for ($d = new \DateTime($localFrom); $d->format('Y-m-d') <= $localTo; $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            $from = max($this->date_from, Formatter::time()->reverse($day . ' 00:00:00'));
            $to = min($this->date_to, Formatter::time()->reverse($day . ' 23:59:59'));

            if ($from <= $to) {
                $days[] = [Formatter::date()->human($from), $from, $to];
            }
        }

        return $days;
    }

    protected function stat($group, string $key)
    {
        if (! $group->hasStat($key)) {
            return null;
        }

        $stat = $group->getStat($key);

        return $stat ? $stat->get() : null;
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('front.object'),
            trans('global.date'),
            trans('front.engine_hours'),
            trans('front.operation_deduction_refueling'),
            trans('front.operation_deduction_idle'),
            trans('front.operation_deduction_unauthorized_stops'),
            trans('front.operation_deduction_bucket_idle'),
            trans('front.operation_deducted'),
            trans('front.operation_net_hours'),
            trans('front.fuel_consumption'),
            trans('front.operation_fuel_per_hour'),
        ]);

        foreach ($this->getItems() as $item) {
            $device = $item['meta']['device.name'] ?? '';

            foreach ($item['table']['rows'] ?? [] as $r) {
                fputcsv($file, [$device, $r['date'], $r['engine'], $r['refueling'], $r['idle'], $r['unauthorized'], $r['bucket_idle'], $r['deducted'], $r['net'], $r['fuel'], $r['fuel_per_h']]);
            }
        }
    }
}
