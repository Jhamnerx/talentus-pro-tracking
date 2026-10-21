@extends('Admin.Layouts.default')

@section('content')
    <div class="panel panel-default" id="table_command_rules">

        <input type="hidden" name="sorting[sort_by]" value="{{ $items->sorting['sort_by'] }}" data-filter>
        <input type="hidden" name="sorting[sort]" value="{{ $items->sorting['sort'] }}" data-filter>

        <div class="panel-heading">
            <ul class="nav nav-tabs nav-icons pull-right">
                <li role="presentation" class="">
                    <a href="javascript:" type="button" class="" data-modal="command_rules_create" data-url="{{ route('admin.command_rules.create') }}">
                        <i class="icon add" title="{{ trans('admin.add_new') }}"></i>
                    </a>
                </li>
            </ul>

            <div class="panel-title">{{ trans('admin.command_rules') }}</div>

            <div class="panel-form">
                <div class="form-group search">
                    {!! Form::text('search_phrase', null, ['class' => 'form-control', 'placeholder' => trans('admin.search_it'), 'data-filter' => 'true']) !!}
                </div>
            </div>
        </div>

        <div class="panel-body" data-table>
            @include('Admin.CommandRules.table')
        </div>
    </div>
@stop

@section('javascript')
    <script>
        tables.set_config('table_command_rules', {
            url:'{{ route("admin.command_rules.index") }}',
            delete_url:'{{ route("admin.command_rules.destroy") }}'
        });
        function command_rules_edit_modal_callback() {
            tables.get('table_command_rules');
        }
        function command_rules_create_modal_callback() {
            tables.get('table_command_rules');
        }

        // Ejecutar ahora: POST y refrescar la tabla
        $(document).on('click', '[data-command-rule-run]', function (e) {
            e.preventDefault();
            var $el = $(this);
            $.post($el.data('command-rule-run'), {_token: '{{ csrf_token() }}'}, function (res) {
                if (res && res.message) {
                    toastr.info(res.message);
                }
                tables.get('table_command_rules');
            }).fail(function (xhr) {
                toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Error');
            });
        });
    </script>
@stop
