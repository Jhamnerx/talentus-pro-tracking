{{-- Rail · navegación principal (2 modos: completo icono+nombre / compacto icono+tooltip) --}}
@php
    $active = $active ?? 'live';
    $u = auth()->user();
    // Idioma actual → alterna es⇄en (ampliar si se requieren más).
    $curLang = app()->getLocale();
    $nextLang = $curLang === 'es' ? 'en' : 'es';

    // key, label, bi (Bootstrap Icon), url|pending. pending = feature legacy
    // aún no migrada a v2 (avisa "próximamente").
    $items = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'stats', 'url' => url('/ui/v2/dashboard')],
        ['key' => 'live', 'label' => 'Objetos', 'icon' => 'navigation', 'url' => url('/ui/v2/live')],
        ['key' => 'alerts', 'label' => 'Alertas', 'icon' => 'alerts', 'url' => url('/ui/v2/alerts')],
        ['key' => 'history', 'label' => 'Historial', 'icon' => 'history', 'url' => url('/ui/v2/history')],
        // Reportes: entra con el modal de generar abierto (?create=1)
        ['key' => 'reports', 'label' => 'Reportes', 'icon' => 'layers', 'url' => url('/ui/v2/reports?create=1')],
        ['key' => 'send_command', 'label' => 'Enviar comando', 'icon' => 'send', 'url' => url('/ui/v2/commands')],
        ['key' => 'media', 'label' => 'Cámara / Medios', 'icon' => 'camera', 'pending' => true],
        ['key' => 'sharing', 'label' => 'Compartir', 'icon' => 'share', 'url' => url('/ui/v2/sharing')],
        ['key' => 'maintenance', 'label' => 'Mantenimiento', 'icon' => 'wrench', 'url' => url('/ui/v2/maintenance')],
        ['key' => 'tasks', 'label' => 'Tareas', 'icon' => 'tasks', 'url' => url('/ui/v2/tasks')],
        ['key' => 'drivers', 'label' => 'Conductores', 'icon' => 'users', 'url' => url('/ui/v2/drivers')],
        ['key' => 'chat', 'label' => 'Chat', 'icon' => 'chat', 'pending' => true],
        ['key' => 'call_actions', 'label' => 'Acciones de llamada', 'icon' => 'phone', 'pending' => true],
        ['key' => 'zones', 'label' => 'Geocercas', 'icon' => 'geofence', 'url' => url('/ui/v2/live?panel=zones')],
        ['key' => 'routes', 'label' => 'Rutas', 'icon' => 'route', 'url' => url('/ui/v2/live?panel=routes')],
        ['key' => 'pois', 'label' => 'POI', 'icon' => 'mappin', 'url' => url('/ui/v2/live?panel=pois')],
    ];
@endphp

<aside class="tp-rail" aria-label="Navegación principal">
    {{-- Toggle de modo (completo / compacto) --}}
    <button class="tp-rail__toggle" id="tp-rail-toggle" type="button" title="Expandir / colapsar menú"
        aria-label="Expandir o colapsar menú">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 6h18M3 12h18M3 18h18" />
        </svg>
    </button>

    <a href="{{ url('/ui/v2/live') }}" class="tp-rail__logo" aria-label="TalentusPro Home">
        <span class="tp-rail__logo-mark">T</span>
        <span class="tp-rail__logo-text">TalentusPro</span>
    </a>

    <nav class="tp-rail__nav">
        @foreach ($items as $it)
            <a href="{{ $it['url'] ?? '#' }}"
                class="tp-rail__item tp-has-tooltip{{ !empty($it['pending']) ? ' tp-rail__item--pending' : '' }}"
                data-tooltip="{{ $it['label'] }}" aria-label="{{ $it['label'] }}"
                @if (!empty($it['pending'])) data-rail-pending="1" @endif
                @if (($it['trigger'] ?? null) === 'commands') data-tp-commands @endif
                @if ($active === $it['key']) aria-current="page" @endif>
                <span class="tp-rail__icon">@include('ui.v2.partials.icons', ['name' => $it['icon']])</span>
                <span class="tp-rail__label">{{ $it['label'] }}</span>
                @if (!empty($it['badge']))
                    <span class="tp-badge tp-rail__badge">{{ $it['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <div class="tp-rail__spacer"></div>

    <div class="tp-rail__foot">
        <a href="{{ route('my_account_settings.change_lang', ['lang' => $nextLang]) }}"
            class="tp-rail__item tp-has-tooltip" data-tooltip="Cambiar idioma" aria-label="Cambiar idioma">
            <span class="tp-rail__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20 15.3 15.3 0 0 1 0-20z" />
                </svg></span>
            <span class="tp-rail__label">Idioma · {{ strtoupper($curLang) }}</span>
        </a>
        <a href="{{ route('ui.v2.legacy') }}" class="tp-rail__item tp-has-tooltip"
            data-tooltip="Versión anterior" aria-label="Cambiar a la versión anterior">
            <span class="tp-rail__icon">@include('ui.v2.partials.icons', ['name' => 'back'])</span>
            <span class="tp-rail__label">Versión anterior</span>
        </a>
    </div>
</aside>

<script>
    (function () {
        var toggle = document.getElementById('tp-rail-toggle');
        function setMode(mode) {
            document.documentElement.setAttribute('data-rail', mode);
            try { localStorage.setItem('tp_rail', mode); } catch (e) {}
        }
        if (toggle) toggle.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-rail') === 'full' ? 'full' : 'compact';
            setMode(cur === 'full' ? 'compact' : 'full');
        });
        // Ítems pendientes de migrar: avisan en vez de navegar.
        document.querySelectorAll('[data-rail-pending]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                if (window.tpModal && window.tpModal.toast) window.tpModal.toast('Disponible próximamente en la nueva UI.', 'info');
            });
        });
    })();
</script>
