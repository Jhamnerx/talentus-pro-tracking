@php
    $videoEditable = Auth::user()->can('edit', $item, 'has_camera');
    $videoDisabled = $videoEditable ? [] : ['disabled' => 'disabled'];
    $videoChannels = (array)($item->camera_channels ?? []);
    $videoRoles = (array)($item->camera_channel_roles ?? []);
@endphp

@if ( ! $item->supportsVideo())
    <div class="alert alert-warning">
        {{ trans('front.video_protocol_unsupported', ['protocol' => $item->protocol]) }}
    </div>
@endif

<div class="form-group">
    <div class="checkbox">
        {!! Form::hidden('has_camera', 0) !!}
        {!! Form::checkbox('has_camera', 1, (bool)$item->has_camera, ['id' => 'has_camera', 'data-disabler' => '#camera-channels-group;hide-disable'] + $videoDisabled) !!}
        {!! Form::label('has_camera', trans('validation.attributes.has_camera')) !!}
    </div>
</div>

<div class="form-group" id="camera-channels-group">
    {!! Form::label(null, trans('validation.attributes.camera_channels').':') !!}
    <div class="row">
        @for ($channel = 1; $channel <= \Tobuli\Entities\Device::VIDEO_MAX_CHANNELS; $channel++)
            <div class="col-xs-6" style="margin-bottom: 6px;">
                <div class="checkbox checkbox-inline" style="min-width: 85px;">
                    {!! Form::checkbox('camera_channels[]', $channel, in_array($channel, $videoChannels), ['id' => "camera_channel_$channel"] + $videoDisabled) !!}
                    {!! Form::label("camera_channel_$channel", trans('front.channel').' '.$channel) !!}
                </div>
                {!! Form::select(
                    "camera_channel_roles[$channel]",
                    ['' => trans('front.video_role_default')] + collect(\Tobuli\Entities\Device::VIDEO_CHANNEL_ROLES)
                        ->mapWithKeys(fn ($role) => [$role => trans("front.video_role_$role")])->all(),
                    $videoRoles[$channel] ?? '',
                    ['class' => 'form-control input-sm', 'style' => 'display: inline-block; width: auto; max-width: 55%;'] + $videoDisabled
                ) !!}
            </div>
        @endfor
    </div>
    <p class="help-block">{{ trans('front.video_channels_help') }}</p>
</div>

@if ($item->id && $item->supportsVideo() && Auth::user()->perm('device_video', 'view'))
    @php($videoAttributes = app(\Tobuli\Services\DeviceVideoService::class)->describeAttributes($item->camera_attributes))
    <div class="form-group" id="camera-attributes"
         data-url="{{ route('devices.video.attributes', $item->id) }}"
         data-query-url="{{ route('devices.video.attributes.query', $item->id) }}">
        {!! Form::label(null, trans('front.video_attributes').':') !!}
        <p class="help-block" id="camera-attributes-info">
            {{ $videoAttributes['summary'] ?? trans('front.video_attributes_none') }}
        </p>
        @if ($videoEditable)
            <button type="button" class="btn btn-default btn-sm" id="camera-attributes-detect">
                <i class="fa fa-search"></i> {{ trans('front.video_attributes_detect') }}
            </button>
        @endif
    </div>
@endif
