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

  function open(deviceId, deviceName) {
    if (!window.tpModal) return;

    var state = { page: 1, pages: 1, canRemove: false, selected: {}, categories: [], category: "" };

    tpModal.open({
      title: "Galería" + (deviceName ? " · " + deviceName : ""),
      size: "lg",
      body: '<div data-media-root><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      onMount: function (dialog) {
        var root = dialog.querySelector("[data-media-root]");

        function load() {
          var qs = new URLSearchParams({ page: state.page, limit: 24 });
          if (state.category) qs.set("media_category", state.category);

          tpModal.fetchJSON(BASE + "/" + deviceId + "/media?" + qs.toString()).then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              root.innerHTML = '<div class="tp-text-muted tp-text-sm">No se pudo cargar la galería.</div>';
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
                  '<option value="">Todas las categorías</option>' +
                  state.categories
                    .map(function (c) {
                      return '<option value="' + c.id + '"' + (String(state.category) === String(c.id) ? " selected" : "") + ">" + esc(c.title) + "</option>";
                    })
                    .join("") +
                  "</select>"
                : "") +
              (state.canRemove
                ? '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-del style="color:#ef4444;" disabled>Eliminar</button>'
                : "") +
              "</span></div>";

            var grid = (d.items || []).length
              ? '<div class="tp-media-grid">' +
                d.items
                  .map(function (f) {
                    return (
                      '<figure class="tp-media-card">' +
                      (state.canRemove
                        ? '<label class="tp-media-card__sel"><input type="checkbox" data-media-ref="' + esc(f.ref) + '"></label>'
                        : "") +
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
              : '<div class="tp-text-muted tp-text-sm" style="padding:18px 0;">Este vehículo todavía no tiene fotos ni vídeos.</div>';

            var pager =
              state.pages > 1
                ? '<div class="tp-row" style="justify-content:center;gap:8px;margin-top:12px;">' +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-prev' + (state.page <= 1 ? " disabled" : "") + ">Anterior</button>" +
                  '<span class="tp-text-sm tp-text-muted" style="align-self:center;">' + state.page + " / " + state.pages + "</span>" +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-media-next' + (state.page >= state.pages ? " disabled" : "") + ">Siguiente</button>" +
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
            var btn = root.querySelector("[data-media-del]");
            if (btn) btn.disabled = !root.querySelectorAll("[data-media-ref]:checked").length;
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
          if (e.target.closest("[data-media-del]")) {
            var refs = [];
            root.querySelectorAll("[data-media-ref]:checked").forEach(function (c) {
              refs.push(c.getAttribute("data-media-ref"));
            });
            if (!refs.length) return;

            tpModal
              .confirm({
                title: "Eliminar archivos",
                message: "Se eliminarán " + refs.length + " archivo(s) del almacenamiento. No se puede deshacer.",
                danger: true,
                confirmLabel: "Eliminar",
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
                      tpModal.toast("No se pudieron eliminar.", "error");
                    }
                  });
              });
          }
        });
      },
    });
  }

  window.tpDeviceMedia = { open: open };
})();
