@extends('front::Layouts.modal')
@section('modal_class', 'modal-lg')

@section('title')
    <i class="icon routes"></i> {{ trans('front.route_checkpoints') }} — {{ $route->name }}
@stop

@php
    /**
     * Una fila por punto. Los campos van en arrays paralelos (uno por columna)
     * porque es lo que sabe repetir el repetidor de la UI: al clonar la fila
     * vacia se clonan todos sus inputs de golpe y los indices siguen cuadrando.
     */
    $fila = function ($prefijo, $geofences, $punto = null) {
        return view('front::RouteCheckpoints.row', [
            'prefijo'   => $prefijo,
            'geofences' => $geofences,
            'punto'     => $punto,
        ])->render();
    };
@endphp

@section('body')
    {!! Form::open(['route' => ['route_checkpoints.update', $route->id], 'method' => 'PUT']) !!}

        <div class="alert alert-info">
            {{ trans('front.route_checkpoint_hint') }}
        </div>

        @foreach ([['out', $outbound, 'front.route_direction_outbound'], ['in', $inbound, 'front.route_direction_inbound']] as [$prefijo, $puntos, $titulo])
            <div class="form-group">
                <label>{{ trans($titulo) }}</label>
            </div>

            <div class="empty-input-items" data-max="30">
                @foreach ($puntos as $punto)
                    {!! $fila($prefijo, $geofences, $punto) !!}
                @endforeach

                {!! $fila($prefijo, $geofences) !!}
            </div>
        @endforeach

    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action" data-submit="modal">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
