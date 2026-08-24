@extends('Frontend.Reports.partials.layout')

@php
    use Tobuli\Reports\Reports\DrivingBehaviourReport;

    $conductas = DrivingBehaviourReport::CONDUCTAS;
    // Los titulos salen del catalogo de eventos, para decir lo mismo que
    // el listado de eventos y la pantalla de alertas.
    $titulos = DrivingBehaviourReport::titulos();
@endphp

@section('content')
    {{-- Una sola tabla con todos los vehículos: el valor de este reporte es
         comparar unos con otros, no ver cada uno por separado. --}}
    <div class="panel panel-default">
        <div class="panel-heading">
            <div class="pull-right">
                {{ Formatter::time()->human($report->getDateFrom()) }} -
                {{ Formatter::time()->human($report->getDateTo()) }}
                ({{ Formatter::time()->unit() }})
            </div>
            <div class="report-bars"></div>
            {{ trans('front.report_type') }}: {{ $report->title() }}
        </div>

        <div class="panel-body no-padding">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>{{ trans('validation.attributes.name') }}</th>
                        <th>{{ trans('validation.attributes.plate_number') }}</th>
                        <th>{{ trans('global.distance') }}</th>
                        @foreach ($titulos as $titulo)
                            <th>{{ $titulo }}</th>
                        @endforeach
                        <th>{{ trans('front.driving_events') }}</th>
                        <th>{{ trans('front.driving_index') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Peor primero: quien tiene el indice mas alto es quien
                        // hay que mirar, y sin kilometraje no se puede comparar.
                        $filas = [];
                        foreach ($report->getItems() as $item) {
                            // Los vehiculos sin eventos los devuelve la clase
                            // base como item de 'error', sin tabla: aqui no
                            // pintan nada, la comparativa es entre los que si.
                            foreach ($item['table']['rows'] ?? [] as $fila) {
                                $filas[] = $fila;
                            }
                        }
                        usort($filas, function ($a, $b) {
                            $x = is_numeric($a['index']) ? (float) $a['index'] : -1;
                            $y = is_numeric($b['index']) ? (float) $b['index'] : -1;
                            return $y <=> $x;
                        });
                    @endphp

                    @forelse ($filas as $fila)
                        <tr>
                            <td>{{ $fila['name'] }}</td>
                            <td>{{ $fila['plate'] }}</td>
                            <td>{{ $fila['distance'] }}</td>
                            @foreach ($conductas as $clave => $conducta)
                                <td>{{ $fila[$clave] }}</td>
                            @endforeach
                            <td><strong>{{ $fila['total'] }}</strong></td>
                            <td><strong>{{ $fila['index'] }}</strong></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($conductas) + 5 }}">
                                {{ trans('front.nothing_found_request') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel-footer">
            <small>
                {{ trans('front.driving_index') }}:
                {{ trans('front.driving_weighted') }} ÷ km × 100.
                {{-- Se explica el peso para que el numero no parezca arbitrario --}}
                @foreach ($conductas as $clave => $conducta)
                    {{ $titulos[$clave] }} ×{{ $conducta['peso'] }}@if (!$loop->last), @endif
                @endforeach
            </small>
        </div>
    </div>
@stop
