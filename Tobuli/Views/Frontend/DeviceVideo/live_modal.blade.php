@extends('Frontend.Layouts.modal_full')

@section('title')
    <i class="icon play"></i> {{ $device->name }} — {{ trans('front.live_video') }}
    <span class="lvm-badge lvm-connecting" id="lvm-badge"
        style="
        margin-left:10px;padding:3px 10px;border-radius:12px;
        font-size:.75rem;background:#e67e22;color:#fff;vertical-align:middle;">
        {{ trans('front.connecting') }}…
    </span>
@stop

@section('modal_class')
    modal-video
@stop

@section('buttons')
    {{-- Sin botones estándar; los controles están en el body --}}
@stop

@section('body')

    <style>
        .modal-video .modal-dialog {
            max-width: 960px;
        }

        .lvm-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: .75rem;
        }

        .lvm-badge.lvm-connecting {
            background: #e67e22;
            color: #fff;
        }

        .lvm-badge.lvm-live {
            background: #27ae60;
            color: #fff;
        }

        .lvm-badge.lvm-offline {
            background: #555;
            color: #ccc;
        }

        .lvm-badge.lvm-error {
            background: #c0392b;
            color: #fff;
        }

        .lvm-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .lvm-tab {
            padding: 5px 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            background: #f5f5f5;
            color: #555;
            font-size: .82rem;
        }

        .lvm-tab.active {
            background: #1a73e8;
            border-color: #1a73e8;
            color: #fff;
        }

        .lvm-player {
            position: relative;
            background: #000;
            border-radius: 4px;
            overflow: hidden;
            width: 100%;
        }

        #lvm-video {
            width: 100%;
            max-height: 500px;
            display: block;
            background: #000;
        }

        .lvm-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .72);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #eee;
            font-size: .9rem;
        }

        .lvm-overlay.hidden {
            display: none;
        }

        .lvm-spinner {
            width: 32px;
            height: 32px;
            border: 4px solid #555;
            border-top-color: #fff;
            border-radius: 50%;
            animation: lvm-spin .8s linear infinite;
            margin-right: 10px;
        }

        @keyframes lvm-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .lvm-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .lvm-btn {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: .85rem;
        }

        .lvm-btn-start {
            background: #27ae60;
            color: #fff;
        }

        .lvm-btn-stop {
            background: #c0392b;
            color: #fff;
        }

        .lvm-btn-audio {
            background: #1a73e8;
            color: #fff;
        }

        .lvm-btn-snap {
            background: #8e44ad;
            color: #fff;
        }

        .lvm-btn:disabled {
            opacity: .4;
            cursor: default;
        }

        .lvm-error-msg {
            color: #c0392b;
            margin-top: 8px;
            display: none;
            font-size: .85rem;
        }
    </style>

    {{-- Selector de canal --}}
    <div class="lvm-tabs">
        @for ($ch = 1; $ch <= 4; $ch++)
            <button class="lvm-tab {{ $ch === 1 ? 'active' : '' }}" data-channel="{{ $ch }}"
                id="lvm-tab{{ $ch }}">
                {{ trans('front.channel') }} {{ $ch }}
            </button>
        @endfor
    </div>

    {{-- Reproductor --}}
    <div class="lvm-player">
        <video id="lvm-video" controls muted playsinline></video>
        <div class="lvm-overlay" id="lvm-overlay">
            <div class="lvm-spinner"></div>
            <span>{{ trans('front.loading_stream') }}…</span>
        </div>
    </div>

    {{-- Controles --}}
    <div class="lvm-controls">
        <button class="lvm-btn lvm-btn-start" id="lvm-btnStart" disabled>
            <i class="fa fa-play"></i> {{ trans('front.video_start') }}
        </button>
        <button class="lvm-btn lvm-btn-stop" id="lvm-btnStop" disabled>
            <i class="fa fa-stop"></i> {{ trans('front.video_stop') }}
        </button>
        <button class="lvm-btn lvm-btn-audio" id="lvm-btnAudio">
            <i class="fa fa-microphone"></i> {{ trans('front.audio_listen') }}
        </button>
        @if ($device->protocol === 'howen')
            <button class="lvm-btn lvm-btn-snap" id="lvm-btnSnap">
                <i class="fa fa-camera"></i> {{ trans('front.snapshot') }}
            </button>
        @endif
    </div>

    <p class="lvm-error-msg" id="lvm-error"></p>

    <script>
        (function() {
            'use strict';

            var deviceId = {{ $device->id }};
            var protocol = '{{ $device->protocol }}';
            var channel = 1;
            var audioActive = false;
            var hls = null;

            var video = document.getElementById('lvm-video');
            var overlay = document.getElementById('lvm-overlay');
            var badge = document.getElementById('lvm-badge');
            var errEl = document.getElementById('lvm-error');
            var btnStart = document.getElementById('lvm-btnStart');
            var btnStop = document.getElementById('lvm-btnStop');
            var btnAudio = document.getElementById('lvm-btnAudio');
            var btnSnap = document.getElementById('lvm-btnSnap');

            /* ── Helpers ─────────────────────────────── */
            function setBadge(cls, txt) {
                badge.className = 'lvm-badge ' + cls;
                badge.textContent = txt;
            }

            function showError(msg) {
                errEl.textContent = msg;
                errEl.style.display = 'block';
                setBadge('lvm-error', 'Error');
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

            /* ── HLS ─────────────────────────────────── */
            function ensureHls(cb) {
                if (window.Hls) {
                    cb();
                    return;
                }
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.4.14/dist/hls.min.js';
                s.onload = function() {
                    cb();
                };
                s.onerror = function() {
                    showError('{{ trans('front.browser_not_support_html_video') }}');
                };
                document.head.appendChild(s);
            }

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

                ensureHls(function() {
                    if (Hls.isSupported()) {
                        hls = new Hls({
                            enableWorker: false
                        });
                        hls.loadSource(m3u8);
                        hls.attachMedia(video);
                        hls.on(Hls.Events.MANIFEST_PARSED, function() {
                            overlay.classList.add('hidden');
                            setBadge('lvm-live', 'LIVE');
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
                            setBadge('lvm-live', 'LIVE');
                            video.play().catch(function() {});
                        }, {
                            once: true
                        });
                    } else {
                        showError('{{ trans('front.browser_not_support_html_video') }}');
                    }
                });
            }

            /* ── URLs ────────────────────────────────── */
            var base = '/device_video/' + deviceId;

            function urlLiveStart() {
                return base + '/live/start';
            }

            function urlLiveStop() {
                return base + '/live/stop';
            }

            function urlAudioStart() {
                return base + '/audio/start';
            }

            function urlAudioStop() {
                return base + '/audio/stop';
            }

            function urlSnapshot() {
                return base + '/snapshot';
            }

            /* ── Acciones ────────────────────────────── */
            function startLive() {
                overlay.classList.remove('hidden');
                setBadge('lvm-connecting', '{{ trans('front.connecting') }}…');
                errEl.style.display = 'none';
                btnStart.disabled = true;
                btnStop.disabled = false;

                post(urlLiveStart(), {
                        channel: channel,
                        stream: 1
                    })
                    .then(function(res) {
                        setTimeout(function() {
                            if (res && res.hls_url) {
                                loadStreamDirect(res.hls_url);
                            } else {
                                loadStream(channel);
                            }
                        }, 3000);
                    })
                    .catch(function() {
                        setTimeout(function() {
                            loadStream(channel);
                        }, 2000);
                    });
            }

            function loadStreamDirect(url) {
                ensureHls(function() {
                    if (Hls.isSupported()) {
                        destroyHls();
                        hls = new Hls({
                            enableWorker: false
                        });
                        hls.loadSource(url);
                        hls.attachMedia(video);
                        hls.on(Hls.Events.MANIFEST_PARSED, function() {
                            overlay.classList.add('hidden');
                            setBadge('lvm-live', 'LIVE');
                            video.play().catch(function() {});
                        });
                        hls.on(Hls.Events.ERROR, function(e, data) {
                            if (data.fatal) {
                                loadStream(channel);
                            }
                        });
                    } else {
                        loadStream(channel);
                    }
                });
            }

            function stopLive() {
                destroyHls();
                setBadge('lvm-offline', '{{ trans('front.offline') }}');
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
                    btnAudio.innerHTML = '<i class="fa fa-stop"></i> {{ trans('front.audio_stop') }}';
                    post(urlAudioStart(), {
                        channel: channel,
                        mode: 0
                    }).catch(function() {});
                } else {
                    btnAudio.innerHTML = '<i class="fa fa-microphone"></i> {{ trans('front.audio_listen') }}';
                    post(urlAudioStop(), {
                        channel: channel
                    }).catch(function() {});
                }
            }

            function takeSnapshot() {
                if (!btnSnap) return;
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

            /* ── Tabs ────────────────────────────────── */
            document.querySelectorAll('.lvm-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.lvm-tab').forEach(function(t) {
                        t.classList.remove('active');
                    });
                    tab.classList.add('active');
                    channel = parseInt(tab.dataset.channel, 10);

                    if (!btnStop.disabled) {
                        overlay.classList.remove('hidden');
                        post(urlLiveStart(), {
                                channel: channel,
                                stream: 1
                            })
                            .then(function(res) {
                                setTimeout(function() {
                                    if (res && res.hls_url) {
                                        loadStreamDirect(res.hls_url);
                                    } else {
                                        loadStream(channel);
                                    }
                                }, 2000);
                            })
                            .catch(function() {
                                loadStream(channel);
                            });
                    }
                });
            });

            /* ── Botones ─────────────────────────────── */
            btnStart.addEventListener('click', startLive);
            btnStop.addEventListener('click', stopLive);
            btnAudio.addEventListener('click', toggleAudio);
            if (btnSnap) {
                btnSnap.addEventListener('click', takeSnapshot);
            }

            /* ── Limpiar al cerrar modal ─────────────── */
            var $modal = document.getElementById('lvm-video').closest('.modal');
            if ($modal) {
                $modal.addEventListener('hidden.bs.modal', cleanup);
                // Bootstrap 2 / jQuery UI fallback
                $($modal).on('hidden', cleanup);
            }

            function cleanup() {
                destroyHls();
                if (audioActive) {
                    post(urlAudioStop(), {
                        channel: channel
                    }).catch(function() {});
                    audioActive = false;
                }
                if (!btnStop.disabled) {
                    post(urlLiveStop(), {
                        channel: channel,
                        stream: 1
                    }).catch(function() {});
                    btnStop.disabled = true;
                }
            }

            /* ── Inicio automático ───────────────────── */
            startLive();

        })();
    </script>

@stop
