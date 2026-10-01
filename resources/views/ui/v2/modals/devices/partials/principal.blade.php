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
    // Un beacon no lleva modelo de GPS ni caducidad de suscripción
    // (create_beacon/edit_beacon del legacy solo piden active, name e imei).
    $isBeaconCap = !empty($caps['is_beacon']);
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

    @if (!$isBeaconCap && Auth::user()->can('view', $item, 'model_id') && !empty($models))
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

    @if ($isAdminCap && !$isBeaconCap)
        <div class="tp-form__field">
            <span class="tp-form__label" id="tp-lbl-expiration">{{ trans('validation.attributes.expiration_date') }}</span>
            <div style="display: flex; gap: 9px; align-items: center;">
                <input type="hidden" name="enable_expiration_date" value="0">
                <input aria-labelledby="tp-lbl-expiration" type="checkbox" name="enable_expiration_date" value="1" data-tp-expiration-toggle
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
    {{-- Configuración remota del GPS al darlo de alta (paridad
         Devices/partials/main.blade @if(!$item->exists && able('configure_device'))).
         Los campos los procesa DeviceModalHelper::configureDevice() al crear. --}}
    @if (!$isEditDev && Auth::user()->able('configure_device'))
        <div class="tp-form__field tp-col-2" style="margin-top: 4px;">
            <label class="tp-choice">
                <input type="hidden" name="configure_device" value="0">
                <input type="checkbox" class="tp-choice-input" name="configure_device" value="1"
                    data-toggle-panel="configurator">
                <span>{{ trans('front.device_configuration') }}</span>
            </label>
        </div>

        <div class="tp-form__grid tp-form__grid--2 tp-col-2" data-panel="configurator" style="display: none;">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.brand') }}</span>
                <select class="tp-input" name="config_id">
                    @foreach ($device_configs ?? [] as $cid => $cname)
                        <option value="{{ $cid }}">{{ $cname }}</option>
                    @endforeach
                </select>
                <small class="tp-form__hint">{{ trans('front.device_config_disclaimer') }}</small>
            </label>
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('front.apn_configuration') }}</span>
                {{-- Al cambiar rellena los 3 campos APN vía device_config.get_apn_data --}}
                <select class="tp-input" name="apn_config" data-apn-config
                    data-apn-url="{{ url('/devices_config/getApnData') }}">
                    @foreach ($apn_configs ?? [] as $aid => $aname)
                        <option value="{{ $aid }}">{{ $aname }}</option>
                    @endforeach
                </select>
            </label>
            <label class="tp-form__field tp-col-2">
                <span class="tp-form__label">{{ trans('validation.attributes.apn_name') }}</span>
                <input type="text" class="tp-input" name="apn_name" data-apn-name autocomplete="off">
            </label>
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.apn_username') }}</span>
                <input type="text" class="tp-input" name="apn_username" data-apn-username autocomplete="off">
            </label>
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.apn_password') }}</span>
                <input type="text" class="tp-input" name="apn_password" data-apn-password autocomplete="off">
            </label>
        </div>
    @endif
</div>
