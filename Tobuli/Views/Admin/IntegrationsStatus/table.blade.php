@php
    // Semaforo -> etiqueta de bootstrap. Los umbrales vienen del servicio,
    // no se repiten aqui: si cambian, cambian en un solo sitio.
    $etiquetas = [
        'ok'      => ['default', trans('admin.status_ok')],
        'curso'   => ['info', trans('admin.status_curso')],
        'aviso'   => ['warning', trans('admin.status_aviso')],
        'critico' => ['danger', trans('admin.status_critico')],
    ];
@endphp

<div class="table-responsive">
    <table class="table table-list">
        <thead>
            <tr>
                {!! tableHeader(trans('validation.attributes.name')) !!}
                {!! tableHeader(trans('validation.attributes.plate_number')) !!}
                {!! tableHeader(trans('admin.integration')) !!}
                {!! tableHeader(trans('admin.pending_frames')) !!}
                {!! tableHeader(trans('admin.oldest_pending')) !!}
                {!! tableHeader(trans('front.last_update')) !!}
                {!! tableHeader(trans('front.status')) !!}
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                @php
                    [$clase, $texto] = $etiquetas[$item['status']] ?? ['default', $item['status']];
                @endphp
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['plate'] }}</td>
                    <td>{{ $item['integration'] }}</td>
                    <td>{{ number_format($item['pending']) }}</td>
                    {{-- La mas vieja sin enviar es el dato que dice si hay un
                         atasco real o solo cola momentanea. --}}
                    <td>{{ $item['oldest_at'] ?: '—' }}</td>
                    <td>{{ $item['last_at'] ?: '—' }}</td>
                    <td><span class="label label-{{ $clase }}">{{ $texto }}</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">{{ trans('front.nothing_found_request') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<p class="text-muted" style="margin: 6px 2px 0;">
    {{ trans('admin.integrations_thresholds', ['aviso' => $umbrales['aviso'], 'critico' => $umbrales['critico']]) }}
</p>
