<div class="form-group">
    {!! Form::label('group_id', trans('validation.attributes.group_id') . ':') !!}
    {!! Form::select('group_id', $device_groups, $group_id ?? null, [
        'class' => 'form-control',
        'data-live-search' => 'true',
    ]) !!}
</div>

@if (Auth::user()->can('view', $item, 'device_type_id'))
    <div class="form-group">
        {!! Form::label('device_type_id', trans('validation.attributes.device_type_id') . ':') !!}
        {!! Form::select(
            'device_type_id',
            $device_types,
            $item->device_type_id,
            ['class' => 'form-control'] +
                (!Auth::user()->can('edit', $item, 'device_type_id') ? ['disabled' => 'disabled'] : []),
        ) !!}
    </div>
@endif

@if (isset($vehicle_types) && $vehicle_types->isNotEmpty())
    <div class="form-group">
        {!! Form::label('vehicle_type_id', trans('validation.attributes.vehicle_type_id') . ':') !!}
        {!! Form::select(
            'vehicle_type_id',
            ['' => trans('front.none')] + $vehicle_types->pluck('name', 'id')->all(),
            $item->vehicle_type_id,
            ['class' => 'form-control'],
        ) !!}
    </div>

    <div class="form-group">
        {!! Form::label('vehicle_state_id', trans('validation.attributes.vehicle_state_id') . ':') !!}
        {!! Form::select(
            'vehicle_state_id',
            ['' => trans('front.no_vehicle_state')] + $vehicle_types->mapWithKeys(function ($vehicleType) {
                return [$vehicleType->name => $vehicleType->states->pluck('name', 'id')->all()];
            })->filter()->all(),
            $item->vehicle_state_id,
            ['class' => 'form-control'],
        ) !!}
    </div>
@endif

@if (Auth::user()->can('view', $item, 'authentication'))
    <div class="form-group">
        {!! Form::label('authentication', trans('validation.attributes.authentication') . ':') !!}
        {!! Form::text(
            'authentication',
            $item->authentication,
            ['class' => 'form-control'] + (Auth::user()->can('edit', $item, 'authentication') ? [] : ['disabled']),
        ) !!}
    </div>
@endif

@php $additional_fields_on = settings('plugins.additional_installation_fields.status') @endphp

<div class="row">
    <div class="col-sm-6">
        @if (Auth::user()->can('view', $item, 'sim_number') && empty($multiItems))
            <div class="form-group">
                {!! Form::label('sim_number', trans('validation.attributes.sim_number') . ':') !!}
                {!! Form::text(
                    'sim_number',
                    $item->sim_number,
                    ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'sim_number') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif

        <div class="form-group">
            {!! Form::label('operator_sim', 'Operador SIM:') !!}
            {!! Form::select(
                'operator_sim',
                [
                    '' => '-- Sin operador --',
                    'M2M' => 'M2M',
                    'CUY' => 'CUY',
                    'IOT' => 'IOT',
                    'MOVISTAR' => 'MOVISTAR',
                    'CLARO' => 'CLARO',
                    'ENTEL' => 'ENTEL',
                    'BITEL' => 'BITEL',
                ],
                $item->operator_sim,
                ['class' => 'form-control'],
            ) !!}
        </div>

        @if (settings('plugins.sim_blocking.status') && Auth::user()->can('view', $item, 'msisdn'))
            <div class="form-group">
                {!! Form::label('msisdn', trans('validation.attributes.msisdn') . ':') !!}
                {!! Form::text(
                    'msisdn',
                    $item->msisdn,
                    ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'msisdn') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif

        @if ($additional_fields_on && Auth::user()->can('view', $item, 'sim_activation_date'))
            <div class="form-group">
                {!! Form::label('sim_activation_date', trans('validation.attributes.sim_activation_date') . ':') !!}
                {!! Form::text(
                    'sim_activation_date',
                    $item->sim_activation_date == '0000-00-00' ? null : $item->sim_activation_date,
                    ['class' => 'form-control datepicker'] +
                        (!Auth::user()->can('edit', $item, 'sim_activation_date') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif

        @if ($additional_fields_on && Auth::user()->can('view', $item, 'sim_expiration_date'))
            <div class="form-group">
                {!! Form::label('sim_expiration_date', trans('validation.attributes.sim_expiration_date') . ':') !!}
                {!! Form::text(
                    'sim_expiration_date',
                    $item->sim_expiration_date == '0000-00-00' ? null : $item->sim_expiration_date,
                    ['class' => 'form-control datepicker'] +
                        (!Auth::user()->can('edit', $item, 'sim_expiration_date') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
        @if (Auth::user()->can('view', $item, 'vin'))
            <div class="form-group">
                {!! Form::label('vin', trans('validation.attributes.vin') . ':') !!}
                {!! Form::text(
                    'vin',
                    $item->vin,
                    ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'vin') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
        @if (Auth::user()->can('view', $item, 'device_model'))
            <div class="form-group">
                {!! Form::label('device_model', trans('validation.attributes.device_model') . ':') !!}
                {!! Form::text(
                    'device_model',
                    $item->device_model,
                    ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'device_model') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
    </div>
    <div class="col-sm-6">
        @if ($additional_fields_on && Auth::user()->can('view', $item, 'installation_date'))
            <div class="form-group">
                {!! Form::label('installation_date', trans('validation.attributes.installation_date') . ':') !!}
                {!! Form::text(
                    'installation_date',
                    $item->installation_date == '0000-00-00' ? null : $item->installation_date,
                    ['class' => 'form-control datepicker'] +
                        (!Auth::user()->can('edit', $item, 'installation_date') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
        @if (Auth::user()->can('view', $item, 'plate_number'))
            <div class="form-group">
                {!! Form::label('plate_number', trans('validation.attributes.plate_number') . ':') !!}
                {!! Form::text(
                    'plate_number',
                    $item->plate_number,
                    ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'plate_number') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
        @if (Auth::user()->can('view', $item, 'registration_number'))
            <div class="form-group">
                {!! Form::label('registration_number', trans('validation.attributes.registration_number') . ':') !!}
                {!! Form::text(
                    'registration_number',
                    $item->registration_number,
                    ['class' => 'form-control'] +
                        (!Auth::user()->can('edit', $item, 'registration_number') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
        @if (Auth::user()->can('view', $item, 'object_owner'))
            <div class="form-group">
                {!! Form::label('object_owner', trans('validation.attributes.object_owner') . ':') !!}
                {!! Form::text(
                    'object_owner',
                    $item->object_owner,
                    ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'object_owner') ? ['disabled' => 'disabled'] : []),
                ) !!}
            </div>
        @endif
    </div>
</div>
@if (Auth::user()->can('view', $item, 'additional_notes'))
    <div class="form-group">
        {!! Form::label('additional_notes', trans('validation.attributes.additional_notes') . ':') !!}
        {!! Form::text(
            'additional_notes',
            $item->additional_notes,
            ['class' => 'form-control'] +
                (!Auth::user()->can('edit', $item, 'additional_notes') ? ['disabled' => 'disabled'] : []),
        ) !!}
    </div>
@endif
@if (Auth::user()->can('view', $item, 'comment'))
    <div class="form-group">
        {!! Form::label('comment', trans('validation.attributes.comment') . ':') !!}
        {!! Form::text(
            'comment',
            $item->comment,
            ['class' => 'form-control'] + (!Auth::user()->can('edit', $item, 'comment') ? ['disabled' => 'disabled'] : []),
        ) !!}
    </div>
@endif
@if (config('addon.device_tracker_app_login'))
    <div class="form-group">
        <div class="checkbox-inline">
            {!! Form::hidden('app_tracker_login', 0) !!}
            {!! Form::checkbox('app_tracker_login', 1, $item->app_tracker_login) !!}
            {!! Form::label(null, trans('validation.attributes.app_tracker_login')) !!}
        </div>
    </div>
@endif
<div class="form-group">
    <div class="checkbox">
        {!! Form::hidden('gprs_templates_only', 0) !!}
        {!! Form::checkbox('gprs_templates_only', 1, $item->gprs_templates_only) !!}
        {!! Form::label('gprs_templates_only', trans('validation.attributes.gprs_templates_only')) !!}
    </div>
</div>
<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            {!! Form::label('fuel_measurement_id', trans('validation.attributes.fuel_measurement_type') . ':') !!}
            {!! Form::select('fuel_measurement_id', $device_fuel_measurements_select, $item->fuel_measurement_id, [
                'class' => 'form-control',
            ]) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label for="fuel_quantity">
                <span class="fuel_title"></span> {!! trans('front.per') !!} <span class="distance_title"></span>:
            </label>
            {!! Form::text('fuel_quantity', $item->fuel_quantity, [
                'class' => 'form-control',
                'placeholder' => '0.00',
                'id' => 'fuel_quantity',
            ]) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label for="fuel_price">
                {!! trans('front.cost_for') !!} <span class="cost_title"></span>:
            </label>
            {!! Form::text('fuel_price', $item->fuel_price, [
                'class' => 'form-control',
                'placeholder' => '0.00',
                'id' => 'fuel_price',
            ]) !!}
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            {!!Form::label('fuel_type', trans('validation.attributes.fuel_type').':')!!}
            {!!Form::select('fuel_type', $fuel_types, $item->fuel_type, ['class' => 'form-control'])!!}
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            {!!Form::label('fuel_emissions', trans('validation.attributes.fuel_emissions').':')!!}
            {!!Form::text('fuel_emissions', $item->fuel_emissions, ['class' => 'form-control', 'placeholder' => '0.00'])!!}
        </div>
    </div>
</div>

@if (Auth::user()->can('view', $item, 'forward'))
    <div class="form-group">
        {!! Form::label(null, trans('validation.attributes.forward') . ':') !!}
        <div class="input-group">
            <div class="checkbox input-group-btn">
                {!! Form::hidden('forward[active]', 0) !!}
                {!! Form::checkbox('forward[active]', 1, \Illuminate\Support\Arr::get($item->forward, 'active')) !!}
                {!! Form::label(null) !!}
            </div>
            {!! Form::text(
                'forward[ip]',
                \Illuminate\Support\Arr::get($item->forward, 'ip'),
                array_merge(
                    ['class' => 'form-control', 'placeholder' => '10.0.0.0:6000'],
                    Auth::user()->can('edit', $item, 'forward') ? [] : ['readonly' => true],
                ),
            ) !!}
            <div class="input-group-addon">
                <div class="checkbox-inline">
                    {!! Form::radio('forward[protocol]', 'TCP', \Illuminate\Support\Arr::get($item->forward, 'protocol') != 'UDP') !!}
                    {!! Form::label(null, 'TCP') !!}
                </div>
                <div class="checkbox-inline">
                    {!! Form::radio('forward[protocol]', 'UDP', \Illuminate\Support\Arr::get($item->forward, 'protocol') == 'UDP') !!}
                    {!! Form::label(null, 'UDP') !!}
                </div>
            </div>
        </div>
        <small>{!! trans('front.forward_semicolon') !!}</small>
    </div>
@endif

<div class="form-group">
    {!! Form::label('timezone_id', trans('validation.attributes.time_adjustment') . ':') !!}
    {!! Form::select('timezone_id', $timezones, empty($timezone_id) ? 0 : $timezone_id, ['class' => 'form-control']) !!}
    <small>{!! trans('front.by_default_time') !!}</small>
</div>

{{-- ================= Colores del recorrido (historial) =================
     Propiedad del vehículo, estilo Wialon: trips | single | speed | sensor.
     OJO estructura: el tema estiliza .radio con el input ABSOLUTO cubriendo
     el contenedor, así que el radio va SOLO (input+label) y los controles
     fuera, en la misma fila (horizontal). --}}
@php
    $rcType = $item->route_color_type ?? 'trips';
    $rcSingle = $item->route_color ?: '#2563eb';
    $rcRanges = json_decode($item->route_speed_ranges ?? '', true);
    if (empty($rcRanges) || !is_array($rcRanges)) {
        $rcRanges = [
            ['from' => 0, 'to' => 40, 'color' => '#22d3ee'],
            ['from' => 40, 'to' => 60, 'color' => '#3b82f6'],
            ['from' => 60, 'to' => 90, 'color' => '#6366f1'],
            ['from' => 90, 'to' => 120, 'color' => '#a855f7'],
            ['from' => 120, 'to' => null, 'color' => '#ef4444'],
        ];
    }
@endphp
<div class="form-group" id="route-colors-group">
    {!! Form::label(null, 'Colores del recorrido — Historial:') !!}

    {{-- Por viajes --}}
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <div class="radio" style="min-width:130px;">
            <input type="radio" name="route_color_type" value="trips" {{ $rcType === 'trips' ? 'checked' : '' }}>
            <label>Por viajes</label>
        </div>
    </div>

    {{-- Único --}}
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <div class="radio" style="min-width:130px;">
            <input type="radio" name="route_color_type" value="single" {{ $rcType === 'single' ? 'checked' : '' }}>
            <label>Único</label>
        </div>
        <input type="color" name="route_color" value="{{ $rcSingle }}"
            style="width:36px;height:24px;padding:0;border:1px solid #ccc;border-radius:4px;cursor:pointer;">
    </div>

    {{-- Por velocidad --}}
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
        <div class="radio" style="min-width:130px;">
            <input type="radio" name="route_color_type" value="speed" {{ $rcType === 'speed' ? 'checked' : '' }}>
            <label>Por velocidad</label>
        </div>
        <div id="rc-bar" style="display:flex;flex:1 1 auto;max-width:260px;height:10px;border-radius:6px;overflow:hidden;border:1px solid rgba(0,0,0,.08);"></div>
        <button type="button" class="btn btn-xs btn-default" id="rc-add-range" title="Añadir rango"
            style="color:#16a34a;font-weight:700;">+</button>
        <button type="button" class="btn btn-xs btn-default" id="rc-reset-ranges" title="Restaurar por defecto">&#8634;</button>
    </div>
    {{-- Rangos en línea (horizontal, con wrap) --}}
    <div id="rc-ranges" style="display:flex;flex-wrap:wrap;gap:6px 14px;margin:2px 0 8px 24px;"></div>

    {{-- Por sensor --}}
    <div style="display:flex;align-items:center;gap:10px;opacity:.55;">
        <div class="radio" style="min-width:130px;">
            <input type="radio" name="route_color_type" value="sensor" disabled {{ $rcType === 'sensor' ? 'checked' : '' }}>
            <label>Por sensor</label>
        </div>
        <small>(requiere un sensor con rangos de colores por intervalo)</small>
    </div>

    <input type="hidden" name="route_speed_ranges" id="rc-json" value="{{ e(json_encode($rcRanges)) }}">
</div>

<script>
    (function () {
        var $rows = $('#rc-ranges'), $json = $('#rc-json'), $bar = $('#rc-bar');
        var DEFAULTS = [
            { from: 0, to: 40, color: '#22d3ee' },
            { from: 40, to: 60, color: '#3b82f6' },
            { from: 60, to: 90, color: '#6366f1' },
            { from: 90, to: 120, color: '#a855f7' },
            { from: 120, to: null, color: '#ef4444' }
        ];
        var ranges;
        try { ranges = JSON.parse($json.val() || '[]'); } catch (e) { ranges = []; }
        if (!ranges.length) ranges = DEFAULTS.slice();

        function normalize() {
            ranges.sort(function (a, b) { return (a.from || 0) - (b.from || 0); });
            var from = 0;
            $.each(ranges, function (i, r) {
                r.from = from;
                if (i === ranges.length - 1) { r.to = null; }
                else {
                    var to = parseFloat(r.to);
                    if (isNaN(to) || to <= from) to = from + 10;
                    r.to = to; from = to;
                }
            });
        }
        function render() {
            normalize();
            $json.val(JSON.stringify(ranges));
            $rows.empty();
            $.each(ranges, function (i, r) {
                var last = i === ranges.length - 1;
                var $row = $('<span style="display:inline-flex;align-items:center;gap:4px;">');
                $row.append($('<input type="number" class="form-control input-sm" disabled style="width:62px;display:inline-block;">').val(r.from));
                $row.append('<span>&ndash;</span>');
                if (last) $row.append('<span style="min-width:20px;text-align:center;">&infin;</span>');
                else $row.append($('<input type="number" class="form-control input-sm rc-to" data-i="' + i + '" style="width:62px;display:inline-block;">').val(r.to));
                $row.append($('<input type="color" class="rc-color" data-i="' + i + '" style="width:34px;height:24px;padding:0;border:1px solid #ccc;border-radius:4px;cursor:pointer;">').val(r.color || '#2563eb'));
                if (ranges.length > 1) $row.append('<button type="button" class="btn btn-xs btn-default rc-del" data-i="' + i + '">&times;</button>');
                $rows.append($row);
            });
            // Barra preview proporcional
            $bar.empty();
            var maxB = ranges.length > 1 ? ranges[ranges.length - 1].from : 100;
            var scale = maxB > 0 ? maxB : 100;
            $.each(ranges, function (i, r) {
                var last = i === ranges.length - 1;
                var w = last ? 18 : Math.max(6, ((r.to - r.from) / scale) * 82);
                $bar.append('<span style="flex:0 0 ' + w + '%;background:' + (r.color || '#2563eb') + ';"></span>');
            });
        }
        function selectSpeed() {
            $('input[name="route_color_type"][value="speed"]').prop('checked', true);
        }
        $rows.on('change', '.rc-to', function () { ranges[$(this).data('i')].to = parseFloat($(this).val()); selectSpeed(); render(); });
        $rows.on('change', '.rc-color', function () { ranges[$(this).data('i')].color = $(this).val(); selectSpeed(); render(); });
        $rows.on('click', '.rc-del', function () { ranges.splice($(this).data('i'), 1); selectSpeed(); render(); });
        $('#rc-add-range').on('click', function () {
            var lastFrom = ranges.length > 1 ? ranges[ranges.length - 1].from : 0;
            ranges.splice(ranges.length - 1, 0, { from: lastFrom, to: lastFrom + 20, color: '#94a3b8' });
            selectSpeed(); render();
        });
        $('#rc-reset-ranges').on('click', function () {
            ranges = $.map(DEFAULTS, function (r) { return $.extend({}, r); });
            render();
        });
        render();
    })();
</script>
