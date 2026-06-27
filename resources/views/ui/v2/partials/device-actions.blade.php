{{--
    UI v2 · Menú de acciones por dispositivo (kebab de la lista Live)
    Renderizado por Ui\V2\DevicesController::actions($id). Espeja
    Tobuli/Views/Frontend/Objects/item.blade.php (dropdown) — MISMO gating
    de permisos y flags por device que el legacy.

    Convención de cada ítem:
      - data-act="history|follow|edit"  → acción NATIVA v2 (ya implementada)
      - data-act="pending"              → acción PENDIENTE de implementar
                                          (data-feature + data-url legacy para el futuro)
--}}
@php
    /** @var \Tobuli\Entities\Device $device */
    $u = Auth::user();
@endphp
<div class="tp-row-menu__list" data-device-id="{{ $device->id }}">

    @if ($u->perm('history', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="history" data-range="last_hour">
            {{ trans('front.show_history') }} ({{ mb_strtolower(trans('front.last_hour')) }})
        </button>
        <button type="button" class="tp-row-menu__item" data-act="history" data-range="today">
            {{ trans('front.show_history') }} ({{ mb_strtolower(trans('front.today')) }})
        </button>
        <button type="button" class="tp-row-menu__item" data-act="history" data-range="yesterday">
            {{ trans('front.show_history') }} ({{ mb_strtolower(trans('front.yesterday')) }})
        </button>
        <div class="tp-row-menu__sep"></div>
    @endif

    <button type="button" class="tp-row-menu__item" data-act="follow">
        {{ trans('front.follow') }}
    </button>

    @if ($u->perm('send_command', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="send_command"
            data-url="{{ route('send_command.create') }}">
            {{ trans('front.send_command') }}
        </button>
    @endif

    @if ($u->perm('devices', 'edit'))
        <button type="button" class="tp-row-menu__item" data-act="edit">
            {{ trans('global.edit') }}
        </button>
    @endif

    @if ($u->perm('devices', 'edit') && $device->vehicle_type_id)
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="vehicle_state"
            data-url="{{ route('devices.vehicle_state_form', $device->id) }}">
            {{ trans('front.change_vehicle_state') }}
        </button>
    @endif

    @if ($u->perm('chat', 'view') && $device->canChat())
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="chat"
            data-url="{{ route('chat.init', [$device->id, 'device', 1]) }}">
            {{ trans('front.chat') }}
        </button>
    @endif

    @if ($u->perm('sharing', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="sharing"
            data-url="{{ route('sharing.index', ['devices_id' => $device->id]) }}">
            {{ trans('front.sharing') }}
        </button>
    @endif

    @if ($u->perm('checklist_qr_code', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="qr"
            data-url="{{ route('checklist.qr_code_preview', ['device_id' => $device->id]) }}">
            {{ trans('front.checklist_qr_code') }}
        </button>
    @endif

    @if (!empty($device->sim_number) && $u->able('configure_device'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="device_config"
            data-url="{{ route('device_config.index', ['device_id' => $device->id]) }}">
            {{ trans('front.device_configuration') }}
        </button>
    @endif

    @if ($u->perm('call_actions', 'view') && $u->perm('events', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="call_action"
            data-url="{{ route('call_actions.create', ['device_id' => $device->id]) }}">
            {{ trans('front.call_action') }}
        </button>
    @endif

    @if ($device->isPlanAble())
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="device_plans"
            data-url="{{ route('device_plans.index', ['device_id' => $device->id]) }}">
            {{ trans('admin.device_plans') }}
        </button>
    @endif

    @if ($u->perm('alerts', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="alerts"
            data-url="{{ route('device.alerts.index', ['device_id' => $device->id]) }}">
            {{ trans('front.device_alerts') }}
        </button>
    @endif

    @if ($u->perm('device_route_types', 'view'))
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="route_types"
            data-url="{{ route('device_route_type.show', ['device_id' => $device->id]) }}">
            {{ trans('front.route_types') }}
        </button>
    @endif

    @if ($u->perm('camera', 'view') && $device->isGeneral())
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="camera"
            data-url="{{ route('device_media.create', ['search_phrase' => $device->imei, 'device_id' => $device->id]) }}">
            {!! trans('front.camera') !!}
        </button>
    @endif

    @if (in_array($device->protocol, ['jt808', 'howen']) && $device->traccar_device_id)
        <div class="tp-row-menu__sep"></div>
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="live_video"
            data-url="{{ route('device_video.live.modal', $device->id) }}">
            {{ trans('front.live_video') }}
        </button>
        <button type="button" class="tp-row-menu__item" data-act="pending" data-feature="playback"
            data-url="{{ route('device_video.playback.modal', $device->id) }}">
            Reproducción
        </button>
    @endif
</div>
