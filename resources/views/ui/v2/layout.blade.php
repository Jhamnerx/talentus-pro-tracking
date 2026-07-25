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
    $theme = request()->cookie('tp_theme', 'dark');
    $density = request()->cookie('tp_density', 'comfortable');
    $rail = trim($__env->yieldContent('rail-active', 'live'));
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

    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/tokens.css') }}?v=2" />
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/components.css') }}?v=21" />
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/shell.css') }}?v=1" />
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/modal.css') }}?v=1" />
    {{-- Formularios en modal (.tp-form*) — global: los usa el modal de cuenta del topbar --}}
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/device-modal.css') }}?v=10" />
    {{-- Tailwind (utilidades) — al final para ganar sobre la capa .tp-* --}}
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/tailwind.css') }}?v=11" />

    <script>
        // Apply persisted theme/density before paint (no FOUC)
        (function() {
            try {
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
                eventsLive: '{{ url('/ui/v2/api/events/live') }}',
                preferences: '{{ url('/ui/v2/api/preferences') }}',
            },
            locale: '{{ app()->getLocale() }}'
        };
    </script>

    @stack('head')
</head>

<body class="tp-scope tp-app-body">

    <div class="tp-app" id="tp-app">

        @include('ui.v2.partials.rail', ['active' => $rail])
        @include('ui.v2.partials.topbar')

        <main class="tp-workspace @yield('workspace-class')" id="tp-workspace">
            @yield('workspace')
        </main>

    </div>

    @include('ui.v2.partials.command-palette')
    @include('ui.v2.partials.notifications-tray')
    @include('ui.v2.partials.toast-host')
    @if (auth()->user() && auth()->user()->perm('send_command', 'view'))
        @include('ui.v2.partials.commands-config')
    @endif
    {{-- Hitos kilométricos (capa de mapa compartida) --}}
    @include('ui.v2.partials.hitos-config')

    {{-- tp-select: componente de select propio (100% Tailwind, sin dependencias).
         Auto-enhancea todo select.tp-input / select.tp-select, incl. AJAX. --}}
    <script src="{{ asset('assets/ui/v2/js/tp-select.js') }}?v=7" defer></script>
    <script src="{{ asset('assets/ui/v2/js/tp-datepicker.js') }}?v=2" defer></script>
    <script src="{{ asset('assets/ui/v2/js/modal.js') }}?v=2" defer></script>
    {{-- Ajustes de cuenta (modal con tabs; se abre desde el topbar) --}}
    <script src="{{ asset('assets/ui/v2/js/tp-account.js') }}?v=2" defer></script>
    {{-- Enviar comando (modal compartido; se abre desde rail y kebab de Live) --}}
    <script src="{{ asset('assets/ui/v2/js/tp-commands.js') }}?v=8" defer></script>
    {{-- Hitos kilométricos (capa Leaflet compartida por páginas con mapa) --}}
    <script src="{{ asset('assets/ui/v2/js/tp-hitos.js') }}?v=2" defer></script>
    <script src="{{ asset('assets/ui/v2/js/shell.js') }}?v=1" defer></script>
    <script src="{{ asset('assets/ui/v2/js/command-palette.js') }}?v=1" defer></script>
    <script src="{{ asset('assets/ui/v2/js/notifications.js') }}?v=2" defer></script>
    {{-- Eventos en vivo: popup + sonido + Notification API (paridad legacy) --}}
    <script src="{{ asset('assets/ui/v2/js/tp-notify.js') }}?v=1" defer></script>

    @stack('scripts')

</body>

</html>
