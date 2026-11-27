{{--
    Tab Servicios Web — integraciones regulatorias Perú.
    $webservices viene de DeviceModalHelper::getWebservices():
    [['key' => 'mtc', 'label' => 'Sutran', 'active' => bool], ...]
    (solo incluye los proveedores donde el usuario tiene perm
    webservice.<x> 'edit'). Cada flag es una columna del device;
    el patrón hidden 0 + checkbox 1 replica el form legacy.
--}}
@php $webservices = $webservices ?? []; @endphp

@if (empty($webservices))
    <div class="tp-form__placeholder">
        {{ trans('ui.no_webservice_edit_permission') }}
    </div>
@else
    <p class="tp-form__hint" style="margin: 0 0 12px;">
        {{ trans('ui.webservices_help') }}
    </p>

    <div class="tp-form__grid tp-form__grid--2">
        @foreach ($webservices as $ws)
            <label class="tp-choice">
                <input type="hidden" name="{{ $ws['key'] }}" value="0">
                <input type="checkbox" class="tp-choice-input" name="{{ $ws['key'] }}" value="1"
                    {{ !empty($ws['active']) ? 'checked' : '' }}>
                <span>{{ $ws['label'] }}</span>
            </label>
        @endforeach
    </div>
@endif
