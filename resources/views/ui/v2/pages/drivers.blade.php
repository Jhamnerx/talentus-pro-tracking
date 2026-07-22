@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.drivers'))
@section('rail-active', 'drivers')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=10">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-drivers-page"
        data-list-url="{{ route('ui.v2.api.drivers') }}"
        data-meta-url="{{ route('ui.v2.api.drivers.meta') }}"
        data-store-url="{{ route('ui.v2.api.drivers.store') }}"
        data-item-url="{{ url('/ui/v2/api/drivers') . '/{id}' }}"
        data-can-edit="{{ !empty($canEdit) ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </span>
                {{ trans('front.drivers') }}
            </h2>
            <div class="tp-maint__tools" style="display: flex; gap: 10px; align-items: center;">
                <input type="text" class="tp-input" id="tp-drivers-search"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                <select class="tp-input" id="tp-drivers-assigned" style="width: 190px">
                    <option value="">{{ trans('global.all') }}</option>
                    <option value="with">Con dispositivos</option>
                    <option value="without">Sin dispositivos</option>
                </select>
                <button type="button" class="tp-btn tp-btn--accent" id="tp-drivers-add">＋</button>
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-drivers-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.name') }}</th>
                            <th>{{ trans('front.current_device') }}</th>
                            <th>RFID</th>
                            <th>{{ trans('validation.attributes.phone') }}</th>
                            <th>{{ trans('validation.attributes.email') }}</th>
                            <th>{{ trans('validation.attributes.description') }}</th>
                            <th>{{ trans('front.devices') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-drivers-rows">
                        <tr>
                            <td colspan="8" class="tp-svc-table__empty">…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-drivers-limit">
                        <option>20</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-drivers-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-drivers-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-drivers-next"
                        aria-label="{{ trans('front.next') }}">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    {{-- tp-services aporta el toggle global del kebab (.tp-svc-kebab) --}}
    @include('ui.v2.partials.services-config')
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=3" defer></script>
    <script src="{{ asset('assets/ui/v2/js/tp-drivers.js') }}?v=2" defer></script>
@endpush
