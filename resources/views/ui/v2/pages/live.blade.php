@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Live Ops · Mapa')
@section('rail-active', in_array(request('panel'), ['pois', 'zones']) ? request('panel') : 'live')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/live-ops.css') }}?v=24">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=1">
    {{-- Leaflet (mismo motor que el legacy) — migración desde MapLibre --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-live" id="tp-live" data-devices-url="{{ route('ui.v2.api.devices') }}"
        data-updates-url="{{ route('ui.v2.api.devices.updates') }}"
        data-zones-url="{{ route('ui.v2.api.zones') }}" data-pois-url="{{ route('ui.v2.api.pois') }}"
        data-routes-url="{{ route('ui.v2.api.routes') }}" data-geo-url="{{ route('address.get') }}"
        data-socket-port="{{ $socketPort }}"
        data-socket-ssl-port="{{ $socketSslPort }}" data-poll-interval="15000">

        {{-- Map config (provider IDs, API keys) injected for the JS layer --}}
        <script type="application/json" id="tp-live-map-config">@json($mapConfig)</script>

        {{-- Device CRUD modal config (shared with fleet / admin → fleet) --}}
        @php
            $tpDevicesBase = url('/ui/v2/api/devices');
            $tpDeviceModalConfig = [
                'formNewUrl' => route('ui.v2.api.devices.form_new'),
                'formUrl' => $tpDevicesBase . '/{id}/form',
                'storeUrl' => route('ui.v2.api.devices.store'),
                'updateUrl' => $tpDevicesBase . '/{id}',
                'canCreate' => $canCreate ?? false,
                'canEdit' => $canCreate ?? false,
            ];
        @endphp
        <script type="application/json" id="tp-device-modal-config">@json($tpDeviceModalConfig)</script>

        {{-- LEFT FILTER PANEL --}}
        <aside class="tp-live__filters" id="tp-live-filters">
            {{-- Panel switcher: un solo mapa, el sidebar cambia de panel (como el legacy) --}}
            <div class="tp-live__paneltabs">
                <div class="tp-live__paneltabs-group" role="tablist">
                    <button class="tp-live__paneltab is-active" data-panel-tab="objects" type="button">Objetos</button>
                    <button class="tp-live__paneltab" data-panel-tab="zones" type="button">Geocercas</button>
                    <button class="tp-live__paneltab" data-panel-tab="pois" type="button">POIs</button>
                </div>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" type="button" id="tp-live-filters-collapse"
                    title="Colapsar panel" aria-label="Colapsar panel">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </button>
            </div>

            <div class="tp-live__panel" id="tp-panel-objects" data-panel="objects">
                <header class="tp-live__filters-head">
                    <div class="tp-row tp-row-between" style="width:100%;">
                        <h3 class="tp-h6 tp-mb-0">Flota</h3>
                    <div class="tp-row" style="gap:6px;align-items:center;">
                        @if (!empty($canCreate))
                            <button class="tp-btn tp-btn--accent tp-btn--icon tp-btn--sm" type="button"
                                id="tp-live-create-device" title="Nuevo vehículo" aria-label="Nuevo vehículo">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                            </button>
                        @endif
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" type="button"
                            id="tp-live-group-toggle" title="Agrupar por grupo" aria-label="Agrupar por grupo"
                            aria-pressed="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 7h18M3 12h18M3 17h18" />
                                <circle cx="6" cy="7" r="0.5" fill="currentColor" />
                            </svg>
                        </button>
                        <a class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" href="{{ route('ui.v2.fleet') }}"
                            id="tp-live-listview" title="Ver lista de vehículos" aria-label="Ver lista de vehículos">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
                            </svg>
                        </a>
                    </div>
                </div>

                {{-- Search --}}
                <div class="tp-input-wrap tp-mt-3">
                    <span class="tp-input-wrap__icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                    </span>
                    <input type="text" class="tp-input tp-input--with-icon" id="tp-live-search"
                        placeholder="Buscar nombre, placa, IMEI…" autocomplete="off">
                </div>

                {{-- Status filter --}}
                <div class="tp-segmented tp-segmented--full tp-segmented--grid2 tp-mt-3" role="tablist"
                    id="tp-live-status-filter">
                    <button class="tp-segmented__item" data-filter="all" aria-selected="true" type="button"
                        title="Todos">
                        <span class="tp-segmented__label">Todos</span>
                        <span class="tp-segmented__count" data-count="all">0</span>
                    </button>
                    <button class="tp-segmented__item" data-filter="moving" type="button" title="En movimiento">
                        <span class="tp-segmented__label"><span class="tp-dot tp-dot--moving"></span><span
                                class="tp-segmented__text">Movimiento</span></span>
                        <span class="tp-segmented__count" data-count="moving">0</span>
                    </button>
                    <button class="tp-segmented__item" data-filter="idle" type="button" title="Ralentí">
                        <span class="tp-segmented__label"><span class="tp-dot tp-dot--idle"></span><span
                                class="tp-segmented__text">Ralentí</span></span>
                        <span class="tp-segmented__count" data-count="idle">0</span>
                    </button>
                    <button class="tp-segmented__item" data-filter="offline" type="button" title="Desconectado">
                        <span class="tp-segmented__label"><span class="tp-dot tp-dot--offline"></span><span
                                class="tp-segmented__text">Desconectado</span></span>
                        <span class="tp-segmented__count" data-count="offline">0</span>
                    </button>
                </div>
            </header>

            {{-- Virtualized list --}}
            <div class="tp-live__list-wrap">
                <div class="tp-live__list" id="tp-live-list" role="listbox" aria-label="Lista de vehículos">
                    {{-- Skeleton --}}
                    <div class="tp-live__skeleton">
                        @for ($i = 0; $i < 8; $i++)
                            <div class="tp-live__row tp-live__row--skeleton">
                                <div class="sk sk-dot"></div>
                                <div class="sk sk-line sk-line-1"></div>
                                <div class="sk sk-line sk-line-2"></div>
                            </div>
                        @endfor
                    </div>
                    <div class="tp-live__empty" hidden>
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                        <div class="tp-mt-2">Sin resultados</div>
                    </div>
                </div>
            </div>

            <footer class="tp-live__filters-foot">
                <span class="tp-text-xs tp-text-muted" id="tp-live-status-text">Cargando…</span>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-live-refresh" type="button">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-3.5-7.1" />
                        <path d="M21 4v5h-5" />
                    </svg>
                    Refrescar
                </button>
            </footer>
            </div>{{-- /#tp-panel-objects --}}

            {{-- POIs panel — mismo mapa de Live (no crea otro mapa) --}}
            <div class="tp-live__panel" id="tp-panel-pois" data-panel="pois" hidden>
                <header class="tp-live__filters-head">
                    <div class="tp-row tp-row-between" style="width:100%;">
                        <h3 class="tp-h6 tp-mb-0">POIs <span class="tp-text-muted tp-text-sm"
                                id="tp-pois-count"></span></h3>
                        @if (auth()->user()->perm('poi', 'edit'))
                            <button class="tp-btn tp-btn--accent tp-btn--icon tp-btn--sm" type="button" id="tp-pois-new"
                                title="Nuevo POI" aria-label="Nuevo POI">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                            </button>
                        @endif
                    </div>
                    <div class="tp-input-wrap tp-mt-3">
                        <span class="tp-input-wrap__icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.35-4.35" />
                            </svg>
                        </span>
                        <input type="text" class="tp-input tp-input--with-icon" id="tp-pois-search"
                            placeholder="Buscar POI…" autocomplete="off">
                    </div>
                    <div class="tp-mt-3">
                        <select id="tp-pois-group" class="tp-input tp-input--sm tp-full">
                            <option value="">Todos los grupos</option>
                        </select>
                    </div>
                </header>
                <div class="tp-live__list-wrap">
                    <div class="tp-pois-list" id="tp-pois-list" role="listbox" aria-label="Lista de POIs"></div>
                    <div class="tp-live__empty" id="tp-pois-empty" hidden>
                        <div class="tp-mt-2">Sin POIs</div>
                    </div>
                </div>
                <footer class="tp-live__filters-foot" style="gap:6px;">
                    <a class="tp-btn tp-btn--ghost tp-btn--sm tp-full" href="{{ route('pois.export') }}"
                        target="_blank">Exportar</a>
                    <a class="tp-btn tp-btn--ghost tp-btn--sm tp-full" href="{{ route('pois.import') }}"
                        target="_blank">Importar</a>
                </footer>
            </div>{{-- /#tp-panel-pois --}}

            {{-- Geocercas panel — mismo mapa de Live --}}
            <div class="tp-live__panel" id="tp-panel-zones" data-panel="zones" hidden>
                <header class="tp-live__filters-head">
                    <div class="tp-row tp-row-between" style="width:100%;">
                        <h3 class="tp-h6 tp-mb-0">Geocercas <span class="tp-text-muted tp-text-sm"
                                id="tp-zones-count"></span></h3>
                        @if (auth()->user()->perm('geofences', 'edit'))
                            <button class="tp-btn tp-btn--accent tp-btn--icon tp-btn--sm" type="button" id="tp-zones-new"
                                title="Nueva geocerca" aria-label="Nueva geocerca">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                            </button>
                        @endif
                    </div>
                    <div class="tp-input-wrap tp-mt-3">
                        <span class="tp-input-wrap__icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.35-4.35" />
                            </svg>
                        </span>
                        <input type="text" class="tp-input tp-input--with-icon" id="tp-zones-search"
                            placeholder="Buscar geocerca…" autocomplete="off">
                    </div>
                    <div class="tp-segmented tp-segmented--full tp-mt-3" role="tablist" id="tp-zones-type-filter">
                        <button class="tp-segmented__item" data-ztype="all" aria-selected="true"
                            type="button">Todas</button>
                        <button class="tp-segmented__item" data-ztype="polygon" type="button">Polígonos</button>
                        <button class="tp-segmented__item" data-ztype="circle" type="button">Círculos</button>
                    </div>
                    <div class="tp-mt-3">
                        <select id="tp-zones-group" class="tp-input tp-input--sm tp-full">
                            <option value="">Todos los grupos</option>
                        </select>
                    </div>
                </header>
                <div class="tp-live__list-wrap">
                    <div class="tp-poi-list" id="tp-zones-list" role="listbox" aria-label="Lista de geocercas"></div>
                    <div class="tp-live__empty" id="tp-zones-empty" hidden>
                        <div class="tp-mt-2">Sin geocercas</div>
                    </div>
                </div>
                <footer class="tp-live__filters-foot" style="gap:6px;">
                    <a class="tp-btn tp-btn--ghost tp-btn--sm tp-full" href="{{ url('/geofences/export') }}"
                        target="_blank">Exportar</a>
                    <a class="tp-btn tp-btn--ghost tp-btn--sm tp-full" href="{{ url('/geofences/import_modal') }}"
                        target="_blank">Importar</a>
                </footer>
            </div>{{-- /#tp-panel-zones --}}
        </aside>

        {{-- COLLAPSED FILTERS HANDLE (when filters panel is hidden) --}}
        <button class="tp-live__filters-handle" id="tp-live-filters-expand" type="button" hidden title="Mostrar lista"
            aria-label="Mostrar lista de vehículos">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6" />
            </svg>
        </button>

        {{-- MAP --}}
        <main class="tp-live__map-area">
            <div id="tp-live-map" class="tp-live__map"></div>

            {{-- Map overlay: stats + actions --}}
            <div class="tp-live__map-overlay">
                <div class="tp-live__map-stats" aria-live="polite">
                    <span class="tp-live__map-stat">
                        <span class="tp-dot tp-dot--moving"></span>
                        <strong id="tp-live-stat-moving">0</strong>
                        <span>en movimiento</span>
                    </span>
                    <span class="tp-live__map-stat">
                        <span class="tp-dot tp-dot--idle"></span>
                        <strong id="tp-live-stat-idle">0</strong>
                        <span>en ralentí</span>
                    </span>
                    <span class="tp-live__map-stat">
                        <span class="tp-dot tp-dot--offline"></span>
                        <strong id="tp-live-stat-offline">0</strong>
                        <span>desconectados</span>
                    </span>
                </div>
            </div>

            {{-- Map controls (top-right) --}}
            <div class="tp-live__map-controls">
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-fullscreen"
                    title="Pantalla completa" aria-label="Pantalla completa" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                        <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-style-toggle"
                    title="Cambiar capa de mapa" type="button" aria-haspopup="menu" aria-expanded="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 22 8.5 12 15 2 8.5 12 2" />
                        <polyline points="2 15.5 12 22 22 15.5" />
                    </svg>
                </button>

                <div class="tp-live__map-controls-divider" aria-hidden="true"></div>

                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-zoom-in" title="Acercar"
                    aria-label="Acercar" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-zoom-out" title="Alejar"
                    aria-label="Alejar" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" />
                    </svg>
                </button>

                <div class="tp-live__map-controls-divider" aria-hidden="true"></div>

                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-fit"
                    title="Ajustar a flota visible" aria-label="Ajustar a flota visible" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 8V6a2 2 0 0 1 2-2h2" />
                        <path d="M16 4h2a2 2 0 0 1 2 2v2" />
                        <path d="M20 16v2a2 2 0 0 1-2 2h-2" />
                        <path d="M8 20H6a2 2 0 0 1-2-2v-2" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-autofollow"
                    title="Seguimiento automático de la flota" aria-label="Seguimiento automático" aria-pressed="false"
                    type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M12 2v3" />
                        <path d="M12 19v3" />
                        <path d="M2 12h3" />
                        <path d="M19 12h3" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-geolocate" title="Mi ubicación"
                    aria-label="Mi ubicación" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <circle cx="12" cy="12" r="3" />
                        <path d="M12 1v3" />
                        <path d="M12 20v3" />
                        <path d="M1 12h3" />
                        <path d="M20 12h3" />
                    </svg>
                </button>

                <div class="tp-live__map-controls-divider" aria-hidden="true"></div>

                {{-- Layer toggles --}}
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-cluster"
                    data-toggle="cluster" title="Agrupar vehículos cercanos" aria-label="Agrupar vehículos"
                    aria-pressed="false" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="9" r="3" />
                        <circle cx="16" cy="14" r="3" />
                        <circle cx="8" cy="16" r="2.5" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-devices"
                    data-toggle="devices" title="Mostrar vehículos" aria-label="Mostrar vehículos" aria-pressed="true"
                    type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 17h2l2-7h10l2 7h2" />
                        <circle cx="7" cy="17" r="2" />
                        <circle cx="17" cy="17" r="2" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-geofences"
                    data-toggle="geofences" title="Mostrar geocercas" aria-label="Mostrar geocercas"
                    aria-pressed="false" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="4 7 12 3 20 7 20 17 12 21 4 17 4 7" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-routes"
                    data-toggle="routes" title="Mostrar rutas" aria-label="Mostrar rutas" aria-pressed="false"
                    type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="19" r="2" />
                        <circle cx="18" cy="5" r="2" />
                        <path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-poi"
                    data-toggle="poi" title="Mostrar POIs" aria-label="Mostrar POIs" aria-pressed="false"
                    type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s7-7.58 7-13a7 7 0 0 0-14 0c0 5.42 7 13 7 13z" />
                        <circle cx="12" cy="9" r="2.5" />
                    </svg>
                </button>
                @if (auth()->user()->perm('poi', 'edit'))
                    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-live-add-poi"
                        title="Agregar POI" aria-label="Agregar POI" type="button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s7-7.58 7-13a7 7 0 0 0-14 0c0 5.42 7 13 7 13z" />
                            <path d="M12 6v6M9 9h6" />
                        </svg>
                    </button>
                @endif
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-names"
                    data-toggle="names" title="Mostrar nombres" aria-label="Mostrar nombres sobre los vehículos"
                    aria-pressed="false" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 7h16" />
                        <path d="M4 12h10" />
                        <path d="M4 17h16" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-tails"
                    data-toggle="tails" title="Mostrar estela de recorrido" aria-label="Mostrar estela"
                    aria-pressed="false" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19c4 0 4-6 8-6s4 6 8 6" />
                        <circle cx="20" cy="13" r="1.5" fill="currentColor" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-live__toggle" id="tp-live-toggle-traffic"
                    data-toggle="traffic" title="Mostrar tráfico en vivo" aria-label="Mostrar tráfico"
                    aria-pressed="false" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="8" y="2" width="8" height="20" rx="3" />
                        <circle cx="12" cy="7" r="1.2" fill="currentColor" />
                        <circle cx="12" cy="12" r="1.2" fill="currentColor" />
                        <circle cx="12" cy="17" r="1.2" fill="currentColor" />
                    </svg>
                </button>
            </div>

            {{-- Map providers menu (positioned by JS) --}}
            <div class="tp-menu tp-live__providers-menu" id="tp-live-providers-menu" role="menu" hidden></div>

            {{-- Live indicator (bottom-left of map) --}}
            <div class="tp-live__pulse-tag" id="tp-live-pulse" aria-live="polite">
                <span class="tp-live__pulse-dot"></span>
                <span>Actualizando cada 15s</span>
            </div>
        
            {{-- Bottom widget cards (servicios, eventos, vista de calle, cámara, conductor, video) --}}
            <div class="tp-live__bottombar" id="tp-live-bottombar" aria-hidden="true">
                <div class="tp-live__bottombar-cards" id="tp-live-bottombar-cards"></div>
                <div class="tp-live__bottombar-ctl">
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-bottombar-config" data-config-url="{{ route('ui.v2.api.widgets.config') }}" data-save-url="{{ route('ui.v2.api.widgets.config_save') }}" title="Configurar widgets" aria-label="Configurar widgets">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                        </svg>
                    </button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-bottombar-collapse"
                        title="Colapsar widgets" aria-label="Colapsar widgets">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                </div>
            </div>
            <button type="button" class="tp-live__bottombar-handle" id="tp-bottombar-expand" hidden
                title="Mostrar widgets" aria-label="Mostrar widgets">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="m18 15-6-6-6 6" />
                </svg>
                <span>Widgets</span>
            </button>
        </main>

        {{-- DEVICE DRAWER (right) --}}
        <aside class="tp-live__drawer" id="tp-live-drawer" aria-hidden="true">
            <header class="tp-live__drawer-head">
                <div class="tp-row" style="gap:12px; align-items:center; min-width:0;">
                    <span class="tp-dot" id="tp-drawer-dot"></span>
                    <div style="min-width:0;">
                        <h3 class="tp-h5 tp-mb-0 tp-text-truncate" id="tp-drawer-name">—</h3>
                        <div class="tp-text-xs tp-text-muted tp-text-truncate" id="tp-drawer-plate">—</div>
                    </div>
                </div>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-drawer-close" type="button"
                    aria-label="Cerrar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18" />
                        <path d="m6 6 12 12" />
                    </svg>
                </button>
            </header>

            <div class="tp-live__drawer-body">
                {{-- Status chip --}}
                <div class="tp-mb-4" id="tp-drawer-status-row"></div>

                {{-- KPI cluster --}}
                <div class="tp-grid tp-grid-2 tp-mb-5">
                    <div class="tp-kpi tp-kpi--sm">
                        <div class="tp-kpi__label">Velocidad</div>
                        <div class="tp-kpi__value" id="tp-drawer-speed">—</div>
                        <div class="tp-text-xs tp-text-muted">km/h</div>
                    </div>
                    <div class="tp-kpi tp-kpi--sm">
                        <div class="tp-kpi__label">Rumbo</div>
                        <div class="tp-kpi__value" id="tp-drawer-course">—</div>
                        <div class="tp-text-xs tp-text-muted">grados</div>
                    </div>
                    <div class="tp-kpi tp-kpi--sm">
                        <div class="tp-kpi__label">Detenido</div>
                        <div class="tp-kpi__value" id="tp-drawer-stop">—</div>
                        <div class="tp-text-xs tp-text-muted">tiempo</div>
                    </div>
                    <div class="tp-kpi tp-kpi--sm">
                        <div class="tp-kpi__label">Distancia</div>
                        <div class="tp-kpi__value" id="tp-drawer-distance">—</div>
                        <div class="tp-text-xs tp-text-muted">km totales</div>
                    </div>
                </div>

                {{-- Telemetry list --}}
                <div class="tp-card tp-card--flat tp-mb-4">
                    <h4 class="tp-h6 tp-mb-3">Telemetría</h4>
                    <dl class="tp-defs">
                        <dt>última señal</dt>
                        <dd id="tp-drawer-lastseen">—</dd>
                        <dt>Coordenadas</dt>
                        <dd class="tp-text-mono" id="tp-drawer-coords">—</dd>
                        <dt>Altitud</dt>
                        <dd id="tp-drawer-altitude">—</dd>
                        <dt>Motor</dt>
                        <dd id="tp-drawer-engine">—</dd>
                        <dt>IMEI</dt>
                        <dd class="tp-text-mono" id="tp-drawer-imei">—</dd>
                        <dt>Modelo</dt>
                        <dd id="tp-drawer-model">—</dd>
                        <dt>SIM</dt>
                        <dd class="tp-text-mono" id="tp-drawer-sim">—</dd>
                    </dl>
                </div>

                {{-- Device widgets (cards) — rellenado por live-ops.js al abrir el drawer --}}
                <div id="tp-drawer-widgets" class="tp-dw-wrap"></div>

                {{-- Quick actions --}}
                <div class="tp-stack" style="gap:8px;">
                    <button class="tp-btn tp-btn--secondary tp-btn--block" id="tp-drawer-center" type="button">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M12 2v3" />
                            <path d="M12 19v3" />
                            <path d="M2 12h3" />
                            <path d="M19 12h3" />
                        </svg>
                        Centrar en mapa
                    </button>
                    <button class="tp-btn tp-btn--ghost tp-btn--block" id="tp-drawer-history" type="button" disabled>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12a9 9 0 1 0 9-9 9.74 9.74 0 0 0-7 3.3L3 8" />
                            <path d="M3 3v5h5" />
                            <path d="M12 7v5l4 2" />
                        </svg>
                        Ver historial (Fase 4)
                    </button>
                </div>
            </div>
        </aside>
    </div>

@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
    <script src="https://unpkg.com/leaflet-geometryutil@0.10.3/src/leaflet.geometryutil.js"></script>
    <script src="{{ asset('assets/ui/v2/js/device-modal.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/live.js') }}?v=2"></script>
    <script src="{{ asset('assets/ui/v2/js/live-zones.js') }}?v=2"></script>
    <script>
        // Wire up the "+ Nuevo" button to the shared device modal.
        (function() {
            var btn = document.getElementById('tp-live-create-device');
            if (!btn || !window.tpDeviceModal) return;
            btn.addEventListener('click', function() {
                window.tpDeviceModal.openCreate(function() {
                    // Refresh device feed after a successful create.
                    if (window.tpLive && typeof window.tpLive.refresh === 'function') {
                        window.tpLive.refresh();
                    } else {
                        // Fallback: full reload — kept simple to avoid coupling.
                        location.reload();
                    }
                });
            });
        })();
    </script>
@endpush
