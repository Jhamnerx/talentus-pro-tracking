{{--
    TalentusPro � Master Layout (Fase 1)
    ============================================================
    Layout aislado del legacy. NO toca app.blade.php ni Tobuli.
    Usa el design system v1.0 (.tp-* prefixed, scope .tp-scope).

    Slots / yields disponibles:
      @section('title')       t�tulo de la pesta�a
      @section('rail-active') secci�n activa: live|dashboard|fleet|zones|alerts|history|reports|integrations|admin
      @section('workspace')   contenido principal de la p�gina
      @push('head')           tags extra en <head>
      @push('scripts')        scripts al final de <body>

    Activaci�n: ver TalentusController::preview()
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
<html lang="{{ app()->getLocale() }}" data-theme="{{ $theme }}" data-density="{{ $density }}"
    dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'ir', 'ps', 'ku']) ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>@yield('title', 'TalentusPro') � Command Center</title>

    <link rel="preconnect" href="https://rsms.me/" crossorigin>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.18/index.css" />

    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/tokens.css') }}?v=1" />
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/components.css') }}?v=1" />
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/shell.css') }}?v=1" />
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/modal.css') }}?v=1" />
    {{-- Tailwind (utilidades) — al final para ganar sobre la capa .tp-* --}}
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/tailwind.css') }}?v=1" />

    <script>
        // Apply persisted theme/density before paint (no FOUC)
        (function() {
            try {
                var t = localStorage.getItem('tp_theme');
                var d = localStorage.getItem('tp_density');
                if (t) document.documentElement.setAttribute('data-theme', t);
                if (d) document.documentElement.setAttribute('data-density', d);
            } catch (e) {}
        })();
        window.Talentus = {
            csrf: '{{ csrf_token() }}',
            user: {!! json_encode(['id' => $user->id ?? null, 'name' => $userName, 'email' => $user->email ?? null]) !!},
            routes: {
                search: '{{ url('/ui/v2/api/search') }}',
                notifications: '{{ url('/ui/v2/api/notifications') }}',
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

    <script src="{{ asset('assets/ui/v2/js/modal.js') }}?v=1" defer></script>
    <script src="{{ asset('assets/ui/v2/js/shell.js') }}?v=1" defer></script>
    <script src="{{ asset('assets/ui/v2/js/command-palette.js') }}?v=1" defer></script>
    <script src="{{ asset('assets/ui/v2/js/notifications.js') }}?v=1" defer></script>

    @stack('scripts')

</body>

</html>
