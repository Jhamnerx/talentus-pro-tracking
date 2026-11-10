<?php

namespace Tobuli\Exports\Operations;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tobuli\Services\Operations\OperationShiftReport;

/**
 * Reporte de turno (fase D · exportacion): libro Excel con el formato del
 * modelo del cliente (TALENTUS (1).xlsx) y graficos NATIVOS de Excel iguales
 * a los de la pestaña «Turno».
 *
 * Recibe el payload de OperationShiftReport::build() tal cual (misma fuente
 * de datos y mismas series que la pantalla) y arma dos hojas:
 *
 * - «Reporte»: B2 TURNO / C2 turno elegido; tabla horometro en B4:H (VAR como
 *   formula =F-C, horas ineficientes en horas con 2 decimales); tabla
 *   kilometraje en J4:M (VAR =L-K); abastecimiento en B12:E (horas de Excel,
 *   VAR =MOD(D-C,1)); horas del turno en J12:O (VAR =MOD(N-K,1)-(M-L));
 *   bloque DISTANCIA (Dia / Noche / Promedio) en gris bajo las tablas; y los
 *   seis graficos (estados por equipo a la derecha, los cinco por hora
 *   debajo) en las mismas zonas que las imagenes del modelo.
 * - «Datos»: una tabla por grafica (hora + series, 24 filas en el orden del
 *   dia operativo) con sus tarjetas al lado, y la tabla de estados por equipo
 *   en horas. Los graficos leen de aqui, asi el cliente puede ajustarlos.
 *
 * Colores por serie: PhpSpreadsheet 1.30 escribe el color de linea de una
 * serie de lineas y el relleno de una de barras con
 * DataSeriesValues::setFillColor('RRGGBB'); el del marcador con
 * getMarkerFillColor()->setColorProperties(). Se usan los mismos hex que
 * tp-operations-shift.js (COLORS) y OperationShiftReport::legend().
 *
 * Las tablas inferiores bajan si hay mas de 6 filas de horometro (con
 * «Todos» hay una fila por vehiculo y turno); con 6 o menos quedan en las
 * celdas del modelo (fila 12).
 */
class ShiftReportExport
{
    const SHEET_REPORT = 'Reporte';
    const SHEET_DATA = 'Datos';

    const NAVY = '002060';
    const RED = 'C00000';
    const GRAY = 'D9D9D9';

    /** Colores de serie: los mismos de tp-operations-shift.js (COLORS). */
    const COLOR_ROLLING = '16A34A';
    const COLOR_EMPTY = '1E3A8A';
    const COLOR_LOADED = 'F97316';
    const COLOR_FLEET_MAX = 'DC2626';
    const COLOR_FLEET_AVG = '2563EB';
    const COLOR_FLEET_MIN = '16A34A';

    /** Filas que ocupa cada bloque de la hoja Datos (titulo + cabecera + 24 + aire). */
    const DATA_BLOCK_ROWS = 29;

    /** Filas de alto de cada grafico por hora en la hoja Reporte. */
    const CHART_ROWS = 16;

    protected array $report;

    protected Spreadsheet $book;

    protected Worksheet $sheet;

    protected Worksheet $data;

    /** @var array<string, array> rangos de la hoja Datos por grafica: key => [cats, series => [label, values]] */
    protected array $ranges = [];

    public function __construct(array $report)
    {
        $this->report = $report;
    }

    // ── API publica ───────────────────────────────────────────────────────

    /** Genera el libro en un temporal y lo devuelve como descarga. */
    public function download(string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'turno_');

        $this->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function save(string $path): void
    {
        $writer = IOFactory::createWriter($this->build(), 'Xlsx');
        $writer->setIncludeCharts(true);
        $writer->save($path);
    }

    public function build(): Spreadsheet
    {
        $this->book = new Spreadsheet();
        $this->book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $this->sheet = $this->book->getActiveSheet();
        $this->sheet->setTitle(self::SHEET_REPORT);

        $this->data = $this->book->createSheet();
        $this->data->setTitle(self::SHEET_DATA);

        $this->dataSheet();
        $chartsTop = $this->reportSheet();
        $this->charts($chartsTop);

        $this->book->setActiveSheetIndex(0);

        return $this->book;
    }

    // ── Hoja Reporte ──────────────────────────────────────────────────────

    /** @return int primera fila libre para los graficos por hora */
    protected function reportSheet(): int
    {
        $ws = $this->sheet;
        $tables = $this->report['tables'] ?? [];
        $all = ($this->report['shift'] ?? 'all') === 'all';

        foreach (['B' => 16, 'C' => 14, 'D' => 14, 'E' => 14, 'F' => 14, 'G' => 12, 'H' => 16, 'I' => 3, 'J' => 16, 'K' => 14, 'L' => 14, 'M' => 14, 'N' => 14, 'O' => 12] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        $ws->getColumnDimension('A')->setWidth(3);

        // Selector de turno (B2:C2 del modelo, en rojo).
        $this->text('B2', 'TURNO');
        $this->text('C2', $this->shiftLabel());
        $ws->mergeCells('C2:D2');
        $this->style('B2:D2', self::RED, true, false);

        // Tabla horometro (B4:H) y kilometraje (J4:M).
        $this->headers(4, 'B', [
            'PLACA / CODIGO', 'HOROMETRO INICIAL', 'HOROMETRO ANTES DEL DESCANSO', 'HOROMETRO DESPUES DEL DESCANSO',
            'HOROMETRO FINAL', 'VAR', 'HORAS INEFICIENTES (RALENTI)',
        ]);
        $this->style('H4', self::RED, true, true);
        $this->headers(4, 'J', ['PLACA / CODIGO', 'KILOMETRO INICIAL', 'KILOMETRO FINAL', 'VAR']);
        $ws->getRowDimension(4)->setRowHeight(47.4);

        $hoursMeter = $tables['hours_meter'] ?? [];
        $odometer = $tables['odometer'] ?? [];
        $row = 5;

        foreach ($hoursMeter as $i => $r) {
            $name = $this->rowName($r, $all);
            $this->text("B{$row}", $name);
            $this->number("C{$row}", $r['engine_start']);
            $this->number("D{$row}", $r['engine_before']);
            $this->number("E{$row}", $r['engine_after']);
            $this->number("F{$row}", $r['engine_end']);
            if (! is_null($r['engine_start']) && ! is_null($r['engine_end'])) {
                $ws->setCellValue("G{$row}", "=F{$row}-C{$row}");
            }
            $this->number("H{$row}", is_null($r['idle_seconds']) ? null : round($r['idle_seconds'] / 3600, 2));
            $ws->getStyle("C{$row}:H{$row}")->getNumberFormat()->setFormatCode('0.00');

            $o = $odometer[$i] ?? null;
            $this->text("J{$row}", $o ? $this->rowName($o, $all) : $name);
            $this->number("K{$row}", $o['start'] ?? null);
            $this->number("L{$row}", $o['end'] ?? null);
            if ($o && ! is_null($o['start']) && ! is_null($o['end'])) {
                $ws->setCellValue("M{$row}", "=L{$row}-K{$row}");
            }
            $ws->getStyle("K{$row}:M{$row}")->getNumberFormat()->setFormatCode('0.00');

            $row++;
        }

        if (! $hoursMeter) {
            $this->text('B5', trans('ui.ops_shift_no_rows'));
            $this->text('J5', trans('ui.ops_shift_no_rows'));
            $row = 6;
        }

        $this->borders("B5:H" . ($row - 1));
        $this->borders("J5:M" . ($row - 1));

        // Tablas inferiores: en la fila 12 del modelo mientras quepan (hasta
        // 6 filas arriba, con una fila en blanco de separacion).
        $lower = max(12, $row + 1);

        // Abastecimiento de combustible (B:E).
        $this->text("B{$lower}", 'ABASTECIMIENTO DE COMBUSTIBLE');
        $ws->mergeCells("B{$lower}:E{$lower}");
        $this->style("B{$lower}:E{$lower}", self::NAVY, true, true);
        $this->headers($lower + 1, 'B', ['PLACA / CODIGO', 'HORA INICIAL', 'HORA FINAL', 'VAR']);
        $ws->getRowDimension($lower)->setRowHeight(57.6);

        $refuelRow = $lower + 2;
        $refuelings = $tables['refuelings'] ?? [];

        foreach ($refuelings as $r) {
            $this->text("B{$refuelRow}", $this->rowName($r, $all));
            $this->clock("C{$refuelRow}", $r['started']);
            $this->clock("D{$refuelRow}", $r['ended']);
            if ($r['started'] && $r['ended']) {
                $ws->setCellValue("E{$refuelRow}", "=MOD(D{$refuelRow}-C{$refuelRow},1)");
                $ws->getStyle("E{$refuelRow}")->getNumberFormat()->setFormatCode('h:mm');
            }
            $refuelRow++;
        }

        if (! $refuelings) {
            $this->text("B{$refuelRow}", trans('ui.ops_shift_export_no_refuelings'));
            $ws->mergeCells("B{$refuelRow}:E{$refuelRow}");
            $refuelRow++;
        }

        $this->borders("B" . ($lower + 2) . ":E" . ($refuelRow - 1));

        // Horas del turno (J:O).
        $this->headers($lower, 'J', [
            'PLACA / CODIGO', 'HORA INICIAL', 'HORA ANTES DEL DESCANSO', 'HORA DESPUES DEL DESCANSO', 'HORA FINAL', 'VAR',
        ]);

        $hoursRow = $lower + 1;

        foreach ($hoursMeter as $r) {
            $this->text("J{$hoursRow}", $this->rowName($r, $all));
            $this->clock("K{$hoursRow}", $r['first_move']);
            $this->clock("L{$hoursRow}", $r['break_start']);
            $this->clock("M{$hoursRow}", $r['break_end']);
            $this->clock("N{$hoursRow}", $r['last_move']);
            if ($r['first_move'] && $r['last_move']) {
                $ws->setCellValue("O{$hoursRow}", "=MOD(N{$hoursRow}-K{$hoursRow},1)-(M{$hoursRow}-L{$hoursRow})");
                $ws->getStyle("O{$hoursRow}")->getNumberFormat()->setFormatCode('[h]:mm');
            }
            $hoursRow++;
        }

        if (! $hoursMeter) {
            $this->text("J{$hoursRow}", trans('ui.ops_shift_no_rows'));
            $hoursRow++;
        }

        $this->borders("J" . ($lower + 1) . ":O" . ($hoursRow - 1));

        // Bloque DISTANCIA (tarjetas en gris) bajo las tablas.
        $dist = max($refuelRow, $hoursRow) + 2;
        $cards = $this->report['cards']['distance'] ?? [];
        $unit = $this->report['units']['distance'] ?? '';

        $this->text("B{$dist}", 'DISTANCIA' . ($unit ? " ({$unit})" : ''));
        $ws->mergeCells("B{$dist}:D{$dist}");
        $this->style("B{$dist}:D{$dist}", self::RED, true, true);
        $this->cardCells($ws, 'B', $dist + 1, [
            [trans('ui.ops_shift_day'), $cards['day'] ?? null],
            [trans('ui.ops_shift_night'), $cards['night'] ?? null],
            [trans('ui.ops_shift_avg'), $cards['avg'] ?? null],
        ], '0.0');

        // Pie: operacion, dia operativo y generado.
        $meta = $dist + 4;
        $this->text("B{$meta}", sprintf('%s · %s · %s %s',
            $this->report['operation']['name'] ?? '',
            $this->report['date'] ?? '',
            trans('ui.ops_shift_generated'),
            $this->report['generated_at'] ?? ''
        ));
        $ws->getStyle("B{$meta}")->getFont()->setItalic(true)->setSize(9);

        return $meta + 2;
    }

    // ── Hoja Datos ────────────────────────────────────────────────────────

    protected function dataSheet(): void
    {
        $ws = $this->data;
        $hours = $this->report['hours'] ?? [];
        $cards = $this->report['cards'] ?? [];
        $units = $this->report['units'] ?? [];
        $speed = $units['speed'] ?? '';
        $hasNight = count($this->report['shifts'] ?? []) > 1;

        $ws->getColumnDimension('A')->setWidth(10);
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $c) {
            $ws->getColumnDimension($c)->setWidth(16);
        }

        $labels = array_map(fn ($h) => $h['label'], $hours);
        $shifts = array_map(fn ($h) => $h['shift'], $hours);
        $pick = fn (string $k) => array_map(fn ($h) => $h[$k], $hours);
        $fleet = fn (string $k, ?string $f = null) => array_map(function ($h) use ($k, $f) {
            if (! $h['fleet'] || is_null($h['fleet'][$k])) {
                return null;
            }

            return $f ? $h['fleet'][$k][$f] : $h['fleet'][$k];
        }, $hours);

        $third = fn (array $m, string $key) => [trans($key), $m[array_key_exists('total', $m) ? 'total' : 'avg'] ?? null];
        $cardSet = function (array $m, string $thirdKey) use ($hasNight, $third) {
            $set = [[trans('ui.ops_shift_day'), $m['day'] ?? null]];
            if ($hasNight) {
                $set[] = [trans('ui.ops_shift_night'), $m['night'] ?? null];
            }
            $set[] = $third($m, $thirdKey);

            return $set;
        };

        $blocks = [
            'rolling' => [
                'title'  => trans('ui.ops_shift_chart_rolling'),
                'series' => [[trans('ui.ops_shift_rolling') . ' (' . trans('ui.ops_shift_units') . ')', $pick('rolling'), '0']],
                'cards'  => $cardSet($cards['rolling'] ?? [], 'ui.ops_shift_total'),
                'format' => '0.0',
            ],
            'empty' => [
                'title'  => trans('ui.ops_shift_chart_empty'),
                'series' => [[trans('ui.ops_shift_series_empty') . " ({$speed})", $pick('speed_empty'), '0.0']],
                'cards'  => $cardSet($cards['speed_empty'] ?? [], 'ui.ops_shift_avg'),
                'format' => '0.0',
            ],
            'loaded' => [
                'title'  => trans('ui.ops_shift_chart_loaded'),
                'series' => [[trans('ui.ops_shift_series_loaded') . " ({$speed})", $pick('speed_loaded'), '0.0']],
                'cards'  => $cardSet($cards['speed_loaded'] ?? [], 'ui.ops_shift_avg'),
                'format' => '0.0',
            ],
            'both' => [
                'title'  => trans('ui.ops_shift_chart_both'),
                'series' => [
                    [trans('ui.ops_shift_series_loaded') . " ({$speed})", $pick('speed_loaded'), '0.0'],
                    [trans('ui.ops_shift_series_empty') . " ({$speed})", $pick('speed_empty'), '0.0'],
                ],
                'cards'  => array_merge(
                    [[trans('ui.ops_shift_series_loaded') . ' · ' . trans('ui.ops_shift_day'), $cards['speed_loaded']['day'] ?? null]],
                    $hasNight ? [[trans('ui.ops_shift_series_loaded') . ' · ' . trans('ui.ops_shift_night'), $cards['speed_loaded']['night'] ?? null]] : [],
                    [[trans('ui.ops_shift_series_loaded') . ' · ' . trans('ui.ops_shift_avg'), $cards['speed_loaded']['avg'] ?? null]],
                    [[trans('ui.ops_shift_series_empty') . ' · ' . trans('ui.ops_shift_day'), $cards['speed_empty']['day'] ?? null]],
                    $hasNight ? [[trans('ui.ops_shift_series_empty') . ' · ' . trans('ui.ops_shift_night'), $cards['speed_empty']['night'] ?? null]] : [],
                    [[trans('ui.ops_shift_series_empty') . ' · ' . trans('ui.ops_shift_avg'), $cards['speed_empty']['avg'] ?? null]]
                ),
                'format' => '0.0',
            ],
            'fleet' => [
                'title'  => trans('ui.ops_shift_chart_fleet'),
                'series' => [
                    [trans('ui.ops_shift_max') . " ({$speed})", $fleet('max', 'speed'), '0'],
                    [trans('ui.ops_shift_mean') . " ({$speed})", $fleet('avg'), '0'],
                    [trans('ui.ops_shift_min') . " ({$speed})", $fleet('min', 'speed'), '0'],
                ],
                'extra' => [
                    [trans('ui.ops_shift_max') . ' · ' . trans('ui.ops_shift_export_unit'), $fleet('max', 'device_name')],
                    [trans('ui.ops_shift_max') . ' · ' . trans('ui.ops_shift_hour'), array_map(fn ($v) => $v ? substr($v, 11, 8) : null, $fleet('max', 'at'))],
                    [trans('ui.ops_shift_min') . ' · ' . trans('ui.ops_shift_export_unit'), $fleet('min', 'device_name')],
                    [trans('ui.ops_shift_min') . ' · ' . trans('ui.ops_shift_hour'), array_map(fn ($v) => $v ? substr($v, 11, 8) : null, $fleet('min', 'at'))],
                ],
                'cards'  => [],
                'format' => '0',
            ],
        ];

        $base = 1;

        foreach ($blocks as $key => $block) {
            $title = $base;
            $head = $base + 1;
            $first = $base + 2;
            $last = $first + max(count($labels), 1) - 1;

            $this->cell($ws, "A{$title}", $block['title']);
            $ws->getStyle("A{$title}")->getFont()->setBold(true)->setSize(12);

            $this->cell($ws, "A{$head}", trans('ui.ops_shift_hour'));
            $col = 2;
            $ranges = ['cats' => $this->ref('A', $first, $last), 'series' => []];

            foreach ($block['series'] as [$label, $values, $format]) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $this->cell($ws, "{$letter}{$head}", $label);
                foreach ($values as $i => $v) {
                    $this->cell($ws, $letter . ($first + $i), $v);
                }
                $ws->getStyle("{$letter}{$first}:{$letter}{$last}")->getNumberFormat()->setFormatCode($format === '0' ? '0' : '0.0');
                $ranges['series'][] = [
                    'label'  => $this->ref($letter, $head, $head),
                    'values' => $this->ref($letter, $first, $last),
                    'data'   => $values,
                ];
                $col++;
            }

            // Columna «Turno» (rotulo de la hora) y, en la flota, unidad y hora exacta.
            $extra = array_merge([[trans('ui.ops_shift'), $shifts]], $block['extra'] ?? []);
            foreach ($extra as [$label, $values]) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $this->cell($ws, "{$letter}{$head}", $label);
                foreach ($values as $i => $v) {
                    $this->cell($ws, $letter . ($first + $i), $v);
                }
                $col++;
            }

            foreach ($labels as $i => $label) {
                $this->cell($ws, 'A' . ($first + $i), $label, DataType::TYPE_STRING);
            }

            $lastCol = Coordinate::stringFromColumnIndex($col - 1);
            $this->style("A{$head}:{$lastCol}{$head}", self::NAVY, true, true, $ws);
            $this->borders("A{$first}:{$lastCol}{$last}", $ws);

            // Tarjetas al lado de la tabla.
            if ($block['cards']) {
                $cardCol = Coordinate::stringFromColumnIndex($col + 1);
                $this->cardCells($ws, $cardCol, $head, $block['cards'], $block['format']);
            }

            $this->ranges[$key] = $ranges;
            $base += self::DATA_BLOCK_ROWS;
        }

        // Estados por equipo (horas, 1 decimal).
        $states = $this->report['states'] ?? [];
        $legend = $states['legend'] ?? OperationShiftReport::legend();
        $rows = $states['rows'] ?? [];

        $title = $base;
        $head = $base + 1;
        $first = $base + 2;
        $last = $first + max(count($rows), 1) - 1;

        $this->cell($ws, "A{$title}", trans('ui.ops_shift_states_title'));
        $ws->getStyle("A{$title}")->getFont()->setBold(true)->setSize(12);
        $this->cell($ws, "A{$head}", trans('ui.ops_shift_export_unit'));

        $ranges = ['cats' => $this->ref('A', $first, $last), 'series' => []];

        foreach ($rows as $i => $r) {
            $this->cell($ws, 'A' . ($first + $i), $r['name'], DataType::TYPE_STRING);
        }

        $col = 2;
        foreach ($legend as $l) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $this->cell($ws, "{$letter}{$head}", $l['label']);
            $values = [];
            foreach ($rows as $i => $r) {
                $values[] = round(($r[$l['key']] ?? 0) / 3600, 1);
                $this->cell($ws, $letter . ($first + $i), end($values));
            }
            $ws->getStyle("{$letter}{$first}:{$letter}{$last}")->getNumberFormat()->setFormatCode('0.0');
            $ranges['series'][] = [
                'label'  => $this->ref($letter, $head, $head),
                'values' => $this->ref($letter, $first, $last),
                'data'   => $values,
                'color'  => ltrim($l['color'], '#'),
            ];
            $col++;
        }

        $lastCol = Coordinate::stringFromColumnIndex($col - 1);
        $this->style("A{$head}:{$lastCol}{$head}", self::NAVY, true, true, $ws);
        $this->borders("A{$first}:{$lastCol}{$last}", $ws);
        $ws->getColumnDimension('A')->setWidth(18);

        $this->ranges['states'] = $ranges + ['count' => count($rows)];
    }

    // ── Graficos ──────────────────────────────────────────────────────────

    protected function charts(int $top): void
    {
        $ws = $this->sheet;
        $n = count($this->report['hours'] ?? []);

        // 6. Estados por equipo: barra apilada horizontal a la derecha de las tablas.
        $states = $this->ranges['states'];
        $series = [];
        foreach ($states['series'] as $s) {
            $series[] = $this->values($s, max($states['count'], 1), 'bar', $s['color']);
        }
        $chart = $this->chart(
            'states',
            trans('ui.ops_shift_states_title'),
            new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_STACKED,
                range(0, count($series) - 1),
                array_map(fn ($s) => $this->label($s), $states['series']),
                array_fill(0, count($series), $this->categories($states['cats'], max($states['count'], 1), array_map(fn ($r) => $r['name'], $this->report['states']['rows'] ?? []))),
                $series,
                DataSeries::DIRECTION_BAR
            ),
            new Legend(Legend::POSITION_BOTTOM, null, false)
        );
        $chart->setTopLeftPosition('Q2');
        $chart->setBottomRightPosition('Y' . (2 + max(26, 6 + 2 * $states['count'])));
        $ws->addChart($chart);

        // 1-5. Graficas por hora, apiladas bajo las tablas (zona de las imagenes del modelo).
        $defs = [
            'rolling' => ['title' => trans('ui.ops_shift_chart_rolling'), 'colors' => [self::COLOR_ROLLING], 'legend' => false],
            'empty'   => ['title' => trans('ui.ops_shift_chart_empty'), 'colors' => [self::COLOR_EMPTY], 'legend' => false],
            'loaded'  => ['title' => trans('ui.ops_shift_chart_loaded'), 'colors' => [self::COLOR_LOADED], 'legend' => false],
            'both'    => ['title' => trans('ui.ops_shift_chart_both'), 'colors' => [self::COLOR_LOADED, self::COLOR_EMPTY], 'legend' => true],
            'fleet'   => ['title' => trans('ui.ops_shift_chart_fleet'), 'colors' => [self::COLOR_FLEET_MAX, self::COLOR_FLEET_AVG, self::COLOR_FLEET_MIN], 'legend' => true],
        ];

        $labels = array_map(fn ($h) => $h['label'], $this->report['hours'] ?? []);
        $row = $top;

        foreach ($defs as $key => $def) {
            $r = $this->ranges[$key];
            $series = [];
            foreach ($r['series'] as $i => $s) {
                $series[] = $this->values($s, max($n, 1), 'line', $def['colors'][$i]);
            }

            $chart = $this->chart(
                $key,
                $def['title'],
                new DataSeries(
                    DataSeries::TYPE_LINECHART,
                    DataSeries::GROUPING_STANDARD,
                    range(0, count($series) - 1),
                    array_map(fn ($s) => $this->label($s), $r['series']),
                    array_fill(0, count($series), $this->categories($r['cats'], max($n, 1), $labels)),
                    $series
                ),
                $def['legend'] ? new Legend(Legend::POSITION_BOTTOM, null, false) : null
            );
            $chart->setTopLeftPosition("B{$row}");
            $chart->setBottomRightPosition('O' . ($row + self::CHART_ROWS));
            $ws->addChart($chart);

            $row += self::CHART_ROWS + 2;
        }
    }

    protected function chart(string $name, string $title, DataSeries $series, ?Legend $legend): Chart
    {
        $chart = new Chart(
            'chart_' . $name,
            new Title($title),
            $legend,
            new PlotArea(null, [$series]),
            true,
            DataSeries::EMPTY_AS_GAP
        );

        return $chart;
    }

    protected function label(array $s): DataSeriesValues
    {
        return new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $s['label'], null, 1);
    }

    protected function categories(string $ref, int $count, array $values): DataSeriesValues
    {
        return new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $ref, null, $count, $values);
    }

    protected function values(array $s, int $count, string $kind, string $color): DataSeriesValues
    {
        $v = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $s['values'], null, $count, $s['data']);
        $v->setFillColor($color);
        $v->setLabelLayout((new Layout())->setShowVal(true)->setShowSerName(false)->setShowCatName(false)->setShowLegendKey(false));

        if ($kind === 'line') {
            $v->setPointMarker('circle');
            $v->setPointSize(5);
            $v->getMarkerFillColor()->setColorProperties($color);
            $v->getMarkerBorderColor()->setColorProperties($color);
            $v->setLineWidth(2.25);
            $v->setSmoothLine(false);
        }

        return $v;
    }

    // ── Utilidades de celdas y estilo ─────────────────────────────────────

    protected function ref(string $col, int $from, int $to): string
    {
        return sprintf("'%s'!\$%s\$%d:\$%s\$%d", self::SHEET_DATA, $col, $from, $col, $to);
    }

    protected function headers(int $row, string $fromCol, array $labels): void
    {
        $col = Coordinate::columnIndexFromString($fromCol);
        foreach ($labels as $label) {
            $this->text(Coordinate::stringFromColumnIndex($col) . $row, $label);
            $col++;
        }
        $last = Coordinate::stringFromColumnIndex($col - 1);
        $this->style("{$fromCol}{$row}:{$last}{$row}", self::NAVY, true, true);
    }

    /** Texto plano (nunca formula) en la hoja Reporte. */
    protected function text(string $cell, $value): void
    {
        $this->cell($this->sheet, $cell, $value, DataType::TYPE_STRING);
    }

    protected function number(string $cell, $value): void
    {
        if (! is_null($value)) {
            $this->sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
        }
    }

    /** "H:i" -> fraccion de dia (hora de Excel) con formato h:mm. */
    protected function clock(string $cell, ?string $hhmm): void
    {
        if (! $hhmm || ! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return;
        }

        $this->sheet->setCellValueExplicit($cell, ((int) $m[1] * 60 + (int) $m[2]) / 1440, DataType::TYPE_NUMERIC);
        $this->sheet->getStyle($cell)->getNumberFormat()->setFormatCode('h:mm');
    }

    protected function cell(Worksheet $ws, string $cell, $value, ?string $type = null): void
    {
        if (is_null($value) || $value === '') {
            return;
        }

        if ($type) {
            $ws->setCellValueExplicit($cell, $type === DataType::TYPE_STRING ? (string) $value : $value, $type);
        } elseif (is_string($value)) {
            // Las cadenas siempre como texto: un nombre "=x" no debe ejecutarse.
            $ws->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        } else {
            $ws->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
        }
    }

    /** Tarjetas (etiqueta arriba en gris, valor debajo) desde una columna. */
    protected function cardCells(Worksheet $ws, string $fromCol, int $row, array $cards, string $format): void
    {
        $col = Coordinate::columnIndexFromString($fromCol);
        foreach ($cards as [$label, $value]) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $this->cell($ws, "{$letter}{$row}", $label, DataType::TYPE_STRING);
            $this->cell($ws, $letter . ($row + 1), $value);
            $ws->getStyle($letter . ($row + 1))->getNumberFormat()->setFormatCode($format);
            $ws->getStyle($letter . ($row + 1))->getFont()->setBold(true)->setSize(14);
            $ws->getStyle($letter . ($row + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $col++;
        }
        $last = Coordinate::stringFromColumnIndex($col - 1);
        $this->style("{$fromCol}{$row}:{$last}{$row}", self::GRAY, true, true, $ws, '000000');
        $this->borders("{$fromCol}{$row}:{$last}" . ($row + 1), $ws);
    }

    protected function style(string $range, string $fill, bool $bold, bool $center, ?Worksheet $ws = null, string $fontColor = 'FFFFFF'): void
    {
        $ws = $ws ?: $this->sheet;
        $style = $ws->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        $style->getFont()->setBold($bold)->getColor()->setRGB($fontColor);
        $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        if ($center) {
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    protected function borders(string $range, ?Worksheet $ws = null): void
    {
        ($ws ?: $this->sheet)->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    protected function rowName(array $row, bool $all): string
    {
        return $all && ! empty($row['shift']) ? $row['device'] . ' · ' . $row['shift'] : (string) $row['device'];
    }

    /** C2: «DÍA / NOCHE» con Todos (nombres de los turnos), o el turno elegido. */
    protected function shiftLabel(): string
    {
        $shifts = $this->report['shifts'] ?? [];
        $selected = $this->report['shift'] ?? 'all';

        if ($selected !== 'all') {
            foreach ($shifts as $s) {
                if ((int) $s['id'] === (int) $selected) {
                    return mb_strtoupper($s['name']);
                }
            }
        }

        return mb_strtoupper(implode(' / ', array_map(fn ($s) => $s['name'], $shifts)) ?: trans('ui.ops_shift_all'));
    }
}
