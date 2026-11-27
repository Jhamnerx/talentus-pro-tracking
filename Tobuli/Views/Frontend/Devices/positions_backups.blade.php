@extends('Frontend.Layouts.modal')

@section('title', trans('front.positions_backups'))

@section('body')
    @if (Session::has('success'))
        <div class="alert alert-success alert-dismissible">
            {!! Session::get('success') !!}
        </div>
    @endif

    <div id="positions_backups_table">
        <div class="form-group">
            <label for="positions_backups_date">{{ trans('backups.filter_by_date') }}</label>
            <input type="date" id="positions_backups_date" name="date" class="form-control" data-filter>
        </div>

        <div data-table>
            @include('Frontend.Devices.positions_backups_table')
        </div>
    </div>
@stop

@section('buttons')
    <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('global.close') }}</button>
@stop

@section('scripts')
    <script>
        tables.set_config('positions_backups_table', {
            url:'{{ route('admin.objects.positions_backups.table', $id) }}'
        });

        // Al cambiar la fecha se pide la primera página (tables.reload usa la URL base).
        // stopPropagation evita que el manejador delegado de tables.js haga una segunda
        // petición con currentUrl; data-filter se conserva para que la fecha se siga enviando.
        $('#positions_backups_date').off('change.positionsBackups').on('change.positionsBackups', function (e) {
            e.stopPropagation();
            tables.reload('positions_backups_table');
        });
    </script>
@stop