@extends('Admin.Layouts.default')

@section('styles')
    <style>
        .ws-card .panel-heading {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .ws-card .panel-heading .service-badge {
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
            vertical-align: middle;
            background: rgba(255, 255, 255, 0.88);
            color: #333;
            border: none;
        }

        #ws-log-container {
            display: none;
            margin-top: 20px;
        }

        #ws-log {
            background: #1a1a2e;
            color: #e0e0e0;
            border-radius: 6px;
            padding: 14px 16px;
            font-family: 'Courier New', monospace;
            font-size: 12.5px;
            min-height: 100px;
            max-height: 380px;
            overflow-y: auto;
            line-height: 1.7;
        }

        #ws-log .log-info {
            color: #8ecae6;
        }

        #ws-log .log-success {
            color: #95d5b2;
        }

        #ws-log .log-warn {
            color: #ffd166;
        }

        #ws-log .log-error {
            color: #ef8a8a;
        }

        .log-spinner {
            display: none;
            margin-left: 8px;
        }

        .ws-action-btn {
            margin-top: 4px;
        }

        .tab-pane {
            padding-top: 14px;
        }

        .form-group label {
            font-weight: 600;
        }

        .coord-row .form-group {
            margin-bottom: 8px;
        }

        /* ── Tarjetas igual altura ─────────────────────────── */
        @media (min-width: 992px) {
            .ws-cards-row {
                display: flex;
                align-items: stretch;
                flex-wrap: wrap;
                margin-bottom: 20px;
            }

            .ws-cards-row>.col-md-4 {
                display: flex;
                flex-direction: column;
            }

            .ws-cards-row>.col-md-4>.panel {
                flex: 1;
                display: flex;
                flex-direction: column;
                margin-bottom: 0;
            }

            .ws-cards-row>.col-md-4>.panel>.panel-body {
                flex: 1;
                display: flex;
                flex-direction: column;
            }

            .ws-cards-row .panel-body .tab-content {
                flex: 1;
                display: flex;
                flex-direction: column;
            }

            .ws-cards-row .panel-body .tab-pane.active {
                display: flex;
                flex-direction: column;
                flex: 1;
            }

            .ws-cards-row .ws-action-btn {
                margin-top: auto;
            }
        }
    </style>
@endsection

@section('content')
    <div class="page-header" style="margin-top:0">
        <h3><i class="fa fa-paper-plane-o" style="color:#5b8dee"></i> Webservices – Reenvío manual</h3>
        <small>Envía posiciones manualmente a los webservices externos. Las acciones son inmediatas (sin cola).</small>
    </div>

    <div class="row ws-cards-row">

        {{-- ═══════════════════ SUTRAN ═══════════════════ --}}
        <div class="col-md-4 ws-card">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <i class="fa fa-road"></i> SUTRAN (MTC)
                    <span class="label label-default service-badge">{{ $devicesSutran->count() }} unidades activas</span>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>Unidad <span class="text-danger">*</span></label>
                        <select id="sutran-device" class="form-control" data-live-search="true">
                            <option value="">-- Seleccionar --</option>
                            @foreach ($devicesSutran as $d)
                                <option value="{{ $d->id }}" data-plate="{{ $d->plate_number }}">
                                    {{ $d->name }} — {{ $d->plate_number }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="row coord-row">
                        <div class="col-xs-6">
                            <div class="form-group">
                                <label>Latitud <span class="text-danger">*</span></label>
                                <input type="number" id="sutran-lat" class="form-control" step="any"
                                    placeholder="-12.0464">
                            </div>
                        </div>
                        <div class="col-xs-6">
                            <div class="form-group">
                                <label>Longitud <span class="text-danger">*</span></label>
                                <input type="number" id="sutran-lng" class="form-control" step="any"
                                    placeholder="-77.0428">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fecha y hora GPS <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="sutran-datetime" class="form-control">
                    </div>

                    <button class="btn btn-primary btn-block ws-action-btn" id="sutran-btn">
                        <i class="fa fa-send"></i> Enviar trama
                        <i class="fa fa-spinner fa-spin log-spinner" id="sutran-spinner"></i>
                    </button>
                </div>
            </div>
        </div>

        {{-- ═══════════════════ OSINERGMIN ═══════════════════ --}}
        <div class="col-md-4 ws-card">
            <div class="panel panel-warning">
                <div class="panel-heading">
                    <i class="fa fa-bolt"></i> OSINERGMIN
                    <span class="label label-default service-badge">{{ $devicesOsinergmin->count() }} unidades
                        activas</span>
                </div>
                <div class="panel-body">

                    <ul class="nav nav-tabs" id="osin-tabs">
                        <li class="active"><a href="#osin-mantener" data-toggle="tab">Mantener</a></li>
                        <li><a href="#osin-rango" data-toggle="tab">Rango fijo</a></li>
                        <li><a href="#osin-historial" data-toggle="tab">Historial</a></li>
                    </ul>

                    <div class="tab-content">

                        {{-- Tab 1: Mantener transmisión --}}
                        <div class="tab-pane active" id="osin-mantener">
                            <div class="form-group">
                                <label>Unidad <span class="text-danger">*</span></label>
                                <select id="om-device" class="form-control" data-live-search="true">
                                    <option value="">-- Seleccionar --</option>
                                    @foreach ($devicesOsinergmin as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }} — {{ $d->plate_number }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row coord-row">
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Latitud</label>
                                        <input type="number" id="om-lat" class="form-control" step="any"
                                            placeholder="-12.0464">
                                    </div>
                                </div>
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Longitud</label>
                                        <input type="number" id="om-lng" class="form-control" step="any"
                                            placeholder="-77.0428">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Cantidad de tramas <span style="color:#666;font-weight:normal;font-size:12px">(1–120,
                                        c/1 min)</span></label>
                                <input type="number" id="om-cantidad" class="form-control" min="1" max="120"
                                    value="5">
                            </div>
                            <div id="om-status" class="text-center" style="font-size:12px;margin-bottom:8px;display:none">
                            </div>
                            <button class="btn btn-warning btn-block ws-action-btn" id="om-btn">
                                <i class="fa fa-play"></i> Iniciar transmisión
                            </button>
                        </div>

                        {{-- Tab 2: Posición fija en rango --}}
                        <div class="tab-pane" id="osin-rango">
                            <div class="form-group">
                                <label>Unidad <span class="text-danger">*</span></label>
                                <select id="or-device" class="form-control" data-live-search="true">
                                    <option value="">-- Seleccionar --</option>
                                    @foreach ($devicesOsinergmin as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }} — {{ $d->plate_number }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row coord-row">
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Latitud</label>
                                        <input type="number" id="or-lat" class="form-control" step="any"
                                            placeholder="-12.0464">
                                    </div>
                                </div>
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Longitud</label>
                                        <input type="number" id="or-lng" class="form-control" step="any"
                                            placeholder="-77.0428">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Desde</label>
                                        <input type="datetime-local" id="or-from" class="form-control">
                                    </div>
                                </div>
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Hasta <span class="text-muted">(máx 2h)</span></label>
                                        <input type="datetime-local" id="or-to" class="form-control">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Intervalo (minutos)</label>
                                <input type="number" id="or-interval" class="form-control" min="1"
                                    max="60" value="1">
                            </div>
                            <button class="btn btn-warning btn-block ws-action-btn" id="or-btn">
                                <i class="fa fa-send"></i> Enviar rango fijo
                                <i class="fa fa-spinner fa-spin log-spinner" id="or-spinner"></i>
                            </button>
                        </div>

                        {{-- Tab 3: Reenviar historial --}}
                        <div class="tab-pane" id="osin-historial">
                            <div class="form-group">
                                <label>Unidad <span class="text-danger">*</span></label>
                                <select id="oh-device" class="form-control" data-live-search="true">
                                    <option value="">-- Seleccionar --</option>
                                    @foreach ($devicesOsinergmin as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }} — {{ $d->plate_number }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Desde</label>
                                        <input type="datetime-local" id="oh-from" class="form-control">
                                    </div>
                                </div>
                                <div class="col-xs-6">
                                    <div class="form-group">
                                        <label>Hasta <span class="text-muted">(máx 2h)</span></label>
                                        <input type="datetime-local" id="oh-to" class="form-control">
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-warning btn-block ws-action-btn" id="oh-btn">
                                <i class="fa fa-history"></i> Reenviar historial
                                <i class="fa fa-spinner fa-spin log-spinner" id="oh-spinner"></i>
                            </button>
                        </div>

                    </div>{{-- /tab-content --}}
                </div>
            </div>
        </div>

        {{-- ═══════════════════ MININTER ═══════════════════ --}}
        <div class="col-md-4 ws-card">
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <i class="fa fa-shield"></i> MININTER
                    <span class="label label-default service-badge">{{ $devicesMininter->count() }} unidades
                        activas</span>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>Unidad <span class="text-danger">*</span></label>
                        <select id="mi-device" class="form-control" data-live-search="true">
                            <option value="">-- Seleccionar --</option>
                            @foreach ($devicesMininter as $d)
                                <option value="{{ $d->id }}">{{ $d->name }} — {{ $d->plate_number }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-xs-6">
                            <div class="form-group">
                                <label>Desde</label>
                                <input type="datetime-local" id="mi-from" class="form-control">
                            </div>
                        </div>
                        <div class="col-xs-6">
                            <div class="form-group">
                                <label>Hasta <span class="text-muted">(máx 31 días)</span></label>
                                <input type="datetime-local" id="mi-to" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info" style="font-size:12px;padding:8px 12px;margin-bottom:10px">
                        <i class="fa fa-info-circle"></i>
                        Carga el <strong>historial</strong> de posiciones del rango (hora Lima) a la tabla Mininter
                        como pendientes; el job las enviará automáticamente. Omite duplicados.
                    </div>
                    <button class="btn btn-danger btn-block ws-action-btn" id="mi-btn">
                        <i class="fa fa-history"></i> Reenviar a Mininter
                        <i class="fa fa-spinner fa-spin log-spinner" id="mi-spinner"></i>
                    </button>
                </div>
            </div>
        </div>

    </div>{{-- /row --}}

    {{-- ═══════════════════ LOG ═══════════════════ --}}
    <div id="ws-log-container">
        <div class="panel panel-default">
            <div class="panel-heading" style="background:#1a1a2e;color:#e0e0e0;border:none">
                <i class="fa fa-terminal"></i> <strong>Log de ejecución</strong>
                <button class="btn btn-xs btn-link pull-right" id="ws-log-clear" style="color:#aaa">
                    <i class="fa fa-trash"></i> Limpiar
                </button>
            </div>
            <div id="ws-log"></div>
        </div>
    </div>
@endsection

@section('javascript')
    <script>
        (function() {
            'use strict';

            /* ── Selectpicker con buscador ────────────────────── */
            $('.ws-card select.form-control').selectpicker({
                iconBase: '',
                tickIcon: '',
                noneSelectedText: '-- Seleccionar --',
            });

            var csrfToken = $('meta[name="csrf-token"]').attr('content');

            /* ── Helpers ──────────────────────────────────────────── */

            function showLog(logs) {
                var $container = $('#ws-log-container');
                var $log = $('#ws-log');
                $container.show();

                var html = '';
                $.each(logs, function(i, entry) {
                    var cls = 'log-' + (entry.level || 'info');
                    html += '<div class="' + cls + '">' + escHtml(entry.msg) + '</div>';
                });
                $log.append(html);
                $log.scrollTop($log[0].scrollHeight);
            }

            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            }

            function setLoading($btn, $spinner, on) {
                $btn.prop('disabled', on);
                $spinner.toggle(on);
            }

            function doPost(url, data, $btn, $spinner) {
                setLoading($btn, $spinner, true);
                data._token = csrfToken;

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: data,
                    headers: {
                        'Accept': 'application/json'
                    },
                    success: function(resp) {
                        showLog(resp.logs || []);
                    },
                    error: function(xhr) {
                        var logs = [];
                        if (xhr.status === 422) {
                            try {
                                var json = JSON.parse(xhr.responseText);
                                var errors = json.errors || {};
                                $.each(errors, function(field, messages) {
                                    $.each(messages, function(i, msg) {
                                        logs.push({
                                            level: 'error',
                                            msg: '\u26a0 ' + msg
                                        });
                                    });
                                });
                                if (!logs.length && json.message) {
                                    logs.push({
                                        level: 'error',
                                        msg: json.message
                                    });
                                }
                            } catch (e) {
                                logs.push({
                                    level: 'error',
                                    msg: '[Error 422] Datos inv\u00e1lidos'
                                });
                            }
                        } else {
                            var msg = '[Error HTTP ' + xhr.status + '] ';
                            try {
                                var json = JSON.parse(xhr.responseText);
                                msg += json.message || JSON.stringify(json);
                            } catch (e) {
                                msg += xhr.responseText.substring(0, 200);
                            }
                            logs.push({
                                level: 'error',
                                msg: msg
                            });
                        }
                        showLog(logs);
                    },
                    complete: function() {
                        setLoading($btn, $spinner, false);
                    }
                });
            }

            /* ── SUTRAN ────────────────────────────────────────────── */

            $('#sutran-btn').on('click', function() {
                doPost(
                    '{{ route('admin.webservices_reenvio.sutran') }}', {
                        device_id: $('#sutran-device').val(),
                        latitud: $('#sutran-lat').val(),
                        longitud: $('#sutran-lng').val(),
                        datetime: $('#sutran-datetime').val()
                    },
                    $(this),
                    $('#sutran-spinner')
                );
            });

            /* ── OSINERGMIN: Mantener (loop c/60s desde JS) ────────────── */

            var omState = {
                running: false,
                sendTimer: null,
                countdownTimer: null,
                current: 0,
                total: 0,
                secsToNext: 0
            };

            function omStop() {
                if (omState.sendTimer) clearInterval(omState.sendTimer);
                if (omState.countdownTimer) clearInterval(omState.countdownTimer);
                omState.running = false;
                omState.sendTimer = null;
                omState.countdownTimer = null;
                $('#om-btn').removeClass('btn-danger').addClass('btn-warning')
                    .prop('disabled', false)
                    .html('<i class="fa fa-play"></i> Iniciar transmisión');
                $('#om-status').hide();
            }

            function omSendOne() {
                if (!omState.running) return;
                omState.secsToNext = 60;
                $.ajax({
                    url: '{{ route('admin.webservices_reenvio.osin_mantener_trama') }}',
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json'
                    },
                    data: {
                        _token: csrfToken,
                        device_id: $('#om-device').val(),
                        latitud: $('#om-lat').val(),
                        longitud: $('#om-lng').val()
                    },
                    success: function(resp) {
                        omState.current++;
                        showLog([{
                            level: resp.success ? 'success' : 'warn',
                            msg: '[' + omState.current + '/' + omState.total + '] ' +
                                (resp.gpsDate || '') + ' → ' + (resp.status || 'OK')
                        }]);
                        if (omState.current >= omState.total) {
                            showLog([{
                                level: 'info',
                                msg: 'Transmisión completada (' + omState.total + ' tramas).'
                            }]);
                            omStop();
                        }
                    },
                    error: function(xhr) {
                        omState.current++;
                        var m = '[' + omState.current + '/' + omState.total + '] Error';
                        try {
                            m += ': ' + JSON.parse(xhr.responseText).error;
                        } catch (e) {
                            m += ' HTTP ' + xhr.status;
                        }
                        showLog([{
                            level: 'error',
                            msg: m
                        }]);
                        if (omState.current >= omState.total) omStop();
                    }
                });
            }

            $('#om-btn').on('click', function() {
                if (omState.running) {
                    showLog([{
                        level: 'warn',
                        msg: 'Transmisión detenida manualmente (' +
                            omState.current + '/' + omState.total + ' enviadas).'
                    }]);
                    omStop();
                    return;
                }

                if (!$('#om-device').val() || !$('#om-lat').val() || !$('#om-lng').val()) {
                    showLog([{
                        level: 'error',
                        msg: '⚠ Completa unidad, latitud y longitud.'
                    }]);
                    return;
                }

                omState.running = true;
                omState.current = 0;
                omState.total = Math.max(1, parseInt($('#om-cantidad').val()) || 5);
                omState.secsToNext = 0;

                $('#om-btn').removeClass('btn-warning').addClass('btn-danger')
                    .html('<i class="fa fa-stop"></i> Detener (0/' + omState.total + ')');
                $('#om-status').show().text('Enviando primera trama...');

                // Primera trama inmediatamente
                omSendOne();

                // Siguientes cada 60 s
                if (omState.total > 1) {
                    omState.sendTimer = setInterval(function() {
                        if (!omState.running || omState.current >= omState.total) {
                            omStop();
                            return;
                        }
                        omSendOne();
                    }, 60000);
                }

                // Contador de UI cada 1 s
                omState.countdownTimer = setInterval(function() {
                    if (!omState.running) return;
                    if (omState.secsToNext > 0) omState.secsToNext--;
                    if (omState.current < omState.total) {
                        var next = omState.current >= omState.total ? '' : ' – próxima en ' + omState
                            .secsToNext + 's';
                        $('#om-btn').html('<i class="fa fa-stop"></i> Detener (' +
                            omState.current + '/' + omState.total + ')' + next);
                        $('#om-status').text('Trama ' + omState.current + '/' + omState.total +
                            (omState.current < omState.total ? ' · próxima en ' + omState
                                .secsToNext + 's' : ''));
                    }
                }, 1000);
            });

            /* ── OSINERGMIN: Rango fijo (loop uno a uno c/1s) ─────────── */

            $('#or-btn').on('click', function() {
                var $btn = $(this);
                var $spinner = $('#or-spinner');

                if (!$('#or-device').val() || !$('#or-lat').val() || !$('#or-lng').val() ||
                    !$('#or-from').val() || !$('#or-to').val()) {
                    showLog([{
                        level: 'error',
                        msg: '⚠ Completa todos los campos del rango.'
                    }]);
                    return;
                }

                var intervalMins = Math.max(1, parseInt($('#or-interval').val()) || 1);

                // Calcular secuencia de datetimes (strings Lima-time)
                function addMins(s, m) {
                    var d = new Date(s);
                    d.setMinutes(d.getMinutes() + m);
                    var pad = function(n) {
                        return n < 10 ? '0' + n : '' + n;
                    };
                    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
                        'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                }

                var sequence = [];
                var cur = $('#or-from').val();
                var toV = $('#or-to').val();
                while (cur <= toV && sequence.length < 240) {
                    sequence.push(cur);
                    cur = addMins(cur, intervalMins);
                }

                if (!sequence.length) {
                    showLog([{
                        level: 'error',
                        msg: '⚠ El rango no genera ninguna trama.'
                    }]);
                    return;
                }

                showLog([{
                    level: 'info',
                    msg: sequence.length + ' trama(s) ' +
                        $('#or-from').val().slice(11, 16) + '–' + toV.slice(11, 16) +
                        ' (c/' + intervalMins + ' min). Enviando una a una...'
                }]);

                setLoading($btn, $spinner, true);
                var idx = 0,
                    ok = 0,
                    err = 0;

                function sendNext() {
                    if (idx >= sequence.length) {
                        showLog([{
                            level: ok === sequence.length ? 'success' : 'warn',
                            msg: 'Rango completado: CREATED=' + ok + ', ERROR=' + err
                        }]);
                        setLoading($btn, $spinner, false);
                        return;
                    }
                    var datetime = sequence[idx];
                    idx++;
                    $.ajax({
                        url: '{{ route('admin.webservices_reenvio.osin_mantener_trama') }}',
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json'
                        },
                        data: {
                            _token: csrfToken,
                            device_id: $('#or-device').val(),
                            latitud: $('#or-lat').val(),
                            longitud: $('#or-lng').val(),
                            datetime: datetime
                        },
                        success: function(resp) {
                            resp.success ? ok++ : err++;
                            showLog([{
                                level: resp.success ? 'success' : 'warn',
                                msg: '[' + idx + '/' + sequence.length + '] ' +
                                    (resp.gpsDate || datetime) + ' → ' + (resp.status ||
                                        'OK')
                            }]);
                            setTimeout(sendNext, 1000);
                        },
                        error: function(xhr) {
                            err++;
                            var m = '[' + idx + '/' + sequence.length + '] Error';
                            try {
                                m += ': ' + JSON.parse(xhr.responseText).error;
                            } catch (e) {
                                m += ' HTTP ' + xhr.status;
                            }
                            showLog([{
                                level: 'error',
                                msg: m
                            }]);
                            setTimeout(sendNext, 1000);
                        }
                    });
                }

                sendNext();
            });

            /* ── OSINERGMIN: Historial ──────────────────────────────── */

            $('#oh-btn').on('click', function() {
                doPost(
                    '{{ route('admin.webservices_reenvio.osin_historial') }}', {
                        device_id: $('#oh-device').val(),
                        from_datetime: $('#oh-from').val(),
                        to_datetime: $('#oh-to').val()
                    },
                    $(this),
                    $('#oh-spinner')
                );
            });

            /* ── MININTER ───────────────────────────────────────────── */

            $('#mi-btn').on('click', function() {
                doPost(
                    '{{ route('admin.webservices_reenvio.mininter') }}', {
                        device_id: $('#mi-device').val(),
                        from_datetime: $('#mi-from').val(),
                        to_datetime: $('#mi-to').val()
                    },
                    $(this),
                    $('#mi-spinner')
                );
            });

            /* ── Limpiar log ────────────────────────────────────────── */

            $('#ws-log-clear').on('click', function() {
                $('#ws-log').empty();
                $('#ws-log-container').hide();
            });

            /* ── Auto-cargar última posición al seleccionar unidad SUTRAN ── */

            $('#sutran-device').on('change', function() {
                var deviceId = $(this).val();
                if (!deviceId) return;
                var url = '{{ url('admin/webservices-reenvio/device') }}/' + encodeURIComponent(deviceId) +
                    '/last-position';
                $.get(url, function(resp) {
                    $('#sutran-lat').val(resp.lat);
                    $('#sutran-lng').val(resp.lng);
                    $('#sutran-datetime').val(resp.datetime);
                }).fail(function(xhr) {
                    var err = '';
                    try {
                        err = JSON.parse(xhr.responseText).error;
                    } catch (e) {
                        err = 'Error al cargar posición';
                    }
                    showLog([{
                        level: 'warn',
                        msg: 'Posición: ' + err
                    }]);
                });
            });

            /* ── Pre-fill datetime-local con hora local del usuario ─── */

            (function prefillNow() {
                $('#sutran-datetime').val('{{ $nowUserTz }}');
            })();

        })();
    </script>
@endsection
