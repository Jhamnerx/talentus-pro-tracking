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
                {!! tableHeader('USUARIO') !!}
                {!! tableHeader('JSON ENVIADO') !!}
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
                        <td style="max-width:160px">
                            @if ($item->user)
                                <span title="ID: {{ $item->user_id }}">{{ $item->user->email }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <button type="button" class="btn btn-xs btn-default btn-show-json"
                                data-json="{{ base64_encode($item->request) }}">
                                <i class="fa fa-code"></i> Ver
                            </button>
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
                    <td class="no-data" colspan="9">
                        {!! trans('admin.no_data') !!}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@include('admin::Layouts.partials.pagination', ['limitChoice' => 1])

{{-- Modal para ver JSON enviado --}}
<div class="modal fade" id="modal-json-log" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title"><i class="fa fa-code"></i> JSON Enviado al Webservice</h4>
            </div>
            <div class="modal-body" style="padding:0">
                <pre id="json-log-content"
                    style="max-height:500px;overflow:auto;margin:0;padding:15px;background:#1e1e1e;color:#d4d4d4;font-size:12px;border-radius:0"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-show-json');
        if (!btn) return;
        var raw = atob(btn.getAttribute('data-json'));
        var pretty;
        try {
            pretty = JSON.stringify(JSON.parse(raw), null, 2);
        } catch (err) {
            pretty = raw;
        }
        document.getElementById('json-log-content').textContent = pretty;
        document.getElementById('modal-json-log').classList.add('in');
        document.getElementById('modal-json-log').style.display = 'block';
        document.body.classList.add('modal-open');
    });

    document.addEventListener('click', function(e) {
        if (e.target.closest('[data-dismiss="modal"]') || e.target.id === 'modal-json-log') {
            var modal = document.getElementById('modal-json-log');
            if (modal) {
                modal.classList.remove('in');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        }
    });
</script>
