@php
    $groups = $device_groups ?? [];
    $types = $device_types ?? [];
    $tzs = $timezones ?? [];
    $selGroup = $group_id ?? ($item->group_id ?? null);
    $selType = $item->device_type_id ?? null;
    $selTz = $timezone_id ?? ($item->timezone_id ?? null);

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

    $f = function ($v) {
        return $v ? \Illuminate\Support\Str::of((string) $v)->substr(0, 10) : '';
    };
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field">
        <span class="tp-form__label">Grupo</span>
        <select class="tp-input" style="width: 100%" name="group_id" data-tp-field>
            @foreach ($groups as $gid => $gtitle)
                <option value="{{ $gid }}" {{ (string) $selGroup === (string) $gid ? 'selected' : '' }}>
                    {{ $gtitle }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Tipo de equipo</span>
        <select class="tp-input" style="width: 100%" name="device_type_id" data-tp-field>
            @foreach ($types as $tid => $ttitle)
                <option value="{{ $tid }}" {{ (string) $selType === (string) $tid ? 'selected' : '' }}>
                    {{ $ttitle }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Zona horaria</span>
        <select class="tp-input" style="width: 100%" name="timezone_id" data-tp-field>
            @foreach ($tzs as $tzid => $tzlabel)
                <option value="{{ $tzid }}" {{ (string) $selTz === (string) $tzid ? 'selected' : '' }}>
                    {{ $tzlabel }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">SIM</span>
        <input type="text" name="sim_number" maxlength="30" value="{{ $item->sim_number ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Operador SIM</span>
        <select class="tp-input" style="width: 100%" name="operator_sim" data-tp-field>
            @foreach ($operatorSimOptions as $k => $v)
                <option value="{{ $k }}" {{ ($item->operator_sim ?? '') === $k ? 'selected' : '' }}>
                    {{ $v }}</option>
            @endforeach
        </select>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">MSISDN</span>
        <input type="text" name="msisdn" maxlength="30" value="{{ $item->msisdn ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">SIM activación</span>
        <input type="date" name="sim_activation_date" value="{{ $f($item->sim_activation_date ?? null) }}"
            data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">SIM expiración</span>
        <input type="date" name="sim_expiration_date" value="{{ $f($item->sim_expiration_date ?? null) }}"
            data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">VIN</span>
        <input type="text" name="vin" maxlength="32" value="{{ $item->vin ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Placa</span>
        <input type="text" name="plate_number" maxlength="20" value="{{ $item->plate_number ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">N° de registro</span>
        <input type="text" name="registration_number" maxlength="40" value="{{ $item->registration_number ?? '' }}"
            data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Propietario</span>
        <input type="text" name="object_owner" maxlength="80" value="{{ $item->object_owner ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Instalación</span>
        <input type="date" name="installation_date" value="{{ $f($item->installation_date ?? null) }}"
            data-tp-field>
    </label>

    <label class="tp-form__field tp-col-2">
        <span class="tp-form__label">Notas adicionales</span>
        <textarea name="additional_notes" rows="2" maxlength="500" data-tp-field>{{ $item->additional_notes ?? '' }}</textarea>
    </label>

    <label class="tp-form__field tp-col-2">
        <span class="tp-form__label">Comentarios</span>
        <textarea name="comment" rows="2" maxlength="500" data-tp-field>{{ $item->comment ?? '' }}</textarea>
    </label>
</div>
