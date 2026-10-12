@extends('Frontend.Reports.partials.layout')

@php
    use Tobuli\Entities\Operation;

    $active = $report->deductions();
    $labels = Operation::deductionLabels();
@endphp

@section('styles')
    <style>
        .ops-num { text-align: right; white-space: nowrap; }
        .ops-small { font-size: 11px; }
        .ops-summary { margin: 0 0 10px; font-size: 12px; }
        .ops-off { color: #999; }
        .ops-net { font-weight: bold; }
    </style>
@stop

@section('content')
    <p class="ops-summary">
        {{ trans('front.operation_deductions_active') }}:
        @if ($active)
            {{ implode(', ', array_map(fn ($k) => $labels[$k] ?? $k, $active)) }}
        @else
            {{ trans('front.none') }}
        @endif
    </p>

    @foreach ($report->getItems() as $item)
        <div class="panel panel-default">
            @include('Frontend.Reports.partials.item_heading')

            @if (isset($item['error']))
                @include('Frontend.Reports.partials.item_empty')
            @else
                <div class="panel-body">
                    <p class="ops-summary">
                        {{ trans('front.engine_hours') }}: <strong>{{ $item['summary']['engine'] }}</strong>
                        · {{ trans('front.operation_net_hours') }}: <strong>{{ $item['summary']['net'] }}</strong>
                        @if ($item['summary']['has_fuel'])
                            · {{ trans('front.fuel_consumption') }}: <strong>{{ $item['summary']['fuel'] }}</strong>
                            · {{ trans('front.operation_fuel_per_hour') }}: <strong>{{ $item['summary']['fuel_per_h'] }}</strong>
                        @endif
                    </p>

                    <table class="table table-hover ops-small">
                        <thead>
                            <tr>
                                <th>{{ trans('global.date') }}</th>
                                <th class="ops-num">{{ trans('front.engine_hours') }}</th>
                                <th class="ops-num {{ in_array('refueling', $active) ? '' : 'ops-off' }}">{{ $labels['refueling'] }}</th>
                                <th class="ops-num {{ in_array('idle', $active) ? '' : 'ops-off' }}">{{ $labels['idle'] }}</th>
                                <th class="ops-num {{ in_array('unauthorized_stops', $active) ? '' : 'ops-off' }}">{{ $labels['unauthorized_stops'] }}</th>
                                <th class="ops-num">{{ trans('front.operation_deducted') }}</th>
                                <th class="ops-num">{{ trans('front.operation_net_hours') }}</th>
                                <th class="ops-num">{{ trans('front.fuel_consumption') }}</th>
                                <th class="ops-num">{{ trans('front.operation_fuel_per_hour') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['table']['rows'] as $r)
                                <tr>
                                    <td>{{ $r['date'] }}</td>
                                    <td class="ops-num">{{ $r['engine'] }}</td>
                                    <td class="ops-num {{ in_array('refueling', $active) ? '' : 'ops-off' }}">{{ $r['refueling'] }}</td>
                                    <td class="ops-num {{ in_array('idle', $active) ? '' : 'ops-off' }}">{{ $r['idle'] }}</td>
                                    <td class="ops-num {{ in_array('unauthorized_stops', $active) ? '' : 'ops-off' }}">{{ $r['unauthorized'] }}</td>
                                    <td class="ops-num">{{ $r['deducted'] }}</td>
                                    <td class="ops-num ops-net">{{ $r['net'] }}</td>
                                    <td class="ops-num">{{ $r['fuel'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['fuel_per_h'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
@stop
