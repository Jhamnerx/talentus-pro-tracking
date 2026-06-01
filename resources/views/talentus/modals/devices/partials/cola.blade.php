@php
    $tailColor = $item->tail_color ?? '#33b5e5';
    $tailLength = $item->tail_length ?? 5;
@endphp

<div class="tp-form__grid tp-form__grid--2">
    <label class="tp-form__field">
        <span class="tp-form__label">Color de cola</span>
        <input type="color" name="tail_color" value="{{ $tailColor }}" data-tp-field>
    </label>

    <label class="tp-form__field">
        <span class="tp-form__label">Longitud (0–10 últimos puntos)</span>
        <input type="number" min="0" max="10" step="1" name="tail_length" value="{{ $tailLength }}" data-tp-field>
    </label>
</div>
