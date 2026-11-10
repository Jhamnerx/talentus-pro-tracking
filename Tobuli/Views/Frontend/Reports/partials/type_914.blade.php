@extends('Frontend.Reports.partials.layout')

{{-- Reporte de turno de Operaciones (PDF/HTML). Mismo contenido que la
     pestaña «Turno»: tarjetas, graficas por hora (SVG), estados por equipo y
     tablas. Maquetado con tablas: wkhtmltopdf no maneja flex ni grid. --}}

@section('styles')
    <style>
        .shr-day { page-break-inside: auto; }
        .shr-day + .shr-day { page-break-before: always; }
        .shr-h1 { font-size: 15px; font-weight: bold; margin: 4px 0 8px; color: #111827; }
        .shr-h1 small { font-size: 11px; font-weight: normal; color: #6b7280; }
        .shr-h2 { font-size: 12px; font-weight: bold; margin: 12px 0 5px; color: #1f2937; }
        .shr-grid { width: 100%; border-collapse: separate; border-spacing: 6px; table-layout: fixed; margin: 0 -6px; }
        .shr-grid td { vertical-align: top; }
        .shr-box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px 8px; background: #fff; page-break-inside: avoid; }
        .shr-box-title { font-size: 10px; font-weight: bold; color: #374151; margin-bottom: 3px; }
        .shr-kpis { width: 100%; border-collapse: collapse; }
        .shr-kpis td { font-size: 9px; color: #6b7280; padding: 1px 4px 0 0; }
        .shr-kpis .v { font-size: 15px; font-weight: bold; color: #111827; }
        .shr-kpis .u { font-size: 9px; font-weight: normal; color: #6b7280; }
        .shr-legend { font-size: 9px; color: #4b5563; margin: 0 0 3px; }
        .shr-legend span { display: inline-block; margin-right: 10px; white-space: nowrap; }
        .shr-legend i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 3px; vertical-align: -1px; }
        .shr-table { width: 100%; border-collapse: collapse; font-size: 9px; page-break-inside: auto; }
        .shr-table th { background: #f3f4f6; color: #374151; text-align: left; padding: 4px 5px; border-bottom: 1px solid #d1d5db; }
        .shr-table td { padding: 3px 5px; border-bottom: 1px solid #eef0f3; }
        .shr-table tr { page-break-inside: avoid; }
        .shr-table .n { text-align: right; white-space: nowrap; }
        .shr-muted { color: #9ca3af; }
        .shr-empty { padding: 12px; color: #6b7280; font-size: 11px; border: 1px dashed #d1d5db; border-radius: 6px; }
    </style>
@stop

@section('content')
    @php
        $n = fn ($v, $dec = 1) => is_null($v) ? '—' : number_format((float) $v, $dec, ',', '.');
        $hm = function ($s) {
            if (is_null($s)) return '—';
            $s = max(0, (int) round($s));
            return intdiv($s, 3600) . ':' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
        };
        $hShort = trans('ui.ops_shift_hours_short');
        $colors = ['rolling' => '#16a34a', 'empty' => '#1e3a8a', 'loaded' => '#f97316', 'max' => '#dc2626', 'avg' => '#2563eb', 'min' => '#16a34a'];
        $operation = $report->getOperation();
    @endphp

    @if (! $operation)
        <div class="shr-empty">{{ trans('ui.ops_pick_operation') }}</div>
    @endif

    @foreach ($report->getItems() as $item)
        @php
            $d = $item['data'];
            $shiftName = ($d['shift'] ?? 'all') === 'all'
                ? trans('ui.ops_shift_all')
                : (collect($d['shifts'] ?? [])->firstWhere('id', (int) $d['shift'])['name'] ?? '');
        @endphp

        <div class="shr-day">
            <div class="shr-h1">
                {{ $d['operation']['name'] ?? ($operation->name ?? '') }} · {{ $item['date_label'] }}
                <small>· {{ trans('ui.ops_shift') }}: {{ $shiftName }} · {{ trans('ui.ops_shift_generated') }} {{ $d['generated_at'] ?? '' }}</small>
            </div>

            @if (! empty($d['empty']))
                <div class="shr-empty">{{ $d['hint'] ?? trans('ui.ops_shift_empty') }}</div>
            @else
                @php
                    $c = $d['cards'] ?? [];
                    $u = $d['units'] ?? [];
                    $hasNight = count($d['shifts'] ?? []) > 1;
                    $hours = $d['hours'] ?? [];
                    $labels = array_map(fn ($h) => $h['label'], $hours);
                    $pick = fn ($k) => array_map(fn ($h) => $h[$k], $hours);
                    $fleet = fn ($k) => array_map(function ($h) use ($k) {
                        $p = $h['fleet'][$k] ?? null;
                        return is_array($p) ? $p['speed'] : $p;
                    }, $hours);

                    $cardGroups = [
                        [trans('ui.ops_shift_rolling'), $c['rolling'] ?? [], trans('ui.ops_shift_units'), 'total', trans('ui.ops_shift_total')],
                        [trans('ui.ops_shift_speed_empty'), $c['speed_empty'] ?? [], $u['speed'] ?? '', 'avg', trans('ui.ops_shift_avg')],
                        [trans('ui.ops_shift_speed_loaded'), $c['speed_loaded'] ?? [], $u['speed'] ?? '', 'avg', trans('ui.ops_shift_avg')],
                        [trans('ui.ops_shift_distance'), $c['distance'] ?? [], $u['distance'] ?? '', 'avg', trans('ui.ops_shift_avg')],
                    ];

                    $charts = [
                        'rolling' => [trans('ui.ops_shift_chart_rolling'), 0, [['name' => trans('ui.ops_shift_rolling'), 'color' => $colors['rolling'], 'values' => $pick('rolling')]]],
                        'empty'   => [trans('ui.ops_shift_chart_empty'), 1, [['name' => trans('ui.ops_shift_series_empty'), 'color' => $colors['empty'], 'values' => $pick('speed_empty')]]],
                        'loaded'  => [trans('ui.ops_shift_chart_loaded'), 1, [['name' => trans('ui.ops_shift_series_loaded'), 'color' => $colors['loaded'], 'values' => $pick('speed_loaded')]]],
                        'both'    => [trans('ui.ops_shift_chart_both'), 1, [
                            ['name' => trans('ui.ops_shift_series_loaded'), 'color' => $colors['loaded'], 'values' => $pick('speed_loaded')],
                            ['name' => trans('ui.ops_shift_series_empty'), 'color' => $colors['empty'], 'values' => $pick('speed_empty')],
                        ]],
                        'fleet'   => [trans('ui.ops_shift_chart_fleet'), 0, [
                            ['name' => trans('ui.ops_shift_max'), 'color' => $colors['max'], 'values' => $fleet('max')],
                            ['name' => trans('ui.ops_shift_mean'), 'color' => $colors['avg'], 'values' => $fleet('avg')],
                            ['name' => trans('ui.ops_shift_min'), 'color' => $colors['min'], 'values' => $fleet('min')],
                        ]],
                    ];

                    $tb = $d['tables'] ?? [];
                    $st = $d['states'] ?? [];
                @endphp

                {{-- 1. Tarjetas --}}
                <table class="shr-grid">
                    <tr>
                        @foreach ($cardGroups as [$title, $m, $unit, $thirdKey, $thirdLabel])
                            <td>
                                <div class="shr-box">
                                    <div class="shr-box-title">{{ $title }}</div>
                                    <table class="shr-kpis">
                                        <tr>
                                            <td>{{ trans('ui.ops_shift_day') }}</td>
                                            @if ($hasNight)<td>{{ trans('ui.ops_shift_night') }}</td>@endif
                                            <td>{{ $thirdLabel }}</td>
                                        </tr>
                                        <tr>
                                            <td class="v">{{ $n($m['day'] ?? null) }} <span class="u">{{ $unit }}</span></td>
                                            @if ($hasNight)<td class="v">{{ $n($m['night'] ?? null) }} <span class="u">{{ $unit }}</span></td>@endif
                                            <td class="v">{{ $n($m[$thirdKey] ?? null) }} <span class="u">{{ $unit }}</span></td>
                                        </tr>
                                    </table>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </table>

                {{-- 2. Graficas por hora --}}
                <table class="shr-grid">
                    @foreach ([['rolling', 'empty'], ['loaded', 'both']] as $pair)
                        <tr>
                            @foreach ($pair as $key)
                                @php [$title, $dec, $series] = $charts[$key]; @endphp
                                <td>
                                    <div class="shr-box">
                                        <div class="shr-box-title">{{ $title }}</div>
                                        @if (count($series) > 1)
                                            <div class="shr-legend">
                                                @foreach ($series as $s)<span><i style="background:{{ $s['color'] }}"></i>{{ $s['name'] }}</span>@endforeach
                                            </div>
                                        @endif
                                        {!! \Tobuli\Services\Operations\ShiftReportSvg::lineChart($labels, $series, $dec, 520, 180) !!}
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="2">
                            @php [$title, $dec, $series] = $charts['fleet']; @endphp
                            <div class="shr-box">
                                <div class="shr-box-title">{{ $title }} ({{ $u['speed'] ?? '' }})</div>
                                <div class="shr-legend">
                                    @foreach ($series as $s)<span><i style="background:{{ $s['color'] }}"></i>{{ $s['name'] }}</span>@endforeach
                                </div>
                                {!! \Tobuli\Services\Operations\ShiftReportSvg::lineChart($labels, $series, $dec, 1060, 210) !!}
                            </div>
                        </td>
                    </tr>
                </table>

                {{-- 3. Estados por equipo --}}
                <div class="shr-box" style="margin-top:6px;">
                    <div class="shr-box-title">{{ trans('ui.ops_shift_states_title') }}</div>
                    <div class="shr-legend">
                        @foreach ($st['legend'] ?? [] as $l)<span><i style="background:{{ $l['color'] }}"></i>{{ $l['label'] }}</span>@endforeach
                    </div>
                    @if (! empty($st['rows']))
                        {!! \Tobuli\Services\Operations\ShiftReportSvg::statesChart($st, $hShort, 1060) !!}
                    @else
                        <div class="shr-muted">{{ trans('ui.ops_no_vehicles') }}</div>
                    @endif
                </div>

                {{-- 4. Horas y horometro --}}
                <div class="shr-h2">{{ trans('ui.ops_shift_table_hours') }}</div>
                <table class="shr-table">
                    <thead>
                        <tr>
                            <th>{{ trans('ui.vehicle') }}</th>
                            <th>{{ trans('ui.ops_shift') }}</th>
                            <th>{{ trans('ui.ops_shift_col_first') }}</th>
                            <th>{{ trans('ui.ops_shift_col_before') }}</th>
                            <th>{{ trans('ui.ops_shift_col_after') }}</th>
                            <th>{{ trans('ui.ops_shift_col_last') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_effective') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_idle') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_hm_start') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_hm_before') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_hm_after') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_hm_end') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_var') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tb['hours_meter'] ?? [] as $r)
                            <tr>
                                <td>{{ $r['device'] }}</td>
                                <td>{{ $r['shift'] }}</td>
                                <td>{{ $r['first_move'] ?? '—' }}</td>
                                <td>{{ $r['break_start'] ?? '—' }}</td>
                                <td>{{ $r['break_end'] ?? '—' }}</td>
                                <td>{{ $r['last_move'] ?? '—' }}</td>
                                <td class="n">{{ $hm($r['effective_seconds']) }}</td>
                                <td class="n">{{ $hm($r['idle_seconds']) }}</td>
                                <td class="n">{{ $n($r['engine_start'], 2) }}</td>
                                <td class="n">{{ $n($r['engine_before'], 2) }}</td>
                                <td class="n">{{ $n($r['engine_after'], 2) }}</td>
                                <td class="n">{{ $n($r['engine_end'], 2) }}</td>
                                <td class="n">{{ $n($r['engine_var'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="13" class="shr-muted">{{ trans('ui.ops_shift_no_rows') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                {{-- 5. Kilometraje --}}
                <div class="shr-h2">{{ trans('ui.ops_shift_table_odometer') }}</div>
                <table class="shr-table">
                    <thead>
                        <tr>
                            <th>{{ trans('ui.vehicle') }}</th>
                            <th>{{ trans('ui.ops_shift') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_start') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_end') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_var') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tb['odometer'] ?? [] as $r)
                            <tr>
                                <td>{{ $r['device'] }}</td>
                                <td>{{ $r['shift'] }}</td>
                                <td class="n">{{ $n($r['start'], 2) }}</td>
                                <td class="n">{{ $n($r['end'], 2) }}</td>
                                <td class="n">{{ $n($r['var'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="shr-muted">{{ trans('ui.ops_shift_no_rows') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                {{-- 6. Abastecimientos --}}
                <div class="shr-h2">{{ trans('ui.ops_shift_table_refuel') }}</div>
                <table class="shr-table">
                    <thead>
                        <tr>
                            <th>{{ trans('ui.vehicle') }}</th>
                            <th>{{ trans('ui.ops_shift') }}</th>
                            <th>{{ trans('ui.ops_shift_col_started') }}</th>
                            <th>{{ trans('ui.ops_shift_col_ended') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_duration') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_level_before') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_level_after') }}</th>
                            <th class="n">{{ trans('ui.ops_shift_col_refuel') }}</th>
                            <th>{{ trans('ui.ops_shift_col_engine_off') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tb['refuelings'] ?? [] as $r)
                            <tr>
                                <td>{{ $r['device'] }}</td>
                                <td>{{ $r['shift'] }}</td>
                                <td>{{ $r['started'] ?? '—' }}</td>
                                <td>{{ $r['ended'] ?? '—' }}</td>
                                <td class="n">{{ $hm($r['duration_seconds']) }}</td>
                                <td class="n">{{ $n($r['level_before']) }}</td>
                                <td class="n">{{ $n($r['level_after']) }}</td>
                                <td class="n">{{ $n($r['refuel_pct']) }}</td>
                                <td>{{ $r['engine_off'] ? trans('ui.ops_shift_yes') : trans('ui.ops_shift_no') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="shr-muted">{{ trans('ui.ops_shift_no_refuelings') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
@stop
