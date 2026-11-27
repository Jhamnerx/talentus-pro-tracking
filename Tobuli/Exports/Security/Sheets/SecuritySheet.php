<?php

namespace Tobuli\Exports\Security\Sheets;

use Maatwebsite\Excel\Concerns\WithCharts;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Services\Security\SecurityLiveStatus;

/**
 * Hoja "Seguridad": filtros, ocho tarjetas, estado vigente por unidad,
 * resumen diario, detalle diario por unidad y cuatro graficos nativos
 * (columna L en adelante) que apuntan a los rangos escritos en esta hoja.
 */
class SecuritySheet extends BaseSheet implements WithCharts
{
    const TITLE = 'Seguridad';

    const LIVE_LABELS = [
        SecurityLiveStatus::STATE_EXPIRED => 'Sin lectura vigente',
        SecurityLiveStatus::STATE_MOVING  => 'En movimiento',
        SecurityLiveStatus::STATE_STANDBY => 'Stand by',
        SecurityLiveStatus::STATE_STOPPED => 'Detenido / motor apagado',
    ];

    const DAILY_LABELS = [
        SecurityDailyStat::STATE_NO_DATA  => 'Sin registro',
        SecurityDailyStat::STATE_MOVING   => 'Movimiento',
        SecurityDailyStat::STATE_STANDBY  => 'Stand by*',
        SecurityDailyStat::STATE_INACTIVE => 'Sin actividad',
    ];

    /** Los cuatro graficos ocupan L15:T81; las tablas auxiliares van debajo. */
    const CHART_TABLES_ROW = 84;

    private array $filters;
    private array $cards;
    private array $live;
    private array $daily;
    private array $perUnitDaily;
    private array $perUnit;

    /** Filas [primera, ultima] de cada tabla referenciada por los graficos. */
    private array $ranges = [];

    public function __construct(array $filters, array $cards, array $live, array $daily, array $perUnitDaily, array $perUnit)
    {
        $this->filters = $filters;
        $this->cards = $cards;
        $this->live = $live;
        $this->daily = $daily;
        $this->perUnitDaily = $perUnitDaily;
        $this->perUnit = $perUnit;
    }

    public function title(): string
    {
        return self::TITLE;
    }

    protected function columnWidths(): array
    {
        return $this->widths('B', 'T', 12, ['A' => 14]);
    }

    protected function build(): void
    {
        $this->blank();
        $this->titleRow = $this->heading('SEGURIDAD · CONTROL DE FLOTA');
        $this->note('Generado el ' . \Formatter::time()->human(date('Y-m-d H:i:s')));
        $this->filters($this->filters);
        $this->blank(2);

        $live = collect($this->live);
        $valid = $live->where('state', '!=', SecurityLiveStatus::STATE_EXPIRED);
        $lastTime = $live->whereNotNull('last_time')->max('last_time');

        $this->cards([
            ['Eventos de sobrevelocidad', $this->cards['overspeed']],
            ['Alertas de cinturón', $this->cards['seatbelt']],
            ['Salidas de geocerca', $this->cards['zone_exits']],
            ['Unidades con lectura vigente', $valid->count()],
        ]);
        $this->blank();
        $this->cards([
            ['Unidades en movimiento', $valid->where('state', SecurityLiveStatus::STATE_MOVING)->count()],
            ['Unidades stand by', $valid->where('state', SecurityLiveStatus::STATE_STANDBY)->count()],
            ['Sin lectura vigente', $live->count() - $valid->count()],
            ['Última lectura de la flota', $lastTime ? \Formatter::time()->human($lastTime->format('Y-m-d H:i:s')) : 'Sin telemetría'],
        ]);
        $this->blank(2);

        $this->heading('ESTADO ACTUAL · ÚLTIMA LECTURA VIGENTE POR UNIDAD');
        $this->blank();
        $this->row(['Unidad', 'Tipo', 'Última lectura', 'Vel. GPS km/h', 'Motor', 'Edad min', 'Estado'], true);
        $this->table($this->live, fn ($r) => [
            $r['unit'],
            $r['type'],
            $r['last_time_formatted'],
            $r['speed'] === null ? null : round($r['speed'], 1),
            $r['engine'] === null ? null : ($r['engine'] ? 1 : 0),
            $r['age_minutes'],
            self::LIVE_LABELS[$r['state']] ?? $r['state'],
        ]);
        $this->blank(2);

        $this->heading('RESUMEN DIARIO · FLOTA FILTRADA');
        $this->blank();
        $this->ranges['daily_header'] = $this->row(['Fecha', 'km', 'Movimiento h', 'Media km/h', 'Máxima GPS km/h', 'Unidades con movimiento', 'Unidades stand by*', 'Eventos de sobrevelocidad'], true);
        $this->ranges['daily'] = $this->table($this->daily, fn ($d) => [
            $d['date'], $d['km'], $d['moving_h'], $d['avg_kmh'], $d['max_kmh'], $d['moving'], $d['standby'], $d['overspeed'],
        ]);
        $this->note('*Stand by diario: hubo horas de motor pero no de movimiento en la fecha.');
        $this->blank();

        $this->heading('DETALLE DIARIO POR UNIDAD');
        $this->blank();
        $this->row(['Fecha', 'Unidad', 'Tipo', 'km', 'Movimiento h', 'Media km/h', 'Máxima GPS km/h', 'Estado diario', 'Motor h', 'Registros'], true);
        $this->table($this->perUnitDaily, fn ($d) => [
            $d['date'], $d['unit'], $d['type'], $d['km'], $d['moving_h'], $d['avg_kmh'], $d['max_kmh'],
            self::DAILY_LABELS[$d['state']] ?? $d['state'], $d['engine_h'], $d['records'],
        ]);

        $this->chartTables($live);
    }

    /**
     * Tablas auxiliares de los graficos 2 y 4 (velocidad media por unidad y
     * estado vigente), en columnas L:M debajo de los graficos, como en el
     * Excel de referencia.
     */
    private function chartTables($live): void
    {
        while ($this->nextRow() < self::CHART_TABLES_ROW) {
            $this->blank();
        }
        $this->blank();

        $this->row(['L' => 'Unidad', 'M' => 'Media km/h'], true);
        $this->ranges['avg'] = $this->table($this->perUnit, fn ($u) => ['L' => $u['unit'], 'M' => $u['avg_kmh']]);
        $this->blank();

        $this->row(['L' => 'Estado vigente', 'M' => 'Unidades'], true);
        $states = [];
        foreach (self::LIVE_LABELS as $state => $label) {
            $states[] = ['L' => $label, 'M' => $live->where('state', $state)->count()];
        }
        $this->ranges['live'] = $this->table($states, fn ($s) => $s);
    }

    /** Escribe las filas de una tabla y devuelve [primera, ultima] (o null si esta vacia). */
    private function table(array $items, callable $mapper): ?array
    {
        if (!$items) {
            return null;
        }

        $first = $this->nextRow();
        $last = $first;
        foreach ($items as $item) {
            $last = $this->row($mapper($item));
        }

        return [$first, $last];
    }

    public function charts(): array
    {
        $this->array();

        // PhpSpreadsheet no admite series vacias: sin resumen diario no hay graficos.
        if (empty($this->ranges['daily'])) {
            return [];
        }

        [$d1, $d2] = $this->ranges['daily'];
        $header = $this->ranges['daily_header'];
        $dates = $this->ref('A', $d1, $d2);
        $days = $d2 - $d1 + 1;

        $charts = [
            $this->chart('estado_diario', 'Unidades por estado diario', DataSeries::TYPE_LINECHART, $dates, [
                [$this->ref('F', $header), $this->ref('F', $d1, $d2)],
                [$this->ref('G', $header), $this->ref('G', $d1, $d2)],
            ], $days, 'L15', 'T30'),
        ];

        if (!empty($this->ranges['avg'])) {
            [$a1, $a2] = $this->ranges['avg'];
            $charts[] = $this->chart('media_unidad', 'Velocidad media por unidad · período filtrado', DataSeries::TYPE_BARCHART, $this->ref('L', $a1, $a2), [
                [$this->ref('M', $a1 - 1), $this->ref('M', $a1, $a2)],
            ], $a2 - $a1 + 1, 'L32', 'T47');
        }

        $charts[] = $this->chart('sobrevelocidad_dia', 'Eventos de sobrevelocidad por día', DataSeries::TYPE_LINECHART, $dates, [
            [$this->ref('H', $header), $this->ref('H', $d1, $d2)],
        ], $days, 'L49', 'T64');

        if (!empty($this->ranges['live'])) {
            [$l1, $l2] = $this->ranges['live'];
            $charts[] = $this->chart('estado_vigente', 'Estado de unidades · lectura vigente', DataSeries::TYPE_BARCHART, $this->ref('L', $l1, $l2), [
                [$this->ref('M', $l1 - 1), $this->ref('M', $l1, $l2)],
            ], $l2 - $l1 + 1, 'L66', 'T81');
        }

        return $charts;
    }

    /**
     * @param string $categories referencia al eje X
     * @param array  $series     lista de [referencia a la etiqueta, referencia a los valores]
     */
    private function chart(string $name, string $title, string $type, string $categories, array $series, int $points, string $topLeft, string $bottomRight): Chart
    {
        $labels = [];
        $cats = [];
        $values = [];

        foreach ($series as [$labelRef, $valuesRef]) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $labelRef, null, 1);
            $cats[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categories, null, $points);
            $values[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $points);
        }

        $bar = $type === DataSeries::TYPE_BARCHART;

        $dataSeries = new DataSeries(
            $type,
            $bar ? DataSeries::GROUPING_CLUSTERED : DataSeries::GROUPING_STANDARD,
            range(0, count($values) - 1),
            $labels,
            $cats,
            $values
        );

        if ($bar) {
            $dataSeries->setPlotDirection(DataSeries::DIRECTION_COL);
        }

        $chart = new Chart(
            $name,
            new Title($title),
            new Legend(Legend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [$dataSeries]),
            true,
            DataSeries::EMPTY_AS_GAP
        );
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        return $chart;
    }

    /** Referencia absoluta a esta hoja: 'Seguridad'!$A$33:$A$48 */
    private function ref(string $column, int $from, ?int $to = null): string
    {
        $range = '$' . $column . '$' . $from;
        if ($to !== null) {
            $range .= ':$' . $column . '$' . $to;
        }

        return "'" . self::TITLE . "'!" . $range;
    }
}
