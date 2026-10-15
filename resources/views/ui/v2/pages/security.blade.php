@extends('ui.v2.layout')

@section('title', trans('ui.security_title'))
@section('rail-active', 'security')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/security.css') }}">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    {{-- Reproduce el tablero Excel del cliente: filtros, 8 tarjetas, estado en
         vivo, resumen diario, graficos y detalle por unidad. Todo lo pinta
         tp-security.js a partir de los endpoints de abajo. --}}
    <div class="tp-sec" id="tp-security"
        data-meta-url="{{ route('ui.v2.api.security.meta') }}"
        data-data-url="{{ route('ui.v2.api.security.data') }}"
        data-settings-url="{{ route('ui.v2.api.security.settings') }}"
        data-recalc-url="{{ route('ui.v2.api.security.recalculate') }}"
        data-export-url="{{ route('ui.v2.api.security.export') }}">

        <header class="tp-sec__head">
            <h1 class="tp-sec__title">
                <span class="tp-sec__title-ico" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                    </svg>
                </span>
                {{ trans('ui.security_title') }}
            </h1>
            <div class="tp-sec__actions">
                <button type="button" class="tp-btn tp-btn--ghost" id="tp-sec-settings">{{ trans('ui.security_settings') }}</button>
                <button type="button" class="tp-btn tp-btn--ghost" id="tp-sec-recalc">{{ trans('ui.security_recalculate') }}</button>
                <button type="button" class="tp-btn tp-btn--accent" id="tp-sec-export">{{ trans('ui.security_export_excel') }}</button>
            </div>
        </header>

        <section class="tp-sec__filters">
            <label class="tp-sec__f">{{ trans('ui.security_fleet') }}
                <select class="tp-input" id="tp-sec-type"><option value="">{{ trans('front.all') }}</option></select>
            </label>
            <label class="tp-sec__f">{{ trans('ui.security_shift') }}
                <select class="tp-input" id="tp-sec-shift">
                    <option value="all">{{ trans('ui.security_shift_both') }}</option>
                    <option value="day">{{ trans('ui.security_shift_day') }}</option>
                    <option value="night">{{ trans('ui.security_shift_night') }}</option>
                </select>
            </label>
            <label class="tp-sec__f">{{ trans('global.from') }}
                <input type="date" class="tp-input" id="tp-sec-from">
            </label>
            <label class="tp-sec__f">{{ trans('global.to') }}
                <input type="date" class="tp-input" id="tp-sec-to">
            </label>
            <label class="tp-sec__f">{{ trans('front.device') }}
                <select class="tp-input" id="tp-sec-device"><option value="">{{ trans('front.all') }}</option></select>
            </label>
            <label class="tp-sec__f tp-sec__f--short">{{ trans('ui.security_validity') }}
                <input type="number" class="tp-input" id="tp-sec-validity" min="1" max="1440">
            </label>
            <label class="tp-sec__f tp-sec__f--short">{{ trans('ui.security_speed_limit') }}
                <input type="number" class="tp-input" id="tp-sec-speed-limit" min="1" max="300" placeholder="—">
                <small class="tp-sec__hint">{{ trans('ui.security_speed_limit_hint') }}</small>
            </label>
            <button type="button" class="tp-btn tp-btn--accent" id="tp-sec-run">{{ trans('front.search') }}</button>
            <span class="tp-sec__calc tp-text-muted" id="tp-sec-last-calc" aria-live="polite"></span>
        </section>

        <section class="tp-sec__cards" id="tp-sec-cards"></section>

        <section class="tp-sec__grid">
            <div class="tp-sec__panel">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_live_title') }}</h2>
                <div class="tp-sec__wrap"><table class="tp-table tp-table--dense" id="tp-sec-live"></table></div>
            </div>
            <div class="tp-sec__panel">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_chart_live') }}</h2>
                <div class="tp-sec__chart" id="tp-sec-chart-live"></div>
            </div>

            <div class="tp-sec__panel tp-sec__panel--wide">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_daily_title') }}</h2>
                <div class="tp-sec__wrap"><table class="tp-table tp-table--dense" id="tp-sec-daily"></table></div>
                <p class="tp-sec__note tp-text-muted">{{ trans('ui.security_standby_note') }}</p>
            </div>

            <div class="tp-sec__panel">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_chart_states') }}</h2>
                <div class="tp-sec__chart" id="tp-sec-chart-states"></div>
            </div>
            <div class="tp-sec__panel">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_chart_avg_speed') }}</h2>
                <div class="tp-sec__chart" id="tp-sec-chart-avg"></div>
            </div>

            <div class="tp-sec__panel tp-sec__panel--wide">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_chart_overspeed') }}</h2>
                <div class="tp-sec__chart" id="tp-sec-chart-overspeed"></div>
            </div>

            <div class="tp-sec__panel tp-sec__panel--wide">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_ops_title') }}</h2>
                <div class="tp-sec__wrap"><table class="tp-table tp-table--dense" id="tp-sec-units"></table></div>
            </div>

            <div class="tp-sec__panel tp-sec__panel--wide">
                <h2 class="tp-sec__panel-title">{{ trans('ui.security_per_unit_title') }}</h2>
                <div class="tp-sec__wrap"><table class="tp-table tp-table--dense" id="tp-sec-unit-daily"></table></div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-security.js') }}" defer></script>
@endpush
