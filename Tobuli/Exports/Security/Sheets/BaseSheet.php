<?php

namespace Tobuli\Exports\Security\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Base comun de las hojas del libro de Seguridad: construye la matriz de
 * celdas con un cursor de filas y recuerda que filas van en negrita
 * (titulos, etiquetas y cabeceras) para aplicarlas en AfterSheet.
 *
 * WithStrictNullComparison: sin ella maatwebsite descarta los ceros
 * (`0 != null`). Las filas en blanco se representan como `[null]`, porque
 * una fila `[]` desaparece al aplanar las filas y descuadraria el layout.
 */
abstract class BaseSheet implements FromArray, WithTitle, ShouldAutoSize, WithEvents, WithStrictNullComparison
{
    /** @var array[] filas ya construidas (indice 0 = fila 1 de Excel) */
    protected array $rows = [];

    /** @var array<int, string[]> numero de fila => [primera, ultima] columna en negrita */
    protected array $bold = [];

    /** @var int|null fila del titulo principal (fuente mayor) */
    protected ?int $titleRow = null;

    abstract protected function build(): void;

    public function array(): array
    {
        if (!$this->rows) {
            $this->build();
        }

        return $this->rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                foreach ($this->bold as $row => [$from, $to]) {
                    $sheet->getStyle("{$from}{$row}:{$to}{$row}")->getFont()->setBold(true);
                }

                if ($this->titleRow) {
                    $sheet->getStyle("A{$this->titleRow}")->getFont()->setBold(true)->setSize(14);
                }
            },
        ];
    }

    /** Numero (1-based) de la proxima fila que se escribira. */
    protected function nextRow(): int
    {
        return count($this->rows) + 1;
    }

    /**
     * Escribe una fila. Las celdas se dan por letra de columna
     * (['A' => 'Fecha', 'D' => 'Turno']) o como lista posicional.
     * Devuelve el numero de fila escrito.
     */
    protected function row(array $cells = [], bool $bold = false): int
    {
        $row = [];

        if ($cells && array_keys($cells) !== range(0, count($cells) - 1)) {
            foreach ($cells as $col => $value) {
                $row[$this->columnIndex($col)] = $value;
            }
            $row = $this->fillGaps($row);
        } else {
            $row = $cells;
        }

        $this->rows[] = $row;
        $number = count($this->rows);

        if ($bold && $row) {
            $this->bold[$number] = ['A', $this->columnLetter(count($row) - 1)];
        }

        return $number;
    }

    protected function blank(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->rows[] = [null];
        }
    }

    /** Fila titulo de seccion/hoja (negrita). */
    protected function heading(string $text): int
    {
        return $this->row(['A' => $text], true);
    }

    /** Tarjetas: fila de etiquetas y fila de valores, en columnas A, D, G, J (como el Excel). */
    protected function cards(array $cards): void
    {
        $columns = ['A', 'D', 'G', 'J'];
        $labels = [];
        $values = [];

        foreach (array_values($cards) as $i => [$label, $value]) {
            $labels[$columns[$i]] = $label;
            $values[$columns[$i]] = $value;
        }

        $this->row($labels, true);
        $this->row($values);
    }

    /** Filas 4/5 del Excel: etiquetas de filtro en A, D, G, I, L, N, P y valores en la columna siguiente. */
    protected function filters(array $filters): void
    {
        $columns = ['A', 'D', 'G', 'I', 'L', 'N', 'P'];
        $labels = [];
        $values = [];

        $i = 0;
        foreach ($filters as $label => $value) {
            $labels[$columns[$i]] = $label;
            $values[$this->columnLetter($this->columnIndex($columns[$i]) + 1)] = $value;
            $i++;
        }

        $this->row($labels, true);
        $this->row($values);
    }

    private function fillGaps(array $row): array
    {
        $max = max(array_keys($row));
        $filled = [];
        for ($i = 0; $i <= $max; $i++) {
            $filled[$i] = $row[$i] ?? null;
        }

        return $filled;
    }

    /** 'A' => 0, 'Q' => 16 */
    protected function columnIndex(string $letter): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letter)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /** 0 => 'A', 16 => 'Q' */
    protected function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }
}
