@extends('ui.v2.layout')

@section('title', trans('front.route_schedules'))
@section('rail-active', 'route_schedules')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/route-schedules.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-rsch-page"
        data-url="{{ route('ui.v2.api.route_schedules') }}"
        data-meta-url="{{ route('ui.v2.api.route_schedules.meta') }}"
        data-store-url="{{ route('ui.v2.api.route_schedules.store') }}"
        data-item-url="{{ url('/ui/v2/api/route-schedules') . '/{id}' }}"
        data-adherence-url="{{ route('ui.v2.api.route_schedules.adherence') }}"
        data-checkpoints-url="{{ url('/ui/v2/api/routes') . '/{id}/checkpoints' }}"
        data-can-edit="{{ auth()->user()->perm('routes', 'edit') ? '1' : '0' }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                </span>
                {{ trans('front.route_schedules') }}
            </h2>

            <div class="tp-rsch__tabs" role="tablist">
                <button type="button" class="tp-btn tp-btn--ghost is-active" data-rsch-tab="list" role="tab">
                    {{ trans('front.route_schedules') }}
                </button>
                <button type="button" class="tp-btn tp-btn--ghost" data-rsch-tab="adherence" role="tab">
                    {{ trans('front.route_adherence') }}
                </button>
            </div>
        </header>

        {{-- ───────────────────────── Programaciones ───────────────────────── --}}
        <section data-rsch-panel="list">
            <div class="tp-maint__card">
                <div class="tp-rsch__bar">
                    <input type="text" class="tp-input" id="tp-rsch-search"
                        placeholder="{{ trans('front.search') }}…" autocomplete="off">
                    @if (auth()->user()->perm('routes', 'edit'))
                        <button type="button" class="tp-btn tp-btn--accent" id="tp-rsch-add">
                            ＋ {{ trans('front.route_schedule_add') }}
                        </button>
                    @endif
                </div>

                <div class="tp-maint__wrap">
                    <table class="tp-svc-table">
                        <thead>
                            <tr>
                                <th>{{ trans('validation.attributes.name') }}</th>
                                <th>{{ trans('front.route') }}</th>
                                <th>{{ trans('front.route_weekdays') }}</th>
                                <th>{{ trans('front.route_start_time') }}</th>
                                <th>{{ trans('front.route_return_start_time') }}</th>
                                <th>{{ trans('front.route_schedule_devices') }}</th>
                                <th>{{ trans('validation.attributes.active') }}</th>
                                <th class="tp-svc-table__actions"></th>
                            </tr>
                        </thead>
                        <tbody id="tp-rsch-rows">
                            <tr><td colspan="8" class="tp-svc-table__empty">{{ trans('front.loading') }}…</td></tr>
                        </tbody>
                    </table>
                </div>

                <footer class="tp-maint__foot">
                    <span class="tp-text-muted" id="tp-rsch-total">—</span>
                    <div class="tp-svc-list__pager">
                        <span id="tp-rsch-range">—</span>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-rsch-prev">‹</button>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-rsch-next">›</button>
                    </div>
                </footer>
            </div>
        </section>

        {{-- ───────────────────────── Cumplimiento ───────────────────────── --}}
        <section data-rsch-panel="adherence" hidden>
            <div class="tp-maint__card">
                <div class="tp-rsch__bar">
                    <label class="tp-rsch__f">{{ trans('global.from') }}
                        <input type="date" class="tp-input" id="tp-rsch-from">
                    </label>
                    <label class="tp-rsch__f">{{ trans('global.to') }}
                        <input type="date" class="tp-input" id="tp-rsch-to">
                    </label>
                    <label class="tp-rsch__f">{{ trans('front.route_schedule') }}
                        <select class="tp-input" id="tp-rsch-filter-schedule">
                            <option value="">{{ trans('global.all') }}</option>
                        </select>
                    </label>
                    <label class="tp-rsch__f">{{ trans('front.object') }}
                        <select class="tp-input" id="tp-rsch-filter-device">
                            <option value="">{{ trans('global.all') }}</option>
                        </select>
                    </label>
                    <label class="tp-rsch__check">
                        <input type="checkbox" id="tp-rsch-only-breaches">
                        {{ trans('front.route_only_breaches') }}
                    </label>
                    <button type="button" class="tp-btn tp-btn--accent" id="tp-rsch-run">
                        {{ trans('front.generate') }}
                    </button>
                </div>

                <div class="tp-maint__wrap" id="tp-rsch-adherence">
                    <p class="tp-svc-table__empty">{{ trans('front.route_schedule_none') }}</p>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/ui/v2/js/tp-route-schedules.js') }}?v=1" defer></script>
@endpush
