<!DOCTYPE html>
<html lang="{{ Language::iso() }}">

<head>
    @include('Frontend.Layouts.partials.head')
    <title>{{ $device->name }} — {{ trans('front.live_video') }}</title>
    <style>
        body {
            background: #111;
            color: #eee;
            margin: 0;
            font-family: Arial, sans-serif;
        }

        .lv-page {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px;
        }

        /* ── cabecera ─────────────────────────────────── */
        .lv-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            width: 100%;
            max-width: 960px;
        }

        .lv-header h2 {
            margin: 0;
            font-size: 1.1rem;
        }

        .lv-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.78rem;
            background: #555;
        }

        .lv-badge.connecting {
            background: #e67e22;
        }

        .lv-badge.live {
            background: #27ae60;
        }

        .lv-badge.error {
            background: #c0392b;
        }

        /* ── canales ─────────────────────────────────── */
        .lv-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }

        .lv-tab {
            padding: 5px 14px;
            border: 1px solid #444;
            border-radius: 4px;
            cursor: pointer;
            background: #222;
            color: #ccc;
            font-size: 0.82rem;
        }

        .lv-tab.active {
            background: #1a73e8;
            border-color: #1a73e8;
            color: #fff;
        }

        /* ── reproductor ─────────────────────────────── */
        .lv-player {
            width: 100%;
            max-width: 960px;
            background: #000;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        #lv-video {
            width: 100%;
            max-height: 540px;
            display: block;
            background: #000;
        }

        .lv-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .72);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }

        .lv-overlay.hidden {
            display: none;
        }

        .lv-spinner {
            width: 36px;
            height: 36px;
            border: 4px solid #555;
            border-top-color: #fff;
            border-radius: 50%;
            animation: lv-spin .8s linear infinite;
            margin-right: 10px;
        }

        @keyframes lv-spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ── botones ─────────────────────────────────── */
        .lv-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .lv-btn {
            padding: 7px 18px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.88rem;
        }

        .lv-btn-start {
            background: #27ae60;
            color: #fff;
        }

        .lv-btn-stop {
            background: #c0392b;
            color: #fff;
        }

        .lv-btn-audio {
            background: #1a73e8;
            color: #fff;
        }

        .lv-btn-snap {
            background: #8e44ad;
            color: #fff;
        }

        .lv-btn:disabled {
            opacity: .4;
            cursor: default;
        }

        .lv-error {
            color: #e74c3c;
            margin-top: 8px;
            display: none;
            font-size: 0.88rem;
        }
    </style>
</head>

<body>

    <div class="lv-page">

        {{-- Cabecera --}}
        <div class="lv-header">
            <h2>{{ $device->name }}</h2>
            <span class="lv-badge connecting" id="lv-badge">{{ trans('front.connecting') }}…</span>
        </div>

        {{-- Selector de canal --}}
        <div class="lv-tabs">
            @for ($ch = 1; $ch <= 4; $ch++)
                <button class="lv-tab {{ $ch === 1 ? 'active' : '' }}" data-channel="{{ $ch }}"
                    id="lv-tab{{ $ch }}">
                    {{ trans('front.channel') }} {{ $ch }}
                </button>
            @endfor
        </div>

        {{-- Reproductor --}}
        <div class="lv-player">
            <video id="lv-video" controls muted playsinline></video>
            <div class="lv-overlay" id="lv-overlay">
                <div class="lv-spinner"></div>
                <span>{{ trans('front.loading_stream') }}…</span>
            </div>
        </div>

        {{-- Controles --}}
        <div class="lv-controls">
            <button class="lv-btn lv-btn-start" id="lv-btnStart" disabled>
                <i class="fa fa-play"></i> {{ trans('front.video_start') }}
            </button>
            <button class="lv-btn lv-btn-stop" id="lv-btnStop" disabled>
                <i class="fa fa-stop"></i> {{ trans('front.video_stop') }}
            </button>
            <button class="lv-btn lv-btn-audio" id="lv-btnAudio">
                <i class="fa fa-microphone"></i> {{ trans('front.audio_listen') }}
            </button>
            @if ($device->protocol === 'howen')
                <button class="lv-btn lv-btn-snap" id="lv-btnSnap">
                    <i class="fa fa-camera"></i> {{ trans('front.snapshot') }}
                </button>
            @endif
        </div>

        <p class="lv-error" id="lv-error"></p>

    </div>

    @include('Frontend.Layouts.partials.app')
    @include('Frontend.Layouts.partials.trans')
    <script src="{{ asset_resource('assets/js/core.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.7/dist/hls.min.js"></script>

    <script>
        (function() {
            'use strict';

            /* ── Configuración ──────────────────────────────────────────── */
            var deviceId = {{ $device->id }};
            var protocol = '{{ $device->protocol }}';
            var channel = 1;
            var audioActive = false;
            var hls = null;

            /* ── Elementos DOM ──────────────────────────────────────────── */
            var video = document.getElementById('lv-video');
            var overlay = document.getElementById('lv-overlay');
            var badge = document.getElementById('lv-badge');
            var errEl = document.getElementById('lv-error');
            var btnStart = document.getElementById('lv-btnStart');
            var btnStop = document.getElementById('lv-btnStop');
            var btnAudio = document.getElementById('lv-btnAudio');
            var btnSnap = document.getElementById('lv-btnSnap');

            /* ── Helpers ────────────────────────────────────────────────── */
            function setBadge(cls, txt) {
                badge.className = 'lv-badge ' + cls;
                badge.textContent = txt;
            }

            function showError(msg) {
                errEl.textContent = msg;
                errEl.style.display = 'block';
                setBadge('error', 'Error');
                overlay.classList.add('hidden');
            }

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
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(data),
                }).then(function(r) {
                    return r.json();
                });
            }

            /* ── HLS player ─────────────────────────────────────────────── */
            function destroyHls() {
                if (hls) {
                    hls.destroy();
                    hls = null;
                }
                video.src = '';
            }

            function loadStream(ch) {
                destroyHls();
                var m3u8 = '/device_video/' + deviceId + '/stream/live.m3u8?channel=' + ch;

                if (window.Hls && Hls.isSupported()) {
                    hls = new Hls();
                    hls.loadSource(m3u8);
                    hls.attachMedia(video);
                    hls.on(Hls.Events.MANIFEST_PARSED, function() {
                        overlay.classList.add('hidden');
                        setBadge('live', 'LIVE');
                        video.play().catch(function() {});
                    });
                    hls.on(Hls.Events.ERROR, function(e, data) {
                        if (data.fatal) {
                            showError('HLS error: ' + data.type);
                        }
                    });
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = m3u8;
                    video.addEventListener('loadedmetadata', function() {
                        overlay.classList.add('hidden');
                        setBadge('live', 'LIVE');
                        video.play().catch(function() {});
                    }, {
                        once: true
                    });
                } else {
                    showError('{{ trans('front.browser_not_support_html_video') }}');
                }
            }

            /* ── Endpoints según protocolo ──────────────────────────────── */
            function urlLiveStart() {
                return '/device_video/' + deviceId + '/live/start';
            }

            function urlLiveStop() {
                return '/device_video/' + deviceId + '/live/stop';
            }

            function urlAudioStart() {
                return '/device_video/' + deviceId + '/audio/start';
            }

            function urlAudioStop() {
                return '/device_video/' + deviceId + '/audio/stop';
            }

            function urlSnapshot() {
                return '/device_video/' + deviceId + '/snapshot';
            }

            /* ── Acciones ───────────────────────────────────────────────── */
            function startLive() {
                overlay.classList.remove('hidden');
                setBadge('connecting', '{{ trans('front.connecting') }}…');
                errEl.style.display = 'none';
                btnStart.disabled = true;
                btnStop.disabled = false;

                post(urlLiveStart(), {
                        channel: channel,
                        stream: 1
                    })
                    .then(function(res) {
                        var delay = 3000;
                        // Si Traccar devuelve hls_url directamente, usarla
                        if (res && res.hls_url) {
                            setTimeout(function() {
                                destroyHls();
                                overlay.classList.remove('hidden');
                                loadStreamDirect(res.hls_url);
                            }, delay);
                        } else {
                            setTimeout(function() {
                                loadStream(channel);
                            }, delay);
                        }
                    })
                    .catch(function() {
                        setTimeout(function() {
                            loadStream(channel);
                        }, 2000);
                    });
            }

            function loadStreamDirect(url) {
                if (window.Hls && Hls.isSupported()) {
                    hls = new Hls();
                    hls.loadSource(url);
                    hls.attachMedia(video);
                    hls.on(Hls.Events.MANIFEST_PARSED, function() {
                        overlay.classList.add('hidden');
                        setBadge('live', 'LIVE');
                        video.play().catch(function() {});
                    });
                    hls.on(Hls.Events.ERROR, function(e, data) {
                        if (data.fatal) {
                            // fallback al proxy interno
                            loadStream(channel);
                        }
                    });
                } else {
                    loadStream(channel);
                }
            }

            function stopLive() {
                destroyHls();
                setBadge('connecting', '{{ trans('front.offline') }}');
                overlay.classList.remove('hidden');
                btnStart.disabled = false;
                btnStop.disabled = true;
                post(urlLiveStop(), {
                    channel: channel,
                    stream: 1
                }).catch(function() {});
            }

            function toggleAudio() {
                audioActive = !audioActive;
                if (audioActive) {
                    btnAudio.textContent = '\u23F9 {{ trans('front.audio_stop') }}';
                    post(urlAudioStart(), {
                        channel: channel,
                        mode: 0
                    }).catch(function() {});
                } else {
                    btnAudio.textContent = '\uD83C\uDFA4 {{ trans('front.audio_listen') }}';
                    post(urlAudioStop(), {
                        channel: channel
                    }).catch(function() {});
                }
            }

            function takeSnapshot() {
                if (btnSnap) {
                    btnSnap.disabled = true;
                    post(urlSnapshot(), {
                            channels: String(channel)
                        })
                        .then(function() {
                            btnSnap.disabled = false;
                        })
                        .catch(function() {
                            btnSnap.disabled = false;
                        });
                }
            }

            /* ── Tabs de canal ──────────────────────────────────────────── */
            document.querySelectorAll('.lv-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.lv-tab').forEach(function(t) {
                        t.classList.remove('active');
                    });
                    tab.classList.add('active');
                    channel = parseInt(tab.dataset.channel, 10);
                    if (!btnStop.disabled) {
                        // cambiar canal en caliente
                        overlay.classList.remove('hidden');
                        post(urlLiveStart(), {
                                channel: channel,
                                stream: 1
                            })
                            .then(function(res) {
                                var delay = 2000;
                                if (res && res.hls_url) {
                                    setTimeout(function() {
                                        loadStreamDirect(res.hls_url);
                                    }, delay);
                                } else {
                                    setTimeout(function() {
                                        loadStream(channel);
                                    }, delay);
                                }
                            })
                            .catch(function() {
                                loadStream(channel);
                            });
                    }
                });
            });

            /* ── Botones ────────────────────────────────────────────────── */
            btnStart.addEventListener('click', startLive);
            btnStop.addEventListener('click', stopLive);
            btnAudio.addEventListener('click', toggleAudio);
            if (btnSnap) {
                btnSnap.addEventListener('click', takeSnapshot);
            }

            /* ── Inicio automático ──────────────────────────────────────── */
            window.addEventListener('load', startLive);

            /* ── Limpiar al cerrar ──────────────────────────────────────── */
            window.addEventListener('beforeunload', function() {
                if (!btnStop.disabled) {
                    post(urlLiveStop(), {
                        channel: channel,
                        stream: 1
                    });
                }
                if (audioActive) {
                    post(urlAudioStop(), {
                        channel: channel
                    });
                }
            });

        })();
    </script>

</body>

</html>
