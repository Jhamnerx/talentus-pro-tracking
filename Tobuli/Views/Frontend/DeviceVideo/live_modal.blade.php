@extends('Frontend.Layouts.modal_full')

@section('title')
    <i class="icon play"></i> {{ $device->name }}
    <span id="lvm-badge" style="margin-left:10px;padding:3px 10px;border-radius:12px;font-size:.75rem;background:#e67e22;color:#fff;vertical-align:middle;">
        {{ trans('front.connecting') }}...
    </span>
@stop

@section('modal_class')
    modal-video
@stop

@section('buttons')
@stop

@section('body')

<style>
    .modal-video .modal-dialog { max-width: 960px; }
    #lvm-video { width: 100%; max-height: 500px; display: block; background: #000; }
    .lvm-tabs { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
    .lvm-tab { padding: 5px 14px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; background: #f5f5f5; color: #555; font-size: .82rem; }
    .lvm-tab.active { background: #1a73e8; border-color: #1a73e8; color: #fff; }
    .lvm-player { position: relative; background: #000; border-radius: 4px; overflow: hidden; }
    .lvm-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.72); display: flex; align-items: center; justify-content: center; color: #eee; font-size: .9rem; }
    .lvm-overlay.hidden { display: none; }
    .lvm-spinner { width: 32px; height: 32px; border: 4px solid #555; border-top-color: #fff; border-radius: 50%; animation: lvm-spin .8s linear infinite; margin-right: 10px; }
    @keyframes lvm-spin { to { transform: rotate(360deg); } }
    .lvm-controls { display: flex; gap: 8px; margin-top: 10px; }
    .lvm-btn { padding: 6px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: .85rem; }
    .lvm-btn:disabled { opacity: .4; cursor: default; }
    .lvm-error { color: #c0392b; margin-top: 8px; font-size: .85rem; display: none; }
</style>

<div class="lvm-tabs">
    @for ($ch = 1; $ch <= 4; $ch++)
        <button class="lvm-tab {{ $ch === 1 ? 'active' : '' }}" data-channel="{{ $ch }}">
            {{ trans('front.channel') }} {{ $ch }}
        </button>
    @endfor
</div>

<div class="lvm-player">
    <video id="lvm-video" controls muted playsinline></video>
    <div class="lvm-overlay" id="lvm-overlay">
        <div class="lvm-spinner"></div>
        <span>{{ trans('front.loading_stream') }}...</span>
    </div>
</div>

<div class="lvm-controls">
    <button class="lvm-btn" style="background:#27ae60;color:#fff" id="lvm-btnStart" disabled>
        <i class="fa fa-play"></i> {{ trans('front.video_start') }}
    </button>
    <button class="lvm-btn" style="background:#c0392b;color:#fff" id="lvm-btnStop" disabled>
        <i class="fa fa-stop"></i> {{ trans('front.video_stop') }}
    </button>
</div>

<p class="lvm-error" id="lvm-error"></p>

<script>
(function () {
    var deviceId = {{ $device->id }};
    var channel  = 1;
    var hls      = null;
    var video    = document.getElementById('lvm-video');
    var overlay  = document.getElementById('lvm-overlay');
    var badge    = document.getElementById('lvm-badge');
    var errEl    = document.getElementById('lvm-error');
    var btnStart = document.getElementById('lvm-btnStart');
    var btnStop  = document.getElementById('lvm-btnStop');

    function setBadge(txt, color) {
        badge.textContent = txt;
        badge.style.background = color;
    }

    function showError(msg) {
        errEl.textContent = msg;
        errEl.style.display = 'block';
        setBadge('Error', '#c0392b');
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
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json(); });
    }

    function destroyHls() {
        if (hls) { hls.destroy(); hls = null; }
        video.src = '';
    }

    function attachHls(url) {
        if (Hls.isSupported()) {
            hls = new Hls({ enableWorker: false });
            hls.loadSource(url);
            hls.attachMedia(video);
            hls.on(Hls.Events.MANIFEST_PARSED, function () {
                overlay.classList.add('hidden');
                setBadge('LIVE', '#27ae60');
                video.play().catch(function () {});
            });
            hls.on(Hls.Events.ERROR, function (e, data) {
                if (data.fatal) { showError('HLS error: ' + data.type); }
            });
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = url;
            overlay.classList.add('hidden');
            setBadge('LIVE', '#27ae60');
            video.play().catch(function () {});
        } else {
            showError('{{ trans('front.browser_not_support_html_video') }}');
        }
    }

    function loadHls(url) {
        destroyHls();
        if (typeof Hls === 'undefined') {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.4.14/dist/hls.min.js';
            s.onload = function () { attachHls(url); };
            s.onerror = function () { showError('{{ trans('front.browser_not_support_html_video') }}'); };
            document.head.appendChild(s);
        } else {
            attachHls(url);
        }
    }

    function streamUrl() {
        return '/device_video/' + deviceId + '/stream/live.m3u8?channel=' + channel;
    }

    function startLive() {
        overlay.classList.remove('hidden');
        errEl.style.display = 'none';
        setBadge('{{ trans('front.connecting') }}...', '#e67e22');
        btnStart.disabled = true;
        btnStop.disabled  = false;

        post('/device_video/' + deviceId + '/live/start', { channel: channel })
            .then(function () {
                setTimeout(function () { loadHls(streamUrl()); }, 3000);
            })
            .catch(function () {
                setTimeout(function () { loadHls(streamUrl()); }, 2000);
            });
    }

    function stopLive() {
        destroyHls();
        overlay.classList.remove('hidden');
        setBadge('{{ trans('front.offline') }}', '#555');
        btnStart.disabled = false;
        btnStop.disabled  = true;
        post('/device_video/' + deviceId + '/live/stop', { channel: channel }).catch(function () {});
    }

    document.querySelectorAll('.lvm-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.lvm-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            channel = parseInt(tab.dataset.channel, 10);
            if (!btnStop.disabled) {
                overlay.classList.remove('hidden');
                post('/device_video/' + deviceId + '/live/start', { channel: channel })
                    .then(function () {
                        setTimeout(function () { loadHls(streamUrl()); }, 2000);
                    })
                    .catch(function () { loadHls(streamUrl()); });
            }
        });
    });

    btnStart.addEventListener('click', startLive);
    btnStop.addEventListener('click', stopLive);

    var modal = video.closest('.modal') || video.closest('[id]');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', function () {
            destroyHls();
            if (!btnStop.disabled) {
                post('/device_video/' + deviceId + '/live/stop', { channel: channel }).catch(function () {});
            }
        });
        $(modal).on('hidden', function () {
            destroyHls();
            if (!btnStop.disabled) {
                post('/device_video/' + deviceId + '/live/stop', { channel: channel }).catch(function () {});
            }
        });
    }

    startLive();
}());
</script>

@stop