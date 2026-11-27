{{--
    Tab Servicios — paridad con Frontend/Devices/partials/services.blade.php:
    "+ Agregar servicio" + buscador + tabla Nombre | Fecha de expiración con
    editar/eliminar como iconos en línea. Reutiliza los modales
    compartidos de tp-services.js (ServiceModalHelper) y el feed
    ui.v2.api.services.device/{id} para re-hidratar.
--}}
@php
    $isEdit = !empty($item->id);
    $servicesList = $services_list ?? [];
@endphp

@if (!$isEdit)
    <div class="tp-form__placeholder">
        {{ trans('ui.save_vehicle_first_services') }}
    </div>
@else
    <div data-tp-services-tab data-device-id="{{ (int) $item->id }}"
        data-list-url="{{ route('ui.v2.api.services.device', $item->id) }}"
        data-destroy-url="{{ url('/ui/v2/api/services') . '/{id}' }}">

        {{-- Barra como el legacy (action-block): "+ Agregar servicio" a la
             izquierda y el buscador (extra v2) a la derecha. --}}
        <div class="tp-devlist__bar">
            <button type="button" class="tp-devlist__add" data-service-add>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                    stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                {{ trans('front.add_service') }}
            </button>
            <span class="tp-devlist__search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input aria-label="{{ trans('front.search') }}" type="search" data-services-search
                    placeholder="{{ trans('front.search') }}" autocomplete="off">
            </span>
        </div>

        <table class="tp-devlist__table">
            <thead>
                <tr>
                    <th>{{ trans('validation.attributes.name') }}</th>
                    <th>{{ trans('validation.attributes.expiration_date') }}</th>
                    <th class="tp-devlist__actions-col"></th>
                </tr>
            </thead>
            <tbody data-services-rows>
                @forelse ($servicesList as $s)
                    <tr data-service-id="{{ $s['id'] }}">
                        <td>{{ $s['name'] }}</td>
                        <td>{{ $s['expiration'] }}</td>
                        <td class="tp-devlist__actions">
                            <button type="button" class="tp-devlist__icon" data-service-edit="{{ $s['id'] }}"
                                title="{{ trans('global.edit') }}" aria-label="{{ trans('global.edit') }}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" /></svg>
                            </button>
                            <button type="button" class="tp-devlist__icon tp-devlist__icon--danger" data-service-del="{{ $s['id'] }}"
                                title="{{ trans('global.delete') }}" aria-label="{{ trans('global.delete') }}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr data-services-empty>
                        <td colspan="3" class="tp-devlist__empty">{{ trans('front.no_services') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endif
