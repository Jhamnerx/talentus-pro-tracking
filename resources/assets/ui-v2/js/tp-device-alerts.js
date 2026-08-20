/* =====================================================================
 * tp-device-alerts.js · Alertas del vehículo con periodo de vigencia
 * ---------------------------------------------------------------------
 * Paridad DeviceAlerts/{index,time_period}: lista las alertas que aplican
 * al vehículo y permite acotar desde/hasta por pivote (alert_device).
 * Sin fechas, la alerta rige siempre — igual que el legacy.
 * ===================================================================== */
(function () {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  var BASE = "/ui/v2/api/devices";

  function toInput(v) {
    return v ? String(v).replace(" ", "T").slice(0, 16) : "";
  }
  function toApi(v) {
    return v ? String(v).replace("T", " ") : "";
  }

  function periodForm(deviceId, alert, afterSave) {
    var hasFrom = !!alert.active_from;
    var hasTo = !!alert.active_to;

    tpModal.open({
      title: "Vigencia · " + alert.name,
      size: "sm",
      body:
        '<div class="tp-form">' +
        '<label class="tp-choice" style="margin-bottom:6px;">' +
        '<input type="checkbox" class="tp-choice-input" data-da-en-from' + (hasFrom ? " checked" : "") + "> <span>Activa desde</span></label>" +
        '<div class="tp-form__field"><input type="datetime-local" class="tp-input" data-da-from value="' +
        esc(toInput(alert.active_from)) + '"' + (hasFrom ? "" : " disabled") + "></div>" +
        '<label class="tp-choice" style="margin:10px 0 6px;">' +
        '<input type="checkbox" class="tp-choice-input" data-da-en-to' + (hasTo ? " checked" : "") + "> <span>Activa hasta</span></label>" +
        '<div class="tp-form__field"><input type="datetime-local" class="tp-input" data-da-to value="' +
        esc(toInput(alert.active_to)) + '"' + (hasTo ? "" : " disabled") + "></div>" +
        '<small class="tp-form__hint" style="margin-top:8px;display:block;">Sin fechas, la alerta rige siempre para este vehículo.</small>' +
        "</div>",
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            var enFrom = d.querySelector("[data-da-en-from]").checked;
            var enTo = d.querySelector("[data-da-en-to]").checked;
            tpModal
              .fetchJSON(BASE + "/" + deviceId + "/alerts/" + alert.id + "/period", {
                method: "PUT",
                json: {
                  date_from: enFrom ? toApi(d.querySelector("[data-da-from]").value) : null,
                  date_to: enTo ? toApi(d.querySelector("[data-da-to]").value) : null,
                },
              })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Vigencia guardada.", "success");
                  tpModal.close(d);
                  if (typeof afterSave === "function") afterSave();
                  return;
                }
                var errs = (res.data && res.data.errors) || {};
                var first = Object.keys(errs)[0];
                tpModal.toast(first ? [].concat(errs[first])[0] : "No se pudo guardar.", "error");
              });
            return false;
          },
        },
      ],
      onMount: function (d) {
        [["[data-da-en-from]", "[data-da-from]"], ["[data-da-en-to]", "[data-da-to]"]].forEach(function (pair) {
          var chk = d.querySelector(pair[0]);
          var inp = d.querySelector(pair[1]);
          chk.addEventListener("change", function () {
            inp.disabled = !chk.checked;
          });
        });
      },
    });
  }

  function open(deviceId, deviceName) {
    if (!window.tpModal) return;

    tpModal.open({
      title: "Alertas del vehículo" + (deviceName ? " · " + deviceName : ""),
      size: "md",
      body: '<div data-da-index><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      onMount: function (dialog) {
        var host = dialog.querySelector("[data-da-index]");

        function load() {
          tpModal.fetchJSON(BASE + "/" + deviceId + "/alerts").then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              host.innerHTML = '<div class="tp-text-muted tp-text-sm">No se pudo cargar.</div>';
              return;
            }
            var items = res.data.items || [];
            host.__items = items;
            var canEdit = res.data.can_edit !== false;
            var editUrl = res.data.edit_url || "/ui/v2/alerts?edit=";

            host.innerHTML = items.length
              ? items
                  .map(function (a) {
                    var period =
                      a.active_from || a.active_to
                        ? (a.active_from || "…") + " → " + (a.active_to || "…")
                        : "Siempre activa";
                    // El legacy tambien indica a cuantos vehiculos aplica.
                    var alcance =
                      a.devices > 1 ? " · " + a.devices + " vehículos" : a.devices === 1 ? " · 1 vehículo" : "";
                    return (
                      '<div class="tp-acc-row"><span>' +
                      "<b>" + esc(a.name) + "</b>" +
                      (a.active ? "" : ' <span class="tp-text-xs tp-text-muted">(desactivada)</span>') +
                      '<br><span class="tp-text-xs tp-text-muted">' + esc(period) + esc(alcance) + "</span></span>" +
                      '<span class="tp-acc-row__actions">' +
                      (canEdit
                        ? '<a class="tp-btn tp-btn--ghost tp-btn--sm" href="' +
                          esc(editUrl) + a.id + '">Editar alerta</a>'
                        : "") +
                      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-da-edit="' + a.id + '">Vigencia</button>' +
                      "</span></div>"
                    );
                  })
                  .join("")
              : '<div class="tp-text-muted tp-text-sm">Este vehículo no está en ninguna alerta.</div>';
          });
        }
        load();

        host.addEventListener("click", function (e) {
          var ed = e.target.closest("[data-da-edit]");
          if (!ed) return;
          var aid = parseInt(ed.getAttribute("data-da-edit"), 10);
          var a = (host.__items || []).filter(function (x) { return x.id === aid; })[0];
          if (a) periodForm(deviceId, a, load);
        });
      },
    });
  }

  window.tpDeviceAlerts = { open: open };
})();
