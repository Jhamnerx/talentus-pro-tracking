@extends('talentus.layout')

@section('title', $pageTitle ?? 'Admin & Integrations')
@section('rail-active', 'admin')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/talentus/css/admin.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--scroll')

@section('workspace')
    <div class="tp-admin" id="tp-admin" data-data-url="{{ route('talentus.api.admin') }}"
        data-is-admin="{{ $isAdmin ? 1 : 0 }}" data-is-manager="{{ $isManager ? 1 : 0 }}">

        <header class="tp-admin__head">
            <div>
                <h1 class="tp-h5 tp-mb-0">Admin & Integrations</h1>
                <p class="tp-text-muted tp-text-sm tp-mb-0">API, webhooks, SSO, branding y accesos r�pidos al panel cl�sico.
                </p>
            </div>
            <div class="tp-row" style="gap:8px;">
                <a class="tp-btn tp-btn--ghost tp-btn--sm" href="{{ url('/user/settings') }}" target="_blank"
                    rel="noopener">Mi cuenta</a>
            </div>
        </header>

        {{-- ====== INTEGRATIONS ROW ====== --}}
        <section class="tp-admin__grid">

            {{-- API ACCESS --}}
            <article class="tp-admin__card" id="card-api">
                <header class="tp-admin__card-head">
                    <div class="tp-admin__card-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M16 18l6-6-6-6M8 6l-6 6 6 6" />
                        </svg>
                    </div>
                    <div class="tp-admin__card-title">
                        <h3 class="tp-h6 tp-mb-0">API Access</h3>
                        <span class="tp-text-muted tp-text-xs">REST � OAuth-like token</span>
                    </div>
                    <span class="tp-pill tp-pill--info tp-pill--sm" id="api-status">�</span>
                </header>
                <dl class="tp-admin__dl">
                    <dt>Base URL</dt>
                    <dd class="tp-admin__mono"><span id="api-base">�</span>
                        <button class="tp-btn tp-btn--ghost tp-btn--xs" data-copy="#api-base" type="button">Copiar</button>
                    </dd>
                    <dt>Token</dt>
                    <dd id="api-token">�</dd>
                    <dt>Expira</dt>
                    <dd id="api-expires">�</dd>
                </dl>
                <footer class="tp-admin__card-foot">
                    <a class="tp-btn tp-btn--ghost tp-btn--xs" href="#" id="api-docs" target="_blank"
                        rel="noopener">Documentaci�n</a>
                    <a class="tp-btn tp-btn--ghost tp-btn--xs" href="{{ url('/user/settings') }}" target="_blank"
                        rel="noopener">Regenerar / cambiar contrase�a</a>
                </footer>
            </article>

            {{-- WEBHOOKS --}}
            <article class="tp-admin__card" id="card-webhooks">
                <header class="tp-admin__card-head">
                    <div class="tp-admin__card-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M18 8a3 3 0 100 6m-12 0a3 3 0 100-6m6 14a3 3 0 100-6M9 6l3 5-3 5M15 6l-3 5 3 5" />
                        </svg>
                    </div>
                    <div class="tp-admin__card-title">
                        <h3 class="tp-h6 tp-mb-0">Webhooks & Forwards</h3>
                        <span class="tp-text-muted tp-text-xs">Eventos hacia sistemas externos</span>
                    </div>
                    <span class="tp-pill tp-pill--info tp-pill--sm" id="wh-count">�</span>
                </header>

                <div class="tp-admin__meta">
                    <div class="tp-admin__stat"><strong id="wh-active">0</strong><span>activos</span></div>
                    <div class="tp-admin__stat"><strong id="wh-total">0</strong><span>total</span></div>
                    <div class="tp-admin__stat"><strong id="wh-types">0</strong><span>tipos</span></div>
                </div>

                <div class="tp-admin__list" id="wh-list">
                    <div class="tp-text-muted tp-text-center" style="padding:16px;">Cargando�</div>
                </div>

                <footer class="tp-admin__card-foot">
                    <a class="tp-btn tp-btn--primary tp-btn--xs" href="{{ url('/forwards') }}" target="_blank"
                        rel="noopener">+ Nuevo</a>
                    <a class="tp-btn tp-btn--ghost tp-btn--xs" href="{{ url('/forwards') }}" target="_blank"
                        rel="noopener">Gestionar todos</a>
                </footer>
            </article>

            {{-- SSO --}}
            <article class="tp-admin__card" id="card-sso">
                <header class="tp-admin__card-head">
                    <div class="tp-admin__card-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <circle cx="12" cy="8" r="4" />
                            <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
                        </svg>
                    </div>
                    <div class="tp-admin__card-title">
                        <h3 class="tp-h6 tp-mb-0">Single Sign-On</h3>
                        <span class="tp-text-muted tp-text-xs">Azure Active Directory</span>
                    </div>
                    <span class="tp-pill tp-pill--muted tp-pill--sm" id="sso-status">�</span>
                </header>
                <dl class="tp-admin__dl">
                    <dt>Proveedor</dt>
                    <dd>Microsoft Azure AD</dd>
                    <dt>Tenant ID</dt>
                    <dd id="sso-tenant" class="tp-admin__mono">�</dd>
                </dl>
                <footer class="tp-admin__card-foot">
                    <a class="tp-btn tp-btn--ghost tp-btn--xs" href="https://entra.microsoft.com" target="_blank"
                        rel="noopener">Portal Entra</a>
                    <span class="tp-text-muted tp-text-xs" id="sso-hint"></span>
                </footer>
            </article>

            {{-- BRANDING --}}
            <article class="tp-admin__card" id="card-branding">
                <header class="tp-admin__card-head">
                    <div class="tp-admin__card-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z" />
                        </svg>
                    </div>
                    <div class="tp-admin__card-title">
                        <h3 class="tp-h6 tp-mb-0">Branding</h3>
                        <span class="tp-text-muted tp-text-xs">Nombre, color y logo</span>
                    </div>
                </header>
                <dl class="tp-admin__dl">
                    <dt>Nombre app</dt>
                    <dd id="br-name">�</dd>
                    <dt>Color principal</dt>
                    <dd><span class="tp-admin__swatch" id="br-swatch"></span> <code id="br-color"
                            class="tp-admin__mono">�</code></dd>
                    <dt>Logo</dt>
                    <dd id="br-logo">�</dd>
                </dl>
                <footer class="tp-admin__card-foot">
                    @if ($isAdmin)
                        <a class="tp-btn tp-btn--ghost tp-btn--xs" href="{{ url('/admin/themes') }}" target="_blank"
                            rel="noopener">Editar branding</a>
                    @endif
                </footer>
            </article>
        </section>

        {{-- ====== ADMIN COUNTS (admin only) ====== --}}
        <section class="tp-admin__panel" id="counts-panel" hidden>
            <header class="tp-admin__panel-head">
                <h2 class="tp-h6 tp-mb-0">Plataforma</h2>
                <span class="tp-text-muted tp-text-sm">Resumen global</span>
            </header>
            <div class="tp-admin__counts">
                <div class="tp-admin__kpi">
                    <span class="tp-admin__kpi-label">Usuarios</span>
                    <strong id="cnt-users">�</strong>
                </div>
                <div class="tp-admin__kpi">
                    <span class="tp-admin__kpi-label">Dispositivos</span>
                    <strong id="cnt-devices">�</strong>
                </div>
            </div>
        </section>

        {{-- ====== QUICK LINKS ====== --}}
        <section class="tp-admin__panel">
            <header class="tp-admin__panel-head">
                <h2 class="tp-h6 tp-mb-0">Accesos r�pidos</h2>
                <span class="tp-text-muted tp-text-sm">Se abren en el panel cl�sico</span>
            </header>
            <div class="tp-admin__quick" id="quick-grid">
                <div class="tp-text-muted tp-text-center" style="padding:24px; grid-column:1/-1;">Cargando�</div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/talentus/js/admin.js') }}?v=1"></script>
@endpush
