@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.history'))
@section('rail-active', 'history')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/history.css') }}?v=19">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-history tp-history--pinned" id="tp-history" data-data-url="{{ route('ui.v2.api.history') }}"
        data-messages-url="{{ route('ui.v2.api.history.messages') }}"
        data-positions-destroy-url="{{ route('ui.v2.api.history.positions.destroy') }}"
        data-can-remove="{{ Auth::user()->perm('history', 'remove') ? 1 : 0 }}"
        data-devices-url="{{ route('ui.v2.api.history.devices') }}" data-geo-url="{{ route('address.get') }}"
        {{-- Dirección del punto gateada por el plugin igual que el legacy (History/index.blade → @if plugins.history_section_address.status) --}}
        data-addr-on="{{ settings('plugins.history_section_address.status') ? '1' : '' }}"
        data-zones-url="{{ route('ui.v2.api.zones') }}" data-pois-url="{{ route('ui.v2.api.pois') }}"
        data-routes-url="{{ route('ui.v2.api.routes') }}" data-export-url="{{ route('history.export') }}"
        data-position-url="{{ route('history.position') }}"
        data-can-geofences="{{ !empty($canViewGeofences) ? 1 : 0 }}"
        data-can-pois="{{ !empty($canViewPois) ? 1 : 0 }}" data-can-routes="{{ !empty($canViewRoutes) ? 1 : 0 }}">

        <script type="application/json" id="tp-history-map-config">@json($mapConfig)</script>
        {{-- Traducciones para el JS (archivos de traducción, como el legacy) --}}
        <script type="application/json" id="tp-hist-i18n">@json($i18n ?? [])</script>

        <div class="tp-history__body">
            {{-- ============ SIDEBAR (tipo Live) ============ --}}
            <aside class="tp-hist__side" id="tp-hist-side">
                <header class="tp-hist__side-head">
                    <h3 class="tp-h6 tp-mb-0 tp-hist__side-title">
                        <span class="tp-hist__side-titleico" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 9-9 9.74 9.74 0 0 0-7 3.3L3 8" />
                                <path d="M3 3v5h5" />
                                <path d="M12 7v5l4 2" />
                            </svg>
                        </span>
                        {{ trans('front.history') }}
                    </h3>
                    <button class="tp-hist__side-close" id="tp-hist-side-collapse" type="button" title="{{ trans('front.collapse') }}"
                        aria-label="{{ trans('front.collapse') }}">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                </header>

                <div class="tp-hist__side-form">
                    {{-- Device --}}
                    <label class="tp-hist__label">{{ trans('front.device') }}</label>
                    <div class="tp-combo" id="tp-hist-device" data-value="">
                        <input type="text" class="tp-input tp-input--sm tp-combo__input" id="tp-hist-device-input"
                            placeholder="{{ trans('front.select_device') }}…" autocomplete="off" role="combobox"
                            aria-expanded="false">
                        <span class="tp-combo__chevron" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </span>
                        <div class="tp-combo__menu" id="tp-hist-device-menu" role="listbox" hidden></div>
                    </div>

                    {{-- Fecha (abre modal date-picker) --}}
                    <label class="tp-hist__label tp-mt-3">{{ trans('front.date') }}</label>
                    <div class="tp-hist__daterange-wrap">
                        <button type="button" class="tp-hist__daterange" id="tp-hist-daterange">
                            <span id="tp-hist-daterange-label">{{ trans('front.today') }}</span>
                        </button>
                        <button type="button" class="tp-hist__daterange-clear" id="tp-hist-daterange-clear"
                            title="{{ trans('front.reset_to_today') }}" aria-label="{{ trans('front.reset_to_today') }}">×</button>
                    </div>

                    {{-- Avanzado --}}
                    <button type="button" class="tp-hist__adv-toggle" id="tp-hist-adv-toggle" aria-expanded="false">
                        <span>{{ trans('front.advanced_options') }}</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    <div class="tp-hist__adv" id="tp-hist-adv" hidden>
                        <div>
                            <label class="tp-hist__label">{{ trans('front.stops') }}</label>
                            <select class="tp-input tp-input--sm tp-full" id="tp-hist-stops">
                                <option value="0">{{ trans('front.all') }}</option>
                                <option value="60">&gt; 1 min</option>
                                <option value="180" selected>&gt; 3 min</option>
                                <option value="300">&gt; 5 min</option>
                                <option value="600">&gt; 10 min</option>
                                <option value="1800">&gt; 30 min</option>
                            </select>
                        </div>
                        {{-- Paridad legacy (Objects/tabs/history.blade): el ajuste
                             solo existe si hay API key de snap-to-road. --}}
                        <label class="tp-check" @if (!config('services.snaptoroad.key')) hidden @endif>
                            <input type="checkbox" id="tp-hist-snap">
                            <span class="tp-check__box" aria-hidden="true">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m4 12 5 5L20 7" />
                                </svg>
                            </span>
                            <span>{{ trans('front.snap_to_road') }}</span>
                        </label>
                        <label class="tp-check">
                            <input type="checkbox" id="tp-hist-invalid" checked>
                            <span class="tp-check__box" aria-hidden="true">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m4 12 5 5L20 7" />
                                </svg>
                            </span>
                            <span>{{ trans('front.show_invalid_coordinates') }}</span>
                        </label>
                    </div>

                    {{-- Los colores del recorrido son PROPIEDAD del vehículo
                         (modal del device → tab Cola): trips/single/speed/sensor. --}}

                    {{-- Acciones --}}
                    <div class="tp-hist__actions tp-mt-3">
                        <button class="tp-btn tp-btn--accent" id="tp-hist-load" type="button">
                            {{ trans('front.show_history') }}
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-download" type="button"
                            title="{{ trans('front.download') }}" disabled>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <path d="M7 10l5 5 5-5" />
                                <path d="M12 15V3" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-clear" type="button"
                            title="Limpiar" disabled>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path
                                    d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m-9 0v14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V6" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Timeline (viajes/paradas/eventos) --}}
                <div class="tp-hist__timeline" id="tp-hist-timeline">
                    <div class="tp-hist__side-empty" id="tp-hist-tl-empty">
                        <span class="tp-text-muted tp-text-sm">{{ trans('ui.select_vehicle_and_date') }}
                            <strong>{{ trans('ui.show_history') }}</strong>.</span>
                    </div>
                </div>
            </aside>

            {{-- ============ MAPA ============ --}}
            <div class="tp-history__map-area">
                <div class="tp-history__map" id="tp-history-map"></div>

                {{-- Handle para reabrir el panel flotante cuando está colapsado --}}
                <button class="tp-hist__side-handle" id="tp-hist-side-expand" type="button" title="{{ trans('front.show_panel') }}" aria-label="{{ trans('front.show_panel') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>

                {{-- Cards resumen flotantes (Tailwind) --}}
                <div class="tp-history__summary" id="tp-hist-summary" hidden>
                    @php
                        // unit = '' donde el valor YA viene con unidad desde la API
                        // (pasa por Formatter). Solo vavg se calcula aqui en el
                        // cliente y llega en crudo, asi que conserva la suya.
                        $sumCards = [
                            ['id' => 'dist', 'label' => trans('front.distance'), 'unit' => ''],
                            ['id' => 'dur', 'label' => trans('front.duration'), 'unit' => ''],
                            ['id' => 'vmax', 'label' => trans('front.max_speed'), 'unit' => ''],
                            ['id' => 'vavg', 'label' => trans('front.average_speed'), 'unit' => 'km/h'],
                            ['id' => 'drives', 'label' => trans('front.trips'), 'unit' => ''],
                            ['id' => 'stops', 'label' => trans('front.stops'), 'unit' => ''],
                            ['id' => 'pts', 'label' => trans('front.points'), 'unit' => ''],
                        ];
                    @endphp
                    @foreach ($sumCards as $c)
                        <div
                            class="rounded-xl border border-slate-200 bg-white/95 shadow-lg px-3.5 py-2 min-w-[92px] backdrop-blur-sm">
                            <div class="text-[10px] font-medium uppercase tracking-wide text-slate-500">
                                {{ $c['label'] }}
                            </div>
                            <div class="flex items-baseline gap-1">
                                <span class="text-lg font-semibold text-slate-800 tabular-nums"
                                    id="tp-hist-sum-{{ $c['id'] }}">—</span>
                                @if ($c['unit'])
                                    <span class="text-[10px] text-slate-400">{{ $c['unit'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="tp-history__loading" id="tp-hist-loading" hidden>
                    <div class="tp-history__loading-inner">
                        <div class="tp-spinner" aria-hidden="true"></div>
                        <span>{{ trans('front.loading') }}…</span>
                    </div>
                </div>

                {{-- Controles de mapa --}}
                {{-- Controles de mapa: GRUPOS flotantes separados (orden legacy:
                     pantalla completa · tipo de mapa · regla · capas · zoom) --}}
                <div class="tp-history__map-controls">
                    {{-- 1 · Vista completa (despeja la UI: solo mapa) --}}
                    <div class="tp-hist-mcg tp-hist-mcg--fs">
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-fullscreen"
                            type="button" title="{{ trans('front.fullscreen') }}" aria-pressed="false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3" />
                            </svg>
                        </button>
                    </div>
                    {{-- 2 · Tipo de mapa --}}
                    <div class="tp-hist-mcg tp-hist-mcg--maps">
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-style" type="button"
                            title="{{ trans('front.change_map_type') }}" aria-haspopup="menu">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 3-6 2v16l6-2 6 2 6-2V3l-6 2-6-2z" />
                                <path d="M9 3v16M15 5v16" />
                            </svg>
                        </button>
                    </div>
                    {{-- 3 · Regla de medición --}}
                    <div class="tp-hist-mcg tp-hist-mcg--tools">
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-ruler" type="button"
                            title="{{ trans('front.ruler') }}" aria-pressed="false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="9" width="20" height="7" rx="1.5" transform="rotate(-20 12 12.5)" />
                                <path d="m7.2 10.4 1 2.7M11 9l1 2.7M14.8 7.6l1 2.7" />
                            </svg>
                        </button>
                    </div>
                    {{-- 4-7 · Capas del historial (recorrido / flechas / paradas / eventos) --}}
                    <div class="tp-hist-mcg tp-hist-mcg--layers">
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle" data-display="route"
                            id="tp-hist-toggle-route" type="button" title="{{ trans('front.show_route') }}"
                            aria-pressed="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="6" cy="19" r="2.4" />
                                <circle cx="18" cy="5" r="2.4" />
                                <path d="M8.2 18h6.3a4 4 0 0 0 0-8H9.5a4 4 0 0 1 0-8h6.3" transform="rotate(180 12 10)" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle" data-display="arrows"
                            id="tp-hist-toggle-arrows" type="button" title="{{ trans('front.arrows') }}"
                            aria-pressed="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="3 11 22 2 13 21 11 13 3 11" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle" data-display="stops"
                            id="tp-hist-toggle-stops" type="button" title="{{ trans('front.stops') }}"
                            aria-pressed="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="4" width="16" height="16" rx="3" />
                                <path d="M10 16V8h3a2.5 2.5 0 0 1 0 5h-3" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle" data-display="events"
                            id="tp-hist-toggle-events" type="button" title="{{ trans('front.events') }}"
                            aria-pressed="false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle" data-display="hitos"
                            id="tp-hist-toggle-hitos" type="button" title="{{ trans('front.show_km_markers') }}"
                            aria-pressed="{{ !empty($mapConfig['toggles']['hitos']) ? 'true' : 'false' }}">
                            {{-- Hito vial: poste con placa km --}}
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 10v11" />
                                <rect x="6" y="3" width="12" height="7" rx="1.5" />
                                <path d="M9 6.5h6" />
                            </svg>
                        </button>
                        {{-- Overlays de mapa (geocercas / rutas / POIs) — estado sembrado de
                             user->map_controls (mismo store que Live); history.js ya sabe
                             cargarlos (setOverlay) y los pinta al iniciar si vienen en true. --}}
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle"
                            data-display="geofences" id="tp-hist-toggle-geofences" type="button"
                            title="{{ trans('front.geofences') }}"
                            aria-pressed="{{ !empty($mapConfig['toggles']['geofences']) ? 'true' : 'false' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="4 7 12 3 20 7 20 17 12 21 4 17 4 7" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle"
                            data-display="routes" id="tp-hist-toggle-routes" type="button"
                            title="{{ trans('front.routes') }}"
                            aria-pressed="{{ !empty($mapConfig['toggles']['routes']) ? 'true' : 'false' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="6" cy="19" r="2" />
                                <circle cx="18" cy="5" r="2" />
                                <path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-hist__toggle"
                            data-display="poi" id="tp-hist-toggle-poi" type="button"
                            title="{{ trans('front.poi') }}"
                            aria-pressed="{{ !empty($mapConfig['toggles']['poi']) ? 'true' : 'false' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s7-7.58 7-13a7 7 0 0 0-14 0c0 5.42 7 13 7 13z" />
                                <circle cx="12" cy="9" r="2.5" />
                            </svg>
                        </button>
                    </div>
                    {{-- 8-9 · Zoom --}}
                    <div class="tp-hist-mcg tp-hist-mcg--zoom">
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-zoom-in" type="button"
                            title="{{ trans('front.zoom_in') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 5v14" />
                                <path d="M5 12h14" />
                            </svg>
                        </button>
                        <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-zoom-out" type="button"
                            title="{{ trans('front.zoom_out') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Panel de la regla (encender / apagar / limpiar) --}}
                <div class="tp-ruler-panel" id="tp-hist-ruler-panel" hidden>
                    <div class="tp-ruler-panel__head">
                        <span class="tp-ruler-panel__title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="9" width="20" height="7" rx="1.5" transform="rotate(-20 12 12.5)" />
                                <path d="m7.2 10.4 1 2.7M11 9l1 2.7M14.8 7.6l1 2.7" />
                            </svg>
                            {{ trans('front.ruler') }}
                        </span>
                        <button type="button" class="tp-ruler-panel__close" id="tp-hist-ruler-close"
                            aria-label="{{ trans('global.cancel') }}">×</button>
                    </div>
                    <div class="tp-ruler-panel__seg">
                        <button type="button" id="tp-hist-ruler-off" class="is-active">{{ trans('front.off') }}</button>
                        <button type="button" id="tp-hist-ruler-on">{{ trans('front.on') }}</button>
                    </div>
                    <button type="button" class="tp-ruler-panel__clear"
                        id="tp-hist-ruler-clear">{{ trans('global.clear') }}</button>
                </div>

                <div class="tp-menu tp-history__providers-menu" id="tp-hist-providers-menu" role="menu" hidden></div>

                {{-- HUD de reproducción: velocidad + brújula (solo al reproducir) --}}
                <div class="tp-hist__pb" id="tp-hist-pb" hidden aria-hidden="true">
                    <div class="tp-hist__pb-circle" id="tp-hist-pb-speed">
                        <strong id="tp-hist-pb-speedval">0</strong>
                        <span id="tp-hist-pb-speedunit">kph</span>
                    </div>
                    <div class="tp-hist__pb-circle tp-hist__pb-compass" title="Curso">
                        <svg viewBox="0 0 48 48" width="46" height="46">
                            <circle cx="24" cy="24" r="21" fill="#3b82f6" />
                            <circle cx="24" cy="24" r="21" fill="none" stroke="rgba(255,255,255,.7)" stroke-width="1.5" />
                            <g id="tp-hist-pb-needle" style="transform-origin:24px 24px;transition:transform .25s ease;">
                                <polygon points="24,8 28,24 20,24" fill="#ef4444" />
                                <polygon points="24,40 28,24 20,24" fill="#ffffff" />
                                <circle cx="24" cy="24" r="2.4" fill="#0f172a" />
                            </g>
                        </svg>
                    </div>
                </div>

                {{-- Leyenda de velocidad: barra y ticks los construye el JS
                     con los rangos configurados en el vehículo --}}
                <div class="tp-history__legend" id="tp-hist-legend" hidden>
                    <div class="tp-history__legend-bar"></div>
                    <div class="tp-history__legend-ticks"></div>
                </div>
            </div>
        </div>

        {{-- ============ FOOTER: Gráfico / Registro de datos ============ --}}
        <footer class="tp-history__bottom" id="tp-hist-bottom" hidden>
            <div class="tp-history__bottom-head">
                {{-- IZQUIERDA: icono + tabs de sensores (Velocidad/Altitud +
                     sensores con add_to_graph; los pinta el JS al cargar) --}}
                <span class="tp-history__bottom-ico" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 3v18h18" />
                        <path d="m7 14 4-4 3 3 5-6" />
                    </svg>
                </span>
                <div class="tp-gtabs" role="tablist" id="tp-hist-sensor">
                    <button class="tp-gtab is-active" data-sensor="speed" type="button">{{ trans('front.speed') }}</button>
                    <button class="tp-gtab" data-sensor="altitude" type="button">{{ trans('front.altitude') }}</button>
                </div>

                {{-- DERECHA: Gráfico/Registro + pin + colapsar --}}
                <div class="tp-history__bottom-right">
                    <div class="tp-history__bottom-tabs" role="tablist">
                        <button class="tp-history__bottom-tab is-active" data-bottom-tab="graph"
                            type="button">{{ trans('front.graph') }}</button>
                        <button class="tp-history__bottom-tab" data-bottom-tab="datalog" type="button">{{ trans('front.data_log') }}</button>
                    </div>
                    <div class="tp-history__bottom-tools">
                    {{-- Pin: ajustar el footer al ancho que deja el sidebar --}}
                    <button class="tp-history__bottom-tool" id="tp-hist-bottom-pin" type="button"
                        title="{{ trans('front.fit_to_panel') }}" aria-pressed="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 17v5" />
                            <path d="M9 3h6l-1 7 3 2v2H7v-2l3-2-1-7z" />
                        </svg>
                    </button>
                    {{-- Colapsar footer (dejar libre el mapa) --}}
                    <button class="tp-history__bottom-tool" id="tp-hist-bottom-collapse" type="button"
                        title="{{ trans('front.hide_panel') }}" aria-pressed="false">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    </div>
                </div>{{-- /bottom-right --}}
            </div>

            {{-- Panel Gráfico --}}
            <div class="tp-history__bottom-panel" data-bottom-panel="graph">
                {{-- Fila 2: reproductor (izq) + lectura del punto y zoom (der) --}}
                <div class="tp-history__gbar" id="tp-hist-player">
                    <div class="tp-history__gbar-left">
                        <button class="tp-history__gbtn" id="tp-hist-play" type="button" title="{{ trans('front.play') }} / {{ trans('front.pause') }}">
                            <svg id="tp-hist-play-icon" width="14" height="14" viewBox="0 0 24 24"
                                fill="currentColor">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>
                        <button class="tp-history__gbtn" id="tp-hist-stop" type="button" title="{{ trans('front.stop_playback') }}">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="5" y="5" width="14" height="14" rx="2" />
                            </svg>
                        </button>
                        <select class="tp-history__gsel" id="tp-hist-speed-sel" title="{{ trans('front.playback_speed') }}">
                            <option value="1">x1</option>
                            <option value="4">x4</option>
                            <option value="8" selected>x8</option>
                            <option value="16">x16</option>
                            <option value="32">x32</option>
                            <option value="120">x120</option>
                        </select>
                    </div>
                    <div class="tp-history__gbar-right">
                        <span class="tp-history__greadout">
                            <strong id="tp-hist-cursor-speed">—</strong>
                            <span id="tp-hist-cursor-time">—</span>
                        </span>
                        <button class="tp-history__gbtn tp-history__gbtn--ghost" id="tp-hist-gzoom-in" type="button"
                            title="{{ trans('front.zoom_in') }}">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m21 21-4.35-4.35M11 8v6M8 11h6" />
                            </svg>
                        </button>
                        <button class="tp-history__gbtn tp-history__gbtn--ghost" id="tp-hist-gzoom-out" type="button"
                            title="{{ trans('front.zoom_out') }}">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m21 21-4.35-4.35M8 11h6" />
                            </svg>
                        </button>
                        <button class="tp-history__gbtn tp-history__gbtn--ghost" id="tp-hist-gzoom-reset" type="button"
                            title="{{ trans('front.reset_zoom') }}">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                                <path d="M3 3v5h5" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="tp-history__graph-canvas-wrap">
                    <canvas id="tp-hist-graph" class="tp-history__graph-canvas"></canvas>
                </div>
            </div>

            {{-- Panel Registro de datos --}}
            <div class="tp-history__bottom-panel" data-bottom-panel="datalog" hidden>
                <div class="tp-history__datalog-wrap">
                    <table class="tp-history__datalog" id="tp-hist-datalog">
                        <thead id="tp-hist-datalog-head"></thead>
                        <tbody id="tp-hist-datalog-body"></tbody>
                    </table>
                </div>
                <div class="tp-history__datalog-foot">
                    <span class="tp-text-xs tp-text-muted" id="tp-hist-datalog-info">—</span>
                    <div class="tp-history__datalog-pager">
                        {{-- Borrar posiciones seleccionadas (legacy: acción multi
                             del data-log, perm history.remove) --}}
                        @if (Auth::user()->perm('history', 'remove'))
                            <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-hist-datalog-del" type="button"
                                style="color:#ef4444;" disabled>{{ trans('global.delete') }}</button>
                        @endif
                        {{-- Orden del registro: el backend ya lo soporta (sorting) --}}
                        <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-hist-datalog-sort" type="button"
                            title="{{ trans('front.date') }}" data-sort="asc">↑ {{ trans('front.date') }}</button>
                        <label class="tp-text-xs tp-text-muted" style="display:flex;align-items:center;gap:6px;">
                            {{ trans('front.rows_per_page') }}
                            <select class="tp-input tp-input--sm" id="tp-hist-datalog-limit" style="width:76px;">
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                                <option value="500">500</option>
                                <option value="1000">1000</option>
                            </select>
                        </label>
                        <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-hist-datalog-prev" type="button"
                            disabled>{{ trans('front.previous') }}</button>
                        <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-hist-datalog-next" type="button"
                            disabled>{{ trans('front.next') }}</button>
                    </div>
                </div>
            </div>
        </footer>

        {{-- ============ DATE-RANGE PICKER (modal) ============ --}}
        <div class="tp-datepick" id="tp-hist-datepick" hidden>
            <div class="tp-datepick__backdrop" data-datepick-close></div>
            <div class="tp-datepick__panel" role="dialog" aria-label="{{ trans('ui.pick_date') }}">
                <header class="tp-datepick__head">
                    <span class="tp-datepick__title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <path d="M16 2v4M8 2v4M3 10h18" />
                        </svg>
                        {{ trans('front.select_date') }}
                    </span>
                    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-datepick-close type="button"
                        aria-label="Cerrar">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>
                <div class="tp-datepick__body">
                    <div class="tp-datepick__quick" id="tp-hist-dp-quick">
                        <button data-quick="today" type="button">{{ trans('front.today') }}</button>
                        <button data-quick="yesterday" type="button">{{ trans('front.yesterday') }}</button>
                        <button data-quick="2days" type="button">{{ trans('front.two_days_ago') }}</button>
                        <button data-quick="3days" type="button">{{ trans('front.three_days_ago') }}</button>
                        <button data-quick="week" type="button">{{ trans('front.this_week') }}</button>
                        <button data-quick="lastweek" type="button">{{ trans('front.last_week') }}</button>
                        <button data-quick="month" type="button">{{ trans('front.this_month') }}</button>
                        <button data-quick="lastmonth" type="button">{{ trans('front.last_month') }}</button>
                    </div>
                    <div class="tp-datepick__cals">
                        <div class="tp-datepick__cal" id="tp-hist-dp-cal-a"></div>
                        <div class="tp-datepick__cal" id="tp-hist-dp-cal-b"></div>
                        <div class="tp-datepick__times">
                            <div>
                                <label class="tp-hist__label">{{ trans('front.from') }}</label>
                                <div class="tp-datepick__time-row">
                                    <input type="text" class="tp-input tp-input--sm" id="tp-hist-dp-from-date"
                                        readonly>
                                    {{-- Hora como select de pasos de 5 min (tp-select, 24h) —
                                         el input time nativo mostraba AM/PM y se cortaba --}}
                                    <select class="tp-input tp-input--sm" id="tp-hist-dp-from-time" style="width: 92px">
                                        @for ($m = 0; $m < 1440; $m += 5)
                                            <option value="{{ sprintf('%02d:%02d', intdiv($m, 60), $m % 60) }}"
                                                {{ $m === 0 ? 'selected' : '' }}>
                                                {{ sprintf('%02d:%02d', intdiv($m, 60), $m % 60) }}</option>
                                        @endfor
                                        <option value="23:59">23:59</option>
                                    </select>
                                </div>
                            </div>
                            <span class="tp-datepick__sep">—</span>
                            <div>
                                <label class="tp-hist__label">{{ trans('front.to') }}</label>
                                <div class="tp-datepick__time-row">
                                    <input type="text" class="tp-input tp-input--sm" id="tp-hist-dp-to-date" readonly>
                                    <select class="tp-input tp-input--sm" id="tp-hist-dp-to-time" style="width: 92px">
                                        @for ($m = 0; $m < 1440; $m += 5)
                                            <option value="{{ sprintf('%02d:%02d', intdiv($m, 60), $m % 60) }}">
                                                {{ sprintf('%02d:%02d', intdiv($m, 60), $m % 60) }}</option>
                                        @endfor
                                        <option value="23:59" selected>23:59</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <footer class="tp-datepick__foot">
                    <button class="tp-btn tp-btn--ghost tp-btn--sm" data-datepick-close type="button">{{ trans('global.cancel') }}</button>
                    <button class="tp-btn tp-btn--primary tp-btn--sm" id="tp-hist-dp-apply" type="button">{{ trans('front.set_date') }}</button>
                </footer>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylineDecorator.js"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-basemaps.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/history.js') }}?v=29"></script>
@endpush
