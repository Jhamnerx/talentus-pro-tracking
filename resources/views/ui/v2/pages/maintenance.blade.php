@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.maintenance'))
@section('rail-active', 'maintenance')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=3">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-maintenance">
        @include('ui.v2.partials.services-config')

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2.7-.7-.7-2.7z" />
                    </svg>
                </span>
                {{ trans('front.maintenance') }}
            </h2>
            <div class="tp-maint__tools">
                <input type="text" class="tp-input" id="tp-maint-search"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                {{-- Plantillas de listas de verificación (addon checklists) --}}
                @if (config('addon.checklists') && Auth::user()->perm('checklist_template', 'view'))
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-maint-checklist-templates"
                        onclick="window.tpChecklists && tpChecklists.templates()">Plantillas</button>
                @endif
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-maint-table">
                    <thead>
                        <tr>
                            <th data-sort="device_name">{{ trans('validation.attributes.device_id') }}</th>
                            <th data-sort="name">{{ trans('validation.attributes.name') }}</th>
                            <th data-sort="odometer_pct">{{ trans('global.distance') }}</th>
                            <th data-sort="odometer_left">{{ trans('global.distance') }} {{ trans('front.left') }}</th>
                            <th data-sort="engine_hours_pct">{{ trans('validation.attributes.engine_hours') }}</th>
                            <th data-sort="engine_hours_left">{{ trans('validation.attributes.engine_hours') }} {{ trans('front.left') }}</th>
                            <th data-sort="days_pct">{{ trans('global.days') }}</th>
                            <th data-sort="days_left">{{ trans('global.days') }} {{ trans('front.left') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-maint-rows">
                        <tr>
                            <td colspan="9" class="tp-svc-table__empty">{{ trans('front.loading') }}…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-maint-limit">
                        <option>25</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-maint-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-maint-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-maint-next"
                        aria-label="{{ trans('front.next') }}">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    {{-- defer: modal.js (tpModal) se carga con defer en el layout — estos deben
         ejecutarse DESPUÉS (los defer corren en orden de aparición). --}}
    <script src="{{ asset('assets/ui/v2/js/tp-services.js') }}?v=4" defer></script>
    <script src="{{ asset('assets/ui/v2/js/maintenance.js') }}?v=3" defer></script>
@endpush
