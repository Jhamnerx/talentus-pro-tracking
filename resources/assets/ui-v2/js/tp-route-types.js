/* =====================================================================
 * tp-route-types.js · Tipos de trayecto por periodo (DeviceRouteType)
 * ---------------------------------------------------------------------
 * Paridad DeviceRoutesType/{show,create,edit}: por vehículo, una lista de
 * periodos marcados como Privado / Negocio / N-A que consumen los reportes
 * de uso. Las fechas viajan en la TZ del usuario (el backend hace el
 * reverse/convert).
 * ===================================================================== */
(function () {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  var BASE = "/ui/v2/api";

  // "YYYY-MM-DD HH:MM:SS" (backend) ⇄ "YYYY-MM-DDTHH:MM" (input datetime-local)
  function toInput(v) {
    return v ? String(v).replace(" ", "T").slice(0, 16) : "";
  }
  function toApi(v) {
    return v ? String(v).replace("T", " ") : "";
  }

  function form(deviceId, types, item, afterSave) {
    var isEdit = !!(item && item.id);

    tpModal.open({
      title: isEdit ? tpT('ui.edit_period') : tpT('ui.new_period'),
      size: "sm",
      body:
        '<div class="tp-form">' +
        '<label class="tp-form__field"><span class="tp-form__label">' + tpT('front.type') + '</span>' +
        '<select class="tp-input" data-rt-type>' +
        types
          .map(function (t) {
            return (
              '<option value="' + t.id + '"' +
              (item && String(item.type) === String(t.id) ? " selected" : "") + ">" + esc(t.label) + "</option>"
            );
          })
          .join("") +
        "</select></label>" +
        '<label class="tp-form__field"><span class="tp-form__label">' + tpT('front.from') + '</span>' +
        '<input type="datetime-local" class="tp-input" data-rt-from value="' + esc(toInput(item && item.started_at)) + '"></label>' +
        '<label class="tp-form__field"><span class="tp-form__label">' + tpT('global.to') + '</span>' +
        '<input type="datetime-local" class="tp-input" data-rt-to value="' + esc(toInput(item && item.ended_at)) + '"></label>' +
        "</div>",
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('global.save'),
          variant: "primary",
          close: false,
          onClick: function (d) {
            var payload = {
              type: d.querySelector("[data-rt-type]").value,
              started_at: toApi(d.querySelector("[data-rt-from]").value),
              ended_at: toApi(d.querySelector("[data-rt-to]").value),
            };
            if (!payload.started_at || !payload.ended_at) {
              tpModal.toast(tpT('ui.indicates_full_period'), "warning");
              return false;
            }

            tpModal
              .fetchJSON(
                isEdit ? BASE + "/route-types/" + item.id : BASE + "/devices/" + deviceId + "/route-types",
                { method: isEdit ? "PUT" : "POST", json: payload },
              )
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Guardado.", "success");
                  tpModal.close(d);
                  if (typeof afterSave === "function") afterSave();
                  return;
                }
                var errs = (res.data && res.data.errors) || {};
                var first = Object.keys(errs)[0];
                tpModal.toast(first ? [].concat(errs[first])[0] : tpT('ui.save_failed'), "error");
              });
            return false;
          },
        },
      ],
    });
  }

  function open(deviceId, deviceName) {
    if (!window.tpModal) return;

    tpModal.open({
      title: tpT('ui.trip_types') + (deviceName ? " · " + deviceName : ""),
      size: "md",
      body: '<div data-rt-index><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      onMount: function (dialog) {
        var host = dialog.querySelector("[data-rt-index]");
        var types = [];

        function load() {
          tpModal.fetchJSON(BASE + "/devices/" + deviceId + "/route-types").then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              host.innerHTML = '<div class="tp-text-muted tp-text-sm">' + tpT('ui.load_failed') + '</div>';
              return;
            }
            types = res.data.types || [];
            var items = res.data.items || [];

            host.innerHTML =
              '<div class="tp-row" style="justify-content:flex-end;margin-bottom:8px;">' +
              '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-rt-add>' + tpT('ui.new_period_plus') + '</button></div>' +
              (items.length
                ? items
                    .map(function (r) {
                      return (
                        '<div class="tp-acc-row"><span>' +
                        "<b>" + esc(r.type_label) + "</b> · " + esc(r.started_at) + " → " + esc(r.ended_at) +
                        "</span>" +
                        '<span class="tp-row" style="gap:6px;">' +
                        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-rt-edit="' + r.id + '">' + tpT('global.edit') + '</button>' +
                        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-rt-del="' + r.id + '" style="color:#ef4444;">' + tpT('global.delete') + '</button>' +
                        "</span></div>"
                      );
                    })
                    .join("")
                : '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_periods_defined') + '</div>');

            host.__items = items;
          });
        }
        load();

        host.addEventListener("click", function (e) {
          var add = e.target.closest("[data-rt-add]");
          if (add) return form(deviceId, types, null, load);

          var ed = e.target.closest("[data-rt-edit]");
          if (ed) {
            var eid = parseInt(ed.getAttribute("data-rt-edit"), 10);
            var it = (host.__items || []).filter(function (x) { return x.id === eid; })[0];
            return form(deviceId, types, it, load);
          }

          var del = e.target.closest("[data-rt-del]");
          if (del) {
            tpModal
              .confirm({ title: tpT('ui.delete_period'), message: tpT('ui.confirm_delete_period'), danger: true, confirmLabel: tpT('global.delete') })
              .then(function (ok) {
                if (!ok) return;
                tpModal
                  .fetchJSON(BASE + "/route-types/" + del.getAttribute("data-rt-del"), { method: "DELETE" })
                  .then(function (res) {
                    if (res.ok && res.data && res.data.ok) {
                      tpModal.toast("Eliminado.", "success");
                      load();
                    } else tpModal.toast(tpT('ui.delete_failed'), "error");
                  });
              });
          }
        });
      },
    });
  }

  window.tpRouteTypes = { open: open };
})();
