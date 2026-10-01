@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.objects'))
@section('rail-active', 'fleet')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/fleet.css') }}">
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/device-modal.css') }}">
    {{-- Kebab compartido + modales de Servicios (tab Servicios del modal device) --}}
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/services.css') }}">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    {{-- data-poll-interval: refresco automático de la lista (0 lo desactiva).
         Los estados y las duraciones cambian en vivo, así que la tabla se
         actualiza sola, como el mapa. --}}
    <div class="tp-fleet" id="tp-fleet" data-data-url="{{ route('ui.v2.api.fleet') }}" data-poll-interval="30000">
        {{-- Device CRUD modal config (shared with live / admin → fleet) --}}
        @php
            $tpDevicesBase = url('/ui/v2/api/devices');
            $tpDeviceModalConfig = [
                'formNewUrl' => route('ui.v2.api.devices.form_new'),
                'formUrl' => $tpDevicesBase . '/{id}/form',
                'storeUrl' => route('ui.v2.api.devices.store'),
                'updateUrl' => $tpDevicesBase . '/{id}',
                'destroyUrl' => $tpDevicesBase . '/{id}',
                'canCreate' => $canCreate ?? false,
                'canEdit' => $canCreate ?? false,
                'canRemove' => Auth::user()->perm('devices', 'remove'),
                // Candado anti-borrado (addon.object_delete_pass): paridad Devices/destroy.blade legacy.
                'objectDeletePass' => (bool) (config('addon.object_delete_pass') && isAdmin()),
            ];
        @endphp
        <script type="application/json" id="tp-device-modal-config">@json($tpDeviceModalConfig)</script>


        {{-- Cabecera de página: título a la izquierda, acciones a la derecha
             (mismo reparto que la referencia del ERP). --}}
        <header class="tp-fleet__page-head">
            <h1 class="tp-fleet__page-title">{{ trans('front.objects') }}</h1>

            <div class="tp-fleet__page-actions">
                @if (!empty($canCreate))
                    <button class="tp-btn tp-btn--accent tp-btn--sm" type="button" id="tp-fleet-create-device">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        <span>{{ trans('global.add') . ' ' . mb_strtolower(trans('front.object')) }}</span>
                    </button>
                @endif
                {{-- Alta de beacon (plugin beacons): kind aparte, form reducido --}}
                @if (!empty($canCreate) && settings('plugins.beacons.status') && Auth::user()->perm('beacons', 'edit'))
                    <button class="tp-btn tp-btn--ghost tp-btn--sm" type="button" id="tp-fleet-create-beacon"
                        title="Beacon">＋ Beacon</button>
                @endif
                {{-- Columnas configurables (ajuste compartido con la lista clásica) --}}
                <button class="tp-btn tp-btn--ghost tp-btn--sm" type="button" id="tp-fleet-columns">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="16" rx="2" />
                        <path d="M9 4v16M15 4v16" />
                    </svg>
                    <span>{{ trans('front.columns') }}</span>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-export" title="{{ trans('front.export') }}">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    <span>{{ trans('front.export') }}</span>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--sm tp-btn--icon" id="tp-fleet-refresh"
                    title="Actualizar" aria-label="Actualizar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 12a9 9 0 11-3-6.7L21 8" />
                        <path d="M21 3v5h-5" />
                    </svg>
                </button>
            </div>
        </header>

        {{-- Panel de filtros en tarjeta. Los desplegables se rellenan con lo que
             hay REALMENTE en la flota del usuario (grupos, modelos, protocolos),
             no con un catálogo fijo: ver 'filters' en el feed. --}}
        <section class="tp-fleet__filters-card">
            <div class="tp-fleet__filters-grid">
                <label class="tp-field">
                    <span class="tp-field__label">{{ trans('front.search') }}</span>
                    <span class="tp-fleet__search">
                        <svg class="tp-fleet__search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7" />
                            <path d="M21 21l-4.3-4.3" />
                        </svg>
                        <input type="search" class="tp-input tp-input--sm" id="tp-fleet-search"
                            placeholder="{{ trans('validation.attributes.name') }}, IMEI, {{ trans('validation.attributes.plate_number') }}, VIN…"
                            autocomplete="off">
                    </span>
                </label>

                <label class="tp-field">
                    <span class="tp-field__label">{{ trans('front.status') }}</span>
                    <select class="tp-input tp-input--sm" id="tp-fleet-status">
                        <option value="">{{ trans('front.all') }}</option>
                        <option value="moving">{{ trans('front.moving') }}</option>
                        {{-- idle = motor encendido sin moverse (ralentí). NO es lo
                             mismo que "inactivo" (sin datos hace tiempo): ambos se
                             llamaban "Inactivo" y salían dos veces. --}}
                        <option value="idle">{{ trans('front.idle') }}</option>
                        <option value="stopped">{{ trans('front.stop') }}</option>
                        <option value="offline">{{ trans('front.offline') }}</option>
                        {{-- Estados que el legacy resolvía con tablas Lookup dedicadas --}}
                        <option value="inactive">{{ trans('front.inactive') }}</option>
                        <option value="never_connected">{{ trans('front.never_connected') }}</option>
                        <option value="expired">{{ trans('front.expired') }}</option>
                    </select>
                </label>

                <label class="tp-field">
                    <span class="tp-field__label">{{ trans('front.group') }}</span>
                    <select class="tp-input tp-input--sm" id="tp-fleet-group">
                        <option value="">{{ trans('front.all') }}</option>
                    </select>
                </label>

                <label class="tp-field">
                    <span class="tp-field__label">{{ trans('validation.attributes.device_model') }}</span>
                    <select class="tp-input tp-input--sm" id="tp-fleet-model">
                        <option value="">{{ trans('front.all') }}</option>
                    </select>
                </label>

                <label class="tp-field">
                    <span class="tp-field__label">{{ trans('validation.attributes.protocol') }}</span>
                    <select class="tp-input tp-input--sm" id="tp-fleet-protocol">
                        <option value="">{{ trans('front.all') }}</option>
                    </select>
                </label>

                <label class="tp-field">
                    <span class="tp-field__label">{{ trans('front.rows_per_page') }}</span>
                    <select class="tp-input tp-input--sm" id="tp-fleet-limit">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100" selected>100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="tp-fleet__table-card">
            <div class="tp-fleet__table-head">
                <h2 class="tp-fleet__table-title">{{ trans('front.objects') }}</h2>
                <span class="tp-fleet__table-count" id="tp-fleet-count">—</span>
            </div>

        <div class="tp-fleet__table-wrap">
            <table class="tp-fleet__table" id="tp-fleet-table" role="grid">
                <thead>
                    <tr>
                        <th class="tp-fleet__th-check"><input type="checkbox" id="tp-fleet-check-all"
                                aria-label="{{ trans('ui.select_all_m') }}"></th>
                        <th data-sort="name">{{ trans('front.device') }}</th>
                        <th data-sort="status">{{ trans('front.state') }}</th>
                        <th data-sort="plate">{{ trans('ui.plate') }}</th>
                        <th>IMEI</th>
                        <th>{{ trans('front.model') }}</th>
                        <th>SIM</th>
                        <th class="tp-text-right" data-sort="speed">km/h</th>
                        <th class="tp-text-right">{{ trans('ui.engine_h') }}</th>
                        <th>{{ trans('ui.last_movement') }}</th>
                        <th>Expira</th>
                        <th class="tp-fleet__th-actions"></th>
                    </tr>
                </thead>
                <tbody id="tp-fleet-tbody">
                    <tr>
                        <td colspan="12" class="tp-text-muted tp-text-center" style="padding:32px;">Cargando…</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer class="tp-fleet__footer">
            <div class="tp-fleet__selection" id="tp-fleet-selection" hidden>
                <span><strong id="tp-fleet-selection-count">0</strong> {{ trans('ui.selected_plural') }}</span>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-selection-clear">{{ trans('global.clear') }}</button>
            </div>
            {{-- "Por página" vive ahora en el panel de filtros, con el resto. --}}
            <nav class="tp-fleet__pager" aria-label="{{ trans('front.page') }}">
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-prev" disabled>‹</button>
                <span class="tp-text-sm tp-text-muted" id="tp-fleet-page">—</span>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-next" disabled>›</button>
            </nav>
        </footer>
        </section>{{-- /tp-fleet__table-card --}}

        {{-- Config de los modales compartidos de Servicios (tp-services.js) --}}
        @include('ui.v2.partials.services-config')
    </div>
@endsection

@push('scripts')
    <script src="{{ asset_resource('assets/ui/v2/js/device-modal.js') }}"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-services.js') }}"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/fleet.js') }}" defer></script>
    <script>
        // Wire up the "+ Nuevo" button in the Fleet toolbar to the shared device modal.
        (function() {
            var btn = document.getElementById('tp-fleet-create-device');
            if (!btn || !window.tpDeviceModal) return;
            btn.addEventListener('click', function() {
                window.tpDeviceModal.openCreate(function() {
                    var refresh = document.getElementById('tp-fleet-refresh');
                    if (refresh) refresh.click();
                });
            });
        })();
        // Alta de beacon (plugin beacons)
        (function() {
            var b = document.getElementById('tp-fleet-create-beacon');
            if (!b || !window.tpDeviceModal) return;
            b.addEventListener('click', function() {
                window.tpDeviceModal.openCreateBeacon(function() {
                    var refresh = document.getElementById('tp-fleet-refresh');
                    if (refresh) refresh.click();
                });
            });
        })();
    </script>
@endpush
