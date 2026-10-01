@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.events'))
@section('rail-active', 'events')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/events.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-events" id="tp-events" data-data-url="{{ route('ui.v2.api.events') }}"
        data-can-remove="{{ Auth::user()->perm('events', 'remove') ? 1 : 0 }}"
        data-destroy-url="{{ url('/ui/v2/api/events') }}"
        data-map-url="{{ route('ui.v2.api.events.map') }}"
        data-geo-url="{{ route('address.get') }}" data-zones-url="{{ route('ui.v2.api.zones') }}"
        data-pois-url="{{ route('ui.v2.api.pois') }}" data-routes-url="{{ route('ui.v2.api.routes') }}"
        {{-- Direcciones gateadas por el plugin igual que el legacy (Events/index.blade → @if plugins.event_section_address.status) --}}
        data-addr-on="{{ settings('plugins.event_section_address.status') ? '1' : '' }}"
        {{-- "Atender" evento = registrar acción de llamada (mismo gate que el legacy: call_actions + events) --}}
        data-ca-on="{{ Auth::user()->perm('call_actions', 'view') && Auth::user()->perm('events', 'view') ? '1' : '' }}"
        data-ca-store-url="{{ route('ui.v2.api.call_actions.store') }}">
        {{-- Map config (provider IDs, API keys) inyectado para la capa JS --}}
        <script type="application/json" id="tp-events-map-config">@json($mapConfig)</script>
        {{-- Tipos de respuesta reales para el modal "Atender" (mismas etiquetas que la tabla) --}}
        <script type="application/json" id="tp-events-resp-types">@json($responseTypes ?? [])</script>
        {{-- Catálogo completo de tipos de evento (mismas etiquetas que el legacy) para el filtro --}}
        <script type="application/json" id="tp-events-types">@json($eventTypes ?? [])</script>

        {{-- LIST PANEL --}}
        <aside class="tp-events__list-panel">
            <header class="tp-events__head">
                <h3 class="tp-h6" style="margin:0;">{{ trans('front.driving_events') }}</h3>
                <select class="tp-input" style="width:100%" id="tp-events-hours">
                    <option value="0" selected>{{ trans('front.all') }}</option>
                    <option value="1">{{ trans('front.last_hour') }}</option>
                    <option value="6">{{ trans('ui.last_6_hours') }}</option>
                    <option value="24">{{ trans('ui.last_24_hours') }}</option>
                    <option value="72">{{ trans('ui.last_3_days') }}</option>
                    <option value="168">{{ trans('ui.last_7_days') }}</option>
                </select>
                {{-- Rango absoluto (paridad EventModalHelper: date_from/date_to).
                     Si se rellena, tiene prioridad sobre la ventana relativa. --}}
                <details class="tp-events__range">
                    <summary class="tp-text-xs tp-text-muted">{{ trans('ui.date_range') }}</summary>
                    <div class="tp-row" style="gap:6px;margin-top:6px;">
                        <input type="date" class="tp-input tp-input--sm" id="tp-events-from" title="{{ trans('front.from') }}">
                        <input type="date" class="tp-input tp-input--sm" id="tp-events-to" title="{{ trans('global.to') }}">
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-events-range-clear"
                            title="Limpiar rango">×</button>
                    </div>
                </details>
                <select class="tp-input" style="width:100%" id="tp-events-type">
                    <option value="">{{ trans('ui.all_types') }}</option>
                </select>
                <select class="tp-input" style="width:100%" id="tp-events-device">
                    <option value="">{{ trans('ui.all_vehicles') }}</option>
                    @foreach ($devices ?? [] as $d)
                        <option value="{{ $d['id'] }}">{{ $d['name'] }}</option>
                    @endforeach
                </select>
                <div class="tp-input-wrap">
                    <input type="text" class="tp-input tp-input--with-icon" id="tp-events-search"
                        placeholder="{{ trans('ui.search_event') }}" autocomplete="off">
                </div>
            </header>

            <div class="tp-events__list" id="tp-events-list" role="listbox" aria-label="{{ trans('ui.event_list') }}">
                <div class="tp-text-xs tp-text-muted" style="padding:12px;">Cargando eventos…</div>
            </div>

            <footer class="tp-events__foot">
                <span class="tp-text-xs tp-text-muted" id="tp-events-status">—</span>
                <div class="tp-row" style="gap:6px;">
                    <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-events-export" type="button">{{ trans('front.export') }}</button>
                    <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-events-refresh" type="button">{{ trans('ui.refresh') }}</button>
                    @if (Auth::user()->perm('events', 'remove'))
                        <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-events-clear" type="button"
                            title="{{ trans('ui.delete_all_my_events') }}">{{ trans('ui.delete_all') }}</button>
                    @endif
                </div>
            </footer>
        </aside>

        {{-- MAP --}}
        <main class="tp-events__map-area">
            <div id="tp-events-map" class="tp-events__map"></div>
            <div class="tp-events__hint" id="tp-events-hint">{{ trans('ui.pick_event_for_map') }}</div>

            {{-- Controles de mapa --}}
            <div class="tp-events__map-controls">
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-fullscreen"
                    title="{{ trans('front.fullscreen') }}" aria-label="{{ trans('front.fullscreen') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                        <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-style"
                    title="{{ trans('ui.change_map_type') }}" aria-label="{{ trans('ui.change_map_type') }}" aria-haspopup="menu">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 22 8.5 12 15 2 8.5 12 2" />
                        <polyline points="2 15.5 12 22 22 15.5" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-zoom-in"
                    title="{{ trans('front.zoom_in') }}" aria-label="{{ trans('front.zoom_in') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ev-zoom-out"
                    title="{{ trans('front.zoom_out') }}" aria-label="{{ trans('front.zoom_out') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-geofences" title="{{ trans('ui.show_geofences') }}" aria-label="{{ trans('ui.show_geofences') }}"
                    aria-pressed="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="4 7 12 3 20 7 20 17 12 21 4 17 4 7" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-routes" title="{{ trans('ui.show_routes') }}" aria-label="{{ trans('ui.show_routes') }}" aria-pressed="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="19" r="2" />
                        <circle cx="18" cy="5" r="2" />
                        <path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-poi" title="{{ trans('ui.show_pois') }}" aria-label="{{ trans('ui.show_pois') }}" aria-pressed="false">
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
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 10v11" />
                        <rect x="6" y="3" width="12" height="7" rx="1.5" />
                        <path d="M9 6.5h6" />
                    </svg>
                </button>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-ev-toggle"
                    id="tp-ev-toggle-risk" title="{{ trans('ui.risk_map_all_events') }}"
                    aria-label="{{ trans('ui.risk_map') }}" aria-pressed="false">
                    {{-- Mapa de riesgo: capas/calor --}}
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7z" />
                        <circle cx="12" cy="9" r="2.5" />
                    </svg>
                </button>
            </div>

            {{-- Leyenda flotante del mapa de riesgo (severidad) --}}
            <div class="tp-events__legend" id="tp-ev-legend" hidden>
                <div class="tp-events__legend-title">{{ trans('ui.risk_map') }}</div>
                <div class="tp-events__legend-row"><span class="tp-events__legend-dot"
                        style="background:#ef4444"></span> {{ trans('admin.status_critico') }}</div>
                <div class="tp-events__legend-row"><span class="tp-events__legend-dot"
                        style="background:#f59e0b"></span> Advertencia</div>
                <div class="tp-events__legend-row"><span class="tp-events__legend-dot"
                        style="background:#3b82f6"></span> Informativo</div>
                <div class="tp-events__legend-note" id="tp-ev-legend-note"></div>
            </div>

            {{-- Menú de tipos de mapa (selector; persiste en user->map_id, compartido con Live) --}}
            <div class="tp-events__providers" id="tp-ev-providers" role="menu" hidden></div>
        </main>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-basemaps.js') }}"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/events.js') }}"></script>
@endpush
