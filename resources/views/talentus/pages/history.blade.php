@extends('talentus.layout')

@section('title', $pageTitle ?? 'History & Replay')
@section('rail-active', 'history')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/talentus/css/history.css') }}?v=1">
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-history" id="tp-history" data-data-url="{{ route('talentus.api.history') }}"
        data-devices-url="{{ route('talentus.api.devices') }}">

        <script type="application/json" id="tp-history-map-config">@json($mapConfig)</script>

        {{-- TOP TOOLBAR --}}
        <header class="tp-history__toolbar">
            <div class="tp-row" style="gap:8px; align-items:center; flex-wrap:wrap;">
                <div class="tp-history__field">
                    <label class="tp-text-muted tp-text-xs">Veh�culo</label>
                    <select class="tp-input tp-input--sm" id="tp-hist-device" style="min-width:220px;">
                        <option value="">Cargando�</option>
                    </select>
                </div>

                <div class="tp-history__field">
                    <label class="tp-text-muted tp-text-xs">Desde</label>
                    <input class="tp-input tp-input--sm" type="datetime-local" id="tp-hist-from">
                </div>

                <div class="tp-history__field">
                    <label class="tp-text-muted tp-text-xs">Hasta</label>
                    <input class="tp-input tp-input--sm" type="datetime-local" id="tp-hist-to">
                </div>

                <div class="tp-seg" role="tablist" aria-label="Rango r�pido" id="tp-hist-quick">
                    <button class="tp-seg__btn" data-quick="1h">1h</button>
                    <button class="tp-seg__btn is-active" data-quick="today">Hoy</button>
                    <button class="tp-seg__btn" data-quick="yesterday">Ayer</button>
                    <button class="tp-seg__btn" data-quick="7d">7d</button>
                </div>

                <button class="tp-btn tp-btn--primary tp-btn--sm" id="tp-hist-load">Cargar</button>
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-hist-legacy"
                    title="Abrir reporte detallado en UI cl�sica">Detalle cl�sico</button>
            </div>

            <div class="tp-history__kpis" id="tp-hist-kpis" hidden>
                <div class="tp-history__kpi"><span class="tp-history__kpi-label">Distancia</span><strong
                        id="kpi-dist">�</strong><span class="tp-text-muted tp-text-xs">km</span></div>
                <div class="tp-history__kpi"><span class="tp-history__kpi-label">Duraci�n</span><strong
                        id="kpi-dur">�</strong></div>
                <div class="tp-history__kpi"><span class="tp-history__kpi-label">V. m�x</span><strong
                        id="kpi-max">�</strong><span class="tp-text-muted tp-text-xs">km/h</span></div>
                <div class="tp-history__kpi"><span class="tp-history__kpi-label">V. prom</span><strong
                        id="kpi-avg">�</strong><span class="tp-text-muted tp-text-xs">km/h</span></div>
                <div class="tp-history__kpi"><span class="tp-history__kpi-label">Paradas</span><strong
                        id="kpi-stops">�</strong></div>
                <div class="tp-history__kpi"><span class="tp-history__kpi-label">Puntos</span><strong
                        id="kpi-pts">�</strong></div>
            </div>
        </header>

        {{-- MAP + GRAPH --}}
        <section class="tp-history__map-area">
            <div class="tp-history__map" id="tp-history-map"></div>

            <div class="tp-history__empty" id="tp-hist-empty">
                <div class="tp-history__empty-icon" aria-hidden="true">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.5">
                        <path d="M3 12h4l3-8 4 16 3-8h4" />
                    </svg>
                </div>
                <h2 class="tp-h6">Reproductor de recorridos</h2>
                <p class="tp-text-muted">Selecciona un veh�culo, un rango y pulsa <strong>Cargar</strong>.</p>
            </div>

            <div class="tp-history__loading" id="tp-hist-loading" hidden>
                <div class="tp-spinner" aria-hidden="true"></div>
                <span>Cargando recorrido�</span>
            </div>
        </section>

        {{-- BOTTOM SCRUBBER --}}
        <footer class="tp-history__scrub" id="tp-hist-scrub" hidden>
            <div class="tp-history__scrub-controls">
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-prev" title="Anterior parada">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M19 20L9 12l10-8M5 19V5" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--primary tp-btn--icon tp-btn--sm" id="tp-hist-play"
                    title="Reproducir / Pausar">
                    <svg id="tp-hist-play-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M8 5v14l11-7z" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-hist-next" title="Siguiente parada">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M5 4l10 8-10 8M19 5v14" />
                    </svg>
                </button>
                <div class="tp-seg tp-seg--xs" role="tablist" aria-label="Velocidad" id="tp-hist-speed">
                    <button class="tp-seg__btn" data-rate="1">1�</button>
                    <button class="tp-seg__btn is-active" data-rate="4">4�</button>
                    <button class="tp-seg__btn" data-rate="16">16�</button>
                    <button class="tp-seg__btn" data-rate="64">64�</button>
                </div>
            </div>

            <div class="tp-history__scrub-track">
                <div class="tp-history__scrub-time tp-text-xs tp-text-muted" id="tp-hist-time-start">�</div>
                <div class="tp-history__scrub-rail">
                    <input type="range" class="tp-history__scrub-range" id="tp-hist-range" min="0"
                        max="100" step="1" value="0" aria-label="Posici�n temporal">
                    <div class="tp-history__scrub-marks" id="tp-hist-marks" aria-hidden="true"></div>
                </div>
                <div class="tp-history__scrub-time tp-text-xs tp-text-muted" id="tp-hist-time-end">�</div>
            </div>

            <div class="tp-history__scrub-info">
                <div class="tp-history__scrub-current">
                    <span class="tp-text-muted tp-text-xs">Cursor</span>
                    <strong id="tp-hist-cursor-time">�</strong>
                    <span class="tp-pill tp-pill--info tp-pill--sm" id="tp-hist-cursor-speed">� km/h</span>
                </div>
            </div>
        </footer>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
    <script src="{{ asset('assets/talentus/js/history.js') }}?v=1"></script>
@endpush
