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

        <div class="form-group" style="margin-bottom:4px;">
            <label>{{ trans('front.vehicle_states') }}:</label>
            <small style="display:block;">{{ trans('ui.name_badge_color') }}</small>
        </div>

        <div class="empty-input-items">
            @include('Frontend.VehicleTypes.partials.state_row', ['state' => null])
        </div>
        @include('Frontend.VehicleTypes.partials.state_rows_script')
    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action update_with_files">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
