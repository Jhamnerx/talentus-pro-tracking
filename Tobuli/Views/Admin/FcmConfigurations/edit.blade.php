@php /** @var \Tobuli\Entities\FcmConfiguration $item */ @endphp

@extends('front::Layouts.modal')

@section('title', trans('global.edit'))

@section('body')
    {!! Form::open(['route' => ['admin.fcm_configurations.update', $item->id], 'method' => 'PUT']) !!}

    <div class="row">
        <div class="col-sm-12">
            <div class="checkbox-inline">
                {!! Form::hidden('is_default', 0) !!}
                {!! Form::checkbox('is_default', 1, $item->is_default) !!}
                {!! Form::label(null, trans('validation.attributes.default')) !!}
            </div>
        </div>
    </div>

    <br>

    <div class="form-group">
        {!! Form::label('title', trans('validation.attributes.title') . ':') !!}
        {!! Form::text('title', $item->title, ['class' => 'form-control']) !!}
    </div>

    <br>

    <div class="form-group">
        {!! Form::label('config', trans('validation.attributes.firebase_config') . ':') !!}
        {!! Form::textarea('config', $item->config, ['class' => 'form-control']) !!}
    </div>

    {!! Form::close() !!}
@stop