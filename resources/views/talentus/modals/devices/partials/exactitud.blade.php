@php
    // Catálogos vienen del helper legacy ($engine_hours, $detect_engine, $detect_speed son
    // arrays asociativos [id => label]). Fallback a opciones genéricas si vinieran vacíos.
    $eh = !empty($engine_hours) ? $engine_hours : ['off' => 'Desactivado'];
    $de = !empty($detect_engine) ? $detect_engine : ['off' => 'Desactivado'];
    $ds = !empty($detect_speed) ? $detect_speed : ['off' => 'Desactivado'];
    $stops = $fuel_detect_sec_after_stop_options ?? [0 => 'Desactivado'];

    $validAvg = isset($item->valid_by_avg_speed) ? (bool) $item->valid_by_avg_speed : true;
    $lbs = (bool) ($item->lbs ?? false);
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field tp-form__field--checkbox tp-col-2">
        <input type="hidden" name="valid_by_avg_speed" value="0">
        <input type="checkbox" name="valid_by_avg_speed" value="1" {{ $validAvg ? 'checked' : '' }}>
        <span class="tp-form__label">Validar posiciones por velocidad promedio</span>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Detección de horas de motor</span>
        <select name="engine_hours" data-tp-field>
            @foreach ($eh as $k => $v)
                <option value="{{ $k }}" {{ ($item->engine_hours ?? 'off') == $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Detección de encendido</span>
        <select name="detect_engine" data-tp-field>
            @foreach ($de as $k => $v)
                <option value="{{ $k }}" {{ ($item->detect_engine ?? 'off') == $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Detección de velocidad</span>
        <select name="detect_speed" data-tp-field>
            @foreach ($ds as $k => $v)
                <option value="{{ $k }}" {{ ($item->detect_speed ?? 'off') == $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Velocidad máxima (km/h)</span>
        <input type="number" min="0" step="1" name="max_speed" value="{{ $item->max_speed ?? 0 }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Vel. mín. de movimiento (km/h)</span>
        <input type="number" min="0" step="1" name="min_moving_speed" value="{{ $item->min_moving_speed ?? 6 }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Mín. carga combustible (L)</span>
        <input type="number" min="0" step="0.1" name="min_fuel_fillings" value="{{ $item->min_fuel_fillings ?? 10 }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Mín. robo combustible (L)</span>
        <input type="number" min="0" step="0.1" name="min_fuel_thefts" value="{{ $item->min_fuel_thefts ?? 10 }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Retraso detección tras detención</span>
        <select name="fuel_detect_sec_after_stop" data-tp-field>
            @foreach ($stops as $k => $v)
                <option value="{{ $k }}" {{ (string) ($item->fuel_detect_sec_after_stop ?? 0) === (string) $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field tp-form__field--checkbox tp-col-2">
        <input type="hidden" name="lbs" value="0">
        <input type="checkbox" name="lbs" value="1" {{ $lbs ? 'checked' : '' }}>
        <span class="tp-form__label">Usar posicionamiento LBS si no hay GPS</span>
    </label>
</div>
