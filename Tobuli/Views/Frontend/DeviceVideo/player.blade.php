<!DOCTYPE html>
<html lang="{{ Language::iso() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $playback ? trans('front.video_recording') : trans('front.video_live') }} #{{ $channel }} - {{ $device->name }}</title>
    <style>
        html, body { margin: 0; height: 100%; background: #000; color: #fff; font: 13px/1.4 Arial, Helvetica, sans-serif; overflow: hidden; }
        .vp { position: relative; width: 100%; height: 100%; }
        .vp video { width: 100%; height: 100%; object-fit: contain; background: #000; display: block; }
        .vp-head, .vp-bar { position: absolute; left: 0; right: 0; display: flex; align-items: center; gap: 8px; padding: 6px 8px; background: rgba(0, 0, 0, .55); }
        .vp-head { top: 0; flex-wrap: wrap; }
        .vp-bar { bottom: 0; justify-content: flex-end; opacity: 0; transition: opacity .2s; }
        .vp:hover .vp-bar { opacity: 1; }
        .vp select, .vp button { background: rgba(255, 255, 255, .12); color: #fff; border: 1px solid rgba(255, 255, 255, .25); border-radius: 3px; padding: 3px 8px; font-size: 12px; cursor: pointer; }
        .vp select option { color: #000; }
        .vp button[aria-pressed="true"] { background: #1f6fd1; border-color: #1f6fd1; }
        .vp-live { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; }
        .vp-dot { width: 8px; height: 8px; border-radius: 50%; background: #f0ad4e; }
        .vp-live.is-live .vp-dot { background: #d9534f; }
        .vp-live.is-error .vp-dot { background: #777; }
        .vp-spacer { flex: 1; }
        .vp-status { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 85%; text-align: center; }
        .vp-status button { margin-top: 10px; }
        .vp-note { position: absolute; left: 8px; bottom: 40px; font-size: 11px; background: rgba(0, 0, 0, .6); padding: 2px 6px; border-radius: 3px; }
        .hidden { display: none !important; }
    </style>
</head>
<body>
<div class="vp" id="vp">
    <video id="vp-video" muted playsinline autoplay></video>

    <div class="vp-head">
        @if (count($channels) > 1)
            <select id="vp-channel" title="{{ trans('front.channel') }}">
                @foreach ($channels as $ch)
                    <option value="{{ $ch }}" {{ $ch == $channel ? 'selected' : '' }}>
                        {{ trans('front.channel') }} {{ $ch }}{{ !empty($roles[$ch]) ? ' · '.trans('front.video_role_'.$roles[$ch]) : '' }}
                    </option>
                @endforeach
            </select>
        @else
            <span>{{ trans('front.channel') }} {{ $channel }}{{ !empty($roles[$channel]) ? ' · '.trans('front.video_role_'.$roles[$channel]) : '' }}</span>
        @endif
        @if ($playback)
            <span>{{ trans('front.video_recording') }} · {{ $playback['title'] }}</span>
        @endif
        <span class="vp-spacer"></span>
        <span class="vp-live" id="vp-live"><i class="vp-dot"></i><span id="vp-live-text">{{ trans('front.video_connecting') }}</span></span>
    </div>

    <div class="vp-status" id="vp-status">
        <div id="vp-status-text">{{ trans('front.video_starting') }}</div>
        <button type="button" id="vp-retry" class="hidden">{{ trans('front.video_retry') }}</button>
    </div>

    <div class="vp-note hidden" id="vp-note"></div>

    <div class="vp-bar">
        @if ($playback)
            <button type="button" id="vp-pause" data-label-pause="{{ trans('front.video_pause') }}" data-label-resume="{{ trans('front.video_resume') }}">{{ trans('front.video_pause') }}</button>
        @else
            <select id="vp-quality" title="HD / SD">
                <option value="hd">HD</option>
                <option value="sd">SD</option>
            </select>
            @if ($realtime)
                <select id="vp-mode" title="{{ trans('front.video_mode') }}">
                    <option value="hls">{{ trans('front.video_mode_compatible') }}</option>
                    <option value="flv">{{ trans('front.video_mode_realtime') }}</option>
                </select>
                <button type="button" id="vp-audio" aria-pressed="false">{{ trans('front.video_audio') }}</button>
            @endif
            @if ($talk)
                <button type="button" id="vp-talk" aria-pressed="false">{{ trans('front.video_talk') }}</button>
            @endif
        @endif
        <button type="button" id="vp-snap">{{ trans('front.video_snapshot') }}</button>
        <button type="button" id="vp-fullscreen">{{ trans('front.video_fullscreen') }}</button>
    </div>
</div>

<script src="{{ asset_resource('assets/plugins/hls/hls.min.js') }}"></script>
@if ($realtime)
    <script src="{{ asset_resource('assets/plugins/mpegts/mpegts.min.js') }}"></script>
    <script src="{{ asset_resource('assets/js/video/g726.js') }}"></script>
@endif
@if ($talk)
    <script src="{{ asset_resource('assets/js/video/talk.js') }}"></script>
@endif
<script>
(function () {
    'use strict';

    var cfg = {
        deviceId: {{ (int)$device->id }},
        deviceName: @json($device->name),
        viewer: @json($viewer),
        channel: {{ (int)$channel }},
        playback: @json($playback),
        realtime: @json($realtime),
        audio: @json($audio),
        talk: @json($talk),
        token: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        urls: {
            start: @json(route('devices.video.start', $device->id)),
            ping: @json(route('devices.video.ping', $device->id)),
            stop: @json(route('devices.video.stop', $device->id)),
            status: @json(route('devices.video.status', $device->id)),
            playbackStart: @json(route('devices.video.playback.start', $device->id)),
            playbackControl: @json(route('devices.video.playback.control', $device->id)),
            stream: @json(url('devices/video/'.$device->id.'/stream')) + '/'
        },
        lang: {
            starting: @json(trans('front.video_starting')),
            waiting: @json(trans('front.video_waiting_device')),
            slow: @json(trans('front.video_slow')),
            timeout: @json(trans('front.video_timeout')),
            live: @json(trans('front.video_live')),
            connecting: @json(trans('front.video_connecting')),
            reconnecting: @json(trans('front.video_reconnecting')),
            codec: @json(trans('front.video_err_codec')),
            codecRealtime: @json(trans('front.video_codec_fallback')),
            platform: @json(trans('front.video_err_platform')),
            unsupported: @json(trans('front.video_err_browser')),
            error: @json(trans('front.video_error')),
            rejected: @json(trans('front.video_err_device_rejected')),
            noImage: @json(trans('front.video_err_device_no_image')),
            finished: @json(trans('front.video_playback_finished')),
            audioCodec: @json(trans('front.video_audio_codec')),
            audioFailed: @json(trans('front.video_audio_failed')),
            audioAacRealtime: @json(trans('front.video_audio_aac_realtime')),
            talkWaiting: @json(trans('front.video_talk_waiting')),
            talkLive: @json(trans('front.video_talk_live')),
            talkMic: @json(trans('front.video_talk_mic')),
            talkExpired: @json(trans('front.video_talk_expired')),
            talkFailed: @json(trans('front.video_talk_failed'))
        }
    };

    var WAIT_SLOW_MS = 20000, WAIT_TIMEOUT_MS = 60000, STALL_MS = 20000, PING_MS = 30000;

    var video = document.getElementById('vp-video'),
        statusBox = document.getElementById('vp-status'),
        statusText = document.getElementById('vp-status-text'),
        retryBtn = document.getElementById('vp-retry'),
        note = document.getElementById('vp-note'),
        live = document.getElementById('vp-live'),
        liveText = document.getElementById('vp-live-text'),
        hls = null, flv = null, session = 0, stopped = false, requestSeq = 0,
        waitTimer = null, pingTimer = null, stallTimer = null,
        lastTime = 0, lastProgressAt = 0,
        mode = 'hls', quality = 'hd', audio = null, audioOn = false, flvAudio = false,
        talkSession = null, talkTimer = null, lastG726Bits = 0;

    var AAC_TYPES = [19, 24];

    function pref(key, value) {
        try {
            if (value === undefined) return localStorage.getItem('device_video_' + key);
            localStorage.setItem('device_video_' + key, value);
        } catch (e) {}
        return null;
    }

    function realtimeUrls() {
        return cfg.realtime && cfg.realtime[cfg.channel] ? cfg.realtime[cfg.channel] : null;
    }

    function canRealtime() {
        return !cfg.playback && realtimeUrls() && window.mpegts && mpegts.isSupported();
    }

    function setStatus(text, state, retry) {
        statusText.textContent = text || '';
        statusBox.classList.toggle('hidden', !text);
        retryBtn.classList.toggle('hidden', !retry);
        live.classList.toggle('is-live', state === 'live');
        live.classList.toggle('is-error', state === 'error');
        liveText.textContent = state === 'live' ? cfg.lang.live : (state === 'error' ? cfg.lang.error : cfg.lang.connecting);
    }

    function setNote(text) {
        note.textContent = text || '';
        note.classList.toggle('hidden', !text);
    }

    function post(url, data, keepalive) {
        var body = new FormData();
        body.append('_token', cfg.token);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });

        if (keepalive && navigator.sendBeacon)
            return navigator.sendBeacon(url, body);

        return fetch(url, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (r) { return r.ok ? r.json() : {ok: false}; });
    }

    function playlistUrl(channel) {
        return cfg.urls.stream + channel + (cfg.playback ? '/history.m3u8' : '/live.m3u8');
    }

    function requestStart() {
        if (cfg.playback)
            return post(cfg.urls.playbackStart, {channel: cfg.channel, from: cfg.playback.from, to: cfg.playback.to});

        return post(cfg.urls.start, {
            channel: cfg.channel, viewer: cfg.viewer, quality: quality, audio: audioOn ? 1 : 0
        });
    }

    function fetchStatus() {
        var url = cfg.urls.status + '?channel=' + cfg.channel + '&type=' + (cfg.playback ? 'history' : 'live');

        return fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; });
    }

    function clearTimers() {
        clearTimeout(waitTimer);
        clearInterval(stallTimer);
        waitTimer = stallTimer = null;
    }

    function destroyPlayers() {
        if (hls) {
            hls.destroy();
            hls = null;
        }
        if (flv) {
            try {
                flv.pause();
                flv.unload();
                flv.detachMediaElement();
                flv.destroy();
            } catch (e) {}
            flv = null;
        }
        video.removeAttribute('src');
    }

    function start() {
        var current = ++session;

        clearTimers();
        destroyPlayers();
        stopped = false;
        setStatus(cfg.lang.starting, 'connecting', false);

        // Los tokens del modo tiempo real se emiten con la página; al vencer se recarga para obtener nuevos.
        var urls = realtimeUrls();
        if (mode === 'flv' && urls && urls.expires * 1000 < Date.now() + 30000) {
            window.location.reload();
            return;
        }

        requestStart().then(function (res) {
            if (current !== session) return;

            if (!res || res.ok === false)
                return setStatus((res && res.error) || cfg.lang.platform, 'error', true);

            requestSeq = res.seq || 0;
            setStatus(cfg.lang.waiting, 'connecting', false);

            if (mode === 'flv' && canRealtime()) {
                playFlv(current);
                watchDevice(current, Date.now(), 0);
            } else {
                waitForSegments(current, Date.now(), 0);
            }
        }).catch(function () {
            if (current === session) setStatus(cfg.lang.platform, 'error', true);
        });

        clearInterval(pingTimer);
        if (cfg.playback) return;

        pingTimer = setInterval(function () {
            if (!stopped) post(cfg.urls.ping, {channel: cfg.channel, viewer: cfg.viewer});
        }, PING_MS);
    }

    /**
     * Revisa el estado del tracker: rechazo del equipo, H.265 y "conectado sin imagen".
     * @return true si el estado terminó la espera con un error
     */
    function handleStatus(status, elapsed) {
        if (status && status.rejectedTime && status.rejectedTime >= requestSeq) {
            setStatus(cfg.lang.rejected, 'error', true);
            return true;
        }

        if (status && status.connected && status.codec === 'h265') {
            if (mode === 'flv') {
                // FLV no lleva HEVC: se sigue en modo compatible.
                setMode('hls');
                setNote(cfg.lang.codecRealtime);
                start();
                return true;
            }
            // HLS con H.265 solo si el navegador lo decodifica (en general por hardware).
            if (!canPlayHevc()) {
                setStatus(cfg.lang.codec, 'error', false);
                return true;
            }
        }

        if (status && status.connected && !status.segments && elapsed > WAIT_SLOW_MS)
            setStatus(cfg.lang.noImage, 'connecting', false);
        else if (elapsed > WAIT_SLOW_MS)
            setStatus(cfg.lang.slow, 'connecting', false);

        return false;
    }

    // El tracker responde un playlist vacío hasta que el equipo envía el primer GOP completo.
    function waitForSegments(current, startedAt, attempt) {
        Promise.all([
            fetch(playlistUrl(cfg.channel), {credentials: 'same-origin', cache: 'no-store'})
                .then(function (r) { return r.ok ? r.text() : ''; })
                .catch(function () { return ''; }),
            attempt % 2 ? fetchStatus() : Promise.resolve(null)
        ]).then(function (results) {
            var text = results[0], status = results[1], elapsed = Date.now() - startedAt;

            if (current !== session) return;

            if (text.indexOf('#EXTINF') !== -1)
                return playHls(current);

            if (elapsed > WAIT_TIMEOUT_MS)
                return setStatus(cfg.lang.timeout, 'error', true);

            if (handleStatus(status, elapsed)) return;

            waitTimer = setTimeout(function () { waitForSegments(current, startedAt, attempt + 1); }, 1500);
        });
    }

    // En modo tiempo real el FLV ya está conectado; solo se vigila el estado hasta que llega imagen.
    function watchDevice(current, startedAt, attempt) {
        if (current !== session || lastTime > 0) return;

        fetchStatus().then(function (status) {
            var elapsed = Date.now() - startedAt;

            if (current !== session || lastTime > 0) return;

            if (elapsed > WAIT_TIMEOUT_MS) {
                destroyPlayers();
                return setStatus(cfg.lang.timeout, 'error', true);
            }

            if (handleStatus(status, elapsed)) {
                if (mode === 'flv') destroyPlayers();
                return;
            }

            waitTimer = setTimeout(function () { watchDevice(current, startedAt, attempt + 1); }, 3000);
        });
    }

    function canPlayHevc() {
        var Source = window.ManagedMediaSource || window.MediaSource, codecs = [
            'hvc1.1.6.L93.B0', 'hev1.1.6.L93.B0', 'hvc1.1.6.L120.90', 'hev1.1.6.L120.90', 'hvc1.1.6.L150.90'
        ];

        for (var i = 0; i < codecs.length; i++) {
            var type = 'video/mp4; codecs="' + codecs[i] + '"';
            if (Source && Source.isTypeSupported ? Source.isTypeSupported(type) : video.canPlayType(type))
                return true;
        }
        return false;
    }

    function playHls(current) {
        if (!window.Hls || !Hls.isSupported()) {
            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = playlistUrl(cfg.channel);
                return watchStall(current);
            }
            return setStatus(cfg.lang.unsupported, 'error', false);
        }

        hls = new Hls({lowLatencyMode: false, liveSyncDurationCount: 2, manifestLoadingMaxRetry: 6, levelLoadingMaxRetry: 6});
        hls.loadSource(playlistUrl(cfg.channel));
        hls.attachMedia(video);

        hls.on(Hls.Events.MANIFEST_PARSED, function () {
            var p = video.play();
            if (p && p.catch) p.catch(function () {});
        });

        hls.on(Hls.Events.ERROR, function (event, data) {
            if (current !== session || !data.fatal) return;

            if (data.details === Hls.ErrorDetails.MANIFEST_INCOMPATIBLE_CODECS_ERROR
                || data.details === Hls.ErrorDetails.BUFFER_ADD_CODEC_ERROR
                || data.details === Hls.ErrorDetails.BUFFER_INCOMPATIBLE_CODECS_ERROR) {
                destroyPlayers();
                return setStatus(cfg.lang.codec, 'error', false);
            }

            if (data.type === Hls.ErrorTypes.MEDIA_ERROR)
                return hls.recoverMediaError();

            reconnect(current);
        });

        watchStall(current);
    }

    function playFlv(current) {
        // Con audio AAC el tracker agrega la pista al FLV (?audio=1) y mpegts.js la reproduce sincronizada.
        flv = mpegts.createPlayer({
            type: 'flv', isLive: true, hasAudio: flvAudio, url: realtimeUrls().flv + (flvAudio ? '&audio=1' : '')
        }, {
            enableStashBuffer: false,
            stashInitialSize: 128,
            liveBufferLatencyChasing: true,
            liveBufferLatencyMaxLatency: 1.5,
            liveBufferLatencyMinRemain: 0.3,
            lazyLoad: false
        });
        flv.attachMediaElement(video);
        flv.load();

        var p = flv.play();
        if (p && p.catch) p.catch(function () {});

        flv.on(mpegts.Events.ERROR, function () {
            if (current === session) reconnect(current);
        });

        watchStall(current);
    }

    function watchStall(current) {
        lastTime = 0;
        lastProgressAt = Date.now();

        clearInterval(stallTimer);
        stallTimer = setInterval(function () {
            if (current !== session) return;

            if (video.paused && cfg.playback) {
                lastProgressAt = Date.now();
                return;
            }

            if (video.currentTime > lastTime + 0.1) {
                lastTime = video.currentTime;
                lastProgressAt = Date.now();
                return setStatus('', 'live', false);
            }

            // En tiempo real, antes de la primera imagen manda la espera del estado del equipo (watchDevice).
            if (!lastTime && mode === 'flv') return;

            if (Date.now() - lastProgressAt > STALL_MS) {
                // En una grabación, quedarse sin video tras haber reproducido es el final del archivo.
                if (cfg.playback && lastTime > 0) {
                    clearTimers();
                    destroyPlayers();
                    return setStatus(cfg.lang.finished, 'error', true);
                }
                reconnect(current);
            }
        }, 2000);
    }

    // El equipo cortó o la red falló: se vuelve a pedir el video (videoStart es idempotente).
    function reconnect(current) {
        if (current !== session) return;

        setStatus(cfg.lang.reconnecting, 'connecting', false);
        start();
    }

    function stop(keepalive) {
        if (stopped) return;

        stopped = true;
        session++;
        clearTimers();
        clearInterval(pingTimer);
        destroyPlayers();
        stopAudio();
        stopTalk(keepalive);

        if (cfg.playback)
            post(cfg.urls.playbackControl, {channel: cfg.channel, action: 2}, keepalive);
        else
            post(cfg.urls.stop, {channel: cfg.channel, viewer: cfg.viewer}, keepalive);
    }

    function setMode(value) {
        mode = value === 'flv' && canRealtime() ? 'flv' : 'hls';
        pref('mode', mode);

        var select = document.getElementById('vp-mode');
        if (select) select.value = mode;
    }

    // ---- Audio en vivo: tramas G.711 decodificadas en el navegador ----

    var ALAW = new Float32Array(256), ULAW = new Float32Array(256);
    (function () {
        for (var i = 0; i < 256; i++) {
            var a = i ^ 0x55, sign = a & 0x80, exp = (a >> 4) & 7, mant = a & 0x0F, v;
            v = exp ? ((mant << 4) + 0x108) << (exp - 1) : (mant << 4) + 8;
            ALAW[i] = (sign ? v : -v) / 32768;

            var u = ~i & 0xFF, s = u & 0x80, e = (u >> 4) & 7, m = u & 0x0F;
            var w = (((m << 3) + 0x84) << e) - 0x84;
            ULAW[i] = (s ? -w : w) / 32768;
        }
    })();

    function startAudio(url) {
        url = url || (realtimeUrls() && realtimeUrls().audio);
        if (!url) return;

        var context = new (window.AudioContext || window.webkitAudioContext)(),
            controller = window.AbortController ? new AbortController() : null,
            pending = new Uint8Array(0),
            nextTime = 0,
            g726 = null,
            g726Bits = 0;

        audio = {context: context, controller: controller};

        fetch(url, {cache: 'no-store', signal: controller ? controller.signal : undefined}).then(function (response) {
            if (!response.ok || !response.body) throw new Error('audio ' + response.status);

            var reader = response.body.getReader();

            function read() {
                return reader.read().then(function (chunk) {
                    if (chunk.done || !audio) return;

                    var merged = new Uint8Array(pending.length + chunk.value.length);
                    merged.set(pending);
                    merged.set(chunk.value, pending.length);

                    var offset = 0;
                    while (merged.length - offset >= 3) {
                        var type = merged[offset], length = (merged[offset + 1] << 8) | merged[offset + 2];
                        if (merged.length - offset - 3 < length) break;
                        playFrame(type, merged.subarray(offset + 3, offset + 3 + length));
                        offset += 3 + length;
                    }
                    pending = merged.slice(offset);

                    return read();
                });
            }

            return read();
        }).catch(function () {
            if (audio) {
                setNote(cfg.lang.audioFailed);
                toggleAudio(false);
            }
        });

        // JT1078: 6 = G.711A, 7 = G.711U, 8 = G.726 (8 kHz mono); AAC (19/24) viaja dentro del FLV.
        function playFrame(type, data) {
            if (AAC_TYPES.indexOf(type) !== -1)
                return enableFlvAudio();

            var buffer, samples, i;

            if (type === 6 || type === 7) {
                var table = type === 6 ? ALAW : ULAW;
                buffer = context.createBuffer(1, data.length, 8000);
                samples = buffer.getChannelData(0);
                for (i = 0; i < data.length; i++) samples[i] = table[data[i]];
            } else if (type === 8 && window.G726) {
                // G.726 guarda estado entre tramas: un decodificador por sesión, salvo que cambie la tasa.
                var bits = G726.bitsForFrame(data.length, cfg.audio && cfg.audio.frameSamples);
                if (!g726 || bits !== g726Bits) {
                    g726 = G726.createDecoder(bits);
                    g726Bits = bits;
                    lastG726Bits = bits;
                }
                var pcm = g726.decode(data, (cfg.audio && cfg.audio.g726Packing) || 'rfc3551');
                if (!pcm.length) return;
                buffer = context.createBuffer(1, pcm.length, 8000);
                samples = buffer.getChannelData(0);
                for (i = 0; i < pcm.length; i++) samples[i] = pcm[i] / 32768;
            } else {
                setNote(cfg.lang.audioCodec.replace(':codec', type));
                return;
            }

            var source = context.createBufferSource();
            source.buffer = buffer;
            source.connect(context.destination);

            var now = context.currentTime;
            if (nextTime < now || nextTime - now > 1) nextTime = now + 0.08;
            source.start(nextTime);
            nextTime += buffer.duration;
        }
    }

    function stopAudio() {
        if (!audio) return;

        try { audio.controller && audio.controller.abort(); } catch (e) {}
        try { audio.context.close(); } catch (e) {}
        audio = null;
    }

    // AAC: se deja de leer live.audio y se recarga el FLV con la pista de audio.
    function enableFlvAudio() {
        stopAudio();

        if (flvAudio) return;

        if (!canRealtime()) {
            setNote(cfg.lang.audioAacRealtime);
            return;
        }

        flvAudio = true;
        video.muted = false;
        if (mode !== 'flv') setMode('flv');
        start();
    }

    function toggleAudio(enable) {
        var button = document.getElementById('vp-audio');

        audioOn = enable;
        if (button) button.setAttribute('aria-pressed', enable ? 'true' : 'false');

        if (enable) {
            if (cfg.audio && AAC_TYPES.indexOf(cfg.audio.codec) !== -1) enableFlvAudio();
            else startAudio();
        } else {
            stopAudio();
            if (flvAudio) {
                flvAudio = false;
                video.muted = true;
                if (mode === 'flv') start();
            }
        }

        // El equipo solo envía audio si se le pide con videoStart (tipo de dato audio y video).
        if (!stopped) post(cfg.urls.start, {channel: cfg.channel, viewer: cfg.viewer, quality: quality, audio: enable ? 1 : 0});
    }

    // ---- Conversación: micrófono → parlante del equipo; mientras tanto se escucha el canal de conversación ----

    function startTalk() {
        var button = document.getElementById('vp-talk');

        button.disabled = true;

        post(cfg.talk.start, {viewer: cfg.viewer}).then(function (res) {
            if (!res || res.ok === false) {
                setNote((res && res.error) || cfg.lang.platform);
                return;
            }

            talkSession = new DeviceTalk.Talk({
                talkUrl: cfg.talk.urls.talk,
                codec: cfg.talk.codec,
                frameSamples: cfg.talk.frameSamples,
                hisilicon: cfg.talk.hisilicon,
                g726Bits: lastG726Bits || cfg.talk.g726Bits,
                g726Packing: cfg.audio && cfg.audio.g726Packing,
                onState: talkState
            });

            return talkSession.start().then(function () {
                button.setAttribute('aria-pressed', 'true');
                setNote(cfg.lang.talkWaiting);

                stopAudio();
                startAudio(cfg.talk.urls.audio);

                talkTimer = setInterval(function () {
                    post(cfg.talk.ping, {viewer: cfg.viewer});
                }, 15000);
            }, function () {
                setNote(cfg.lang.talkMic);
                stopTalk(false);
            });
        }).catch(function () {
            setNote(cfg.lang.talkFailed);
        }).then(function () {
            button.disabled = false;
        });
    }

    function talkState(state) {
        if (state === 'live') setNote(cfg.lang.talkLive);
        else if (state === 'waiting') setNote(cfg.lang.talkWaiting);
        else if (state === 'expired') {
            setNote(cfg.lang.talkExpired);
            stopTalk(false);
        } else setNote(cfg.lang.talkFailed);
    }

    function stopTalk(keepalive) {
        if (!talkSession && !talkTimer) return;

        if (talkSession) talkSession.stop();
        talkSession = null;
        clearInterval(talkTimer);
        talkTimer = null;

        var button = document.getElementById('vp-talk');
        if (button) button.setAttribute('aria-pressed', 'false');

        post(cfg.talk.stop, {viewer: cfg.viewer}, keepalive);

        // Vuelve al audio normal del canal si estaba activo.
        stopAudio();
        if (audioOn && !flvAudio && !stopped) startAudio();
        if (!keepalive) setNote('');
    }

    // ---- Controles ----

    retryBtn.addEventListener('click', function () { setNote(''); start(); });

    var channelSelect = document.getElementById('vp-channel');
    if (channelSelect) {
        channelSelect.addEventListener('change', function () {
            var hadAudio = audioOn;

            stop(false);
            cfg.channel = parseInt(channelSelect.value, 10);
            document.title = cfg.lang.live + ' #' + cfg.channel + ' - ' + cfg.deviceName;
            setNote('');
            start();
            if (hadAudio) toggleAudio(true);
        });
    }

    var qualitySelect = document.getElementById('vp-quality');
    if (qualitySelect) {
        quality = pref('quality') === 'sd' ? 'sd' : 'hd';
        qualitySelect.value = quality;
        qualitySelect.addEventListener('change', function () {
            quality = qualitySelect.value;
            pref('quality', quality);
            start();
        });
    }

    var modeSelect = document.getElementById('vp-mode');
    if (modeSelect) {
        setMode(pref('mode') || 'flv');
        modeSelect.addEventListener('change', function () {
            setMode(modeSelect.value);
            setNote('');
            start();
        });
    }

    var audioBtn = document.getElementById('vp-audio');
    if (audioBtn) {
        audioBtn.addEventListener('click', function () { toggleAudio(!audioOn); });
    }

    var talkBtn = document.getElementById('vp-talk');
    if (talkBtn) {
        talkBtn.addEventListener('click', function () {
            if (talkSession) stopTalk(false); else startTalk();
        });
    }

    var pauseBtn = document.getElementById('vp-pause');
    if (pauseBtn) {
        pauseBtn.addEventListener('click', function () {
            var pausing = !video.paused;

            if (pausing) video.pause();
            else video.play();

            pauseBtn.textContent = pauseBtn.getAttribute(pausing ? 'data-label-resume' : 'data-label-pause');
            post(cfg.urls.playbackControl, {channel: cfg.channel, action: pausing ? 1 : 0});
        });
    }

    document.getElementById('vp-fullscreen').addEventListener('click', function () {
        var el = document.getElementById('vp');
        if (document.fullscreenElement) document.exitFullscreen();
        else if (el.requestFullscreen) el.requestFullscreen();
    });

    document.getElementById('vp-snap').addEventListener('click', function () {
        if (!video.videoWidth) return;
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/jpeg', 0.92);
        a.download = cfg.deviceName.replace(/[^\w-]+/g, '_') + '_ch' + cfg.channel + '_' + Date.now() + '.jpg';
        a.click();
    });

    window.addEventListener('pagehide', function () { stop(true); });

    // Permite que la ventana padre cierre el stream antes de quitar el iframe.
    window.videoPlayer = {stop: function () { stop(true); }};

    start();
})();
</script>
</body>
</html>
