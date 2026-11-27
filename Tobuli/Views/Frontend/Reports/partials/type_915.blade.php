@extends('Frontend.Reports.partials.layout')

{{-- Salidas del area de operacion (combustible). Ver OperationAreaExitsReport. --}}

@section('styles')
    <style>
        .oae-num { text-align: right; white-space: nowrap; }
        .oae-small { font-size: 11px; }
        .oae-summary { margin: 0 0 10px; font-size: 12px; }
        .oae-flag td { background: #fef2f2; }
        .oae-flag .oae-drop { color: #b91c1c; font-weight: bold; }
        .oae-badge { display: inline-block; padding: 1px 6px; border-radius: 8px; background: #dc2626; color: #fff; font-size: 10px; font-weight: bold; }
        .oae-muted { color: #9ca3af; }
        .oae-note { font-size: 11px; color: #6b7280; margin: 0 0 10px; }
    </style>
@stop

@section('content')
    @php
        $n = fn ($v) => is_null($v) ? '—' : number_format((float) $v, 1, ',', '.');
    @endphp

    <p class="oae-note">
        {{ trans('front.operation_area_areas') }}: <strong>{{ $report->areaNames() ?: '—' }}</strong>
        · {{ trans('front.operation_area_alert_drop') }}: <strong>{{ $n($report->alertDrop()) }}</strong>
        <br>{{ trans('front.operation_area_note') }}
    </p>

    @foreach ($report->getItems() as $item)
        <div class="panel panel-default">
            @include('Frontend.Reports.partials.item_heading')

            @if (isset($item['error']))
                @include('Frontend.Reports.partials.item_empty')
            @else
                <div class="panel-body">
                    <p class="oae-summary">
                        {{ trans('front.operation_area_trips') }}: <strong>{{ $item['summary']['trips'] }}</strong>
                        · {{ trans('front.operation_area_flagged') }}: <strong>{{ $item['summary']['flagged'] }}</strong>
                        @if ($item['summary']['flagged'])
                            · {{ trans('front.operation_area_loss') }}: <strong>{{ $n($item['summary']['loss']) }} %</strong>
                        @endif
                        @unless ($item['summary']['has_fuel'])
                            · <span class="oae-muted">{{ trans('front.operation_area_no_sensor') }}</span>
                        @endunless
                    </p>

                    <table class="table table-hover oae-small">
                        <thead>
                            <tr>
                                <th>{{ trans('front.operation_area_exit') }}</th>
                                <th>{{ trans('front.operation_area_return') }}</th>
                                <th class="oae-num">{{ trans('front.operation_area_outside') }}</th>
                                <th class="oae-num">{{ trans('front.distance') }}</th>
                                <th class="oae-num">{{ trans('front.operation_area_fuel_exit') }}</th>
                                <th class="oae-num">{{ trans('front.operation_area_fuel_return') }}</th>
                                <th class="oae-num">{{ trans('front.operation_area_refilled') }}</th>
                                <th class="oae-num">{{ trans('front.operation_area_drop') }}</th>
                                <th class="oae-num">{{ trans('front.operation_area_drop_100km') }}</th>
                                <th>{{ trans('front.operation_area_longest_stop') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['table']['rows'] as $r)
                                <tr class="{{ $r['flag'] ? 'oae-flag' : '' }}">
                                    <td>{{ $r['exit_at'] }}<br><span class="oae-muted">{!! $r['exit_place'] !!}</span></td>
                                    <td>{!! $r['return_at'] ?? '<span class="oae-muted">' . e(trans('front.operation_area_not_back')) . '</span>' !!}</td>
                                    <td class="oae-num">{{ $r['outside'] ?? '—' }}</td>
                                    <td class="oae-num">{{ $r['distance'] }}</td>
                                    <td class="oae-num">{{ $n($r['fuel_exit']) }}</td>
                                    <td class="oae-num">{{ $n($r['fuel_return']) }}</td>
                                    <td class="oae-num">{{ $n($r['refilled']) }}</td>
                                    <td class="oae-num oae-drop">{{ $n($r['drop']) }}</td>
                                    <td class="oae-num">{{ $n($r['drop_100km']) }}</td>
                                    <td>
                                        @if ($r['stop_at'])
                                            {{ $r['stop_at'] }} ({{ $r['stop_for'] }})<br><span class="oae-muted">{!! $r['stop_place'] !!}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>@if ($r['flag'])<span class="oae-badge">{{ trans('front.operation_area_check') }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    @if ($totals = $report->globalTotals())
        <p class="oae-summary">
            @foreach ($totals as $label => $value)
                {{ $label }}: <strong>{{ $value }}</strong>@if (! $loop->last) · @endif
            @endforeach
        </p>
    @endif
@stop
