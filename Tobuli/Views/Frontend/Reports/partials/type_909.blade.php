@extends('Frontend.Reports.partials.layout')

@php
    use Tobuli\Services\RouteAdherenceService;

    $estadosVuelta = RouteAdherenceService::estadosVuelta();
    $estadosPunto  = RouteAdherenceService::estadosPunto();

    // Clase por estado: el color es lo que hace legible el reporte de un
    // vistazo, que es como se mira un cumplimiento.
    $claseVuelta = [
        RouteAdherenceService::ROUND_COMPLETED      => 'ok',
        RouteAdherenceService::ROUND_COMPLETED_LATE => 'warn',
        RouteAdherenceService::ROUND_INCOMPLETE     => 'warn',
        RouteAdherenceService::ROUND_NOT_STARTED    => 'bad',
    ];
    $clasePunto = [
        RouteAdherenceService::CP_ON_TIME => 'ok',
        RouteAdherenceService::CP_EARLY   => 'warn',
        RouteAdherenceService::CP_LATE    => 'warn',
        RouteAdherenceService::CP_MISSED  => 'bad',
    ];
@endphp

@section('styles')
    <style>
        .adh-round { margin: 0 0 18px; }
        .adh-round__head {
            padding: 6px 10px;
            border-left: 4px solid #999;
            background: #f5f5f5;
            font-size: 12px;
        }
        .adh-round__head.ok   { border-left-color: #3c9a3c; }
        .adh-round__head.warn { border-left-color: #d79b00; }
        .adh-round__head.bad  { border-left-color: #c0392b; }
        .adh-round__title { font-weight: bold; }
        .adh-round__state { float: right; }
        .adh-state.ok   { color: #3c9a3c; }
        .adh-state.warn { color: #d79b00; }
        .adh-state.bad  { color: #c0392b; }
        .adh-dev { text-align: right; white-space: nowrap; }
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
                    @foreach ($item['rounds'] as $vuelta)
                        @php
                            $clase = $claseVuelta[$vuelta['status']] ?? '';
                        @endphp

                        <div class="adh-round">
                            <div class="adh-round__head {{ $clase }}">
                                <span class="adh-round__state adh-state {{ $clase }}">
                                    {{ $estadosVuelta[$vuelta['status']] ?? $vuelta['status'] }}
                                    ({{ $vuelta['summary']['passed'] }}/{{ $vuelta['summary']['total'] }})
                                </span>
                                <span class="adh-round__title">{{ $vuelta['schedule'] }}</span>
                                — {{ $vuelta['route'] }}
                                · {{ $vuelta['direction'] }}
                                · {{ trans('front.route_round') }}: {{ $vuelta['start'] }}
                            </div>

                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>{{ trans('front.route_checkpoint') }}</th>
                                        <th>{{ trans('front.route_planned_time') }}</th>
                                        <th>{{ trans('front.route_actual_time') }}</th>
                                        <th class="adh-dev">{{ trans('front.route_deviation') }}</th>
                                        <th>{{ trans('front.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($vuelta['points'] as $i => $punto)
                                        <tr>
                                            <td>{{ $i + 1 }}</td>
                                            <td>{{ $punto['label'] }}</td>
                                            <td>{{ $punto['planned'] }}</td>
                                            <td>{{ $punto['actual'] ?: '—' }}</td>
                                            <td class="adh-dev">
                                                {{-- El signo dice de un vistazo si llego tarde o antes --}}
                                                @if ($punto['deviation'] === null)
                                                    —
                                                @else
                                                    {{ $punto['deviation'] > 0 ? '+' . $punto['deviation'] : $punto['deviation'] }}
                                                @endif
                                            </td>
                                            <td class="adh-state {{ $clasePunto[$punto['status']] ?? '' }}">
                                                {{ $estadosPunto[$punto['status']] ?? $punto['status'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    @if ($report->globalTotals())
        <div class="panel panel-default">
            <div class="panel-heading">{{ trans('front.route_rounds') }}</div>
            <div class="panel-body no-padding">
                <table class="table">
                    <tbody>
                        @foreach ($report->globalTotals() as $titulo => $valor)
                            <tr>
                                <th class="col-sm-6">{{ $titulo }}</th>
                                <td class="col-sm-6">{{ $valor }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @include('Frontend.Reports.partials.report_empty')
@stop
