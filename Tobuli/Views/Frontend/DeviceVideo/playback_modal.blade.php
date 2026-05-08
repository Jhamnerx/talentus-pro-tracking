@extends('Frontend.Layouts.modal_full')

@section('title')
    <i class="icon video"></i> {{ $device->name }} &mdash; Reproducción
@stop

@section('modal_class')
    modal-playback
@stop

@section('buttons')
@stop

@section('body')

    <style>
        .modal-playback .modal-dialog {
            max-width: 1400px;
        }

        .modal-playback .modal-body {
            padding: 0;
            overflow: hidden;
        }

        /* Layout principal */
        .pb-layout {
            display: flex;
            height: 82vh;
            min-height: 480px;
            overflow: hidden;
        }

        /* Sidebar izquierdo */
        .pb-sidebar {
            width: 220px;
            min-width: 200px;
            background: #1a1a2e;
            color: #eee;
            padding: 12px 10px;
            overflow-y: auto;
            flex-shrink: 0;
            border-right: 1px solid #2e2e50;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .pb-sidebar label {
            font-size: .74rem;
            color: #aaa;
            display: block;
            margin-bottom: 2px;
        }

        .pb-sidebar .form-control {
            background: #12122a;
            border: 1px solid #3a3a60;
            color: #eee;
            font-size: .78rem;
            padding: 4px 6px;
            border-radius: 4px;
            width: 100%;
        }

        .pb-sidebar .form-control:focus {
            outline: none;
            border-color: #5555aa;
        }

        .pb-sb-group {
            display: flex;
            flex-direction: column;
        }

        .pb-sb-title {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .05em;
            color: #7878cc;
            text-transform: uppercase;
            margin: 6px 0 4px;
        }

        .pb-sb-channels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
        }

        .pb-sb-ch-label {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: .78rem;
            cursor: pointer;
        }

        .pb-btn {
            padding: 6px 0;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: .78rem;
            font-weight: 700;
            width: 100%;
            margin-top: 4px;
        }

        .pb-btn:disabled {
            opacity: .4;
            cursor: default;
        }

        .pb-btn-search {
            background: #2980b9;
            color: #fff;
        }

        .pb-btn-all {
            background: #27ae60;
            color: #fff;
        }

        .pb-btn-stop {
            background: #c0392b;
            color: #fff;
        }

        .pb-status-msg {
            font-size: .72rem;
            color: #e67e22;
            min-height: 18px;
            word-break: break-word;
        }

        /* Panel central */
        .pb-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #0e0e1e;
        }

        /* Grid de videos */
        .pb-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 6px;
            flex: 1;
            overflow: hidden;
        }

        .pb-card {
            border: 1px solid #2a2a4e;
            border-radius: 5px;
            overflow: hidden;
            background: #111;
            display: flex;
            flex-direction: column;
        }

        .pb-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 8px;
            background: #1a1a2e;
            color: #eee;
            font-size: .75rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .pb-badge {
            padding: 1px 7px;
            border-radius: 10px;
            font-size: .68rem;
            background: #444;
            color: #fff;
        }

        .pb-player-wrap {
            position: relative;
            background: #000;
            flex: 1;
            min-height: 0;
        }

        .pb-video {
            width: 100%;
            height: 100%;
            display: block;
            background: #000;
            object-fit: contain;
            min-height: 120px;
            max-height: 220px;
        }

        .pb-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #aaa;
            font-size: .78rem;
            gap: 6px;
        }

        .pb-overlay.hidden {
            display: none;
        }

        .pb-spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #444;
            border-top-color: #fff;
            border-radius: 50%;
            animation: pb-spin .8s linear infinite;
        }

        @keyframes pb-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .pb-card-footer {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 4px 6px;
            background: #1a1a2e;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .pb-btn-sm {
            padding: 2px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: .72rem;
            font-weight: 600;
        }

        .pb-btn-sm:disabled {
            opacity: .4;
            cursor: default;
        }

        .pb-btn-play {
            background: #27ae60;
            color: #fff;
        }

        .pb-btn-stop2 {
            background: #c0392b;
            color: #fff;
        }

        .pb-err {
            color: #e74c3c;
            font-size: .68rem;
            flex: 1;
            display: none;
        }

        /* Timeline */
        .pb-timeline-wrap {
            background: #12122a;
            border-top: 1px solid #2a2a4e;
            padding: 6px 8px;
            flex-shrink: 0;
            overflow-x: auto;
        }

        .pb-tl-title {
            font-size: .70rem;
            color: #7878cc;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .pb-tl-hours {
            display: flex;
            position: relative;
            height: 14px;
            margin-left: 52px;
        }

        .pb-tl-hour-label {
            position: absolute;
            font-size: .62rem;
            color: #666;
            transform: translateX(-50%);
            white-space: nowrap;
        }

        .pb-tl-row {
            display: flex;
            align-items: center;
            height: 18px;
            margin-bottom: 3px;
        }

        .pb-tl-ch-label {
            width: 52px;
            font-size: .70rem;
            color: #aaa;
            flex-shrink: 0;
        }

        .pb-tl-bar-wrap {
            flex: 1;
            position: relative;
            height: 12px;
            background: #1e1e3a;
            border-radius: 3px;
            cursor: crosshair;
            min-width: 400px;
        }

        .pb-tl-segment {
            position: absolute;
            height: 100%;
            border-radius: 2px;
            cursor: pointer;
            transition: opacity .15s;
        }

        .pb-tl-segment:hover {
            opacity: .75;
        }

        .pb-tl-type-1 {
            background: #2980b9;
        }

        .pb-tl-type-2 {
            background: #e74c3c;
        }

        .pb-tl-type-3 {
            background: #27ae60;
        }

        .pb-tl-type-other {
            background: #8e44ad;
        }

        .pb-tl-cursor {
            position: absolute;
            top: -2px;
            width: 2px;
            height: calc(100% + 4px);
            background: #f39c12;
            pointer-events: none;
            display: none;
        }

        .pb-tl-empty {
            font-size: .72rem;
            color: #555;
            padding: 4px 0;
            text-align: center;
        }

        .pb-tl-legend {
            display: flex;
            gap: 12px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .pb-tl-legend-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: .65rem;
            color: #888;
        }

        .pb-tl-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }

        /* Panel mapa */
        .pb-map-panel {
            width: 280px;
            min-width: 200px;
            background: #0e0e1e;
            border-left: 1px solid #2a2a4e;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .pb-map-title {
            padding: 6px 10px;
            background: #1a1a2e;
            color: #7878cc;
            font-size: .70rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid #2a2a4e;
            flex-shrink: 0;
        }

        #pb-map {
            flex: 1;
            min-height: 0;
        }

        .pb-map-info {
            padding: 4px 10px;
            font-size: .68rem;
            color: #666;
            background: #12122a;
            flex-shrink: 0;
            min-height: 22px;
        }
    </style>

    <div class="pb-layout">

        {{-- Sidebar izquierdo: formulario de búsqueda --}}
        <div class="pb-sidebar">
            <div class="pb-sb-title">Búsqueda</div>

            <div class="pb-sb-group">
                <label>Fecha inicio</label>
                <input type="date" id="pb-date-start" class="form-control" value="{{ date('Y-m-d') }}">
            </div>

            <div class="pb-sb-group">
                <label>Hora inicio</label>
                <input type="time" id="pb-time-start" class="form-control" value="00:00">
            </div>

            <div class="pb-sb-group">
                <label>Fecha fin</label>
                <input type="date" id="pb-date-end" class="form-control" value="{{ date('Y-m-d') }}">
            </div>

            <div class="pb-sb-group">
                <label>Hora fin</label>
                <input type="time" id="pb-time-end" class="form-control" value="23:59">
            </div>

            <div class="pb-sb-group">
                <div class="pb-sb-title" style="margin-top:4px">Canales</div>
                <div class="pb-sb-channels">
                    @for ($ch = 1; $ch <= 4; $ch++)
                        <label class="pb-sb-ch-label">
                            <input type="checkbox" class="pb-ch-check" value="{{ $ch }}" checked>
                            CH{{ $ch }}
                        </label>
                    @endfor
                </div>
            </div>

            <div class="pb-sb-group">
                <label>Tipo grabación</label>
                <select id="pb-file-type" class="form-control">
                    <option value="0">Todos</option>
                    <option value="1" selected>Normal</option>
                    <option value="2">Alarma</option>
                    <option value="3">Manual</option>
                </select>
            </div>

            <div class="pb-sb-group">
                <label>Almacenamiento</label>
                <select id="pb-storage" class="form-control">
                    <option value="">Auto</option>
                    <option value="sd1">SD 1</option>
                    <option value="hd1">HDD 1</option>
                    <option value="sd1;hd1">SD + HDD</option>
                </select>
            </div>

            <div class="pb-sb-group">
                <label>Arroyo (stream)</label>
                <select id="pb-stream" class="form-control">
                    <option value="1" selected>Principal</option>
                    <option value="0">Secundario</option>
                </select>
            </div>

            <button id="pb-btn-search" class="pb-btn pb-btn-search">
                &#128269; Buscar archivos
            </button>

            <button id="pb-btn-play-all" class="pb-btn pb-btn-all" disabled>
                &#9654; Reproducir selección
            </button>

            <button id="pb-btn-stop-all" class="pb-btn pb-btn-stop" disabled>
                &#9632; Detener todo
            </button>

            <div class="pb-status-msg" id="pb-status"></div>
        </div>

        {{-- Panel central: videos + timeline --}}
        <div class="pb-main">

            <div class="pb-grid">
                @for ($ch = 1; $ch <= 4; $ch++)
                    <div class="pb-card" data-ch="{{ $ch }}" id="pb-card-{{ $ch }}">
                        <div class="pb-card-header">
                            <span>Canal {{ $ch }}</span>
                            <span class="pb-badge" id="pb-badge-{{ $ch }}">Parado</span>
                        </div>
                        <div class="pb-player-wrap">
                            <video class="pb-video" id="pb-video-{{ $ch }}" controls muted playsinline></video>
                            <div class="pb-overlay" id="pb-overlay-{{ $ch }}">
                                <span>&mdash; inactivo &mdash;</span>
                            </div>
                        </div>
                        <div class="pb-card-footer">
                            <button class="pb-btn-sm pb-btn-play" id="pb-play-{{ $ch }}" disabled>
                                &#9654; Play
                            </button>
                            <button class="pb-btn-sm pb-btn-stop2" id="pb-pstop-{{ $ch }}" disabled>
                                &#9632; Stop
                            </button>
                            <span class="pb-err" id="pb-err-{{ $ch }}"></span>
                        </div>
                    </div>
                @endfor
            </div>

            {{-- Timeline --}}
            <div class="pb-timeline-wrap">
                <div class="pb-tl-title">Línea de tiempo</div>
                <div id="pb-tl-container">
                    <div class="pb-tl-empty">Realiza una búsqueda para ver la línea de tiempo</div>
                </div>
                <div class="pb-tl-legend">
                    <div class="pb-tl-legend-item">
                        <div class="pb-tl-legend-dot pb-tl-type-1"></div>Normal
                    </div>
                    <div class="pb-tl-legend-item">
                        <div class="pb-tl-legend-dot pb-tl-type-2"></div>Alarma
                    </div>
                    <div class="pb-tl-legend-item">
                        <div class="pb-tl-legend-dot pb-tl-type-3"></div>Manual
                    </div>
                    <div class="pb-tl-legend-item">
                        <div class="pb-tl-legend-dot pb-tl-type-other"></div>Otro
                    </div>
                </div>
            </div>

        </div>

        {{-- Panel derecho: mapa --}}
        <div class="pb-map-panel">
            <div class="pb-map-title">&#128205; Recorrido GPS</div>
            <div id="pb-map"></div>
            <div class="pb-map-info" id="pb-map-info">Busca archivos para cargar el recorrido</div>
        </div>

    </div>

    {{-- hls.js --}}
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.4.14/dist/hls.min.js"></script>

    <script>
        (function() {
            'use strict';

            var deviceId = {{ $device->id }};
            var _csrfToken = null;

            /* ------------------------------------------------------------------ */
            /* Helpers HTTP                                                         */
            /* ------------------------------------------------------------------ */
            function getCsrf() {
                if (_csrfToken) return _csrfToken;
                var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                _csrfToken = m ? decodeURIComponent(m[1]) : '';
                return _csrfToken;
            }

            function apiPost(url, data) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': getCsrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                }).then(function(r) {
                    return r.json();
                });
            }

            function apiGet(url) {
                return fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': getCsrf()
                    }
                }).then(function(r) {
                    return r.json();
                });
            }

            /* ------------------------------------------------------------------ */
            /* Estado                                                               */
            /* ------------------------------------------------------------------ */
            var hlsInstances = {}; // ch -> Hls instance
            var currentFiles = []; // archivos del último resultado de búsqueda
            var selectedSegment = null; // segmento seleccionado en la timeline
            var pollTimer = null;
            var searchSession = null;
            var map = null;
            var routeLayer = null;
            var posMarker = null;

            /* ------------------------------------------------------------------ */
            /* UI helpers                                                           */
            /* ------------------------------------------------------------------ */
            function status(msg, color) {
                var el = document.getElementById('pb-status');
                if (el) {
                    el.textContent = msg;
                    el.style.color = color || '#e67e22';
                }
            }

            function setBadge(ch, txt, bg) {
                var el = document.getElementById('pb-badge-' + ch);
                if (!el) return;
                el.textContent = txt;
                el.style.background = bg || '#444';
            }

            function showOverlay(ch, msg) {
                var ov = document.getElementById('pb-overlay-' + ch);
                if (!ov) return;
                ov.classList.remove('hidden');
                ov.innerHTML = msg ?
                    '<div class="pb-spinner"></div><span>' + msg + '</span>' :
                    '<span>&mdash; inactivo &mdash;</span>';
            }

            function hideOverlay(ch) {
                var ov = document.getElementById('pb-overlay-' + ch);
                if (ov) ov.classList.add('hidden');
            }

            function setPlayBtns(ch, enabled) {
                var p = document.getElementById('pb-play-' + ch);
                var s = document.getElementById('pb-pstop-' + ch);
                if (p) p.disabled = !enabled;
                if (s) s.disabled = !enabled;
            }

            function showErr(ch, msg) {
                var el = document.getElementById('pb-err-' + ch);
                if (el) {
                    el.textContent = msg || '';
                    el.style.display = msg ? 'block' : 'none';
                }
            }

            /* ------------------------------------------------------------------ */
            /* Formulario: obtener parámetros                                       */
            /* ------------------------------------------------------------------ */
            function getSearchParams() {
                var ds = document.getElementById('pb-date-start').value;
                var ts = document.getElementById('pb-time-start').value;
                var de = document.getElementById('pb-date-end').value;
                var te = document.getElementById('pb-time-end').value;

                if (!ds || !de) return null;

                var checks = document.querySelectorAll('.pb-ch-check:checked');
                var channels = Array.from(checks).map(function(c) {
                    return c.value;
                }).join(';');
                if (!channels) channels = '1;2;3;4';

                return {
                    startTime: ds + ' ' + (ts || '00:00') + ':00',
                    endTime: de + ' ' + (te || '23:59') + ':59',
                    channels: channels,
                    fileType: document.getElementById('pb-file-type').value,
                    storage: document.getElementById('pb-storage').value || undefined,
                    stream: document.getElementById('pb-stream').value
                };
            }

            /* ------------------------------------------------------------------ */
            /* Búsqueda de archivos                                                 */
            /* ------------------------------------------------------------------ */
            document.getElementById('pb-btn-search').addEventListener('click', function() {
                var params = getSearchParams();
                if (!params) {
                    status('Completa la fecha de inicio y fin', '#e74c3c');
                    return;
                }

                status('Buscando archivos en el dispositivo…');
                document.getElementById('pb-btn-search').disabled = true;
                document.getElementById('pb-btn-play-all').disabled = true;
                currentFiles = [];
                selectedSegment = null;
                renderTimeline([]);

                apiPost('/device_video/' + deviceId + '/playback/search', params)
                    .then(function(res) {
                        if (res.error) {
                            status('Error: ' + res.error, '#e74c3c');
                            document.getElementById('pb-btn-search').disabled = false;
                            return;
                        }
                        searchSession = res.session;
                        status('Esperando resultados del dispositivo…');
                        startPolling(searchSession, params);
                    })
                    .catch(function() {
                        status('Error de red al buscar archivos', '#e74c3c');
                        document.getElementById('pb-btn-search').disabled = false;
                    });
            });

            function startPolling(session, searchParams) {
                if (pollTimer) clearInterval(pollTimer);
                var attempts = 0;
                var maxAttempts = 30; // 30 × 1s = 30 s

                pollTimer = setInterval(function() {
                    attempts++;
                    apiGet('/device_video/' + deviceId + '/playback/files/' + encodeURIComponent(session))
                        .then(function(res) {
                            if (res.files && res.files.length > 0) {
                                // Acumulamos
                                res.files.forEach(function(f) {
                                    try {
                                        var obj = typeof f === 'string' ? JSON.parse(f) : f;
                                        currentFiles.push(obj);
                                    } catch (e) {
                                        /* ignorar */ }
                                });
                                renderTimeline(currentFiles);
                                status('Encontrados ' + currentFiles.length + ' archivos');
                            }

                            if (attempts >= maxAttempts) {
                                clearInterval(pollTimer);
                                pollTimer = null;
                                document.getElementById('pb-btn-search').disabled = false;
                                if (currentFiles.length === 0) {
                                    status('No se encontraron archivos para ese período', '#aaa');
                                } else {
                                    status(currentFiles.length +
                                        ' archivos disponibles. Selecciona uno en la línea de tiempo.',
                                        '#27ae60');
                                    document.getElementById('pb-btn-play-all').disabled = false;
                                    loadRoute(searchParams.startTime, searchParams.endTime);
                                }
                            }
                        })
                        .catch(function() {
                            /* ignorar errores de polling */ });
                }, 1000);
            }

            /* ------------------------------------------------------------------ */
            /* Timeline                                                             */
            /* ------------------------------------------------------------------ */
            function parseTime(str) {
                // Formato: "YYYY-MM-DD HH:MM:SS"
                if (!str) return 0;
                var d = new Date(str.replace(' ', 'T'));
                return isNaN(d) ? 0 : d.getTime();
            }

            function renderTimeline(files) {
                var container = document.getElementById('pb-tl-container');
                if (!container) return;

                if (!files || files.length === 0) {
                    container.innerHTML = '<div class="pb-tl-empty">No se encontraron archivos</div>';
                    return;
                }

                // Determinar rango del eje X (fecha seleccionada, 0-24h del primer día)
                var ds = document.getElementById('pb-date-start').value;
                var ts = document.getElementById('pb-time-start').value || '00:00';
                var de = document.getElementById('pb-date-end').value;
                var te = document.getElementById('pb-time-end').value || '23:59';
                var rangeStart = parseTime(ds + ' ' + ts + ':00');
                var rangeEnd = parseTime(de + ' ' + te + ':59');
                var rangeMs = rangeEnd - rangeStart;
                if (rangeMs <= 0) rangeMs = 86400000;

                // Marcas de hora
                var hoursHtml = '';
                var totalHours = Math.ceil(rangeMs / 3600000);
                for (var h = 0; h <= totalHours; h++) {
                    var pct = (h / totalHours) * 100;
                    var absH = new Date(rangeStart + h * 3600000);
                    var label = absH.getHours().toString().padStart(2, '0') + ':00';
                    hoursHtml += '<span class="pb-tl-hour-label" style="left:' + pct + '%">' + label + '</span>';
                }

                // Colores por canal
                var chColors = {
                    '1': '#2980b9',
                    '2': '#16a085',
                    '3': '#8e44ad',
                    '4': '#d35400'
                };

                // Agrupar por canal
                var byChannel = {};
                files.forEach(function(f) {
                    var ch = String(f.chl || f.ch || f.channel || '1');
                    if (!byChannel[ch]) byChannel[ch] = [];
                    byChannel[ch].push(f);
                });

                var rowsHtml = '';
                Object.keys(byChannel).sort().forEach(function(ch) {
                    var segs = byChannel[ch];
                    var barsHtml = '';

                    segs.forEach(function(seg, idx) {
                        var st = parseTime(seg.st || seg.startTime || seg.start_time);
                        var et = parseTime(seg.et || seg.endTime || seg.end_time);
                        if (!st || !et || et <= st) return;

                        var left = Math.max(0, ((st - rangeStart) / rangeMs) * 100);
                        var width = Math.min(100 - left, ((et - st) / rangeMs) * 100);
                        if (width < 0.2) width = 0.2;

                        var ft = String(seg.ft || seg.fileType || '1');
                        var typeClass = {
                            '1': 'pb-tl-type-1',
                            '2': 'pb-tl-type-2',
                            '3': 'pb-tl-type-3'
                        } [ft] || 'pb-tl-type-other';
                        var title = 'CH' + ch + ' | ' + (seg.st || '') + ' → ' + (seg.et || '') +
                            ' | Tipo: ' + ft;

                        barsHtml += '<div class="pb-tl-segment ' + typeClass + '"' +
                            ' style="left:' + left + '%;width:' + width + '%"' +
                            ' title="' + title + '"' +
                            ' data-ch="' + ch + '" data-idx="' + idx + '"' +
                            ' data-seg=\'' + JSON.stringify(seg).replace(/'/g, '&apos;') + '\'' +
                            '></div>';
                    });

                    rowsHtml += '<div class="pb-tl-row">' +
                        '<span class="pb-tl-ch-label">Canal ' + ch + '</span>' +
                        '<div class="pb-tl-bar-wrap" data-ch="' + ch + '">' +
                        barsHtml +
                        '<div class="pb-tl-cursor" id="pb-tl-cursor-' + ch + '"></div>' +
                        '</div>' +
                        '</div>';
                });

                container.innerHTML =
                    '<div class="pb-tl-hours" style="margin-left:52px;position:relative;height:16px;">' + hoursHtml +
                    '</div>' +
                    rowsHtml;

                // Eventos de click en segmentos
                container.querySelectorAll('.pb-tl-segment').forEach(function(el) {
                    el.addEventListener('click', function() {
                        var seg = JSON.parse(this.getAttribute('data-seg').replace(/&apos;/g, "'"));
                        var ch = parseInt(this.getAttribute('data-ch'), 10);
                        selectSegment(seg, ch);
                        // Resaltar
                        container.querySelectorAll('.pb-tl-segment').forEach(function(s) {
                            s.style.outline = '';
                        });
                        this.style.outline = '2px solid #f39c12';
                    });
                });
            }

            function selectSegment(seg, ch) {
                selectedSegment = {
                    seg: seg,
                    ch: ch
                };
                var info = 'CH' + ch + ': ' + (seg.st || '') + ' → ' + (seg.et || '');
                status('Seleccionado: ' + info + ' — haz clic en ▶ Play', '#27ae60');
                // Habilitar el play del canal correspondiente
                setPlayBtns(ch, true);
                document.getElementById('pb-btn-play-all').disabled = false;
            }

            /* ------------------------------------------------------------------ */
            /* Reproducción                                                         */
            /* ------------------------------------------------------------------ */
            function startChannelPlayback(ch, seg) {
                showOverlay(ch, 'Iniciando reproducción…');
                setBadge(ch, 'Cargando', '#e67e22');
                showErr(ch, '');

                var body = {
                    channel: ch,
                    startTime: seg.st || seg.startTime || '',
                    endTime: seg.et || seg.endTime || '',
                };
                if (seg.fn) body.fileName = seg.fn;
                if (seg.si !== undefined) body.stream = String(seg.si);

                apiPost('/device_video/' + deviceId + '/playback/start', body)
                    .then(function(res) {
                        if (res.error) {
                            showOverlay(ch, '');
                            showErr(ch, res.error);
                            setBadge(ch, 'Error', '#c0392b');
                            return;
                        }
                        loadHls(ch, res.hls_url, 10000);
                        document.getElementById('pb-pstop-' + ch).disabled = false;
                    })
                    .catch(function() {
                        showErr(ch, 'Error de red');
                        setBadge(ch, 'Error', '#c0392b');
                        showOverlay(ch, '');
                    });
            }

            function stopChannelPlayback(ch) {
                if (hlsInstances[ch]) {
                    hlsInstances[ch].destroy();
                    delete hlsInstances[ch];
                }
                var v = document.getElementById('pb-video-' + ch);
                if (v) {
                    v.pause();
                    v.removeAttribute('src');
                    v.load();
                }

                apiPost('/device_video/' + deviceId + '/playback/stop', {
                    channel: ch
                });

                setBadge(ch, 'Parado', '#444');
                showOverlay(ch, '');
                setPlayBtns(ch, false);
                showErr(ch, '');
            }

            function loadHls(ch, url, delayMs) {
                var video = document.getElementById('pb-video-' + ch);
                if (!video) return;

                if (hlsInstances[ch]) {
                    hlsInstances[ch].destroy();
                    delete hlsInstances[ch];
                }

                if (!Hls.isSupported()) {
                    if (video.canPlayType('application/vnd.apple.mpegurl')) {
                        video.src = url;
                        video.play().catch(function() {});
                    }
                    return;
                }

                var hls = new Hls({
                    liveSyncDurationCount: 3,
                    liveMaxLatencyDurationCount: 10,
                    manifestLoadingTimeOut: 30000,
                    manifestLoadingMaxRetry: 10,
                    manifestLoadingRetryDelay: delayMs || 2000,
                    levelLoadingTimeOut: 30000,
                    levelLoadingMaxRetry: 10,
                    fragLoadingTimeOut: 30000,
                    fragLoadingMaxRetry: 6,
                    startFragPrefetch: true,
                });

                hlsInstances[ch] = hls;
                hls.loadSource(url);
                hls.attachMedia(video);

                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    video.play().catch(function() {});
                });

                video.addEventListener('playing', function onPlaying() {
                    hideOverlay(ch);
                    setBadge(ch, 'Reproduciendo', '#27ae60');
                    video.removeEventListener('playing', onPlaying);
                }, {
                    once: true
                });

                hls.on(Hls.Events.ERROR, function(evt, data) {
                    if (data.fatal) {
                        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                            hls.startLoad();
                        } else {
                            showErr(ch, 'Error de stream. Reintentando…');
                            setTimeout(function() {
                                if (hlsInstances[ch] === hls) {
                                    loadHls(ch, url, 5000);
                                }
                            }, 5000);
                        }
                    }
                });
            }

            /* ------------------------------------------------------------------ */
            /* Botones de playback por canal                                        */
            /* ------------------------------------------------------------------ */
            @for ($ch = 1; $ch <= 4; $ch++)
                document.getElementById('pb-play-{{ $ch }}').addEventListener('click', function() {
                    var ch = {{ $ch }};
                    if (selectedSegment && selectedSegment.ch === ch) {
                        startChannelPlayback(ch, selectedSegment.seg);
                    } else if (selectedSegment) {
                        startChannelPlayback(ch, selectedSegment.seg);
                    } else {
                        status('Selecciona un segmento en la línea de tiempo primero', '#e74c3c');
                    }
                });

                document.getElementById('pb-pstop-{{ $ch }}').addEventListener('click', function() {
                    stopChannelPlayback({{ $ch }});
                });
            @endfor

            /* ------------------------------------------------------------------ */
            /* Botones globales                                                     */
            /* ------------------------------------------------------------------ */
            document.getElementById('pb-btn-play-all').addEventListener('click', function() {
                if (!selectedSegment) {
                    status('Selecciona un segmento en la línea de tiempo primero', '#e74c3c');
                    return;
                }
                var activeChannels = document.querySelectorAll('.pb-ch-check:checked');
                activeChannels.forEach(function(c) {
                    startChannelPlayback(parseInt(c.value, 10), selectedSegment.seg);
                });
                document.getElementById('pb-btn-stop-all').disabled = false;
            });

            document.getElementById('pb-btn-stop-all').addEventListener('click', function() {
                [1, 2, 3, 4].forEach(function(ch) {
                    stopChannelPlayback(ch);
                });
                document.getElementById('pb-btn-stop-all').disabled = true;
                status('Reproducción detenida', '#aaa');
            });

            /* ------------------------------------------------------------------ */
            /* Mapa Leaflet                                                         */
            /* ------------------------------------------------------------------ */
            function initMap() {
                if (map) return;
                if (typeof L === 'undefined') return;

                map = L.map('pb-map', {
                    center: [20, -100],
                    zoom: 4,
                    zoomControl: true
                });

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap',
                    maxZoom: 18
                }).addTo(map);
            }

            function loadRoute(fromTime, toTime) {
                var url = '/device_video/' + deviceId + '/playback/route' +
                    '?from=' + encodeURIComponent(fromTime) +
                    '&to=' + encodeURIComponent(toTime);

                document.getElementById('pb-map-info').textContent = 'Cargando recorrido GPS…';

                apiGet(url).then(function(res) {
                    if (!res.positions || res.positions.length === 0) {
                        document.getElementById('pb-map-info').textContent = 'Sin posiciones para el período.';
                        return;
                    }

                    initMap();

                    if (routeLayer) map.removeLayer(routeLayer);
                    if (posMarker) map.removeLayer(posMarker);

                    var latlngs = res.positions.map(function(p) {
                        return [p.lat, p.lng];
                    });

                    routeLayer = L.polyline(latlngs, {
                        color: '#3498db',
                        weight: 3,
                        opacity: 0.8
                    }).addTo(map);

                    // Marcadores inicio/fin
                    var start = res.positions[0];
                    var end = res.positions[res.positions.length - 1];

                    L.circleMarker([start.lat, start.lng], {
                            radius: 6,
                            color: '#27ae60',
                            fillOpacity: 1
                        })
                        .bindTooltip('Inicio: ' + (start.time || '')).addTo(map);
                    L.circleMarker([end.lat, end.lng], {
                            radius: 6,
                            color: '#c0392b',
                            fillOpacity: 1
                        })
                        .bindTooltip('Fin: ' + (end.time || '')).addTo(map);

                    map.fitBounds(routeLayer.getBounds(), {
                        padding: [20, 20]
                    });
                    map.invalidateSize();

                    document.getElementById('pb-map-info').textContent =
                        res.positions.length + ' posiciones cargadas';
                }).catch(function() {
                    document.getElementById('pb-map-info').textContent = 'Error al cargar el recorrido.';
                });
            }

            /* ------------------------------------------------------------------ */
            /* Inicialización del mapa al renderizar (lazy)                         */
            /* ------------------------------------------------------------------ */
            setTimeout(function() {
                if (document.getElementById('pb-map').offsetParent !== null) {
                    initMap();
                }
            }, 400);

            /* ------------------------------------------------------------------ */
            /* Limpieza al cerrar modal                                             */
            /* ------------------------------------------------------------------ */
            var modalEl = document.getElementById('device-modal');
            if (!modalEl) {
                // Buscar el modal contenedor más cercano
                var el = document.querySelector('.modal-playback');
                if (el) modalEl = el;
            }
            if (modalEl) {
                modalEl.addEventListener('hidden.bs.modal', cleanup);
                modalEl.addEventListener('hide.bs.modal', cleanup);
            }

            function cleanup() {
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
                [1, 2, 3, 4].forEach(function(ch) {
                    if (hlsInstances[ch]) {
                        hlsInstances[ch].destroy();
                        delete hlsInstances[ch];
                    }
                    apiPost('/device_video/' + deviceId + '/playback/stop', {
                        channel: ch
                    });
                });
                if (map) {
                    map.remove();
                    map = null;
                }
            }

        })();
    </script>

@stop
