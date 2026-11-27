{{--
    Tab Exactitud — paridad con Frontend/Devices/partials/accuracy.blade.php
    (con detectionFields): valid_by_avg_speed, engine_hours (detección de
    encendido), detect_engine, detect_speed, max_speed (checkbox habilitador
    enable_max_speed), min_moving_speed, min_fuel_fillings, min_fuel_thefts,
    fuel_detect_sec_after_stop (checkbox enable_*) y lbs. Mismos names.
--}}
@php
    $eh = !empty($engine_hours) ? $engine_hours : [];
    $de = !empty($detect_engine) ? $detect_engine : [];
    $ds = !empty($detect_speed) ? $detect_speed : [];
    $stops = $fuel_detect_sec_after_stop_options ?? [];

    $validAvg = isset($item->valid_by_avg_speed) ? (bool) $item->valid_by_avg_speed : true;
    $lbs = (bool) ($item->lbs ?? false);
    $hasMaxSpeed = (bool) ($item->max_speed ?? false);
    $hasFuelStop = (bool) ($item->fuel_detect_sec_after_stop ?? false);
    // Paridad legacy: los 3 selects de detección (engine_hours/detect_engine/
    // detect_speed) solo se muestran al EDITAR (accuracy.blade → detectionFields
    // que edit.blade pasa como true; en crear el device aún no tiene sensores).
    $isEdit = !empty($item->id);
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field tp-form__field--checkbox tp-col-2">
        <input type="hidden" name="valid_by_avg_speed" value="0">
        <input type="checkbox" name="valid_by_avg_speed" value="1" {{ $validAvg ? 'checked' : '' }}>
        <span class="tp-form__label">{{ trans('front.valid_by_avg_speed') }}</span>
    </label>

    @if ($isEdit)
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.ignition_detection') }}</span>
            <select class="tp-input" style="width: 100%" name="engine_hours" data-tp-field>
                @foreach ($eh as $k => $v)
                    <option value="{{ $k }}" {{ ($item->engine_hours ?? 'off') == $k ? 'selected' : '' }}>
                        {{ $v }}</option>
                @endforeach
            </select>
        </label>

        {{-- detect_engine solo aplica si "ignición" = sensor engine_hours;
             device-modal.js (wireIgnitionEngine) lo muestra/oculta según
             engine_hours — paridad legacy .ignition_detection_engine. --}}
        <label class="tp-form__field" data-ignition-engine>
            <span class="tp-form__label">{{ trans('validation.attributes.detect_engine') }}</span>
            <select class="tp-input" style="width: 100%" name="detect_engine" data-tp-field>
                @foreach ($de as $k => $v)
                    <option value="{{ $k }}" {{ ($item->detect_engine ?? 'off') == $k ? 'selected' : '' }}>
                        {{ $v }}</option>
                @endforeach
            </select>
        </label>

        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.detect_speed') }}</span>
            <select class="tp-input" style="width: 100%" name="detect_speed" data-tp-field>
                @foreach ($ds as $k => $v)
                    <option value="{{ $k }}" {{ ($item->detect_speed ?? 'off') == $k ? 'selected' : '' }}>
                        {{ $v }}</option>
                @endforeach
            </select>
        </label>
    @endif

    {{-- Gate de permiso (paridad legacy accuracy.blade): visible solo con
         can('view', ..., 'max_speed'); bloqueado si no puede editar. --}}
    @if (Auth::user()->can('view', $item, 'max_speed'))
        @php $canEditMax = Auth::user()->can('edit', $item, 'max_speed'); @endphp
        <div class="tp-form__field">
            <span class="tp-form__label" id="tp-lbl-maxspeed">{{ trans('validation.attributes.max_speed') }}</span>
            <div style="display: flex; gap: 9px; align-items: center;">
                <input type="hidden" name="max_speed" value="">
                <input aria-labelledby="tp-lbl-maxspeed" type="checkbox" name="enable_max_speed" value="1" id="tp-dev-maxspeed"
                    {{ $hasMaxSpeed ? 'checked' : '' }} {{ $canEditMax ? '' : 'disabled' }}>
                <input type="number" min="0" step="1" name="max_speed" value="{{ $item->max_speed ?? '' }}"
                    data-enable="tp-dev-maxspeed" {{ $hasMaxSpeed && $canEditMax ? '' : 'disabled' }} style="flex: 1;"
                    data-tp-field>
            </div>
        </div>
    @endif

    <label class="tp-form__field">
        <span class="tp-form__label">{{ trans('validation.attributes.min_moving_speed') }}
            ({{ trans('front.affects_stops_track', ['default' => 6]) }})</span>
        <input type="number" min="0" step="1" name="min_moving_speed"
            value="{{ $item->min_moving_speed ?? 6 }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">{{ trans('validation.attributes.min_fuel_fillings') }}
            ({{ trans('front.default_value', ['default' => 10]) }})</span>
        <input type="number" min="0" step="0.1" name="min_fuel_fillings"
            value="{{ $item->min_fuel_fillings ?? 10 }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">{{ trans('validation.attributes.min_fuel_thefts') }}
            ({{ trans('front.default_value', ['default' => 10]) }})</span>
        <input type="number" min="0" step="0.1" name="min_fuel_thefts"
            value="{{ $item->min_fuel_thefts ?? 10 }}" data-tp-field>
    </label>

    <div class="tp-form__field">
        <span class="tp-form__label" id="tp-lbl-fuelstop">{{ trans('validation.attributes.fuel_detect_sec_after_stop') }}</span>
        <div style="display: flex; gap: 9px; align-items: center;">
            <input type="hidden" name="fuel_detect_sec_after_stop" value="">
            <input aria-labelledby="tp-lbl-fuelstop" type="checkbox" name="enable_fuel_detect_sec_after_stop" value="1" id="tp-dev-fuelstop"
                {{ $hasFuelStop ? 'checked' : '' }}>
            <select class="tp-input" name="fuel_detect_sec_after_stop" data-enable="tp-dev-fuelstop"
                {{ $hasFuelStop ? '' : 'disabled' }} style="flex: 1; width: 100%;" data-tp-field>
                @foreach ($stops as $k => $v)
                    <option value="{{ $k }}"
                        {{ (string) ($item->fuel_detect_sec_after_stop ?? 0) === (string) $k ? 'selected' : '' }}>
                        {{ $v }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Gate de permiso (paridad legacy): LBS visible solo con can('view', ...,
         'lbs') → config('addon.lbs'); si no puede editar, checkbox deshabilitado. --}}
    @if (Auth::user()->can('view', $item, 'lbs'))
        <div class="tp-col-2">
            <label class="tp-form__field tp-form__field--checkbox">
                @if (Auth::user()->can('edit', $item, 'lbs'))
                    <input type="hidden" name="lbs" value="0">
                    <input type="checkbox" name="lbs" value="1" {{ $lbs ? 'checked' : '' }}>
                @else
                    <input type="checkbox" name="lbs" value="1" {{ $lbs ? 'checked' : '' }} disabled>
                @endif
                <span class="tp-form__label">{{ trans('validation.attributes.lbs') }}</span>
            </label>
            <small class="tp-form__hint">{!! trans('front.lbs_explanation') !!}</small>
        </div>
    @endif
</div>
