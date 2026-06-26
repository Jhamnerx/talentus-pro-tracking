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

        <div class="form-group">
            <label>{{ trans('front.vehicle_states') }}:</label>
        </div>

        <div class="empty-input-items">
            @foreach ($item->states as $state)
                <div class="form-group">
                    <div class="input-group">
                        {!! Form::text("edit_state[{$state->id}]", $state->name, ['class' => 'form-control', 'placeholder' => trans('validation.attributes.name')]) !!}
                        <span class="input-group-addon"><a href="javascript:" class="delete-item"><span aria-hidden="true">&times;</span></a></span>
                    </div>
                </div>
            @endforeach

            <div class="form-group empty-input-add-new">
                <div class="input-group">
                    {!! Form::text('add_state[]', null, ['class' => 'form-control', 'placeholder' => trans('front.add_vehicle_state')]) !!}
                    <span class="input-group-addon"><a href="javascript:" class="delete-item"><span aria-hidden="true">&times;</span></a></span>
                </div>
            </div>
        </div>
    {!! Form::close() !!}
@stop

@section('buttons')
    <button type="button" class="btn btn-action update_with_files">{!! trans('global.save') !!}</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
