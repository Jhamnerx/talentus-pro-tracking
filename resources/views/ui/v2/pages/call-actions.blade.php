@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.call_actions'))
@section('rail-active', 'call_actions')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=5">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-callactions-page"
        data-list-url="{{ route('ui.v2.api.call_actions') }}"
        data-meta-url="{{ route('ui.v2.api.call_actions.meta') }}"
        data-events-url="{{ route('ui.v2.api.call_actions.events') }}"
        data-store-url="{{ route('ui.v2.api.call_actions.store') }}"
        data-item-url="{{ url('/ui/v2/api/call-actions') . '/{id}' }}"
        data-can-edit="{{ !empty($canEdit) ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13 1 .37 1.96.72 2.88a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.11-.45c.92.35 1.88.59 2.88.72A2 2 0 0 1 22 16.92z" />
                    </svg>
                </span>
                {{ trans('front.call_actions') }}
            </h2>
            <div class="tp-maint__tools" style="display:flex; gap:10px; align-items:center;">
                <input type="text" class="tp-input" id="tp-ca-search" placeholder="{{ trans('front.search') }}…" autocomplete="off">
                <select class="tp-input" id="tp-ca-device" style="width:200px"><option value="">Todos los vehículos</option></select>
                <select class="tp-input" id="tp-ca-response" style="width:170px"><option value="">Toda respuesta</option></select>
                <button type="button" class="tp-btn tp-btn--accent" id="tp-ca-add">＋</button>
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-ca-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.device_id') }}</th>
                            <th>{{ trans('validation.attributes.event') }}</th>
                            <th>{{ trans('validation.attributes.response') }}</th>
                            <th>{{ trans('validation.attributes.called_at') }}</th>
                            <th>{{ trans('validation.attributes.remarks') }}</th>
                            <th>{{ trans('validation.attributes.user') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-ca-rows"><tr><td colspan="7" class="tp-svc-table__empty">…</td></tr></tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-ca-limit"><option>20</option><option>50</option><option>100</option></select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-ca-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ca-prev">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ca-next">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    @include('ui.v2.partials.services-config')
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=4" defer></script>
    <script src="{{ asset('assets/ui/v2/js/tp-call-actions.js') }}?v=3" defer></script>
@endpush
