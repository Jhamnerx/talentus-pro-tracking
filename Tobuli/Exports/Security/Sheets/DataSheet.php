<?php

namespace Tobuli\Exports\Security\Sheets;

use Illuminate\Support\Collection;
use Tobuli\Entities\SecurityDailyStat;

/**
 * Hoja "Datos": una fila por SecurityDailyStat (unidad, fecha y turno) con
 * las columnas A..L del Excel del cliente, M "Incluido por filtros" = 1 y
 * Q "Velocidad máxima GPS (km/h)". Cabecera en la fila 4, datos desde la 5.
 */
class DataSheet extends BaseSheet
{
    const SHIFTS = [
        SecurityDailyStat::SHIFT_DAY   => 'Día',
        SecurityDailyStat::SHIFT_NIGHT => 'Noche',
    ];

    private Collection $stats;

    /** Usuario cuya clasificacion (tipo de vehiculo) se muestra. */
    private int $userId;

    /** @param Collection<SecurityDailyStat> $stats con `device` cargado via withClassificationFor($userId) */
    public function __construct(Collection $stats, int $userId)
    {
        $this->stats = $stats;
        $this->userId = $userId;
    }

    public function title(): string
    {
        return 'Datos';
    }

    protected function columnWidths(): array
    {
        return $this->widths('A', 'Q', 14);
    }

    protected function build(): void
    {
        $this->titleRow = $this->heading('DATOS · una fila por unidad, fecha y turno');
        $this->blank(2);
        $this->row([
            'A' => 'Fecha', 'B' => 'Unidad', 'C' => 'Tipo', 'D' => 'Turno', 'E' => 'Distancia km', 'F' => 'Motor h',
            'G' => 'Movimiento h', 'H' => 'Eventos sobrevelocidad', 'I' => 'Alertas cinturón', 'J' => 'Salidas geocerca',
            'K' => 'Paradas', 'L' => 'Desconexiones', 'M' => 'Incluido por filtros', 'Q' => 'Velocidad máxima GPS (km/h)',
        ], true);

        foreach ($this->stats as $s) {
            $this->row([
                'A' => $s->work_date->toDateString(),
                'B' => optional($s->device)->name,
                'C' => $s->device ? optional($s->device->vehicleTypeFor($this->userId))->name : null,
                'D' => self::SHIFTS[$s->shift] ?? $s->shift,
                'E' => round($s->distance_km, 1),
                'F' => round($s->engine_seconds / 3600, 2),
                'G' => round($s->moving_seconds / 3600, 2),
                'H' => $s->overspeed_total,
                'I' => $s->seatbelt_alerts,
                'J' => $s->zone_exits,
                'K' => $s->stop_count,
                'L' => $s->disconnect_events + $s->signal_gaps,
                'M' => 1,
                'Q' => $s->speed_max,
            ]);
        }
    }
}
