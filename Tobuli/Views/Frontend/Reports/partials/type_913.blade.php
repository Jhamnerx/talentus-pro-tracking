@extends('Frontend.Reports.partials.layout')

@section('styles')
    <style>
        .ops-num { text-align: right; white-space: nowrap; }
        .ops-small { font-size: 11px; }
        .ops-summary { margin: 0 0 10px; font-size: 12px; }
    </style>
@stop

@section('content')
    @foreach ($report->getItems() as $item)
        <div class="panel panel-default">
            @include('Frontend.Reports.partials.item_heading')

            @if (isset($item['error']))
                @include('Frontend.Reports.partials.item_empty')
            @else
                <div class="panel-body">
                    <p class="ops-summary">
                        {{ trans('front.operation_refueling_visits') }}: <strong>{{ $item['summary']['visits'] }}</strong>
                        · {{ trans('front.operation_stop_duration') }}: <strong>{{ $item['summary']['duration'] }}</strong>
                    </p>

                    <table class="table table-hover ops-small">
                        <thead>
                            <tr>
                                <th>{{ trans('front.operation_stop_geofence') }}</th>
                                <th>{{ trans('front.operation_refueling_enter') }}</th>
                                <th>{{ trans('front.operation_refueling_exit') }}</th>
                                <th class="ops-num">{{ trans('front.operation_stop_duration') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['table']['rows'] as $r)
                                <tr>
                                    <td>{{ $r['geofence'] }}</td>
                                    <td>{{ $r['entered_at'] }}</td>
                                    <td>{{ $r['exited_at'] ?: '—' }}</td>
                                    <td class="ops-num">{{ $r['duration'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
@stop
