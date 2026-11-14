{{-- Ventana flotante "Ubicación de la Unidad" para reportes HTML.
     Requiere en <head>: report.js (jQuery + Leaflet) y el parcial map_tiles (reportTileLayer).
     Uso: botones <button class="report-map-btn" data-lat data-lng data-duration> dentro de un
     div.panel con data-device='{"name":..., "icon":{"url","width","height","type"}|null}'. --}}
<style>
    .report-map-btn {
        display: inline-block;
        padding: 3px 10px;
        font-size: 11px;
        line-height: 1.5;
        color: #fff;
        background: #4a5568;
        border: 0;
        border-radius: 3px;
        cursor: pointer;
        white-space: nowrap;
    }
    .report-map-btn:hover { background: #2d3748; }

    #report-map-window {
        display: none;
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        margin: auto;
        width: 60%;
        min-width: 320px;
        max-width: calc(100% - 20px);
        height: 420px;
        max-height: calc(100% - 20px);
        background: #fff;
        border: 1px solid #c9c9c9;
        border-radius: 4px;
        box-shadow: 0 6px 24px rgba(0, 0, 0, .35);
        z-index: 10000;
    }
    #report-map-window.open { display: block; }
    #report-map-window .report-map-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 38px;
        padding: 0 12px;
        border-bottom: 1px solid #e3e3e3;
        font-weight: 700;
        cursor: move;
        user-select: none;
        touch-action: none;
    }
    #report-map-window.dragging { opacity: .92; }
    #report-map-window.dragging .report-map-body { pointer-events: none; }
    #report-map-window .report-map-close {
        border: 0;
        background: none;
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
        color: #888;
    }
    #report-map-window .report-map-close:hover { color: #000; }
    #report-map-window .report-map-body {
        position: absolute;
        top: 38px;
        right: 0;
        bottom: 0;
        left: 0;
    }
    #report-map-canvas { width: 100%; height: 100%; }
    @media print { #report-map-window, .report-map-btn, .report-map-col { display: none !important; } }
</style>

<div id="report-map-window" role="dialog" aria-labelledby="report-map-title">
    <div class="report-map-header">
        <span id="report-map-title">{{ trans('front.unit_location') }}</span>
        <button type="button" class="report-map-close" aria-label="{{ trans('global.close') }}">&times;</button>
    </div>
    <div class="report-map-body">
        <div id="report-map-canvas"></div>
    </div>
</div>

<script>
    var reportMapWindow = (function () {
        var $window = $('#report-map-window'),
            map = null,
            marker = null;

        L.Icon.Default.imagePath = @json(asset('assets/images') . '/');

        function escapeHtml(text) {
            return $('<div>').text(text == null ? '' : String(text)).html();
        }

        // Mismo marcador que el mapa en vivo (resources/assets/js/model/device.js):
        // etiqueta con el nombre encima del icono del dispositivo. Los iconos de tipo
        // "arrow" se dibujan con su imagen (sin color de estado ni rotación): en una
        // parada no hay rumbo que mostrar.
        function buildIcon(device, label) {
            if (!device || !device.icon) {
                return null;
            }

            var width  = Number(device.icon.width) || 32,
                height = Number(device.icon.height) || 32,
                anchor = device.icon.type === 'icon' ? [width / 2, height] : [width / 2, height / 2];

            var html = '<span class="name"><i><b>' + escapeHtml(device.name) + '</b>'
                + (label ? '<span>' + escapeHtml(label) + '</span>' : '')
                + '</i></span>'
                + '<img src="' + escapeHtml(device.icon.url) + '" width="' + width + '" height="' + height + '" alt="">';

            return L.divIcon({
                html: html,
                className: 'leaf-device-marker',
                iconSize: [width, height],
                iconAnchor: anchor,
                popupAnchor: [0, -height]
            });
        }

        function ensureMap() {
            if (map) {
                return map;
            }

            map = L.map('report-map-canvas', { attributionControl: false });
            reportTileLayer(map);

            return map;
        }

        function open(lat, lng, device, label) {
            var position = [Number(lat), Number(lng)];

            $window.addClass('open');

            ensureMap();
            map.invalidateSize();
            map.setView(position, 16);

            if (marker) {
                map.removeLayer(marker);
            }

            var icon = buildIcon(device, label);

            if (icon) {
                marker = L.marker(position, { icon: icon });
            } else {
                marker = L.marker(position);

                var tooltip = escapeHtml(device && device.name ? device.name : '')
                    + (label ? '<br>' + escapeHtml(label) : '');

                if (tooltip) {
                    marker.bindTooltip(tooltip, { permanent: true, direction: 'top' });
                }
            }

            marker.addTo(map);
        }

        function close() {
            $window.removeClass('open');
        }

        $(document).on('click', '.report-map-btn', function () {
            var $btn = $(this),
                device = $btn.closest('.panel').data('device') || null;

            open($btn.data('lat'), $btn.data('lng'), device, $btn.attr('data-duration'));
        });

        // Arrastre libre desde la cabecera. Al primer arrastre la ventana deja de estar
        // centrada (margin:auto) y pasa a top/left explícitos; conserva esa posición al
        // reabrirla. En horizontal queda entera dentro de la vista (el botón de cerrar
        // está a la derecha); en vertical basta con que la cabecera siga visible.
        var windowEl = $window[0],
            headerEl = $window.find('.report-map-header')[0],
            HEADER_HEIGHT = 38,
            drag = null,
            moved = false;

        function place(left, top) {
            var width = windowEl.offsetWidth;

            left = Math.max(Math.min(left, window.innerWidth - width), 0);
            top  = Math.min(Math.max(top, 0), window.innerHeight - HEADER_HEIGHT);

            windowEl.style.margin = '0';
            windowEl.style.right  = 'auto';
            windowEl.style.bottom = 'auto';
            windowEl.style.left   = left + 'px';
            windowEl.style.top    = top + 'px';
            moved = true;
        }

        headerEl.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || $(e.target).closest('.report-map-close').length) {
                return;
            }

            var rect = windowEl.getBoundingClientRect();

            drag = { x: e.clientX - rect.left, y: e.clientY - rect.top, id: e.pointerId };
            place(rect.left, rect.top);
            headerEl.setPointerCapture(e.pointerId);
            $window.addClass('dragging');
            e.preventDefault();
        });

        headerEl.addEventListener('pointermove', function (e) {
            if (drag && e.pointerId === drag.id) {
                place(e.clientX - drag.x, e.clientY - drag.y);
            }
        });

        function endDrag(e) {
            if (drag && e.pointerId === drag.id) {
                drag = null;
                $window.removeClass('dragging');
            }
        }

        headerEl.addEventListener('pointerup', endDrag);
        headerEl.addEventListener('pointercancel', endDrag);

        $(window).on('resize', function () {
            if (moved && $window.hasClass('open')) {
                var rect = windowEl.getBoundingClientRect();
                place(rect.left, rect.top);
            }
        });

        $window.on('click', '.report-map-close', close);

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                close();
            }
        });

        return { open: open, close: close };
    })();
</script>
