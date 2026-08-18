{{--
    Tab Principal — paridad con Frontend/Devices/partials/main.blade.php:
    active, name, imei, model_id (catálogo GPS $models) y expiración
    (checkbox enable_expiration_date + fecha, solo admin). El campo
    device_model (texto libre) vive en Información, como el legacy.
--}}
@php
    $isActive = $item->active ?? true;
    $expirationDate = $item->expiration_date ?? null;
    $isEditDev = !empty($item->exists);
    $hasExpValue = !empty($expirationDate) && substr((string) $expirationDate, 0, 4) !== '0000';
    // Paridad legacy main.blade: la BD guarda UTC y el helper hace reverse()
    // (TZ usuario → UTC) al guardar, así que aquí se PINTA convert() (UTC →
    // TZ usuario) — pintar el crudo corría la fecha −offset en cada edición.
    // Al CREAR el checkbox va SIEMPRE desmarcado (la fecha precargada por
    // createData es solo sugerencia; marcado forzaba caducidad al alta).
    $hasExpire = $isEditDev && $hasExpValue;
    $expDisplay = '';
    if ($hasExpValue) {
        $expDisplay = $isEditDev ? Formatter::time()->convert($expirationDate) : (string) $expirationDate;
    }
    $expDate = $expDisplay !== '' ? substr($expDisplay, 0, 10) : '';
    $expTime = [];
    if ($expDisplay !== '') {
        preg_match('/\b(\d{2}):(\d{2})/', $expDisplay, $expTime);
    }
    $expH = $expTime[1] ?? '00';
    $expM = $expTime[2] ?? '00';
    $expCombined = $hasExpire ? ($expDate . ' ' . $expH . ':' . $expM) : '';
    $isAdminCap = !empty($caps['is_admin']);
@endphp

{{-- Una sola columna: campos apilados a todo el ancho (como la referencia) --}}
<div class="tp-form__grid">
    {{-- Gates de permiso por propiedad (paridad legacy main.blade): active y
         imei solo visibles con can('view', ...); bloqueados si no can('edit'). --}}
    @if (Auth::user()->can('view', $item, 'active'))
        <label class="tp-form__field tp-form__field--checkbox">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" {{ $isActive ? 'checked' : '' }}
                {{ Auth::user()->can('edit', $item, 'active') ? '' : 'disabled' }}>
            <span class="tp-form__label">{{ trans('validation.attributes.active') }}</span>
        </label>
    @endif

    <label class="tp-form__field tp-form__field--required">
        <span class="tp-form__label">{{ trans('validation.attributes.name') }}</span>
        <input type="text" name="name" required maxlength="80" value="{{ $item->name ?? '' }}" data-tp-field>
    </label>

    @if (Auth::user()->can('view', $item, 'imei'))
        <label class="tp-form__field tp-form__field--required">
            <span class="tp-form__label">{{ trans('front.device_imei') }} / {{ trans('front.tracker_id') }}</span>
            <input type="text" name="imei" required maxlength="32" placeholder="{{ trans('front.imei_placeholder') }}"
                value="{{ $item->imei ?? '' }}" data-tp-field
                {{ Auth::user()->can('edit', $item, 'imei') ? '' : 'disabled' }}>
        </label>
    @endif

    @if (Auth::user()->can('view', $item, 'model_id') && !empty($models))
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.model_id') }}</span>
            <select class="tp-input" style="width: 100%" name="model_id" data-tp-field
                {{ Auth::user()->can('edit', $item, 'model_id') ? '' : 'disabled' }}>
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
                {{-- Valor combinado (YYYY-MM-DD HH:MM) que se envía; lo arma device-modal.js. --}}
                <input type="hidden" name="expiration_date" value="{{ $expCombined }}" data-tp-exp-value data-tp-field>
                <input type="date" data-tp-exp-date value="{{ $expDate }}" {{ $hasExpire ? '' : 'disabled' }}
                    style="flex: 1; min-width: 0;">
                <select data-tp-exp-h data-no-ts="1" {{ $hasExpire ? '' : 'disabled' }} style="width: 70px; flex: 0 0 auto;">
                    @for ($h = 0; $h < 24; $h++)
                        @php $hh = str_pad((string) $h, 2, '0', STR_PAD_LEFT); @endphp
                        <option value="{{ $hh }}" {{ $expH === $hh ? 'selected' : '' }}>{{ $hh }}</option>
                    @endfor
                </select>
                <span style="font-weight: 600;">:</span>
                <select data-tp-exp-m data-no-ts="1" {{ $hasExpire ? '' : 'disabled' }} style="width: 70px; flex: 0 0 auto;">
                    @for ($m = 0; $m < 60; $m++)
                        @php $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT); @endphp
                        <option value="{{ $mm }}" {{ $expM === $mm ? 'selected' : '' }}>{{ $mm }}</option>
                    @endfor
                </select>
            </div>
        </div>
    @endif
</div>
