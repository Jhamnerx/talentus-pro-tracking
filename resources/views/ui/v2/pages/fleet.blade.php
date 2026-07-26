@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Fleet')
@section('rail-active', 'fleet')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/fleet.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=10">
    {{-- Kebab compartido + modales de Servicios (tab Servicios del modal device) --}}
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-fleet" id="tp-fleet" data-data-url="{{ route('ui.v2.api.fleet') }}">
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


        <header class="tp-fleet__toolbar">
            <div class="tp-fleet__title">
                <h1 class="tp-h5 tp-mb-0">Fleet</h1>
                <span class="tp-text-muted tp-text-sm" id="tp-fleet-count">—</span>
            </div>

            <div class="tp-fleet__search">
                <svg class="tp-fleet__search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.3-4.3" />
                </svg>
                <input type="search" class="tp-input tp-input--sm" id="tp-fleet-search"
                    placeholder="Buscar por nombre, IMEI, placa, VIN…" autocomplete="off">
            </div>

            <div class="tp-fleet__filters" role="tablist" aria-label="Estado">
                <button class="tp-seg__btn is-active" role="tab" data-status="">Todos</button>
                <button class="tp-seg__btn" role="tab" data-status="moving">Moviendo</button>
                <button class="tp-seg__btn" role="tab" data-status="idle">Inactivo</button>
                <button class="tp-seg__btn" role="tab" data-status="stopped">Detenido</button>
                <button class="tp-seg__btn" role="tab" data-status="offline">Offline</button>
            </div>

            <div class="tp-fleet__actions">
                @if (!empty($canCreate))
                    <button class="tp-btn tp-btn--accent tp-btn--icon tp-btn--sm" type="button"
                        id="tp-fleet-create-device" title="Nuevo vehículo" aria-label="Nuevo vehículo">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                    </button>
                @endif
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-export" title="Exportar CSV">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    <span>Exportar</span>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-refresh" title="Actualizar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 12a9 9 0 11-3-6.7L21 8" />
                        <path d="M21 3v5h-5" />
                    </svg>
                </button>
            </div>
        </header>

        <div class="tp-fleet__table-wrap">
            <table class="tp-fleet__table" id="tp-fleet-table" role="grid">
                <thead>
                    <tr>
                        <th class="tp-fleet__th-check"><input type="checkbox" id="tp-fleet-check-all"
                                aria-label="Seleccionar todos"></th>
                        <th data-sort="name">Dispositivo</th>
                        <th data-sort="status">Estado</th>
                        <th data-sort="plate">Placa</th>
                        <th>IMEI</th>
                        <th>Modelo</th>
                        <th>SIM</th>
                        <th class="tp-text-right" data-sort="speed">km/h</th>
                        <th class="tp-text-right">Motor (h)</th>
                        <th>último movimiento</th>
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
                <span><strong id="tp-fleet-selection-count">0</strong> seleccionados</span>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-selection-clear">Limpiar</button>
            </div>
            <nav class="tp-fleet__pager" aria-label="Paginación">
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-prev" disabled>—</button>
                <span class="tp-text-sm tp-text-muted" id="tp-fleet-page">—</span>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-fleet-next" disabled>—</button>
            </nav>
        </footer>

        {{-- Config de los modales compartidos de Servicios (tp-services.js) --}}
        @include('ui.v2.partials.services-config')
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/ui/v2/js/device-modal.js') }}?v=11"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=3"></script>
    <script src="{{ asset('assets/ui/v2/js/fleet.js') }}?v=1" defer></script>
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
    </script>
@endpush
