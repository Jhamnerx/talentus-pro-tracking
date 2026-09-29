@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.dashboard'))
@section('rail-active', 'dashboard')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/dashboard.css') }}?v=7">
@endpush

@section('workspace-class', 'tp-workspace--scroll')

@section('workspace')
    <div class="tp-dash" id="tp-dash" data-data-url="{{ route('ui.v2.api.dashboard') }}" data-poll-interval="60000"
        data-user-today="{{ date('Y-m-d', \Formatter::time()->now()) }}">

        <header class="tp-dash__head">
            <div>
                <h1 class="tp-h4 tp-mb-0">Panel de control</h1>
                <p class="tp-text-muted tp-mb-0">{{ trans('ui.dashboard_subtitle') }}</p>
            </div>
            {{-- Toolbar de rango (estilo Wialon: Hoy / Ayer / Semana / Mes) --}}
            <div class="tp-dash__range" id="tp-dash-range">
                <button type="button" class="tp-dash__range-btn" data-range="today">Hoy</button>
                <button type="button" class="tp-dash__range-btn" data-range="yesterday">Ayer</button>
                <button type="button" class="tp-dash__range-btn is-active" data-range="week">Semana</button>
                <button type="button" class="tp-dash__range-btn" data-range="month">Mes</button>
                <span class="tp-dash__range-sep"></span>
                <input type="date" class="tp-input tp-input--sm" id="tp-dash-from" title="Desde">
                <input type="date" class="tp-input tp-input--sm" id="tp-dash-to" title="Hasta">
                <button type="button" class="tp-btn tp-btn--primary tp-btn--sm" id="tp-dash-apply">Aplicar</button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-dash-refresh" title="Actualizar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 11-3-6.7L21 8" />
                        <path d="M21 3v5h-5" />
                    </svg>
                </button>
            </div>
        </header>
        <div class="tp-dash__meta"><span id="tp-dash-fetched"></span></div>

        {{-- KPI ROW --}}
        <section class="tp-dash__kpis" aria-label="Indicadores clave">
            @php
                $kpis = [
                    ['key' => 'total', 'label' => 'Flota total', 'icon' => 'fleet'],
                    ['key' => 'moving', 'label' => 'En movimiento', 'icon' => 'live', 'tone' => 'success'],
                    ['key' => 'idle', 'label' => 'Inactivos', 'icon' => 'history', 'tone' => 'warning'],
                    ['key' => 'stopped', 'label' => 'Detenidos', 'icon' => 'zones', 'tone' => 'info'],
                    ['key' => 'offline', 'label' => 'Sin datos', 'icon' => 'alerts', 'tone' => 'muted'],
                    ['key' => 'avgSpeed', 'label' => 'Vel. promedio', 'icon' => 'reports', 'suffix' => ' km/h'],
                    ['key' => 'utilization', 'label' => 'Utilización', 'icon' => 'sparkle', 'tone' => 'brand', 'suffix' => '%'],
                ];
            @endphp
            @foreach ($kpis as $k)
                <article class="tp-kpi tp-kpi--{{ $k['tone'] ?? 'default' }}" data-kpi="{{ $k['key'] }}"
                    data-suffix="{{ $k['suffix'] ?? '' }}">
                    <div class="tp-kpi__icon">@include('ui.v2.partials.icons', ['name' => $k['icon']])</div>
                    <div class="tp-kpi__body">
                        <div class="tp-kpi__label">{{ $k['label'] }}</div>
                        <div class="tp-kpi__value" data-kpi-value>—</div>
                    </div>
                </article>
            @endforeach
        </section>

        {{-- WIDGETS GRID --}}
        <section class="tp-dash__grid">

            {{-- Fila 1 · donuts de estado (Wialon): donut izquierda + leyenda con conteos --}}
            <article class="tp-widget tp-widget--span-4" data-widget="health">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Estado de dispositivos</h3>
                    <span class="tp-widget__tag">En vivo</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-donut">
                        <div class="tp-donut__chart" id="tp-chart-health"></div>
                        <ul class="tp-legend" id="tp-legend-health"></ul>
                    </div>
                </div>
            </article>

            <article class="tp-widget tp-widget--span-4" data-widget="connection">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">{{ trans('ui.connection_status') }}</h3>
                    <span class="tp-widget__tag">En vivo</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-donut">
                        <div class="tp-donut__chart" id="tp-chart-connection"></div>
                        <ul class="tp-legend" id="tp-legend-connection"></ul>
                    </div>
                </div>
            </article>

            <article class="tp-widget tp-widget--span-4" data-widget="movement">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Estado de movimiento</h3>
                    <span class="tp-widget__tag">En vivo</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-donut">
                        <div class="tp-donut__chart" id="tp-chart-movement"></div>
                        <ul class="tp-legend" id="tp-legend-movement"></ul>
                    </div>
                </div>
            </article>

            {{-- Fila 2 · Conducción (analítica propia) + Notificaciones --}}
            <article class="tp-widget tp-widget--span-8" data-widget="driving">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">{{ trans('ui.driving_and_behaviour') }}</h3>
                    <span class="tp-widget__hint" id="tp-driving-range">—</span>
                </header>
                <div class="tp-widget__body">
                    <div class="tp-driving">
                        <div class="tp-driving__score">
                            {{-- Puntuación 0-100 de la flota (más es mejor). Se
                                 llama distinto del "índice" del ranking, que es
                                 eventos por 100 km y va al revés: dos números
                                 con el mismo nombre no se pueden leer. --}}
                            <div class="tp-eco" id="tp-eco"
                                title="{{ trans('ui.score_help') }}">
                                <div class="tp-eco__value" data-eco-value>—</div>
                                <div class="tp-eco__label">{{ trans('ui.score') }}<br>de flota</div>
                            </div>
                            <div class="tp-driving__risk">
                                <span class="tp-driving__risk-val" data-risk-events>—</span>
                                <span class="tp-driving__risk-lbl">eventos de riesgo</span>
                            </div>
                        </div>
                        <div class="tp-driving__tiles">
                            <div class="tp-behav" data-behav="harsh_braking">
                                <span class="tp-behav__dot" style="background:#ef4444"></span>
                                <span class="tp-behav__val" data-behav-val>—</span>
                                <span class="tp-behav__lbl">Frenados bruscos</span>
                            </div>
                            <div class="tp-behav" data-behav="harsh_acceleration">
                                <span class="tp-behav__dot" style="background:#f97316"></span>
                                <span class="tp-behav__val" data-behav-val>—</span>
                                <span class="tp-behav__lbl">Aceleraciones</span>
                            </div>
                            <div class="tp-behav" data-behav="harsh_turning">
                                <span class="tp-behav__dot" style="background:#eab308"></span>
                                <span class="tp-behav__val" data-behav-val>—</span>
                                <span class="tp-behav__lbl">Giros bruscos</span>
                            </div>
                            <div class="tp-behav" data-behav="overspeed">
                                <span class="tp-behav__dot" style="background:#dc2626"></span>
                                <span class="tp-behav__val" data-behav-val>—</span>
                                <span class="tp-behav__lbl">Exceso de velocidad</span>
                            </div>
                        </div>
                        <div class="tp-driving__trend">
                            <div class="tp-widget__subhead">Tendencia de eventos de riesgo</div>
                            <div class="tp-chart" id="tp-chart-trend" style="height:120px;"></div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="tp-widget tp-widget--span-4" data-widget="notifications">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">{{ trans('ui.latest_notifications') }}</h3>
                </header>
                <div class="tp-widget__body">
                    <ul class="tp-notes" id="tp-dash-notes">
                        <li class="tp-text-muted tp-text-sm">Cargando…</li>
                    </ul>
                </div>
            </article>

            {{-- Fila 3 · Alertas por tipo + Top vehículos de riesgo --}}
            <article class="tp-widget tp-widget--span-6" data-widget="bycategory">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Alertas por tipo</h3>
                    <span class="tp-widget__hint" data-total-events>—</span>
                </header>
                <div class="tp-widget__body"><div class="tp-barlist" id="tp-dash-bycategory"></div></div>
            </article>

            <article class="tp-widget tp-widget--span-6" data-widget="toprisk">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">{{ trans('ui.top_risk_vehicles') }}</h3>
                </header>
                <div class="tp-widget__body"><div class="tp-barlist" id="tp-dash-toprisk"></div></div>
            </article>

            {{-- Ranking de conducción: el MISMO cálculo que el reporte de
                 Conducción (DrivingScoreService), para que el panel y el
                 reporte no digan cosas distintas del mismo vehículo. --}}
            @if (Auth::user()->perm('reports', 'view'))
                <article class="tp-widget tp-widget--span-12" data-widget="ranking">
                    <header class="tp-widget__head">
                        <h3 class="tp-h6 tp-mb-0">{{ trans('ui.driving_ranking') }}</h3>
                        <span class="tp-widget__hint" id="tp-rank-hint">—</span>
                    </header>
                    <div class="tp-widget__body">
                        <div class="tp-rank">
                            <div class="tp-rank__col">
                                <div class="tp-widget__subhead">{{ trans('ui.best_driving') }}</div>
                                <div class="tp-barlist" id="tp-dash-rank-best"></div>
                            </div>
                            <div class="tp-rank__col">
                                <div class="tp-widget__subhead">{{ trans('ui.worst_driving') }}</div>
                                <div class="tp-barlist" id="tp-dash-rank-worst"></div>
                            </div>
                        </div>
                        <p class="tp-rank__foot" id="tp-rank-foot"></p>
                    </div>
                </article>
            @endif

            {{-- Reparto de la flota por estado: los mismos 6 grupos y colores del
                 bloque device_overview del legacy --}}
            <article class="tp-widget tp-widget--span-6" data-widget="overview">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Estado de la flota</h3>
                    <a href="{{ url('/ui/v2/fleet') }}" class="tp-link tp-text-sm">Ver flota →</a>
                </header>
                <div class="tp-widget__body"><div class="tp-barlist" id="tp-dash-overview"></div></div>
            </article>

            @if (Auth::user()->perm('tasks', 'view'))
                <article class="tp-widget tp-widget--span-6" data-widget="tasks">
                    <header class="tp-widget__head">
                        <h3 class="tp-h6 tp-mb-0">{{ trans('front.latest_tasks') }}</h3>
                        <a href="{{ url('/ui/v2/tasks') }}" class="tp-link tp-text-sm">Ver tareas →</a>
                    </header>
                    <div class="tp-widget__body">
                        <ul class="tp-notes" id="tp-dash-tasks">
                            <li class="tp-text-muted tp-text-sm">Cargando…</li>
                        </ul>
                    </div>
                </article>
            @endif

            {{-- Fila 4 · Kilometraje + Top velocidad --}}
            <article class="tp-widget tp-widget--span-6" data-widget="mileage">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Unidades con mayor kilometraje</h3>
                    <span class="tp-widget__hint" id="tp-dash-mileage-hint">{{ trans('ui.in_the_period') }}</span>
                </header>
                <div class="tp-widget__body"><div class="tp-barlist" id="tp-dash-mileage"></div></div>
            </article>

            <article class="tp-widget tp-widget--span-6" data-widget="topspeed">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">Top 5 · velocidad actual</h3>
                    <a href="{{ url('/ui/v2/live') }}" class="tp-link tp-text-sm">Ver mapa →</a>
                </header>
                <div class="tp-widget__body">
                    <table class="tp-table tp-table--sm" id="tp-dash-top-table">
                        <thead>
                            <tr><th>#</th><th>Dispositivo</th><th>Estado</th><th class="tp-text-right">km/h</th></tr>
                        </thead>
                        <tbody><tr><td colspan="4" class="tp-text-muted tp-text-center">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </article>

            {{-- Fila 5 · Actividad 24h --}}
            <article class="tp-widget tp-widget--span-12" data-widget="activity">
                <header class="tp-widget__head">
                    <h3 class="tp-h6 tp-mb-0">{{ trans('ui.activity_last_24h') }}</h3>
                    <span class="tp-widget__hint">Movimientos por hora</span>
                </header>
                <div class="tp-widget__body"><div class="tp-chart" id="tp-chart-activity" style="height:240px;"></div></div>
            </article>

        </section>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('assets/ui/v2/js/dashboard.js') }}?v=11" defer></script>
@endpush
