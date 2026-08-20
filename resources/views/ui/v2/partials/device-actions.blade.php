{{--
    UI v2 · Menú de acciones por dispositivo (kebab de la lista Live)
    TEMPLATE ESTÁTICO DE SESIÓN (sin AJAX): se renderiza UNA vez dentro de
    <template id="tp-row-menu-tpl"> en live.blade. Espeja el gating del
    legacy Objects/item.blade:
      - Los permisos del usuario se resuelven aquí (server, fijos por sesión).
      - Las condiciones POR DEVICE van como data-need="vt|chat|sim|plans|
        app_uuid|general|video": live.js las evalúa con los flags que ya
        viajan en el JSON de la lista (api.devices) y quita lo que no aplique.
      - Las URLs llevan __ID__ / __IMEI__ que live.js sustituye al abrir.
    Convención data-act: history|follow|edit|send_command = acción nativa v2;
    pending = feature aún legacy (data-url de referencia).
--}}
@php
    $u = Auth::user();

    // Iconos (SVG inline, estilo trazo 16px — consistente con la UI v2).
    $ic = [
        'last_hour' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'today'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4h4"/></svg>',
        'yesterday' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 8v4l3 2"/></svg>',
        'command'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
        'edit'      => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>',
        'vehicle_state' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2v-3.34a4 4 0 0 0-.8-2.4L18 8h-2"/><path d="M5 17H3v-6l2-5h9l3 5v6h-2"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg>',
        'follow'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/></svg>',
        'route_type' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h4a3 3 0 0 0 3-3V8a3 3 0 0 1 3-3h6"/><circle cx="4" cy="19" r="2"/><circle cx="20" cy="5" r="2"/></svg>',
        'dev_alerts' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
        'dev_config' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'gallery'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
        'video'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>',
        'share'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>',
        'sharing'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98M15.41 6.51 8.59 10.49"/></svg>',
        'chat'      => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'qr'        => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM21 14v.01M14 21v.01M21 21v.01M17 17v.01"/></svg>',
        'device_config' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'call'      => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        'plans'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        'alerts'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
        'route'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
        'reset'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>',
        'camera'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3"/></svg>',
        'video'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
        'playback'  => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m10 9 5 3-5 3Z"/></svg>',
    ];
@endphp
<div class="tp-row-menu__list" data-device-id="__ID__">

    @if ($u->perm('history', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="history" data-range="last_hour">
            <span class="tp-row-menu__icon">{!! $ic['last_hour'] !!}</span>
            <span>{{ trans('front.show_history') }} ({{ mb_strtolower(trans('front.last_hour')) }})</span>
        </button>
        <button type="button" class="tp-row-menu__item" data-act="history" data-range="today">
            <span class="tp-row-menu__icon">{!! $ic['today'] !!}</span>
            <span>{{ trans('front.show_history') }} ({{ mb_strtolower(trans('front.today')) }})</span>
        </button>
        <button type="button" class="tp-row-menu__item" data-act="history" data-range="yesterday">
            <span class="tp-row-menu__icon">{!! $ic['yesterday'] !!}</span>
            <span>{{ trans('front.show_history') }} ({{ mb_strtolower(trans('front.yesterday')) }})</span>
        </button>
        <div class="tp-row-menu__sep"></div>
    @endif

    @if ($u->perm('send_command', 'view'))
        {{-- Modal nativo v2 (tp-commands.js), preseleccionando el device --}}
        <button type="button" class="tp-row-menu__item" data-act="send_command">
            <span class="tp-row-menu__icon">{!! $ic['command'] !!}</span>
            <span>{{ trans('front.send_command') }}</span>
        </button>
    @endif

    @if ($u->perm('devices', 'edit'))
        <button type="button" class="tp-row-menu__item" data-act="edit">
            <span class="tp-row-menu__icon">{!! $ic['edit'] !!}</span>
            <span>{{ trans('global.edit') }}</span>
        </button>
    @endif

    @if ($u->perm('devices', 'edit'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="vehicle_state"
            data-need="vt" data-url="{{ route('devices.vehicle_state_form', '__ID__') }}">
            <span class="tp-row-menu__icon">{!! $ic['vehicle_state'] !!}</span>
            <span>{{ trans('front.change_vehicle_state') }}</span>
        </button>
    @endif

    <button type="button" class="tp-row-menu__item" data-act="follow">
        <span class="tp-row-menu__icon">{!! $ic['follow'] !!}</span>
        <span>{{ trans('front.follow') }}</span>
    </button>

    {{-- Vídeo a bordo: visible solo en equipos con protocolo compatible
         (data-need="video" ⇒ jt808/howen). La reproducción llega con la
         integración del decoder; por ahora la opción avisa de eso. --}}
    @if ($u->perm('camera', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="device_video" data-need="video">
            <span class="tp-row-menu__icon">{!! $ic['video'] !!}</span>
            <span>{{ trans('front.live_video') }}</span>
        </button>
    @endif

    {{-- Galería de la cámara (paridad Objects/item.blade: perm camera + isGeneral) --}}
    @if ($u->perm('camera', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="device_media" data-need="general">
            <span class="tp-row-menu__icon">{!! $ic['gallery'] !!}</span>
            <span>{{ trans('front.images') }}</span>
        </button>
    @endif

    {{-- Configurar GPS por SMS (able configure_device + el device debe tener SIM) --}}
    @if (Auth::user()->able('configure_device'))
        <button type="button" class="tp-row-menu__item" data-act="device_config" data-need="sim">
            <span class="tp-row-menu__icon">{!! $ic['dev_config'] !!}</span>
            <span>{{ trans('front.device_configuration') }}</span>
        </button>
    @endif

    {{-- Alertas del vehículo con vigencia (paridad Objects/item.blade) --}}
    @if ($u->perm('alerts', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="device_alerts">
            <span class="tp-row-menu__icon">{!! $ic['dev_alerts'] !!}</span>
            <span>{{ trans('front.alerts') }}</span>
        </button>
    @endif

    {{-- Tipos de trayecto (privado/negocio) — paridad Objects/item.blade --}}
    @if ($u->perm('device_route_types', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="route_types">
            <span class="tp-row-menu__icon">{!! $ic['route_type'] !!}</span>
            <span>{{ trans('front.route_types') }}</span>
        </button>
    @endif

    @if ($u->perm('sharing', 'view'))
        {{-- front.share y front.sharing se traducen los DOS como "Compartir",
             asi que en el menu salian dos entradas identicas haciendo cosas
             distintas: una crea un enlace y la otra lleva al listado. Se
             matizan aqui, sin tocar los ficheros de idioma (los comparte el
             legacy). --}}
        <button type="button" class="tp-row-menu__item" data-act="share" data-feature="share">
            <span class="tp-row-menu__icon">{!! $ic['share'] !!}</span>
            <span>{{ trans('front.share') }}</span>
        </button>
        <button type="button" class="tp-row-menu__item" data-act="sharing_list" data-feature="sharing">
            <span class="tp-row-menu__icon">{!! $ic['sharing'] !!}</span>
            <span>Enlaces compartidos</span>
        </button>
    @endif

    @if ($u->perm('chat', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="chat"
            data-need="chat" data-url="{{ route('chat.init', ['__ID__', 'device', 1]) }}">
            <span class="tp-row-menu__icon">{!! $ic['chat'] !!}</span>
            <span>{{ trans('front.chat') }}</span>
        </button>
    @endif

    @if ($u->perm('checklist_qr_code', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="qr"
            data-url="{{ route('checklist.qr_code_preview', ['device_id' => '__ID__']) }}">
            <span class="tp-row-menu__icon">{!! $ic['qr'] !!}</span>
            <span>{{ trans('front.checklist_qr_code') }}</span>
        </button>
    @endif

    @if ($u->perm('call_actions', 'view') && $u->perm('events', 'view'))
        {{-- Abre el modal nativo tp-call-actions con el device preseleccionado
             (paridad legacy call_actions.create?device_id) --}}
        <button type="button" class="tp-row-menu__item" data-act="call_action">
            <span class="tp-row-menu__icon">{!! $ic['call'] !!}</span>
            <span>{{ trans('front.call_action') }}</span>
        </button>
    @endif

    <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="device_plans"
        data-need="plans" data-url="{{ route('device_plans.index', ['device_id' => '__ID__']) }}">
        <span class="tp-row-menu__icon">{!! $ic['plans'] !!}</span>
        <span>{{ trans('admin.device_plans') }}</span>
    </button>

    @if ($u->perm('devices', 'edit'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="reset_app_uuid"
            data-need="app_uuid" data-url="{{ route('devices.do_reset_app_uuid', '__ID__') }}">
            <span class="tp-row-menu__icon">{!! $ic['reset'] !!}</span>
            <span>{{ trans('front.reset_app_uuid') }}</span>
        </button>
    @endif

    <div class="tp-row-menu__sep" data-need="video"></div>
    <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="playback"
        data-need="video" data-url="{{ route('device_video.playback.modal', '__ID__') }}">
        <span class="tp-row-menu__icon">{!! $ic['playback'] !!}</span>
        <span>{{ trans('front.playback') }}</span>
    </button>
</div>
