@extends('Frontend.Layouts.modal')

@section('modal_class', 'modal-lg')

@section('title', trans('admin.command_rules_logs') . ' — ' . $item->name)

@section('body')
    <div class="form-group" style="max-width: 260px">
        {!! Form::select('phase', ['' => trans('front.all')] + $phases, $phase, ['class' => 'form-control', 'id' => 'command-rule-log-phase']) !!}
    </div>

    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
        <table class="table table-list table-condensed">
            <thead>
            <tr>
                {!! tableHeader('front.date') !!}
                {!! tableHeader('front.object') !!}
                {!! tableHeader('admin.command_rules_phase') !!}
                {!! tableHeader('admin.command_rules_condition') !!}
                {!! tableHeader('front.message') !!}
            </tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                @php
                    $labels = [
                        'probe_sent'    => 'info',
                        'probe_failed'  => 'danger',
                        'probe_skipped' => 'default',
                        'response'      => 'primary',
                        'matched'       => 'warning',
                        'action_sent'   => 'success',
                        'action_failed' => 'danger',
                    ];
                @endphp
                <tr>
                    <td style="white-space: nowrap">{{ Formatter::time()->human($log->created_at) }}</td>
                    <td>{{ $log->device->name ?? ('#' . $log->device_id) }}</td>
                    <td><span class="label label-{{ $labels[$log->phase] ?? 'default' }}">{{ $phases[$log->phase] ?? $log->phase }}</span></td>
                    <td>{{ $log->condition ? $log->condition->describe() : '' }}</td>
                    <td style="word-break: break-all"><code>{{ $log->message }}</code></td>
                </tr>
            @empty
                <tr><td class="no-data" colspan="5">{!! trans('admin.no_data') !!}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <script>
        $('#command-rule-log-phase').off('change.crlogs').on('change.crlogs', function () {
            var $modal = $(this).closest('.modal');
            var url = '{{ route('admin.command_rules.logs', $item->id) }}' + '?phase=' + encodeURIComponent($(this).val());
            $modal.find('.contents').load(url, function () {
                initComponents($modal);
            });
        });
    </script>
@stop

@section('footer')
    <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('global.close') }}</button>
@stop
