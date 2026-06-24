@extends('front::Layouts.modal')

@section('title')
    {{ trans('front.change_vehicle_state') }}
@stop

@section('body')
    {!! Form::open(['route' => 'devices.change_vehicle_state', 'method' => 'POST']) !!}
        {!! Form::hidden('id', $item->id) !!}

        <div class="form-group">
            {!! Form::label('vehicle_state_id', trans('validation.attributes.vehicle_state_id') . ':') !!}
            {!! Form::select(
                'vehicle_state_id',
                ['' => trans('front.no_vehicle_state')] + $states->all(),
                $item->vehicle_state_id,
                ['class' => 'form-control'],
            ) !!}
        </div>
    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action update_with_files">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
