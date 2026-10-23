@extends('ui.v2.layout')

@section('title', trans('front.operations'))
@section('rail-active', 'operations')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/services.css') }}">
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/operations.css') }}">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-ops-page"
        data-url="{{ route('ui.v2.api.operations') }}"
        data-meta-url="{{ route('ui.v2.api.operations.meta') }}"
        data-live-url="{{ route('ui.v2.api.operations.live') }}"
        data-cycles-url="{{ route('ui.v2.api.operations.cycles') }}"
        data-cycle-url="{{ url('/ui/v2/api/operations/cycles') . '/{id}' }}"
        data-store-url="{{ route('ui.v2.api.operations.store') }}"
        data-item-url="{{ url('/ui/v2/api/operations') . '/{id}' }}"
        data-toggle-url="{{ url('/ui/v2/api/operations') . '/{id}/toggle' }}"
        data-recalc-preview-url="{{ route('ui.v2.api.operations.recalculate_preview') }}"
        data-recalc-url="{{ route('ui.v2.api.operations.recalculate') }}"
        data-recalcs-url="{{ route('ui.v2.api.operations.recalculations') }}"
        data-reasons-url="{{ route('ui.v2.api.operations.reasons') }}"
        data-justify-url="{{ url('/ui/v2/api/operations/stops') . '/{id}/justify' }}"
        data-history-url="{{ url('/ui/v2/history') }}"
        data-shift-report-url="{{ route('ui.v2.api.operations.shift_report') }}"
        data-shift-build-url="{{ route('ui.v2.api.operations.shift_report_build') }}"
        data-shift-export-url="{{ route('ui.v2.api.operations.shift_report_export') }}"
        data-report-url="{{ url('/reports/update') }}"
        data-can-edit="{{ auth()->user()->perm('operations', 'edit') ? '1' : '0' }}"
        data-can-remove="{{ auth()->user()->perm('operations', 'remove') ? '1' : '0' }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M17 2l4 4-4 4" />
                        <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                        <path d="M7 22l-4-4 4-4" />
                        <path d="M21 13v2a4 4 0 0 1-4 4H3" />
                    </svg>
                </span>
                {{ trans('front.operations') }}
            </h2>

            <div class="tp-ops__tabs" role="tablist">
                <button type="button" class="tp-btn tp-btn--ghost is-active" data-ops-tab="live" role="tab" aria-selected="true">
                    {{ trans('ui.ops_tab_live') }}
                </button>
                <button type="button" class="tp-btn tp-btn--ghost" data-ops-tab="cycles" role="tab" aria-selected="false">
                    {{ trans('ui.ops_tab_cycles') }}
                </button>
                <button type="button" class="tp-btn tp-btn--ghost" data-ops-tab="list" role="tab" aria-selected="false">
                    {{ trans('front.operations') }}
                </button>
                <button type="button" class="tp-btn tp-btn--ghost" data-ops-tab="shift" role="tab" aria-selected="false">
                    {{ trans('ui.ops_tab_shift') }}
                </button>
            </div>
        </header>

        {{-- ───────────────────────── En vivo ───────────────────────── --}}
        <section data-ops-panel="live">
            <div class="tp-maint__card">
                <div class="tp-ops__bar">
                    <label class="tp-ops__f">{{ trans('ui.ops_operation') }}
                        <select class="tp-input" id="tp-ops-live-operation"></select>
                    </label>
                    <label class="tp-ops__f">{{ trans('front.date') }}
                        <input type="date" class="tp-input" id="tp-ops-live-date">
                    </label>
                    <span class="tp-ops__updated tp-text-muted" id="tp-ops-live-updated" aria-live="polite"></span>
                </div>

                <div class="tp-ops__kpis" id="tp-ops-kpis">
                    <div class="tp-ops__kpi"><span class="tp-ops__kpi-label">{{ trans('ui.ops_kpi_cycles') }}</span><span class="tp-ops__kpi-value" data-kpi="cycles">—</span></div>
                    <div class="tp-ops__kpi"><span class="tp-ops__kpi-label">{{ trans('ui.ops_kpi_avg_cycle') }}</span><span class="tp-ops__kpi-value" data-kpi="avg_cycle">—</span></div>
                    <div class="tp-ops__kpi"><span class="tp-ops__kpi-label">{{ trans('ui.ops_kpi_on_cycle') }}</span><span class="tp-ops__kpi-value" data-kpi="on_cycle">—</span></div>
                    <div class="tp-ops__kpi tp-ops__kpi--warn"><span class="tp-ops__kpi-label">{{ trans('ui.ops_unauthorized_stops') }}</span><span class="tp-ops__kpi-value" data-kpi="unauthorized">—</span></div>
                </div>

                <div class="tp-maint__wrap">
                    <table class="tp-svc-table">
                        <thead>
                            <tr>
                                <th>{{ trans('ui.vehicle') }}</th>
                                <th>{{ trans('front.status') }}</th>
                                <th>{{ trans('ui.ops_cycles') }}</th>
                                <th>{{ trans('ui.ops_last_cycle') }}</th>
                                <th>{{ trans('front.stopped') }}</th>
                                <th>{{ trans('front.idle') }}</th>
                                <th>{{ trans('ui.ops_unauthorized_short') }}</th>
                                <th>{{ trans('ui.ops_last_position') }}</th>
                            </tr>
                        </thead>
                        <tbody id="tp-ops-live-rows">
                            <tr><td colspan="8" class="tp-svc-table__empty">{{ trans('ui.ops_pick_operation') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ───────────────────────── Vueltas ───────────────────────── --}}
        <section data-ops-panel="cycles" hidden>
            <div class="tp-maint__card">
                <div class="tp-ops__bar">
                    <label class="tp-ops__f">{{ trans('global.from') }}
                        <input type="date" class="tp-input" id="tp-ops-from">
                    </label>
                    <label class="tp-ops__f">{{ trans('global.to') }}
                        <input type="date" class="tp-input" id="tp-ops-to">
                    </label>
                    <label class="tp-ops__f">{{ trans('ui.ops_operation') }}
                        <select class="tp-input" id="tp-ops-filter-operation">
                            <option value="">{{ trans('ui.all_f') }}</option>
                        </select>
                    </label>
                    <label class="tp-ops__f">{{ trans('ui.vehicle') }}
                        <select class="tp-input" id="tp-ops-filter-device">
                            <option value="">{{ trans('global.all') }}</option>
                        </select>
                    </label>
                    <label class="tp-ops__f">{{ trans('ui.ops_shift') }}
                        <select class="tp-input" id="tp-ops-filter-shift">
                            <option value="">{{ trans('global.all') }}</option>
                        </select>
                    </label>
                    <label class="tp-ops__check">
                        <input type="checkbox" id="tp-ops-only-unauthorized">
                        {{ trans('ui.ops_only_unauthorized') }}
                    </label>
                    <button type="button" class="tp-btn tp-btn--accent" id="tp-ops-run">{{ trans('front.search') }}</button>
                    @if (auth()->user()->perm('reports', 'view'))
                        <button type="button" class="tp-btn tp-btn--ghost" id="tp-ops-export">{{ trans('front.export') }}</button>
                    @endif
                </div>

                <div class="tp-maint__wrap">
                    <table class="tp-svc-table tp-ops__cycles">
                        <thead>
                            <tr>
                                <th></th>
                                <th>#</th>
                                <th>{{ trans('ui.vehicle') }}</th>
                                <th>{{ trans('ui.ops_departure') }}</th>
                                <th>{{ trans('ui.ops_shift') }}</th>
                                <th>{{ trans('ui.ops_cycle_total') }}</th>
                                <th>{{ trans('ui.ops_outbound') }}</th>
                                <th>{{ trans('ui.ops_inbound') }}</th>
                                <th>{{ trans('front.stopped') }}</th>
                                <th>{{ trans('front.idle') }}</th>
                                <th>{{ trans('ui.ops_unauthorized_short') }}</th>
                                <th>{{ trans('front.status') }}</th>
                            </tr>
                        </thead>
                        <tbody id="tp-ops-cycle-rows">
                            <tr><td colspan="12" class="tp-svc-table__empty">{{ trans('ui.pick_date_range') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <footer class="tp-maint__foot">
                    <span class="tp-text-muted" id="tp-ops-cycles-total">—</span>
                    <div class="tp-svc-list__pager">
                        <span id="tp-ops-cycles-range">—</span>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ops-cycles-prev" aria-label="{{ trans('front.previous') }}">‹</button>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ops-cycles-next" aria-label="{{ trans('front.next') }}">›</button>
                    </div>
                </footer>
            </div>
        </section>

        {{-- ───────────────────────── Operaciones ───────────────────────── --}}
        <section data-ops-panel="list" hidden>
            <div class="tp-maint__card">
                <div class="tp-ops__bar">
                    <input type="text" class="tp-input" id="tp-ops-search"
                        placeholder="{{ trans('front.search') }}…" aria-label="{{ trans('front.search') }}" autocomplete="off">
                    @if (auth()->user()->perm('operations', 'edit'))
                        <button type="button" class="tp-btn tp-btn--ghost" id="tp-ops-reasons">{{ trans('ui.ops_reasons') }}</button>
                        <button type="button" class="tp-btn tp-btn--ghost" id="tp-ops-recalc">{{ trans('ui.ops_recalculate') }}</button>
                        <button type="button" class="tp-btn tp-btn--accent" id="tp-ops-add">＋ {{ trans('ui.ops_add') }}</button>
                    @endif
                </div>

                <div class="tp-maint__wrap">
                    <table class="tp-svc-table">
                        <thead>
                            <tr>
                                <th>{{ trans('ui.name') }}</th>
                                <th>{{ trans('front.group') }}</th>
                                <th>{{ trans('ui.vehicles') }}</th>
                                <th>{{ trans('ui.ops_points') }}</th>
                                <th>{{ trans('ui.ops_shifts') }}</th>
                                <th>{{ trans('validation.attributes.active') }}</th>
                                <th class="tp-svc-table__actions"></th>
                            </tr>
                        </thead>
                        <tbody id="tp-ops-rows">
                            <tr><td colspan="7" class="tp-svc-table__empty">{{ trans('front.loading') }}…</td></tr>
                        </tbody>
                    </table>
                </div>

                <footer class="tp-maint__foot">
                    <span class="tp-text-muted" id="tp-ops-total">—</span>
                    <div class="tp-svc-list__pager">
                        <span id="tp-ops-range">—</span>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ops-prev" aria-label="{{ trans('front.previous') }}">‹</button>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ops-next" aria-label="{{ trans('front.next') }}">›</button>
                    </div>
                </footer>
            </div>
        </section>

        {{-- ───────────────────────── Turno (reporte de turno) ───────────────────────── --}}
        <section data-ops-panel="shift" hidden>
            <div class="tp-maint__card tp-ops-shift" id="tp-ops-shift">
                <div class="tp-ops__bar tp-ops-shift__bar">
                    <label class="tp-ops__f">{{ trans('ui.ops_operation') }}
                        <select class="tp-input" id="tp-ops-shift-operation"></select>
                    </label>
                    <div class="tp-ops__f">{{ trans('front.date') }}
                        <div class="tp-ops-shift__date">
                            <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ops-shift-prev" aria-label="{{ trans('ui.ops_shift_prev_day') }}" title="{{ trans('ui.ops_shift_prev_day') }}">◀</button>
                            <input type="date" class="tp-input" id="tp-ops-shift-date" aria-label="{{ trans('front.date') }}">
                            <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-ops-shift-next" aria-label="{{ trans('ui.ops_shift_next_day') }}" title="{{ trans('ui.ops_shift_next_day') }}">▶</button>
                        </div>
                    </div>
                    <label class="tp-ops__f">{{ trans('ui.ops_shift') }}
                        <select class="tp-input" id="tp-ops-shift-shift">
                            <option value="all">{{ trans('ui.ops_shift_all') }}</option>
                        </select>
                    </label>
                    <button type="button" class="tp-btn tp-btn--accent" id="tp-ops-shift-refresh">{{ trans('ui.ops_shift_refresh') }}</button>
                    <button type="button" class="tp-btn tp-btn--ghost" id="tp-ops-shift-full" aria-pressed="false">{{ trans('ui.ops_shift_fullscreen') }}</button>
                    <button type="button" class="tp-btn tp-btn--ghost" id="tp-ops-shift-export" title="{{ trans('ui.ops_shift_export_hint') }}" disabled>{{ trans('ui.ops_shift_export') }}</button>
                    <span class="tp-ops__updated tp-text-muted" id="tp-ops-shift-updated" aria-live="polite"></span>
                </div>

                <div class="tp-ops-shift__status" id="tp-ops-shift-status" hidden></div>

                <div class="tp-ops-shift__body" id="tp-ops-shift-body" hidden>
                    {{-- 1. Tarjetas --}}
                    <div class="tp-ops-shift__cards" id="tp-ops-shift-cards"></div>

                    {{-- 2. Gráficas por hora --}}
                    <div class="tp-ops-shift__charts">
                        <figure class="tp-ops-shift__chart" data-chart="rolling">
                            <figcaption>{{ trans('ui.ops_shift_chart_rolling') }}</figcaption>
                            <div class="tp-ops-shift__canvas-wrap"><canvas role="img" aria-label="{{ trans('ui.ops_shift_chart_rolling') }}"></canvas><div class="tp-ops-shift__tip" hidden></div></div>
                        </figure>
                        <figure class="tp-ops-shift__chart" data-chart="empty">
                            <figcaption>{{ trans('ui.ops_shift_chart_empty') }}</figcaption>
                            <div class="tp-ops-shift__canvas-wrap"><canvas role="img" aria-label="{{ trans('ui.ops_shift_chart_empty') }}"></canvas><div class="tp-ops-shift__tip" hidden></div></div>
                        </figure>
                        <figure class="tp-ops-shift__chart" data-chart="loaded">
                            <figcaption>{{ trans('ui.ops_shift_chart_loaded') }}</figcaption>
                            <div class="tp-ops-shift__canvas-wrap"><canvas role="img" aria-label="{{ trans('ui.ops_shift_chart_loaded') }}"></canvas><div class="tp-ops-shift__tip" hidden></div></div>
                        </figure>
                        <figure class="tp-ops-shift__chart" data-chart="both">
                            <figcaption>{{ trans('ui.ops_shift_chart_both') }}</figcaption>
                            <div class="tp-ops-shift__legend" data-legend></div>
                            <div class="tp-ops-shift__canvas-wrap"><canvas role="img" aria-label="{{ trans('ui.ops_shift_chart_both') }}"></canvas><div class="tp-ops-shift__tip" hidden></div></div>
                        </figure>
                        <figure class="tp-ops-shift__chart tp-ops-shift__chart--wide" data-chart="fleet">
                            <figcaption>{{ trans('ui.ops_shift_chart_fleet') }} <span class="tp-ops__hint">{{ trans('ui.ops_shift_chart_fleet_hint') }}</span></figcaption>
                            <div class="tp-ops-shift__legend" data-legend></div>
                            <div class="tp-ops-shift__canvas-wrap tp-ops-shift__canvas-wrap--tall"><canvas role="img" aria-label="{{ trans('ui.ops_shift_chart_fleet') }}"></canvas><div class="tp-ops-shift__tip" hidden></div></div>
                        </figure>
                    </div>

                    {{-- 3. Estados por equipo --}}
                    <section class="tp-ops-shift__block">
                        <h3 class="tp-ops-shift__h">{{ trans('ui.ops_shift_states_title') }}</h3>
                        <div class="tp-ops-shift__legend" id="tp-ops-shift-states-legend"></div>
                        <div class="tp-ops-shift__states" id="tp-ops-shift-states"></div>
                    </section>

                    {{-- 4. Horas y horómetro --}}
                    <section class="tp-ops-shift__block">
                        <h3 class="tp-ops-shift__h">{{ trans('ui.ops_shift_table_hours') }}</h3>
                        <div class="tp-maint__wrap">
                            <table class="tp-svc-table tp-ops-shift__table">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ trans('ui.vehicle') }}</th>
                                        <th scope="col">{{ trans('ui.ops_shift') }}</th>
                                        <th scope="col">{{ trans('ui.ops_shift_col_first') }}</th>
                                        <th scope="col">{{ trans('ui.ops_shift_col_before') }}</th>
                                        <th scope="col">{{ trans('ui.ops_shift_col_after') }}</th>
                                        <th scope="col">{{ trans('ui.ops_shift_col_last') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_effective') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_idle') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_hm_start') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_hm_before') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_hm_after') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_hm_end') }}</th>
                                        <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_var') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="tp-ops-shift-hours-rows"></tbody>
                            </table>
                        </div>
                    </section>

                    {{-- 5. Kilometraje y abastecimientos --}}
                    <div class="tp-ops-shift__two">
                        <section class="tp-ops-shift__block">
                            <h3 class="tp-ops-shift__h">{{ trans('ui.ops_shift_table_odometer') }}</h3>
                            <div class="tp-maint__wrap">
                                <table class="tp-svc-table tp-ops-shift__table">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ trans('ui.vehicle') }}</th>
                                            <th scope="col">{{ trans('ui.ops_shift') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_start') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_end') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_var') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tp-ops-shift-odo-rows"></tbody>
                                </table>
                            </div>
                        </section>
                        <section class="tp-ops-shift__block">
                            <h3 class="tp-ops-shift__h">{{ trans('ui.ops_shift_table_refuel') }}</h3>
                            <div class="tp-maint__wrap">
                                <table class="tp-svc-table tp-ops-shift__table">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ trans('ui.vehicle') }}</th>
                                            <th scope="col">{{ trans('ui.ops_shift') }}</th>
                                            <th scope="col">{{ trans('ui.ops_shift_col_started') }}</th>
                                            <th scope="col">{{ trans('ui.ops_shift_col_ended') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_duration') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_level_before') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_level_after') }}</th>
                                            <th scope="col" class="is-num">{{ trans('ui.ops_shift_col_refuel') }}</th>
                                            <th scope="col">{{ trans('ui.ops_shift_col_engine_off') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tp-ops-shift-refuel-rows"></tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset_resource('assets/ui/v2/js/tp-chips-select.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-operations.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-operations-shift.js') }}" defer></script>
@endpush
