<div class="table_error"></div>
<div class="table-responsive">
    <table class="table table-list" data-toggle="multiCheckbox">
        <thead>
            <tr>
                @php
                    $multiActions = [];
                    // if (Auth::User()->perm('devices', 'remove')) {
                    //     $multiActions = array_merge($multiActions, ['do_destroy' => trans('admin.delete_selected')]);
                    // }
                @endphp

                @if ($multiActions)
                    {!! tableHeaderCheckall($multiActions) !!}
                @endif
                {!! tableHeader('SERVICIO WEB') !!}
                {!! tableHeaderSort($items->sorting, 'logs.date', 'FECHA/HORA ENVIADO') !!}
                {!! tableHeaderSort($items->sorting, 'logs.fecha_hora_posicion', 'FECHA/HORA POSICION') !!}
                {!! tableHeaderSort($items->sorting, 'logs.plate_number', 'PLACA') !!}
                {!! tableHeaderSort($items->sorting, 'logs.imei', 'IMEI') !!}
                {!! tableHeader('RESPUESTA') !!}
                {!! tableHeaderSort($items->sorting, 'logs.status', 'STATUS') !!}
            </tr>
        </thead>

        <tbody>
            @if (count($collection = $items->getCollection()))
                @foreach ($collection as $item)
                    <tr>
                        @if ($multiActions)
                            <td>
                                <div class="checkbox">
                                    <input type="checkbox" value="{!! $item->id !!}">
                                    <label></label>
                                </div>
                            </td>
                        @endif
                        <td>
                            <span>
                                {{ \App\Enums\WebServices::labels($item->service_name) }}
                            </span>
                        </td>
                        <td>
                            {{ $item->date }}
                        </td>
                        <td>
                            {{ $item->fecha_hora_posicion }}
                        </td>
                        <td>
                            {{ $item->plate_number }}
                        </td>
                        <td>
                            {{ $item->imei }}
                        </td>
                        <td>
                            {{ $item->response }}
                        </td>
                        <td>
                            <span class="label label-sm label-{{ $item->status == 'success' ? 'success' : 'danger' }}">

                                {{ $item->status }}

                            </span>
                        </td>

                        <td class="actions">
                            <div class="btn-group dropdown droparrow" data-position="fixed">
                                <i class="btn icon edit" data-toggle="dropdown" aria-haspopup="true"
                                    aria-expanded="true"></i>
                                <ul class="dropdown-menu">

                                    {{-- <li>
                                        <a href="javascript:" data-modal="devices_delete"
                                            data-url="{{ route('devices.do_destroy', ['id' => $item->id]) }}">
                                            {{ trans('global.delete') }}
                                        </a>
                                    </li> --}}

                                </ul>
                            </div>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr class="">
                    <td class="no-data" colspan="7">
                        {!! trans('admin.no_data') !!}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@include('admin::Layouts.partials.pagination', ['limitChoice' => 1])
