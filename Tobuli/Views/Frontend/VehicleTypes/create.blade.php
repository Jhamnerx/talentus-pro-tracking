@extends('front::Layouts.modal')

@section('title')
    {{ trans('front.add_vehicle_type') }}
@stop

@section('body')
    {!! Form::open(['route' => 'vehicle_types.store', 'method' => 'POST']) !!}
        <div class="form-group">
            {!! Form::label('name', trans('validation.attributes.name') . ':') !!}
            {!! Form::text('name', null, ['class' => 'form-control']) !!}
        </div>
    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action update_with_files">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
