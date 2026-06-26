@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Co-Pilot IA')
@section('rail-active', 'copilot')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/copilot.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--scroll')

@section('workspace')
    <div class="tp-copilot" id="tp-copilot" data-insights-url="{{ route('ui.v2.api.copilot.insights') }}"
        data-ask-url="{{ route('ui.v2.api.copilot.ask') }}">

        <header class="tp-copilot__hero">
            <div class="tp-copilot__hero-orb" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.5">
                    <path d="M12 2l2 5 5 2-5 2-2 5-2-5-5-2 5-2z" />
                </svg>
            </div>
            <div class="tp-copilot__hero-text">
                <h1 class="tp-h4 tp-mb-1">Co-Pilot IA</h1>
                <p class="tp-text-muted tp-mb-0">Tu copiloto operativo: detecta anomalías, propone acciones y responde
                    preguntas en lenguaje natural.</p>
            </div>
            <div class="tp-copilot__hero-meta" id="cp-meta">
                <span class="tp-text-muted tp-text-xs">Analizando…</span>
            </div>
        </header>

        {{-- ===== ASK BOX ===== --}}
        <section class="tp-copilot__ask">
            <form class="tp-copilot__ask-form" id="cp-form" onsubmit="return false;">
                <div class="tp-copilot__ask-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                </div>
                <input type="text" class="tp-copilot__ask-input" id="cp-q"
                    placeholder="Pregúntame algo… ej. "vehículos parados", "excesos de velocidad", "ABC-123""
                    autocomplete="off" maxlength="200">
                <button class="tp-btn tp-btn--primary tp-btn--sm" id="cp-go" type="button">Preguntar</button>
            </form>

            <div class="tp-copilot__suggestions" id="cp-quickq" aria-label="Sugerencias rápidas">
                <button class="tp-copilot__chip" type="button" data-q="vehículos parados">Parados</button>
                <button class="tp-copilot__chip" type="button" data-q="excesos de velocidad">Excesos velocidad</button>
                <button class="tp-copilot__chip" type="button" data-q="vehículos offline">Offline</button>
                <button class="tp-copilot__chip" type="button" data-q="alertas sos">SOS</button>
                <button class="tp-copilot__chip" type="button" data-q="reporte de ayer">Reporte ayer</button>
                <button class="tp-copilot__chip" type="button" data-q="historial">Historial</button>
            </div>

            <div class="tp-copilot__chat" id="cp-chat" hidden></div>
        </section>

        {{-- ===== KPIs ===== --}}
        <section class="tp-copilot__kpis" id="cp-kpis" hidden>
            <div class="tp-copilot__kpi"><span>Flota total</span><strong id="kpi-total">—</strong></div>
            <div class="tp-copilot__kpi"><span>Utilización</span><strong id="kpi-util">—</strong></div>
            <div class="tp-copilot__kpi"><span>Eventos 24h</span><strong id="kpi-evt">—</strong></div>
            <div class="tp-copilot__kpi"><span>Insights</span><strong id="kpi-ins">—</strong></div>
        </section>

        {{-- ===== INSIGHTS ===== --}}
        <section class="tp-copilot__panel">
            <header class="tp-copilot__panel-head">
                <h2 class="tp-h6 tp-mb-0">Insights & recomendaciones</h2>
                <button class="tp-btn tp-btn--ghost tp-btn--xs" id="cp-refresh" type="button" title="Re-analizar">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M3 12a9 9 0 0114-7l4 4M21 12a9 9 0 01-14 7l-4-4M21 5v6h-6M3 19v-6h6" />
                    </svg>
                    Refrescar
                </button>
            </header>
            <div class="tp-copilot__insights" id="cp-insights">
                <div class="tp-text-muted tp-text-center" style="padding:32px;">Analizando tu operación…</div>
            </div>
        </section>

        {{-- ===== SUGGESTIONS ===== --}}
        <section class="tp-copilot__panel">
            <header class="tp-copilot__panel-head">
                <h2 class="tp-h6 tp-mb-0">Próximas acciones</h2>
            </header>
            <div class="tp-copilot__shortcuts" id="cp-shortcuts">
                <div class="tp-text-muted tp-text-center" style="padding:24px; grid-column:1/-1;">—</div>
            </div>
        </section>

        <footer class="tp-copilot__foot">
            <span class="tp-text-muted tp-text-xs">
                Co-Pilot opera con heurísticas deterministas sobre tus propios datos.
                No envía información a servicios externos.
            </span>
        </footer>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/ui/v2/js/copilot.js') }}?v=1"></script>
@endpush
