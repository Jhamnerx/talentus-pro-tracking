@extends('Admin.Layouts.default')

@section('content')
    @if (Session::has('messages'))
        <div class="alert alert-success">
            <ul>
                @foreach (Session::get('messages') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="panel panel-default" id="table_logs">

        <input type="hidden" name="sorting[sort_by]" value="{{ $items->sorting['sort_by'] }}" data-filter>
        <input type="hidden" name="sorting[sort]" value="{{ $items->sorting['sort'] }}" data-filter>

        <div class="panel-heading">
            <ul class="nav nav-tabs nav-icons pull-right">

                @if (config('addon.devices_bulk_delete') && Auth::User()->isAdmin())
                    <li role="presentation" class="">
                        <a href="javascript:" type="button" data-modal="logs_bulk_delete"
                            data-url="{{ route('admin.objects.bulk_delete') }}">
                            <i class="fa fa-trash" title="Bulk delete"></i>
                        </a>
                    </li>
                @endif
            </ul>

            <div class="panel-title"><i class="icon device"></i> Web Service Logs</div>

            <div class="panel-form">
                <div class="form-group search">
                    {!! Form::text('search_phrase', null, [
                        'class' => 'form-control',
                        'placeholder' => trans('admin.search_it'),
                        'data-filter' => 'true',
                    ]) !!}
                </div>
            </div>
        </div>

        <div class="panel-body" data-table>
            @include('Admin.' . ucfirst($section) . '.logs.table')
        </div>
    </div>
@stop

@section('javascript')
    <script>
        tables.set_config('table_logs', {
            url: '{{ route("admin.{$section}.logs") }}',
            do_destroy: {
                url: '{{ route('admin.objects.do_destroy') }}',
                modal: 'logs_delete',
                method: 'GET'
            },
        });


        function logs_delete_modal_callback() {
            tables.get('table_logs');
        }

        $(document).on('bulk_delete_object', function(e, res) {
            $('#objects_bulk_delete .alert-success').css('display', 'block').html(res.content);
        });
    </script>
@stop
