@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.routes'))
@section('rail-active', 'routes')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/routes-table.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-routes-page" data-url="{{ route('ui.v2.api.routes') }}"
        data-live-url="{{ url('/ui/v2/live?panel=routes') }}"
        data-export-url="{{ route('routes.export.store') }}" data-import-url="{{ route('routes.import') }}"
        data-can-edit="{{ auth()->user()->perm('routes', 'edit') ? '1' : '0' }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="19" r="2" />
                        <circle cx="18" cy="5" r="2" />
                        <path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6" />
                    </svg>
                </span>
                {{ trans('front.routes') }}
            </h2>
            <div class="tp-maint__tools">
                <input type="text" class="tp-input" id="tp-routes-tbl-search"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                @if (auth()->user()->perm('routes', 'edit'))
                    <button type="button" class="tp-btn tp-btn--accent tp-btn--icon" id="tp-routes-tbl-new"
                        title="{{ trans('front.add_route') }}" aria-label="{{ trans('front.add_route') }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                    </button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon" id="tp-routes-tbl-export"
                        title="{{ trans('front.export') }}" aria-label="{{ trans('front.export') }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v12M7 8l5-5 5 5M5 21h14" />
                        </svg>
                    </button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon" id="tp-routes-tbl-import"
                        title="{{ trans('front.import') }}" aria-label="{{ trans('front.import') }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 15V3M7 10l5 5 5-5M5 21h14" />
                        </svg>
                    </button>
                @endif
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-routes-tbl">
                    <thead>
                        <tr>
                            <th data-sort="active">{{ trans('validation.visible') }}</th>
                            <th data-sort="name">{{ trans('validation.attributes.name') }}</th>
                            <th data-sort="color">{{ trans('validation.color') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-routes-tbl-rows">
                        <tr>
                            <td colspan="4" class="tp-svc-table__empty">{{ trans('front.loading') }}…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-routes-tbl-limit">
                        <option>20</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-routes-tbl-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-routes-tbl-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-routes-tbl-next"
                        aria-label="{{ trans('front.next') }}">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/ui/v2/js/routes-table.js') }}?v=4" defer></script>
@endpush
