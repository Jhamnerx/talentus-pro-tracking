{{--
    UI v2 · Modal de configuración de widgets
    Renderizado por Ui\V2\ShellController::widgetsConfig(). Espeja la config de
    widgets del legacy (MyAccountSettings tab Widgets): default / status / list,
    catálogo = config('lists.widgets'). Guarda vía POST a widgetsConfigSave().
--}}
@php
    $list = $current['list'] ?? [];
    $isDefault = !empty($current['default']);
    $isStatus = !empty($current['status']);
@endphp
<form id="tp-widgets-config-form" class="tp-widgets-config">
    <label class="tp-widgets-config__row">
        <input type="checkbox" name="default" value="1" {{ $isDefault ? 'checked' : '' }}>
        <span>{{ trans('front.default') }} Widgets</span>
    </label>
    <label class="tp-widgets-config__row">
        <input type="checkbox" name="status" value="1" {{ $isStatus ? 'checked' : '' }}>
        <span>{{ trans('front.enable_widgets') }}</span>
    </label>

    <div id="tp-widgets-config-fields" class="tp-widgets-grid">
        @foreach ($catalog as $key => $label)
            <label class="tp-widget-opt">
                <input type="checkbox" name="widgets[]" value="{{ $key }}" {{ in_array($key, $list, true) ? 'checked' : '' }}>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</form>
