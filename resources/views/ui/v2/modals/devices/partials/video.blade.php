{{--
    Tab Vídeo — paridad con Frontend/Devices/partials/video.blade.php (JT1078):
    has_camera + camera_channels[] + camera_channel_roles[n] y «Detectar
    capacidades» (0x9003). Mismos names: se guarda por DeviceModalHelper y
    DeviceService normaliza (sin cámara ⇒ sin canales). Gate de la pestaña y
    de la edición: DevicePropertiesPolicy (device_video view/edit).
    El botón de detectar lo atiende tp-device-video.js (delegado en document).
--}}
@php
    $videoEditable = auth()->user()->can('edit', $item, 'has_camera');
    $videoDisabled = $videoEditable ? '' : 'disabled';
    $videoChannels = array_map('intval', (array) ($item->camera_channels ?? []));
    $videoRoles = (array) ($item->camera_channel_roles ?? []);
    $videoRoleOptions = collect(\Tobuli\Entities\Device::VIDEO_CHANNEL_ROLES)
        ->mapWithKeys(fn ($role) => [$role => trans("front.video_role_$role")])->all();
@endphp

@if (! $item->supportsVideo())
    <div class="tp-form__placeholder">
        {{ trans('front.video_protocol_unsupported', ['protocol' => $item->protocol]) }}
    </div>
@endif

<div class="tp-form__grid">
    <label class="tp-form__field tp-form__field--checkbox">
        <input type="hidden" name="has_camera" value="0" {{ $videoDisabled }}>
        <input type="checkbox" name="has_camera" value="1" {{ $item->has_camera ? 'checked' : '' }} {{ $videoDisabled }}>
        <span class="tp-form__label">{{ trans('validation.attributes.has_camera') }}</span>
    </label>

    <div class="tp-form__field">
        <span class="tp-form__label">{{ trans('validation.attributes.camera_channels') }}</span>
        <div class="tp-dv-channels">
            @for ($channel = 1; $channel <= \Tobuli\Entities\Device::VIDEO_MAX_CHANNELS; $channel++)
                <div class="tp-dv-channels__item">
                    <label>
                        <input type="checkbox" name="camera_channels[]" value="{{ $channel }}"
                            {{ in_array($channel, $videoChannels, true) ? 'checked' : '' }} {{ $videoDisabled }}>
                        {{ trans('front.channel') }} {{ $channel }}
                    </label>
                    <select class="tp-input" name="camera_channel_roles[{{ $channel }}]" {{ $videoDisabled }}>
                        <option value="">{{ trans('front.video_role_default') }}</option>
                        @foreach ($videoRoleOptions as $role => $label)
                            <option value="{{ $role }}" {{ ($videoRoles[$channel] ?? '') === $role ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endfor
        </div>
        <small class="tp-form__hint">{{ trans('front.video_channels_help') }}</small>
    </div>

    @if ($item->id && $item->supportsVideo() && auth()->user()->perm('device_video', 'view'))
        @php
            $videoAttributes = app(\Tobuli\Services\DeviceVideoService::class)->describeAttributes($item->camera_attributes);
        @endphp
        <div class="tp-form__field" data-tp-video-attrs
            data-url="{{ route('devices.video.attributes', $item->id) }}"
            data-query-url="{{ route('devices.video.attributes.query', $item->id) }}">
            <span class="tp-form__label">{{ trans('front.video_attributes') }}</span>
            <p class="tp-text-sm" data-tp-video-attrs-info style="margin:0 0 8px;">
                {{ $videoAttributes['summary'] ?? trans('front.video_attributes_none') }}
            </p>
            @if ($videoEditable)
                <div>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tp-video-attrs-detect>
                        {{ trans('front.video_attributes_detect') }}
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>
