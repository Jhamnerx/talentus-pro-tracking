@php
    $iconTypes = $icons_type ?? ['arrow' => 'Flecha', 'rotating' => 'Rotatorio', 'icon' => 'Fijo'];
    $colors = $device_icon_colors ?? [];
    $icons = collect($device_icons ?? [])->filter(function ($i) {
        return ($i->type ?? null) !== 'arrow';
    })->values();
    $iconId = (int) ($item->icon_id ?? 0);
    $iconType = isset($item->icon) && $item->icon ? ($item->icon->type ?? 'arrow') : 'arrow';
    $iconColorsItem = (array) ($item->icon_colors ?? []);
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field">
        <span class="tp-form__label">Tipo de ícono</span>
        <select name="device_icons_type" data-tp-field>
            @foreach ($iconTypes as $k => $label)
                <option value="{{ $k }}" {{ $iconType === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </label>
</div>

<div class="tp-form__section">
    <h4 class="tp-form__section-title">Galería</h4>
    <div class="tp-icon-gallery">
        @foreach ($icons as $icon)
            @php $sel = (int) $icon->id === $iconId; @endphp
            <label class="tp-icon-gallery__item{{ $sel ? ' is-selected' : '' }}" data-icon-id="{{ $icon->id }}">
                <input type="radio" name="icon_id" value="{{ $icon->id }}" {{ $sel ? 'checked' : '' }}>
                <img src="{{ asset($icon->path) }}" alt="icono #{{ $icon->id }}" loading="lazy">
            </label>
        @endforeach
    </div>
</div>

<div class="tp-form__section">
    <h4 class="tp-form__section-title">Colores por estado</h4>
    <div class="tp-form__grid tp-form__grid--3">
        @foreach (['moving' => 'En movimiento', 'stopped' => 'Detenido', 'offline' => 'Sin conexión', 'engine' => 'Motor encendido'] as $st => $stLabel)
            @php $val = $iconColorsItem[$st] ?? null; @endphp
            <label class="tp-form__field">
                <span class="tp-form__label">{{ $stLabel }}</span>
                <select name="icon_colors[{{ $st }}]" data-tp-field>
                    @foreach ($colors as $cKey => $cLabel)
                        <option value="{{ $cKey }}" {{ $val === $cKey ? 'selected' : '' }}>{{ $cLabel }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
    </div>
</div>
