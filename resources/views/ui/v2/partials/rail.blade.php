{{-- Rail · navegación principal (2 modos: completo icono+nombre / compacto icono+tooltip) --}}
@php
    $active = $active ?? 'live';
    $u = auth()->user();
    // Idioma actual → alterna es⇄en (ampliar si se requieren más).
    $curLang = app()->getLocale();
    $nextLang = $curLang === 'es' ? 'en' : 'es';

    // Marca desde Appearance (mismos ajustes que el legacy: server_name + logo)
    $serverName = Appearance::getSetting('server_name') ?: 'TalentusPro';
    $hasLogo = Appearance::assetFileExists('logo');

    // key, label, bi (Bootstrap Icon), url|pending. pending = feature legacy
    // aún no migrada a v2 (avisa "próximamente").
    // 'can' = misma condición que el menú Tools del legacy (header.blade):
    // perm(módulo,'view') por ítem; call_actions exige además events (paridad).
    // Dashboard, Objetos e Historial no se condicionan en el legacy.
    $items = [
        ['key' => 'dashboard', 'label' => trans('front.dashboard'), 'icon' => 'stats', 'url' => url('/ui/v2/dashboard')],
        ['key' => 'live', 'label' => trans('front.objects'), 'icon' => 'navigation', 'url' => url('/ui/v2/live')],
        ['key' => 'monitor', 'label' => trans('ui.group_monitor'), 'icon' => 'grid', 'url' => url('/ui/v2/monitor')],
        ['key' => 'events', 'label' => trans('front.events'), 'icon' => 'events', 'url' => url('/ui/v2/events'), 'can' => $u->perm('events', 'view')],
        ['key' => 'alerts', 'label' => trans('front.alerts'), 'icon' => 'alerts', 'url' => url('/ui/v2/alerts'), 'can' => $u->perm('alerts', 'view')],
        ['key' => 'history', 'label' => trans('front.history'), 'icon' => 'history', 'url' => url('/ui/v2/history')],
        // Reportes: entra con el modal de generar abierto (?create=1)
        ['key' => 'reports', 'label' => trans('front.reports'), 'icon' => 'layers', 'url' => url('/ui/v2/reports?create=1'), 'can' => $u->perm('reports', 'view')],
        ['key' => 'send_command', 'label' => trans('front.send_command'), 'icon' => 'send', 'url' => url('/ui/v2/commands'), 'can' => $u->perm('send_command', 'view')],
        ['key' => 'media', 'label' => trans('front.camera'), 'icon' => 'camera', 'pending' => true, 'can' => $u->perm('camera', 'view')],
        ['key' => 'sharing', 'label' => trans('front.sharing'), 'icon' => 'share', 'url' => url('/ui/v2/sharing'), 'can' => $u->perm('sharing', 'view')],
        ['key' => 'maintenance', 'label' => trans('front.maintenance'), 'icon' => 'wrench', 'url' => url('/ui/v2/maintenance'), 'can' => $u->perm('maintenance', 'view')],
        ['key' => 'expenses', 'label' => trans('front.expenses'), 'icon' => 'money', 'url' => url('/ui/v2/expenses'), 'can' => $u->perm('device_expenses', 'view')],
        ['key' => 'tasks', 'label' => trans('front.tasks'), 'icon' => 'tasks', 'url' => url('/ui/v2/tasks'), 'can' => $u->perm('tasks', 'view')],
        ['key' => 'drivers', 'label' => trans('front.drivers'), 'icon' => 'users', 'url' => url('/ui/v2/drivers'), 'can' => $u->perm('drivers', 'view')],
        ['key' => 'chat', 'label' => trans('front.chat'), 'icon' => 'chat', 'url' => url('/ui/v2/chat'), 'can' => $u->perm('chat', 'view')],
        ['key' => 'call_actions', 'label' => trans('ui.call_actions'), 'icon' => 'phone', 'url' => url('/ui/v2/call-actions'), 'can' => $u->perm('call_actions', 'view') && $u->perm('events', 'view')],
        ['key' => 'zones', 'label' => trans('front.geofences'), 'icon' => 'geofence', 'url' => url('/ui/v2/live?panel=zones'), 'can' => $u->perm('geofences', 'view')],
        ['key' => 'routes', 'label' => trans('front.routes'), 'icon' => 'route', 'url' => url('/ui/v2/live?panel=routes'), 'can' => $u->perm('routes', 'view')],
        ['key' => 'route_schedules', 'label' => trans('ui.route_schedules'), 'icon' => 'history', 'url' => url('/ui/v2/route-schedules'), 'can' => $u->perm('routes', 'view')],
        ['key' => 'pois', 'label' => trans('front.poi'), 'icon' => 'mappin', 'url' => url('/ui/v2/live?panel=pois'), 'can' => $u->perm('poi', 'view')],
    ];

    $items = array_filter($items, function ($it) {
        return ! array_key_exists('can', $it) || $it['can'];
    });
@endphp

<aside class="tp-rail" id="tp-rail" aria-label="{{ trans('ui.main_navigation') }}">
    {{-- Toggle de modo (completo / compacto) --}}
    <button class="tp-rail__toggle" id="tp-rail-toggle" type="button" title="{{ trans('ui.toggle_menu') }}"
        aria-label="{{ trans('ui.toggle_menu_aria') }}">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 6h18M3 12h18M3 18h18" />
        </svg>
    </button>

    <a href="{{ url('/ui/v2/live') }}" class="tp-rail__logo" aria-label="{{ $serverName }}" title="{{ $serverName }}">
        @if ($hasLogo)
            <img class="tp-rail__logo-img" src="{{ Appearance::getAssetFileUrl('logo') }}" alt="{{ $serverName }}">
        @else
            <span class="tp-rail__logo-mark">{{ mb_strtoupper(mb_substr($serverName, 0, 1)) }}</span>
            <span class="tp-rail__logo-text">{{ $serverName }}</span>
        @endif
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
        {{-- Enlace externo configurable. MISMO gate que el header legacy:
             addon + ajuste activo + permiso. La URL se construye con el mismo
             builder, que sustituye los marcadores del usuario. --}}
        @if (config('addon.external_url') && settings('external_url.enabled') && Auth::user()->perm('external_url', 'view'))
            @php
                $tpExternalUrl = (new \Tobuli\Helpers\TextBuilder\UserExternalUrlBuilder())
                    ->build(settings('external_url.external_url'), Auth::user());
            @endphp
            <a href="{!! $tpExternalUrl !!}" target="_blank" rel="noopener"
                class="tp-rail__item tp-has-tooltip" data-tooltip="{{ trans('front.external_url') }}"
                aria-label="{{ trans('front.external_url') }}">
                <span class="tp-rail__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                        <path d="M15 3h6v6" />
                        <path d="M10 14 21 3" />
                    </svg></span>
                <span class="tp-rail__label">{{ trans('front.external_url') }}</span>
            </a>
        @endif
        {{-- 'return' devuelve a esta misma pantalla: sin el, cambiar el idioma
             te sacaba de la v2 y te dejaba en el mapa legacy. --}}
        <a href="{{ route('my_account_settings.change_lang', ['lang' => $nextLang, 'return' => request()->getRequestUri()]) }}"
            class="tp-rail__item tp-has-tooltip" data-tooltip="{{ trans('ui.change_language') }}" aria-label="Cambiar idioma">
            <span class="tp-rail__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20 15.3 15.3 0 0 1 0-20z" />
                </svg></span>
            <span class="tp-rail__label">Idioma · {{ strtoupper($curLang) }}</span>
        </a>
        <a href="{{ route('ui.v2.legacy') }}" class="tp-rail__item tp-has-tooltip"
            data-tooltip="{{ trans('ui.previous_version') }}" aria-label="{{ trans('front.switch_to_old_version') }}">
            <span class="tp-rail__icon">@include('ui.v2.partials.icons', ['name' => 'back'])</span>
            <span class="tp-rail__label">{{ trans('ui.previous_version') }}</span>
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
        function abierto() {
            return document.documentElement.getAttribute('data-rail') === 'full';
        }
        function alterna() {
            setMode(abierto() ? 'compact' : 'full');
            sincroniza();
        }

        // En movil el rail se superpone: hay que avisar a quien lee la pagina
        // de que el boton abre y cierra, y mostrar el velo.
        //
        // OJO con el orden: este <script> corre al parsearse y el rail va
        // ANTES que el topbar y el velo en el layout, asi que aqui todavia no
        // existen. Se resuelven cuando el DOM esta listo.
        var navBtn = null;
        var scrim = null;
        var movil = window.matchMedia('(max-width: 767px)');

        function sincroniza() {
            var on = abierto();
            if (navBtn) {
                navBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
                navBtn.setAttribute('aria-label', on ? 'Cerrar menú' : 'Abrir menú');
            }
            if (scrim) scrim.hidden = !(on && movil.matches);
        }

        if (toggle) toggle.addEventListener('click', alterna);

        function enganchaTopbar() {
            navBtn = document.getElementById('tp-nav-toggle');
            scrim = document.getElementById('tp-nav-scrim');

            if (navBtn) navBtn.addEventListener('click', alterna);
            if (scrim) scrim.addEventListener('click', function () { setMode('compact'); sincroniza(); });

            sincroniza();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enganchaTopbar);
        } else {
            enganchaTopbar();
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && abierto() && movil.matches) {
                setMode('compact');
                sincroniza();
            }
        });

        // Al pasar de movil a escritorio (o al reves) el velo puede quedarse
        // colgado: se recalcula con el cambio de medida.
        if (movil.addEventListener) movil.addEventListener('change', sincroniza);

        // En movil se arranca con el menu cerrado aunque la preferencia
        // guardada sea "full": si no, tapa la pantalla al entrar.
        if (movil.matches && abierto()) {
            document.documentElement.setAttribute('data-rail', 'compact');
        }

        sincroniza();
        // Ítems pendientes de migrar: avisan en vez de navegar.
        document.querySelectorAll('[data-rail-pending]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                if (window.tpModal && window.tpModal.toast) window.tpModal.toast('Disponible próximamente en la nueva UI.', 'info');
            });
        });
    })();
</script>
