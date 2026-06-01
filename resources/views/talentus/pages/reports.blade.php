@extends('talentus.layout')

@section('title', $pageTitle ?? 'Reports')
@section('rail-active', 'reports')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/talentus/css/reports.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--scroll')

@section('workspace')
    <div class="tp-reports" id="tp-reports" data-data-url="{{ route('talentus.api.reports') }}"
        data-can-create="{{ $canCreate ? 1 : 0 }}">

        <header class="tp-reports__head">
            <div>
                <h1 class="tp-h5 tp-mb-0">Reportes</h1>
                <p class="tp-text-muted tp-text-sm tp-mb-0">Genera, programa y descarga reportes operativos.</p>
            </div>
            <div class="tp-row" style="gap:8px;">
                <a class="tp-btn tp-btn--ghost tp-btn--sm" href="{{ url('/reports/logs') }}" target="_blank" rel="noopener"
                    title="Historial completo en UI cl�sica">
                    Historial completo
                </a>
                @if ($canCreate)
                    <a class="tp-btn tp-btn--primary tp-btn--sm" href="{{ url('/reports') }}" target="_blank"
                        rel="noopener">
                        + Nuevo reporte programado
                    </a>
                @endif
            </div>
        </header>

        {{-- ===== QUICK GENERATE ===== --}}
        <section class="tp-reports__panel">
            <header class="tp-reports__panel-head">
                <h2 class="tp-h6 tp-mb-0">Generaci�n r�pida</h2>
                <span class="tp-text-muted tp-text-sm">Selecciona plantilla, rango y veh�culos. Se abre la generaci�n en la
                    UI cl�sica.</span>
            </header>

            <form class="tp-reports__form" id="tp-rep-form" onsubmit="return false;">
                <div class="tp-reports__form-row">
                    <div class="tp-reports__field" style="grid-column: span 2;">
                        <label class="tp-text-muted tp-text-xs" for="rep-type">Plantilla</label>
                        <select class="tp-input tp-input--sm" id="rep-type">
                            <option value="">Cargando�</option>
                        </select>
                    </div>

                    <div class="tp-reports__field">
                        <label class="tp-text-muted tp-text-xs" for="rep-format">Formato</label>
                        <select class="tp-input tp-input--sm" id="rep-format">
                            <option value="pdf">PDF</option>
                        </select>
                    </div>

                    <div class="tp-reports__field">
                        <label class="tp-text-muted tp-text-xs" for="rep-from">Desde</label>
                        <input class="tp-input tp-input--sm" type="datetime-local" id="rep-from">
                    </div>

                    <div class="tp-reports__field">
                        <label class="tp-text-muted tp-text-xs" for="rep-to">Hasta</label>
                        <input class="tp-input tp-input--sm" type="datetime-local" id="rep-to">
                    </div>
                </div>

                <div class="tp-reports__quick" id="tp-rep-quick" role="tablist">
                    <button class="tp-seg__btn" data-quick="today">Hoy</button>
                    <button class="tp-seg__btn is-active" data-quick="yesterday">Ayer</button>
                    <button class="tp-seg__btn" data-quick="7d">�ltimos 7d</button>
                    <button class="tp-seg__btn" data-quick="month">Mes actual</button>
                    <button class="tp-seg__btn" data-quick="lastmonth">Mes pasado</button>
                </div>

                <div class="tp-reports__devices" id="tp-rep-devices-wrap">
                    <div class="tp-row" style="justify-content:space-between; align-items:center;">
                        <label class="tp-text-muted tp-text-xs">Veh�culos</label>
                        <div class="tp-row" style="gap:8px;">
                            <input class="tp-input tp-input--sm" type="search" id="tp-rep-devices-q"
                                placeholder="Buscar veh�culo�" style="max-width:200px;">
                            <button class="tp-btn tp-btn--ghost tp-btn--xs" id="tp-rep-devices-all"
                                type="button">Todos</button>
                            <button class="tp-btn tp-btn--ghost tp-btn--xs" id="tp-rep-devices-none"
                                type="button">Ninguno</button>
                            <span class="tp-text-muted tp-text-xs" id="tp-rep-devices-count">0</span>
                        </div>
                    </div>
                    <div class="tp-reports__device-list" id="tp-rep-devices" tabindex="0" aria-label="Veh�culos">
                        <div class="tp-text-muted tp-text-center" style="padding:24px;">Cargando�</div>
                    </div>
                </div>

                <footer class="tp-reports__form-foot">
                    <span class="tp-text-muted tp-text-xs" id="tp-rep-help">Se abrir� en nueva pesta�a.</span>
                    <button class="tp-btn tp-btn--primary tp-btn--sm" id="tp-rep-go" type="button">Generar</button>
                </footer>
            </form>
        </section>

        {{-- ===== SAVED TEMPLATES ===== --}}
        <section class="tp-reports__panel">
            <header class="tp-reports__panel-head">
                <h2 class="tp-h6 tp-mb-0">Plantillas guardadas</h2>
                <span class="tp-text-muted tp-text-sm" id="tp-rep-saved-count">�</span>
            </header>

            <div class="tp-reports__grid" id="tp-rep-saved">
                <div class="tp-text-muted tp-text-center" style="padding:24px; grid-column:1/-1;">Cargando�</div>
            </div>
        </section>

        {{-- ===== RUN LOGS ===== --}}
        <section class="tp-reports__panel">
            <header class="tp-reports__panel-head">
                <h2 class="tp-h6 tp-mb-0">Ejecuciones recientes</h2>
                <span class="tp-text-muted tp-text-sm" id="tp-rep-logs-count">�</span>
            </header>

            <div class="tp-reports__table-wrap">
                <table class="tp-table tp-table--sm" id="tp-rep-logs-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>T�tulo</th>
                            <th>Formato</th>
                            <th>Tama�o</th>
                            <th>Estado</th>
                            <th style="text-align:right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tp-rep-logs-body">
                        <tr>
                            <td colspan="6" class="tp-text-muted tp-text-center" style="padding:24px;">Cargando�</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/talentus/js/reports.js') }}?v=1"></script>
@endpush
