{{--
    UI v2 · Modal de POI (crear / editar)
    Renderizado por Ui\V2\MapLayersController::poiForm(). Espeja
    Tobuli/Views/Frontend/Pois/create.blade.php (nombre, descripción, grupo,
    icono). Las coordenadas las aporta el JS (clic en el mapa). Guarda vía
    PoiUserService (create/edit).
--}}
@php
    $item = $item ?? null;
@endphp
<form id="tp-poi-form" class="tp-poi-form">
    <div class="tp-field">
        <label class="tp-field__label">{{ trans('validation.attributes.name') }}</label>
        <input type="text" name="name" class="tp-input" value="{{ $item->name ?? '' }}" autocomplete="off">
        <div class="tp-field__error" data-error="name"></div>
    </div>

    <div class="tp-field">
        <label class="tp-field__label">{{ trans('validation.attributes.description') }}</label>
        <textarea name="description" class="tp-input" rows="3">{{ $item->description ?? '' }}</textarea>
    </div>

    <div class="tp-field">
        <label class="tp-field__label">{{ trans('validation.attributes.group_id') }}</label>
        <select name="group_id" class="tp-input">
            @foreach ($poiGroups as $gid => $title)
                <option value="{{ $gid }}" {{ (string) ($item->group_id ?? '0') === (string) $gid ? 'selected' : '' }}>
                    {{ $title }}</option>
            @endforeach
        </select>
    </div>

    <div class="tp-field">
        <label class="tp-field__label">{{ trans('validation.attributes.map_icon_id') }}</label>
        <div class="tp-poi-icons">
            @foreach ($mapIcons as $icon)
                <label class="tp-poi-icon" title="#{{ $icon->id }}">
                    <input type="radio" name="map_icon_id" value="{{ $icon->id }}"
                        {{ (int) ($item->map_icon_id ?? 0) === (int) $icon->id ? 'checked' : '' }}>
                    <img src="{{ asset($icon->path) }}" alt="">
                </label>
            @endforeach
        </div>
    </div>
</form>
