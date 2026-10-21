<?php

namespace Tobuli\Services\Operations;

use Illuminate\Support\Collection;
use Tobuli\Entities\Device;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationHourStat;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationRefueling;
use Tobuli\Entities\OperationShift;
use Tobuli\Entities\OperationShiftBreak;
use Tobuli\Entities\OperationShiftSummary;
use Tobuli\Entities\OperationStop;
use Tobuli\Entities\OperationStopReason;
use Tobuli\Helpers\Formatter\Formatter;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupFuelFilling;
use Tobuli\History\Actions\GroupGeofenceIn;
use Tobuli\History\DeviceHistory;
use Tobuli\History\Group;

/**
 * Reporte de turno (fase B · calculo): llena operation_shift_summaries,
 * operation_hour_stats y operation_refuelings.
 *
 * Por cada vehiculo y turno-fecha hace UNA pasada por las posiciones del
 * rango con el pipeline de historial (ShiftPositionScan + GroupGeofenceIn
 * para taller/descanso + GroupFuelFilling si hay sensor de tanque) y cruza
 * el resultado con lo ya calculado (vueltas, tramos, paradas).
 *
 * Decisiones:
 * - Rango del turno: [inicio, fin) en UTC a partir de la hora local del dueño
 *   (Formatter::time()->reverse) y OperationShift::crossesMidnight().
 * - Horas locales: desplazamiento local-UTC calculado una vez al inicio del
 *   rango (un cambio de horario de verano dentro del turno se ignora).
 * - DELAY (idle) y STANDBY (stop_engine_off) se calculan en la misma pasada
 *   que moving y gap, clasificando cada intervalo entre tramas (ver
 *   ShiftPositionScan). Asi moving + idle + stop_engine_off + gap = tiempo
 *   cubierto por tramas dentro del turno, sin dobles cuentas; la stat
 *   engine_idle del pipeline no excluye los huecos sin datos.
 * - Distancias y tiempo en movimiento por sentido salen de las tramas (cada
 *   trama pertenece al tramo abierto en ese instante), no de las columnas
 *   de los tramos: asi coinciden con las filas por hora y no dependen de que
 *   el tramo ya haya pasado por OperationLegCalculator (tras un recalculo
 *   los tramos quedan con calculated_at nulo hasta el siguiente
 *   operations:process).
 * - Todo es idempotente: el resumen se hace con updateOrCreate, las filas por
 *   hora y los abastecimientos se borran y recrean.
 */
class OperationShiftSummaryService
{
    /** Mismo criterio de "sin datos" que OperationLegCalculator. */
    const GAP_SECONDS = OperationLegCalculator::GAP_SECONDS;

    /** @var array<int, Formatter> */
    protected array $formatters = [];

    // ── API publica ───────────────────────────────────────────────────────

    /**
     * Calcula (o recalcula) el resumen de un vehiculo en un turno-fecha, sus
     * abastecimientos y sus filas por hora.
     *
     * @param string $shiftDate fecha local (Y-m-d) en que empezo el turno
     */
    public function buildShift(Operation $operation, Device $device, OperationShift $shift, string $shiftDate): OperationShiftSummary
    {
        $formatter = $this->formatter($operation);
        [$startUtc, $endUtc] = $this->shiftRangeUtc($operation, $shift, $shiftDate);

        $startTs = $this->utcTs($startUtc);
        $endTs = $this->utcTs($endUtc);
        $tzOffset = $this->tzOffset($formatter, $startUtc);

        // ── Descansos ──
        $shift->loadMissing('breaks');
        $scheduled = $shift->breaks->filter(fn (OperationShiftBreak $b) => $b->isScheduled() && $b->start_time && $b->end_time)->values();
        $mainBreak = $scheduled->first();

        $breakStartUtc = null;
        $breakEndUtc = null;
        $breakSeconds = 0;

        foreach ($scheduled as $i => $break) {
            [$bStart, $bEnd] = $this->breakRangeUtc($operation, $shift, $shiftDate, $break);
            $breakSeconds += max(0, $this->utcTs($bEnd) - $this->utcTs($bStart));

            if ($i === 0) {
                $breakStartUtc = $bStart;
                $breakEndUtc = $bEnd;
            }
        }

        // ── Datos ya calculados del rango ──
        $legs = $this->legsCrossing($operation, $device, $startUtc, $endUtc);
        $stops = $this->stopsIn($operation, $device, $startUtc, $endUtc);
        $cycles = OperationCycle::where('operation_id', $operation->id)
            ->where('device_id', $device->id)
            ->where('shift_id', $shift->id)
            ->where('shift_date', $shiftDate)
            ->get(['id', 'load_seconds', 'unload_seconds']);

        $geofences = Geofence::userOwned($operation->user)
            ->category([Geofence::CATEGORY_WORKSHOP, Geofence::CATEGORY_REST])
            ->get();
        $geofenceCategory = $geofences->pluck('category', 'id')->all();

        // ── Pasada por las posiciones ──
        $ctx = new ShiftScanContext();
        $ctx->minSpeed = $this->minMovingSpeed($device);
        $ctx->gapSeconds = self::GAP_SECONDS;
        $ctx->tzOffset = $tzOffset;
        $ctx->rangeStartTs = $startTs;
        $ctx->rangeEndTs = $endTs;
        $ctx->breakStartTs = $breakStartUtc ? $this->utcTs($breakStartUtc) : null;
        $ctx->breakEndTs = $breakEndUtc ? $this->utcTs($breakEndUtc) : null;
        $ctx->engineHoursSensor = $device->getEngineHoursSensor();
        $ctx->odometerSensor = $device->getOdometerSensor();
        $ctx->legs = $legs->map(fn (OperationLeg $leg) => [
            'start'    => $leg->started_at->getTimestamp(),
            'end'      => $leg->ended_at ? $leg->ended_at->getTimestamp() : null,
            'outbound' => $leg->isOutbound(),
        ])->values()->all();

        $hasFuelSensor = ! is_null($device->getFuelTankSensor());

        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_speed'        => $ctx->minSpeed,
            'stop_seconds'      => max(1, (int) $operation->stop_threshold_seconds),
            'min_fuel_fillings' => $device->min_fuel_fillings,
            'min_fuel_thefts'   => $device->min_fuel_thefts,
            'shift_scan'        => $ctx,
        ]);
        $history->setGeofences($geofences);

        $actions = [Duration::class, GroupGeofenceIn::class, ShiftPositionScan::class];

        if ($hasFuelSensor) {
            $actions[] = GroupFuelFilling::class;
        }

        $history->registerActions($actions);
        // whereBetween es inclusivo: el fin del turno pertenece al siguiente.
        $history->setRange($startUtc, date('Y-m-d H:i:s', $endTs - 1));

        $groups = collect();

        try {
            $data = $history->get();
            $groups = collect($data['groups']->all());
        } catch (\PDOException $e) {
            // Vehiculo sin tabla de posiciones: resumen vacio.
        }

        // ── Geocercas taller / descanso ──
        $workshopSeconds = 0;
        $restSeconds = 0;

        foreach ($groups as $group) {
            if (strpos($group->getKey(), 'geofence_in_') !== 0) {
                continue;
            }

            $category = $geofenceCategory[$group->geofence_id ?? 0] ?? null;
            $seconds = (int) $this->stat($group, 'duration');

            if ($category === Geofence::CATEGORY_WORKSHOP) {
                $workshopSeconds += $seconds;
            } elseif ($category === Geofence::CATEGORY_REST) {
                $restSeconds += $seconds;
            }
        }

        $maintStopSeconds = (int) $stops
            ->filter(fn (OperationStop $s) => $s->reason && $s->reason->state === OperationStopReason::STATE_MAINT)
            ->sum('duration_seconds');

        // ── Resumen ──
        $now = date('Y-m-d H:i:s');

        $summary = OperationShiftSummary::updateOrCreate(
            [
                'operation_id' => $operation->id,
                'device_id'    => $device->id,
                'shift_id'     => $shift->id,
                'shift_date'   => $shiftDate,
            ],
            [
                'shift_name'                => $shift->name,
                'first_move_at'             => $ctx->firstMoveAt,
                'last_move_at'              => $ctx->lastMoveAt,
                'break_start_at'            => $breakStartUtc,
                'break_end_at'              => $breakEndUtc,
                'break_source'              => $mainBreak ? OperationShiftSummary::BREAK_SCHEDULED : OperationShiftSummary::BREAK_NONE,
                'engine_hours_start'        => $ctx->engineHoursStart,
                'engine_hours_before_break' => $ctx->engineHoursBeforeBreak,
                'engine_hours_after_break'  => $ctx->engineHoursAfterBreak,
                'engine_hours_end'          => $ctx->engineHoursEnd,
                'odometer_start'            => $ctx->odometerStart,
                'odometer_end'              => $ctx->odometerEnd,
                'moving_seconds'            => (int) round($ctx->movingSeconds),
                'idle_seconds'              => (int) round($ctx->idleSeconds),
                'stop_engine_off_seconds'   => (int) round($ctx->stopEngineOffSeconds),
                'break_seconds'             => $breakSeconds,
                'gap_seconds'               => (int) round($ctx->gapSecondsTotal),
                'workshop_seconds'          => $workshopSeconds,
                'rest_seconds'              => $restSeconds,
                'maint_seconds'             => $workshopSeconds + $maintStopSeconds,
                'load_seconds'              => (int) $cycles->sum('load_seconds'),
                'unload_seconds'            => (int) $cycles->sum('unload_seconds'),
                'cycles_count'              => $cycles->count(),
                'distance_meters'           => (int) round($ctx->distanceMeters),
                'loaded_distance_m'         => (int) round($ctx->loadedDistanceM),
                'loaded_moving_s'           => (int) round($ctx->loadedMovingS),
                'empty_distance_m'          => (int) round($ctx->emptyDistanceM),
                'empty_moving_s'            => (int) round($ctx->emptyMovingS),
                'calculated_at'             => $now,
            ]
        );

        // ── Abastecimientos ──
        OperationRefueling::where('summary_id', $summary->id)->delete();

        foreach ($groups as $group) {
            if ($group->getKey() !== 'fuel_filling') {
                continue;
            }

            $start = $group->getStartPosition();
            $end = $group->getEndPosition();

            if (! $start || ! $end) {
                continue;
            }

            $stop = $this->stopCovering($stops, $start->time, $end->time);
            $sensorId = null;

            foreach ($device->sensors as $sensor) {
                if ($sensor->type === 'fuel_tank' && $sensor->name === ($group->sensor_name ?? null)) {
                    $sensorId = $sensor->id;
                    break;
                }
            }

            OperationRefueling::create([
                'operation_id'     => $operation->id,
                'device_id'        => $device->id,
                'summary_id'       => $summary->id,
                'stop_id'          => $stop ? $stop->id : null,
                'sensor_id'        => $sensorId,
                'started_at'       => $start->time,
                'ended_at'         => $end->time,
                'level_before_pct' => $this->numericStat($group, 'fuel_level_previous'),
                'level_after_pct'  => $this->numericStat($group, 'fuel_level_current'),
                'refuel_pct'       => $this->numericStat($group, 'fuel_level_difference'),
                'engine_off'       => $stop ? ((int) $stop->engine_off_seconds > 0) : false,
                'calculated_at'    => $now,
            ]);
        }

        // ── Filas por hora del vehiculo ──
        $this->writeDeviceHours($operation, $device, $ctx, $startTs, $endTs, $tzOffset, $now);

        return $summary;
    }

    /**
     * Dia operativo: cada turno de la operacion para cada vehiculo del grupo
     * (mas los que tengan vueltas en ese turno aunque ya no esten), y luego
     * las filas de flota de las horas que cubren esos turnos.
     *
     * @param string $operativeDate fecha local Y-m-d del dia operativo
     */
    public function buildDay(Operation $operation, string $operativeDate): void
    {
        $operation->loadMissing('user', 'shifts.breaks');

        if ($operation->shifts->isEmpty()) {
            return;
        }

        $spanFrom = null;
        $spanTo = null;

        foreach ($operation->shifts as $shift) {
            $shiftDate = $this->shiftDateForOperativeDay($operation, $shift, $operativeDate);
            [$startUtc, $endUtc] = $this->shiftRangeUtc($operation, $shift, $shiftDate);

            if ($this->utcTs($startUtc) > time()) {
                continue; // turno que aun no empieza
            }

            foreach ($this->devicesFor($operation, $shift, $shiftDate) as $device) {
                $this->buildShift($operation, $device, $shift, $shiftDate);
            }

            $spanFrom = is_null($spanFrom) ? $startUtc : min($spanFrom, $startUtc);
            $spanTo = is_null($spanTo) ? $endUtc : max($spanTo, $endUtc);
        }

        if ($spanFrom && $spanTo) {
            $this->buildFleetHours($operation, $spanFrom, $spanTo);
        }
    }

    /** Dias operativos [from, to] (fechas locales Y-m-d, ambos inclusive). */
    public function buildRange(Operation $operation, string $fromDate, string $toDate): void
    {
        for ($d = new \DateTime($fromDate); $d->format('Y-m-d') <= $toDate; $d->modify('+1 day')) {
            $this->buildDay($operation, $d->format('Y-m-d'));
        }
    }

    /**
     * Filas de flota (device_id NULL) por hora local a partir de las filas
     * por vehiculo: segundos y distancias sumados, media ponderada por tiempo
     * en movimiento, maxima y minima con el vehiculo y el instante.
     *
     * @param string $fromUtc inicio (UTC) del rango a agregar
     * @param string $toUtc   fin exclusivo (UTC)
     */
    public function buildFleetHours(Operation $operation, string $fromUtc, string $toUtc): void
    {
        $formatter = $this->formatter($operation);
        $offset = $this->tzOffset($formatter, $fromUtc);

        $fromTs = $this->utcTs($fromUtc) + $offset;
        $toTs = $this->utcTs($toUtc) + $offset;

        $firstHour = gmdate('Y-m-d H:00:00', $fromTs - ($fromTs % 3600));
        $lastHourExclusive = gmdate('Y-m-d H:00:00', $toTs + ($toTs % 3600 ? 3600 - ($toTs % 3600) : 0));

        $rows = OperationHourStat::where('operation_id', $operation->id)
            ->whereNotNull('device_id')
            ->where('hour_at', '>=', $firstHour)
            ->where('hour_at', '<', $lastHourExclusive)
            ->get()
            ->groupBy(fn (OperationHourStat $r) => $r->hour_at->format('Y-m-d H:00:00'));

        OperationHourStat::where('operation_id', $operation->id)
            ->whereNull('device_id')
            ->where('hour_at', '>=', $firstHour)
            ->where('hour_at', '<', $lastHourExclusive)
            ->delete();

        $now = date('Y-m-d H:i:s');
        $inserts = [];

        for ($h = new \DateTime($firstHour); $h->format('Y-m-d H:00:00') < $lastHourExclusive; $h->modify('+1 hour')) {
            $key = $h->format('Y-m-d H:00:00');
            $fleet = [
                'operation_id'        => $operation->id,
                'device_id'           => null,
                'hour_at'             => $key,
                'moving_seconds'      => 0,
                'loaded_distance_m'   => 0,
                'loaded_moving_s'     => 0,
                'empty_distance_m'    => 0,
                'empty_moving_s'      => 0,
                'max_speed'           => null,
                'min_speed'           => null,
                'avg_speed'           => null,
                'max_speed_device_id' => null,
                'min_speed_device_id' => null,
                'max_speed_at'        => null,
                'min_speed_at'        => null,
                'calculated_at'       => $now,
            ];

            $speedTime = 0.0;

            foreach ($rows->get($key, collect()) as $row) {
                $fleet['moving_seconds'] += $row->moving_seconds;
                $fleet['loaded_distance_m'] += $row->loaded_distance_m;
                $fleet['loaded_moving_s'] += $row->loaded_moving_s;
                $fleet['empty_distance_m'] += $row->empty_distance_m;
                $fleet['empty_moving_s'] += $row->empty_moving_s;

                if (! is_null($row->avg_speed)) {
                    $speedTime += $row->avg_speed * $row->moving_seconds;
                }

                if (! is_null($row->max_speed) && (is_null($fleet['max_speed']) || $row->max_speed > $fleet['max_speed'])) {
                    $fleet['max_speed'] = $row->max_speed;
                    $fleet['max_speed_device_id'] = $row->device_id;
                    $fleet['max_speed_at'] = $row->max_speed_at ? $row->max_speed_at->format('Y-m-d H:i:s') : null;
                }

                if (! is_null($row->min_speed) && (is_null($fleet['min_speed']) || $row->min_speed < $fleet['min_speed'])) {
                    $fleet['min_speed'] = $row->min_speed;
                    $fleet['min_speed_device_id'] = $row->device_id;
                    $fleet['min_speed_at'] = $row->min_speed_at ? $row->min_speed_at->format('Y-m-d H:i:s') : null;
                }
            }

            if ($fleet['moving_seconds'] > 0) {
                $fleet['avg_speed'] = round($speedTime / $fleet['moving_seconds'], 1);
            }

            $inserts[] = $fleet;
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            OperationHourStat::insert($chunk);
        }
    }

    /**
     * Enganche del recalculo: rehace los resumenes de los turnos de un
     * vehiculo cuyo rango cruza [fromUtc, ahora] y las filas de flota de esas
     * horas.
     */
    public function rebuildFrom(Operation $operation, Device $device, string $fromUtc): void
    {
        $operation->loadMissing('user', 'shifts.breaks');

        if ($operation->shifts->isEmpty()) {
            return;
        }

        $formatter = $this->formatter($operation);
        $nowTs = time();
        $fromTs = $this->utcTs($fromUtc);

        // Un turno que cruza la medianoche puede haber empezado el dia local
        // anterior a `from`; se mira desde ahi.
        $localFrom = $formatter->time()->convert($fromUtc, 'Y-m-d');
        $localNow = $formatter->time()->convert(date('Y-m-d H:i:s'), 'Y-m-d');

        $spanFrom = null;
        $spanTo = null;

        for ($d = (new \DateTime($localFrom))->modify('-1 day'); $d->format('Y-m-d') <= $localNow; $d->modify('+1 day')) {
            $shiftDate = $d->format('Y-m-d');

            foreach ($operation->shifts as $shift) {
                [$startUtc, $endUtc] = $this->shiftRangeUtc($operation, $shift, $shiftDate);
                $startTs = $this->utcTs($startUtc);
                $endTs = $this->utcTs($endUtc);

                if ($endTs <= $fromTs || $startTs > $nowTs) {
                    continue;
                }

                $this->buildShift($operation, $device, $shift, $shiftDate);

                $spanFrom = is_null($spanFrom) ? $startUtc : min($spanFrom, $startUtc);
                $spanTo = is_null($spanTo) ? $endUtc : max($spanTo, $endUtc);
            }
        }

        if ($spanFrom && $spanTo) {
            $this->buildFleetHours($operation, $spanFrom, $spanTo);
        }
    }

    /**
     * Rango UTC [inicio, fin) de un turno en una fecha local.
     *
     * @return array{0:string,1:string}
     */
    public function shiftRangeUtc(Operation $operation, OperationShift $shift, string $shiftDate): array
    {
        $formatter = $this->formatter($operation);

        $startLocal = $shiftDate . ' ' . $this->hms($shift->start_time);
        $endDate = $shift->crossesMidnight()
            ? (new \DateTime($shiftDate))->modify('+1 day')->format('Y-m-d')
            : $shiftDate;
        $endLocal = $endDate . ' ' . $this->hms($shift->end_time);

        return [
            $formatter->time()->reverse($startLocal, 'Y-m-d H:i:s'),
            $formatter->time()->reverse($endLocal, 'Y-m-d H:i:s'),
        ];
    }

    /**
     * Fecha de turno que corresponde a un dia operativo: el turno empieza el
     * mismo dia si su hora de inicio es >= day_start; si no, el dia siguiente
     * (un turno de 02:00 del dia operativo del 5 empieza el 6).
     */
    public function shiftDateForOperativeDay(Operation $operation, OperationShift $shift, string $operativeDate): string
    {
        $dayStart = OperationShift::seconds($operation->dayStart());

        if (OperationShift::seconds($shift->start_time) >= $dayStart) {
            return $operativeDate;
        }

        return (new \DateTime($operativeDate))->modify('+1 day')->format('Y-m-d');
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /**
     * Rango UTC de un descanso programado en la fecha del turno. Si el turno
     * cruza la medianoche y el descanso cae antes de la hora de inicio del
     * turno, es del dia siguiente (Cena 02:00 en Noche 19:00-07:00).
     *
     * @return array{0:string,1:string}
     */
    protected function breakRangeUtc(Operation $operation, OperationShift $shift, string $shiftDate, OperationShiftBreak $break): array
    {
        $formatter = $this->formatter($operation);

        $startDate = new \DateTime($shiftDate);

        if ($shift->crossesMidnight() && OperationShift::seconds($break->start_time) < OperationShift::seconds($shift->start_time)) {
            $startDate->modify('+1 day');
        }

        $endDate = clone $startDate;

        if ($break->crossesMidnight()) {
            $endDate->modify('+1 day');
        }

        return [
            $formatter->time()->reverse($startDate->format('Y-m-d') . ' ' . $this->hms($break->start_time), 'Y-m-d H:i:s'),
            $formatter->time()->reverse($endDate->format('Y-m-d') . ' ' . $this->hms($break->end_time), 'Y-m-d H:i:s'),
        ];
    }

    /** Vehiculos del grupo mas los que tengan vueltas en ese turno-fecha. */
    protected function devicesFor(Operation $operation, OperationShift $shift, string $shiftDate): Collection
    {
        $ids = $operation->deviceIds()
            ->merge(
                OperationCycle::where('operation_id', $operation->id)
                    ->where('shift_id', $shift->id)
                    ->where('shift_date', $shiftDate)
                    ->distinct()
                    ->pluck('device_id')
            )
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Device::whereIn('id', $ids)->with('sensors')->get();
    }

    protected function legsCrossing(Operation $operation, Device $device, string $startUtc, string $endUtc): Collection
    {
        return OperationLeg::where('device_id', $device->id)
            ->whereHas('cycle', fn ($q) => $q->where('operation_id', $operation->id))
            ->where('started_at', '<', $endUtc)
            ->where(function ($q) use ($startUtc) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>', $startUtc);
            })
            ->orderBy('started_at')
            ->get(['id', 'cycle_id', 'direction', 'started_at', 'ended_at']);
    }

    /** Paradas del vehiculo en la operacion que cruzan el rango, con su motivo. */
    protected function stopsIn(Operation $operation, Device $device, string $startUtc, string $endUtc): Collection
    {
        return OperationStop::where('device_id', $device->id)
            ->whereHas('leg.cycle', fn ($q) => $q->where('operation_id', $operation->id))
            ->where('started_at', '<', $endUtc)
            ->where('ended_at', '>', $startUtc)
            ->with('reason')
            ->orderBy('started_at')
            ->get();
    }

    /** Parada que contiene (o solapa) el intervalo de un abastecimiento. */
    protected function stopCovering(Collection $stops, string $fromUtc, string $toUtc): ?OperationStop
    {
        $fromTs = $this->utcTs($fromUtc);
        $toTs = $this->utcTs($toUtc);

        foreach ($stops as $stop) {
            $s = $stop->started_at->getTimestamp();
            $e = $stop->ended_at ? $stop->ended_at->getTimestamp() : PHP_INT_MAX;

            if ($s <= $toTs && $e >= $fromTs) {
                return $stop;
            }
        }

        return null;
    }

    /** Borra y recrea las filas por hora del vehiculo para las horas del turno. */
    protected function writeDeviceHours(Operation $operation, Device $device, ShiftScanContext $ctx, int $startTs, int $endTs, int $offset, string $now): void
    {
        $localStart = $startTs + $offset;
        $localEnd = $endTs + $offset;

        $firstHour = gmdate('Y-m-d H:00:00', $localStart - ($localStart % 3600));
        $lastHourExclusive = gmdate('Y-m-d H:00:00', $localEnd + ($localEnd % 3600 ? 3600 - ($localEnd % 3600) : 0));

        OperationHourStat::where('operation_id', $operation->id)
            ->where('device_id', $device->id)
            ->where('hour_at', '>=', $firstHour)
            ->where('hour_at', '<', $lastHourExclusive)
            ->delete();

        $inserts = [];

        for ($h = new \DateTime($firstHour); $h->format('Y-m-d H:00:00') < $lastHourExclusive; $h->modify('+1 hour')) {
            $key = $h->format('Y-m-d H:00:00');
            $acc = $ctx->hours[$key] ?? $ctx->hour($key);

            $moving = (int) round($acc['moving']);

            $inserts[] = [
                'operation_id'        => $operation->id,
                'device_id'           => $device->id,
                'hour_at'             => $key,
                'moving_seconds'      => $moving,
                'loaded_distance_m'   => (int) round($acc['loaded_d']),
                'loaded_moving_s'     => (int) round($acc['loaded_s']),
                'empty_distance_m'    => (int) round($acc['empty_d']),
                'empty_moving_s'      => (int) round($acc['empty_s']),
                'max_speed'           => is_null($acc['max_speed']) ? null : round($acc['max_speed'], 1),
                'min_speed'           => is_null($acc['min_speed']) ? null : round($acc['min_speed'], 1),
                'avg_speed'           => $moving > 0 ? round($acc['speed_time'] / $acc['moving'], 1) : null,
                'max_speed_device_id' => is_null($acc['max_speed']) ? null : $device->id,
                'min_speed_device_id' => is_null($acc['min_speed']) ? null : $device->id,
                'max_speed_at'        => $acc['max_speed_at'],
                'min_speed_at'        => $acc['min_speed_at'],
                'calculated_at'       => $now,
            ];
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            OperationHourStat::insert($chunk);
        }
    }

    protected function minMovingSpeed(Device $device): float
    {
        $speed = $device->min_moving_speed;

        if (is_null($speed) || $speed === '') {
            $speed = config('tobuli.device.min_moving_speed', 6);
        }

        return (float) $speed;
    }

    protected function formatter(Operation $operation): Formatter
    {
        $userId = (int) $operation->user_id;

        if (! isset($this->formatters[$userId])) {
            $operation->loadMissing('user');

            $formatter = new Formatter();
            $formatter->byUser($operation->user);

            $this->formatters[$userId] = $formatter;
        }

        return $this->formatters[$userId];
    }

    /** Segundos entre la hora local del dueño y UTC en un instante dado. */
    protected function tzOffset(Formatter $formatter, string $utc): int
    {
        $local = $formatter->time()->convert($utc, 'Y-m-d H:i:s');

        return $this->utcTs($local) - $this->utcTs($utc);
    }

    protected function utcTs(string $datetime): int
    {
        return (new \DateTime($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }

    protected function hms(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    protected function stat(Group $group, string $key)
    {
        if (! $group->hasStat($key)) {
            return null;
        }

        $stat = $group->getStat($key);

        return $stat ? $stat->get() : null;
    }

    protected function numericStat(Group $group, string $key): ?float
    {
        $value = $this->stat($group, $key);

        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
