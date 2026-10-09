@extends('Frontend.Reports.partials.layout')

@section('styles')
    <style>
        .ops-groups { margin: 0 0 12px; }
        .ops-groups td, .ops-groups th { font-size: 12px; }
        .ops-bad { color: #c0392b; font-weight: bold; }
        .ops-num { text-align: right; white-space: nowrap; }
        .ops-small { font-size: 11px; }
    </style>
@stop

@section('content')
    @php $col = $report->columnLabels(); @endphp
    @foreach ($report->getItems() as $item)
        <div class="panel panel-default">
            @include('Frontend.Reports.partials.item_heading')

            @if (isset($item['error']))
                @include('Frontend.Reports.partials.item_empty')
            @else
                <div class="panel-body">
                    <table class="table table-condensed ops-groups">
                        <thead>
                            <tr>
                                <th>{{ trans('front.operation_totals') }}</th>
                                <th class="ops-num">{{ trans('front.operation_cycles') }}</th>
                                <th class="ops-num">{{ trans('front.operation_trips') }}</th>
                                <th class="ops-num">{{ trans('front.operation_avg_cycle') }}</th>
                                <th class="ops-num">{{ trans('front.operation_unauthorized_stops') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['groups'] as $g)
                                <tr>
                                    <td>{{ $g['title'] }}</td>
                                    <td class="ops-num">{{ $g['cycles'] }}</td>
                                    <td class="ops-num">{{ $g['trips'] }}</td>
                                    <td class="ops-num">{{ $g['avg_cycle'] }}</td>
                                    <td class="ops-num {{ $g['unauthorized'] ? 'ops-bad' : '' }}">{{ $g['unauthorized'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <table class="table table-hover ops-small">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ trans('global.date') }}</th>
                                <th>{{ trans('front.operation_shift') }}</th>
                                <th>{{ trans('front.status') }}</th>
                                <th>{{ $col['a_exit'] }}</th>
                                <th>{{ $col['b_enter'] }}</th>
                                <th>{{ $col['b_exit'] }}</th>
                                <th>{{ $col['a_enter'] }}</th>
                                <th class="ops-num">{{ $col['a_time'] }}</th>
                                <th class="ops-num">{{ trans('front.operation_outbound') }}</th>
                                <th class="ops-num">{{ $col['b_time'] }}</th>
                                <th class="ops-num">{{ trans('front.operation_inbound') }}</th>
                                <th class="ops-num">{{ trans('front.operation_cycle') }}</th>
                                <th class="ops-num">{{ trans('front.stopped') }}</th>
                                <th class="ops-num">{{ trans('front.idle') }}</th>
                                <th class="ops-num">{{ trans('front.operation_engine_off') }}</th>
                                <th class="ops-num">{{ trans('front.operation_unauthorized_stops') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['table']['rows'] as $r)
                                <tr>
                                    <td>{{ $r['number'] }}</td>
                                    <td>{{ $r['work_date'] }}</td>
                                    <td>{{ $r['shift'] }}</td>
                                    <td>{{ $r['status'] }}</td>
                                    <td>{{ $r['load_exit'] }}</td>
                                    <td>{{ $r['unload_enter'] ?: '—' }}</td>
                                    <td>{{ $r['unload_exit'] ?: '—' }}</td>
                                    <td>{{ $r['load_enter'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['load_time'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['outbound'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['unload_time'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['inbound'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['cycle'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['stop'] }}</td>
                                    <td class="ops-num">{{ $r['idle'] }}</td>
                                    <td class="ops-num">{{ $r['engine_off'] }}</td>
                                    <td class="ops-num {{ $r['unauthorized'] ? 'ops-bad' : '' }}">{{ $r['unauthorized'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
@stop
