@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.reports'))
@section('rail-active', 'reports')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=3">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=13">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-reports-page"
        data-meta-url="{{ route('ui.v2.api.reports.meta') }}"
        data-saved-url="{{ route('ui.v2.api.reports.saved') }}"
        data-logs-url="{{ route('ui.v2.api.reports.logs') }}"
        data-item-url="{{ url('/ui/v2/api/reports') . '/{id}/item' }}"
        data-store-url="{{ route('ui.v2.api.reports.store') }}"
        data-destroy-url="{{ url('/ui/v2/api/reports') . '/{id}' }}"
        data-log-destroy-url="{{ url('/ui/v2/api/reports/logs') . '/{id}' }}"
        data-open-modal="{{ !empty($openModal) ? 1 : 0 }}"
        data-can-create="{{ !empty($canCreate) ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 12l10 5 10-5M2 17l10 5 10-5" />
                    </svg>
                </span>
                {{ trans('front.reports') }}
            </h2>
        </header>

        {{-- Tabs: definiciones guardadas | logs de programados enviados --}}
        <div style="display: flex; gap: 2px; border-bottom: 1px solid var(--border-default, #e2e8f0); margin-bottom: 14px;">
            <button type="button" class="tp-rep-tab is-active" data-rep-tab="saved"
                style="appearance:none;border:0;border-bottom:2px solid transparent;background:transparent;cursor:pointer;padding:10px 16px;font:inherit;font-size:13px;font-weight:600;">
                Reportes generados</button>
            <button type="button" class="tp-rep-tab" data-rep-tab="logs"
                style="appearance:none;border:0;border-bottom:2px solid transparent;background:transparent;cursor:pointer;padding:10px 16px;font:inherit;font-size:13px;font-weight:500;">
                Logs de programados</button>
        </div>

        <div class="tp-maint__card">
            <div style="display:flex;gap:10px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--border-default,#eef2f7);">
                <input type="text" class="tp-input" id="tp-rep-search" placeholder="{{ trans('front.search') }}…"
                    autocomplete="off" style="max-width: 240px; border-radius: 999px;">
                <select class="tp-input" id="tp-rep-type-filter" style="width: 260px"></select>
                @if (!empty($canCreate))
                    <button type="button" class="tp-btn tp-btn--accent" id="tp-rep-add" style="margin-left:auto;">＋</button>
                @endif
            </div>

            <div class="tp-maint__wrap" data-rep-panel="saved">
                <table class="tp-svc-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.title') }}</th>
                            <th>{{ trans('front.type') }}</th>
                            <th>{{ trans('front.format') }}</th>
                            <th>{{ trans('front.devices') }}</th>
                            <th>{{ trans('front.geofences') }}</th>
                            <th>Programación</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-rep-saved-rows">
                        <tr><td colspan="7" class="tp-svc-table__empty">…</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tp-maint__wrap" data-rep-panel="logs" hidden>
                <table class="tp-svc-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.name') }}</th>
                            <th>{{ trans('front.type') }}</th>
                            <th>{{ trans('front.format') }}</th>
                            <th>Tamaño</th>
                            <th>{{ trans('global.is_send') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-rep-logs-rows">
                        <tr><td colspan="6" class="tp-svc-table__empty">…</td></tr>
                    </tbody>
                </table>
            </div>

            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-rep-limit">
                        <option>20</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-rep-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-rep-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-rep-next"
                        aria-label="{{ trans('front.next') }}">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    {{-- tp-services aporta el toggle global del kebab (.tp-svc-kebab) --}}
    @include('ui.v2.partials.services-config')
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=4" defer></script>
    <script src="{{ asset('assets/ui/v2/js/tp-chips-select.js') }}?v=1" defer></script>
    <script src="{{ asset('assets/ui/v2/js/reports.js') }}?v=14" defer></script>
@endpush
