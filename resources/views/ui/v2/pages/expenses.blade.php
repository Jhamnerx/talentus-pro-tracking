@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.expenses'))
@section('rail-active', 'expenses')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/services.css') }}">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-expenses-page"
        data-url="{{ route('ui.v2.api.expenses') }}"
        data-meta-url="{{ route('ui.v2.api.expenses.meta') }}"
        data-store-url="{{ route('ui.v2.api.expenses.store') }}"
        data-item-url="{{ url('/ui/v2/api/expenses') . '/{id}' }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="6" width="20" height="12" rx="2" />
                        <circle cx="12" cy="12" r="2.5" />
                        <path d="M6 12h.01M18 12h.01" />
                    </svg>
                </span>
                {{ trans('front.expenses') }}
            </h2>
            <div class="tp-maint__tools" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input aria-label="{{ trans('ui.search_ellipsis') }}" type="text" class="tp-input" id="tp-exp-search" placeholder="{{ trans('ui.search_ellipsis') }}" autocomplete="off">
                <select class="tp-input" id="tp-exp-device" style="width:200px"><option value="">{{ trans('ui.all_vehicles') }}</option></select>
                <input type="date" class="tp-input" id="tp-exp-from" title="{{ trans('front.from') }}">
                <input type="date" class="tp-input" id="tp-exp-to" title="{{ trans('global.to') }}">
                <button type="button" class="tp-btn tp-btn--accent" id="tp-exp-add">＋</button>
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-exp-table">
                    <thead>
                        <tr>
                            <th>{{ trans('ui.vehicle') }}</th>
                            <th>{{ trans('ui.name') }}</th>
                            <th>{{ trans('front.type') }}</th>
                            <th style="text-align:right;">{{ trans('global.quantity') }}</th>
                            <th style="text-align:right;">Costo unit.</th>
                            <th style="text-align:right;">{{ trans('front.count') }}</th>
                            <th>{{ trans('front.date') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-exp-rows"><tr><td colspan="8" class="tp-svc-table__empty">…</td></tr></tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <span class="tp-text-muted" id="tp-exp-total">—</span>
                <div class="tp-svc-list__pager">
                    <span id="tp-exp-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-exp-prev">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-exp-next">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset_resource('assets/ui/v2/js/tp-expenses.js') }}" defer></script>
@endpush
