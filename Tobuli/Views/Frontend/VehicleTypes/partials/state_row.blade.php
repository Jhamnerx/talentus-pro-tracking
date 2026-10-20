{{-- Fila de estado del tipo de vehículo: nombre · label corto (badge) · color.
     MISMO formato que la ui/v2 (tp-account.js stateRow):
       existentes: edit_state[id][name|label|color]
       nuevos:     add_state[N][name|label|color]
     El clonado legacy (.empty-input-add-new copia el outerHTML) deja todas
     las filas nuevas con el mismo índice; state_rows_script.blade las
     renumera al escribir y al borrar. --}}
@php
    $isNew = empty($state);
    $base = $isNew ? 'add_state[0]' : "edit_state[{$state->id}]";
    $color = !$isNew && preg_match('/^#[0-9a-fA-F]{6}/', (string) $state->color) ? $state->color : '#3b82f6';
@endphp
<div class="form-group {{ $isNew ? 'empty-input-add-new' : '' }}" {!! $isNew ? 'data-new-state' : '' !!}>
    <div class="row">
        <div class="col-xs-6" style="padding-right:4px;">
            {!! Form::text("{$base}[name]", $isNew ? null : $state->name, [
                'class' => 'form-control',
                'placeholder' => $isNew ? trans('front.add_vehicle_state') : trans('validation.attributes.name'),
            ]) !!}
        </div>
        <div class="col-xs-6" style="padding-left:4px;">
            <div class="input-group">
                {!! Form::text("{$base}[label]", $isNew ? null : $state->label, [
                    'class' => 'form-control',
                    'placeholder' => trans('front.vehicle_state_label'),
                    'maxlength' => 24,
                ]) !!}
                <span class="input-group-addon" style="padding:2px 6px;">
                    <input type="color" name="{{ $base }}[color]" value="{{ $color }}" title="{{ trans('front.vehicle_state_color') }}"
                        style="width:30px;height:22px;padding:0;border:1px solid #ccc;border-radius:4px;cursor:pointer;">
                </span>
                <span class="input-group-addon"><a href="javascript:" class="delete-item"><span aria-hidden="true">&times;</span></a></span>
            </div>
        </div>
    </div>
</div>
