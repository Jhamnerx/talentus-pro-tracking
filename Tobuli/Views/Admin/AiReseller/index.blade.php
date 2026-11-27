@extends('Admin.Layouts.default')

@section('content')
    <style>
        .ab-kpis{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:4px}
        .ab-kpi{flex:1 1 160px;padding:12px 14px;border:1px solid #e3e7ee;border-radius:10px;background:#fff}
        .ab-kpi .v{font-size:22px;font-weight:700}
        .ab-kpi .l{color:#6b7280;font-size:12px}
        .ab-muted{color:#6b7280}
    </style>

    @if (isset($errors) && $errors->any())
        <div class="alert alert-danger">{!! implode('<br>', array_map('e', $errors->all())) !!}</div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><div class="panel-title">{{ $name }} · {{ date('m/Y') }}</div></div>
        <div class="panel-body">
            @if ($ownKey)
                <div class="alert alert-success">Tus usuarios usan <strong>tu clave</strong> ({{ $maskedKey }}). El consumo se te cobra a ti en Anthropic.</div>
            @elseif ($active)
                <div class="alert alert-info">Todavía no cargaste tu clave: tus usuarios usan la clave del proveedor de la plataforma. Cuando guardes la tuya, pasarán a usarla.</div>
            @else
                <div class="alert alert-warning">El asistente está apagado para tus usuarios: carga tu clave de la API para activarlo.</div>
            @endif

            <div class="ab-kpis">
                <div class="ab-kpi"><div class="v">USD {{ number_format($spent, 2) }}</div><div class="l">Consumo del mes con tu clave</div></div>
                <div class="ab-kpi"><div class="v">{{ $users->where('access', true)->count() }} / {{ $users->count() }}</div><div class="l">Usuarios con acceso al asistente</div></div>
                <div class="ab-kpi"><div class="v">{{ $users->sum('used') }}</div><div class="l">Preguntas del mes</div></div>
                @if ($mainSpent > 0)
                    <div class="ab-kpi"><div class="v">USD {{ number_format($mainSpent, 2) }}</div><div class="l">Consumo con la clave del proveedor</div></div>
                @endif
            </div>
            <p class="ab-muted" style="margin:6px 0 0">El acceso se da con el permiso "Asistente IA" de cada usuario (Usuarios &rarr; editar &rarr; Permisos).</p>
        </div>
    </div>

    {!! Form::open(['route' => 'admin.ai_reseller.save', 'method' => 'POST', 'autocomplete' => 'off']) !!}
    <div class="panel panel-default">
        <div class="panel-heading"><div class="panel-title">Tu clave y modelos</div></div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-8 form-group">
                    <label>Clave de la API de Anthropic</label>
                    <input type="password" name="api_key" class="form-control" autocomplete="new-password"
                           placeholder="{{ $ownKey ? 'Guardada ' . $maskedKey . ' · déjalo vacío para conservarla' : 'sk-ant-...' }}">
                    <p class="ab-muted" style="margin:4px 0 0">Se guarda cifrada y no se vuelve a mostrar. Se crea en console.anthropic.com &rarr; API Keys.</p>
                    @if ($ownKey)
                        <label style="font-weight:normal;margin-top:4px"><input type="checkbox" name="remove_api_key" value="1"> Borrar mi clave</label>
                    @endif
                </div>
            </div>
            <div class="row">
                <div class="col-sm-4 form-group">
                    <label>Modelo del chat</label>
                    <select name="model" class="form-control">
                        <option value="">El del proveedor</option>
                        @foreach ($models as $id => $label)
                            <option value="{{ $id }}" @if (($settings->model ?? '') === $id) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4 form-group">
                    <label>Modelo de resúmenes</label>
                    <select name="light_model" class="form-control">
                        <option value="">El del proveedor</option>
                        @foreach ($models as $id => $label)
                            <option value="{{ $id }}" @if (($settings->light_model ?? '') === $id) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4 form-group">
                    <label>Esfuerzo de razonamiento</label>
                    <select name="effort" class="form-control">
                        <option value="">El del proveedor</option>
                        @foreach (['low' => 'Bajo (más barato)', 'medium' => 'Medio', 'high' => 'Alto (más caro)'] as $id => $label)
                            <option value="{{ $id }}" @if (($settings->effort ?? '') === $id) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="ab-muted">Los modelos y el esfuerzo solo se aplican con tu clave. Costo aproximado por pregunta: Opus 5.5 ~USD 0,09 · Sonnet 5.5 ~USD 0,05 · Haiku 5.5 ~USD 0,003.</p>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><div class="panel-title">Cuotas de tus usuarios</div></div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-4 form-group">
                    <label>Preguntas al mes por usuario (por defecto)</label>
                    <input type="number" min="0" name="default_quota" class="form-control" value="{{ $settings->default_quota ?? '' }}"
                           placeholder="La del proveedor (0 = sin límite)">
                </div>
                <div class="col-sm-4 form-group">
                    <label>Tope de gasto mensual con tu clave (USD)</label>
                    <input type="number" min="0" step="0.01" name="monthly_budget_usd" class="form-control"
                           value="{{ $settings->monthly_budget_usd ?? '' }}" placeholder="0 = sin tope">
                </div>
            </div>
            <p class="ab-muted">Las cuotas y el tope se aplican cuando tus usuarios usan tu clave.</p>

            <table class="table table-condensed" style="max-width:760px">
                <thead><tr><th>Usuario</th><th>Acceso</th><th class="text-right">Usadas</th><th class="text-right">Límite actual</th><th style="width:160px">Límite propio</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>{{ $u->email }}</td>
                        <td>{!! $u->access ? 'Sí' : '<span class="ab-muted">No</span>' !!}</td>
                        <td class="text-right">{{ $u->used }}</td>
                        <td class="text-right">{{ $u->limit > 0 ? $u->limit : 'Sin límite' }}</td>
                        <td><input type="number" min="0" name="user_quotas[{{ $u->id }}]" class="form-control input-sm" value="{{ $u->quota }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">{{ trans('global.save') }}</button>
        </div>
    </div>
    {!! Form::close() !!}

    <div class="panel panel-default">
        <div class="panel-heading"><div class="panel-title">Consumo del mes con tu clave</div></div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-condensed">
                        <thead><tr><th>Función</th><th class="text-right">Llamadas</th><th class="text-right">USD</th></tr></thead>
                        <tbody>
                        @forelse ($byFeature as $row)
                            <tr><td>{{ $row->feature }}</td><td class="text-right">{{ $row->calls }}</td><td class="text-right">{{ number_format($row->cost, 3) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="ab-muted">Sin consumo todavía.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-condensed">
                        <thead><tr><th>Modelo</th><th class="text-right">Llamadas</th><th class="text-right">USD</th></tr></thead>
                        <tbody>
                        @forelse ($byModel as $row)
                            <tr><td><code>{{ $row->model }}</code></td><td class="text-right">{{ $row->calls }}</td><td class="text-right">{{ number_format($row->cost, 3) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="ab-muted">Sin consumo todavía.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@stop
