@extends('Frontend.Layouts.modal')

@section('modal_class', 'modal-lg')

@section('title', trans('admin.command_rules_add'))

@section('body')
    {!! Form::open(['route' => 'admin.command_rules.store', 'method' => 'POST']) !!}

    @include('Admin.CommandRules.form')

    {!! Form::close() !!}
@stop
