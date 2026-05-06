<div class="widget widget-live-video" id="widget-live-video">
    <div class="widget-heading">
        <div class="widget-title">
            <i class="icon play"></i> {{ trans('front.live_video') }}
        </div>
    </div>
    <div class="widget-body text-center" style="padding:15px;">
        <a href="javascript:" id="widget-live-video-btn" class="btn btn-primary" data-url="" data-modal=""
            style="display:none;">
            <i class="fa fa-play"></i> {{ trans('front.live_video') }}
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

        new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.attributeName !== 'data-device-id') return;
                var deviceId = $widgets.getAttribute('data-device-id');
                var btn = document.getElementById('widget-live-video-btn');
                var hint = document.getElementById('widget-live-video-hint');
                if (!btn) return;

                if (deviceId) {
                    btn.setAttribute('data-url', '/device_video/' + deviceId + '/live/modal');
                    btn.setAttribute('data-modal', 'device_video_live_' + deviceId);
                    btn.style.display = 'inline-block';
                    if (hint) hint.style.display = 'none';
                } else {
                    btn.style.display = 'none';
                    if (hint) hint.style.display = 'block';
                }
            });
        }).observe($widgets, {
            attributes: true,
            attributeFilter: ['data-device-id']
        });
    }());
</script>
