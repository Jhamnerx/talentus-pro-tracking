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

<input type="hidden" name="icon_id" value="0">

{{-- Galerías por grupo (icon / rotating) — visibles solo si el tipo coincide --}}
@foreach ($grouped as $group => $icons)
    <div class="tp-form__section" data-tp-icons-group="{{ $group }}" style="display: none;">
        <h4 class="tp-form__section-title">{{ trans('validation.attributes.icon_id') }}</h4>
        <div class="tp-icon-gallery">
            @foreach ($icons as $icon)
                @php $sel = (int) $icon->id === $iconId; @endphp
                <label class="tp-icon-gallery__item{{ $sel ? ' is-selected' : '' }}" data-icon-id="{{ $icon->id }}">
                    <input type="radio" name="icon_id" value="{{ $icon->id }}" {{ $sel ? 'checked' : '' }} disabled>
                    <img src="{{ asset($icon->path) }}" alt="icono #{{ $icon->id }}" loading="lazy">
                </label>
            @endforeach
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
