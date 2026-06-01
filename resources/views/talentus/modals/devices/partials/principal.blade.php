@php
    $isActive = $item->active ?? true;
    $expirationDate = $item->expiration_date ?? null;
    $expValue = $expirationDate ? \Illuminate\Support\Str::of((string) $expirationDate)->substr(0, 10) : '';
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field tp-form__field--checkbox tp-col-2">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" {{ $isActive ? 'checked' : '' }}>
        <span class="tp-form__label">Activo</span>
    </label>

    <label class="tp-form__field tp-form__field--required">
        <span class="tp-form__label">Nombre</span>
        <input type="text" name="name" required maxlength="80"
               value="{{ $item->name ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field tp-form__field--required">
        <span class="tp-form__label">IMEI</span>
        <input type="text" name="imei" required maxlength="32" pattern="[0-9A-Za-z\-]+"
               value="{{ $item->imei ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Modelo (texto libre)</span>
        <input type="text" name="device_model" maxlength="80"
               value="{{ $item->device_model ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Expiración</span>
        <input type="date" name="expiration_date" value="{{ $expValue }}" data-tp-field>
        <input type="hidden" name="enable_expiration_date" value="{{ $expValue ? 1 : 0 }}" data-tp-mirror-expiration>
        <small class="tp-form__hint">Déjalo en blanco para ilimitada.</small>
    </label>
</div>
