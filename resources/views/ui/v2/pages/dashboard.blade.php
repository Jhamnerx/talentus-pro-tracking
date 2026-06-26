@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Command Center')
@section('rail-active', 'dashboard')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/dashboard.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--scroll')

@section('workspace')
    <div class="tp-dash" id="tp-dash" data-data-url="{{ route('ui.v2.api.dashboard') }}" data-poll-interval="60000">

        <header class="tp-dash__head">
            <div>
                <h1 class="tp-h4 tp-mb-0">Command Center</h1>
                <p class="tp-text-muted tp-mb-0">Vista ejecutiva de tu flota � KPIs, distribuci�n y actividad.</p>
            </div>
            <div class="tp-row" style="gap:8px;">
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-dash-refresh" title="Actualizar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 12a9 9 0 11-3-6.7L21 8" />
                        <path d="M21 3v5h-5" />
                    </svg>
                    <span>Actualizar</span>
                </button>
                <span class="tp-text-muted tp-text-sm" id="tp-dash-fetched"></span>
            </div>
        </header>

        {{-- KPI ROW --}}
        <section class="tp-dash__kpis" aria-label="Indicadores clave">
            @php
                $kpis = [
                    ['key' => 'total', 'label' => 'Flota total', 'icon' => 'fleet'],
                    ['key' => 'moving', 'label' => 'En movimiento', 'icon' => 'live', 'tone' => 'success'],
                    ['key' => 'idle', 'label' => 'Inactivos', 'icon' => 'history', 'tone' => 'warning'],
                    ['key' => 'stopped', 'label' => 'Detenidos', 'icon' => 'zones', 'tone' => 'info'],
                    ['key' => 'offline', 'label' => 'Offline', 'icon' => 'alerts', 'tone' => 'muted'],
                    ['key' => 'avgSpeed', 'label' => 'Vel. promedio', 'icon' => 'reports', 'suffix' => ' km/h'],
                    [
                        'key' => 'utilization',
                        'label' => 'Utilizaci�n',
                        'icon' => 'sparkle',
                        'tone' => 'brand',
                        'suffix' => '%',
                    ],
                ];
            @endphp
            @foreach ($kpis as $k)
                <article class="tp-kpi tp-kpi--{{ $k['tone'] ?? 'default' }}" data-kpi="{{ $k['key'] }}"
                    data-suffix="{{ $k['suffix'] ?? '' }}">
                    <div class="tp-kpi__icon">@include('ui.v2.partials.icons', ['name' => $k['icon']])</div>
                    <div class="tp-kpi__body">
                        <div class="tp-kpi__label">{{ $k['label'] }}</div>
                        <div class="tp-kpi__value" data-kpi-value>�</div>
                    </div>
                </article>
            @endforeach
        </section>

        {{-- WIDGETS GRID --}}
        <section class="tp-dash__grid">

            <article class="tp-widget tp-widget--span-6" data-widget="distribution">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Distribuci�n por estado</h3>
                    <span class="tp-widget__hint">Tiempo real</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-chart" id="tp-chart-distribution" style="height:280px;"></div>
                </div>
            </article>

            <article class="tp-widget tp-widget--span-6" data-widget="activity">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Actividad �ltimas 24h</h3>
                    <span class="tp-widget__hint">Movimientos por hora</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-chart" id="tp-chart-activity" style="height:280px;"></div>
                </div>
            </article>

            <article class="tp-widget tp-widget--span-6" data-widget="topspeed">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Top 5 � velocidad actual</h3>
                    <a href="{{ url('/ui/v2/live') }}" class="tp-link tp-text-sm">Ver mapa ?</a>
                </header>
                <div class="tp-widget__body">
                    <table class="tp-table tp-table--sm" id="tp-dash-top-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Dispositivo</th>
                                <th>Estado</th>
                                <th class="tp-text-right">km/h</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="4" class="tp-text-muted tp-text-center">Cargando�</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="tp-widget tp-widget--span-6" data-widget="health">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Salud operativa</h3>
                    <span class="tp-widget__hint">Snapshot</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-health" id="tp-dash-health">
                        <div class="tp-health__row">
                            <span class="tp-health__label">Conectividad</span>
                            <div class="tp-health__bar"><span class="tp-health__fill" data-health="connectivity"
                                    style="width:0%"></span></div>
                            <span class="tp-health__val" data-health-val="connectivity">�</span>
                        </div>
                        <div class="tp-health__row">
                            <span class="tp-health__label">Utilizaci�n</span>
                            <div class="tp-health__bar"><span class="tp-health__fill" data-health="utilization"
                                    style="width:0%"></span></div>
                            <span class="tp-health__val" data-health-val="utilization">�</span>
                        </div>
                        <div class="tp-health__row">
                            <span class="tp-health__label">Activos / Total</span>
                            <div class="tp-health__bar"><span class="tp-health__fill" data-health="active"
                                    style="width:0%"></span></div>
                            <span class="tp-health__val" data-health-val="active">�</span>
                        </div>
                    </div>
                </div>
            </article>

        </section>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('assets/ui/v2/js/dashboard.js') }}?v=1" defer></script>
@endpush
