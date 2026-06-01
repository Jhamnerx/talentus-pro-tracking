@extends('talentus.layout')

@section('title', $pageTitle ?? 'Zones & Routes')
@section('rail-active', 'zones')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/talentus/css/zones.css') }}?v=13">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-zones" id="tp-zones" data-data-url="{{ route('talentus.api.zones') }}"
        data-meta-url="{{ route('talentus.api.zones.meta') }}" data-store-url="{{ route('talentus.api.zones.store') }}"
        data-base-url="{{ url('/talentus/api/zones') }}" data-can-edit="{{ $canEdit ? 1 : 0 }}"
        data-can-create="{{ $canCreate ? 1 : 0 }}" data-legacy-new="{{ url('/geofences') }}"
        data-legacy-edit="{{ url('/geofences') }}" data-import-url="{{ route('talentus.api.zones.import') }}"
        data-export-items-url="{{ route('talentus.api.zones.export_items') }}"
        data-export-url="{{ route('geofences.export_create') }}">
        @php
            $exportTypes = [
                \Tobuli\Exporters\Util\ExportTypesUtil::EXPORT_TYPE_SINGLE => trans('front.export_single'),
                \Tobuli\Exporters\Util\ExportTypesUtil::EXPORT_TYPE_GROUPS => trans('front.export_groups'),
                \Tobuli\Exporters\Util\ExportTypesUtil::EXPORT_TYPE_ACTIVE => trans('front.export_active'),
                \Tobuli\Exporters\Util\ExportTypesUtil::EXPORT_TYPE_INACTIVE => trans('front.export_inactive'),
            ];
        @endphp
        <script type="application/json" id="tp-zones-export-types">@json($exportTypes)</script>

        <script type="application/json" id="tp-zones-map-config">@json($mapConfig)</script>

        {{-- LEFT PANEL --}}
        <aside class="tp-zones__panel">
            <header class="tp-zones__panel-head">
                <h1 class="tp-h6 tp-mb-0">Zones & Routes</h1>
                <span class="tp-text-muted tp-text-sm" id="tp-zones-count">�</span>
            </header>

            <div class="tp-zones__search">
                <input type="search" class="tp-input tp-input--sm" id="tp-zones-search" placeholder="Buscar geocerca�"
                    autocomplete="off">
            </div>

            <div class="tp-zones__filters" role="tablist">
                <button class="tp-seg__btn is-active" role="tab" data-type="">Todas</button>
                <button class="tp-seg__btn" role="tab" data-type="polygon">Pol�gonos</button>
                <button class="tp-seg__btn" role="tab" data-type="circle">C�rculos</button>
            </div>

            <div class="tp-zones__group-row">
                <label class="tp-text-sm tp-text-muted" for="tp-zones-group">Grupo</label>
                <select id="tp-zones-group" class="tp-input tp-input--sm">
                    <option value="">Todos los grupos</option>
                </select>
            </div>

            <div class="tp-zones__list" id="tp-zones-list" role="listbox" aria-label="Geocercas">
                <div class="tp-text-muted tp-text-center" style="padding:24px;">Cargando�</div>
            </div>

            @if ($canCreate)
                <footer class="tp-zones__panel-foot">
                    <button type="button" class="tp-btn tp-btn--primary tp-btn--sm tp-full" id="tp-zones-new"
                        title="Crear nueva geocerca">
                        + Nueva geocerca
                    </button>
                    <div class="tp-row" style="gap:6px; margin-top:8px;">
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm tp-full" id="tp-zones-export"
                            title="Exportar geocercas">Exportar</button>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm tp-full" id="tp-zones-import"
                            title="Importar geocercas">Importar</button>
                    </div>
                </footer>
            @endif
        </aside>

        {{-- MAP --}}
        <section class="tp-zones__map-area">
            <div class="tp-zones__map" id="tp-zones-map"></div>

            <div class="tp-zones__map-controls">
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-zones-fit"
                    title="Encajar todas las geocercas">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4" />
                    </svg>
                </button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-zones-providers-btn"
                    title="Tipo de mapa" aria-haspopup="true" aria-expanded="false">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21" />
                        <line x1="8" y1="3" x2="8" y2="18" />
                        <line x1="16" y1="6" x2="16" y2="21" />
                    </svg>
                </button>
                <div class="tp-menu" id="tp-zones-providers-menu" role="menu" hidden></div>
            </div>

            {{-- Inline editor panel (replaces modal) --}}
            <aside class="tp-zones__editor" id="tp-zones-editor" hidden aria-label="Editor de geocerca">
                <header class="tp-zones__editor-head">
                    <h3 class="tp-h6 tp-mb-0" id="tp-zones-editor-title">Nueva geocerca</h3>
                    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-zones-editor-close"
                        type="button" aria-label="Cerrar editor">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </header>
                <form id="tp-zones-editor-form" autocomplete="off" novalidate>
                    <div id="tp-zones-editor-body"></div>
                </form>
                <footer class="tp-zones__editor-foot">
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm"
                        id="tp-zones-editor-cancel">Cancelar</button>
                    <button type="button" class="tp-btn tp-btn--primary tp-btn--sm"
                        id="tp-zones-editor-save">Guardar</button>
                </footer>
            </aside>

            <div class="tp-zones__detail" id="tp-zones-detail" hidden>
                <header class="tp-zones__detail-head">
                    <div class="tp-row" style="gap:8px; align-items:center; min-width:0;">
                        <span class="tp-zones__detail-swatch" id="tp-zones-detail-swatch"></span>
                        <h3 class="tp-h6 tp-mb-0 tp-text-truncate" id="tp-zones-detail-name">�</h3>
                    </div>
                    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-zones-detail-close"
                        aria-label="Cerrar">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </header>
                <dl class="tp-zones__detail-grid">
                    <dt>Tipo</dt>
                    <dd id="tp-zones-detail-type">�</dd>
                    <dt>Estado</dt>
                    <dd id="tp-zones-detail-active">�</dd>
                    <dt>Grupo</dt>
                    <dd id="tp-zones-detail-group">�</dd>
                    <dt>V�rtices / Radio</dt>
                    <dd id="tp-zones-detail-size">�</dd>
                    <dt>Velocidad l�mite</dt>
                    <dd id="tp-zones-detail-speed">�</dd>
                </dl>
                @if ($canEdit)
                    <div class="tp-row" style="gap:6px; margin-top:12px;">
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm"
                            id="tp-zones-detail-edit">Editar</button>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-zones-detail-del"
                            style="margin-left:auto; color:var(--status-alert,#ef4444);">Eliminar</button>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
    <script src="https://unpkg.com/leaflet-geometryutil@0.10.3/src/leaflet.geometryutil.js"></script>
    <script src="{{ asset('assets/talentus/js/zones.js') }}?v=13"></script>
@endpush
