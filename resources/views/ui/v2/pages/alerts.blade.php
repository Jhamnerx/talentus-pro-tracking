@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Alerts Center')
@section('rail-active', 'alerts')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/alerts.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-alerts" id="tp-alerts" data-data-url="{{ route('ui.v2.api.alerts') }}"
        data-meta-url="{{ route('ui.v2.api.alerts.meta') }}"
        data-store-url="{{ route('ui.v2.api.alerts.store') }}"
        data-toggle-url="{{ url('/ui/v2/api/alerts') }}"
        data-can-edit="{{ $canEdit ? 1 : 0 }}"
        data-can-create="{{ $canCreate ? 1 : 0 }}">

        {{-- LEFT · RULES --}}
        <aside class="tp-alerts__rules">
            <header class="tp-alerts__rules-head">
                <div>
                    <h1 class="tp-h6 tp-mb-0">Reglas</h1>
                    <span class="tp-text-muted tp-text-sm" id="tp-alerts-rules-count">—</span>
                </div>
                @if ($canCreate)
                    <button class="tp-btn tp-btn--accent tp-btn--icon tp-btn--sm" id="tp-alerts-new" type="button"
                        title="Nueva regla" aria-label="Nueva regla">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                    </button>
                @endif
            </header>

            <div class="tp-alerts__rules-search">
                <input type="search" class="tp-input tp-input--sm" id="tp-alerts-rules-search" placeholder="Buscar regla…"
                    autocomplete="off">
            </div>

            <div class="tp-alerts__rules-list" id="tp-alerts-rules-list" role="list">
                <div class="tp-text-muted tp-text-center" style="padding:24px;">Cargando…</div>
            </div>
        </aside>

        {{-- RIGHT · FEED --}}
        <section class="tp-alerts__feed">
            <header class="tp-alerts__feed-head">
                <div class="tp-row" style="gap:14px; align-items:center; flex-wrap:wrap;">
                    <h2 class="tp-h5 tp-mb-0">Feed de eventos</h2>
                    <span class="tp-text-muted tp-text-sm" id="tp-alerts-feed-count">—</span>
                </div>

                <div class="tp-row" style="gap:8px; flex-wrap:wrap; align-items:center;">
                    <div class="tp-alerts__seg" role="tablist" aria-label="Ventana de tiempo">
                        <button class="tp-seg__btn" role="tab" data-hours="1">1h</button>
                        <button class="tp-seg__btn is-active" role="tab" data-hours="24">24h</button>
                        <button class="tp-seg__btn" role="tab" data-hours="72">3d</button>
                        <button class="tp-seg__btn" role="tab" data-hours="168">7d</button>
                    </div>

                    <div class="tp-alerts__seg" role="tablist" aria-label="Severidad">
                        <button class="tp-seg__btn is-active" role="tab" data-sev="">Todas</button>
                        <button class="tp-seg__btn" role="tab" data-sev="critical">Críticas</button>
                        <button class="tp-seg__btn" role="tab" data-sev="warning">Aviso</button>
                        <button class="tp-seg__btn" role="tab" data-sev="info">Info</button>
                    </div>

                    <input type="search" class="tp-input tp-input--sm" id="tp-alerts-feed-search"
                        placeholder="Buscar en mensaje o dirección…" style="min-width:240px;">

                    <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-alerts-export" title="Exportar CSV">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                            <polyline points="7 10 12 15 17 10" />
                            <line x1="12" y1="15" x2="12" y2="3" />
                        </svg>
                        <span>Exportar</span>
                    </button>
                    <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-alerts-refresh" title="Actualizar">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M21 12a9 9 0 11-3-6.7L21 8" />
                            <path d="M21 3v5h-5" />
                        </svg>
                    </button>
                </div>
            </header>

            <div class="tp-alerts__feed-list" id="tp-alerts-feed-list" role="list">
                <div class="tp-text-muted tp-text-center" style="padding:48px;">Cargando…</div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/ui/v2/js/alerts.js') }}?v=1" defer></script>
@endpush
