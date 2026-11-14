@extends('Frontend.Layouts.modal')

@section('modal_class', 'modal-lg')

@section('title')
    <i class="fa fa-film"></i> {{ trans('front.video_recordings') }} ({{ $device->name }})
@stop

@section('body')
    <div id="video-recordings" data-device-id="{{ $device->id }}"
         data-query-url="{{ route('devices.video.recordings.query', $device->id) }}"
         data-list-url="{{ route('devices.video.recordings.list', $device->id) }}"
         data-downloads-url="{{ route('devices.video.downloads', $device->id) }}">
        <div class="row">
            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('vr-channel', trans('front.channel')) !!}
                    {!! Form::select('channel', [0 => trans('front.video_all_channels')] + collect($channels)->mapWithKeys(fn ($ch) => [
                        $ch => trans('front.channel').' '.$ch.(! empty($roles[$ch]) ? ' · '.trans('front.video_role_'.$roles[$ch]) : '')
                    ])->all(), 0, ['id' => 'vr-channel', 'class' => 'form-control']) !!}
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('vr-from', trans('front.from')) !!}
                    {!! Form::text('from', $from, ['id' => 'vr-from', 'class' => 'form-control datetimepicker', 'autocomplete' => 'off']) !!}
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('vr-to', trans('front.to')) !!}
                    {!! Form::text('to', $to, ['id' => 'vr-to', 'class' => 'form-control datetimepicker', 'autocomplete' => 'off']) !!}
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    <label class="hidden-xs">&nbsp;</label>
                    <button type="button" class="btn btn-action btn-block" id="vr-query">
                        <i class="fa fa-search"></i> {{ trans('front.video_recordings_query') }}
                    </button>
                </div>
            </div>
        </div>

        <div id="vr-status" class="alert" style="display: none;"></div>

        <div id="vr-player" style="display: none; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                <strong id="vr-player-title"></strong>
                <button type="button" class="btn btn-default btn-xs" id="vr-player-close">&times; {{ trans('front.close') }}</button>
            </div>
            <div id="vr-player-frame" style="position: relative; width: 100%; height: 340px; background: #000;"></div>
        </div>

        <div class="table-responsive" id="vr-results" style="display: none; max-height: 40vh; overflow-y: auto;">
            <table class="table table-list">
                <thead>
                <tr>
                    <th>{{ trans('front.channel') }}</th>
                    <th>{{ trans('front.from') }}</th>
                    <th>{{ trans('front.to') }}</th>
                    <th>{{ trans('front.duration') }}</th>
                    <th>{{ trans('front.video_size') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="vr-items"></tbody>
            </table>
        </div>

        <div id="vr-downloads" style="display: none; margin-top: 12px;">
            <strong>{{ trans('front.video_downloads') }}</strong>
            <div class="table-responsive" style="max-height: 25vh; overflow-y: auto;">
                <table class="table table-list">
                    <tbody id="vr-downloads-items"></tbody>
                </table>
            </div>
        </div>
    </div>
@stop

@section('buttons')
    <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('global.close') }}</button>
@stop
