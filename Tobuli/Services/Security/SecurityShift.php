<?php

namespace Tobuli\Services\Security;

use Carbon\Carbon;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Entities\SecuritySetting;

/**
 * Convierte (dia local, turno) en un rango UTC. La noche empieza el dia
 * indicado y termina al dia siguiente (19:00 D -> 07:00 D+1).
 */
class SecurityShift
{
    private string $tz;
    private string $dayStart;
    private string $dayEnd;

    public function __construct(SecuritySetting $settings, string $timezone)
    {
        $this->tz = $timezone;
        $this->dayStart = $settings->day_start;
        $this->dayEnd = $settings->day_end;
    }

    /** @return array{0: Carbon, 1: Carbon} [desde, hasta) en UTC */
    public function range(string $workDate, string $shift): array
    {
        $day = Carbon::parse($workDate, $this->tz)->startOfDay();

        if ($shift === SecurityDailyStat::SHIFT_DAY) {
            $from = $day->copy()->setTimeFromTimeString($this->dayStart);
            $to = $day->copy()->setTimeFromTimeString($this->dayEnd);
        } else {
            $from = $day->copy()->setTimeFromTimeString($this->dayEnd);
            $to = $day->copy()->addDay()->setTimeFromTimeString($this->dayStart);
        }

        return [$from->utc(), $to->utc()];
    }

    /** Turno al que pertenece un instante UTC, y su dia de trabajo local. */
    public function shiftAt(Carbon $utc): array
    {
        $local = $utc->copy()->setTimezone($this->tz);
        $start = $local->copy()->setTimeFromTimeString($this->dayStart);
        $end = $local->copy()->setTimeFromTimeString($this->dayEnd);

        if ($local >= $start && $local < $end) {
            return [$local->toDateString(), SecurityDailyStat::SHIFT_DAY];
        }
        // Noche: antes del inicio del dia pertenece a la noche del dia anterior.
        $workDate = $local < $start ? $local->copy()->subDay()->toDateString() : $local->toDateString();

        return [$workDate, SecurityDailyStat::SHIFT_NIGHT];
    }
}
