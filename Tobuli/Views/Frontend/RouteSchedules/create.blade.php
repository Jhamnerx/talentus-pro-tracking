@extends('front::Layouts.modal')

@section('title')
    <i class="icon routes"></i> {{ trans('front.route_schedule_add') }}
@stop

@section('body')
    {!! Form::open(['route' => 'route_schedules.store', 'method' => 'POST']) !!}
        @include('front::RouteSchedules.form', ['item' => null])
    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action" data-submit="modal">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
