<?php

namespace Tobuli\Exports\Security\Sheets;

use Tobuli\Services\Security\SecurityShift;

/**
 * Hoja "Telemetría": ultima lectura por unidad (fecha y hora, velocidad,
 * motor), a partir de las filas de SecurityLiveStatus. Cabecera en la fila 4.
 */
class TelemetrySheet extends BaseSheet
{
    private array $live;
    private SecurityShift $shift;

    public function __construct(array $live, SecurityShift $shift)
    {
        $this->live = $live;
        $this->shift = $shift;
    }

    public function title(): string
    {
        return 'Telemetría';
    }

    protected function columnWidths(): array
    {
        return $this->widths('A', 'G', 16);
    }

    protected function build(): void
    {
        $this->titleRow = $this->heading('TELEMETRÍA · ÚLTIMA LECTURA POR UNIDAD');
        $this->blank(2);
        $this->row(['Fecha y hora', 'Unidad', 'Tipo', 'Turno', 'Velocidad GPS km/h', 'Motor (1/0)', 'Fuente / observación'], true);

        foreach ($this->live as $r) {
            $turno = null;
            if ($r['last_time']) {
                [, $shift] = $this->shift->shiftAt($r['last_time']);
                $turno = DataSheet::SHIFTS[$shift] ?? $shift;
            }

            $this->row([
                $r['last_time_formatted'],
                $r['unit'],
                $r['type'],
                $turno,
                $r['speed'] === null ? null : round($r['speed'], 1),
                $r['engine'] === null ? null : ($r['engine'] ? 1 : 0),
                $r['last_time'] ? 'Última posición' : 'Sin telemetría',
            ]);
        }
    }
}
