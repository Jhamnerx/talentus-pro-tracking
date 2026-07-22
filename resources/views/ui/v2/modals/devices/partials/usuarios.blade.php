{{--
    Tab Usuarios (solo admin) — caja estilo referencia: buscador +
    "Seleccionar todo | Deseleccionar todo" + lista paginada con checkbox.
    Misma fuente que el multiselect legacy (DeviceUsersController@get/@index,
    UsersLoader paginado con búsqueda `s`, cada fila trae `selected`).
    El JS acumula la selección en inputs ocultos selected_user_id[] con el
    formato del EntityLoader ("s;1;" todo / "s;0;" nada / "id;1|0;<uid>")
    que DeviceModalHelper::setAbleUsers() resuelve contra device->users().
    Sin cambios → no viaja nada → asignaciones intactas.
--}}
@php $isEdit = !empty($item->id); @endphp

<div class="tp-group-box" data-tp-users
    data-users-url="{{ $isEdit ? route('devices.users.get', $item->id) : route('devices.users.index') }}">

    <div class="tp-group-box__head">
        <span class="tp-search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.35-4.35" />
            </svg>
            <input type="search" class="tp-input" data-users-search placeholder="{{ trans('front.search') }}"
                autocomplete="off">
        </span>
        <button type="button" class="tp-link-action" data-users-select-all>{{ trans('front.select_all') }}</button>
        <span class="tp-group-box__sep">|</span>
        <button type="button" class="tp-link-action" data-users-deselect-all>{{ trans('front.deselect_all') }}</button>
    </div>

    <div data-users-list style="min-height: 120px; max-height: 300px; overflow-y: auto;">
        <div class="tp-text-muted" style="padding: 12px 4px; font-size: 13px;">…</div>
    </div>

    <div class="tp-pagination" data-users-pagination hidden></div>

    {{-- Selección serializada (selected_user_id[]) que viaja con el submit del device --}}
    <div data-users-deltas hidden></div>
</div>
