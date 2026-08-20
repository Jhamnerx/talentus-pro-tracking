@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.tasks'))
@section('rail-active', 'tasks')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=6">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-tasks-page"
        data-list-url="{{ route('ui.v2.api.tasks') }}"
        data-meta-url="{{ route('ui.v2.api.tasks.meta') }}"
        data-store-url="{{ route('ui.v2.api.tasks.store') }}"
        data-item-url="{{ url('/ui/v2/api/tasks') . '/{id}' }}"
        data-assign-url="{{ route('ui.v2.api.tasks.assign') }}"
        data-import-url="{{ route('ui.v2.api.tasks.import') }}"
        data-import-fields-url="{{ route('ui.v2.api.tasks.import_fields') }}"
        data-sets-url="{{ route('ui.v2.api.tasks.sets') }}"
        data-set-item-url="{{ url('/ui/v2/api/tasks/sets') . '/{id}' }}"
        data-example-csv="{{ asset('examples/import_task.csv') }}"
        data-example-xls="{{ asset('examples/import_task.xls') }}"
        data-address-search-url="{{ route('address.search') }}"
        data-address-reverse-url="{{ route('address.reverse') }}"
        data-can-edit="{{ !empty($canEdit) ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}"
        data-can-sets="{{ !empty($canTaskSets) ? 1 : 0 }}">

        <script type="application/json" id="tp-tasks-map-config">@json($mapConfig ?? [])</script>

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
                <span data-tsk-only="tasks" style="display:inline-flex;">
                    <select class="tp-input" id="tp-tasks-device" style="width: 210px">
                        <option value="">Todos los vehículos</option>
                    </select>
                </span>
                <span data-tsk-only="tasks" style="display:inline-flex;">
                    <select class="tp-input" id="tp-tasks-status" style="width: 170px">
                        <option value="">Todos los estados</option>
                        @foreach ($statuses as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </span>
                @if (!empty($canEdit))
                    <button type="button" class="tp-btn tp-btn--outline" id="tp-tasks-assign"
                        style="display:none;white-space:nowrap;" data-tsk-only="tasks">Asignar (0)</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon" id="tp-tasks-import"
                        title="{{ trans('front.import') }}" data-tsk-only="tasks">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" />
                        </svg>
                    </button>
                @endif
                <button type="button" class="tp-btn tp-btn--accent" id="tp-tasks-add">＋</button>
            </div>
        </header>

        {{-- Tabs: tareas | conjuntos de tareas --}}
        <div style="display: flex; gap: 2px; border-bottom: 1px solid var(--border-default, #e2e8f0); margin-bottom: 14px;">
            <button type="button" class="tp-tsk-tab is-active" data-tsk-tab="tasks"
                style="appearance:none;border:0;border-bottom:2px solid transparent;background:transparent;cursor:pointer;padding:10px 16px;font:inherit;font-size:13px;font-weight:600;">
                {{ trans('front.tasks') }}</button>
            @if (!empty($canTaskSets))
                <button type="button" class="tp-tsk-tab" data-tsk-tab="sets"
                    style="appearance:none;border:0;border-bottom:2px solid transparent;background:transparent;cursor:pointer;padding:10px 16px;font:inherit;font-size:13px;font-weight:500;">
                    {{ trans('front.task_sets') }}</button>
            @endif
        </div>

        <div class="tp-maint__card" data-tsk-panel="tasks">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-tasks-table">
                    <thead>
                        <tr>
                            @if (!empty($canEdit))
                                <th style="width: 34px;"><input type="checkbox" class="tp-choice-input"
                                        id="tp-tasks-checkall"></th>
                            @endif
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
                            <td colspan="7" class="tp-svc-table__empty">…</td>
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

        {{-- Conjuntos de tareas --}}
        <div class="tp-maint__card" data-tsk-panel="sets" style="display:none;">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-tasksets-table">
                    <thead>
                        <tr>
                            <th>{{ trans('validation.attributes.title') }}</th>
                            <th>{{ trans('validation.attributes.device_id') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-tasksets-rows">
                        <tr>
                            <td colspan="3" class="tp-svc-table__empty">…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <span></span>
                <div class="tp-svc-list__pager">
                    <span id="tp-tasksets-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-tasksets-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-tasksets-next"
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
    <script src="{{ asset('assets/ui/v2/js/tp-basemaps.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=4" defer></script>
    <script src="{{ asset('assets/ui/v2/js/tp-tasks.js') }}?v=4" defer></script>
@endpush
