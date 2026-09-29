/* =====================================================================
 * tp-device-config.js · Configuración remota del GPS por SMS
 * ---------------------------------------------------------------------
 * Paridad DeviceConfig/index: elige vehículo (solo los que tienen SIM),
 * marca de GPS y APN, y envía los comandos por SMS al equipo.
 * Gate: able('configure_device') — el backend responde 403 si no.
 * ===================================================================== */
(function () {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  var BASE = "/ui/v2/api/device-config";
  var META = null;

  function opts(list, sel) {
    return list
      .map(function (o) {
        return '<option value="' + o.id + '"' + (String(sel) === String(o.id) ? " selected" : "") + ">" + esc(o.name) + "</option>";
      })
      .join("");
  }

  function render(deviceId) {
    var devs = META.devices || [];
    var cfgs = META.configs || [];
    var apns = META.apns || [];

    if (!devs.length) {
      return '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_vehicle_has_sim') + '</div>';
    }
    if (!cfgs.length) {
      return '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_gps_brands_configured') + '</div>';
    }

    return (
      '<div class="tp-form">' +
      '<label class="tp-form__field"><span class="tp-form__label">' + tpT('ui.vehicle') + '</span>' +
      '<select class="tp-input" data-dc-device>' + opts(devs, deviceId) + "</select></label>" +
      '<label class="tp-form__field"><span class="tp-form__label">' + tpT('ui.gps_brand') + '</span>' +
      '<select class="tp-input" data-dc-config>' + opts(cfgs, null) + "</select></label>" +
      '<label class="tp-form__field"><span class="tp-form__label">' + tpT('ui.apn_config') + '</span>' +
      '<select class="tp-input" data-dc-apn>' + opts(apns, null) + "</select></label>" +
      '<label class="tp-form__field"><span class="tp-form__label">APN</span>' +
      '<input type="text" class="tp-input" data-dc-apn-name></label>' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      '<label class="tp-form__field"><span class="tp-form__label">Usuario</span>' +
      '<input type="text" class="tp-input" data-dc-apn-user></label>' +
      '<label class="tp-form__field"><span class="tp-form__label">' + tpT('attr.password') + '</span>' +
      '<input type="text" class="tp-input" data-dc-apn-pass></label>' +
      "</div>" +
      '<small class="tp-form__hint">' + tpT('ui.apn_sms_note') + '</small>' +
      "</div>"
    );
  }

  function open(deviceId) {
    if (!window.tpModal) return;

    var dlg = tpModal.open({
      title: "Configurar GPS",
      size: "md",
      body: '<div class="tp-text-muted tp-text-sm">Cargando…</div>',
      buttons: [
        { label: tpT('global.close'), variant: "ghost", close: true },
        {
          label: tpT('ui.send_config'),
          variant: "primary",
          close: false,
          onClick: function (d) {
            var dev = d.querySelector("[data-dc-device]");
            var cfg = d.querySelector("[data-dc-config]");
            if (!dev || !cfg) return false;

            tpModal.setBusy(d, true);
            tpModal
              .fetchJSON(BASE, {
                method: "POST",
                json: {
                  device_id: dev.value,
                  config_id: cfg.value,
                  apn_name: (d.querySelector("[data-dc-apn-name]") || {}).value || "",
                  apn_username: (d.querySelector("[data-dc-apn-user]") || {}).value || "",
                  apn_password: (d.querySelector("[data-dc-apn-pass]") || {}).value || "",
                },
              })
              .then(function (res) {
                tpModal.setBusy(d, false);
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast(tpT('ui.commands_sent_to_unit'), "success");
                  tpModal.close(d);
                  return;
                }
                var errs = (res.data && res.data.errors) || {};
                var first = Object.keys(errs)[0];
                tpModal.toast(
                  first ? [].concat(errs[first])[0] : (res.data && res.data.message) || tpT('ui.send_failed'),
                  "error",
                );
              })
              .catch(function () {
                tpModal.setBusy(d, false);
                tpModal.toast(tpT('ui.network_error'), "error");
              });
            return false;
          },
        },
      ],
      onMount: function (d) {
        var body = d.querySelector(".tp-modal__body") || d;

        var paint = function () {
          body.innerHTML = render(deviceId);
          var apnSel = d.querySelector("[data-dc-apn]");
          if (!apnSel) return;

          var fill = function () {
            var a = (META.apns || []).filter(function (x) { return String(x.id) === String(apnSel.value); })[0];
            if (!a) return;
            d.querySelector("[data-dc-apn-name]").value = a.apn_name || "";
            d.querySelector("[data-dc-apn-user]").value = a.apn_username || "";
            d.querySelector("[data-dc-apn-pass]").value = a.apn_password || "";
          };
          apnSel.addEventListener("change", fill);
          fill();
        };

        if (META) return paint();

        tpModal.fetchJSON(BASE + "/meta").then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            body.innerHTML = '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_permission_configure_units') + '</div>';
            return;
          }
          META = res.data;
          paint();
        });
      },
    });

    return dlg;
  }

  window.tpDeviceConfig = { open: open };
})();
