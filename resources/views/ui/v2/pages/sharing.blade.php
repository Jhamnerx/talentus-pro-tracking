@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.sharing'))
@section('rail-active', 'sharing')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=4">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-sharing-page"
        data-list-url="{{ route('ui.v2.api.sharing') }}"
        data-meta-url="{{ route('ui.v2.api.sharing.meta') }}"
        data-store-url="{{ route('ui.v2.api.sharing.store') }}"
        data-item-url="{{ url('/ui/v2/api/sharing') . '/{id}' }}"
        data-device-id="{{ $deviceId ?? '' }}"
        data-can-edit="{{ !empty($canEdit) ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="18" cy="5" r="3" />
                        <circle cx="6" cy="12" r="3" />
                        <circle cx="18" cy="19" r="3" />
                        <path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" />
                    </svg>
                </span>
                {{ trans('front.sharing') }}
            </h2>
            <div class="tp-maint__tools" style="display: flex; gap: 10px; align-items: center;">
                <input type="text" class="tp-input" id="tp-sharing-search"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                <select class="tp-input" id="tp-sharing-device" style="width: 200px"></select>
                <button type="button" class="tp-btn tp-btn--accent" id="tp-sharing-add">＋</button>
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-sharing-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.active') }}</th>
                            <th>{{ trans('validation.attributes.name') }}</th>
                            <th>Enlace</th>
                            <th>{{ trans('front.expired') }}</th>
                            <th>{{ trans('validation.attributes.expiration_date') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-sharing-rows">
                        <tr>
                            <td colspan="6" class="tp-svc-table__empty">…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-sharing-limit">
                        <option>20</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-sharing-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-sharing-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-sharing-next"
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
    <script src="{{ asset('assets/ui/v2/js/tp-sharing.js') }}?v=4" defer></script>
@endpush
