{{--
    TalentusPro · Master Layout (Fase 1)
    ============================================================
    Layout aislado del legacy. NO toca app.blade.php ni Tobuli.
    Usa el design system v1.0 (.tp-* prefixed, scope .tp-scope).

    Slots / yields disponibles:
      @section('title')       título de la pestaña
      @section('rail-active') sección activa: live|dashboard|fleet|zones|alerts|history|reports|integrations|admin
      @section('workspace')   contenido principal de la página
      @push('head')           tags extra en <head>
      @push('scripts')        scripts al final de <body>

    Activación: ver TalentusController::preview()
============================================================ --}}
@php
    $user = auth()->user();
    $userName = $user ? trim($user->name ?? ($user->email ?? 'User')) : 'Guest';
    $userInit = $user ? strtoupper(mb_substr(preg_replace('/\s+/', '', $userName), 0, 2)) : 'G';
    // Preferencia guardada en la cuenta (ShellController@preferences) > cookie > defecto.
    // La de la cuenta manda también sobre el localStorage (ver script anti-FOUC).
    $prefTheme = null;
    $prefDensity = null;
    if ($user && ! isDemoUser()) {
        try {
            $prefTheme = $user->getSettings('tp_theme');
            $prefDensity = $user->getSettings('tp_density');
        } catch (\Throwable $e) {
        }
    }
    $prefTheme = in_array($prefTheme, ['light', 'dark'], true) ? $prefTheme : null;
    $prefDensity = in_array($prefDensity, ['comfortable', 'compact'], true) ? $prefDensity : null;
    $theme = $prefTheme ?: request()->cookie('tp_theme', 'dark');
    $density = $prefDensity ?: request()->cookie('tp_density', 'comfortable');
    $rail = trim($__env->yieldContent('rail-active', 'live'));
    // Talentus IA CHAT: permiso del usuario/plan + asistente configurado en Admin.
    $tpaiOn = false;
    try {
        $tpaiOn = $user && $user->perm('ai_assistant', 'view') && \App\Services\Ai\AiSettings::isConfiguredFor($user);
    } catch (\Throwable $e) {
    }
    // Formato de fecha/hora del usuario para el JS (tpFmt, modal.js): el
    // mismo que usa Formatter en el servidor, para que Eventos, Flota, la
    // bandeja y el tablero pinten las fechas igual (QA V10).
    $tpDateTime = ['date' => 'Y-m-d', 'time' => 'H:i:s', 'offset' => 0];
    try {
        $tpNowUtc = date('Y-m-d H:i:s');
        $tpDateTime = [
            'date'   => \Formatter::date()->getFormat(),
            'time'   => \Formatter::dtime()->getFormat(),
            // Minutos entre la hora del servidor y la zona del usuario.
            'offset' => (int) round((strtotime(\Formatter::time()->convert($tpNowUtc)) - strtotime($tpNowUtc)) / 60),
        ];
    } catch (\Throwable $e) {
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $theme }}" data-density="{{ $density }}" data-rail="compact"
    dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'ir', 'ps', 'ku']) ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- Título y favicon desde los ajustes de marca (Appearance, como el legacy) --}}
    <title>@yield('title', 'Panel') · {{ Appearance::getSetting('server_name') ?: 'TalentusPro' }}</title>
    <link rel="shortcut icon" href="{{ Appearance::getAssetFileUrl('favicon') }}" type="image/x-icon">

    <link rel="preconnect" href="https://rsms.me/" crossorigin>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.18/index.css" />

    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/tokens.css') }}" />
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/components.css') }}" />
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/shell.css') }}" />
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/modal.css') }}" />
    {{-- Formularios en modal (.tp-form*) — global: los usa el modal de cuenta del topbar --}}
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/device-modal.css') }}" />
    @if ($tpaiOn)
        {{-- Talentus IA CHAT (panel lateral del asistente) --}}
        <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/ai-chat.css') }}" />
    @endif
    {{-- Tailwind (utilidades) — al final para ganar sobre la capa .tp-* --}}
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/tailwind.css') }}" />

    <script>
        // Apply persisted theme/density before paint (no FOUC)
        (function() {
            try {
                // Lo guardado en la cuenta gana al localStorage (que puede venir
                // de otra sesión en este navegador); se resincroniza aquí.
                var st = @json($prefTheme), sd = @json($prefDensity);
                if (st) localStorage.setItem('tp_theme', st);
                if (sd) localStorage.setItem('tp_density', sd);
                var t = localStorage.getItem('tp_theme');
                var d = localStorage.getItem('tp_density');
                var r = localStorage.getItem('tp_rail');
                if (t) document.documentElement.setAttribute('data-theme', t);
                if (d) document.documentElement.setAttribute('data-density', d);
                if (r) document.documentElement.setAttribute('data-rail', r);
            } catch (e) {}
        })();
        window.Talentus = {
            csrf: '{{ csrf_token() }}',
            user: {!! json_encode(['id' => $user->id ?? null, 'name' => $userName, 'email' => $user->email ?? null]) !!},
            routes: {
                search: '{{ url('/ui/v2/api/search') }}',
                notifications: '{{ url('/ui/v2/api/notifications') }}',
                notificationsSeen: '{{ url('/ui/v2/api/notifications/seen') }}',
                eventsLive: '{{ url('/ui/v2/api/events/live') }}',
                preferences: '{{ url('/ui/v2/api/preferences') }}',
            },
            locale: '{{ app()->getLocale() }}',
            datetime: @json($tpDateTime),
            // Tiempo real: mismo servidor y mismo canal que el legacy.
            // Los puertos por defecto son los de socket/socket.js (9001/9002),
            // NO 3000/3001: con esos la v2 no conectaba con nada.
            socket: {
                // Fijos, igual que el legacy (controller/app.js: socket_port / socket_ssl_port).
                // Sin env(): con config:cache devuelve siempre el valor por defecto.
                port: 9001,
                sslPort: 9002,
                channel: @json($user ? $user->getSocketChannel() : null)
            }
        };
    </script>

    @stack('head')
</head>

<body class="tp-scope tp-app-body">

    <div class="tp-app" id="tp-app">

        @include('ui.v2.partials.rail', ['active' => $rail])
        @include('ui.v2.partials.topbar')

        {{-- Velo del menu en movil: cierra tocando fuera. Solo existe como
             capa visible bajo 640px (ver components.css). --}}
        <div class="tp-nav-scrim" id="tp-nav-scrim" hidden></div>

        <main class="tp-workspace @yield('workspace-class')" id="tp-workspace">
            @yield('workspace')
        </main>

    </div>

    {{-- Avisos del administrador (addon notification_popops): franjas + popups --}}
    @include('ui.v2.partials.admin-popups')

    @include('ui.v2.partials.command-palette')
    @include('ui.v2.partials.notifications-tray')
    @include('ui.v2.partials.toast-host')
    @if ($tpaiOn)
        @include('ui.v2.partials.ai-chat')
    @endif
    @if (auth()->user() && auth()->user()->perm('send_command', 'view'))
        @include('ui.v2.partials.commands-config')
    @endif
    {{-- Hitos kilométricos (capa de mapa compartida) --}}
    @include('ui.v2.partials.hitos-config')

    {{-- tp-select: componente de select propio (100% Tailwind, sin dependencias).
         Auto-enhancea todo select.tp-input / select.tp-select, incl. AJAX. --}}
    {{-- Tiempo real (avisos, chat, camaras). Va antes que todo lo demas para
         que cualquier script pueda suscribirse con tpSocket.on(). --}}
    @include('ui.v2.partials.i18n')

    <script src="{{ asset('assets/ui/v2/js/lib/socket.io.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-socket.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-select.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-datepicker.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/modal.js') }}" defer></script>
    {{-- Ajustes de cuenta (modal con tabs; se abre desde el topbar) --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-account.js') }}" defer></script>
    {{-- Enviar comando (modal compartido; se abre desde rail y kebab de Live) --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-commands.js') }}" defer></script>
    {{-- Galería de medios del vehículo: kebab de Live y «Cámara / Medios» del rail --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-device-media.js') }}" defer></script>
    {{-- Hitos kilométricos (capa Leaflet compartida por páginas con mapa) --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-hitos.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/shell.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/command-palette.js') }}" defer></script>
    <script src="{{ asset_resource('assets/ui/v2/js/notifications.js') }}" defer></script>
    {{-- Eventos en vivo: popup + sonido + Notification API (paridad legacy) --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-notify.js') }}" defer></script>
    {{-- Avisos del administrador (popups del legacy) --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-admin-popups.js') }}" defer></script>
    @if ($tpaiOn)
        <script src="{{ asset_resource('assets/ui/v2/js/tp-ai-chat.js') }}" defer></script>
    @endif
    {{-- Listas de verificación (addon checklists): plantillas + ejecución por servicio --}}
    @if (config('addon.checklists'))
        <script>
            window.TP_CHECKLISTS_ON = true;
        </script>
    @endif
    {{-- El QR del equipo (menú de la lista) lo gatea solo checklist_qr_code.view,
         igual que Objects/item.blade del legacy: el script va también sin el addon. --}}
    @if (config('addon.checklists') || (Auth::check() && Auth::user()->perm('checklist_qr_code', 'view')))
        <script src="{{ asset_resource('assets/ui/v2/js/tp-checklists.js') }}" defer></script>
    @endif

    @stack('scripts')

</body>

</html>
