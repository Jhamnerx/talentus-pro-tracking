{{--
    Tab Servicios — paridad con Frontend/Devices/partials/services.blade.php:
    buscador + botón añadir + lista de servicios de mantenimiento del device
    (Nombre | Fecha de expiración) con editar/eliminar. Reutiliza los modales
    compartidos de tp-services.js (ServiceModalHelper) y el feed
    ui.v2.api.services.device/{id} para re-hidratar.
--}}
@php
    $isEdit = !empty($item->id);
    $servicesList = $services_list ?? [];
@endphp

@if (!$isEdit)
    <div class="tp-form__placeholder">
        Guarda el vehículo primero; después podrás registrar servicios desde esta pestaña.
    </div>
@else
    <div data-tp-services-tab data-device-id="{{ (int) $item->id }}"
        data-list-url="{{ route('ui.v2.api.services.device', $item->id) }}"
        data-destroy-url="{{ url('/ui/v2/api/services') . '/{id}' }}">

        <div class="tp-group-box__head">
            <span class="tp-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input type="search" class="tp-input" data-services-search placeholder="{{ trans('front.search') }}"
                    autocomplete="off">
            </span>
            <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-service-add
                style="margin-left: auto;">＋ {{ trans('front.add_service') }}</button>
        </div>

        <div class="tp-embed-box">
            <table>
                <thead>
                    <tr>
                        <th>{{ trans('validation.attributes.name') }}</th>
                        <th>{{ trans('validation.attributes.expiration_date') }}</th>
                        <th style="width: 84px;"></th>
                    </tr>
                </thead>
                <tbody data-services-rows>
                    @forelse ($servicesList as $s)
                        <tr data-service-id="{{ $s['id'] }}">
                            <td>{{ $s['name'] }}</td>
                            <td>{{ $s['expiration'] }}</td>
                            <td style="text-align: right;">
                                @include('ui.v2.modals.devices.partials.kebab', ['edit' => 'data-service-edit', 'del' => 'data-service-del', 'id' => $s['id']])
                            </td>
                        </tr>
                    @empty
                        <tr data-services-empty>
                            <td colspan="3" class="tp-text-muted" style="padding: 14px 12px; font-size: 13px;">
                                {{ trans('front.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
