<?php

namespace Tobuli\Services\Operations;

use Illuminate\Support\Collection;
use Tobuli\Entities\Device;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationHourStat;
use Tobuli\Entities\OperationRefueling;
use Tobuli\Entities\OperationShift;
use Tobuli\Entities\OperationShiftSummary;
use Tobuli\Helpers\Formatter\Formatter;

/**
 * Reporte de turno (fase C · pantalla): arma el payload de la pestaña
 * «Turno» de Operaciones SOLO a partir de las tablas agregadas
 * (operation_shift_summaries, operation_hour_stats, operation_refuelings).
 * No lee posiciones. La misma clase la usara la exportacion a Excel (fase D)
 * para que pantalla y Excel salgan de las mismas series.
 *
 * Convenciones:
 * - `date` es el dia operativo (desde Operation::dayStart() hasta +24 h).
 * - Dia / Noche = primer y segundo turno de la operacion por `position`.
 * - Las 24 horas empiezan en la hora (en punto) en que empieza el primer
 *   turno del dia operativo: con day_start 06:30 y turnos 07:00/19:00 van de
 *   07 a 06; con turnos 06:30/18:30 van de 06 a 05.
 * - Velocidades: los agregados guardan km/h; se convierten a la unidad del
 *   usuario con Formatter::speed(). Distancias: metros -> unidad del usuario.
 * - Horas locales con el Formatter del usuario (misma zona que el dueño de
 *   la operacion, que es quien consulta).
 */
class OperationShiftReport
{
    const DAY_SECONDS = 86400;

    protected Formatter $formatter;

    protected OperationShiftSummaryService $service;

    public function __construct(Formatter $formatter, ?OperationShiftSummaryService $service = null)
    {
        $this->formatter = $formatter;
        $this->service = $service ?: new OperationShiftSummaryService();
    }

    /**
     * @param string $date  dia operativo (Y-m-d, local)
     * @param string $shift 'all' o id de turno
     */
    public function build(Operation $operation, string $date, string $shift = 'all'): array
    {
        $operation->loadMissing('user', 'shifts.breaks');

        $shiftsMeta = [];
        $selected = [];     // turnos incluidos: id => meta
        $dayId = null;
        $nightId = null;
        $firstStartLocalTs = null;

        foreach ($operation->shifts->values() as $i => $s) {
            /** @var OperationShift $s */
            $shiftDate = $this->service->shiftDateForOperativeDay($operation, $s, $date);
            [$startUtc, $endUtc] = $this->service->shiftRangeUtc($operation, $s, $shiftDate);

            $startLocalTs = $this->localTs($startUtc);
            $endLocalTs = $this->localTs($endUtc);

            $meta = [
                'id'         => (int) $s->id,
                'name'       => (string) $s->name,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time'   => substr((string) $s->end_time, 0, 5),
                'shift_date' => $shiftDate,
                'start_ts'   => $startLocalTs,
                'end_ts'     => $endLocalTs,
            ];

            $shiftsMeta[] = $meta;

            if ($i === 0) {
                $dayId = (int) $s->id;
            } elseif ($i === 1) {
                $nightId = (int) $s->id;
            }

            $firstStartLocalTs = is_null($firstStartLocalTs) ? $startLocalTs : min($firstStartLocalTs, $startLocalTs);

            if ($shift === 'all' || (int) $shift === (int) $s->id) {
                $selected[(int) $s->id] = $meta;
            }
        }

        $base = [
            'ok'        => true,
            'operation' => ['id' => (int) $operation->id, 'name' => (string) $operation->name, 'day_start' => $operation->dayStart()],
            'date'      => $date,
            'shift'     => $shift,
            'shifts'    => array_map(fn ($m) => array_diff_key($m, ['start_ts' => 1, 'end_ts' => 1]), $shiftsMeta),
            'units'     => [
                'speed'    => (string) $this->formatter->speed()->unit(),
                'distance' => (string) $this->formatter->distance()->unit(),
            ],
            'generated_at' => $this->formatter->time()->human(date('Y-m-d H:i:s')),
        ];

        if (empty($shiftsMeta) || empty($selected)) {
            return $base + ['empty' => true, 'hint' => trans('ui.ops_shift_no_shifts')];
        }

        $summaries = $this->summaries($operation, $selected);

        if ($summaries->isEmpty()) {
            return $base + ['empty' => true, 'hint' => trans('ui.ops_shift_empty')];
        }

        // Ventana de 24 h local: hora en punto en que empieza el primer turno.
        $firstHourTs = $firstStartLocalTs - ($firstStartLocalTs % 3600);
        $hourRows = $this->hourRows($operation, $firstHourTs);

        $hours = $this->hours($firstHourTs, $shiftsMeta, $selected, $hourRows);

        return $base + [
            'empty'  => false,
            'cards'  => $this->cards($summaries, $hours, $dayId, $nightId),
            'hours'  => $hours,
            'states' => $this->states($summaries),
            'tables' => $this->tables($summaries),
        ];
    }

    // ── Bloques ───────────────────────────────────────────────────────────

    /**
     * @param array $hours puntos por hora ya armados (para el promedio de rodando)
     */
    protected function cards(Collection $summaries, array $hours, ?int $dayId, ?int $nightId): array
    {
        $day = $summaries->where('shift_id', $dayId);
        $night = is_null($nightId) ? collect() : $summaries->where('shift_id', $nightId);

        $rollingDay = $this->avgRolling(array_filter($hours, fn ($h) => $h['shift_id'] === $dayId));
        $rollingNight = is_null($nightId) ? null : $this->avgRolling(array_filter($hours, fn ($h) => $h['shift_id'] === $nightId));

        $distDay = $day->isEmpty() ? null : $this->distance((int) $day->sum('distance_meters'));
        $distNight = $night->isEmpty() ? null : $this->distance((int) $night->sum('distance_meters'));

        return [
            'rolling' => [
                'day'   => $rollingDay,
                'night' => $rollingNight,
                'total' => $this->avgRolling(array_filter($hours, fn ($h) => ! is_null($h['shift_id']))),
            ],
            'speed_empty' => [
                'day'   => $this->avgSpeed($day, 'empty'),
                'night' => is_null($nightId) ? null : $this->avgSpeed($night, 'empty'),
                'avg'   => $this->avgSpeed($summaries, 'empty'),
            ],
            'speed_loaded' => [
                'day'   => $this->avgSpeed($day, 'loaded'),
                'night' => is_null($nightId) ? null : $this->avgSpeed($night, 'loaded'),
                'avg'   => $this->avgSpeed($summaries, 'loaded'),
            ],
            'distance' => [
                'day'   => $distDay,
                'night' => $distNight,
                'avg'   => $this->meanOf([$distDay, $distNight]),
            ],
        ];
    }

    /**
     * 24 puntos desde la primera hora del dia operativo.
     *
     * @param array      $shiftsMeta todos los turnos (para rotular la hora)
     * @param array      $selected   turnos incluidos (fuera de ellos, valores null)
     * @param Collection $hourRows   filas de operation_hour_stats agrupadas por hora local
     */
    protected function hours(int $firstHourTs, array $shiftsMeta, array $selected, Collection $hourRows): array
    {
        $out = [];

        for ($i = 0; $i < 24; $i++) {
            $ts = $firstHourTs + $i * 3600;
            $key = gmdate('Y-m-d H:00:00', $ts);
            $hour = (int) gmdate('G', $ts);

            $shiftMeta = $this->shiftAtTs($shiftsMeta, $ts);
            $included = $shiftMeta && isset($selected[$shiftMeta['id']]);

            $point = [
                'hour'         => $hour,
                'label'        => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                'at'           => gmdate('Y-m-d H:00', $ts),
                'shift'        => $shiftMeta ? $shiftMeta['name'] : null,
                'shift_id'     => $included ? $shiftMeta['id'] : null,
                'rolling'      => null,
                'speed_empty'  => null,
                'speed_loaded' => null,
                'fleet'        => null,
            ];

            if ($included) {
                $rows = $hourRows->get($key, collect());
                $devices = $rows->filter(fn (OperationHourStat $r) => ! is_null($r->device_id));
                $fleet = $rows->first(fn (OperationHourStat $r) => is_null($r->device_id));

                $point['rolling'] = $devices->filter(fn (OperationHourStat $r) => $r->moving_seconds > 0)->count();
                $point['speed_empty'] = $this->speedFrom((int) $devices->sum('empty_distance_m'), (int) $devices->sum('empty_moving_s'));
                $point['speed_loaded'] = $this->speedFrom((int) $devices->sum('loaded_distance_m'), (int) $devices->sum('loaded_moving_s'));

                if ($fleet && ! is_null($fleet->max_speed)) {
                    $point['fleet'] = [
                        'max' => $this->fleetPoint($fleet->max_speed, $fleet->max_speed_device_id, $fleet->max_speed_at),
                        'min' => $this->fleetPoint($fleet->min_speed, $fleet->min_speed_device_id, $fleet->min_speed_at),
                        'avg' => is_null($fleet->avg_speed) ? null : $this->speed($fleet->avg_speed),
                    ];
                }
            }

            $out[] = $point;
        }

        return $out;
    }

    /** Segundos por estado para el dia operativo, un vehiculo por fila (barra de 24 h). */
    protected function states(Collection $summaries): array
    {
        $rows = [];

        foreach ($summaries->groupBy('device_id') as $deviceId => $items) {
            $ready = (int) $items->sum('moving_seconds');
            $delay = (int) $items->sum('idle_seconds');
            $standby = (int) $items->sum('stop_engine_off_seconds');
            $maint = (int) $items->sum('maint_seconds');
            $noData = (int) $items->sum('gap_seconds');

            // Mantenimiento ya esta contado en standby (motor apagado) o en
            // ralenti: se descuenta de ahi para no sumarlo dos veces.
            $fromStandby = min($maint, $standby);
            $standby -= $fromStandby;
            $delay -= min($maint - $fromStandby, $delay);

            $used = $ready + $delay + $standby + $maint + $noData;

            $rows[] = [
                'device_id' => (int) $deviceId,
                'name'      => $this->deviceName($items->first()),
                'ready'     => $ready,
                'delay'     => $delay,
                'standby'   => $standby,
                'maint'     => $maint,
                'no_data'   => $noData,
                'off'       => max(0, self::DAY_SECONDS - $used),
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'total_seconds' => self::DAY_SECONDS,
            'legend'        => self::legend(),
            'rows'          => $rows,
        ];
    }

    /** Orden y colores del tablero del cliente. */
    public static function legend(): array
    {
        return [
            ['key' => 'delay',   'label' => trans('ui.ops_shift_state_delay'),   'color' => '#f59e0b'],
            ['key' => 'maint',   'label' => trans('ui.ops_shift_state_maint'),   'color' => '#dc2626'],
            ['key' => 'ready',   'label' => trans('ui.ops_shift_state_ready'),   'color' => '#16a34a'],
            ['key' => 'standby', 'label' => trans('ui.ops_shift_state_standby'), 'color' => '#eab308'],
            ['key' => 'no_data', 'label' => trans('ui.ops_shift_state_no_data'), 'color' => '#4b5563'],
            ['key' => 'off',     'label' => trans('ui.ops_shift_state_off'),     'color' => '#c4cad6'],
        ];
    }

    protected function tables(Collection $summaries): array
    {
        $ordered = $summaries->sortBy(fn (OperationShiftSummary $s) => [$this->deviceName($s), $s->shift_date, $s->shift_id])->values();

        $hoursMeter = [];
        $odometer = [];

        foreach ($ordered as $s) {
            /** @var OperationShiftSummary $s */
            $first = $s->first_move_at ? $s->first_move_at->getTimestamp() : null;
            $last = $s->last_move_at ? $s->last_move_at->getTimestamp() : null;
            $bStart = $s->break_start_at ? $s->break_start_at->getTimestamp() : null;
            $bEnd = $s->break_end_at ? $s->break_end_at->getTimestamp() : null;

            $effective = null;

            if (! is_null($first) && ! is_null($last)) {
                $effective = max(0, $last - $first);

                if (! is_null($bStart) && ! is_null($bEnd)) {
                    // Solo descuenta la parte del descanso que cae dentro de
                    // la jornada del vehiculo.
                    $effective -= max(0, min($bEnd, $last) - max($bStart, $first));
                    $effective = max(0, $effective);
                }
            }

            $hoursMeter[] = [
                'device_id'         => (int) $s->device_id,
                'device'            => $this->deviceName($s),
                'shift'             => (string) $s->shift_name,
                'first_move'        => $this->clock($s->first_move_at),
                'break_start'       => $this->clock($s->break_start_at),
                'break_end'         => $this->clock($s->break_end_at),
                'last_move'         => $this->clock($s->last_move_at),
                'effective_seconds' => $effective,
                'idle_seconds'      => (int) $s->idle_seconds,
                'engine_start'      => $this->num2($s->engine_hours_start),
                'engine_before'     => $this->num2($s->engine_hours_before_break),
                'engine_after'      => $this->num2($s->engine_hours_after_break),
                'engine_end'        => $this->num2($s->engine_hours_end),
                'engine_var'        => $this->num2($s->engineHoursDiff()),
            ];

            $odometer[] = [
                'device_id' => (int) $s->device_id,
                'device'    => $this->deviceName($s),
                'shift'     => (string) $s->shift_name,
                'start'     => $this->num2($s->odometer_start),
                'end'       => $this->num2($s->odometer_end),
                'var'       => $this->num2($s->odometerDiff()),
            ];
        }

        $byId = $ordered->keyBy('id');
        $refuelings = OperationRefueling::whereIn('summary_id', $byId->keys())
            ->orderBy('started_at')
            ->get()
            ->map(function (OperationRefueling $r) use ($byId) {
                $s = $byId->get($r->summary_id);

                return [
                    'device_id'        => (int) $r->device_id,
                    'device'           => $s ? $this->deviceName($s) : '',
                    'shift'            => $s ? (string) $s->shift_name : '',
                    'started'          => $this->clock($r->started_at),
                    'ended'            => $this->clock($r->ended_at),
                    'duration_seconds' => $r->durationSeconds(),
                    'level_before'     => $r->level_before_pct,
                    'level_after'      => $r->level_after_pct,
                    'refuel_pct'       => $r->refuel_pct,
                    'engine_off'       => (bool) $r->engine_off,
                ];
            })
            ->values()
            ->all();

        return [
            'hours_meter' => $hoursMeter,
            'odometer'    => $odometer,
            'refuelings'  => $refuelings,
        ];
    }

    // ── Consultas ─────────────────────────────────────────────────────────

    /** @param array<int, array> $selected id => meta (con shift_date) */
    protected function summaries(Operation $operation, array $selected): Collection
    {
        return OperationShiftSummary::where('operation_id', $operation->id)
            ->where(function ($q) use ($selected) {
                foreach ($selected as $meta) {
                    $q->orWhere(function ($w) use ($meta) {
                        $w->where('shift_id', $meta['id'])->where('shift_date', $meta['shift_date']);
                    });
                }
            })
            ->with('device:id,name')
            ->get();
    }

    /** Filas por hora (vehiculos y flota) de la ventana de 24 h, agrupadas por hora local. */
    protected function hourRows(Operation $operation, int $firstHourTs): Collection
    {
        return OperationHourStat::where('operation_id', $operation->id)
            ->where('hour_at', '>=', gmdate('Y-m-d H:00:00', $firstHourTs))
            ->where('hour_at', '<', gmdate('Y-m-d H:00:00', $firstHourTs + self::DAY_SECONDS))
            ->get()
            ->groupBy(fn (OperationHourStat $r) => $r->hour_at->format('Y-m-d H:00:00'));
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    protected function shiftAtTs(array $shiftsMeta, int $ts): ?array
    {
        foreach ($shiftsMeta as $meta) {
            if ($ts >= $meta['start_ts'] && $ts < $meta['end_ts']) {
                return $meta;
            }
        }

        return null;
    }

    protected function avgRolling(array $hours): ?float
    {
        $values = array_values(array_filter(array_map(fn ($h) => $h['rolling'], $hours), fn ($v) => ! is_null($v)));

        return $values ? round(array_sum($values) / count($values), 1) : null;
    }

    /** Σ distancia / Σ tiempo en movimiento por sentido, en la unidad del usuario. */
    protected function avgSpeed(Collection $summaries, string $direction): ?float
    {
        return $this->speedFrom((int) $summaries->sum($direction . '_distance_m'), (int) $summaries->sum($direction . '_moving_s'));
    }

    protected function speedFrom(int $meters, int $seconds): ?float
    {
        if ($seconds <= 0 || $meters <= 0) {
            return null;
        }

        return $this->speed($meters / $seconds * 3.6);
    }

    /** km/h -> unidad del usuario, 1 decimal. */
    protected function speed(float $kmh): float
    {
        return round((float) $this->formatter->speed()->convert($kmh), 1);
    }

    /** metros -> unidad de distancia del usuario, 1 decimal. */
    protected function distance(int $meters): float
    {
        return round((float) $this->formatter->distance()->convert($meters / 1000), 1);
    }

    protected function fleetPoint(?float $kmh, ?int $deviceId, $at): ?array
    {
        if (is_null($kmh)) {
            return null;
        }

        $device = $deviceId ? Device::find($deviceId, ['id', 'name']) : null;

        return [
            'speed'       => $this->speed($kmh),
            'device_id'   => $deviceId,
            'device_name' => $device ? (string) $device->name : null,
            'at'          => $at ? $this->formatter->time()->convert($at, 'Y-m-d H:i:s') : null,
        ];
    }

    protected function meanOf(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => ! is_null($v)));

        return $values ? round(array_sum($values) / count($values), 1) : null;
    }

    protected function clock($utc): ?string
    {
        return $utc ? $this->formatter->time()->convert($utc, 'H:i') : null;
    }

    protected function num2($value): ?float
    {
        return is_null($value) ? null : round((float) $value, 2);
    }

    protected function deviceName(OperationShiftSummary $s): string
    {
        return $s->device ? (string) $s->device->name : ('#' . $s->device_id);
    }

    /** Timestamp "local" (reloj del usuario leido como UTC) de una fecha-hora UTC. */
    protected function localTs(string $utc): int
    {
        return (new \DateTime($this->formatter->time()->convert($utc, 'Y-m-d H:i:s'), new \DateTimeZone('UTC')))->getTimestamp();
    }
}
