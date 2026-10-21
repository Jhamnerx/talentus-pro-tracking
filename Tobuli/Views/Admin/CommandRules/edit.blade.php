@extends('Frontend.Layouts.modal')

@section('modal_class', 'modal-lg')

@section('title', trans('admin.command_rules_edit'))

@section('body')
    {!! Form::open(['route' => ['admin.command_rules.update', $item->id], 'method' => 'PUT']) !!}
    {!! Form::hidden('id', $item->id) !!}

    @include('Admin.CommandRules.form')

    {!! Form::close() !!}
@stop
