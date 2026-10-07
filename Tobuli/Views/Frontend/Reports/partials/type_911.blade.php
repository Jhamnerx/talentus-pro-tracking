@extends('Frontend.Reports.partials.layout')

@section('styles')
    <style>
        .ops-bad { color: #c0392b; font-weight: bold; }
        .ops-muted { color: #777; font-style: italic; }
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
                        {{ trans('front.stops') }}: <strong>{{ $item['summary']['stops'] }}</strong>
                        · {{ trans('front.operation_unauthorized_stops') }}:
                        <strong class="{{ $item['summary']['unauthorized'] ? 'ops-bad' : '' }}">{{ $item['summary']['unauthorized'] }}</strong>
                    </p>

                    <table class="table table-hover ops-small">
                        <thead>
                            <tr>
                                <th>{{ trans('front.operations') }}</th>
                                <th>{{ trans('front.operation_cycle') }}</th>
                                <th>{{ trans('front.operation_outbound') }}/{{ trans('front.operation_inbound') }}</th>
                                <th>{{ trans('front.operation_stop_start') }}</th>
                                <th class="ops-num">{{ trans('front.operation_stop_duration') }}</th>
                                <th>{{ trans('front.operation_stop_location') }}</th>
                                <th>{{ trans('front.operation_stop_engine') }}</th>
                                <th>{{ trans('front.operation_stop_geofence') }}</th>
                                <th>{{ trans('front.status') }}</th>
                                <th>{{ trans('front.operation_stop_reason') }}</th>
                                <th>{{ trans('front.operation_stop_justified_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['table']['rows'] as $r)
                                <tr>
                                    <td>{{ $r['operation'] }}</td>
                                    <td>{{ $r['cycle'] }}</td>
                                    <td>{{ $r['leg'] }}</td>
                                    <td>{{ $r['started_at'] }}</td>
                                    <td class="ops-num">{{ $r['duration'] }}</td>
                                    <td>
                                        @if ($r['latitude'] !== null)
                                            <a href="https://maps.google.com/?q={{ $r['latitude'] }},{{ $r['longitude'] }}" target="_blank" rel="noopener">{{ $r['location'] }}</a>
                                        @else
                                            {{ $r['location'] }}
                                        @endif
                                    </td>
                                    <td>{{ $r['engine'] }}</td>
                                    <td>{{ $r['geofence'] ?: '—' }}</td>
                                    <td class="{{ $r['doubtful'] ? 'ops-muted' : ($r['authorized'] ? '' : 'ops-bad') }}">{{ $r['status'] }}</td>
                                    <td>{{ $r['reason'] }}@if ($r['note']) <span class="ops-muted">{{ $r['note'] }}</span>@endif</td>
                                    <td>{{ $r['justified_by'] }} {{ $r['justified_at'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
@stop
