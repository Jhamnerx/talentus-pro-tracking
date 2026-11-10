@extends('Frontend.Reports.partials.layout')

@section('content')
    {{-- Inicio y duracion de la subida: solo rellenos (type_12, robos, incluye esta vista). --}}
    @php $showFillingStart = $report->typeID() == \Tobuli\Reports\Reports\FuelFillingsReport::TYPE_ID; @endphp
    @foreach ($report->getItems() as $item)
        <div class="panel panel-default">
            @include('Frontend.Reports.partials.item_heading')

            @if (isset($item['error']))
                @include('Frontend.Reports.partials.item_empty')
            @else

                @if ( ! empty($item['table']))
                    <div class="panel-body no-padding">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                @if ($showFillingStart)
                                    <th>{{ trans('front.start_time') }}</th>
                                @endif
                                <th>{{ trans('front.time') }}</th>
                                @if ($showFillingStart)
                                    <th>{{ trans('front.duration') }}</th>
                                @endif
                                <th>{{ trans('front.last_value') }}</th>
                                <th>{{ trans('front.difference') }}</th>
                                <th>{{ trans('front.current_value') }}</th>
                                <th>{{ trans('front.sensor') }}</th>
                                <th>{{ trans('front.position') }}</th>
                            </tr>
                            </thead>

                            <tbody>
                            @foreach ($item['table']['rows'] as $row)
                                <tr>
                                    @if ($showFillingStart)
                                        <td>{{ $row['filling_start_at'] ?? '-' }}</td>
                                    @endif
                                    <td>{{ $row['start_at'] }}</td>
                                    @if ($showFillingStart)
                                        <td>{{ $row['filling_duration'] ?? '-' }}</td>
                                    @endif
                                    <td>{{ $row['fuel_level_previous'] }}</td>
                                    <td>{{ $row['fuel_level_difference'] }}</td>
                                    <td>{{ $row['fuel_level_current'] }}</td>
                                    <td>{{ $row['sensor_name'] }}</td>
                                    <td >{!! $row['location'] !!}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @include('Frontend.Reports.partials.item_total')
            @endif
        </div>
    @endforeach
@stop