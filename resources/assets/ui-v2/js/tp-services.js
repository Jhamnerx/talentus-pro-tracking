/* =====================================================
 * TalentusPro · tp-services · Servicios / Mantenimiento
 * Modales COMPARTIDOS sobre tpModal, reutilizables desde
 * Live (widget), la página Mantenimiento y futuros módulos:
 *   tpServices.openForm(deviceId, serviceId?, onSaved?)
 *   tpServices.openList(deviceId, onChange?)
 *   tpServices.remove(serviceId, name?, onDone?)
 *   tpServices.kebabHtml(row)   — celda de acciones (⋮ Editar/Eliminar)
 *   tpServices.barClass(pct)    — umbrales legacy (<20 danger, <50 warn)
 * Config JSON en #tp-services-config (urls + i18n).
 * Backend: Ui\V2\ServicesController → ServiceModalHelper legacy.
 * ===================================================== */
(function () {
  "use strict";

  var CFG = null;

  function cfg() {
    if (CFG) return CFG;
    var el = document.getElementById("tp-services-config");
    if (!el) return null;
    try {
      CFG = JSON.parse(el.textContent);
    } catch (e) {
      CFG = null;
    }
    return CFG;
  }

  function T(key, fb) {
    var c = cfg();
    return (c && c.i18n && c.i18n[key]) || fb || key;
  }

  // Las rutas se generan con id=0 como placeholder (…/services/0).
  function url(key, id) {
    var c = cfg();
    if (!c || !c.urls || !c.urls[key]) return null;
    var u = c.urls[key];
    return id == null ? u : u.replace(/\/0(\?|$)/, "/" + id + "$1");
  }

  function esc(s) {
    return window.tpModal ? tpModal.escapeHtml(s) : String(s == null ? "" : s);
  }

  function todayISO() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, "0");
    var day = String(d.getDate()).padStart(2, "0");
    return d.getFullYear() + "-" + m + "-" + day;
  }

  /* ---------- Kebab (⋮) compartido ---------- */

  var ICON_DOTS =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
  var ICON_EDIT =
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>';
  var ICON_TRASH =
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';

  /** Celda de acciones estándar: ⋮ → Editar / Eliminar. */
  function kebabHtml(row) {
    return (
      '<div class="tp-svc-kebab">' +
      '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="' +
      esc(T("edit")) + " / " + esc(T("delete")) +
      '">' + ICON_DOTS + "</button>" +
      '<div class="tp-svc-kebab__menu" role="menu" hidden>' +
      '<button type="button" role="menuitem" data-svc-act="edit" data-id="' +
      row.id + '" data-device="' + row.device_id + '">' +
      ICON_EDIT + " " + esc(T("edit")) + "</button>" +
      (window.TP_CHECKLISTS_ON
        ? '<button type="button" role="menuitem" data-svc-act="checklists" data-id="' +
          row.id + '" data-name="' + esc(row.name) + '">☑ ' + esc(T("checklists", tpT('ui.checklists'))) + "</button>"
        : "") +
      '<button type="button" role="menuitem" class="is-danger" data-svc-act="del" data-id="' +
      row.id + '" data-device="' + row.device_id + '" data-name="' + esc(row.name) + '">' +
      ICON_TRASH + " " + esc(T("delete")) + "</button>" +
      "</div></div>"
    );
  }

  function closeKebabs(except) {
    document.querySelectorAll(".tp-svc-kebab__menu:not([hidden])").forEach(function (m) {
      if (m !== except) m.setAttribute("hidden", "");
    });
  }

  // Posiciona el menú como "portal" fixed pegado al botón: ningún overflow
  // (tabla/card con pocas filas) lo recorta; abre hacia arriba si no cabe.
  function positionKebabMenu(btn, menu) {
    var r = btn.getBoundingClientRect();
    menu.style.position = "fixed";
    menu.style.right = "auto";
    var w = menu.offsetWidth;
    var h = menu.offsetHeight;
    menu.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + "px";
    if (r.bottom + 4 + h > window.innerHeight - 8 && r.top - h - 4 > 8) {
      menu.style.top = r.top - h - 4 + "px";
    } else {
      menu.style.top = r.bottom + 4 + "px";
    }
  }

  // Abrir/cerrar kebabs (delegado global; las ACCIONES las maneja cada consumidor)
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-svc-kebab]");
    if (btn) {
      var menu = btn.parentNode.querySelector(".tp-svc-kebab__menu");
      var wasOpen = menu && !menu.hasAttribute("hidden");
      closeKebabs();
      if (menu && !wasOpen) {
        menu.removeAttribute("hidden");
        positionKebabMenu(btn, menu);
      }
      e.stopPropagation();
      return;
    }
    closeKebabs();
  });

  // El menú es fixed: al scrollear cualquier contenedor se cierra (si no,
  // quedaría flotando desanclado del botón).
  window.addEventListener(
    "scroll",
    function (e) {
      if (e.target && e.target.closest && e.target.closest(".tp-svc-kebab__menu")) return;
      closeKebabs();
    },
    true,
  );

  /** Umbrales de la barra de progreso (idénticos al /maintenance legacy). */
  function barClass(pct) {
    return pct < 20 ? "is-danger" : pct < 50 ? "is-warn" : "is-ok";
  }

  /* ---------- Modal FORMULARIO (crear / editar) ---------- */

  function fieldHtml(name, label, inputHtml, required) {
    return (
      '<div class="tp-field"><label class="tp-label">' +
      esc(label) + (required ? ' <span class="tp-svc-req">*</span>' : "") +
      "</label>" + inputHtml + "</div>"
    );
  }

  function openForm(deviceId, serviceId, onSaved) {
    if (!cfg() || !window.tpModal) return;
    var metaUrl =
      url("meta") + "?device_id=" + encodeURIComponent(deviceId || 0) +
      (serviceId ? "&id=" + encodeURIComponent(serviceId) : "");

    tpModal.fetchJSON(metaUrl).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        tpModal.toast((res.data && res.data.message) || T("error"), "error");
        return;
      }
      var meta = res.data;
      var item = meta.item;
      var expBy = meta.expiration_by || {};

      var optsHtml = "";
      Object.keys(expBy).forEach(function (k) {
        var sel = item ? item.expiration_by === k : k === "odometer";
        optsHtml +=
          '<option value="' + esc(k) + '"' + (sel ? " selected" : "") + ">" +
          esc(expBy[k]) + "</option>";
      });

      var body =
        '<form class="tp-svc-form" data-svc-form>' +
        fieldHtml("name", T("name"),
          '<input type="text" name="name" class="tp-input" value="' + esc(item ? item.name : "") + '" autocomplete="off">', true) +
        '<div class="tp-svc-form__grid">' +
        fieldHtml("expiration_by", T("expiration_by"),
          '<select name="expiration_by" class="tp-select" data-svc-expby>' + optsHtml + "</select>") +
        fieldHtml("interval", T("interval"),
          '<input type="number" name="interval" class="tp-input" min="1" step="any" value="' + esc(item ? item.interval : "") + '">', true) +
        "</div>" +
        '<div class="tp-svc-form__grid">' +
        fieldHtml("last_service", T("last_service"),
          '<input type="number" name="last_service" class="tp-input" min="0" step="any" data-svc-last>') +
        fieldHtml("trigger_event_left", T("trigger_event_left"),
          '<input type="number" name="trigger_event_left" class="tp-input" min="1" step="any" value="' + esc(item ? item.trigger_event_left : "") + '">', true) +
        "</div>" +
        '<label class="tp-check tp-svc-form__check"><input type="checkbox" name="renew_after_expiration" value="1"' +
        (item && item.renew_after_expiration ? " checked" : "") + "><span>" +
        esc(T("renew_after_expiration")) + "</span></label>" +
        '<div class="tp-svc-form__grid">' +
        fieldHtml("current_odometer", T("current_odometer"),
          '<input type="text" class="tp-input" value="' + esc(meta.odometer_value) + '" disabled>') +
        fieldHtml("current_engine_hours", T("current_engine_hours"),
          '<input type="text" class="tp-input" value="' + esc(meta.engine_hours_value) + '" disabled>') +
        "</div>" +
        fieldHtml("description", T("description"),
          '<textarea name="description" class="tp-textarea" rows="2" maxlength="255">' + esc(item ? item.description : "") + "</textarea>") +
        fieldHtml("email", T("email"),
          '<input type="text" name="email" class="tp-input" value="' + esc(item ? item.email : "") + '" placeholder="a@b.com;c@d.com">' +
          '<div class="tp-hint">' + esc(T("email_semicolon")) + "</div>") +
        "</form>";

      tpModal.open({
        title: serviceId ? T("edit") + " · " + T("service") : T("add_service"),
        size: "lg",
        body: body,
        buttons: [
          { label: T("cancel"), variant: "ghost" },
          {
            label: T("save"),
            variant: "primary",
            close: false,
            onClick: function (dlg) {
              submitForm(dlg, deviceId, serviceId, onSaved);
              return false;
            },
          },
        ],
        onMount: function (dlg) {
          var sel = dlg.querySelector("[data-svc-expby]");
          var syncLast = function (initial) {
            var input = dlg.querySelector("[data-svc-last]");
            if (!input) return;
            if (sel.value === "days") {
              input.type = "date";
              input.removeAttribute("min");
              input.removeAttribute("step");
              input.value =
                initial && item && item.expiration_by === "days" && item.last_service
                  ? item.last_service
                  : todayISO();
            } else {
              input.type = "number";
              input.min = "0";
              input.step = "any";
              input.value =
                initial && item && item.expiration_by === sel.value
                  ? (item.last_service != null ? item.last_service : "")
                  : "";
            }
          };
          sel.addEventListener("change", function () {
            syncLast(false);
          });
          syncLast(true);
          var name = dlg.querySelector('[name="name"]');
          if (name) name.focus();
        },
      });
    });
  }

  function submitForm(dlg, deviceId, serviceId, onSaved) {
    var form = dlg.querySelector("[data-svc-form]");
    if (!form) return;

    var payload = {
      device_id: deviceId,
      name: form.querySelector('[name="name"]').value.trim(),
      expiration_by: form.querySelector('[name="expiration_by"]').value,
      interval: form.querySelector('[name="interval"]').value,
      last_service: form.querySelector('[name="last_service"]').value,
      trigger_event_left: form.querySelector('[name="trigger_event_left"]').value,
      description: form.querySelector('[name="description"]').value,
      email: form.querySelector('[name="email"]').value.trim(),
    };
    // El backend renueva por presencia de la clave (isset) — solo enviarla marcada.
    if (form.querySelector('[name="renew_after_expiration"]').checked)
      payload.renew_after_expiration = 1;
    if (serviceId) payload.id = serviceId;

    tpModal.setBusy(dlg, true);
    tpModal
      .fetchJSON(serviceId ? url("update", serviceId) : url("store"), {
        method: serviceId ? "PUT" : "POST",
        json: payload,
      })
      .then(function (res) {
        tpModal.setBusy(dlg, false);
        if (res.ok && res.data && res.data.ok) {
          tpModal.toast(T("changes_saved"), "success");
          tpModal.close(dlg);
          if (typeof onSaved === "function") onSaved();
          return;
        }
        var errors = (res.data && res.data.errors) || null;
        if (errors) {
          tpModal.showFieldErrors(dlg, errors);
          // 'id' = "servicio ya expirado" (sin input asociado) → toast
          if (errors.id)
            tpModal.toast(Array.isArray(errors.id) ? errors.id[0] : errors.id, "error");
        } else {
          tpModal.toast((res.data && res.data.message) || T("error"), "error");
        }
      });
  }

  /* ---------- Modal LISTA (servicios de un vehículo) ---------- */

  function openList(deviceId, onChange) {
    if (!cfg() || !window.tpModal) return;

    var state = { items: [], q: "", page: 1, limit: 10, deviceName: "" };

    var body =
      '<div class="tp-svc-list">' +
      '<div class="tp-svc-list__bar">' +
      '<input type="text" class="tp-input" data-svc-search placeholder="' + esc(T("search")) + '…" autocomplete="off">' +
      '<button type="button" class="tp-btn tp-btn--primary tp-btn--sm" data-svc-add>+ ' + esc(T("add_service")) + "</button>" +
      "</div>" +
      '<div class="tp-svc-list__wrap"><table class="tp-svc-table">' +
      "<thead><tr><th>" + esc(T("name")) + "</th><th>" + esc(T("expiration_date")) + '</th><th class="tp-svc-table__actions"></th></tr></thead>' +
      "<tbody data-svc-rows></tbody></table></div>" +
      '<div class="tp-svc-list__foot">' +
      '<label class="tp-svc-list__limit">' + esc(T("rows_per_page")) +
      ' <select class="tp-select" data-svc-limit><option>10</option><option>25</option><option>50</option></select></label>' +
      '<div class="tp-svc-list__pager">' +
      '<span data-svc-range>—</span>' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-svc-prev aria-label="' + esc(T("previous")) + '">‹</button>' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-svc-next aria-label="' + esc(T("next")) + '">›</button>' +
      "</div></div></div>";

    var dlg = tpModal.open({
      title: T("services"),
      size: "lg",
      body: body,
      onMount: function (d) {
        load(d);
        bind(d);
      },
    });

    function load(d) {
      tpModal.fetchJSON(url("device", deviceId)).then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast(T("error"), "error");
          return;
        }
        state.items = res.data.items || [];
        state.deviceName = (res.data.device && res.data.device.name) || "";
        var sub = d.querySelector(".tp-modal__sub");
        if (!sub && state.deviceName) {
          sub = document.createElement("div");
          sub.className = "tp-modal__sub";
          d.querySelector(".tp-modal__title").after(sub);
        }
        if (sub) sub.textContent = state.deviceName;
        state.page = 1;
        render(d);
      });
    }

    function filtered() {
      if (!state.q) return state.items;
      var q = state.q.toLowerCase();
      return state.items.filter(function (r) {
        return (r.name || "").toLowerCase().indexOf(q) !== -1;
      });
    }

    function render(d) {
      var rows = filtered();
      var total = rows.length;
      var pages = Math.max(1, Math.ceil(total / state.limit));
      if (state.page > pages) state.page = pages;
      var start = (state.page - 1) * state.limit;
      var slice = rows.slice(start, start + state.limit);

      var html = "";
      slice.forEach(function (r) {
        var cls = r.expired ? " is-expired" : r.expiring ? " is-expiring" : "";
        html +=
          "<tr><td>" + esc(r.name) + "</td>" +
          '<td class="tp-svc-expires' + cls + '">' + esc(r.expiration) + "</td>" +
          '<td class="tp-svc-table__actions">' + kebabHtml(r) + "</td></tr>";
      });
      if (!html)
        html = '<tr><td colspan="3" class="tp-svc-table__empty">' + esc(T("no_services")) + "</td></tr>";
      d.querySelector("[data-svc-rows]").innerHTML = html;

      d.querySelector("[data-svc-range]").textContent = total
        ? start + 1 + "–" + Math.min(start + state.limit, total) + " / " + total
        : "0";
      d.querySelector("[data-svc-prev]").disabled = state.page <= 1;
      d.querySelector("[data-svc-next]").disabled = state.page >= pages;
    }

    function reload() {
      if (dlg && dlg.open) load(dlg);
      if (typeof onChange === "function") onChange();
    }

    function bind(d) {
      d.querySelector("[data-svc-search]").addEventListener("input", function () {
        state.q = this.value.trim();
        state.page = 1;
        render(d);
      });
      d.querySelector("[data-svc-limit]").addEventListener("change", function () {
        state.limit = parseInt(this.value, 10) || 10;
        state.page = 1;
        render(d);
      });
      d.querySelector("[data-svc-prev]").addEventListener("click", function () {
        state.page--;
        render(d);
      });
      d.querySelector("[data-svc-next]").addEventListener("click", function () {
        state.page++;
        render(d);
      });
      d.querySelector("[data-svc-add]").addEventListener("click", function () {
        openForm(deviceId, null, reload);
      });
      d.addEventListener("click", function (e) {
        var act = e.target.closest("[data-svc-act]");
        if (!act) return;
        closeKebabs();
        var id = parseInt(act.getAttribute("data-id"), 10);
        var what = act.getAttribute("data-svc-act");
        if (what === "edit") {
          openForm(deviceId, id, reload);
        } else if (what === "checklists") {
          if (window.tpChecklists) tpChecklists.forService(id, act.getAttribute("data-name"));
        } else {
          remove(id, act.getAttribute("data-name"), reload);
        }
      });
    }
  }

  /* ---------- Eliminar (confirmación compartida) ---------- */

  function remove(serviceId, name, onDone) {
    if (!window.tpModal) return;
    tpModal
      .confirm({
        title: T("delete") + " · " + T("service"),
        message: (name ? '"' + name + '" — ' : "") + T("are_you_sure"),
        danger: true,
        confirmLabel: T("delete"),
        cancelLabel: T("cancel"),
      })
      .then(function (ok) {
        if (!ok) return;
        tpModal
          .fetchJSON(url("destroy", serviceId), { method: "DELETE" })
          .then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast(T("changes_saved"), "success");
              if (typeof onDone === "function") onDone();
            } else {
              var errs = res.data && res.data.errors;
              tpModal.toast(
                tpModal.firstError(errs, (res.data && res.data.message) || T("error")),
                "error",
              );
            }
          });
      });
  }

  window.tpServices = {
    openForm: openForm,
    openList: openList,
    remove: remove,
    kebabHtml: kebabHtml,
    barClass: barClass,
    url: url,
    t: T,
  };
})();
