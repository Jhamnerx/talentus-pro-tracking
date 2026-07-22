/*
 * TalentusPro · device-modal.js
 * ---------------------------------------------------------------
 * Modal de creación / edición del dominio *Device* (vehículo).
 * Expuesto como window.tpDeviceModal y compartido por:
 *   - live page   (Live Ops "+ Nuevo")
 *   - fleet page  (Fleet "+ Nuevo" + edición por fila)
 *   - admin → fleet
 *
 * Estrategia: el HTML del formulario se renderiza con Blade
 * (resources/views/ui/v2/modals/devices/*) y se entrega vía:
 *   GET /ui/v2/api/devices/form        → form de creación
 *   GET /ui/v2/api/devices/{id}/form   → form de edición
 *
 * Este script SOLO se encarga de:
 *   1. Abrir el shell del modal (window.tpModal).
 *   2. Pedir el HTML al backend e inyectarlo.
 *   3. Wirear pestañas, galería de iconos, submit.
 *
 * Endpoints de submit (sin cambios):
 *   POST   /ui/v2/api/devices            → crear
 *   PUT    /ui/v2/api/devices/{id}       → actualizar
 *
 * Depende de window.tpModal (modal.js).
 * ---------------------------------------------------------------
 */
(function () {
  "use strict";

  // Config desde <script id="tp-device-modal-config" type="application/json">
  function readConfig() {
    var el = document.getElementById("tp-device-modal-config");
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || "null");
    } catch (e) {
      return null;
    }
  }

  var CFG = readConfig() || {
    formNewUrl: "/ui/v2/api/devices/form",
    formUrl: "/ui/v2/api/devices/{id}/form",
    storeUrl: "/ui/v2/api/devices",
    updateUrl: "/ui/v2/api/devices/{id}",
    canCreate: true,
    canEdit: true,
  };

  // --------------------------------------------------------------------------
  // Wire-ups después de inyectar el HTML
  // --------------------------------------------------------------------------
  function wireTabs(scope) {
    var tabs = scope.querySelectorAll("[data-tp-tab]");
    var panels = scope.querySelectorAll("[data-tp-panel]");
    Array.prototype.forEach.call(tabs, function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.getAttribute("data-tp-tab");
        Array.prototype.forEach.call(tabs, function (b) {
          b.classList.toggle("is-active", b === btn);
        });
        Array.prototype.forEach.call(panels, function (p) {
          p.classList.toggle(
            "is-active",
            p.getAttribute("data-tp-panel") === key,
          );
        });
      });
    });
  }

  function wireIconGallery(scope) {
    var items = scope.querySelectorAll(".tp-icon-gallery__item");
    Array.prototype.forEach.call(items, function (it) {
      it.addEventListener("click", function (ev) {
        Array.prototype.forEach.call(items, function (b) {
          b.classList.remove("is-selected");
        });
        it.classList.add("is-selected");
        var radio = it.querySelector('input[type="radio"]');
        if (radio && ev.target !== radio) radio.checked = true;
      });
    });
  }

  function wireExpirationMirror(scope) {
    // Mantiene enable_expiration_date sincronizado con expiration_date.
    var dateInput = scope.querySelector('input[name="expiration_date"]');
    var mirror = scope.querySelector("[data-tp-mirror-expiration]");
    if (!dateInput || !mirror) return;
    dateInput.addEventListener("change", function () {
      mirror.value = dateInput.value ? "1" : "0";
    });
  }

  // --------------------------------------------------------------------------
  // ── Colores del recorrido (trips / single / speed / sensor) ─────────
  function wireRouteColors(scope) {
    var root = scope.querySelector("[data-tp-routecolors]");
    if (!root) return;
    var jsonInput = root.querySelector("[data-rc-json]");
    var rowsEl = root.querySelector("[data-rc-rows]");
    var barEl = root.querySelector("[data-rc-bar]");
    var DEFAULTS = [
      { from: 0, to: 40, color: "#22d3ee" },
      { from: 40, to: 60, color: "#3b82f6" },
      { from: 60, to: 90, color: "#6366f1" },
      { from: 90, to: 120, color: "#a855f7" },
      { from: 120, to: null, color: "#ef4444" },
    ];
    var ranges;
    try {
      ranges = JSON.parse(jsonInput.value || "[]");
    } catch (e) {
      ranges = [];
    }
    if (!ranges.length) ranges = DEFAULTS.slice();

    function normalize() {
      // contiguos y ascendentes; el último siempre abierto (to = null)
      ranges.sort(function (a, b) {
        return (a.from || 0) - (b.from || 0);
      });
      var from = 0;
      ranges.forEach(function (r, i) {
        r.from = from;
        if (i === ranges.length - 1) r.to = null;
        else {
          var to = parseFloat(r.to);
          if (isNaN(to) || to <= from) to = from + 10;
          r.to = to;
          from = to;
        }
      });
    }
    function sync() {
      normalize();
      jsonInput.value = JSON.stringify(ranges);
      renderRows();
      renderBar();
    }
    function renderRows() {
      rowsEl.innerHTML = ranges
        .map(function (r, i) {
          var last = i === ranges.length - 1;
          return (
            '<span class="tp-routecolors__row" data-idx="' + i + '">' +
            '<input type="number" class="tp-input tp-input--sm" value="' + r.from + '" disabled style="width:64px;">' +
            '<span class="tp-routecolors__dash">–</span>' +
            (last
              ? '<span class="tp-routecolors__inf">∞</span>'
              : '<input type="number" class="tp-input tp-input--sm" data-rc-to value="' + r.to + '" min="1" style="width:64px;">') +
            '<input type="color" data-rc-color value="' + (r.color || "#2563eb") + '">' +
            (ranges.length > 1
              ? '<button type="button" class="tp-routecolors__del" data-rc-del title="Quitar">×</button>'
              : "") +
            "</span>"
          );
        })
        .join("");
    }
    function renderBar() {
      if (!barEl) return;
      var maxB = ranges.length > 1 ? ranges[ranges.length - 1].from : 100;
      var scale = maxB > 0 ? maxB : 100;
      barEl.innerHTML = ranges
        .map(function (r, i) {
          var last = i === ranges.length - 1;
          var w = last ? 18 : Math.max(6, ((r.to - r.from) / scale) * 82);
          return (
            '<span style="flex:0 0 ' + w + "%;background:" + r.color + '" title="' +
            r.from + (last ? "+" : "–" + r.to) + '"></span>'
          );
        })
        .join("");
    }

    rowsEl.addEventListener("change", function (e) {
      var row = e.target.closest("[data-idx]");
      if (!row) return;
      var i = parseInt(row.getAttribute("data-idx"), 10);
      if (e.target.hasAttribute("data-rc-to")) ranges[i].to = parseFloat(e.target.value);
      if (e.target.hasAttribute("data-rc-color")) ranges[i].color = e.target.value;
      sync();
    });
    rowsEl.addEventListener("click", function (e) {
      var del = e.target.closest("[data-rc-del]");
      if (!del) return;
      e.preventDefault();
      var i = parseInt(del.closest("[data-idx]").getAttribute("data-idx"), 10);
      ranges.splice(i, 1);
      sync();
    });
    var addBtn = root.querySelector("[data-rc-add]");
    if (addBtn)
      addBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var lastFrom = ranges.length > 1 ? ranges[ranges.length - 1].from : 0;
        ranges.splice(ranges.length - 1, 0, { from: lastFrom, to: lastFrom + 20, color: "#94a3b8" });
        // seleccionar "por velocidad" al editar rangos
        var radio = root.querySelector('input[name="route_color_type"][value="speed"]');
        if (radio) radio.checked = true;
        markActive();
        sync();
      });
    var resetBtn = root.querySelector("[data-rc-reset]");
    if (resetBtn)
      resetBtn.addEventListener("click", function (e) {
        e.preventDefault();
        ranges = DEFAULTS.slice().map(function (r) {
          return { from: r.from, to: r.to, color: r.color };
        });
        sync();
      });

    // Swatches rápidos del modo "único"
    Array.prototype.forEach.call(root.querySelectorAll("[data-rc-swatch]"), function (sw) {
      sw.addEventListener("click", function (e) {
        e.preventDefault();
        var input = root.querySelector("[data-rc-single]");
        if (input) input.value = sw.getAttribute("data-rc-swatch");
        var radio = root.querySelector('input[name="route_color_type"][value="single"]');
        if (radio) radio.checked = true;
        markActive();
      });
    });

    // Atenuar los paneles de modos no seleccionados
    function markActive() {
      var checked = root.querySelector('input[name="route_color_type"]:checked');
      root.setAttribute("data-active", checked ? checked.value : "trips");
    }
    Array.prototype.forEach.call(root.querySelectorAll('input[name="route_color_type"]'), function (r) {
      r.addEventListener("change", markActive);
    });
    markActive();
    sync();
  }

  // Lectura del formulario (FormData → objeto plano + soporte arrays)
  // --------------------------------------------------------------------------
  function readForm(form) {
    var fd = new FormData(form);
    var data = {};
    fd.forEach(function (value, key) {
      // Soporta name="icon_colors[moving]" → data.icon_colors.moving
      var m = /^([^\[]+)\[([^\]]+)\]$/.exec(key);
      if (m) {
        var k = m[1];
        var sub = m[2];
        if (!data[k] || typeof data[k] !== "object") data[k] = {};
        data[k][sub] = value;
        return;
      }
      // Checkboxes con hidden previo: el último valor gana (FormData) → ok.
      data[key] = value;
    });
    // Normaliza booleanos esperados por DeviceModalHelper
    ["active", "lbs", "valid_by_avg_speed", "gprs_templates_only"].forEach(
      function (k) {
        if (k in data) data[k] = data[k] == "1" ? 1 : 0;
      },
    );
    return data;
  }

  // --------------------------------------------------------------------------
  // Carga del HTML del formulario
  // --------------------------------------------------------------------------
  function fetchFormHtml(id) {
    var url = id
      ? CFG.formUrl.replace("{id}", encodeURIComponent(id))
      : CFG.formNewUrl;
    return tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        throw new Error((res.data && res.data.message) || "form_load_failed");
      }
      return res.data.html;
    });
  }

  // --------------------------------------------------------------------------
  // API pública
  // --------------------------------------------------------------------------
  function open(opts) {
    opts = opts || {};
    var isEdit = !!opts.id;
    if (isEdit && !CFG.canEdit) {
      tpModal.toast && tpModal.toast("Sin permiso para editar.", "error");
      return;
    }
    if (!isEdit && !CFG.canCreate) {
      tpModal.toast &&
        tpModal.toast("Sin permiso para crear vehículos.", "error");
      return;
    }

    var dlg = tpModal.open({
      title: isEdit ? "Editar vehículo" : "Nuevo vehículo",
      sub: isEdit
        ? "Modifica los datos del equipo y guarda."
        : "Da de alta una unidad: configuración técnica completa.",
      size: "xl",
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: isEdit ? "Guardar cambios" : "Crear vehículo",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            var form = dialog.querySelector("form.tp-device-form");
            if (!form) return;
            var payload = readForm(form);
            var url = isEdit
              ? CFG.updateUrl.replace("{id}", encodeURIComponent(opts.id))
              : CFG.storeUrl;
            var method = isEdit ? "PUT" : "POST";
            tpModal.setBusy(dialog, true);
            tpModal
              .fetchJSON(url, { method: method, json: payload })
              .then(function (res) {
                tpModal.setBusy(dialog, false);
                if (res.status === 422) {
                  tpModal.showFieldErrors(
                    dialog,
                    (res.data && res.data.errors) || {},
                  );
                  tpModal.toast("Revisa los campos marcados.", "warning");
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast(
                    (res.data && res.data.message) ||
                      (isEdit
                        ? "No se pudo guardar."
                        : "No se pudo crear el vehículo."),
                    "error",
                  );
                  return;
                }
                tpModal.toast(
                  isEdit ? "Vehículo actualizado." : "Vehículo creado.",
                  "success",
                );
                tpModal.close(dialog);
                if (typeof opts.onSaved === "function") {
                  try {
                    opts.onSaved(res.data.item || { id: res.data.id });
                  } catch (_) {}
                }
              })
              .catch(function () {
                tpModal.setBusy(dialog, false);
                tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
    });

    fetchFormHtml(opts.id)
      .then(function (html) {
        var bodyEl = dlg.querySelector(".tp-modal__body");
        if (!bodyEl) return;
        bodyEl.innerHTML = html;
        wireTabs(bodyEl);
        wireIconGallery(bodyEl);
        wireExpirationMirror(bodyEl);
        wireRouteColors(bodyEl);
      })
      .catch(function () {
        var bodyEl = dlg.querySelector(".tp-modal__body");
        if (bodyEl) {
          bodyEl.innerHTML =
            '<div class="tp-text-center tp-text-error" style="padding:24px;">' +
            "No se pudo cargar el formulario.</div>";
        }
      });

    return dlg;
  }

  window.tpDeviceModal = {
    openCreate: function (onSaved) {
      return open({ onSaved: onSaved });
    },
    openEdit: function (id, onSaved) {
      return open({ id: id, onSaved: onSaved });
    },
    open: open,
    invalidateMeta: function () {
      /* compat: ya no hay caché client-side */
    },
  };
})();
