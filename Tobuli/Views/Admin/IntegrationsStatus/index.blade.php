@extends('admin::Layouts.default')

@section('content')
    <div class="panel panel-default" id="table_integrations_status">

        <div class="panel-heading">
            <div class="panel-title">
                {{ trans('admin.integrations_status') }}
                @if ($calculado)
                    <small class="text-muted">· {{ trans('front.last_update') }}: {{ $calculado }}</small>
                @endif
            </div>

            <div class="panel-form">
                <div class="form-group" style="width: 200px">
                    {!! Form::select('search_integration', ['' => trans('front.all')] + $integraciones, request('search_integration'), [
                            'class' => 'form-control',
                            'title' => trans('admin.integration'),
                            'data-filter' => 'true']) !!}
                </div>
                <div class="form-group">
                    {!! Form::select('search_failing', [0 => trans('front.all'), 1 => trans('admin.only_failing')], request('search_failing'), [
                            'class' => 'form-control',
                            'data-filter' => 'true']) !!}
                </div>
            </div>
        </div>

        {{-- Resumen por integracion: es lo primero que se mira, antes de
             bajar al detalle vehiculo a vehiculo. --}}
        <div class="panel-body" style="padding-bottom: 0;">
            <div class="table-responsive">
                <table class="table table-list">
                    <thead>
                        <tr>
                            {!! tableHeader(trans('admin.integration')) !!}
                            {!! tableHeader(trans('front.objects')) !!}
                            {!! tableHeader(trans('admin.status_ok')) !!}
                            {!! tableHeader(trans('admin.status_curso')) !!}
                            {!! tableHeader(trans('admin.status_aviso')) !!}
                            {!! tableHeader(trans('admin.status_critico')) !!}
                            {!! tableHeader(trans('admin.pending_frames')) !!}
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($resumen as $r)
                            <tr>
                                <td><strong>{{ $r['integration'] }}</strong></td>
                                <td>{{ $r['devices'] }}</td>
                                <td>{{ $r['ok'] }}</td>
                                <td>{{ $r['curso'] }}</td>
                                <td>
                                    @if ($r['aviso'])
                                        <span class="label label-warning">{{ $r['aviso'] }}</span>
                                    @else
                                        0
                                    @endif
                                </td>
                                <td>
                                    @if ($r['critico'])
                                        <span class="label label-danger">{{ $r['critico'] }}</span>
                                    @else
                                        0
                                    @endif
                                </td>
                                <td>{{ number_format($r['pending']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">{{ trans('admin.no_integrations_active') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($fallos)
                <p class="text-muted" style="margin: 6px 2px 12px;">
                    {{ trans('admin.failed_jobs') }}:
                    @foreach ($fallos as $clase => $n)
                        {{ $clase }} ({{ $n }})@if (!$loop->last), @endif
                    @endforeach
                </p>
            @endif
        </div>

        <div class="panel-body" data-table>
            @include('admin::IntegrationsStatus.table')
        </div>
    </div>
@stop

@section('javascript')
    <script>
        tables.set_config('table_integrations_status', {
            url: '{{ request()->fullUrl() }}'
        });
    </script>
@stop
