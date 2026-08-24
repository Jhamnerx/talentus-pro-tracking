@extends('Frontend.Reports.partials.layout')

@section('content')
    @php
        $filas = [];
        foreach ($report->getItems() as $item) {
            // Los vehiculos sin gastos ni recorrido vienen como item de
            // 'error', sin tabla: no pintan fila.
            foreach ($item['table']['rows'] ?? [] as $fila) {
                $filas[] = $fila;
            }
        }

        // Primero el que mas costo de verdad; los que solo tienen estimacion
        // van despues, porque su numero es un supuesto.
        usort($filas, function ($a, $b) {
            return [$b['real'] === null, $b['real'] ?? $b['estimated'] ?? 0]
                <=> [$a['real'] === null, $a['real'] ?? $a['estimated'] ?? 0];
        });

        $totales = $report->globalTotals();
    @endphp

    <div class="panel panel-default">
        <div class="panel-heading">
            <div class="pull-right">
                {{ Formatter::time()->human($report->getDateFrom()) }} -
                {{ Formatter::time()->human($report->getDateTo()) }}
                ({{ Formatter::time()->unit() }})
            </div>
            {{ trans('front.report_type') }}: {{ $report->title() }}
        </div>

        <div class="panel-body no-padding">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>{{ trans('validation.attributes.name') }}</th>
                        <th>{{ trans('validation.attributes.plate_number') }}</th>
                        <th>{{ trans('global.distance') }}</th>
                        <th>{{ trans('front.cost_expenses_count') }}</th>
                        <th>{{ trans('front.cost_real') }}</th>
                        <th>{{ trans('front.cost_real_per_km') }}</th>
                        <th>{{ trans('front.cost_per_km') }}</th>
                        <th>{{ trans('front.cost_estimated') }}</th>
                        <th>{{ trans('front.cost_difference') }}</th>
                        <th>{{ trans('front.cost_source') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $fila)
                        <tr>
                            <td>{{ $fila['name'] }}</td>
                            <td>{{ $fila['plate'] }}</td>
                            <td>{{ $fila['distance'] }}</td>
                            <td>{{ $fila['expenses_count'] ?: '—' }}</td>
                            {{-- Un guion, no un cero: sin gastos cargados no se
                                 sabe cuanto costo, y un cero lo dejaria como el
                                 vehiculo mas barato de la flota. --}}
                            <td><strong>{{ $fila['real'] !== null ? $fila['real'] : '—' }}</strong></td>
                            <td>{{ $fila['real_per_km'] !== null ? $fila['real_per_km'] : '—' }}</td>
                            <td>{{ $fila['cost_per_km'] !== null ? $fila['cost_per_km'] : '—' }}</td>
                            <td>{{ $fila['estimated'] !== null ? $fila['estimated'] : '—' }}</td>
                            <td>
                                @if ($fila['difference'] === null)
                                    —
                                @else
                                    {{ $fila['difference'] > 0 ? '+' : '' }}{{ $fila['difference'] }}
                                @endif
                            </td>
                            <td>{{ $fila['source'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">{{ trans('front.nothing_found_request') }}</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($filas)
                    <tfoot>
                        <tr>
                            <th colspan="4">{{ trans('front.total') }}</th>
                            <th>{{ $totales[trans('front.cost_real')] ?? 0 }}</th>
                            <th></th>
                            <th></th>
                            <th>{{ $totales[trans('front.cost_estimated')] ?? 0 }}</th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="panel-footer">
            <small>
                {{ trans('front.cost_real') }}: {{ trans('front.cost_source_expenses') }}.
                {{ trans('front.cost_estimated') }}: {{ trans('front.cost_per_km') }} × {{ trans('global.distance') }}.
                {{ trans('front.cost_difference') }}: {{ trans('front.cost_real') }} − {{ trans('front.cost_estimated') }}.
            </small>
        </div>
    </div>
@stop
