/* =====================================================================
 * tp-device-media.js · Galería de medios del vehículo
 * ---------------------------------------------------------------------
 * Paridad DeviceMedia/images: fotos y vídeos que las cámaras suben por
 * FTP. Los archivos no están en base de datos: el backend los enumera
 * desde disco y devuelve un `ref` cifrado que las rutas legacy usan para
 * servir, descargar y borrar.
 * ===================================================================== */
(function () {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  var BASE = "/ui/v2/api/devices";

  // Galeria abierta ahora mismo, para poder recargarla desde fuera cuando
  // llega una foto nueva por el socket.
  var abierta = null;

  function open(deviceId, deviceName) {
    if (!window.tpModal) return;

    var state = { page: 1, pages: 1, canRemove: false, selected: {}, categories: [], category: "" };

    tpModal.open({
      title: tpT('ui.gallery') + (deviceName ? " · " + deviceName : ""),
      size: "lg",
      body: '<div data-media-root><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      onMount: function (dialog) {
        var root = dialog.querySelector("[data-media-root]");

        abierta = { deviceId: deviceId, recarga: function () { load(); } };

        dialog.addEventListener("close", function () {
          if (abierta && abierta.deviceId === deviceId) abierta = null;
        });

        function load() {
          var qs = new URLSearchParams({ page: state.page, limit: 24 });
          if (state.category) qs.set("media_category", state.category);

          tpModal.fetchJSON(BASE + "/" + deviceId + "/media?" + qs.toString()).then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              root.innerHTML = '<div class="tp-text-muted tp-text-sm">' + tpT('ui.gallery_load_failed') + '</div>';
              return;
            }
            var d = res.data;
            state.pages = d.pages || 1;
            state.canRemove = !!d.can_remove;
            state.categories = d.categories || [];

            var toolbar =
              '<div class="tp-row" style="justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;">' +
              '<span class="tp-text-sm tp-text-muted">' + (d.total || 0) + " archivo(s)</span>" +
              '<span class="tp-row" style="gap:6px;align-items:center;">' +
              (state.categories.length
                ? '<select class="tp-input tp-input--sm" data-media-cat style="width:auto;">' +
                  '<option value="">' + tpT('ui.all_categories') + '</option>' +
                  state.categories
                    .map(function (c) {
                      return '<option value="' + c.id + '"' + (String(state.category) === String(c.id) ? " selected" : "") + ">" + esc(c.title) + "</option>";
                    })
                    .join("") +
                  "</select>"
                : "") +
              '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-zip disabled>Descargar ZIP</button>' +
              (state.canRemove
                ? '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-del style="color:#ef4444;" disabled>' + tpT('global.delete') + '</button>'
                : "") +
              "</span></div>";

            var grid = (d.items || []).length
              ? '<div class="tp-media-grid">' +
                d.items
                  .map(function (f) {
                    return (
                      '<figure class="tp-media-card">' +
                      // La selección sirve para descargar en ZIP y para borrar;
                      // solo el botón Eliminar depende del permiso.
                      '<label class="tp-media-card__sel"><input type="checkbox" data-media-ref="' + esc(f.ref) + '"></label>' +
                      '<a href="' + esc(f.url) + '" target="_blank" rel="noopener" class="tp-media-card__thumb">' +
                      (f.is_video
                        ? '<span class="tp-media-card__video">▶</span>'
                        : '<img src="' + esc(f.url) + '" alt="" loading="lazy">') +
                      "</a>" +
                      '<figcaption class="tp-media-card__meta">' +
                      '<span class="tp-media-card__name" title="' + esc(f.name) + '">' + esc(f.name) + "</span>" +
                      '<span class="tp-media-card__sub">' + esc(f.created_at || "") +
                      (f.camera ? " · " + esc(f.camera) : "") + "</span>" +
                      '<a class="tp-media-card__dl" href="' + esc(f.download) + '">Descargar</a>' +
                      "</figcaption></figure>"
                    );
                  })
                  .join("") +
                "</div>"
              : '<div class="tp-text-muted tp-text-sm" style="padding:18px 0;">' + tpT('ui.vehicle_has_no_media') + '</div>';

            var pager =
              state.pages > 1
                ? '<div class="tp-row" style="justify-content:center;gap:8px;margin-top:12px;">' +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-prev' + (state.page <= 1 ? " disabled" : "") + ">" + tpT('front.previous') + "</button>" +
                  '<span class="tp-text-sm tp-text-muted" style="align-self:center;">' + state.page + " / " + state.pages + "</span>" +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-next' + (state.page >= state.pages ? " disabled" : "") + ">" + tpT('front.next') + "</button>" +
                  "</div>"
                : "";

            root.innerHTML = toolbar + grid + pager;
          });
        }
        load();

        root.addEventListener("change", function (e) {
          if (e.target.matches("[data-media-cat]")) {
            state.category = e.target.value;
            state.page = 1;
            return load();
          }
          if (e.target.matches("[data-media-ref]")) {
            var any = !!root.querySelectorAll("[data-media-ref]:checked").length;
            var btnDel = root.querySelector("[data-media-del]");
            if (btnDel) btnDel.disabled = !any;
            var btnZip = root.querySelector("[data-media-zip]");
            if (btnZip) btnZip.disabled = !any;
          }
        });

        root.addEventListener("click", function (e) {
          if (e.target.closest("[data-media-prev]")) {
            state.page = Math.max(1, state.page - 1);
            return load();
          }
          if (e.target.closest("[data-media-next]")) {
            state.page = Math.min(state.pages, state.page + 1);
            return load();
          }
          if (e.target.closest("[data-media-zip]")) {
            var zrefs = [];
            root.querySelectorAll("[data-media-ref]:checked").forEach(function (c) {
              zrefs.push(c.getAttribute("data-media-ref"));
            });
            if (!zrefs.length) return;

            // El ZIP lo arma el endpoint legacy (ZipArchive + descarga con
            // borrado del temporal); se envía por form-POST porque la respuesta
            // es un binario, no JSON.
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "/device_media/download/" + deviceId;
            form.style.display = "none";

            var csrf = document.createElement("input");
            csrf.type = "hidden";
            csrf.name = "_token";
            csrf.value = (window.Talentus && window.Talentus.csrf) ||
              (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
            form.appendChild(csrf);

            zrefs.forEach(function (r) {
              var i = document.createElement("input");
              i.type = "hidden";
              i.name = "id[]";
              i.value = r;
              form.appendChild(i);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            return;
          }

          if (e.target.closest("[data-media-del]")) {
            var refs = [];
            root.querySelectorAll("[data-media-ref]:checked").forEach(function (c) {
              refs.push(c.getAttribute("data-media-ref"));
            });
            if (!refs.length) return;

            tpModal
              .confirm({
                title: tpT('ui.delete_files'),
                message: tpT('ui.will_be_deleted') + refs.length + tpT('ui.files_from_storage'),
                danger: true,
                confirmLabel: tpT('global.delete'),
              })
              .then(function (ok) {
                if (!ok) return;
                tpModal
                  .fetchJSON(BASE + "/" + deviceId + "/media", { method: "DELETE", json: { files: refs } })
                  .then(function (res) {
                    if (res.ok && res.data && res.data.ok) {
                      tpModal.toast("Eliminados " + (res.data.deleted || 0) + " archivo(s).", "success");
                      load();
                    } else {
                      tpModal.toast(tpT('ui.could_not_delete_them'), "error");
                    }
                  });
              });
          }
        });
      },
    });
  }

  window.tpDeviceMedia = {
    open: open,

    /** Recarga la galeria si la que esta abierta es la de ese vehiculo. */
    refresh: function (deviceId) {
      if (abierta && (!deviceId || abierta.deviceId === deviceId)) abierta.recarga();
    },
  };

  /* Foto nueva de camara: el legacy recarga su tabla, aqui se recarga la
     galeria si la tienes delante y, si no, se avisa sin molestar. */
  if (window.tpSocket) {
    tpSocket.on("device_camera_create", function (msg) {
      if (msg && msg.success === false) return;

      if (abierta) {
        abierta.recarga();
      } else if (window.tpModal) {
        tpModal.toast(tpT('ui.new_camera_photo'), "info");
      }
    });
  }
})();
