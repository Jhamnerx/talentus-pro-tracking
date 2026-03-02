@extends('front::Layouts.modal')

@section('title', trans('global.add_new'))

@section('body')
    {!! Form::open(['route' => ['admin.fcm_configurations.store'], 'method' => 'POST']) !!}

    <div class="row">
        <div class="col-sm-12">
            <div class="checkbox-inline">
                {!! Form::hidden('is_default', 0) !!}
                {!! Form::checkbox('is_default', 1, false) !!}
                {!! Form::label(null, trans('validation.attributes.default')) !!}
            </div>
        </div>
    </div>

    <br>

    <div class="form-group">
        {!! Form::label('title', trans('validation.attributes.title') . ':') !!}
        {!! Form::text('title', null, ['class' => 'form-control']) !!}
    </div>

    <br>

    <div class="form-group">
        {!! Form::label('config', trans('validation.attributes.firebase_config') . ':') !!}
        {!! Form::textarea('config', null, ['class' => 'form-control']) !!}
    </div>

    {!! Form::close() !!}
@stop