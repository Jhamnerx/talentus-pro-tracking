{{--
    UI v2 · Menú de acciones por dispositivo (kebab de la lista Live)
    Renderizado por Ui\V2\DevicesController::actions($id). Espeja
    Tobuli/Views/Frontend/Objects/item.blade.php (dropdown) — MISMO gating
    de permisos y flags por device que el legacy, con iconos por ítem.

    Convención de cada ítem:
      - data-act="history|follow|edit"  → acción NATIVA v2 (ya implementada)
      - data-act="pending"              → acción PENDIENTE de portar a v2
                                          (data-feature + data-url legacy para el futuro)
--}}
@php
    /** @var \Tobuli\Entities\Device $device */
    $u = Auth::user();

    // Iconos (SVG inline, estilo trazo 16px — consistente con la UI v2).
    $ic = [
        'last_hour' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'today'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        'yesterday' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 8v4l3 2"/></svg>',
        'command'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
        'edit'      => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>',
        'vehicle_state' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2v-3.34a4 4 0 0 0-.8-2.4L18 8h-2"/><path d="M5 17H3v-6l2-5h9l3 5v6h-2"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg>',
        'follow'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l19-9-9 19-2-8-8-2Z"/></svg>',
        'share'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98M15.41 6.51 8.59 10.49"/></svg>',
        'sharing'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
        'chat'      => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'qr'        => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM21 14v.01M14 21v.01M21 21v.01M17 17v.01"/></svg>',
        'device_config' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'call'      => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        'plans'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        'alerts'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
        'route'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg>',
        'reset'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>',
        'camera'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3"/></svg>',
        'video'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
        'playback'  => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m10 9 5 3-5 3Z"/></svg>',
    ];
@endphp
<div class="tp-row-menu__list" data-device-id="{{ $device->id }}">

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
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="send_command"
            data-url="{{ route('send_command.create') }}">
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

    @if ($u->perm('devices', 'edit') && $device->vehicle_type_id)
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="vehicle_state"
            data-url="{{ route('devices.vehicle_state_form', $device->id) }}">
            <span class="tp-row-menu__icon">{!! $ic['vehicle_state'] !!}</span>
            <span>{{ trans('front.change_vehicle_state') }}</span>
        </button>
    @endif

    <button type="button" class="tp-row-menu__item" data-act="follow">
        <span class="tp-row-menu__icon">{!! $ic['follow'] !!}</span>
        <span>{{ trans('front.follow') }}</span>
    </button>

    @if ($u->perm('sharing', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="share"
            data-url="{{ route('sharing.create', ['devices_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['share'] !!}</span>
            <span>{{ trans('front.share') }}</span>
        </button>
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="sharing"
            data-url="{{ route('sharing.index', ['devices_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['sharing'] !!}</span>
            <span>{{ trans('front.sharing') }}</span>
        </button>
    @endif

    @if ($u->perm('chat', 'view') && $device->canChat())
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="chat"
            data-url="{{ route('chat.init', [$device->id, 'device', 1]) }}">
            <span class="tp-row-menu__icon">{!! $ic['chat'] !!}</span>
            <span>{{ trans('front.chat') }}</span>
        </button>
    @endif

    @if ($u->perm('checklist_qr_code', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="qr"
            data-url="{{ route('checklist.qr_code_preview', ['device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['qr'] !!}</span>
            <span>{{ trans('front.checklist_qr_code') }}</span>
        </button>
    @endif

    @if (!empty($device->sim_number) && $u->able('configure_device'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="device_config"
            data-url="{{ route('device_config.index', ['device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['device_config'] !!}</span>
            <span>{{ trans('front.device_configuration') }}</span>
        </button>
    @endif

    @if ($u->perm('call_actions', 'view') && $u->perm('events', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="call_action"
            data-url="{{ route('call_actions.create', ['device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['call'] !!}</span>
            <span>{{ trans('front.call_action') }}</span>
        </button>
    @endif

    @if ($device->isPlanAble())
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="device_plans"
            data-url="{{ route('device_plans.index', ['device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['plans'] !!}</span>
            <span>{{ trans('admin.device_plans') }}</span>
        </button>
    @endif

    @if ($u->perm('alerts', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="alerts"
            data-url="{{ route('device.alerts.index', ['device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['alerts'] !!}</span>
            <span>{{ trans('front.device_alerts') }}</span>
        </button>
    @endif

    @if ($u->perm('device_route_types', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="route_types"
            data-url="{{ route('device_route_type.show', ['device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['route'] !!}</span>
            <span>{{ trans('front.route_types') }}</span>
        </button>
    @endif

    @if ($u->perm('devices', 'edit') && $device->app_uuid)
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="reset_app_uuid"
            data-url="{{ route('devices.do_reset_app_uuid', $device->id) }}">
            <span class="tp-row-menu__icon">{!! $ic['reset'] !!}</span>
            <span>{{ trans('front.reset_app_uuid') }}</span>
        </button>
    @endif

    @if ($u->perm('camera', 'view') && $device->isGeneral())
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="camera"
            data-url="{{ route('device_media.create', ['search_phrase' => $device->imei, 'device_id' => $device->id]) }}">
            <span class="tp-row-menu__icon">{!! $ic['camera'] !!}</span>
            <span>{!! trans('front.camera') !!}</span>
        </button>
    @endif

    @if (in_array($device->protocol, ['jt808', 'howen']) && $device->traccar_device_id)
        <div class="tp-row-menu__sep"></div>
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="live_video"
            data-url="{{ route('device_video.live.modal', $device->id) }}">
            <span class="tp-row-menu__icon">{!! $ic['video'] !!}</span>
            <span>{{ trans('front.live_video') }}</span>
        </button>
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="playback"
            data-url="{{ route('device_video.playback.modal', $device->id) }}">
            <span class="tp-row-menu__icon">{!! $ic['playback'] !!}</span>
            <span>Reproducción</span>
        </button>
    @endif
</div>
