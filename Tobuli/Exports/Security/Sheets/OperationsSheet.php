<?php

namespace Tobuli\Exports\Security\Sheets;

/**
 * Hoja "Operaciones": filtros, cuatro totales del periodo y resultado por unidad.
 */
class OperationsSheet extends BaseSheet
{
    private array $filters;
    private array $cards;
    private array $perUnit;

    public function __construct(array $filters, array $cards, array $perUnit)
    {
        $this->filters = $filters;
        $this->cards = $cards;
        $this->perUnit = $perUnit;
    }

    public function title(): string
    {
        return 'Operaciones';
    }

    protected function columnWidths(): array
    {
        return $this->widths('A', 'G', 14);
    }

    protected function build(): void
    {
        $this->blank();
        $this->titleRow = $this->heading('OPERACIONES · CONTROL DE FLOTA');
        $this->note('Filtros compartidos con Seguridad');
        $this->filters(array_slice($this->filters, 0, 5));
        $this->blank(2);

        $this->cards([
            ['Distancia recorrida (km)', $this->cards['distance_km']],
            ['Horas de motor', $this->cards['engine_h']],
            ['Horas en movimiento', $this->cards['moving_h']],
            ['Paradas registradas', $this->cards['stops']],
        ]);
        $this->blank(2);

        $this->heading('RESULTADO POR UNIDAD');
        $this->blank();
        $this->row(['Unidad', 'Tipo', 'km', 'Motor h', 'Movimiento h', 'Paradas', 'Registros'], true);
        foreach ($this->perUnit as $u) {
            $this->row([$u['unit'], $u['type'], $u['km'], $u['engine_h'], $u['moving_h'], $u['stops'], $u['records']]);
        }
    }
}
