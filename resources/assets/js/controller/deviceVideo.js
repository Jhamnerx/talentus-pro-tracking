function DeviceVideo() {
    var
        _this = this,
        RECORDINGS_POLL_MS = 3000,
        RECORDINGS_POLL_MAX = 20,
        DOWNLOADS_POLL_MS = 5000,
        recordingsPoll = null,
        downloadsPoll = null;

    _this.init = function() {
        $(document)
            .on('click', '#video-recordings #vr-query', function() {
                queryRecordings($('#video-recordings'));
            })
            .on('click', '#video-recordings .vr-play', function() {
                var $row = $(this).closest('tr');

                openPlayback($('#video-recordings'), $row.data('channel'), $row.data('start'), $row.data('end'), $row.data('title'));
            })
            .on('click', '#video-recordings #vr-player-close', function() {
                closePlayback();
            })
            .on('click', '#video-recordings .vr-download', function() {
                var $row = $(this).closest('tr');

                requestDownload($('#video-recordings'), $(this), $row.data('channel'), $row.data('start'), $row.data('end'));
            })
            .on('click', '#video-recordings .vr-download-delete', function() {
                deleteDownload($('#video-recordings'), $(this).data('url'));
            })
            .on('click', '#camera-attributes-detect', function() {
                detectAttributes($('#camera-attributes'), $(this));
            });
    };

    /**
     * Abre el video del dispositivo: un canal directo o la cuadrícula si tiene varios.
     */
    _this.open = function(deviceId, channels) {
        channels = $.map(channels || [], function(channel) { return parseInt(channel, 10) || null; });

        if (!channels.length)
            return;

        if (channels.length === 1)
            return _this.live(deviceId, channels[0]);

        _this.grid(deviceId, channels);
    };

    _this.live = function(deviceId, channel) {
        var id = 'device-video-' + deviceId + '-' + channel;

        if (_this.focus(id))
            return;

        var html = '<div id="' + id + '">' + playerFrame(playerUrl(deviceId, channel)) + '</div>';

        openDialog(id, html, window.lang.video_live + ' #' + channel + deviceTitle(deviceId), 480, 320);
    };

    _this.grid = function(deviceId, channels) {
        var id = 'device-video-grid-' + deviceId;

        if (_this.focus(id))
            return;

        var columns = channels.length <= 1 ? 1 : (channels.length <= 4 ? 2 : 3),
            cells = $.map(channels, function(channel) {
                return '<div style="position:relative;overflow:hidden;background:#000;">' +
                    playerFrame(playerUrl(deviceId, channel)) + '</div>';
            }).join('');

        var html = '<div id="' + id + '" style="display:grid;gap:2px;width:100%;height:100%;background:#000;' +
            'grid-template-columns:repeat(' + columns + ',1fr);grid-auto-rows:1fr;">' + cells + '</div>';

        openDialog(id, html, window.lang.video_live + deviceTitle(deviceId), 720, 540);
    };

    _this.recordings = function(deviceId) {
        var modalId = 'device_video_recordings',
            $modalEl = $modal.initModal(modalId);

        $modalEl.off('.video').on('hidden.video hidden.bs.modal.video', function() {
            stopRecordingsPoll();
            stopDownloadsPoll();
            closePlayback();
        });

        // $modal.initEvent dispara "modal-<id con el primer _ cambiado por ->-content-success" en document.
        $(document).one('modal-' + modalId.replace('_', '-') + '-content-success', function() {
            loadDownloads($('#video-recordings'));
        });

        $modal.getModalContent({url: app.urls.deviceVideo + deviceId + '/recordings', modal: modalId}, 'GET', $modalEl);
    };

    _this.focus = function(id) {
        var $dialog = $('#' + id);

        if (!$dialog.length)
            return false;

        dialogMoveToTop($dialog.parent('.ui-dialog.ui-widget.ui-widget-content'), true);

        return true;
    };

    function queryRecordings($box) {
        var $button = $box.find('#vr-query');

        stopRecordingsPoll();
        closePlayback();
        $box.find('#vr-results').hide();
        $box.find('#vr-items').empty();
        setRecordingsStatus($box, 'info', window.lang.video_recordings_waiting);
        $button.prop('disabled', true);

        $.ajax({
            url: $box.data('query-url'),
            type: 'POST',
            dataType: 'json',
            data: {
                channel: $box.find('#vr-channel').val(),
                from: $box.find('#vr-from').val(),
                to: $box.find('#vr-to').val()
            }
        }).done(function(res) {
            if (!res || !res.ok) {
                $button.prop('disabled', false);
                return setRecordingsStatus($box, 'danger', (res && res.error) || window.lang.video_err_platform);
            }

            pollRecordings($box, res.seq, 0);
        }).fail(function(xhr) {
            $button.prop('disabled', false);
            setRecordingsStatus($box, 'danger', firstError(xhr) || window.lang.video_err_platform);
        });
    }

    // El equipo responde la lista (0x1205) de forma asíncrona; se consulta hasta que llegue una lista nueva.
    function pollRecordings($box, seq, attempt) {
        recordingsPoll = setTimeout(function() {
            $.getJSON($box.data('list-url'), {seq: seq}).done(function(res) {
                if (!$box.closest('body').length)
                    return;

                if (res && res.fresh)
                    return showRecordings($box, res.items || []);

                if (res && res.nak)
                    return finishRecordings($box, 'warning', window.lang.video_recordings_rejected);

                if (attempt + 1 >= RECORDINGS_POLL_MAX)
                    return finishRecordings($box, 'warning', window.lang.video_recordings_timeout);

                pollRecordings($box, seq, attempt + 1);
            }).fail(function() {
                finishRecordings($box, 'danger', window.lang.video_err_platform);
            });
        }, RECORDINGS_POLL_MS);
    }

    function showRecordings($box, items) {
        var channel = parseInt($box.find('#vr-channel').val(), 10) || 0;

        if (channel)
            items = $.grep(items, function(item) { return item.channel === channel; });

        if (!items.length)
            return finishRecordings($box, 'info', window.lang.video_recordings_empty);

        var rows = $.map(items, function(item) {
            var title = window.lang.channel + ' ' + item.channel + ' · ' + item.start_at;

            return $('<tr>')
                .attr({'data-channel': item.channel, 'data-start': item.start, 'data-end': item.end})
                .data('title', title)
                .append($('<td>').text(window.lang.channel + ' ' + item.channel + (item.alarm ? ' ⚠' : '')))
                .append($('<td>').text(item.start_at))
                .append($('<td>').text(item.end_at))
                .append($('<td>').text(formatDuration(item.duration)))
                .append($('<td>').text(item.size ? (item.size / 1048576).toFixed(1) + ' MB' : '-'))
                .append($('<td class="text-right" style="white-space:nowrap;">').html(
                    '<button type="button" class="btn btn-xs btn-action vr-play"><i class="fa fa-play"></i> ' +
                    window.lang.video_play + '</button> ' +
                    '<button type="button" class="btn btn-xs btn-default vr-download" title="' + window.lang.video_download +
                    '"><i class="fa fa-download"></i></button>'));
        });

        $box.find('#vr-items').empty().append(rows);
        $box.find('#vr-results').show();
        finishRecordings($box);
    }

    function finishRecordings($box, type, message) {
        $box.find('#vr-query').prop('disabled', false);

        if (message)
            setRecordingsStatus($box, type, message);
        else
            $box.find('#vr-status').hide();
    }

    function stopRecordingsPoll() {
        clearTimeout(recordingsPoll);
        recordingsPoll = null;
    }

    function setRecordingsStatus($box, type, message) {
        $box.find('#vr-status').attr('class', 'alert alert-' + type).text(message).show();
    }

    // Capacidades de la cámara (JT1078 0x9003 → 0x1003): la respuesta llega de forma asíncrona.
    function detectAttributes($box, $button) {
        var $info = $box.find('#camera-attributes-info');

        $button.prop('disabled', true);
        $info.text(window.lang.video_attributes_waiting);

        $.ajax({url: $box.data('query-url'), type: 'POST', dataType: 'json'}).done(function(res) {
            if (!res || !res.ok) {
                $button.prop('disabled', false);
                return $info.text((res && res.error) || window.lang.video_err_platform);
            }

            pollAttributes($box, $button, res.seq, 0);
        }).fail(function() {
            $button.prop('disabled', false);
            $info.text(window.lang.video_err_platform);
        });
    }

    function pollAttributes($box, $button, seq, attempt) {
        setTimeout(function() {
            $.getJSON($box.data('url'), {seq: seq}).done(function(res) {
                var $info = $box.find('#camera-attributes-info');

                if (res && res.fresh && res.attributes) {
                    $button.prop('disabled', false);
                    $info.text(res.attributes.summary);
                    return suggestChannels(res.attributes.maxVideoChannels);
                }

                if (res && res.nak) {
                    $button.prop('disabled', false);
                    return $info.text(window.lang.video_attributes_rejected);
                }

                if (attempt + 1 >= 10) {
                    $button.prop('disabled', false);
                    return $info.text(window.lang.video_attributes_timeout);
                }

                pollAttributes($box, $button, seq, attempt + 1);
            }).fail(function() {
                $button.prop('disabled', false);
            });
        }, 3000);
    }

    // Si todavía no se eligieron canales, marca los que informa el equipo (se guardan con el formulario).
    function suggestChannels(count) {
        count = parseInt(count, 10) || 0;

        var $boxes = $('input[name="camera_channels[]"]');
        if (!count || $boxes.filter(':checked').length)
            return;

        $('#has_camera').prop('checked', true).trigger('change');
        $boxes.each(function() {
            var channel = parseInt($(this).val(), 10);
            if (channel <= count) $(this).prop('checked', true).trigger('change');
        });
    }

    function requestDownload($box, $button, channel, start, end) {
        $button.prop('disabled', true);

        $.ajax({
            url: $box.data('downloads-url'),
            type: 'POST',
            dataType: 'json',
            data: {channel: channel, from: start, to: end}
        }).done(function(res) {
            if (!res || !res.ok)
                return setRecordingsStatus($box, 'warning', (res && res.error) || window.lang.video_err_platform);

            setRecordingsStatus($box, 'success', window.lang.video_download_requested);
            renderDownloads($box, res.items || []);
        }).fail(function(xhr) {
            setRecordingsStatus($box, 'danger', firstError(xhr) || window.lang.video_err_platform);
        }).always(function() {
            $button.prop('disabled', false);
        });
    }

    function deleteDownload($box, url) {
        $.ajax({url: url, type: 'DELETE', dataType: 'json'}).done(function(res) {
            if (res && res.ok)
                renderDownloads($box, res.items || []);
            else
                setRecordingsStatus($box, 'warning', (res && res.error) || window.lang.video_err_platform);
        });
    }

    function loadDownloads($box) {
        stopDownloadsPoll();

        if (!$box.length)
            return;

        $.getJSON($box.data('downloads-url')).done(function(res) {
            if ($box.closest('body').length && res && res.ok)
                renderDownloads($box, res.items || []);
        });
    }

    function renderDownloads($box, items) {
        var active = false;

        var rows = $.map(items, function(item) {
            var running = item.status === 'pending' || item.status === 'recording',
                ftp = item.method === 'ftp',
                status = (ftp && item.status === 'recording' && window.lang.video_download_uploading)
                    || window.lang['video_download_' + item.status] || item.status,
                actions = '';

            if (running)
                active = true;

            if (item.url)
                actions += '<a class="btn btn-xs btn-action" href="' + item.url + '"><i class="fa fa-download"></i> ' +
                    window.lang.video_download + '</a> ';

            // Las subidas FTP en curso se pueden cancelar (0x9207); la grabación de la reproducción no.
            if (!running || ftp)
                actions += '<button type="button" class="btn btn-xs btn-default vr-download-delete" data-url="' +
                    $box.data('downloads-url') + '/' + item.id + '" title="' +
                    (running ? window.lang.video_download_cancel : '') + '"><i class="fa ' +
                    (running ? 'fa-times' : 'fa-trash') + '"></i></button>';

            return $('<tr>')
                .append($('<td>').text(window.lang.channel + ' ' + item.channel))
                .append($('<td>').text(item.start_at))
                .append($('<td>').text(formatDuration(item.seconds)))
                .append($('<td>').text(status + (item.size ? ' · ' + (item.size / 1048576).toFixed(1) + ' MB' : '')
                    + (item.error ? ' · ' + item.error : '')))
                .append($('<td class="text-right" style="white-space:nowrap;">').html(actions));
        });

        $box.find('#vr-downloads-items').empty().append(rows);
        $box.find('#vr-downloads').toggle(items.length > 0);

        stopDownloadsPoll();
        if (active)
            downloadsPoll = setTimeout(function() { loadDownloads($box); }, DOWNLOADS_POLL_MS);
    }

    function stopDownloadsPoll() {
        clearTimeout(downloadsPoll);
        downloadsPoll = null;
    }

    function openPlayback($box, channel, start, end, title) {
        closePlayback();

        var url = playerUrl($box.data('device-id'), channel) +
            '&mode=history&from=' + encodeURIComponent(start) + '&to=' + encodeURIComponent(end);

        $box.find('#vr-player-title').text(title);
        $box.find('#vr-player-frame').html(playerFrame(url));
        $box.find('#vr-player').show();
    }

    function closePlayback() {
        var $frame = $('#video-recordings #vr-player-frame');

        stopPlayers($frame);
        $frame.empty();
        $('#video-recordings #vr-player').hide();
    }

    function playerUrl(deviceId, channel) {
        return app.urls.deviceVideo + deviceId + '/player?channel=' + channel + '&viewer=' + viewerId();
    }

    function playerFrame(src) {
        return '<iframe src="' + src + '" allow="autoplay; fullscreen; microphone" allowfullscreen ' +
            'style="border:0;width:100%;height:100%;display:block;"></iframe>';
    }

    function openDialog(id, html, title, width, height) {
        $('body').append(html);

        var $dialog = $('#' + id);

        $dialog.dialog({
            autoOpen: false,
            width: width,
            height: height,
            minWidth: 280,
            minHeight: 200,
            resizable: true,
            draggable: true,
            title: title,
            close: function() {
                stopPlayers($(this));
                $(this).remove();
            },
            open: function() {
                $('div[aria-describedby="' + id + '"]').find('.ui-dialog-titlebar-close').html('<span>×</span>');
            }
        });

        $dialog.dialog('open');
        dialogMoveToTop($dialog.parent('.ui-dialog.ui-widget.ui-widget-content'), true);
    }

    // Cada iframe también avisa en pagehide; esto asegura el stop antes de quitarlo del DOM.
    function stopPlayers($container) {
        $container.find('iframe').each(function() {
            try {
                this.contentWindow.videoPlayer && this.contentWindow.videoPlayer.stop();
            } catch (e) {}
        });
    }

    function deviceTitle(deviceId) {
        var device = app.devices.get(deviceId);

        return device ? ' (' + device.name() + ')' : '';
    }

    function formatDuration(seconds) {
        seconds = parseInt(seconds, 10) || 0;

        var h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;

        return (h ? h + 'h ' : '') + (h || m ? m + 'min ' : '') + s + 's';
    }

    function firstError(xhr) {
        var json = xhr && xhr.responseJSON;

        if (!json)
            return null;

        if (json.errors)
            return $.map(json.errors, function(errors) { return errors; })[0];

        return json.message || json.error || null;
    }

    function viewerId() {
        return 'w' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }
}
