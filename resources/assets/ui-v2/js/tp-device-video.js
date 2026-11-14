/* =====================================================================
 * tp-device-video.js · Video JT1078 del vehiculo en la UI v2
 * ---------------------------------------------------------------------
 * Opcion A del plan (docs/superpowers/plans/2026-11-14-video-jt1078-ui-v2.md):
 * el reproductor es el mismo del legacy (DeviceVideo/player.blade.php) dentro
 * de un iframe, asi se reutiliza tal cual lo ya probado: FLV firmado con
 * mpegts.js, HLS de respaldo, HD/SD, audio G.711/G.726/AAC, hablar con la
 * camara y H.265. Este modulo solo pone la ventana alrededor:
 *
 *   tpDeviceVideo.open(deviceId, { name, channel })  en vivo (cuadricula)
 *   tpDeviceVideo.recordings(deviceId, { name })     grabaciones y descargas
 *
 * Los canales, roles y valores por defecto salen de GET devices/video/{id}/config
 * (mismo endpoint que la app movil). Todos los endpoints exigen devices.show y
 * device_video.view en el servidor; aqui no se decide ningun permiso.
 *
 * Al cerrar se llama a videoPlayer.stop() de cada iframe para que el servidor
 * envie videoStop cuando no queden espectadores (el iframe tambien lo hace en
 * pagehide, por si se cierra la pestaña entera).
 * ===================================================================== */
(function () {
  "use strict";

  var BASE = "/devices/video/";
  var RECORDINGS_POLL_MS = 3000;
  var RECORDINGS_POLL_MAX = 20;
  var DOWNLOADS_POLL_MS = 5000;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function viewerId() {
    return "w" + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  function playerUrl(deviceId, channel, extra) {
    return BASE + deviceId + "/player?channel=" + channel + "&viewer=" + viewerId() + (extra || "");
  }

  function frame(src) {
    return '<iframe src="' + esc(src) + '" allow="autoplay; fullscreen; microphone" allowfullscreen></iframe>';
  }

  /**
   * Pide videoStop (si no quedan espectadores) y quita los iframes, como el legacy
   * (deviceVideo.js). El stop del reproductor sale por sendBeacon (keepalive), que el
   * navegador entrega aunque el iframe se destruya enseguida.
   */
  function stopPlayers(host) {
    if (!host) return;
    host.querySelectorAll("iframe").forEach(function (f) {
      try {
        if (f.contentWindow && f.contentWindow.videoPlayer) f.contentWindow.videoPlayer.stop();
      } catch (e) { /* otro origen o ya cerrado */ }
      f.remove();
    });
  }

  function formatDuration(seconds) {
    seconds = parseInt(seconds, 10) || 0;
    var h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60;
    return (h ? h + "h " : "") + (h || m ? m + "min " : "") + s + "s";
  }

  function mb(size) {
    return size ? (size / 1048576).toFixed(1) + " MB" : "-";
  }

  function channelTitle(cfg, channel) {
    var found = (cfg.channels || []).filter(function (c) { return c.channel === channel; })[0];
    return found ? found.title : tpT("front.channel") + " " + channel;
  }

  function loadConfig(deviceId) {
    return tpModal.fetchJSON(BASE + deviceId + "/config").then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        var msg = (res.data && (res.data.message || res.data.error)) || tpT("front.video_err_platform");
        throw new Error(msg);
      }
      return res.data;
    });
  }

  function failed(err) {
    tpModal.toast((err && err.message) || tpT("front.video_err_platform"), "error");
  }

  /** POST/DELETE con CSRF; devuelve { ok, status, data } como tpModal.fetchJSON. */
  function send(url, method, body) {
    return tpModal.fetchJSON(url, {
      method: method,
      headers: { "Content-Type": "application/json" },
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  function firstError(res) {
    var d = res && res.data;
    if (!d) return null;
    if (d.errors) {
      var k = Object.keys(d.errors)[0];
      return k ? [].concat(d.errors[k])[0] : null;
    }
    return d.error || d.message || null;
  }

  // ----------------------------------------------------------------------
  // En vivo
  // ----------------------------------------------------------------------
  function open(deviceId, opts) {
    if (!window.tpModal) return;
    opts = opts || {};

    loadConfig(deviceId).then(function (cfg) {
      var channels = (cfg.channels || []).map(function (c) { return c.channel; });
      if (!channels.length) return failed(new Error(tpT("video_no_channels")));

      var only = opts.channel && channels.indexOf(+opts.channel) !== -1 ? +opts.channel : 0;
      var name = opts.name || (cfg.device && cfg.device.name) || "";

      var chOpts = '<option value="0">' + esc(tpT("front.video_all_channels")) + "</option>" +
        cfg.channels.map(function (c) {
          return '<option value="' + c.channel + '"' + (c.channel === only ? " selected" : "") + ">" + esc(c.title) + "</option>";
        }).join("");

      tpModal.open({
        title: tpT("front.video_live") + (name ? " · " + name : ""),
        size: "xl",
        body:
          '<div class="tp-dv" data-dv-root>' +
          '<div class="tp-dv__bar">' +
          '<label class="tp-dv__field"><span>' + esc(tpT("front.channel")) + '</span>' +
          '<select class="tp-input tp-input--sm" data-dv-channel>' + chOpts + "</select></label>" +
          '<label class="tp-dv__field" data-dv-cols-wrap><span>' + esc(tpT("video_columns")) + '</span>' +
          '<select class="tp-input tp-input--sm" data-dv-cols>' +
          '<option value="1">1</option><option value="2">2</option><option value="3">3</option>' +
          "</select></label>" +
          '<span class="tp-dv__spacer"></span>' +
          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-dv-recordings>' + esc(tpT("front.video_recordings")) + "</button>" +
          "</div>" +
          '<div class="tp-dv__grid" data-dv-grid></div>' +
          "</div>",
        buttons: [{ label: tpT("global.close"), variant: "ghost", close: true }],
        onMount: function (dialog) {
          var grid = dialog.querySelector("[data-dv-grid]");
          var selCh = dialog.querySelector("[data-dv-channel]");
          var selCols = dialog.querySelector("[data-dv-cols]");
          var colsWrap = dialog.querySelector("[data-dv-cols-wrap]");

          // 1 canal: 1 columna; hasta 4: 2; mas: 3 (igual que el legacy).
          selCols.value = String(channels.length <= 1 ? 1 : channels.length <= 4 ? 2 : 3);

          function render() {
            stopPlayers(grid);
            var ch = +selCh.value;
            var list = ch ? [ch] : channels;
            var cols = ch ? 1 : +selCols.value;
            colsWrap.style.display = ch || channels.length < 2 ? "none" : "";
            grid.style.gridTemplateColumns = "repeat(" + cols + ", minmax(0, 1fr))";
            grid.setAttribute("data-count", list.length);
            grid.innerHTML = list.map(function (c) {
              return '<div class="tp-dv__cell">' + frame(playerUrl(deviceId, c)) + "</div>";
            }).join("");
          }

          selCh.addEventListener("change", render);
          selCols.addEventListener("change", render);
          dialog.querySelector("[data-dv-recordings]").addEventListener("click", function () {
            tpModal.close(dialog);
            recordings(deviceId, { name: name });
          });
          // Antes de que el <dialog> se desmonte: si no, el iframe muere sin avisar.
          dialog.addEventListener("close", function () { stopPlayers(grid); });

          render();
        },
      });
    }).catch(failed);
  }

  // ----------------------------------------------------------------------
  // Grabaciones y descargas
  // ----------------------------------------------------------------------
  function recordings(deviceId, opts) {
    if (!window.tpModal) return;
    opts = opts || {};

    loadConfig(deviceId).then(function (cfg) {
      var name = opts.name || (cfg.device && cfg.device.name) || "";
      var toLocal = function (v) { return String(v || "").replace(" ", "T"); };

      var chOpts = '<option value="0">' + esc(tpT("front.video_all_channels")) + "</option>" +
        (cfg.channels || []).map(function (c) {
          return '<option value="' + c.channel + '">' + esc(c.title) + "</option>";
        }).join("");

      var timers = { list: null, downloads: null };

      tpModal.open({
        title: tpT("front.video_recordings") + (name ? " · " + name : ""),
        size: "xl",
        body:
          '<div class="tp-dv" data-vr-root>' +
          '<form class="tp-dv__bar" data-vr-form>' +
          '<label class="tp-dv__field"><span>' + esc(tpT("front.channel")) + '</span>' +
          '<select class="tp-input tp-input--sm" name="channel">' + chOpts + "</select></label>" +
          '<label class="tp-dv__field"><span>' + esc(tpT("front.from")) + '</span>' +
          '<input class="tp-input tp-input--sm" type="datetime-local" name="from" value="' + esc(toLocal(cfg.recordings_from)) + '"></label>' +
          '<label class="tp-dv__field"><span>' + esc(tpT("global.to")) + '</span>' +
          '<input class="tp-input tp-input--sm" type="datetime-local" name="to" value="' + esc(toLocal(cfg.recordings_to)) + '"></label>' +
          '<button type="submit" class="tp-btn tp-btn--accent tp-btn--sm" data-vr-query>' + esc(tpT("front.video_recordings_query")) + "</button>" +
          "</form>" +
          '<div class="tp-dv__status" data-vr-status hidden></div>' +
          '<div class="tp-dv__player" data-vr-player hidden>' +
          '<div class="tp-dv__player-head"><strong data-vr-player-title></strong>' +
          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-vr-player-close>' + esc(tpT("global.close")) + "</button></div>" +
          '<div class="tp-dv__cell tp-dv__cell--player" data-vr-player-frame></div>' +
          "</div>" +
          '<div class="tp-table-wrap" data-vr-results hidden><table class="tp-table tp-table--dense"><thead><tr>' +
          "<th>" + esc(tpT("front.channel")) + "</th><th>" + esc(tpT("front.from")) + "</th><th>" + esc(tpT("global.to")) + "</th>" +
          "<th>" + esc(tpT("video_duration")) + "</th><th>" + esc(tpT("front.video_size")) + "</th><th></th>" +
          "</tr></thead><tbody data-vr-items></tbody></table></div>" +
          '<div data-vr-downloads hidden><h4 class="tp-h6 tp-dv__subtitle">' + esc(tpT("front.video_downloads")) + "</h4>" +
          '<div class="tp-table-wrap"><table class="tp-table tp-table--dense"><thead><tr>' +
          "<th>" + esc(tpT("front.channel")) + "</th><th>" + esc(tpT("front.from")) + "</th><th>" + esc(tpT("video_duration")) + "</th>" +
          "<th>" + esc(tpT("front.status")) + "</th><th></th>" +
          "</tr></thead><tbody data-vr-downloads-items></tbody></table></div></div>" +
          "</div>",
        buttons: [{ label: tpT("global.close"), variant: "ghost", close: true }],
        onMount: function (dialog) {
          var root = dialog.querySelector("[data-vr-root]");
          var form = root.querySelector("[data-vr-form]");
          var btnQuery = root.querySelector("[data-vr-query]");
          var statusEl = root.querySelector("[data-vr-status]");
          var results = root.querySelector("[data-vr-results]");
          var itemsEl = root.querySelector("[data-vr-items]");
          var player = root.querySelector("[data-vr-player]");
          var playerFrame = root.querySelector("[data-vr-player-frame]");
          var downloads = root.querySelector("[data-vr-downloads]");
          var downloadsItems = root.querySelector("[data-vr-downloads-items]");
          var closed = false;

          function setStatus(type, msg) {
            if (!msg) { statusEl.hidden = true; return; }
            statusEl.hidden = false;
            statusEl.className = "tp-dv__status tp-dv__status--" + type;
            statusEl.textContent = msg;
          }

          function finish(type, msg) {
            btnQuery.disabled = false;
            setStatus(type, msg);
          }

          function closePlayer() {
            stopPlayers(playerFrame);
            player.hidden = true;
          }

          // El equipo responde la lista (0x1205) de forma asincrona: se consulta hasta que llegue una nueva.
          function poll(seq, attempt) {
            timers.list = setTimeout(function () {
              tpModal.fetchJSON(BASE + deviceId + "/recordings/list?seq=" + encodeURIComponent(seq)).then(function (res) {
                if (closed) return;
                var d = res.data || {};
                if (!res.ok || d.ok === false) return finish("error", d.error || tpT("front.video_err_platform"));
                if (d.fresh) return showItems(d.items || []);
                if (d.nak) return finish("warning", tpT("front.video_recordings_rejected"));
                if (attempt + 1 >= RECORDINGS_POLL_MAX) return finish("warning", tpT("front.video_recordings_timeout"));
                poll(seq, attempt + 1);
              });
            }, RECORDINGS_POLL_MS);
          }

          function showItems(items) {
            var ch = +form.elements.channel.value;
            if (ch) items = items.filter(function (it) { return it.channel === ch; });
            if (!items.length) return finish("info", tpT("front.video_recordings_empty"));

            itemsEl.innerHTML = items.map(function (it, i) {
              return "<tr data-i=\"" + i + "\">" +
                "<td>" + esc(channelTitle(cfg, it.channel)) + (it.alarm ? " ⚠" : "") + "</td>" +
                "<td>" + esc(it.start_at) + "</td><td>" + esc(it.end_at) + "</td>" +
                "<td>" + esc(formatDuration(it.duration)) + "</td><td>" + esc(mb(it.size)) + "</td>" +
                '<td class="tp-dv__actions">' +
                '<button type="button" class="tp-btn tp-btn--secondary tp-btn--sm" data-vr-play>' + esc(tpT("front.video_play")) + "</button>" +
                '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-vr-download>' + esc(tpT("front.video_download")) + "</button>" +
                "</td></tr>";
            }).join("");
            itemsEl._items = items;
            results.hidden = false;
            finish();
          }

          function query() {
            clearTimeout(timers.list);
            closePlayer();
            results.hidden = true;
            itemsEl.innerHTML = "";
            setStatus("info", tpT("front.video_recordings_waiting"));
            btnQuery.disabled = true;

            send(BASE + deviceId + "/recordings/query", "POST", {
              channel: form.elements.channel.value,
              from: String(form.elements.from.value || "").replace("T", " "),
              to: String(form.elements.to.value || "").replace("T", " "),
            }).then(function (res) {
              var d = res.data || {};
              if (!res.ok || !d.ok) return finish("error", firstError(res) || tpT("front.video_err_platform"));
              poll(d.seq, 0);
            });
          }

          function play(it) {
            closePlayer();
            root.querySelector("[data-vr-player-title]").textContent = channelTitle(cfg, it.channel) + " · " + it.start_at;
            playerFrame.innerHTML = frame(playerUrl(deviceId, it.channel,
              "&mode=history&from=" + encodeURIComponent(it.start) + "&to=" + encodeURIComponent(it.end)));
            player.hidden = false;
          }

          function renderDownloads(items) {
            var active = false;
            downloadsItems.innerHTML = items.map(function (it) {
              var running = it.status === "pending" || it.status === "recording";
              var ftp = it.method === "ftp";
              var status = (ftp && it.status === "recording" && tpT("front.video_download_uploading")) ||
                tpT("front.video_download_" + it.status);
              if (running) active = true;
              var actions = "";
              if (it.url) actions += '<a class="tp-btn tp-btn--secondary tp-btn--sm" href="' + esc(it.url) + '">' + esc(tpT("front.video_download")) + "</a>";
              // Una subida FTP en curso se cancela en el equipo (0x9207); la grabacion de la reproduccion no.
              if (!running || ftp)
                actions += '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-vr-dl-delete="' + it.id + '">' +
                  esc(running ? tpT("front.video_download_cancel") : tpT("global.delete")) + "</button>";
              return "<tr><td>" + esc(channelTitle(cfg, it.channel)) + "</td><td>" + esc(it.start_at) + "</td>" +
                "<td>" + esc(formatDuration(it.seconds)) + "</td>" +
                "<td>" + esc(status) + (it.size ? " · " + esc(mb(it.size)) : "") + (it.error ? " · " + esc(it.error) : "") + "</td>" +
                '<td class="tp-dv__actions">' + actions + "</td></tr>";
            }).join("");
            downloads.hidden = !items.length;

            clearTimeout(timers.downloads);
            if (active && !closed) timers.downloads = setTimeout(loadDownloads, DOWNLOADS_POLL_MS);
          }

          function loadDownloads() {
            tpModal.fetchJSON(BASE + deviceId + "/downloads").then(function (res) {
              if (!closed && res.ok && res.data && res.data.ok) renderDownloads(res.data.items || []);
            });
          }

          form.addEventListener("submit", function (e) { e.preventDefault(); query(); });
          root.querySelector("[data-vr-player-close]").addEventListener("click", closePlayer);

          itemsEl.addEventListener("click", function (e) {
            var btn = e.target.closest("button");
            var tr = e.target.closest("tr[data-i]");
            if (!btn || !tr) return;
            var it = itemsEl._items[+tr.getAttribute("data-i")];
            if (btn.hasAttribute("data-vr-play")) return play(it);
            if (btn.hasAttribute("data-vr-download")) {
              btn.disabled = true;
              send(BASE + deviceId + "/downloads", "POST", { channel: it.channel, from: it.start, to: it.end }).then(function (res) {
                btn.disabled = false;
                var d = res.data || {};
                if (!res.ok || !d.ok) return setStatus("warning", firstError(res) || tpT("front.video_err_platform"));
                setStatus("success", tpT("front.video_download_requested"));
                renderDownloads(d.items || []);
              });
            }
          });

          downloadsItems.addEventListener("click", function (e) {
            var btn = e.target.closest("[data-vr-dl-delete]");
            if (!btn) return;
            btn.disabled = true;
            send(BASE + deviceId + "/downloads/" + btn.getAttribute("data-vr-dl-delete"), "DELETE").then(function (res) {
              var d = res.data || {};
              if (res.ok && d.ok) renderDownloads(d.items || []);
              else { btn.disabled = false; setStatus("warning", firstError(res) || tpT("front.video_err_platform")); }
            });
          });

          dialog.addEventListener("close", function () {
            closed = true;
            clearTimeout(timers.list);
            clearTimeout(timers.downloads);
            closePlayer();
          });

          loadDownloads();
        },
      });
    }).catch(failed);
  }

  // Botones del widget "Video en vivo" del drawer (partials/device-widgets.blade.php):
  // el HTML de los widgets se reinyecta en cada refresco, asi que se delega en document.
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-tp-video]");
    if (!btn) return;
    e.preventDefault();
    var id = +btn.getAttribute("data-device-id");
    if (!id) return;
    if (btn.getAttribute("data-tp-video") === "recordings") recordings(id);
    else open(id, { channel: +btn.getAttribute("data-channel") || 0 });
  });

  // Ficha del vehiculo (modals/devices/partials/video.blade.php): capacidades de la camara
  // (JT1078 0x9003 -> 0x1003). La respuesta llega de forma asincrona: se consulta cada 3 s, 10 veces.
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-tp-video-attrs-detect]");
    if (!btn) return;
    var box = btn.closest("[data-tp-video-attrs]");
    var info = box.querySelector("[data-tp-video-attrs-info]");

    function done(msg) {
      btn.disabled = false;
      if (msg) info.textContent = msg;
    }

    btn.disabled = true;
    info.textContent = tpT("front.video_attributes_waiting");

    send(box.getAttribute("data-query-url"), "POST").then(function (res) {
      var d = res.data || {};
      if (!res.ok || !d.ok) return done(firstError(res) || tpT("front.video_err_platform"));

      (function poll(attempt) {
        setTimeout(function () {
          tpModal.fetchJSON(box.getAttribute("data-url") + "?seq=" + encodeURIComponent(d.seq)).then(function (r) {
            var a = r.data || {};
            if (a.fresh && a.attributes) {
              done(a.attributes.summary);
              return suggestChannels(box.closest("form"), a.attributes.maxVideoChannels);
            }
            if (a.nak) return done(tpT("front.video_attributes_rejected"));
            if (attempt + 1 >= 10) return done(tpT("front.video_attributes_timeout"));
            poll(attempt + 1);
          });
        }, 3000);
      })(0);
    });
  });

  // Si todavia no se eligieron canales, marca los que informa el equipo (se guardan con el formulario).
  function suggestChannels(form, count) {
    count = parseInt(count, 10) || 0;
    if (!form || !count) return;
    var boxes = form.querySelectorAll('input[name="camera_channels[]"]');
    if ([].some.call(boxes, function (b) { return b.checked; })) return;
    var has = form.querySelector('input[type="checkbox"][name="has_camera"]');
    if (has) has.checked = true;
    boxes.forEach(function (b) { if (+b.value <= count) b.checked = true; });
  }

  window.tpDeviceVideo = { open: open, recordings: recordings, stopPlayers: stopPlayers };
})();
