@extends('Frontend.Reports.partials.layout')

@section('styles')
    <style>
        .bottomline {
            border-bottom: 1px dotted !important;
        }

        .field {
            white-space: nowrap;
            width: 1%;
            font-weight: bold;
        }
    </style>
@stop

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="icon report"></i> {{ trans('front.overspeeds') }} / {{ trans('front.geofences') }} - Buenaventura
            </h3>
        </div>

        <div class="panel-body no-padding">
            <table class="table table-hover">
                <thead>
                    <tr>
                        @foreach ($report->metas('device') as $meta)
                            <th>{{ $meta['title'] }}</th>
                        @endforeach
                        <th>{{ trans('front.geofences') }}</th>
                        <th>{{ trans('front.overspeed_limit') }}</th>
                        <th>{{ trans('front.start') }}</th>
                        <th>{{ trans('front.end') }}</th>
                        <th>{{ trans('front.duration') }}</th>
                        <th>{{ trans('front.top_speed') }}</th>
                        <th>{{ trans('front.average_speed') }}</th>
                        <th>{{ trans('front.excesive_speed') }}</th>
                        <th>{{ trans('front.distance') }}</th>
                        <th>{{ trans('front.position') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->getItems() as $item)
                        @if (!isset($item['error']))
                            @foreach ($item['table']['rows'] as $row)
                                <tr>
                                    @foreach ($item['meta'] as $key => $meta)
                                        <td>{{ $meta['value'] }}</td>
                                    @endforeach
                                    <td>{{ $row['geofences_in'] }}</td>
                                    <td>{{ $row['overspeed_limit'] }}</td>
                                    <td>{{ $row['start_at'] }}</td>
                                    <td>{{ $row['end_at'] }}</td>
                                    <td>{{ $row['duration'] }}</td>
                                    <td>{{ $row['speed_max'] }}</td>
                                    <td>{{ $row['speed_avg'] }}</td>
                                    <td>{{ $row['excesive_speed'] }}</td>
                                    <td>{{ $row['distance'] }}</td>
                                    <td>{!! $row['location'] !!}</td>
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background-color: #f5f5f5; font-weight: bold;">
                        @foreach ($report->metas('device') as $meta)
                            <th></th>
                        @endforeach
                        <th colspan="3" style="text-align: right;">{{ trans('front.total') }}:</th>
                        <th></th>
                        <th>{{ $report->globalTotals('duration') }}</th>
                        <th>{{ $report->globalTotals('speed_max') }}</th>
                        <th>{{ $report->globalTotals('speed_avg') }}</th>
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@stop
