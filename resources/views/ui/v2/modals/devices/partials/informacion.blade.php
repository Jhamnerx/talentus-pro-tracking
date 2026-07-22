{{--
    Tab Información (Avanzado) — paridad con
    Frontend/Devices/partials/advanced.blade.php: mismos campos, orden y
    estructura (grupo, tipo, tipo/estado de vehículo, autenticación,
    2 columnas SIM/instalación, notas, comentario, app_tracker_login,
    gprs_templates_only, combustible con labels dinámicos, reenvío y
    ajuste de hora). Gating por campo con can() cuando hay entidad.
--}}
@php
    $dev = ($item ?? null) instanceof \Tobuli\Entities\Device ? $item : null;
    $cv = function ($f) use ($dev) {
        return !$dev || Auth::user()->can('view', $dev, $f);
    };

    $groups = $device_groups ?? [];
    $types = $device_types ?? [];
    $tzs = $timezones ?? [];
    $fuelSelect = $device_fuel_measurements_select ?? [];
    $fuelMeasurements = $device_fuel_measurements ?? [];
    $vehicleTypes = $vehicle_types ?? collect();

    $selGroup = $group_id ?? ($item->group_id ?? null);
    $selTz = $timezone_id ?? ($item->timezone_id ?? 0);

    $additionalFieldsOn = settings('plugins.additional_installation_fields.status');
    $simBlockingOn = settings('plugins.sim_blocking.status');

    $operatorSimOptions = [
        '' => '-- Sin operador --',
        'M2M' => 'M2M',
        'CUY' => 'CUY',
        'IOT' => 'IOT',
        'MOVISTAR' => 'MOVISTAR',
        'CLARO' => 'CLARO',
        'ENTEL' => 'ENTEL',
        'BITEL' => 'BITEL',
    ];

    $d = function ($v) {
        $v = (string) ($v ?? '');
        return $v === '' || substr($v, 0, 4) === '0000' ? '' : substr($v, 0, 10);
    };

    $forward = (array) ($item->forward ?? []);
@endphp

<div class="tp-form__grid tp-form__grid--2">

    <label class="tp-form__field tp-col-2">
        <span class="tp-form__label">{{ trans('validation.attributes.group_id') }}</span>
        <select class="tp-input" style="width: 100%" name="group_id" data-tp-field>
            @foreach ($groups as $gid => $gtitle)
                <option value="{{ $gid }}" {{ (string) $selGroup === (string) $gid ? 'selected' : '' }}>{{ $gtitle }}</option>
            @endforeach
        </select>
    </label>

    @if ($cv('device_type_id'))
        <label class="tp-form__field tp-col-2">
            <span class="tp-form__label">{{ trans('validation.attributes.device_type_id') }}</span>
            <select class="tp-input" style="width: 100%" name="device_type_id" data-tp-field>
                @foreach ($types as $tid => $ttitle)
                    <option value="{{ $tid }}" {{ (string) ($item->device_type_id ?? '') === (string) $tid ? 'selected' : '' }}>
                        {{ $ttitle }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @if ($vehicleTypes->isNotEmpty())
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.vehicle_type_id') }}</span>
            <select class="tp-input" style="width: 100%" name="vehicle_type_id" data-tp-field>
                <option value="">{{ trans('front.none') }}</option>
                @foreach ($vehicleTypes as $vt)
                    <option value="{{ $vt->id }}" {{ (string) ($item->vehicle_type_id ?? '') === (string) $vt->id ? 'selected' : '' }}>
                        {{ $vt->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.vehicle_state_id') }}</span>
            <select class="tp-input" style="width: 100%" name="vehicle_state_id" data-tp-field>
                <option value="">{{ trans('front.no_vehicle_state') }}</option>
                @foreach ($vehicleTypes as $vt)
                    @if ($vt->states->isNotEmpty())
                        <optgroup label="{{ $vt->name }}">
                            @foreach ($vt->states as $vs)
                                <option value="{{ $vs->id }}"
                                    {{ (string) ($item->vehicle_state_id ?? '') === (string) $vs->id ? 'selected' : '' }}>
                                    {{ $vs->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                @endforeach
            </select>
        </label>
    @endif

    @if ($cv('authentication'))
        <label class="tp-form__field tp-col-2">
            <span class="tp-form__label">{{ trans('validation.attributes.authentication') }}</span>
            <input type="text" name="authentication" value="{{ $item->authentication ?? '' }}" data-tp-field>
        </label>
    @endif

    {{-- ── Dos columnas (estructura legacy) ── --}}
    @if ($cv('sim_number'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.sim_number') }}</span>
            <input type="text" name="sim_number" maxlength="30" value="{{ $item->sim_number ?? '' }}" data-tp-field>
        </label>
    @endif

    @if ($additionalFieldsOn && $cv('installation_date'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.installation_date') }}</span>
            <input type="date" name="installation_date" value="{{ $d($item->installation_date ?? null) }}" data-tp-field>
        </label>
    @endif

    <label class="tp-form__field">
        <span class="tp-form__label">Operador SIM</span>
        <select class="tp-input" style="width: 100%" name="operator_sim" data-tp-field>
            @foreach ($operatorSimOptions as $k => $v)
                <option value="{{ $k }}" {{ ($item->operator_sim ?? '') === $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </label>

    @if ($cv('plate_number'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.plate_number') }}</span>
            <input type="text" name="plate_number" maxlength="20" value="{{ $item->plate_number ?? '' }}" data-tp-field>
        </label>
    @endif

    @if ($simBlockingOn && $cv('msisdn'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.msisdn') }}</span>
            <input type="text" name="msisdn" maxlength="30" value="{{ $item->msisdn ?? '' }}" data-tp-field>
        </label>
    @endif

    @if ($cv('registration_number'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.registration_number') }}</span>
            <input type="text" name="registration_number" maxlength="40" value="{{ $item->registration_number ?? '' }}"
                data-tp-field>
        </label>
    @endif

    @if ($additionalFieldsOn && $cv('sim_activation_date'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.sim_activation_date') }}</span>
            <input type="date" name="sim_activation_date" value="{{ $d($item->sim_activation_date ?? null) }}" data-tp-field>
        </label>
    @endif

    @if ($cv('object_owner'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.object_owner') }}</span>
            <input type="text" name="object_owner" maxlength="80" value="{{ $item->object_owner ?? '' }}" data-tp-field>
        </label>
    @endif

    @if ($additionalFieldsOn && $cv('sim_expiration_date'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.sim_expiration_date') }}</span>
            <input type="date" name="sim_expiration_date" value="{{ $d($item->sim_expiration_date ?? null) }}" data-tp-field>
        </label>
    @endif

    @if ($cv('vin'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.vin') }}</span>
            <input type="text" name="vin" maxlength="32" value="{{ $item->vin ?? '' }}" data-tp-field>
        </label>
    @endif

    @if ($cv('device_model'))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.device_model') }}</span>
            <input type="text" name="device_model" maxlength="80" value="{{ $item->device_model ?? '' }}" data-tp-field>
        </label>
    @endif

    @if ($cv('additional_notes'))
        <label class="tp-form__field tp-col-2">
            <span class="tp-form__label">{{ trans('validation.attributes.additional_notes') }}</span>
            <input type="text" name="additional_notes" maxlength="500" value="{{ $item->additional_notes ?? '' }}"
                data-tp-field>
        </label>
    @endif

    @if ($cv('comment'))
        <label class="tp-form__field tp-col-2">
            <span class="tp-form__label">{{ trans('validation.attributes.comment') }}</span>
            <input type="text" name="comment" maxlength="500" value="{{ $item->comment ?? '' }}" data-tp-field>
        </label>
    @endif

    @if (config('addon.device_tracker_app_login'))
        <label class="tp-form__field tp-form__field--checkbox tp-col-2">
            <input type="hidden" name="app_tracker_login" value="0">
            <input type="checkbox" name="app_tracker_login" value="1" {{ !empty($item->app_tracker_login) ? 'checked' : '' }}>
            <span class="tp-form__label">{{ trans('validation.attributes.app_tracker_login') }}</span>
        </label>
    @endif

    <label class="tp-form__field tp-form__field--checkbox tp-col-2">
        <input type="hidden" name="gprs_templates_only" value="0">
        <input type="checkbox" name="gprs_templates_only" value="1" {{ !empty($item->gprs_templates_only) ? 'checked' : '' }}>
        <span class="tp-form__label">{{ trans('validation.attributes.gprs_templates_only') }}</span>
    </label>

    {{-- ── Combustible (labels dinámicos según medición) ── --}}
    <div class="tp-col-2" data-tp-fuel>
        <script type="application/json" data-tp-fuel-measurements>@json($fuelMeasurements)</script>
        <div class="tp-form__grid tp-form__grid--3">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.fuel_measurement_type') }}</span>
                <select class="tp-input" style="width: 100%" name="fuel_measurement_id" data-tp-field>
                    @foreach ($fuelSelect as $fid => $ftitle)
                        <option value="{{ $fid }}" {{ (string) ($item->fuel_measurement_id ?? '1') === (string) $fid ? 'selected' : '' }}>
                            {{ $ftitle }}</option>
                    @endforeach
                </select>
            </label>
            <label class="tp-form__field">
                <span class="tp-form__label"><span data-fuel-title></span> {{ trans('front.per') }}
                    <span data-distance-title></span></span>
                <input type="number" step="0.01" name="fuel_quantity" placeholder="0.00"
                    value="{{ $item->fuel_quantity ?? '' }}" data-tp-field>
            </label>
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('front.cost_for') }} <span data-cost-title></span></span>
                <input type="number" step="0.01" name="fuel_price" placeholder="0.00"
                    value="{{ $item->fuel_price ?? '' }}" data-tp-field>
            </label>
        </div>
    </div>

    {{-- ── Reenvío (forward) ── --}}
    @if ($cv('forward'))
        <div class="tp-col-2">
            <div class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.forward') }}</span>
                <div style="display: flex; gap: 9px; align-items: center;">
                    <input type="hidden" name="forward[active]" value="0">
                    <input type="checkbox" name="forward[active]" value="1" id="tp-dev-forward"
                        {{ !empty($forward['active']) ? 'checked' : '' }}>
                    <input type="text" name="forward[ip]" placeholder="10.0.0.0:6000"
                        value="{{ $forward['ip'] ?? '' }}" data-enable="tp-dev-forward"
                        {{ !empty($forward['active']) ? '' : 'disabled' }} style="flex: 1;" data-tp-field>
                    <label class="tp-choice" style="margin: 0;">
                        <input type="radio" class="tp-choice-input" name="forward[protocol]" data-enable="tp-dev-forward" value="TCP"
                            {{ ($forward['protocol'] ?? '') != 'UDP' ? 'checked' : '' }}>
                        <span>TCP</span>
                    </label>
                    <label class="tp-choice" style="margin: 0;">
                        <input type="radio" class="tp-choice-input" name="forward[protocol]" data-enable="tp-dev-forward" value="UDP"
                            {{ ($forward['protocol'] ?? '') == 'UDP' ? 'checked' : '' }}>
                        <span>UDP</span>
                    </label>
                </div>
                <small class="tp-form__hint">{!! trans('front.forward_semicolon') !!}</small>
            </div>
        </div>
    @endif

    {{-- ── Ajuste de hora ── --}}
    <div class="tp-col-2">
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.time_adjustment') }}</span>
            <select class="tp-input" style="width: 100%" name="timezone_id" data-tp-field>
                @foreach ($tzs as $tzid => $tzlabel)
                    <option value="{{ $tzid }}" {{ (string) $selTz === (string) $tzid ? 'selected' : '' }}>{{ $tzlabel }}</option>
                @endforeach
            </select>
            <small class="tp-form__hint">{!! trans('front.by_default_time') !!}</small>
        </label>
    </div>
</div>
