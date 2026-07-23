@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.tasks'))
@section('rail-active', 'tasks')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=10">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-tasks-page"
        data-list-url="{{ route('ui.v2.api.tasks') }}"
        data-meta-url="{{ route('ui.v2.api.tasks.meta') }}"
        data-store-url="{{ route('ui.v2.api.tasks.store') }}"
        data-item-url="{{ url('/ui/v2/api/tasks') . '/{id}' }}"
        data-address-search-url="{{ route('address.search') }}"
        data-address-reverse-url="{{ route('address.reverse') }}"
        data-can-edit="{{ !empty($canEdit) ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12l4 4L15 8" />
                        <path d="M9 12l4 4 8-8" />
                    </svg>
                </span>
                {{ trans('front.tasks') }}
            </h2>
            <div class="tp-maint__tools" style="display: flex; gap: 10px; align-items: center;">
                <input type="text" class="tp-input" id="tp-tasks-search"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                <select class="tp-input" id="tp-tasks-device" style="width: 210px">
                    <option value="">Todos los vehículos</option>
                </select>
                <select class="tp-input" id="tp-tasks-status" style="width: 170px">
                    <option value="">Todos los estados</option>
                    @foreach ($statuses as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
                <button type="button" class="tp-btn tp-btn--accent" id="tp-tasks-add">＋</button>
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-tasks-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.title') }}</th>
                            <th>{{ trans('validation.attributes.device_id') }}</th>
                            <th>{{ trans('validation.attributes.status') }}</th>
                            <th>{{ trans('validation.attributes.priority') }}</th>
                            <th>{{ trans('validation.attributes.invoice_number') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-tasks-rows">
                        <tr>
                            <td colspan="6" class="tp-svc-table__empty">…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-tasks-limit">
                        <option>20</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-tasks-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-tasks-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-tasks-next"
                        aria-label="{{ trans('front.next') }}">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    {{-- tp-services aporta el toggle global del kebab (.tp-svc-kebab) --}}
    @include('ui.v2.partials.services-config')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=3" defer></script>
    <script src="{{ asset('assets/ui/v2/js/tp-tasks.js') }}?v=1" defer></script>
@endpush
