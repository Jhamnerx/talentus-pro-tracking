<?php

namespace Tobuli\Services\Security;

use Illuminate\Support\Collection;
use Tobuli\Entities\SecurityDailyStat;

/**
 * Agrega filas de SecurityDailyStat (ya filtradas por usuario, fechas, turno,
 * tipo y unidad) en tarjetas, resumen diario y detalle por unidad.
 *
 * perUnitDaily() y perUnit() leen `$stat->device->name` y
 * `$stat->device->vehicleType->name`: el llamador debe eager-loadear
 * `device.vehicleType` (`SecurityDailyStat::with('device.vehicleType')`).
 */
class SecuritySummary
{
    /** @param Collection<SecurityDailyStat> $stats */
    public function cards(Collection $stats): array
    {
        return [
            'overspeed'   => (int) $stats->sum('overspeed_total'),
            'seatbelt'    => (int) $stats->whereNotNull('seatbelt_alerts')->sum('seatbelt_alerts'),
            'zone_exits'  => (int) $stats->sum('zone_exits'),
            'distance_km' => round($stats->sum('distance_km'), 1),
            'engine_h'    => round($stats->sum('engine_seconds') / 3600, 2),
            'moving_h'    => round($stats->sum('moving_seconds') / 3600, 2),
            'stops'       => (int) $stats->sum('stop_count'),
            'disconnects' => (int) ($stats->sum('disconnect_events') + $stats->sum('signal_gaps')),
        ];
    }

    /**
     * Una fila por fecha: km, horas en movimiento, media, maxima, unidades en
     * movimiento / stand by, excesos.
     *
     * @param Collection<SecurityDailyStat> $stats
     */
    public function daily(Collection $stats): array
    {
        return $stats
            ->groupBy(fn ($s) => $s->work_date->toDateString())
            ->map(function (Collection $rows, string $date) {
                $km = $rows->sum('distance_km');
                $mh = $rows->sum('moving_seconds') / 3600;
                $byUnit = $rows->groupBy('device_id');

                return [
                    'date'      => $date,
                    'km'        => round($km, 1),
                    'moving_h'  => round($mh, 2),
                    'avg_kmh'   => $mh > 0 ? round($km / $mh, 1) : null,
                    'max_kmh'   => $rows->max('speed_max'),
                    'moving'    => $byUnit->filter(fn ($u) => $u->sum('moving_seconds') > 0)->count(),
                    'standby'   => $byUnit->filter(fn ($u) => $u->sum('moving_seconds') == 0 && $u->sum('engine_seconds') > 0)->count(),
                    'overspeed' => (int) $rows->sum('overspeed_total'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Detalle diario por unidad (hoja Seguridad, bloque fechas x unidades).
     * Requiere `device.vehicleType` eager-loaded.
     *
     * @param Collection<SecurityDailyStat> $stats
     */
    public function perUnitDaily(Collection $stats): array
    {
        return $stats
            ->groupBy(fn ($s) => $s->work_date->toDateString() . '|' . $s->device_id)
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $km = $rows->sum('distance_km');
                $mh = $rows->sum('moving_seconds') / 3600;
                $eh = $rows->sum('engine_seconds') / 3600;
                $positions = (int) $rows->sum('positions');

                return [
                    'date'      => $first->work_date->toDateString(),
                    'device_id' => $first->device_id,
                    'unit'      => optional($first->device)->name,
                    'type'      => optional(optional($first->device)->vehicleType)->name,
                    'km'        => round($km, 1),
                    'moving_h'  => round($mh, 2),
                    'engine_h'  => round($eh, 2),
                    'avg_kmh'   => $mh > 0 ? round($km / $mh, 1) : null,
                    'max_kmh'   => $rows->max('speed_max'),
                    'state'     => $this->dailyState($positions, $mh, $eh),
                    'records'   => $positions,
                    'stops'     => (int) $rows->sum('stop_count'),
                    'overspeed' => (int) $rows->sum('overspeed_total'),
                    'seatbelt'  => $rows->whereNotNull('seatbelt_alerts')->isEmpty()
                        ? null
                        : (int) $rows->sum('seatbelt_alerts'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Totales por unidad en el periodo (hoja Operaciones).
     * Requiere `device.vehicleType` eager-loaded.
     *
     * @param Collection<SecurityDailyStat> $stats
     */
    public function perUnit(Collection $stats): array
    {
        return $stats
            ->groupBy('device_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $km = $rows->sum('distance_km');
                $mh = $rows->sum('moving_seconds') / 3600;

                return [
                    'device_id' => $first->device_id,
                    'unit'      => optional($first->device)->name,
                    'type'      => optional(optional($first->device)->vehicleType)->name,
                    'km'        => round($km, 1),
                    'engine_h'  => round($rows->sum('engine_seconds') / 3600, 2),
                    'moving_h'  => round($mh, 2),
                    'stops'     => (int) $rows->sum('stop_count'),
                    'records'   => (int) $rows->sum('positions'),
                    'overspeed' => (int) $rows->sum('overspeed_total'),
                    'avg_kmh'   => $mh > 0 ? round($km / $mh, 1) : null,
                ];
            })
            ->values()
            ->all();
    }

    /** Misma regla que SecurityDailyStat::dailyState() aplicada a un grupo de filas. */
    private function dailyState(int $positions, float $movingHours, float $engineHours): string
    {
        if ($positions <= 0) {
            return SecurityDailyStat::STATE_NO_DATA;
        }

        if ($movingHours > 0) {
            return SecurityDailyStat::STATE_MOVING;
        }

        if ($engineHours > 0) {
            return SecurityDailyStat::STATE_STANDBY;
        }

        return SecurityDailyStat::STATE_INACTIVE;
    }
}
