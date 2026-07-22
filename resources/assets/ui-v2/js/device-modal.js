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

  // --------------------------------------------------------------------------
  // ── Tab Usuarios (admin) ────────────────────────────────────────────
  // Misma fuente que el multiselect legacy (DeviceUsersController@get):
  // paginador con búsqueda `s`; cada fila trae `selected`. La UI acumula
  // DELTAS en inputs ocultos selected_user_id[] ("id;1;<uid>" asignar /
  // "id;0;<uid>" quitar) que DeviceModalHelper::setAbleUsers() resuelve
  // contra device->users(). Sin cambios → no viaja nada → se preserva.
  // --------------------------------------------------------------------------
  function wireUsersTab(scope) {
    var root = scope.querySelector("[data-tp-users]");
    if (!root) return;

    var url = root.getAttribute("data-users-url");
    var listEl = root.querySelector("[data-users-list]");
    var searchEl = root.querySelector("[data-users-search]");
    var moreBtn = root.querySelector("[data-users-more]");
    var deltasEl = root.querySelector("[data-users-deltas]");

    var page = 1;
    var lastPage = 1;
    var search = "";
    var timer = null;
    var base = {}; // uid -> selected en BD (bool)
    var deltas = {}; // uid -> "1" | "0"

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function rowHtml(row) {
      var uid = String(row.id);
      var sel = deltas[uid] != null ? deltas[uid] === "1" : !!+row.selected;
      return (
        '<label class="tp-choice-row" style="display:flex;align-items:center;gap:9px;padding:4px 2px;cursor:pointer;">' +
        '<input type="checkbox" class="tp-choice-input" data-user-id="' + uid + '"' + (sel ? " checked" : "") + ">" +
        '<span style="font-size:13px;">' + esc(row.name) + "</span>" +
        "</label>"
      );
    }

    function syncDeltas() {
      var html = "";
      Object.keys(deltas).forEach(function (uid) {
        html +=
          '<input type="hidden" name="selected_user_id[]" value="id;' +
          deltas[uid] + ";" + uid + '">';
      });
      deltasEl.innerHTML = html;
    }

    function fetchPage(append) {
      var q = url + (url.indexOf("?") < 0 ? "?" : "&") + "s=" + encodeURIComponent(search) + "&page=" + page;
      tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
        if (!res.ok || !res.data) {
          listEl.innerHTML = '<div class="tp-text-error" style="padding:10px 4px;font-size:13px;">No se pudo cargar la lista.</div>';
          return;
        }
        var rows = res.data.data || [];
        lastPage = res.data.last_page || 1;
        rows.forEach(function (r) {
          if (base[String(r.id)] == null) base[String(r.id)] = !!+r.selected;
        });
        var html = rows.map(rowHtml).join("");
        if (append) listEl.insertAdjacentHTML("beforeend", html);
        else listEl.innerHTML = html || '<div class="tp-text-muted" style="padding:10px 4px;font-size:13px;">Sin resultados.</div>';
        moreBtn.hidden = page >= lastPage;
      });
    }

    listEl.addEventListener("change", function (e) {
      var chk = e.target.closest("[data-user-id]");
      if (!chk) return;
      var uid = chk.getAttribute("data-user-id");
      var original = !!base[uid];
      if (chk.checked === original) delete deltas[uid];
      else deltas[uid] = chk.checked ? "1" : "0";
      syncDeltas();
    });

    if (searchEl)
      searchEl.addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          search = searchEl.value.trim();
          page = 1;
          fetchPage(false);
        }, 300);
      });

    if (moreBtn)
      moreBtn.addEventListener("click", function () {
        page += 1;
        fetchPage(true);
      });

    fetchPage(false);
  }

  // --------------------------------------------------------------------------
  // ── Tab Sensores ────────────────────────────────────────────────────
  // Tabla del device + form en modal ANIDADO (tpModal apila <dialog>).
  // CRUD contra los endpoints v2 que delegan en SensorModalHelper.
  // --------------------------------------------------------------------------
  function wireSensorsTab(scope) {
    var root = scope.querySelector("[data-tp-sensors]");
    if (!root) return;

    var cfg;
    try {
      cfg = JSON.parse(root.querySelector("[data-sensors-cfg]").textContent || "null");
    } catch (e) {
      cfg = null;
    }
    if (!cfg) return;

    var rowsEl = root.querySelector("[data-sensors-rows]");

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function renderRows(items) {
      if (!items.length) {
        rowsEl.innerHTML =
          '<tr data-sensors-empty><td colspan="3" class="tp-text-muted" style="padding:14px 12px;font-size:13px;">Sin datos</td></tr>';
        return;
      }
      rowsEl.innerHTML = items
        .map(function (s) {
          return (
            '<tr data-sensor-id="' + s.id + '">' +
            '<td style="padding:7px 12px;">' + esc(s.name) + "</td>" +
            '<td style="padding:7px 12px;">' + esc(s.type) + "</td>" +
            '<td style="padding:4px 8px;text-align:right;white-space:nowrap;">' +
            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sensor-edit="' + s.id + '" title="Editar">✎</button>' +
            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sensor-del="' + s.id + '" title="Eliminar">🗑</button>' +
            "</td></tr>"
          );
        })
        .join("");
    }

    function reload() {
      tpModal.fetchJSON(cfg.list, { method: "GET" }).then(function (res) {
        if (res.ok && res.data && res.data.ok) renderRows(res.data.items || []);
      });
    }

    root.addEventListener("click", function (e) {
      var add = e.target.closest("[data-sensor-add]");
      if (add) {
        openSensorForm(cfg, null, reload);
        return;
      }
      var edit = e.target.closest("[data-sensor-edit]");
      if (edit) {
        openSensorForm(cfg, edit.getAttribute("data-sensor-edit"), reload);
        return;
      }
      var del = e.target.closest("[data-sensor-del]");
      if (del) {
        var sid = del.getAttribute("data-sensor-del");
        tpModal
          .confirm({
            title: "Eliminar sensor",
            message: "¿Eliminar este sensor? Esta acción no se puede deshacer.",
            danger: true,
            confirmLabel: "Eliminar",
          })
          .then(function (okGo) {
            if (!okGo) return;
            tpModal
              .fetchJSON(cfg.destroy.replace("{id}", sid), { method: "DELETE" })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Sensor eliminado.", "success");
                  reload();
                } else {
                  tpModal.toast((res.data && res.data.message) || "No se pudo eliminar.", "error");
                }
              });
          });
      }
    });
  }

  // Serializador del form de sensor: soporta calibrations[x] (objeto),
  // mappings[i][k] (array de objetos) y claves planas. Los inputs de
  // bloques ocultos van disabled → FormData los excluye (paridad legacy).
  function readSensorForm(form) {
    var fd = new FormData(form);
    var data = {};
    fd.forEach(function (value, key) {
      var m2 = /^([^\[]+)\[([^\]]*)\]\[([^\]]*)\]$/.exec(key);
      if (m2) {
        data[m2[1]] = data[m2[1]] || {};
        data[m2[1]][m2[2]] = data[m2[1]][m2[2]] || {};
        data[m2[1]][m2[2]][m2[3]] = value;
        return;
      }
      var m1 = /^([^\[]+)\[([^\]]*)\]$/.exec(key);
      if (m1) {
        if (m1[2] === "") {
          if (!Array.isArray(data[m1[1]])) data[m1[1]] = [];
          data[m1[1]].push(value);
        } else {
          if (!data[m1[1]] || typeof data[m1[1]] !== "object") data[m1[1]] = {};
          data[m1[1]][m1[2]] = value;
        }
        return;
      }
      data[key] = value;
    });
    if (data.mappings) data.mappings = Object.values(data.mappings);
    return data;
  }

  function openSensorForm(cfg, sensorId, onSaved) {
    var isEdit = !!sensorId;

    var dlg = tpModal.open({
      title: isEdit ? "Editar sensor" : "Nuevo sensor",
      size: "lg",
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            var form = dialog.querySelector("form.tp-sensor-form");
            if (!form) return false;
            var payload = readSensorForm(form);
            var url = isEdit ? cfg.update.replace("{id}", sensorId) : cfg.store;
            tpModal.setBusy(dialog, true);
            tpModal
              .fetchJSON(url, { method: isEdit ? "PUT" : "POST", json: payload })
              .then(function (res) {
                tpModal.setBusy(dialog, false);
                if (res.status === 422) {
                  tpModal.showFieldErrors(dialog, (res.data && res.data.errors) || {});
                  tpModal.toast("Revisa los campos marcados.", "warning");
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast((res.data && res.data.message) || "No se pudo guardar el sensor.", "error");
                  return;
                }
                tpModal.toast(isEdit ? "Sensor actualizado." : "Sensor creado.", "success");
                tpModal.close(dialog);
                if (typeof onSaved === "function") onSaved();
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

    var formUrl = isEdit ? cfg.formEdit.replace("{id}", sensorId) : cfg.formNew;
    tpModal.fetchJSON(formUrl, { method: "GET" }).then(function (res) {
      var bodyEl = dlg.querySelector(".tp-modal__body");
      if (!bodyEl) return;
      if (!res.ok || !res.data || !res.data.ok) {
        bodyEl.innerHTML =
          '<div class="tp-text-center tp-text-error" style="padding:24px;">No se pudo cargar el formulario.</div>';
        return;
      }
      bodyEl.innerHTML = res.data.html;
      wireSensorForm(dlg, cfg);
    });

    return dlg;
  }

  // Port del JS inline legacy (Frontend/Sensors/form.blade.php):
  // bloques [data-sensor-input] por tipo + shown_value_by, habilitadores
  // data-enable, paneles calibración/mapping y vista previa.
  function wireSensorForm(dlg, cfg) {
    var form = dlg.querySelector("form.tp-sensor-form");
    if (!form) return;

    var types;
    try {
      types = JSON.parse(form.querySelector("[data-tp-sensor-types]").textContent || "[]");
    } catch (e) {
      types = [];
    }
    if (!Array.isArray(types)) types = Object.values(types);

    var activeInputs = {};

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function currentType() {
      var v = form.querySelector('select[name="sensor_type"]').value;
      for (var i = 0; i < types.length; i++) if (types[i].type == v) return types[i];
      return null;
    }

    function setBlock(key, on) {
      var blocks = form.querySelectorAll('[data-sensor-input="' + key + '"]');
      Array.prototype.forEach.call(blocks, function (el) {
        el.style.display = on ? "" : "none";
        Array.prototype.forEach.call(el.querySelectorAll("input, select, textarea"), function (i) {
          i.disabled = !on;
        });
      });
    }

    function refreshEnables() {
      Array.prototype.forEach.call(form.querySelectorAll("[data-enable]"), function (el) {
        var chk = form.querySelector("#" + el.getAttribute("data-enable"));
        if (!chk) return;
        var block = el.closest("[data-sensor-input]");
        var blockOn = !block || block.style.display !== "none";
        var on = blockOn && chk.checked && !chk.disabled;
        el.disabled = !on;
        if (el.getAttribute("data-enable-hide")) el.style.visibility = on ? "" : "hidden";
      });
    }

    function syncPanels() {
      ["calibration", "mapping"].forEach(function (k) {
        var panel = form.querySelector('[data-panel="' + k + '"]');
        if (!panel) return;
        var chk = form.querySelector('input[type="checkbox"][name="' + k + '"]');
        var on = !!activeInputs[k] && chk && chk.checked && !chk.disabled;
        panel.style.display = on ? "" : "none";
        Array.prototype.forEach.call(panel.querySelectorAll("input, select, textarea"), function (i) {
          i.disabled = !on;
        });
      });
    }

    function populateShownBy(t) {
      var sel = form.querySelector('select[name="shown_value_by"]');
      var prev = sel.getAttribute("data-value") || sel.value;
      sel.innerHTML = "";
      Object.keys(t.shown_by).forEach(function (k) {
        var o = document.createElement("option");
        o.value = k;
        o.textContent = t.shown_by[k];
        sel.appendChild(o);
      });
      if (prev && t.shown_by[prev] != null) sel.value = prev;
      sel.removeAttribute("data-value");
      if (window.tpSelect && tpSelect.refresh) {
        try {
          tpSelect.refresh(sel);
        } catch (e) {}
      }
    }

    function applyInputs(t) {
      activeInputs = {};
      Array.prototype.forEach.call(form.querySelectorAll("[data-sensor-input]"), function (el) {
        el.style.display = "none";
        Array.prototype.forEach.call(el.querySelectorAll("input, select, textarea"), function (i) {
          i.disabled = true;
        });
      });

      var inputs = null;
      if (t.shown_by) {
        setBlock("shown_value_by", true);
        activeInputs.shown_value_by = true;
        inputs = t.inputs[form.querySelector('select[name="shown_value_by"]').value];
      }
      if (!inputs) {
        var keys = Object.keys(t.inputs || {});
        inputs = keys.length ? t.inputs[keys[0]] : {};
      }
      Object.keys(inputs || {}).forEach(function (k) {
        if (inputs[k]) {
          setBlock(k, true);
          activeInputs[k] = true;
        }
      });

      refreshEnables();
      syncPanels();
    }

    function applyType() {
      var t = currentType();
      if (!t) return;
      if (t.shown_by) populateShownBy(t);
      applyInputs(t);
    }

    // ── Eventos delegados ──
    form.addEventListener("change", function (e) {
      var tgt = e.target;
      if (tgt.name === "sensor_type") {
        applyType();
        return;
      }
      if (tgt.name === "shown_value_by") {
        var t = currentType();
        if (t) applyInputs(t);
        return;
      }
      if (tgt.type === "checkbox") {
        refreshEnables();
        syncPanels();
      }
    });

    // ── Calibraciones ──
    var calRows = form.querySelector("[data-cal-rows]");
    form.addEventListener("click", function (e) {
      var addCal = e.target.closest("[data-cal-add]");
      if (addCal) {
        var xEl = form.querySelector("[data-cal-x]");
        var yEl = form.querySelector("[data-cal-y]");
        var x = (xEl.value || "").trim();
        var y = (yEl.value || "").trim();
        var bad = false;
        xEl.style.borderColor = "";
        yEl.style.borderColor = "";
        if (x === "" || isNaN(+x) || calRows.querySelector('input[name="calibrations[' + x + ']"]')) {
          xEl.style.borderColor = "#ef4444";
          bad = true;
        }
        if (y === "" || isNaN(+y)) {
          yEl.style.borderColor = "#ef4444";
          bad = true;
        }
        if (bad) return;
        calRows.insertAdjacentHTML(
          "beforeend",
          "<tr>" +
            '<td style="padding:5px 10px;">' + esc(x) + '<input type="hidden" name="calibrations[' + esc(x) + ']" value="' + esc(y) + '"></td>' +
            '<td style="padding:5px 10px;">' + esc(y) + "</td>" +
            '<td style="text-align:center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-cal-del>×</button></td>' +
            "</tr>",
        );
        xEl.value = "";
        yEl.value = "";
        return;
      }
      var delCal = e.target.closest("[data-cal-del]");
      if (delCal) {
        delCal.closest("tr").remove();
        return;
      }

      // ── Mappings ──
      var addMap = e.target.closest("[data-map-add]");
      if (addMap) {
        addMappingRow();
        return;
      }
      var delMap = e.target.closest("[data-map-del]");
      if (delMap) {
        delMap.closest("tr").remove();
        return;
      }

      // ── Vista previa ──
      var prev = e.target.closest("[data-sensor-preview]");
      if (prev) {
        var payload = readSensorForm(form);
        tpModal.fetchJSON(cfg.preview, { method: "POST", json: payload }).then(function (res) {
          var out = form.querySelector("[data-sensor-preview-result]");
          if (res.ok && res.data && res.data.ok) {
            if (out) out.value = res.data.formatted == null ? "—" : res.data.formatted;
          } else if (res.status === 422) {
            tpModal.showFieldErrors(dlg, (res.data && res.data.errors) || {});
            tpModal.toast("Revisa los campos marcados.", "warning");
          } else {
            tpModal.toast((res.data && res.data.message) || "No se pudo previsualizar.", "error");
          }
        });
      }
    });

    // Controles del alta de mapping (rango / tipo de valor mapeado)
    var mapVt = form.querySelector("[data-map-vt]");
    var mapMt = form.querySelector("[data-map-mt]");
    var mapV = form.querySelector("[data-map-v]");
    var mapT = form.querySelector("[data-map-t]");
    var mapMv = form.querySelector("[data-map-mv]");
    var mapIndex = 1000; // los índices server-rendered van de 0..n

    function syncMapControls() {
      if (!mapVt) return;
      var range = mapVt.value === "2";
      mapT.style.display = range ? "" : "none";
      var mt = mapMt.value;
      mapMv.style.display = mt === "3" ? "none" : "";
      if (mt === "2" && !mapMv.value) mapMv.value = "[value]";
    }
    if (mapVt) mapVt.addEventListener("change", syncMapControls);
    if (mapMt) mapMt.addEventListener("change", syncMapControls);
    syncMapControls();

    function addMappingRow() {
      var mapRows = form.querySelector("[data-map-rows]");
      var vt = mapVt.value;
      var mt = mapMt.value;
      var v = (mapV.value || "").trim();
      var t = (mapT.value || "").trim();
      var mv = (mapMv.value || "").trim();

      mapV.style.borderColor = "";
      mapT.style.borderColor = "";
      if (vt === "2") {
        var bad = false;
        if (v === "" || isNaN(+v)) {
          mapV.style.borderColor = "#ef4444";
          bad = true;
        }
        if (t === "" || isNaN(+t) || parseFloat(t) < parseFloat(v)) {
          mapT.style.borderColor = "#ef4444";
          bad = true;
        }
        if (bad) return;
      } else if (v === "") {
        mapV.style.borderColor = "#ef4444";
        return;
      }

      var i = mapIndex++;
      var hid = '<input type="hidden" name="mappings[' + i + '][vt]" value="' + esc(vt) + '">' +
        '<input type="hidden" name="mappings[' + i + '][v]" value="' + esc(v) + '">';
      if (vt === "2") hid += '<input type="hidden" name="mappings[' + i + '][t]" value="' + esc(t) + '">';
      var hidMt = '<input type="hidden" name="mappings[' + i + '][mt]" value="' + esc(mt) + '">';
      if (mt !== "3") hidMt += '<input type="hidden" name="mappings[' + i + '][mv]" value="' + esc(mv) + '">';

      mapRows.insertAdjacentHTML(
        "beforeend",
        "<tr>" +
          '<td style="padding:5px 10px;">' + esc(vt === "2" ? v + " .. " + t : v) + hid + "</td>" +
          '<td style="padding:5px 10px;">' + esc(mt === "3" ? "[value]" : mv) + hidMt + "</td>" +
          "<td></td>" +
          '<td style="text-align:center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-map-del>×</button></td>' +
          "</tr>",
      );
      mapV.value = "";
      mapT.value = "";
      mapMv.value = "";
    }

    // Estado inicial según el tipo seleccionado (create: primero del catálogo)
    applyType();
  }

  // Lectura del formulario (FormData → objeto plano + soporte arrays)
  // --------------------------------------------------------------------------
  function readForm(form) {
    var fd = new FormData(form);
    var data = {};
    fd.forEach(function (value, key) {
      // Soporta name="selected_user_id[]" → data.selected_user_id = [v1, v2…]
      var arr = /^(.+)\[\]$/.exec(key);
      if (arr) {
        var ak = arr[1];
        if (!Array.isArray(data[ak])) data[ak] = [];
        data[ak].push(value);
        return;
      }
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
        wireUsersTab(bodyEl);
        wireSensorsTab(bodyEl);
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
