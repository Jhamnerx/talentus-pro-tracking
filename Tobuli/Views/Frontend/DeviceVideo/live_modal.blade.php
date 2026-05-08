@extends('Frontend.Layouts.modal_full')

@section('title')
    <i class="icon play"></i> {{ $device->name }}
@stop

@section('modal_class')
    modal-video
@stop

@section('buttons')
@stop

@section('body')

    <style>
        .modal-video .modal-dialog {
            max-width: 1200px;
        }

        .modal-video .modal-body {
            max-height: 80vh;
            overflow-y: auto;
        }

        .lvm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 600px) {
            .lvm-grid {
                grid-template-columns: 1fr;
            }
        }

        .lvm-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
            background: #111;
        }

        .lvm-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px;
            background: #1a1a2e;
            color: #eee;
            font-size: .82rem;
            font-weight: 600;
        }

        .lvm-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: .72rem;
            background: #555;
            color: #fff;
        }

        .lvm-player-wrap {
            position: relative;
            background: #000;
        }

        .lvm-video {
            width: 100%;
            display: block;
            height: 280px;
            background: #000;
            object-fit: contain;
        }

        .lvm-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #ccc;
            font-size: .82rem;
            gap: 8px;
        }

        .lvm-overlay.hidden {
            display: none;
        }

        .lvm-spinner {
            width: 28px;
            height: 28px;
            border: 3px solid #555;
            border-top-color: #fff;
            border-radius: 50%;
            animation: lvm-spin .8s linear infinite;
        }

        @keyframes lvm-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .lvm-card-footer {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 8px;
            background: #1a1a2e;
            flex-wrap: wrap;
        }

        .lvm-btn {
            padding: 4px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: .78rem;
            font-weight: 600;
        }

        .lvm-btn:disabled {
            opacity: .4;
            cursor: default;
        }

        .lvm-btn-start {
            background: #27ae60;
            color: #fff;
        }

        .lvm-btn-stop {
            background: #c0392b;
            color: #fff;
        }

        .lvm-err {
            color: #e74c3c;
            font-size: .75rem;
            flex: 1;
            display: none;
        }
    </style>

    <div class="lvm-grid" id="lvm-grid">
        @for ($ch = 1; $ch <= 4; $ch++)
            <div class="lvm-card" data-ch="{{ $ch }}">
                <div class="lvm-card-header">
                    <span>{{ trans('front.channel') }} {{ $ch }}</span>
                    <span class="lvm-badge" id="lvm-badge-{{ $ch }}">Offline</span>
                </div>
                <div class="lvm-player-wrap">
                    <video class="lvm-video" id="lvm-video-{{ $ch }}" controls muted playsinline></video>
                    <div class="lvm-overlay" id="lvm-overlay-{{ $ch }}">
                        <span>&mdash; {{ trans('front.offline') }} &mdash;</span>
                    </div>
                </div>
                <div class="lvm-card-footer">
                    <button class="lvm-btn lvm-btn-start" id="lvm-start-{{ $ch }}">
                        &#9654; {{ trans('front.video_start') }}
                    </button>
                    <button class="lvm-btn lvm-btn-stop" id="lvm-stop-{{ $ch }}" disabled>
                        &#9632; {{ trans('front.video_stop') }}
                    </button>
                    <span class="lvm-err" id="lvm-err-{{ $ch }}"></span>
                </div>
            </div>
        @endfor
    </div>

    <script>
        (function() {
            var deviceId = {{ $device->id }};
            var hlsInstances = {};

            function csrfToken() {
                var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                return m ? decodeURIComponent(m[1]) : '';
            }

            function post(url, data) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                }).then(function(r) {
                    return r.json();
                });
            }

            function setBadge(ch, txt, color) {
                var el = document.getElementById('lvm-badge-' + ch);
                if (el) {
                    el.textContent = txt;
                    el.style.background = color;
                }
            }

            function showOverlay(ch, msg) {
                var ov = document.getElementById('lvm-overlay-' + ch);
                if (!ov) return;
                ov.classList.remove('hidden');
                ov.innerHTML = msg ?
                    '<div class="lvm-spinner"></div><span>' + msg + '</span>' :
                    '<span>&mdash; offline &mdash;</span>';
            }

            function hideOverlay(ch) {
                var ov = document.getElementById('lvm-overlay-' + ch);
                if (ov) ov.classList.add('hidden');
            }

            function showErr(ch, msg) {
                var el = document.getElementById('lvm-err-' + ch);
                if (el) {
                    el.textContent = msg;
                    el.style.display = msg ? 'block' : 'none';
                }
            }

            function destroyHls(ch) {
                if (hlsInstances[ch]) {
                    hlsInstances[ch].destroy();
                    delete hlsInstances[ch];
                }
                var v = document.getElementById('lvm-video-' + ch);
                if (v) v.src = '';
            }

            var retryCount = {};
            var MAX_RETRIES = 12;

            function attachHls(ch, url) {
                var video = document.getElementById('lvm-video-' + ch);
                if (!video) return;
                destroyHls(ch);
                showErr(ch, '');
                retryCount[ch] = 0;

                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                    var h = new Hls({
                        enableWorker: false,
                        liveSyncDurationCount: 2,
                        liveMaxLatencyDurationCount: 6,
                        levelLoadingRetryDelay: 2000,
                        fragLoadingMaxRetry: 6,
                        levelLoadingMaxRetry: 4
                    });
                    hlsInstances[ch] = h;
                    h.loadSource(url);
                    h.attachMedia(video);
                    h.on(Hls.Events.MANIFEST_PARSED, function(e, data) {
                        retryCount[ch] = 0;
                        // Detectar si el stream usa H.265 (no soportado por Chrome/Firefox vía MSE)
                        var level = h.levels && h.levels[0];
                        var codec = level && level.videoCodec ? level.videoCodec : '';
                        if (codec && (codec.indexOf('hvc') === 0 || codec.indexOf('hev') === 0)) {
                            showErr(ch, 'H.265 no soportado en este navegador. Actualizando servidor...');
                            setBadge(ch, 'H.265', '#e67e22');
                            hideOverlay(ch);
                            return;
                        }
                        hideOverlay(ch);
                        setBadge(ch, 'LIVE', '#27ae60');
                        video.play().catch(function() {});
                    });
                    h.on(Hls.Events.ERROR, function(e, data) {
                        if (data.fatal) {
                            if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                                h.recoverMediaError();
                                return;
                            }
                            // NETWORK_ERROR: reintento con backoff exponencial
                            retryCount[ch] = (retryCount[ch] || 0) + 1;
                            if (retryCount[ch] <= MAX_RETRIES) {
                                var delay = Math.min(1000 * Math.pow(1.5, retryCount[ch] - 1), 8000);
                                showOverlay(ch, 'Reconectando... (' + retryCount[ch] + '/' + MAX_RETRIES + ')');
                                setBadge(ch, 'Conectando...', '#e67e22');
                                setTimeout(function() {
                                    if (hlsInstances[ch] === h) {
                                        // startLoad() no funciona tras un error fatal de manifest;
                                        // hay que recrear la instancia completa.
                                        attachHls(ch, url);
                                    }
                                }, delay);
                            } else {
                                showErr(ch, 'Sin respuesta del dispositivo. Intente de nuevo.');
                                setBadge(ch, 'Error', '#c0392b');
                                showOverlay(ch, null);
                                document.getElementById('lvm-start-' + ch).disabled = false;
                                document.getElementById('lvm-stop-' + ch).disabled = true;
                                destroyHls(ch);
                            }
                        }
                    });
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = url;
                    hideOverlay(ch);
                    setBadge(ch, 'LIVE', '#27ae60');
                    video.play().catch(function() {});
                } else {
                    showErr(ch, '{{ trans('front.browser_not_support_html_video') }}');
                }
            }

            function loadHlsOnce(ch, url) {
                if (typeof Hls !== 'undefined') {
                    attachHls(ch, url);
                } else {
                    var s = document.createElement('script');
                    s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.4.14/dist/hls.min.js';
                    s.onload = function() {
                        attachHls(ch, url);
                    };
                    s.onerror = function() {
                        showErr(ch, '{{ trans('front.browser_not_support_html_video') }}');
                    };
                    document.head.appendChild(s);
                }
            }

            function startChannel(ch) {
                setBadge(ch, 'Conectando...', '#e67e22');
                showOverlay(ch, 'Conectando...');
                showErr(ch, '');
                document.getElementById('lvm-start-' + ch).disabled = true;
                document.getElementById('lvm-stop-' + ch).disabled = false;

                var url = '/device_video/' + deviceId + '/stream/live.m3u8?channel=' + ch;

                post('/device_video/' + deviceId + '/live/start', {
                        channel: ch
                    })
                    .then(function() {
                        setTimeout(function() {
                            loadHlsOnce(ch, url);
                        }, 6000);
                    })
                    .catch(function() {
                        setTimeout(function() {
                            loadHlsOnce(ch, url);
                        }, 6000);
                    });
            }

            function stopChannel(ch) {
                destroyHls(ch);
                showOverlay(ch, null);
                setBadge(ch, 'Offline', '#555');
                showErr(ch, '');
                document.getElementById('lvm-start-' + ch).disabled = false;
                document.getElementById('lvm-stop-' + ch).disabled = true;
                post('/device_video/' + deviceId + '/live/stop', {
                    channel: ch
                }).catch(function() {});
            }

            // Bind buttons for all channels
            [1, 2, 3, 4].forEach(function(ch) {
                var btnStart = document.getElementById('lvm-start-' + ch);
                var btnStop = document.getElementById('lvm-stop-' + ch);
                if (btnStart) btnStart.addEventListener('click', function() {
                    startChannel(ch);
                });
                if (btnStop) btnStop.addEventListener('click', function() {
                    stopChannel(ch);
                });
            });

            // Cleanup on modal close
            function cleanupAll() {
                [1, 2, 3, 4].forEach(function(ch) {
                    var btnStop = document.getElementById('lvm-stop-' + ch);
                    if (btnStop && !btnStop.disabled) {
                        post('/device_video/' + deviceId + '/live/stop', {
                            channel: ch
                        }).catch(function() {});
                    }
                    destroyHls(ch);
                });
            }

            var grid = document.getElementById('lvm-grid');
            var modal = grid ? grid.closest('.modal') || grid.closest('[id]') : null;
            if (modal) {
                modal.addEventListener('hidden.bs.modal', cleanupAll);
                $(modal).on('hidden', cleanupAll);
            }
        }());
    </script>

@stop
