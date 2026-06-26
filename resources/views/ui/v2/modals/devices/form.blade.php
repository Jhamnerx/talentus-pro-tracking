{{--
    TalentusPro · Device modal — formulario tabbed
    -----------------------------------------------------------------
    Render server-side. Mismo set de variables que DeviceModalHelper
    (createData() / editData()), por lo que los `name=` coinciden con
    la validación legacy y NO hay duplicación de catálogos.
    Variables disponibles:
      $item, $device_groups, $device_types, $timezones, $device_icons,
      $device_icons_grouped, $icons_type, $device_icon_colors,
      $fuel_detect_sec_after_stop_options, $models, $caps
--}}
@php
    $caps = $caps ?? [];
    $isEdit = !empty($item->id);
    $tabs = collect([
        ['key' => 'principal', 'label' => 'Principal'],
        ['key' => 'usuarios', 'label' => 'Usuarios', 'if' => !empty($caps['can_edit_users'])],
        ['key' => 'iconos', 'label' => 'Iconos'],
        ['key' => 'informacion', 'label' => 'Información'],
        ['key' => 'sensores', 'label' => 'Sensores', 'if' => !empty($caps['can_view_sensors'])],
        ['key' => 'exactitud', 'label' => 'Exactitud'],
        ['key' => 'cola', 'label' => 'Cola'],
        ['key' => 'webservices', 'label' => 'Servicios Web', 'if' => !empty($caps['can_view_webservices'])],
    ])
        ->filter(function ($t) {
            return !array_key_exists('if', $t) || $t['if'];
        })
        ->values();
@endphp

<form class="tp-form tp-device-form" autocomplete="off" novalidate data-device-id="{{ $isEdit ? (int) $item->id : '' }}">
    <div class="tp-tabs">
        <div class="tp-tabs__nav" role="tablist">
            @foreach ($tabs as $i => $t)
                <button type="button" class="tp-tabs__tab{{ $i === 0 ? ' is-active' : '' }}" role="tab"
                    data-tp-tab="{{ $t['key'] }}">{{ $t['label'] }}</button>
            @endforeach
        </div>

        <div class="tp-tabs__panels">
            @foreach ($tabs as $i => $t)
                <div class="tp-tabs__panel{{ $i === 0 ? ' is-active' : '' }}" data-tp-panel="{{ $t['key'] }}">
                    @includeIf('ui.v2.modals.devices.partials.' . $t['key'])
                </div>
            @endforeach
        </div>
    </div>
</form>
