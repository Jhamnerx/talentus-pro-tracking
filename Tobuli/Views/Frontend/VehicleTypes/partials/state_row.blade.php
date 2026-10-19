{{-- Fila de estado del tipo de vehículo: nombre · label corto (badge) · color.
     Paridad con ui/v2 (tp-account.js stateRow). Para los existentes van
     edit_state[id] / edit_state_label[id] / edit_state_color[id]; para los
     nuevos add_state[] / add_state_label[] / add_state_color[] (arrays
     paralelos: el clonado de .empty-input-add-new no puede indexar). --}}
@php
    $isNew = empty($state);
    $nameField = $isNew ? 'add_state[]' : "edit_state[{$state->id}]";
    $labelField = $isNew ? 'add_state_label[]' : "edit_state_label[{$state->id}]";
    $colorField = $isNew ? 'add_state_color[]' : "edit_state_color[{$state->id}]";
    $color = !$isNew && preg_match('/^#[0-9a-fA-F]{6}/', (string) $state->color) ? $state->color : '#3b82f6';
@endphp
<div class="form-group {{ $isNew ? 'empty-input-add-new' : '' }}">
    <div class="row">
        <div class="col-xs-6" style="padding-right:4px;">
            {!! Form::text($nameField, $isNew ? null : $state->name, [
                'class' => 'form-control',
                'placeholder' => $isNew ? trans('front.add_vehicle_state') : trans('validation.attributes.name'),
            ]) !!}
        </div>
        <div class="col-xs-6" style="padding-left:4px;">
            <div class="input-group">
                {!! Form::text($labelField, $isNew ? null : $state->label, [
                    'class' => 'form-control',
                    'placeholder' => trans('front.vehicle_state_label'),
                    'maxlength' => 24,
                ]) !!}
                <span class="input-group-addon" style="padding:2px 6px;">
                    <input type="color" name="{{ $colorField }}" value="{{ $color }}" title="{{ trans('front.vehicle_state_color') }}"
                        style="width:30px;height:22px;padding:0;border:1px solid #ccc;border-radius:4px;cursor:pointer;">
                </span>
                <span class="input-group-addon"><a href="javascript:" class="delete-item"><span aria-hidden="true">&times;</span></a></span>
            </div>
        </div>
    </div>
</div>
