@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Eventos')
@section('rail-active', 'events')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/events.css') }}?v=3">
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-events" id="tp-events" data-data-url="{{ route('ui.v2.api.events') }}"
        data-geo-url="{{ route('address.get') }}" data-zones-url="{{ route('ui.v2.api.zones') }}">
        {{-- Map config (provider IDs, API keys) inyectado para la capa JS --}}
        <script type="application/json" id="tp-events-map-config">@json($mapConfig)</script>

        {{-- LIST PANEL --}}
        <aside class="tp-events__list-panel">
            <header class="tp-events__head">
                <h3 class="tp-h6 tp-mb-2">Eventos</h3>
                <select class="tp-input tp-input--sm" id="tp-events-hours">
                    <option value="1">Última hora</option>
                    <option value="6">Últimas 6 horas</option>
                    <option value="24" selected>Últimas 24 horas</option>
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
            </div>
        </main>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-map-providers.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-map-shared.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/events.js') }}?v=3"></script>
@endpush
