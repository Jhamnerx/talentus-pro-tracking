{{--
    Tab Usuarios (solo admin) — misma disposición que el multiselect legacy
    (Devices/partials/users.blade, selectpicker "multiexpand half"):
    etiqueta "Usuario", caja con [Seleccionar todo] [Deseleccionar todo]
    [buscador] y lista a 2 columnas con scroll que carga más páginas al
    bajar (sin paginador numérico).
    Misma fuente que el legacy (DeviceUsersController@get/@index,
    UsersLoader paginado con búsqueda `s`, cada fila trae `selected`).
    El JS acumula la selección en inputs ocultos selected_user_id[] con el
    formato del EntityLoader ("s;1;" todo / "s;0;" nada / "id;1|0;<uid>")
    que DeviceModalHelper::setAbleUsers() resuelve contra device->users().
    Sin cambios → no viaja nada → asignaciones intactas.
--}}
@php $isEdit = !empty($item->id); @endphp

<div class="tp-form__field">
    <span class="tp-form__label">{{ trans('validation.attributes.user') }}</span>

    <div class="tp-users-box" data-tp-users
        data-users-url="{{ $isEdit ? route('devices.users.get', $item->id) : route('devices.users.index') }}">

        <div class="tp-users-box__head">
            <button type="button" class="tp-users-box__action" data-users-select-all>{{ trans('front.select_all') }}</button>
            <button type="button" class="tp-users-box__action" data-users-deselect-all>{{ trans('front.deselect_all') }}</button>
            <span class="tp-users-box__search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input aria-label="{{ trans('front.search') }}" type="search" data-users-search autocomplete="off">
            </span>
        </div>

        <div class="tp-users-box__list" data-users-list>
            <div class="tp-text-muted tp-users-box__msg">…</div>
        </div>

        {{-- Selección serializada (selected_user_id[]) que viaja con el submit del device --}}
        <div data-users-deltas hidden></div>
    </div>
</div>
