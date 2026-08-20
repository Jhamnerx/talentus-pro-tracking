{{--
    Tab Iconos — paridad con Frontend/Devices/partials/icons.blade.php:
    el contenido depende del tipo (arrow → SOLO colores por estado;
    icon/rotating → SOLO la galería de su grupo). Names legacy
    icon_moving/icon_stopped/icon_offline/icon_engine (+icon_blocked):
    DeviceModalHelper::setIconColors() lee "icon_$status".
--}}
@php
    $iconTypes = $icons_type ?? ['arrow' => 'Arrow', 'rotating' => 'Rotating', 'icon' => 'Icon'];
    $colors = $device_icon_colors ?? [];
    $grouped = $device_icons_grouped ?? [];
    $iconId = (int) ($item->icon_id ?? 0);
    $iconType = isset($item->icon) && $item->icon ? $item->icon->type ?? 'arrow' : 'arrow';
    $iconColorsItem = (array) ($item->icon_colors ?? []);
    $statusColors = [
        'moving'  => trans('front.moving'),
        'stopped' => trans('front.stopped'),
        'offline' => trans('front.offline'),
        'engine'  => trans('front.engine_idle'),
    ];
    if (\Tobuli\Sensors\Types\Blocked::isEnabled()) {
        $statusColors['blocked'] = trans('front.blocked');
    }
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field tp-col-2">
        <span class="tp-form__label">{{ trans('validation.attributes.icon_type') }}</span>
        <select class="tp-input" style="width: 100%" name="device_icons_type" data-tp-icons-type data-tp-field>
            @foreach ($iconTypes as $k => $label)
                <option value="{{ $k }}" {{ $iconType === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </label>
</div>

{{-- Valor actual del icono. Antes era 0 fijo porque los radios de la galería
     siempre estaban en el DOM y uno de ellos lo sobrescribía; ahora la galería
     se carga al abrir el tab, así que guardar sin pasar por él enviaría 0 y
     borraría el icono del vehículo. --}}
<input type="hidden" name="icon_id" value="{{ $iconId }}">

{{-- Galerías por grupo (icon / rotating).
     El catálogo puede pasar del millar de iconos: incrustarlo en el formulario
     lo hacía pesar ~460 KB (el 77% eran estas galerías) y ralentizaba cada
     apertura del modal. Ahora el contenedor va vacío y device-modal.js pide la
     galería del grupo la primera vez que se muestra. --}}
{{-- Se itera clave=>valor (ignorando los iconos): en el alta $grouped es una
     Collection y array_keys() reventaba. --}}
@foreach ($grouped as $group => $iconsUnused)
    <div class="tp-form__section" data-tp-icons-group="{{ $group }}" style="display: none;">
        <h4 class="tp-form__section-title">{{ trans('validation.attributes.icon_id') }}</h4>
        <div class="tp-icon-gallery" data-tp-icons-lazy="{{ $group }}"
            data-url="{{ url('/ui/v2/api/devices/icons') }}?group={{ $group }}&selected={{ $iconId }}">
            <span class="tp-text-muted tp-text-sm">{{ trans('front.loading') ?: 'Cargando…' }}</span>
        </div>
    </div>
@endforeach

{{-- Colores por estado — solo tipo arrow --}}
<div class="tp-form__section" data-tp-icons-colors style="display: none;">
    <div class="tp-form__grid tp-form__grid--2">
        @foreach ($statusColors as $st => $stLabel)
            @php $val = $iconColorsItem[$st] ?? settings('device.status_colors.colors.' . $st); @endphp
            <label class="tp-form__field">
                <span class="tp-form__label">{{ $stLabel }}</span>
                <select class="tp-input" style="width: 100%" name="icon_{{ $st }}" data-tp-field>
                    @foreach ($colors as $cKey => $cLabel)
                        <option value="{{ $cKey }}" {{ $val === $cKey ? 'selected' : '' }}>{{ $cLabel }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
    </div>
</div>
