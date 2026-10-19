@extends('front::Layouts.modal')

@section('title')
    {{ trans('global.edit') }}
@stop

@section('body')
    {!! Form::open(['route' => ['vehicle_types.update', $item->id], 'method' => 'PUT']) !!}
        <div class="form-group">
            {!! Form::label('name', trans('validation.attributes.name') . ':') !!}
            {!! Form::text('name', $item->name, ['class' => 'form-control']) !!}
        </div>

        <div class="form-group" style="margin-bottom:4px;">
            <label>{{ trans('front.vehicle_states') }}:</label>
            <small style="display:block;">{{ trans('ui.name_badge_color') }}</small>
        </div>

        <div class="empty-input-items">
            @foreach ($item->states as $state)
                @include('Frontend.VehicleTypes.partials.state_row', ['state' => $state])
            @endforeach

            @include('Frontend.VehicleTypes.partials.state_row', ['state' => null])
        </div>
    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action update_with_files">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
