@php
    $tailColor = $item->tail_color ?? '#33b5e5';
    $tailLength = $item->tail_length ?? 5;

    // ── Colores del recorrido (historial) — propiedad del vehículo ──
    $rcType = $item->route_color_type ?? 'trips';
    $rcSingle = $item->route_color ?: '#2563eb';
    $rcRanges = [];
    if (!empty($item->route_speed_ranges)) {
        $decoded = json_decode($item->route_speed_ranges, true);
        if (is_array($decoded)) {
            $rcRanges = $decoded;
        }
    }
    if (empty($rcRanges)) {
        // Rangos por defecto (mismos que usaba History hasta ahora)
        $rcRanges = [
            ['from' => 0, 'to' => 40, 'color' => '#22d3ee'],
            ['from' => 40, 'to' => 60, 'color' => '#3b82f6'],
            ['from' => 60, 'to' => 90, 'color' => '#6366f1'],
            ['from' => 90, 'to' => 120, 'color' => '#a855f7'],
            ['from' => 120, 'to' => null, 'color' => '#ef4444'],
        ];
    }
    // Sensores con rangos de color por intervalo (futuro): hoy ninguno los
    // define, así que "Por sensor" queda deshabilitado (como Wialon cuando
    // no hay sensores con "Intervals and colors").
    $rcSensors = [];
    $quickColors = ['#2563eb', '#16a34a', '#22d3ee', '#0ea5e9', '#6366f1', '#7c3aed', '#a855f7', '#db2777', '#ea580c', '#f59e0b'];
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field">
        <span class="tp-form__label">Color de cola</span>
        <input type="color" name="tail_color" value="{{ $tailColor }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Longitud (0–10 últimos puntos)</span>
        <input type="number" min="0" max="10" step="1" name="tail_length" value="{{ $tailLength }}"
            data-tp-field>
    </label>
</div>

{{-- ================= Colores del recorrido (historial) ================= --}}
<div class="tp-routecolors" data-tp-routecolors>
    <div class="tp-form__label" style="margin:16px 0 8px;">Colores del recorrido</div>

    {{-- Por viajes --}}
    <label class="tp-routecolors__opt">
        <input type="radio" name="route_color_type" value="trips" {{ $rcType === 'trips' ? 'checked' : '' }}>
        <span>Por viajes</span>
    </label>

    {{-- Único (single) --}}
    <label class="tp-routecolors__opt">
        <input type="radio" name="route_color_type" value="single" {{ $rcType === 'single' ? 'checked' : '' }}>
        <span>Único</span>
        <span class="tp-routecolors__opt-body" data-rc-panel="single">
            <input type="color" name="route_color" value="{{ $rcSingle }}" class="tp-routecolors__hex" data-rc-single>
            <span class="tp-routecolors__swatches">
                @foreach ($quickColors as $qc)
                    <button type="button" class="tp-routecolors__swatch" data-rc-swatch="{{ $qc }}"
                        style="background: {{ $qc }}" title="{{ $qc }}"></button>
                @endforeach
            </span>
        </span>
    </label>

    {{-- Por velocidad --}}
    <label class="tp-routecolors__opt tp-routecolors__opt--block">
        <span class="tp-routecolors__opt-head">
            <input type="radio" name="route_color_type" value="speed" {{ $rcType === 'speed' ? 'checked' : '' }}>
            <span>Por velocidad</span>
            <button type="button" class="tp-routecolors__add" data-rc-add title="Añadir rango">+</button>
            <button type="button" class="tp-routecolors__reset" data-rc-reset title="Restaurar rangos por defecto">↺</button>
        </span>
        <span class="tp-routecolors__opt-body tp-routecolors__opt-body--block" data-rc-panel="speed">
            <span class="tp-routecolors__bar" data-rc-bar></span>
            <span class="tp-routecolors__rows" data-rc-rows></span>
        </span>
    </label>

    {{-- Por sensor (solo si un sensor define rangos de color por intervalo) --}}
    <label class="tp-routecolors__opt @if (empty($rcSensors)) is-disabled @endif">
        <input type="radio" name="route_color_type" value="sensor" {{ $rcType === 'sensor' ? 'checked' : '' }}
            {{ empty($rcSensors) ? 'disabled' : '' }}>
        <span>Por sensor</span>
        <span class="tp-routecolors__opt-body" data-rc-panel="sensor">
            <select name="route_sensor_id" class="tp-input tp-input--sm" {{ empty($rcSensors) ? 'disabled' : '' }}
                style="min-width:160px;">
                <option value="">—</option>
                @foreach ($rcSensors as $s)
                    <option value="{{ $s['id'] }}"
                        {{ ($item->route_sensor_id ?? null) == $s['id'] ? 'selected' : '' }}>
                        {{ $s['name'] }}</option>
                @endforeach
            </select>
        </span>
    </label>
    @if (empty($rcSensors))
        <div class="tp-text-xs tp-text-muted" style="margin:2px 0 0 24px;">
            Se activa cuando un sensor del equipo define rangos de colores por intervalo.
        </div>
    @endif

    {{-- Rangos serializados (JSON) — lo consume el guardado y el mapa de Historial --}}
    <input type="hidden" name="route_speed_ranges" value="{{ e(json_encode($rcRanges)) }}" data-rc-json>
</div>
