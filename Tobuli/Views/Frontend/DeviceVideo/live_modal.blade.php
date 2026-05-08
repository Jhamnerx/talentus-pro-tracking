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

        .lvm-card-footer-audio {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px 6px;
            background: #1a1a2e;
            flex-wrap: wrap;
            border-top: 1px solid #2a2a4e;
        }

        .lvm-btn-audio {
            background: #d35400;
            color: #fff;
        }

        .lvm-btn-audio.active {
            background: #e67e22;
            box-shadow: 0 0 6px rgba(230, 126, 34, .7);
        }

        .lvm-btn-intercom {
            background: #6c3483;
            color: #fff;
        }

        .lvm-btn-intercom.active {
            background: #9b59b6;
            box-shadow: 0 0 6px rgba(155, 89, 182, .7);
        }

        .lvm-audio-indicator {
            font-size: .70rem;
            color: #aaa;
            flex: 1;
        }

        .lvm-audio-indicator.on {
            color: #e67e22;
        }

        .lvm-audio-indicator.intercom {
            color: #9b59b6;
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
                <div class="lvm-card-footer-audio">
                    <button class="lvm-btn lvm-btn-audio" id="lvm-audio-{{ $ch }}" disabled
                        title="{{ trans('front.audio_mode_listen') }}">
                        &#128266; {{ trans('front.audio_mode_listen') }}
                    </button>
                    <button class="lvm-btn lvm-btn-intercom" id="lvm-intercom-{{ $ch }}" disabled
                        title="{{ trans('front.audio_mode_intercom') }}">
                        &#127908; {{ trans('front.audio_mode_intercom') }}
                    </button>
                    <span class="lvm-audio-indicator" id="lvm-audio-status-{{ $ch }}"></span>
                </div>
            </div>
        @endfor
    </div>

    <script>
        (function() {
            var deviceId = {{ $device->id }};
            var hlsInstances = {};
            var audioState = {}; // ch -> 'off' | 'listen' | 'intercom'

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

            function setAudioButtons(ch, enabled) {
                var btnAudio = document.getElementById('lvm-audio-' + ch);
                var btnIntercom = document.getElementById('lvm-intercom-' + ch);
                if (btnAudio) btnAudio.disabled = !enabled;
                if (btnIntercom) btnIntercom.disabled = !enabled;
            }

            function setAudioStatus(ch, state) {
                audioState[ch] = state || 'off';
                var el = document.getElementById('lvm-audio-status-' + ch);
                var btnAudio = document.getElementById('lvm-audio-' + ch);
                var btnIntercom = document.getElementById('lvm-intercom-' + ch);
                if (!el) return;
                if (state === 'listen') {
                    el.textContent = '&#128266; Escuchando...';
                    el.className = 'lvm-audio-indicator on';
                    if (btnAudio) btnAudio.classList.add('active');
                    if (btnIntercom) btnIntercom.classList.remove('active');
                } else if (state === 'intercom') {
                    el.textContent = '&#127908; Intercomunicador activo...';
                    el.className = 'lvm-audio-indicator intercom';
                    if (btnAudio) btnAudio.classList.remove('active');
                    if (btnIntercom) btnIntercom.classList.add('active');
                } else {
                    el.textContent = '';
                    el.className = 'lvm-audio-indicator';
                    if (btnAudio) btnAudio.classList.remove('active');
                    if (btnIntercom) btnIntercom.classList.remove('active');
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

            // ── HLS attach ────────────────────────────────────────────────────
            // NOTE: MANIFEST_PARSED fires even on an empty live playlist (no segments).
            // We must NOT show "LIVE" there — only after the first video frame plays.
            // The `video.playing` DOM event is the reliable signal.

            function attachHls(ch, url, overlaySuffix) {
                var video = document.getElementById('lvm-video-' + ch);
                if (!video) return;
                destroyHls(ch);
                showErr(ch, '');

                var suffix = overlaySuffix || 'Aguardando dispositivo...';
                showOverlay(ch, suffix);
                setBadge(ch, 'Conectando...', '#e67e22');

                // Once video actually plays → mark LIVE
                var onPlaying = function() {
                    hideOverlay(ch);
                    setBadge(ch, 'LIVE', '#27ae60');
                    video.removeEventListener('playing', onPlaying);
                };
                video.addEventListener('playing', onPlaying);

                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                    var h = new Hls({
                        enableWorker: false,
                        liveSyncDurationCount: 2,
                        liveMaxLatencyDurationCount: 8,
                        levelLoadingRetryDelay: 2000,
                        fragLoadingMaxRetry: 8,
                        levelLoadingMaxRetry: 6,
                        manifestLoadingMaxRetry: 6,
                        manifestLoadingRetryDelay: 2000,
                    });
                    hlsInstances[ch] = h;
                    h.loadSource(url);
                    h.attachMedia(video);

                    h.on(Hls.Events.MANIFEST_PARSED, function() {
                        // Playlist cargado — puede estar vacío aún; no cambiamos overlay.
                        // Solo intentamos play(); el evento `playing` del video
                        // se encargará de mostrar LIVE cuando haya datos reales.
                        video.play().catch(function() {});
                    });

                    h.on(Hls.Events.FRAG_PARSED, function(e, data) {
                        var level = h.levels && h.levels[h.currentLevel >= 0 ? h.currentLevel : 0];
                        var codec = (level && level.videoCodec) ? level.videoCodec : null;
                        if (codec && (codec.indexOf('hvc') === 0 || codec.indexOf('hev') === 0)) {
                            showErr(ch, 'H.265 detectado — transcode pendiente.');
                            setBadge(ch, 'H.265', '#e67e22');
                        }
                    });

                    h.on(Hls.Events.ERROR, function(e, data) {
                        if (!data.fatal) return;

                        if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                            h.recoverMediaError();
                            return;
                        }

                        // Fatal network error: reintento con backoff exponencial.
                        // attachHls() resets el contador por lo que el backoff solo aplica
                        // dentro de una misma "sesión de attach".
                        var rc = (h._lvmRetry = (h._lvmRetry || 0) + 1);
                        var delay = Math.min(1500 * Math.pow(1.6, rc - 1), 12000);
                        showOverlay(ch, 'Reconectando... (' + rc + ')');
                        setTimeout(function() {
                            if (hlsInstances[ch] === h) {
                                attachHls(ch, url, 'Reconectando...');
                            }
                        }, delay);
                    });

                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    // Safari native HLS
                    video.src = url;
                    video.load();
                    video.play().catch(function() {});
                } else {
                    showErr(ch, '{{ trans('front.browser_not_support_html_video') }}');
                }
            }

            function loadHlsLib(cb) {
                if (typeof Hls !== 'undefined') {
                    cb();
                    return;
                }
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.4.14/dist/hls.min.js';
                s.onload = cb;
                s.onerror = function() {
                    console.warn('hls.js failed to load from CDN');
                    cb(); // proceed anyway; canPlayType fallback may work
                };
                document.head.appendChild(s);
            }

            // ── Video start/stop ──────────────────────────────────────────────

            function startChannel(ch) {
                setBadge(ch, 'Enviando comando...', '#e67e22');
                showOverlay(ch, 'Enviando comando...');
                showErr(ch, '');
                document.getElementById('lvm-start-' + ch).disabled = true;
                document.getElementById('lvm-stop-' + ch).disabled = false;

                var url = '/device_video/' + deviceId + '/stream/live.m3u8?channel=' + ch;

                post('/device_video/' + deviceId + '/live/start', {
                        channel: ch
                    })
                    .then(function(res) {
                        // `initial_delay`: 6000 ms si device conectado, 20000 ms si offline
                        var delay = (res && res.initial_delay) ? res.initial_delay : 8000;
                        var msg = (res && res.connected === false) ?
                            'Dispositivo offline — comando en cola (' + Math.round(delay / 1000) + ' s)...' :
                            'Aguardando dispositivo...';
                        showOverlay(ch, msg);
                        setBadge(ch, 'Conectando...', '#e67e22');
                        loadHlsLib(function() {
                            setTimeout(function() {
                                attachHls(ch, url, msg);
                            }, delay);
                        });
                    })
                    .catch(function() {
                        // Fallback: mismo comportamiento con delay fijo
                        loadHlsLib(function() {
                            setTimeout(function() {
                                attachHls(ch, url, 'Aguardando dispositivo...');
                            }, 8000);
                        });
                    });
            }

            function stopChannel(ch) {
                destroyHls(ch);
                showOverlay(ch, null);
                setBadge(ch, 'Offline', '#555');
                showErr(ch, '');
                setAudioButtons(ch, false);
                setAudioStatus(ch, 'off');
                document.getElementById('lvm-start-' + ch).disabled = false;
                document.getElementById('lvm-stop-' + ch).disabled = true;
                post('/device_video/' + deviceId + '/live/stop', {
                    channel: ch
                }).catch(function() {});
                post('/device_video/' + deviceId + '/audio/stop', {
                    channel: ch
                }).catch(function() {});
            }

            // ── Audio listen / intercom ───────────────────────────────────────

            function toggleAudio(ch, mode) {
                // mode: 0 = listen, 1 = intercom
                var modeLabel = mode === 0 ? 'listen' : 'intercom';
                var current = audioState[ch] || 'off';

                // Toggle off if same mode active
                if ((mode === 0 && current === 'listen') || (mode === 1 && current === 'intercom')) {
                    setAudioStatus(ch, 'off');
                    post('/device_video/' + deviceId + '/audio/stop', {
                        channel: ch
                    }).catch(function() {});
                    return;
                }

                setAudioStatus(ch, modeLabel);
                post('/device_video/' + deviceId + '/audio/start', {
                        channel: ch,
                        mode: mode
                    })
                    .then(function(res) {
                        if (res && res.error) {
                            setAudioStatus(ch, 'off');
                            showErr(ch, res.error);
                        }
                    })
                    .catch(function() {
                        setAudioStatus(ch, 'off');
                    });
            }

            // ── Button binding ────────────────────────────────────────────────

            [1, 2, 3, 4].forEach(function(ch) {
                var btnStart = document.getElementById('lvm-start-' + ch);
                var btnStop = document.getElementById('lvm-stop-' + ch);
                var btnAudio = document.getElementById('lvm-audio-' + ch);
                var btnIntercom = document.getElementById('lvm-intercom-' + ch);

                if (btnStart) btnStart.addEventListener('click', function() {
                    startChannel(ch);
                });
                if (btnStop) btnStop.addEventListener('click', function() {
                    stopChannel(ch);
                });
                if (btnAudio) btnAudio.addEventListener('click', function() {
                    toggleAudio(ch, 0);
                });
                if (btnIntercom) btnIntercom.addEventListener('click', function() {
                    toggleAudio(ch, 1);
                });

                // Enable audio buttons when video is active (playing event on video element)
                var video = document.getElementById('lvm-video-' + ch);
                if (video) {
                    video.addEventListener('playing', function() {
                        setAudioButtons(ch, true);
                    });
                    video.addEventListener('pause', function() {
                        if (!hlsInstances[ch]) setAudioButtons(ch, false);
                    });
                }
            });

            // ── Modal cleanup ─────────────────────────────────────────────────

            function cleanupAll() {
                [1, 2, 3, 4].forEach(function(ch) {
                    var btnStop = document.getElementById('lvm-stop-' + ch);
                    if (btnStop && !btnStop.disabled) {
                        post('/device_video/' + deviceId + '/live/stop', {
                            channel: ch
                        }).catch(function() {});
                        post('/device_video/' + deviceId + '/audio/stop', {
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
