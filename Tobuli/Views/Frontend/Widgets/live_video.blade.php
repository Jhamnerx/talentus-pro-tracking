<div class="widget widget-live-video" id="widget-live-video">
    <div class="widget-heading">
        <div class="widget-title">
            <i class="icon play"></i> {{ trans('front.live_video') }}
        </div>
    </div>
    <div class="widget-body text-center" style="padding:15px;">
        <a href="javascript:" id="widget-live-video-btn" class="btn btn-primary"
            style="display:none;">
            <i class="fa fa-play"></i> {{ trans('front.live_video') }}
        </a>
        <a href="javascript:" id="widget-playback-btn" class="btn btn-default"
            style="display:none; margin-left:6px;">
            <i class="fa fa-history"></i> {{ trans('front.video_recordings') }}
        </a>
        <p class="text-muted small" id="widget-live-video-hint">
            {{ trans('front.select_device') }}
        </p>
    </div>
</div>

<script>
    (function() {
        var $widgets = document.getElementById('widgets');
        if (!$widgets) return;

        // El vehiculo seleccionado tiene video? Es la misma regla del servidor
        // (Device::hasVideo() + permiso device_video), que viaja en el payload del mapa.
        // Aqui hace falta en cliente porque esta caja es UNA sola y cambia con
        // el vehiculo: no puede decidirse al renderizar, como el menu del objeto.
        function opciones(deviceId) {
            try {
                // app.devices.get(id): `items` es privado dentro del controlador
                var item = app.devices.get(deviceId);

                return (item && item.options()) || {};
            } catch (e) {
                return {};
            }
        }

        function tieneVideo(deviceId) {
            return !!opciones(deviceId).video;
        }

        new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.attributeName !== 'data-device-id') return;
                var deviceId = $widgets.getAttribute('data-device-id');
                var btn = document.getElementById('widget-live-video-btn');
                var hint = document.getElementById('widget-live-video-hint');
                var box = document.getElementById('widget-live-video');
                if (!btn) return;

                // Sin video, la caja entera se esconde: ofrecer un boton que
                // abre un visor que devuelve 404 no ayuda a nadie.
                if (box) {
                    box.style.display = (deviceId && !tieneVideo(deviceId)) ? 'none' : '';
                }

                if (deviceId && tieneVideo(deviceId)) {
                    btn.onclick = function () { app.deviceVideo.open(deviceId, opciones(deviceId).camera_channels); };
                    btn.style.display = 'inline-block';
                    var pbBtn = document.getElementById('widget-playback-btn');
                    if (pbBtn) {
                        pbBtn.onclick = function () { app.deviceVideo.recordings(deviceId); };
                        pbBtn.style.display = 'inline-block';
                    }
                    if (hint) hint.style.display = 'none';
                } else {
                    btn.style.display = 'none';
                    var pbBtn2 = document.getElementById('widget-playback-btn');
                    if (pbBtn2) pbBtn2.style.display = 'none';
                    if (hint) hint.style.display = 'block';
                }
            });
        }).observe($widgets, {
            attributes: true,
            attributeFilter: ['data-device-id']
        });
    }());
</script>
