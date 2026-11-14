@extends('Frontend.Reports.partials.layout')

<?php $inline = $report->getFormat() === 'html'; ?>

@if ($inline)
    @section('styles')
        <style><?php require ( base_path('public/assets/css/report-map.css') ); ?></style>
    @stop

    @section('scripts')
        <script src="{{ asset('assets/js/report.js') }}" type="text/javascript"></script>
        @include('Frontend.Reports.partials.map_tiles')
    @stop
@endif

@section('content')
    @foreach ($report->getItems() as $item)
        <div class="panel panel-default" data-device="{{ json_encode($item['device'] ?? null, JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) }}">
            @include('Frontend.Reports.partials.item_heading')

            @if (isset($item['error']))
                @include('Frontend.Reports.partials.item_empty')
            @else
                @if ( ! empty($item['table']))
                    <div class="panel-body no-padding">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>{{ trans('front.start') }}</th>
                                <th>{{ trans('front.end') }}</th>
                                <th>{{ trans('front.duration') }}</th>
                                <th>{{ trans('front.engine_idle') }}</th>
                                <th>{{ trans('front.driver') }}</th>
                                <th>{{ trans('front.stop_position') }}</th>
                                @if ($report->zones_instead)
                                    <th>{{ trans('front.geofences') }}</th>
                                @endif
                                @if ($inline)
                                    <th class="report-map-col">{{ trans('front.action') }}</th>
                                @endif
                            </tr>
                            </thead>

                            <tbody>
                            @foreach ($item['table']['rows'] as $row)
                                <tr>
                                    <td>{{ $row['start_at'] }}</td>
                                    <td>{{ $row['end_at'] }}</td>
                                    <td>{{ $row['duration'] }}</td>
                                    <td>{{ $row['engine_idle'] }}</td>
                                    <td>{{ $row['drivers'] }}</td>
                                    <td>{!! $row['location'] !!}</td>

                                    @if ($report->zones_instead)
                                        <td>{{ \Illuminate\Support\Arr::get($row, 'geofences_in') }}</td>
                                    @endif

                                    @if ($inline)
                                        <td class="report-map-col">
                                            @if (isset($row['latitude'], $row['longitude']))
                                                <button type="button"
                                                        class="report-map-btn"
                                                        data-lat="{{ $row['latitude'] }}"
                                                        data-lng="{{ $row['longitude'] }}"
                                                        data-duration="{{ $row['duration'] }}">{{ trans('front.show_map') }}</button>
                                            @endif
                                        </td>
                                    @endif
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

    @if ($inline)
        @include('Frontend.Reports.partials.map_window')
    @endif
@stop
