{{--
    Tab Usuarios (solo admin) — asignación de usuarios al vehículo.
    Misma fuente que el multiselect legacy: DeviceUsersController@get/@index
    (UsersLoader paginado con búsqueda `s`, cada fila trae `selected`).
    La UI marca/desmarca y el JS acumula DELTAS en inputs ocultos
    `selected_user_id[]` con el formato del EntityLoader ("id;1;<uid>"
    = asignar, "id;0;<uid>" = quitar). DeviceModalHelper::setAbleUsers()
    los lee de request() y sincroniza contra device->users().
--}}
@php $isEdit = !empty($item->id); @endphp

<div data-tp-users data-users-url="{{ $isEdit ? route('devices.users.get', $item->id) : route('devices.users.index') }}">
    <p class="tp-form__hint" style="margin: 0 0 10px;">
        Marca los usuarios con acceso a este vehículo. Los cambios se aplican al guardar.
    </p>

    <input type="search" class="tp-input" data-users-search placeholder="Buscar por email…" autocomplete="off">

    <div data-users-list
        style="max-height: 300px; overflow-y: auto; border: 1px solid rgba(148,163,184,.35); border-radius: 10px; padding: 6px 10px; margin-top: 10px;">
        <div class="tp-text-muted" style="padding: 12px 4px; font-size: 13px;">Cargando…</div>
    </div>

    <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-users-more hidden
        style="margin-top: 8px;">Cargar más</button>

    {{-- Deltas serializados (selected_user_id[]) que viajan con el submit del device --}}
    <div data-users-deltas hidden></div>
</div>
