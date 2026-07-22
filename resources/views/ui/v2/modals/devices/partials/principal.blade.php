{{--
    Tab Principal — paridad con Frontend/Devices/partials/main.blade.php:
    active, name, imei, model_id (catálogo GPS $models) y expiración
    (checkbox enable_expiration_date + fecha, solo admin). El campo
    device_model (texto libre) vive en Información, como el legacy.
--}}
@php
    $isActive = $item->active ?? true;
    $expirationDate = $item->expiration_date ?? null;
    $hasExpire = !empty($expirationDate) && substr((string) $expirationDate, 0, 4) !== '0000';
    $expValue = $hasExpire ? \Illuminate\Support\Str::of((string) $expirationDate)->substr(0, 10) : '';
    $isAdminCap = !empty($caps['is_admin']);
@endphp

{{-- Una sola columna: campos apilados a todo el ancho (como la referencia) --}}
<div class="tp-form__grid">
    <label class="tp-form__field tp-form__field--checkbox">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" {{ $isActive ? 'checked' : '' }}>
        <span class="tp-form__label">{{ trans('validation.attributes.active') }}</span>
    </label>

    <label class="tp-form__field tp-form__field--required">
        <span class="tp-form__label">{{ trans('validation.attributes.name') }}</span>
        <input type="text" name="name" required maxlength="80" value="{{ $item->name ?? '' }}" data-tp-field>
    </label>

    <label class="tp-form__field tp-form__field--required">
        <span class="tp-form__label">{{ trans('front.device_imei') }} / {{ trans('front.tracker_id') }}</span>
        <input type="text" name="imei" required maxlength="32" placeholder="{{ trans('front.imei_placeholder') }}"
            value="{{ $item->imei ?? '' }}" data-tp-field>
    </label>

    @if (!empty($models))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.model_id') }}</span>
            <select class="tp-input" style="width: 100%" name="model_id" data-tp-field>
                @foreach ($models as $mid => $mtitle)
                    <option value="{{ $mid }}" {{ (string) ($item->model_id ?? '') === (string) $mid ? 'selected' : '' }}>
                        {{ $mtitle }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @if ($isAdminCap)
        <div class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.expiration_date') }}</span>
            <div style="display: flex; gap: 9px; align-items: center;">
                <input type="hidden" name="enable_expiration_date" value="0">
                <input type="checkbox" name="enable_expiration_date" value="1" data-tp-expiration-toggle
                    {{ $hasExpire ? 'checked' : '' }}>
                <input type="date" name="expiration_date" value="{{ $expValue }}" data-tp-expiration-date
                    {{ $hasExpire ? '' : 'disabled' }} style="flex: 1;" data-tp-field>
            </div>
        </div>
    @endif
</div>
