@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Eventos')
@section('rail-active', 'events')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/events.css') }}?v=8">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-events" id="tp-events" data-data-url="{{ route('ui.v2.api.events') }}"
        data-geo-url="{{ route('address.get') }}" data-zones-url="{{ route('ui.v2.api.zones') }}"
        data-pois-url="{{ route('ui.v2.api.pois') }}" data-routes-url="{{ route('ui.v2.api.routes') }}"
        {{-- Direcciones gateadas por el plugin igual que el legacy (Events/index.blade → @if plugins.event_section_address.status) --}}
        data-addr-on="{{ settings('plugins.event_section_address.status') ? '1' : '' }}">
        {{-- Map config (provider IDs, API keys) inyectado para la capa JS --}}
        <script type="application/json" id="tp-events-map-config">@json($mapConfig)</script>

        {{-- LIST PANEL --}}
        <aside class="tp-events__list-panel">
            <header class="tp-events__head">
                <h3 class="tp-h6 tp-mb-2">Eventos</h3>
                <select class="tp-input tp-input--sm" id="tp-events-hours">
                    <option value="0" selected>Todos</option>
                    <option value="1">Última hora</option>
                    <option value="6">Últimas 6 horas</option>
                    <option value="24">Últimas 24 horas</option>
                    <option value="72">Últimos 3 días</option>
                    <option value="168">Últimos 7 días</option>
                </select>
                <div class="tp-input-wrap tp-mt-2">
                    <input type="text" class="tp-input tp-input--with-icon" id="tp-events-search"
                        placeholder="Buscar evento…" autocomplete="off">
                </div>
            </header>

            <div class="tp-events__list" id="tp-events-list" role="listbox" aria-label="Lista de eventos">
                <div class="tp-text-xs tp-text-muted" style="padding:12px;">Cargando eventos…</div>
            </div>

            <footer class="tp-events__foot">
                <span class="tp-text-xs tp-text-muted" id="tp-events-status">—</span>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-events-refresh" type="button">Refrescar</button>
            </footer>
        </aside>

        {{-- MAP --}}
        <main class="tp-events__map-area">
            <div id="tp-events-map" class="tp-events__map"></div>
            <div class="tp-events__hint" id="tp-events-hint">Selecciona un evento para verlo en el mapa</div>

            {{-- Controles de mapa --}}
            <div class="tp-events__map-controls">
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-fullscreen"
                    title="Pantalla completa" aria-label="Pantalla completa">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                        <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-style"
                    title="Cambiar tipo de mapa" aria-label="Cambiar tipo de mapa" aria-haspopup="menu">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 22 8.5 12 15 2 8.5 12 2" />
                        <polyline points="2 15.5 12 22 22 15.5" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-zoom-in"
                    title="Acercar" aria-label="Acercar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-zoom-out"
                    title="Alejar" aria-label="Alejar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-geofences" title="Mostrar geocercas" aria-label="Mostrar geocercas"
                    aria-pressed="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="4 7 12 3 20 7 20 17 12 21 4 17 4 7" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-routes" title="Mostrar rutas" aria-label="Mostrar rutas" aria-pressed="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="19" r="2" />
                        <circle cx="18" cy="5" r="2" />
                        <path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-poi" title="Mostrar POIs" aria-label="Mostrar POIs" aria-pressed="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s7-7.58 7-13a7 7 0 0 0-14 0c0 5.42 7 13 7 13z" />
                        <circle cx="12" cy="9" r="2.5" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-hitos" title="{{ trans('front.show_km_markers') }}"
                    aria-label="{{ trans('front.show_km_markers') }}" aria-pressed="false">
                    {{-- Hito vial: poste con placa km --}}
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 10v11" />
                        <rect x="6" y="3" width="12" height="7" rx="1.5" />
                        <path d="M9 6.5h6" />
                    </svg>
                </button>
            </div>

            {{-- Menú de tipos de mapa (selector; persiste en user->map_id, compartido con Live) --}}
            <div class="tp-events__providers" id="tp-ev-providers" role="menu" hidden></div>
        </main>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-basemaps.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/events.js') }}?v=12"></script>
@endpush
