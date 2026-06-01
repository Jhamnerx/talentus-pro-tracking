@extends('talentus.layout')

@section('title', $pageTitle ?? 'Live Ops')
@section('rail-active', 'live')

@push('head')
    <style>
        /* Preview-only page styles */
        .preview-hero {
            background: linear-gradient(135deg, var(--brand-900) 0%, var(--brand-700) 100%);
            color: #fff;
            padding: var(--sp-8);
            border-radius: var(--r-xl);
            margin-bottom: var(--sp-8);
            position: relative;
            overflow: hidden;
        }

        .preview-hero::after {
            content: "";
            position: absolute;
            right: -80px;
            top: -80px;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(46, 99, 217, 0.4) 0%, transparent 70%);
            pointer-events: none;
        }

        .preview-hero h1 {
            margin: 0 0 8px;
        }

        .preview-hero .sub {
            opacity: 0.8;
            max-width: 640px;
        }

        .preview-hero .cta {
            margin-top: var(--sp-5);
            display: flex;
            gap: var(--sp-3);
            flex-wrap: wrap;
        }

        .preview-hero .tp-btn--primary {
            background: #fff;
            color: var(--brand-700);
            border-color: #fff;
        }

        .preview-hero .tp-btn--primary:hover {
            background: rgba(255, 255, 255, 0.92);
        }

        .preview-hero .tp-btn--secondary {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.18);
        }

        .preview-hero .tp-btn--secondary:hover {
            background: rgba(255, 255, 255, 0.16);
        }

        .demo-spark {
            stroke: var(--brand-500);
            stroke-width: 2;
            fill: none;
        }

        .demo-spark--up {
            stroke: var(--success-500);
        }

        .demo-spark--down {
            stroke: var(--danger-500);
        }

        .tp-kpi__spark {
            width: 80px;
            height: 28px;
            opacity: 0.8;
        }
    </style>
@endpush

@section('workspace')

    <section class="preview-hero">
        <span class="tp-chip" style="background:rgba(255,255,255,0.14); color:#fff; border-color:transparent;">Fase 1 � Shell
            & Navigation</span>
        <h1 class="tp-h1" style="margin-top:12px;">Bienvenido al nuevo Command Center</h1>
        <p class="sub">
            Esta es la vista previa de la nueva experiencia <strong>TalentusPro</strong>: shell redise�ado, b�squeda global,
            notificaciones, multi-empresa y modo claro/oscuro.
            Convive con tu UI cl�sica sin afectar las operaciones. Cuando quieras volver, usa el men� de usuario.
        </p>
        <div class="cta">
            <button class="tp-btn tp-btn--primary" id="open-palette-cta">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                </svg>
                Probar Command Palette (Ctrl+K)
            </button>
            <button class="tp-btn tp-btn--secondary" id="open-notif-cta">Ver notificaciones</button>
            <a class="tp-btn tp-btn--secondary" href="{{ url('/talentus/legacy') }}">Volver a UI cl�sica</a>
        </div>
    </section>

    {{-- KPI strip --}}
    <section class="tp-mb-6">
        <div class="tp-row tp-row-between tp-mb-4">
            <div>
                <h2 class="tp-h3">Operaci�n � Hoy</h2>
                <div class="tp-text-sm tp-text-muted">Resumen ejecutivo en tiempo real</div>
            </div>
            <div class="tp-segmented">
                <button class="tp-segmented__item" aria-selected="true">Hoy</button>
                <button class="tp-segmented__item">7d</button>
                <button class="tp-segmented__item">30d</button>
            </div>
        </div>

        <div class="tp-grid tp-grid-6">
            <div class="tp-kpi">
                <div class="tp-kpi__label">Live</div>
                <div class="tp-kpi__value" data-live-count="moving">�</div>
                <div class="tp-kpi__delta tp-kpi__delta--up">? 4% vs ayer</div>
                <svg class="tp-kpi__spark" viewBox="0 0 80 28" preserveAspectRatio="none">
                    <polyline class="demo-spark demo-spark--up"
                        points="0,20 10,18 20,22 30,15 40,17 50,10 60,12 70,6 80,8" />
                </svg>
            </div>
            <div class="tp-kpi">
                <div class="tp-kpi__label">Idle</div>
                <div class="tp-kpi__value" data-live-count="idle">�</div>
                <div class="tp-kpi__delta tp-kpi__delta--flat">= sin cambio</div>
            </div>
            <div class="tp-kpi">
                <div class="tp-kpi__label">Offline</div>
                <div class="tp-kpi__value" data-live-count="offline">�</div>
                <div class="tp-kpi__delta tp-kpi__delta--down">? 2%</div>
            </div>
            <div class="tp-kpi">
                <div class="tp-kpi__label">Alertas cr�ticas</div>
                <div class="tp-kpi__value" style="color:var(--danger-500);">7</div>
                <div class="tp-kpi__delta tp-kpi__delta--down">? 1 nueva</div>
            </div>
            <div class="tp-kpi">
                <div class="tp-kpi__label">Distancia hoy</div>
                <div class="tp-kpi__value">8,420</div>
                <div class="tp-kpi__delta tp-kpi__delta--up">km � ? 12%</div>
            </div>
            <div class="tp-kpi">
                <div class="tp-kpi__label">SOS activos</div>
                <div class="tp-kpi__value">0</div>
                <div class="tp-kpi__delta tp-kpi__delta--up" style="color:var(--success-500);">Sin SOS</div>
            </div>
        </div>
    </section>

    {{-- Feature cards --}}
    <section class="tp-mb-6">
        <h2 class="tp-h4 tp-mb-4">Lo nuevo en Fase 1</h2>
        <div class="tp-grid tp-grid-3">

            <article class="tp-card tp-card--interactive" data-feat-open="palette">
                <div class="tp-card__header">
                    <span class="tp-chip tp-chip--brand">Atajo ?K</span>
                    <span class="tp-text-mono tp-text-muted">/</span>
                </div>
                <h3 class="tp-h5">Command Palette</h3>
                <p class="tp-text-sm tp-text-secondary">
                    Busca veh�culos, geocercas, POIs y ejecuta acciones desde cualquier pantalla.
                    Reduce 4�7 clics a una sola pulsaci�n.
                </p>
                <div class="tp-card__footer">
                    <span class="tp-text-xs tp-text-muted">Datos en vivo desde tu flota</span>
                </div>
            </article>

            <article class="tp-card tp-card--interactive" data-feat-open="notif">
                <div class="tp-card__header">
                    <span class="tp-chip tp-chip--warning">Tray</span>
                </div>
                <h3 class="tp-h5">Notificaciones contextuales</h3>
                <p class="tp-text-sm tp-text-secondary">
                    Bandeja de eventos con severidad, filtros y acceso directo al origen.
                    Polling autom�tico cada 30s mientras llega WebSocket en Fase 2.
                </p>
                <div class="tp-card__footer">
                    <span class="tp-text-xs tp-text-muted">Inbox unificado</span>
                </div>
            </article>

            <article class="tp-card tp-card--interactive" data-feat-toggle="theme">
                <div class="tp-card__header">
                    <span class="tp-chip tp-chip--info">Dark / Light</span>
                </div>
                <h3 class="tp-h5">Tema persistente</h3>
                <p class="tp-text-sm tp-text-secondary">
                    Modo oscuro por default para operadores 24/7. Cambia y se guarda por usuario,
                    cookie + localStorage + backend.
                </p>
                <div class="tp-card__footer">
                    <span class="tp-text-xs tp-text-muted">Click para alternar</span>
                </div>
            </article>

            <article class="tp-card tp-card--interactive" data-feat-toggle="density">
                <div class="tp-card__header">
                    <span class="tp-chip">Density</span>
                </div>
                <h3 class="tp-h5">Densidad enterprise</h3>
                <p class="tp-text-sm tp-text-secondary">
                    Comfortable para gesti�n, Compact para operadores con mucha info.
                    Cambia y persiste por usuario.
                </p>
                <div class="tp-card__footer">
                    <span class="tp-text-xs tp-text-muted">Click para alternar</span>
                </div>
            </article>

            <article class="tp-card">
                <div class="tp-card__header">
                    <span class="tp-chip tp-chip--success">Workspaces</span>
                </div>
                <h3 class="tp-h5">Multi-empresa ready</h3>
                <p class="tp-text-sm tp-text-secondary">
                    Switcher en la cabecera para clientes con varias unidades de negocio.
                    Wiring de datos en Fase 2.
                </p>
                <div class="tp-card__footer">
                    <span class="tp-text-xs tp-text-muted">Listo para escalar</span>
                </div>
            </article>

            <article class="tp-card">
                <div class="tp-card__header">
                    <span class="tp-chip tp-chip--danger">SOS pulse</span>
                </div>
                <h3 class="tp-h5">Estado operacional vivo</h3>
                <p class="tp-text-sm tp-text-secondary">
                    Colores sem�nticos para movimiento, idle, offline, alerta y SOS pulsante.
                    Coherentes en mapa, listas y notificaciones.
                </p>
                <div class="tp-card__footer">
                    <div class="tp-row" style="gap:8px;">
                        <span class="tp-chip"><span class="tp-dot tp-dot--moving"></span> Live</span>
                        <span class="tp-chip"><span class="tp-dot tp-dot--idle"></span> Idle</span>
                        <span class="tp-chip"><span class="tp-dot tp-dot--sos"></span> SOS</span>
                    </div>
                </div>
            </article>

        </div>
    </section>

    {{-- Roadmap --}}
    <section class="tp-mb-6">
        <h2 class="tp-h4 tp-mb-4">Roadmap visible</h2>
        <div class="tp-card">
            <div class="tp-table-wrap" style="border:0;">
                <table class="tp-table">
                    <thead>
                        <tr>
                            <th>Fase</th>
                            <th>Entregable</th>
                            <th>Estado</th>
                            <th>Pr�ximo paso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="tp-chip tp-chip--success">0</span></td>
                            <td>Design system foundations (tokens + componentes)</td>
                            <td><span class="tp-chip tp-chip--success">Completo</span></td>
                            <td class="tp-text-muted">Showcase disponible</td>
                        </tr>
                        <tr>
                            <td><span class="tp-chip tp-chip--brand">1</span></td>
                            <td>Shell, Command Palette, notificaciones, multi-empresa</td>
                            <td><span class="tp-chip tp-chip--brand">En curso</span></td>
                            <td class="tp-text-muted">Est�s aqu� ?</td>
                        </tr>
                        <tr>
                            <td><span class="tp-chip">2</span></td>
                            <td>Live Ops nuevo (mapa + lista + drawer + socket)</td>
                            <td><span class="tp-chip">Pendiente</span></td>
                            <td class="tp-text-muted">MapLibre + virtualizaci�n</td>
                        </tr>
                        <tr>
                            <td><span class="tp-chip">3</span></td>
                            <td>Command Center con widgets reordenables</td>
                            <td><span class="tp-chip">Pendiente</span></td>
                            <td class="tp-text-muted">ECharts + widget catalog</td>
                        </tr>
                        <tr>
                            <td><span class="tp-chip">4</span></td>
                            <td>History & Playback</td>
                            <td><span class="tp-chip">Pendiente</span></td>
                            <td class="tp-text-muted">Timeline swimlanes</td>
                        </tr>
                        <tr>
                            <td><span class="tp-chip">5</span></td>
                            <td>Alert Inbox + Rule Builder</td>
                            <td><span class="tp-chip">Pendiente</span></td>
                            <td class="tp-text-muted">Reemplaza AlertModalHelper</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

@endsection

@push('scripts')
    <script>
        (function() {
            var paletteBtn = document.getElementById('open-palette-cta');
            if (paletteBtn) paletteBtn.addEventListener('click', function() {
                var openBtn = document.getElementById('tp-open-palette');
                if (openBtn) openBtn.click();
            });

            var notifCta = document.getElementById('open-notif-cta');
            if (notifCta) notifCta.addEventListener('click', function() {
                var b = document.getElementById('tp-notif-btn');
                if (b) b.click();
            });

            document.querySelectorAll('[data-feat-open="palette"]').forEach(function(el) {
                el.addEventListener('click', function() {
                    document.getElementById('tp-open-palette').click();
                });
            });
            document.querySelectorAll('[data-feat-open="notif"]').forEach(function(el) {
                el.addEventListener('click', function() {
                    document.getElementById('tp-notif-btn').click();
                });
            });
            document.querySelectorAll('[data-feat-toggle="theme"]').forEach(function(el) {
                el.addEventListener('click', function() {
                    document.getElementById('tp-theme-toggle').click();
                });
            });
            document.querySelectorAll('[data-feat-toggle="density"]').forEach(function(el) {
                el.addEventListener('click', function() {
                    var d = document.documentElement.getAttribute('data-density') || 'comfortable';
                    window.Talentus.setDensity(d === 'comfortable' ? 'compact' : 'comfortable');
                    window.tpToast && window.tpToast({
                        title: 'Densidad cambiada',
                        variant: 'info',
                        duration: 1800
                    });
                });
            });

            // Showcase: seed live counts so the topbar pill shows numbers right away.
            window.tpUpdateLiveStatus && window.tpUpdateLiveStatus({
                moving: 142,
                idle: 18,
                offline: 3
            });

            // Welcome toast on first visit
            setTimeout(function() {
                window.tpToast && window.tpToast({
                    title: '�Bienvenido al nuevo Command Center!',
                    description: 'Presiona Ctrl+K para abrir el buscador global.',
                    variant: 'info',
                    duration: 5000
                });
            }, 600);
        })();
    </script>
@endpush
