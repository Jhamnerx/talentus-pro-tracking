@extends('Frontend.Reports.partials.layout')

@section('content')
    @foreach ($report->getItems() as $item)
        <div class="panel panel-default">
            @include('Frontend.Reports.partials.item_heading')

            <div class="panel-body no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            @foreach ($item['meta'] as $meta)
                                <th>{{ $meta['title'] }}</th>
                            @endforeach
                            <th>{{ trans('front.server_time') }}</th>
                            <th>{{ trans('front.time') }}</th>
                            <th>{{ trans('front.latitude') }}</th>
                            <th>{{ trans('front.longitude') }}</th>
                            <th>{{ trans('front.address') }}</th>
                            <th>Geocerca</th>
                            <th>{{ trans('front.altitude') }}</th>
                            <th>{{ trans('front.speed') }}</th>
                            <th>Lim Velocidad</th>
                            <th>Diferencia de velocidad</th>
                            <th>Nivel de Infraccion</th>

                        </tr>
                    </thead>
                    <tbody>

                        @if (isset($item['error']))
                            <tr>
                                <td colspan="12">
                                    {{ $item['error'] }}</td>
                            </tr>
                        @else
                            @if ($item['table']['rows'])
                                @foreach ($item['table']['rows'] as $row)
                                    <tr>
                                        @foreach ($item['meta'] as $meta)
                                            <td>{{ $meta['value'] }}</td>
                                        @endforeach
                                        <td>{{ $row['server_time'] }}</td>
                                        <td>{{ $row['time'] }}</td>
                                        <td>{{ $row['latitude'] }}</td>
                                        <td>{{ $row['longitude'] }}</td>
                                        <td>{!! $row['location'] !!}</td>
                                        <td>{{ $row['geofence_name'] }}</td>
                                        <td>{{ $row['altitude'] }}</td>
                                        <td>{{ $row['speed'] }}</td>
                                        <td>{{ $row['speed_limit'] }}</td>
                                        <td>{{ $row['speed_difference'] }}</td>
                                        <td>{{ $row['violation_level'] }}</td>

                                    </tr>
                                @endforeach
                            @endif
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@stop
