<?php

namespace Tobuli\Services\Security;

use Carbon\Carbon;
use Tobuli\Entities\Device;
use Tobuli\Entities\Event;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\User;
use Tobuli\History\Actions\AppendSpeedLimitGeofenceOrStatic;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\GroupOverspeed;
use Tobuli\History\Actions\NoDataDuration;
use Tobuli\History\Actions\Overspeed;
use Tobuli\History\Actions\Speed;
use Tobuli\History\DeviceHistory;
use Illuminate\Support\Collection;

/**
 * Calcula y guarda la fila (unidad, dia, turno) de security_daily_stats.
 * Una pasada del pipeline de historial por turno; eventos de la tabla
 * events; cinturon leyendo el sensor posicion a posicion.
 */
class SecurityDailyCalculator
{
    private User $user;
    private SecuritySetting $settings;
    private SecurityShift $shift;
    private Collection $geofences; // geocercas del usuario con sus limites por tipo/estado

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->settings = SecuritySetting::forUser($user);
        $this->shift = new SecurityShift($this->settings, $this->timezone($user));
        $this->geofences = Geofence::where('user_id', $user->id)->with('vehicleSpeedLimits')->get();
    }

    public function calculate(Device $device, string $workDate, string $shift): ?SecurityDailyStat
    {
        [$from, $to] = $this->shift->range($workDate, $shift);

        // Un turno que todavia no empieza no se guarda: saldria como "Sin
        // registro" en el detalle y en el Excel.
        if ($from->gt(now())) {
            return null;
        }

        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_speed'   => $device->min_moving_speed,
            'speed_limit'  => $this->settings->speed_limit,
            'gap_seconds'  => $this->settings->gap_minutes * 60,
            'stop_seconds' => $this->settings->stop_minutes * 60,
        ]);
        $history->setGeofences($this->geofences);
        // El orden importa: el limite de velocidad debe anexarse antes de
        // que GroupOverspeed/Overspeed evaluen cada posicion.
        $history->registerActions([
            AppendSpeedLimitGeofenceOrStatic::class,
            Duration::class, Distance::class, DriveStop::class, EngineHours::class, Speed::class,
            NoDataDuration::class,
            GroupOverspeed::class, Overspeed::class,
        ]);
        $history->setRange($from, $to);

        $data = $history->get();
        $root = $data['root'];
        $groups = collect($data['groups']->all());

        $overspeedGroups = $groups->filter(fn($g) => strpos($g->getKey(), 'overspeed') === 0);

        // Solo los eventos de este usuario: las unidades se comparten entre
        // cuentas y las alertas de otro usuario no son de este tablero.
        $events = Event::where('device_id', $device->id)
            ->where('user_id', $this->user->id)
            ->where('time', '>=', $from)->where('time', '<', $to)
            ->whereIn('type', [Event::TYPE_OVERSPEED, Event::TYPE_ZONE_OVERSPEED, Event::TYPE_ZONE_OUT, Event::TYPE_POWER_CUT, Event::TYPE_OFFLINE_DURATION])
            ->get(['type', 'time']);

        $overspeedEvents = $events->whereIn('type', [Event::TYPE_OVERSPEED, Event::TYPE_ZONE_OVERSPEED]);
        $overspeedBounds = $overspeedGroups->map(fn($g) => $this->groupBounds($g, $to))->values();
        $overspeedTotal = $overspeedGroups->count() + $overspeedEvents->filter(function ($e) use ($overspeedBounds) {
            // Un evento dentro de un tramo calculado ya esta contado.
            $t = Carbon::parse($e->time);

            return ! $overspeedBounds->contains(fn($b) => $t->between($b[0], $b[1]));
        })->count();

        $positions = $this->countPositions($device, $from, $to);

        return SecurityDailyStat::updateOrCreate(
            ['device_id' => $device->id, 'work_date' => $workDate, 'shift' => $shift],
            [
                'user_id'           => $this->user->id,
                'range_from'        => $from,
                'range_to'          => $to,
                'distance_km'       => round((float) $this->stat($root, 'distance', 0), 3), // el stat ya viene en km (getDistance)
                'engine_seconds'    => (int) $this->stat($root, 'engine_hours', 0),
                'moving_seconds'    => (int) $this->stat($root, 'drive_duration', 0),
                'stop_count'        => (int) $this->stat($root, 'stop_count', 0),
                'speed_max'         => $positions ? (float) $this->stat($root, 'speed_max', 0) : null,
                'overspeed_events'  => $overspeedEvents->count(),
                'overspeed_calc'    => $overspeedGroups->count(),
                'overspeed_total'   => $overspeedTotal,
                // Salidas de geocerca: solo los eventos de las alertas "Geocerca de
                // salida" del usuario (decision del cliente), no cada salida del historial.
                'zone_exits'        => $events->where('type', Event::TYPE_ZONE_OUT)->count(),
                'disconnect_events' => $events->whereIn('type', [Event::TYPE_POWER_CUT, Event::TYPE_OFFLINE_DURATION])->count(),
                'signal_gaps'       => (int) $this->stat($root, 'no_data_count', 0),
                'seatbelt_alerts'   => $this->seatbeltAlerts($device, $from, $to),
                'positions'         => $positions,
                'calculated_at'     => now(),
            ]
        );
    }

    /**
     * Recalcula todas las unidades del usuario entre dos dias locales (ambos
     * incluidos). Sin $force se saltan los turnos ya cerrados y calculados
     * despues de su fin (el proceso horario los repetiria sin cambios).
     */
    public function calculateRange(string $fromDate, string $toDate, ?array $deviceIds = null, bool $force = false): int
    {
        $devices = $this->user->devices()->clearOrdersBy()->when($deviceIds, fn($q) => $q->whereIn('devices.id', $deviceIds))->get();
        $n = 0;
        $end = Carbon::parse($toDate);
        for ($d = Carbon::parse($fromDate); $d->lte($end); $d->addDay()) {
            foreach ($devices as $device) {
                foreach (SecurityDailyStat::shifts() as $shift) {
                    if (! $force && $this->isFinal($device, $d->toDateString(), $shift)) {
                        continue;
                    }
                    try {
                        if ($this->calculate($device, $d->toDateString(), $shift)) {
                            $n++;
                        }
                    } catch (\PDOException $e) {
                        // 42S02 = unidad sin tabla de posiciones: se omite en silencio.
                        if ((string) $e->getCode() !== '42S02') {
                            \Log::warning('security:aggregate', ['device' => $device->id, 'error' => $e->getMessage()]);
                        }
                    } catch (\Throwable $e) {
                        // Unidad sin traccar u otro fallo puntual: no tumba la pasada entera.
                        \Log::warning('security:aggregate', ['device' => $device->id, 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        return $n;
    }

    /** Turno cerrado y calculado al menos media hora despues de su fin. */
    private function isFinal(Device $device, string $workDate, string $shift): bool
    {
        [, $to] = $this->shift->range($workDate, $shift);
        if ($to->gt(now())) {
            return false;
        }
        $calculatedAt = SecurityDailyStat::where('device_id', $device->id)
            ->where('work_date', $workDate)->where('shift', $shift)->value('calculated_at');

        return $calculatedAt && Carbon::parse($calculatedAt)->gte($to->copy()->addMinutes(30));
    }

    /**
     * Zona horaria del usuario en un formato que Carbon acepta ("-05:00").
     * Timezone::zone es relativo ("-5 hours") y Carbon no lo entiende.
     */
    private function timezone(User $user): string
    {
        $tz = $user->timezone;

        return $tz && $tz->hi_format ? $tz->hi_format : config('app.timezone');
    }

    /** [inicio, fin] de un tramo; un tramo abierto al cierre del rango termina en range_to. */
    private function groupBounds($group, Carbon $rangeTo): array
    {
        $start = Carbon::parse($group->getStartPosition()->time);
        $end = $group->getEndPosition() ? Carbon::parse($group->getEndPosition()->time) : $rangeTo->copy();

        return [$start, $end];
    }

    private function stat($group, string $key, $default)
    {
        if (! $group->hasStat($key)) return $default;
        $s = $group->getStat($key);

        return $s ? $s->get() : $default;
    }

    private function countPositions(Device $device, Carbon $from, Carbon $to): int
    {
        return (int) $device->positions()->where('time', '>=', $from)->where('time', '<', $to)->count();
    }

    /**
     * Alertas de cinturon: tramos de >= 30 s con el sensor en 0 y velocidad
     * >= 1 km/h. null si la unidad no tiene sensor de tipo seatbelt.
     *
     * Se lee el parametro de cada trama historica con getValue($other, false):
     * getValueCurrent() cae al valor EN VIVO del equipo cuando la trama no trae
     * el parametro y falsearia el pasado. Una trama sin dato no cambia el
     * estado: se arrastra el ultimo conocido (y se omite si aun no hay ninguno).
     */
    private function seatbeltAlerts(Device $device, Carbon $from, Carbon $to): ?int
    {
        $sensor = $device->getSensorByType('seatbelt');
        if (! $sensor) return null;

        $alerts = 0; $openSince = null; $buckled = null;
        $device->positions()->where('time', '>=', $from)->where('time', '<', $to)->orderBy('time')
            ->chunk(2000, function ($rows) use ($sensor, &$alerts, &$openSince, &$buckled) {
                foreach ($rows as $p) {
                    $value = $sensor->getValue($p->other, false);
                    if ($value instanceof \Tobuli\Sensors\Value) $value = $value->getValue();
                    if ($value !== null) $buckled = (bool) $value;
                    if ($buckled === null) continue;

                    $unbuckled = ! $buckled && $p->speed >= 1;
                    if ($unbuckled && $openSince === null) {
                        $openSince = Carbon::parse($p->time);
                    } elseif (! $unbuckled && $openSince !== null) {
                        if (Carbon::parse($p->time)->diffInSeconds($openSince) >= 30) $alerts++;
                        $openSince = null;
                    }
                }
            });

        return $alerts;
    }
}
