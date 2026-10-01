{{-- Topbar · brand + workspace switcher + global search + status + notifications + user --}}
@php
    $user = auth()->user();
    $userName = $user ? trim($user->name ?? ($user->email ?? 'User')) : 'Guest';
    $userInit = $user ? strtoupper(mb_substr(preg_replace('/\s+/', '', $userName), 0, 2)) : 'G';
    // Marca (white-label): mismo ajuste que el <title> del layout maestro.
    $brandName = Appearance::getSetting('server_name') ?: 'TalentusPro';
    $brandInit = strtoupper(mb_substr(preg_replace('/\s+/', '', $brandName), 0, 1)) ?: 'T';
@endphp

<header class="tp-topbar">

    {{-- Menu (solo movil). El boton de plegar vive DENTRO del rail, asi que
         cuando el rail sale de pantalla se va con el: hace falta uno aqui,
         que el topbar siempre esta a la vista. --}}
    <button class="tp-topbar__nav" id="tp-nav-toggle" type="button" aria-label="{{ trans('ui.open_menu') }}"
        aria-controls="tp-rail" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" aria-hidden="true">
            <path d="M3 6h18M3 12h18M3 18h18" />
        </svg>
    </button>

    <div class="tp-topbar__brand">
        <a href="{{ url('/ui/v2/live') }}" class="tp-row" style="text-decoration:none; color:inherit; gap:8px;">
            <span class="tp-rail__logo" style="width:26px; height:26px; font-size:12px;">{{ $brandInit }}</span>
            <span style="font-weight:600;">{{ $brandName }}</span>
        </a>
    </div>

    {{-- Global search (opens Command Palette) --}}
    <button class="tp-topbar__search-btn" id="tp-open-palette" aria-label="{{ trans('ui.search_ctrl_k') }}">
        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
        </svg>
        <span class="tp-topbar__search-placeholder">{{ trans('ui.search_placeholder') }}</span>
        <span class="tp-topbar__kbd"><kbd>Ctrl</kbd><kbd>K</kbd></span>
    </button>

    <div class="tp-topbar__spacer"></div>

    {{-- Herramientas de mapa (solo en Live y sus paneles): Regla, Mostrar punto,
         Buscar dirección. Las gestiona live.js (que solo carga en la página Live). --}}
    @if (in_array($rail ?? '', ['live', 'zones', 'pois'], true))
        <div class="tp-topbar__tools" style="position:relative;">
            <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-tools-btn" data-tooltip="{{ trans('ui.tools') }}"
                aria-label="{{ trans('front.tools') }}" aria-haspopup="menu" aria-expanded="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2.7-.7-.7-2.7z" />
                </svg>
            </button>
            <div class="tp-menu tp-menu--right" id="tp-tools-menu" role="menu" hidden
                data-address-search-url="{{ route('address.search') }}"
                style="position:absolute; top:calc(100% + 6px); right:0;">
                <div class="tp-menu__head"><div class="tp-text-overline">{{ trans('front.tools') }}</div></div>
                <button class="tp-menu__item" role="menuitem" data-tp-tool="ruler">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M21.3 15.3 8.7 2.7a1 1 0 0 0-1.4 0L2.7 7.3a1 1 0 0 0 0 1.4l12.6 12.6a1 1 0 0 0 1.4 0l4.6-4.6a1 1 0 0 0 0-1.4z"/><path d="m14.5 12.5 2-2M11.5 9.5l2-2M8.5 6.5l2-2M11.5 15.5l2-2"/></svg>
                    Regla
                </button>
                <button class="tp-menu__item" role="menuitem" data-tp-tool="point">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    {{ trans('ui.show_point') }}
                </button>
                <button class="tp-menu__item" role="menuitem" data-tp-tool="address">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    {{ trans('front.show_address') }}
                </button>
                {{-- Unidades sin transmitir: mismo gate que ver los vehiculos
                     en el mapa, porque no enseña nada que el usuario no
                     pudiera mirar uno a uno. --}}
                @if (auth()->user()->perm('devices', 'view'))
                    <button class="tp-menu__item" role="menuitem" data-tp-tool="silent-devices">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M2 2l20 20"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M5 13a10 10 0 0 1 3-2"/><path d="M2 8.8A15 15 0 0 1 6 6.3"/><path d="M22 8.8a15 15 0 0 0-9.7-2.7"/><circle cx="12" cy="20" r="1"/></svg>
                        {{ trans('ui.silent_units') }}
                    </button>
                @endif
                @if (config('addon.forwards') && auth()->user()->perm('forwards', 'view'))
                    <hr class="tp-divider" style="margin:4px 0;">
                    <button class="tp-menu__item" role="menuitem" data-tp-tool="forwards"
                        data-forwards-url="{{ route('ui.v2.api.forwards') }}">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16M14 6l6 6-6 6"/></svg>
                        {{ trans('front.forwards') }}
                    </button>
                @endif
            </div>
        </div>
    @endif

    {{-- Theme toggle --}}
    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-theme-toggle" data-tooltip="{{ trans('ui.change_theme') }}"
        aria-label="Cambiar tema">
        <svg class="tp-icon-dark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>
        <svg class="tp-icon-light" width="18" height="18" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2" />
            <path d="M12 20v2" />
            <path d="m4.93 4.93 1.41 1.41" />
            <path d="m17.66 17.66 1.41 1.41" />
            <path d="M2 12h2" />
            <path d="M20 12h2" />
            <path d="m6.34 17.66-1.41 1.41" />
            <path d="m19.07 4.93-1.41 1.41" />
        </svg>
    </button>

    {{-- Panel de administración. Mismo gate que el header legacy: isAdmin().
         Lleva al admin clásico, que es donde vive todo lo de administración. --}}
    @if (isAdmin())
        <a href="{{ route('admin') }}" class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip"
            data-tooltip="{{ trans('global.admin') }}" aria-label="{{ trans('global.admin') }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            </svg>
        </a>
    @endif

    {{-- Ajustes de cuenta (abre el modal con tabs) --}}
    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-account-btn" data-tp-account
        data-tooltip="{{ trans('ui.account_settings') }}" aria-label="{{ trans('ui.account_settings') }}">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
    </button>

    {{-- Notifications --}}
    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-notif-btn" data-tooltip="{{ trans('front.notifications') }}"
        aria-label="{{ trans('front.notifications') }}" style="position:relative;">
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        <span class="tp-badge" id="tp-notif-count" style="position:absolute; top:4px; right:4px;" hidden>0</span>
    </button>

    {{-- User menu --}}
    <button class="tp-topbar__user" id="tp-user-btn" aria-haspopup="menu" aria-expanded="false"
        aria-label="{{ trans('ui.user_menu') }}">
        <div class="tp-avatar">{{ $userInit }}</div>
    </button>

    <div class="tp-menu tp-menu--right" id="tp-user-menu" role="menu" hidden>
        <div class="tp-menu__head">
            <div class="tp-text-strong">{{ $userName }}</div>
            <div class="tp-text-xs tp-text-muted">{{ $user->email ?? '' }}</div>
        </div>
        <button class="tp-menu__item" role="menuitem" data-tp-account>
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
            </svg>
            {{ trans('ui.account_settings') }}
        </button>
        <button class="tp-menu__item" role="menuitem" data-tp-password>
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            {{ trans('front.change_password') }}
        </button>
        @if (empty($user->email_active))
            {{-- Email sin confirmar → modal de código único (tp-account.js).
                 Paridad del flujo EmailConfirmation legacy (código + reenviar). --}}
            <button class="tp-menu__item" role="menuitem" data-tp-email-confirm
                data-confirm-url="{{ route('ui.v2.api.account.email_confirm') }}"
                data-resend-url="{{ route('ui.v2.api.account.email_resend') }}">
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                    <polyline points="22,6 12,13 2,6" />
                </svg>
                Confirmar email
                <span style="margin-left:auto;width:7px;height:7px;border-radius:50%;background:#f59e0b;flex:0 0 auto;"
                    aria-hidden="true"></span>
            </button>
        @endif
        {{-- Idioma (paridad LanguageController: solo los activos del sistema) --}}
        <button class="tp-menu__item" role="menuitem" data-tp-language>
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" />
                <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
            {{ trans('global.language') }}
        </button>
        <button class="tp-menu__item" role="menuitem" data-density-toggle>
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="6" x2="21" y2="6" />
                <line x1="3" y1="12" x2="21" y2="12" />
                <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
            Cambiar densidad
        </button>
        <a class="tp-menu__item" href="{{ url('/ui/v2/legacy') }}" role="menuitem">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                <path d="M3 3v5h5" />
            </svg>
            {{ trans('ui.back_to_classic_ui') }}
        </a>
        <hr class="tp-divider" />
        <a class="tp-menu__item tp-menu__item--danger" href="{{ url('logout') }}" role="menuitem">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <polyline points="16 17 21 12 16 7" />
                <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            {{ trans('front.logout') }}
        </a>
    </div>

</header>
