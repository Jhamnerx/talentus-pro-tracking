@extends('front::Layouts.modal')
@section('modal_class', 'modal-lg')

@section('title')
    <i class="icon routes"></i> {{ trans('front.route_schedules') }}
@stop

@section('body')
    <div class="row" id="table_route_schedules">
        <input type="hidden" name="sorting[sort_by]" value="{{ $items->sorting['sort_by'] }}" data-filter>
        <input type="hidden" name="sorting[sort]" value="{{ $items->sorting['sort'] }}" data-filter>

        @if (Auth::User()->perm('routes', 'edit'))
            <div class="pull-right">
                <ul class="nav nav-tabs nav-icons">
                    <li>
                        <a href="javascript:"
                           data-url="{{ route('route_schedules.create') }}"
                           data-modal="route_schedules_create">
                            <i class="icon add" title="{{ trans('front.add_new') }}"></i>
                        </a>
                    </li>
                </ul>
            </div>
        @endif

        <div class="col-xs-4">
            <div class="form-group search">
                {!! Form::text('search_phrase', null, [
                    'class'       => 'form-control',
                    'placeholder' => trans('admin.search_it'),
                    'data-filter' => 'true',
                ]) !!}
            </div>
        </div>

        <div class="col-xs-12" data-table>
            @include('front::RouteSchedules.table')
        </div>
    </div>
@stop

<script>
    tables.set_config('table_route_schedules', {
        url: '{{ route("route_schedules.table") }}',
        destroy: '{{ route("route_schedules.destroy") }}'
    });

    // El modal de listado se queda abierto detras: al guardar en el de alta o
    // edicion hay que refrescar la tabla para que se vea el cambio.
    function route_schedules_create_modal_callback() {
        tables.get('table_route_schedules');
    }

    function route_schedules_edit_modal_callback() {
        tables.get('table_route_schedules');
    }
</script>

@section('buttons')
    <button type="button" class="btn btn-default" data-dismiss="modal">{!! trans('global.cancel') !!}</button>
@stop
