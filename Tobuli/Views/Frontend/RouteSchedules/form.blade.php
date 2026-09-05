@php
    /** @var \Tobuli\Entities\RouteSchedule|null $item */
    $item = $item ?? null;

    $diasElegidos = $item ? $item->weekdays_list : [1, 2, 3, 4, 5];
    $vehiculos    = $item ? $item->devices->pluck('id')->all() : [];

    // El campo llega de MySQL como 08:00:00; el input de hora quiere 08:00
    $recorta = function ($hora) {
        return $hora ? substr($hora, 0, 5) : null;
    };
@endphp

<div class="form-group">
    {!! Form::label('name', trans('validation.attributes.name') . ':') !!}
    {!! Form::text('name', $item->name ?? null, ['class' => 'form-control']) !!}
</div>

<div class="form-group">
    {!! Form::label('route_id', trans('front.route') . ':') !!}
    {!! Form::select('route_id', $routes, $item->route_id ?? null, ['class' => 'form-control', 'data-live-search' => 'true']) !!}
</div>

<div class="form-group">
    {!! Form::label('devices', trans('front.route_schedule_devices') . ':') !!}
    {!! Form::select('devices[]', $devices, $vehiculos, [
        'class'             => 'form-control',
        'multiple'          => 'multiple',
        'data-live-search'  => 'true',
        'data-actions-box'  => 'true',
    ]) !!}
</div>

<div class="form-group">
    {!! Form::label('weekdays', trans('front.route_weekdays') . ':') !!}
    <div>
        @foreach ($days as $iso => $nombre)
            <label class="checkbox-inline">
                <input type="checkbox" name="weekdays[]" value="{{ $iso }}"
                       {{ in_array($iso, $diasElegidos, true) ? 'checked' : '' }}>
                {{ $nombre }}
            </label>
        @endforeach
    </div>
</div>

<div class="row">
    <div class="col-xs-6">
        <div class="form-group">
            {!! Form::label('start_time', trans('front.route_start_time') . ':') !!}
            {!! Form::input('time', 'start_time', $recorta($item->start_time ?? '07:00:00'), ['class' => 'form-control']) !!}
        </div>
    </div>
    <div class="col-xs-6">
        <div class="form-group">
            {!! Form::label('return_start_time', trans('front.route_return_start_time') . ':') !!}
            {!! Form::input('time', 'return_start_time', $recorta($item->return_start_time ?? null), ['class' => 'form-control']) !!}
            <span class="help-block">{{ trans('front.route_return_hint') }}</span>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-6">
        <div class="form-group">
            {!! Form::label('tolerance_early', trans('front.route_tolerance_early') . ':') !!}
            {!! Form::number('tolerance_early', $item->tolerance_early ?? 5, ['class' => 'form-control', 'min' => 0]) !!}
        </div>
    </div>
    <div class="col-xs-6">
        <div class="form-group">
            {!! Form::label('tolerance_late', trans('front.route_tolerance_late') . ':') !!}
            {!! Form::number('tolerance_late', $item->tolerance_late ?? 10, ['class' => 'form-control', 'min' => 0]) !!}
        </div>
    </div>
</div>

<div class="form-group">
    <label class="checkbox-inline">
        <input type="checkbox" name="active" value="1" {{ ($item->active ?? true) ? 'checked' : '' }}>
        {{ trans('validation.attributes.active') }}
    </label>
</div>
