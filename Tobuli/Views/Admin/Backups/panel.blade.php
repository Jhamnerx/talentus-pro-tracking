<div class="panel panel-default">

    <div class="panel-heading">
        <div class="panel-title">{{ trans('front.database_backups') }}</div>
    </div>

    <div class="panel-body">

        @if(!(new \Tobuli\Helpers\Backup\BackupService(settings('backups')))->hasHiveCredentials())
        <div class="alert alert-warning">
            <span class="warning">{{ trans('admin.automatic_backup_disabled') }}</span>
        </div>
        @endif

        {!! Form::open(['route' => 'admin.backups.save', 'method' => 'POST', 'class' => 'form form-horizontal', 'id' => 'database-backup-form']) !!}

        <div class="form-group">
            {!! Form::label('type', trans('validation.attributes.type'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
            <div class="col-xs-12 col-sm-8">
                {!! Form::select('type', $types, isset($settings['type']) ? $settings['type'] : null, ['class' => 'form-control']) !!}
            </div>
        </div>

        <div class="backup-type backup-type-custom">
            <div class="form-group">
                {!! Form::label('ftp_server', trans('validation.attributes.ftp_server'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::text('ftp_server', isset($settings['ftp_server']) ? $settings['ftp_server'] : null, ['class' => 'form-control']) !!}
                </div>
            </div>
            <div class="form-group">
                {!! Form::label('ftp_port', trans('validation.attributes.ftp_port'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::text('ftp_port', isset($settings['ftp_port']) ? $settings['ftp_port'] : 21, ['class' => 'form-control']) !!}
                </div>
            </div>
            <div class="form-group">
                {!! Form::label('ftp_username', trans('validation.attributes.ftp_username'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::text('ftp_username', isset($settings['ftp_username']) ? $settings['ftp_username'] : null, ['class' => 'form-control']) !!}
                </div>
            </div>
            <div class="form-group">
                {!! Form::label('ftp_password', trans('validation.attributes.ftp_password'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::password('ftp_password', ['class' => 'form-control']) !!}
                </div>
            </div>
            <div class="form-group">
                {!! Form::label('ftp_path', trans('validation.attributes.ftp_path'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::text('ftp_path', isset($settings['ftp_path']) ? $settings['ftp_path'] : '/', ['class' => 'form-control']) !!}
                </div>
            </div>
        </div>
        <div class="form-group">
            {!! Form::label('period', trans('validation.attributes.period'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
            <div class="col-xs-12 col-sm-8">
                {!! Form::select('period', $periods, isset($settings['period']) ? $settings['period'] : null, ['class' => 'form-control']) !!}
            </div>
        </div>
        <div class="form-group">
            {!! Form::label('hour', trans('validation.attributes.hour'), ['class' => 'col-xs-12 col-sm-4 control-label"']) !!}
            <div class="col-xs-12 col-sm-8">
                {!! Form::select('hour', $hours, isset($settings['hour']) ? $settings['hour'] : null, ['class' => 'form-control']) !!}
                <small>{{ trans('front.server_time_now') }} {{ date('Y-m-d H:i:s') }} (UTC 00:00)</small>
            </div>
        </div>

        <hr>
        <h4>{{ trans('backups.drive_title') }}</h4>

        <div class="form-group">
            <div class="col-xs-12 col-sm-8 col-sm-offset-4">
                <label>
                    {!! Form::checkbox('drive_enabled', 1, !empty($settings['drive_enabled'])) !!}
                    {{ trans('backups.drive_enabled') }}
                </label>
            </div>
        </div>

        @foreach ([
            'drive_remote' => $settings['drive_remote'] ?? 'gdrive',
            'drive_path' => $settings['drive_path'] ?? 'backup_plataforma/',
            'retention_daily_days' => $settings['retention_daily_days'] ?? 30,
            'retention_long_term_months' => $settings['retention_long_term_months'] ?? 2,
            'retention_max_days' => $settings['retention_max_days'] ?? 365,
        ] as $field => $value)
            <div class="form-group">
                {!! Form::label($field, trans('backups.' . $field), ['class' => 'col-xs-12 col-sm-4 control-label']) !!}
                <div class="col-xs-12 col-sm-8">
                    {!! Form::text($field, $value, ['class' => 'form-control']) !!}
                </div>
            </div>
        @endforeach

        <button class="btn btn-main" type="button" onClick="test_drive();">
            <i class="icon upload"></i> {{ trans('backups.test_drive') }}
        </button>
        <div class="test_drive_response alert alert-danger" style="display: none;"></div>

        @if (isset($settings['ftp_server']))
            <hr>
            <h4>
                {{ trans('front.test_ftp_upload') }}
            </h4>
            <button class="btn btn-main test_ftp" type="button" onClick="test_ftp();"><i class="icon upload"></i> {{ trans('front.test_ftp') }}</button>
            <div class="test_ftp_response alert alert-danger" style="display: none;">
            </div>
        @endif

        {!! Form::close() !!}
    </div>

    <div class="panel-footer">
        <button type="submit" class="btn btn-action" onClick="$('#database-backup-form').submit();">{{ trans('global.save') }}</button>
        <a class="btn btn-default" href="{{ route('admin.backup.index') }}" target="_blank">{{ trans('front.latest_uploads') }}</a>
    </div>
</div>

@section('javascript')
    <script>
        function test_ftp() {
            $.ajax({
                type: 'GET',
                url: '{{ route('admin.backups.test') }}',
                beforeSend: function() {
                    $('.test_ftp_response').hide();
                },
                success: function(res) {
                    if (res.status == 1)
                        $('.test_ftp_response').show().removeClass('alert-danger').addClass('alert-success').html(res.message);
                    else
                        $('.test_ftp_response').show().removeClass('alert-success').addClass('alert-danger').html(res.message);
                }
            });
        }

        function test_drive() {
            $.ajax({
                type: 'GET',
                url: '{{ route('admin.backups.test_drive') }}',
                beforeSend: function() {
                    $('.test_drive_response').hide();
                },
                success: function(res) {
                    $('.test_drive_response')
                        .show()
                        .toggleClass('alert-success', res.status == 1)
                        .toggleClass('alert-danger', res.status != 1)
                        .text(res.message);
                },
                error: function() {
                    $('.test_drive_response')
                        .show()
                        .removeClass('alert-success')
                        .addClass('alert-danger')
                        .text(@json(trans('front.unexpected_error')));
                }
            });
        }

        $(document).ready(function() {
            $(document).on('change', 'select[name="type"]', function () {
                $('.backup-type').hide();
                $('.backup-type-'+$(this).val()).show();
            });

            $('select[name="type"]').trigger('change');
        });
    </script>
@stop