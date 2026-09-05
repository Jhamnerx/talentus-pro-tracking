@php
    /** @var \Tobuli\Entities\RouteCheckpoint|null $punto */
    $punto = $punto ?? null;

    // La ultima fila es la plantilla: el repetidor la clona en cuanto alguien
    // escribe en ella (ver empty-input-add-new en controller/app.js).
    $clases = 'form-group route-cp-row' . ($punto ? '' : ' empty-input-add-new');
@endphp

<div class="{{ $clases }}">
    <div class="row">
        <div class="col-xs-4">
            {!! Form::select("cp_{$prefijo}_geofence[]", ['' => trans('front.route_checkpoint')] + $geofences->all(), $punto->geofence_id ?? null, [
                'class'            => 'form-control',
                'data-live-search' => 'true',
            ]) !!}
        </div>
        <div class="col-xs-2">
            {!! Form::number("cp_{$prefijo}_offset[]", $punto->planned_offset ?? null, [
                'class'       => 'form-control',
                'min'         => 0,
                'placeholder' => trans('front.route_planned_offset'),
                'title'       => trans('front.route_planned_offset'),
            ]) !!}
        </div>
        <div class="col-xs-2">
            {!! Form::number("cp_{$prefijo}_early[]", $punto->tolerance_early ?? null, [
                'class'       => 'form-control',
                'min'         => 0,
                'placeholder' => trans('front.route_tolerance_early'),
                'title'       => trans('front.route_tolerance_hint'),
            ]) !!}
        </div>
        <div class="col-xs-2">
            {!! Form::number("cp_{$prefijo}_late[]", $punto->tolerance_late ?? null, [
                'class'       => 'form-control',
                'min'         => 0,
                'placeholder' => trans('front.route_tolerance_late'),
                'title'       => trans('front.route_tolerance_hint'),
            ]) !!}
        </div>
        <div class="col-xs-2">
            <div class="input-group">
                {!! Form::text("cp_{$prefijo}_name[]", $punto->name ?? null, [
                    'class'       => 'form-control',
                    'placeholder' => trans('validation.attributes.name'),
                ]) !!}
                <span class="input-group-addon">
                    <a href="javascript:" class="delete-item"><span aria-hidden="true">&times;</span></a>
                </span>
            </div>
        </div>
    </div>
</div>
