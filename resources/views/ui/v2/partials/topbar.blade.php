{{-- Topbar · brand + workspace switcher + global search + status + notifications + user --}}
@php
    $user = auth()->user();
    $userName = $user ? trim($user->name ?? ($user->email ?? 'User')) : 'Guest';
    $userInit = $user ? strtoupper(mb_substr(preg_replace('/\s+/', '', $userName), 0, 2)) : 'G';
    $workspaces = $workspaces ?? [['id' => 'primary', 'name' => 'Mi Empresa', 'role' => 'Operations']];
    $currentWorkspace = $workspaces[0] ?? null;
@endphp

<header class="tp-topbar">

    <div class="tp-topbar__brand">
        <a href="{{ url('/ui/v2/preview') }}" class="tp-row" style="text-decoration:none; color:inherit; gap:8px;">
            <span class="tp-rail__logo" style="width:26px; height:26px; font-size:12px;">T</span>
            <span style="font-weight:600;">TalentusPro</span>
        </a>

        {{-- Workspace switcher --}}
        @if (count($workspaces) >= 1)
            <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-workspace-btn" aria-haspopup="menu"
                aria-expanded="false" style="margin-left:8px;">
                <span style="opacity:.55; margin-right:4px;">/</span>
                <span>{{ $currentWorkspace['name'] ?? 'Workspace' }}</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>

            <div class="tp-menu" id="tp-workspace-menu" role="menu" hidden>
                <div class="tp-menu__head">
                    <div class="tp-text-overline">Workspaces</div>
                </div>
                @foreach ($workspaces as $w)
                    <button class="tp-menu__item" role="menuitem" data-workspace-id="{{ $w['id'] }}">
                        <div class="tp-avatar tp-avatar--sm">{{ strtoupper(mb_substr($w['name'], 0, 2)) }}</div>
                        <div class="tp-menu__item-main">
                            <div>{{ $w['name'] }}</div>
                            <div class="tp-text-xs tp-text-muted">{{ $w['role'] ?? '' }}</div>
                        </div>
                        @if (($currentWorkspace['id'] ?? null) === $w['id'])
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        @endif
                    </button>
                @endforeach
                <hr class="tp-divider" />
                <button class="tp-menu__item tp-menu__item--muted" role="menuitem">
                    + Agregar empresa
                </button>
            </div>
        @endif
    </div>

    {{-- Global search (opens Command Palette) --}}
    <button class="tp-topbar__search-btn" id="tp-open-palette" aria-label="Buscar (Ctrl+K)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
        </svg>
        <span class="tp-topbar__search-placeholder">Buscar vehículo, conductor, geocerca, comando…</span>
        <span class="tp-topbar__kbd"><kbd>?</kbd><kbd>K</kbd></span>
    </button>

    <div class="tp-topbar__spacer"></div>

    {{-- Live status pill --}}
    <a href="{{ url('/ui/v2/preview') }}" class="tp-topbar__status" id="tp-live-status" title="Estado de la flota">
        <span class="tp-dot tp-dot--moving"></span>
        <span><span data-live-count="moving">—</span> en mov. · <span data-live-count="idle">—</span> ralentí · <span
                data-live-count="offline">—</span> desc.</span>
    </a>

    {{-- Theme toggle --}}
    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-theme-toggle" data-tooltip="Cambiar tema"
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

    {{-- Ajustes de cuenta (abre el modal con tabs) --}}
    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-account-btn" data-tp-account
        data-tooltip="Ajustes de cuenta" aria-label="Ajustes de cuenta">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
    </button>

    {{-- Notifications --}}
    <button class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" id="tp-notif-btn" data-tooltip="Notificaciones"
        aria-label="Notificaciones" style="position:relative;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        <span class="tp-badge" id="tp-notif-count" style="position:absolute; top:4px; right:4px;" hidden>0</span>
    </button>

    {{-- User menu --}}
    <button class="tp-topbar__user" id="tp-user-btn" aria-haspopup="menu" aria-expanded="false"
        aria-label="Menú de usuario">
        <div class="tp-avatar">{{ $userInit }}</div>
    </button>

    <div class="tp-menu tp-menu--right" id="tp-user-menu" role="menu" hidden>
        <div class="tp-menu__head">
            <div class="tp-text-strong">{{ $userName }}</div>
            <div class="tp-text-xs tp-text-muted">{{ $user->email ?? '' }}</div>
        </div>
        <button class="tp-menu__item" role="menuitem" data-tp-account>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
            </svg>
            Ajustes de cuenta
        </button>
        <button class="tp-menu__item" role="menuitem" data-tp-password>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            Cambiar contraseña
        </button>
        <button class="tp-menu__item" role="menuitem" data-density-toggle>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
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
            Volver a UI clásica
        </a>
        <hr class="tp-divider" />
        <a class="tp-menu__item tp-menu__item--danger" href="{{ url('logout') }}" role="menuitem">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <polyline points="16 17 21 12 16 7" />
                <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            Cerrar sesión
        </a>
    </div>

</header>
