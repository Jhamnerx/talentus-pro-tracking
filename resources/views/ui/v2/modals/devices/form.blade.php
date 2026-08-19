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
    // Mismo orden de tabs que el legacy (Devices/edit.blade.php):
    // Main, Users, Icons, Advanced, Sensors, Services, Accuracy, Tail,
    // Servicios Web, Expenses, Cameras.
    $tabs = collect([
        ['key' => 'principal', 'label' => trans('front.main')],
        ['key' => 'usuarios', 'label' => trans('front.users'), 'if' => !empty($caps['can_edit_users'])],
        ['key' => 'iconos', 'label' => trans('front.icons')],
        ['key' => 'informacion', 'label' => trans('front.advanced')],
        ['key' => 'sensores', 'label' => trans('front.sensors'), 'if' => !empty($caps['can_view_sensors'])],
        ['key' => 'servicios', 'label' => trans('front.services')],
        ['key' => 'exactitud', 'label' => trans('front.accuracy')],
        ['key' => 'cola', 'label' => trans('front.tail')],
        ['key' => 'webservices', 'label' => 'Servicios Web', 'if' => !empty($caps['can_view_webservices'])],
        ['key' => 'gastos', 'label' => trans('front.expenses'), 'if' => !empty($caps['has_expenses'])],
        ['key' => 'camaras', 'label' => trans('front.cameras'), 'if' => !empty($caps['can_view_cameras'])],
        // Campos personalizados: mismo gate que el legacy (Devices/edit.blade)
        ['key' => 'personalizados', 'label' => trans('admin.custom_fields'), 'if' => !empty($caps['has_custom_fields'])],
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
