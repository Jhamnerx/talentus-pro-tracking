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
    // Beacons: kind distinto y endpoints propios (DeviceModalHelper rechaza
    // beacons; el legacy los edita por BeaconsController).
    beaconStoreUrl: "/ui/v2/api/beacons",
    beaconUpdateUrl: "/ui/v2/api/beacons/{id}",
    destroyUrl: "/ui/v2/api/devices/{id}",
    canCreate: true,
    canEdit: true,
    canRemove: false,
    objectDeletePass: false,
  };

  /**
   * Escape de HTML a nivel de modulo.
   * Varias funciones internas declaran su propio `esc` y lo tapan sin
   * problema; el que NO lo declaraba se quedaba sin nada y reventaba con
   * "esc is not defined" (pasaba en el modal de copia de sensores y, sin
   * detectar, en el tab de Mantenimientos al pintar filas).
   */
  function esc(v) {
    return tpModal.escapeHtml(v);
  }

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

  function wireExpiration(scope) {
    // Paridad legacy: expiración = FECHA + HORA. El checkbox habilita date+HH+MM
    // y se combinan en el hidden name="expiration_date" (YYYY-MM-DD HH:MM).
    var toggle = scope.querySelector("[data-tp-expiration-toggle]");
    var dateInput = scope.querySelector("[data-tp-exp-date]");
    var hSel = scope.querySelector("[data-tp-exp-h]");
    var mSel = scope.querySelector("[data-tp-exp-m]");
    var hidden = scope.querySelector("[data-tp-exp-value]");
    if (!toggle || !dateInput || !hidden) return;

    function setEnabled(on) {
      dateInput.disabled = !on;
      if (hSel) hSel.disabled = !on;
      if (mSel) mSel.disabled = !on;
    }
    function combine() {
      var dv = (dateInput.value || "").trim();
      hidden.value =
        toggle.checked && dv ? dv + " " + (hSel ? hSel.value : "00") + ":" + (mSel ? mSel.value : "00") : "";
    }

    toggle.addEventListener("change", function () { setEnabled(toggle.checked); combine(); });
    dateInput.addEventListener("change", combine);
    if (hSel) hSel.addEventListener("change", combine);
    if (mSel) mSel.addEventListener("change", combine);
    setEnabled(toggle.checked);
    combine();
  }

  // Habilitadores genéricos: [data-enable="<idCheckbox>"] → el input se
  // habilita solo con el checkbox marcado (mismo patrón que el sensor form).
  function wireEnablers(scope) {
    function refresh() {
      Array.prototype.forEach.call(scope.querySelectorAll("[data-enable]"), function (el) {
        var chk = scope.querySelector("#" + el.getAttribute("data-enable"));
        if (!chk) return;
        el.disabled = !(chk.checked && !chk.disabled);
        // tp-select no re-sincroniza su botón al togglear el disabled del
        // <select> nativo → re-enhancear para que el dropdown se habilite/
        // deshabilite de verdad (bug: "Detectar cambio de combustible…"
        // quedaba bloqueado al marcar el checkbox).
        if (el.tagName === "SELECT" && el._tpdd && window.tpSelect && tpSelect.refresh) {
          try { tpSelect.refresh(el); } catch (e) {}
        }
      });
    }
    scope.addEventListener("change", function (e) {
      if (e.target.type === "checkbox") refresh();
    });
    refresh();
  }

  // "Detección de motor ON/OFF por" (detect_engine) solo aplica cuando
  // "Detección de ignición" = sensor engine_hours; se muestra/oculta según el
  // valor de engine_hours (paridad legacy .ignition_detection_engine en app.js).
  function wireIgnitionEngine(scope) {
    var box = scope.querySelector("[data-ignition-engine]");
    var sel = scope.querySelector('select[name="engine_hours"]');
    if (!box || !sel) return;
    function apply() {
      box.style.display = sel.value === "engine_hours" ? "" : "none";
    }
    scope.addEventListener("change", function (e) {
      if (e.target && e.target.name === "engine_hours") apply();
    });
    apply();
  }

  // Labels dinámicos de combustible (paridad legacy: fuel_title /
  // distance_title / cost_title cambian según la medición elegida).
  function wireFuelLabels(scope) {
    var root = scope.querySelector("[data-tp-fuel]");
    if (!root) return;
    var measurements;
    try {
      measurements = JSON.parse(root.querySelector("[data-tp-fuel-measurements]").textContent || "[]");
    } catch (e) {
      measurements = [];
    }
    if (!Array.isArray(measurements)) measurements = Object.values(measurements);
    var sel = root.querySelector('select[name="fuel_measurement_id"]');
    if (!sel) return;

    function apply() {
      var m = null;
      for (var i = 0; i < measurements.length; i++) {
        if (String(measurements[i].id) === String(sel.value)) {
          m = measurements[i];
          break;
        }
      }
      if (!m) return;
      var ft = root.querySelector("[data-fuel-title]");
      var dt = root.querySelector("[data-distance-title]");
      var ct = root.querySelector("[data-cost-title]");
      if (ft) ft.textContent = m.fuel_title || "";
      if (dt) dt.textContent = m.distance_title || "";
      if (ct) ct.textContent = m.cost_title || "";
    }

    sel.addEventListener("change", apply);
    apply();
  }

  // Tab Iconos — paridad legacy: arrow → SOLO colores por estado;
  // icon/rotating → SOLO la galería de su grupo (radios de grupos
  // ocultos quedan disabled para que icon_id no viaje).
  function wireIconsTab(scope) {
    var typeSel = scope.querySelector("[data-tp-icons-type]");
    if (!typeSel) return;

    // La galería del grupo se pide la primera vez que se muestra: el catálogo
    // pasa del millar de iconos y embeberlo hacía el formulario inmanejable.
    function loadGallery(box) {
      if (!box || box.getAttribute("data-loaded") === "1") return;
      box.setAttribute("data-loaded", "1");

      tpModal
        .fetchJSON(box.getAttribute("data-url"))
        .then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            box.innerHTML = '<span class="tp-text-muted tp-text-sm">No se pudieron cargar los iconos.</span>';
            return;
          }
          var items = res.data.items || [];
          box.innerHTML = items.length
            ? items
                .map(function (i) {
                  return (
                    '<label class="tp-icon-gallery__item' + (i.selected ? " is-selected" : "") +
                    '" data-icon-id="' + i.id + '">' +
                    '<input type="radio" name="icon_id" value="' + i.id + '"' + (i.selected ? " checked" : "") + ">" +
                    '<img src="' + tpModal.escapeHtml(i.url) + '" alt="" loading="lazy"></label>'
                  );
                })
                .join("")
            : '<span class="tp-text-muted tp-text-sm">Sin iconos en este grupo.</span>';

          // Los radios recién insertados siguen la regla del grupo visible
          var group = box.closest("[data-tp-icons-group]");
          var visible = group && group.style.display !== "none";
          box.querySelectorAll('input[type="radio"]').forEach(function (r) {
            r.disabled = !visible;
          });
        })
        .catch(function () {
          box.innerHTML = '<span class="tp-text-muted tp-text-sm">No se pudieron cargar los iconos.</span>';
        });
    }

    function apply() {
      var type = typeSel.value;
      Array.prototype.forEach.call(scope.querySelectorAll("[data-tp-icons-group]"), function (g) {
        var on = g.getAttribute("data-tp-icons-group") === type;
        g.style.display = on ? "" : "none";
        if (on) loadGallery(g.querySelector("[data-tp-icons-lazy]"));
        Array.prototype.forEach.call(g.querySelectorAll('input[type="radio"]'), function (r) {
          r.disabled = !on;
        });
      });
      var colors = scope.querySelector("[data-tp-icons-colors]");
      if (colors) {
        var isArrow = type === "arrow";
        colors.style.display = isArrow ? "" : "none";
        Array.prototype.forEach.call(colors.querySelectorAll("select"), function (s) {
          s.disabled = !isArrow;
        });
      }
    }

    typeSel.addEventListener("change", apply);
    apply();
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
    var pagEl = root.querySelector("[data-users-pagination]");
    var deltasEl = root.querySelector("[data-users-deltas]");
    var selAllBtn = root.querySelector("[data-users-select-all]");
    var deselAllBtn = root.querySelector("[data-users-deselect-all]");

    var page = 1;
    var lastPage = 1;
    var search = "";
    var timer = null;
    // Modelo de selección estilo EntityLoader:
    //   mode null → estado BD (base) + overrides individuales
    //   mode 'all'/'none' → "s;1;"/"s;0;" + overrides posteriores
    var mode = null;
    var base = {}; // uid -> selected en BD (bool)
    var overrides = {}; // uid -> bool

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function isChecked(uid) {
      if (uid in overrides) return overrides[uid];
      if (mode === "all") return true;
      if (mode === "none") return false;
      return !!base[uid];
    }

    function rowHtml(row) {
      var uid = String(row.id);
      return (
        '<label class="tp-choice-row" style="display:flex;align-items:center;gap:9px;padding:5px 2px;cursor:pointer;">' +
        '<input type="checkbox" class="tp-choice-input" data-user-id="' + uid + '"' + (isChecked(uid) ? " checked" : "") + ">" +
        '<span style="font-size:13px;">' + esc(row.name) + "</span>" +
        "</label>"
      );
    }

    // Serializa la selección al formato de secuencia del EntityLoader.
    function syncDeltas() {
      var ops = [];
      if (mode === "all") ops.push("s;1;");
      if (mode === "none") ops.push("s;0;");
      Object.keys(overrides).forEach(function (uid) {
        var on = overrides[uid];
        if (mode === "all" && on) return; // redundante
        if (mode === "none" && !on) return;
        if (mode === null && on === !!base[uid]) return; // sin cambio real
        ops.push("id;" + (on ? "1" : "0") + ";" + uid);
      });
      deltasEl.innerHTML = ops
        .map(function (op) {
          return '<input type="hidden" name="selected_user_id[]" value="' + op + '">';
        })
        .join("");
    }

    function renderPagination() {
      if (!pagEl) return;
      if (lastPage <= 1) {
        pagEl.hidden = true;
        return;
      }
      pagEl.hidden = false;
      var html = '<button type="button" data-users-page="' + (page - 1) + '"' + (page <= 1 ? " disabled" : "") + ">‹</button>";
      var start = Math.max(1, page - 3);
      var end = Math.min(lastPage, start + 6);
      for (var p = start; p <= end; p++) {
        html += '<button type="button" data-users-page="' + p + '" class="' + (p === page ? "is-current" : "") + '">' + p + "</button>";
      }
      html += '<button type="button" data-users-page="' + (page + 1) + '"' + (page >= lastPage ? " disabled" : "") + ">›</button>";
      pagEl.innerHTML = html;
    }

    function fetchPage() {
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
        listEl.innerHTML =
          rows.map(rowHtml).join("") ||
          '<div class="tp-text-muted" style="padding:10px 4px;font-size:13px;">Sin resultados.</div>';
        renderPagination();
      });
    }

    listEl.addEventListener("change", function (e) {
      var chk = e.target.closest("[data-user-id]");
      if (!chk) return;
      var uid = chk.getAttribute("data-user-id");
      overrides[uid] = chk.checked;
      syncDeltas();
    });

    if (pagEl)
      pagEl.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-users-page]");
        if (!btn || btn.disabled) return;
        var p = parseInt(btn.getAttribute("data-users-page"), 10);
        if (p < 1 || p > lastPage || p === page) return;
        page = p;
        fetchPage();
      });

    if (selAllBtn)
      selAllBtn.addEventListener("click", function () {
        mode = "all";
        overrides = {};
        syncDeltas();
        fetchPage();
      });

    if (deselAllBtn)
      deselAllBtn.addEventListener("click", function () {
        mode = "none";
        overrides = {};
        syncDeltas();
        fetchPage();
      });

    if (searchEl)
      searchEl.addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          search = searchEl.value.trim();
          page = 1;
          fetchPage();
        }, 300);
      });

    fetchPage();
  }

  // --------------------------------------------------------------------------
  // ── Kebab de acciones (⋮ → Editar / Eliminar) ───────────────────────
  // Mismo markup/estilos que tpServices.kebabHtml (.tp-svc-kebab, css en
  // services.css); el TOGGLE lo maneja el delegado global de tp-services.js
  // ([data-svc-kebab]) — por eso fleet también carga tp-services.
  // --------------------------------------------------------------------------
  var K_DOTS =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
  var K_EDIT =
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>';
  var K_TRASH =
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';

  function kebabCell(editAttr, delAttr, id, editExtra) {
    return (
      '<div class="tp-svc-kebab">' +
      '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="Acciones">' +
      K_DOTS +
      "</button>" +
      '<div class="tp-svc-kebab__menu" role="menu" hidden>' +
      '<button type="button" role="menuitem" ' + editAttr + '="' + id + '"' + (editExtra || "") + ">" +
      K_EDIT + " Editar</button>" +
      '<button type="button" role="menuitem" class="is-danger" ' + delAttr + '="' + id + '">' +
      K_TRASH + " Eliminar</button>" +
      "</div></div>"
    );
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
    var searchEl = root.querySelector("[data-sensors-search]");

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function applyFilter() {
      if (!searchEl) return;
      var q = searchEl.value.trim().toLowerCase();
      Array.prototype.forEach.call(rowsEl.querySelectorAll("tr[data-sensor-id]"), function (tr) {
        tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) >= 0 ? "" : "none";
      });
    }
    if (searchEl) searchEl.addEventListener("input", applyFilter);

    // Sensores que se estan mostrando. NO vale guardar solo lo que llega
    // por AJAX: la primera vez las filas las pinta Blade (sensores.blade)
    // y renderRows() no ha corrido todavia, asi que el modal de copia
    // creia que el vehiculo no tenia ninguno. Se leen del DOM, que es lo
    // que el usuario ve.
    function sensoresVisibles() {
      var out = [];
      Array.prototype.forEach.call(rowsEl.querySelectorAll("tr[data-sensor-id]"), function (tr) {
        var tds = tr.querySelectorAll("td");
        out.push({
          id: parseInt(tr.getAttribute("data-sensor-id"), 10),
          name: tds[0] ? tds[0].textContent.trim() : "",
          type: tds[1] ? tds[1].textContent.trim() : "",
        });
      });
      return out;
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
            "<td>" + esc(s.name) + "</td>" +
            "<td>" + esc(s.type) + "</td>" +
            '<td style="text-align:right;">' + kebabCell("data-sensor-edit", "data-sensor-del", s.id) + "</td></tr>"
          );
        })
        .join("");
      applyFilter();
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
      if (e.target.closest("[data-sensors-copy]")) {
        openSensorsCopy(cfg, sensoresVisibles(), reload);
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

  // --------------------------------------------------------------------------
  // ── Tab Servicios (mantenimiento) ───────────────────────────────────
  // Reutiliza los modales compartidos de tp-services.js; la lista se
  // re-hidrata desde ui.v2.api.services.device/{id}.
  // --------------------------------------------------------------------------
  function wireServicesTab(scope) {
    var root = scope.querySelector("[data-tp-services-tab]");
    if (!root) return;

    var deviceId = root.getAttribute("data-device-id");
    var listUrl = root.getAttribute("data-list-url");
    var destroyUrl = root.getAttribute("data-destroy-url");
    var rowsEl = root.querySelector("[data-services-rows]");
    var searchEl = root.querySelector("[data-services-search]");

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function applyFilter() {
      if (!searchEl) return;
      var q = searchEl.value.trim().toLowerCase();
      Array.prototype.forEach.call(rowsEl.querySelectorAll("tr[data-service-id]"), function (tr) {
        tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) >= 0 ? "" : "none";
      });
    }
    if (searchEl) searchEl.addEventListener("input", applyFilter);

    function renderRows(items) {
      if (!items.length) {
        rowsEl.innerHTML =
          '<tr data-services-empty><td colspan="3" class="tp-text-muted" style="padding:14px 12px;font-size:13px;">Sin datos</td></tr>';
        return;
      }
      rowsEl.innerHTML = items
        .map(function (s) {
          var exp = s.expiration != null ? s.expiration : s.expiration_date || "";
          return (
            '<tr data-service-id="' + s.id + '">' +
            "<td>" + esc(s.name || "") + "</td>" +
            "<td>" + esc(exp) + "</td>" +
            '<td style="text-align:right;">' + kebabCell("data-service-edit", "data-service-del", s.id) + "</td></tr>"
          );
        })
        .join("");
      applyFilter();
    }

    function reload() {
      tpModal.fetchJSON(listUrl, { method: "GET" }).then(function (res) {
        if (res.ok && res.data && res.data.ok) renderRows(res.data.items || []);
      });
    }

    root.addEventListener("click", function (e) {
      if (!window.tpServices) return;
      var add = e.target.closest("[data-service-add]");
      if (add) {
        tpServices.openForm(deviceId, null, reload);
        return;
      }
      var edit = e.target.closest("[data-service-edit]");
      if (edit) {
        tpServices.openForm(deviceId, edit.getAttribute("data-service-edit"), reload);
        return;
      }
      var del = e.target.closest("[data-service-del]");
      if (del) {
        var sid = del.getAttribute("data-service-del");
        tpModal
          .confirm({
            title: "Eliminar servicio",
            message: "¿Eliminar este servicio?",
            danger: true,
            confirmLabel: "Eliminar",
          })
          .then(function (okGo) {
            if (!okGo) return;
            tpModal.fetchJSON(destroyUrl.replace("{id}", sid), { method: "DELETE" }).then(function (res) {
              if (res.ok && res.data && (res.data.ok || res.data.status)) {
                tpModal.toast("Servicio eliminado.", "success");
                reload();
              } else {
                tpModal.toast("No se pudo eliminar.", "error");
              }
            });
          });
      }
    });
  }

  // --------------------------------------------------------------------------
  // ── Tab Cámaras ─────────────────────────────────────────────────────
  // Form nativo mínimo (paridad legacy: name + show_widget); las
  // credenciales FTP las genera el job del backend al crear.
  // --------------------------------------------------------------------------
  /* ===== Configuración remota del GPS en el alta (able configure_device) ===== */
  function wireConfigurator(scope) {
    var chk = scope.querySelector('[data-toggle-panel="configurator"]');
    var panel = scope.querySelector('[data-panel="configurator"]');
    if (!chk || !panel) return;

    var sync = function () {
      panel.style.display = chk.checked ? "" : "none";
    };
    chk.addEventListener("change", sync);
    sync();

    // El selector de APN rellena nombre/usuario/contraseña, igual que el
    // legacy (DeviceConfig/form.blade → device_config.get_apn_data).
    var apnSel = scope.querySelector("[data-apn-config]");
    if (!apnSel) return;

    var fill = function () {
      var id = apnSel.value;
      if (!id) return;
      tpModal
        .fetchJSON(apnSel.getAttribute("data-apn-url") + "/" + id)
        .then(function (res) {
          var d = res.data && res.data.success;
          if (!d) return;
          var set = function (sel, v) {
            var el = scope.querySelector(sel);
            if (el) el.value = v == null ? "" : v;
          };
          set("[data-apn-name]", d.apn_name);
          set("[data-apn-username]", d.apn_username);
          set("[data-apn-password]", d.apn_password);
        })
        .catch(function () {});
    };
    apnSel.addEventListener("change", fill);
    fill();
  }

  /* ===== Tab Historial: mantenimientos / suspensiones ===== */
  // Reusa los endpoints legacy device_maintenances.* (validan can('edit') y
  // sincronizan con el ERP); aquí solo se pinta y se envía.
  function wireMaintenances(scope) {
    var root = scope.querySelector("[data-tp-maint]");
    if (!root) return;

    var cfg;
    try {
      cfg = JSON.parse(root.querySelector("[data-maint-cfg]").textContent || "null");
    } catch (e) {
      cfg = null;
    }
    if (!cfg) return;

    var rowsEl = root.querySelector("[data-maint-rows]");

    var BADGES = {
      mantenimiento: { label: "Mantenimiento", color: "#3b82f6" },
      suspension: { label: "Suspensión", color: "#ef4444" },
      reactivacion: { label: "Reactivación", color: "#22c55e" },
    };

    function empty(msg) {
      rowsEl.innerHTML =
        '<tr><td colspan="7" class="tp-text-muted" style="padding:14px 12px;font-size:13px;">' + esc(msg) + "</td></tr>";
    }

    function load() {
      empty("Cargando…");
      tpModal
        .fetchJSON(cfg.list)
        .then(function (res) {
          var items = (res.data && res.data.items) || [];
          if (!items.length) return empty("Sin registros aún.");

          rowsEl.innerHTML = items
            .map(function (m) {
              var b = BADGES[m.tipo] || { label: m.tipo, color: "#64748b" };
              return (
                "<tr>" +
                '<td><span class="tp-maint-badge" style="background:' + b.color + ';">' + esc(b.label) + "</span></td>" +
                "<td>" + esc(m.fecha) + "</td>" +
                "<td>" + esc(m.motivo || "—") + "</td>" +
                "<td>" + esc(m.notas || "—") + "</td>" +
                '<td style="font-size:12px;">' + esc(m.user || "—") + "</td>" +
                '<td title="' + (m.synced ? "Sincronizado" : "Pendiente de sincronizar") + '">' +
                (m.synced ? "✅" : "⏳") + "</td>" +
                '<td style="text-align:center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" ' +
                'data-maint-del="' + m.id + '" style="color:#ef4444;">×</button></td>' +
                "</tr>"
              );
            })
            .join("");
        })
        .catch(function () {
          empty("Error al cargar los datos.");
        });
    }

    root.querySelector("[data-maint-save]").addEventListener("click", function (btn) {
      var el = btn.currentTarget;
      var motivo = (root.querySelector("[data-maint-motivo]").value || "").trim();
      if (!motivo) {
        tpModal.toast("El campo Motivo es obligatorio.", "warning");
        return;
      }
      el.disabled = true;
      tpModal
        .fetchJSON(cfg.store, {
          method: "POST",
          json: {
            tipo: root.querySelector("[data-maint-tipo]").value,
            fecha: root.querySelector("[data-maint-fecha]").value,
            motivo: motivo,
            notas: (root.querySelector("[data-maint-notas]").value || "").trim(),
          },
        })
        .then(function (res) {
          el.disabled = false;
          if (res.ok && res.data && res.data.status === 1) {
            root.querySelector("[data-maint-motivo]").value = "";
            root.querySelector("[data-maint-notas]").value = "";
            tpModal.toast("Registro guardado.", "success");
            load();
            return;
          }
          var errs = (res.data && res.data.errors) || {};
          var first = Object.keys(errs)[0];
          tpModal.toast(
            first ? [].concat(errs[first])[0] : (res.data && res.data.message) || "No se pudo guardar.",
            "error",
          );
        })
        .catch(function () {
          el.disabled = false;
          tpModal.toast("Error de red.", "error");
        });
    });

    rowsEl.addEventListener("click", function (e) {
      var del = e.target.closest("[data-maint-del]");
      if (!del) return;
      tpModal
        .confirm({ title: "Eliminar registro", message: "¿Eliminar este registro del historial?", danger: true, confirmLabel: "Eliminar" })
        .then(function (ok) {
          if (!ok) return;
          tpModal
            .fetchJSON(cfg.destroy.replace("{id}", del.getAttribute("data-maint-del")), { method: "DELETE" })
            .then(function (res) {
              if (res.ok && res.data && res.data.status === 1) {
                tpModal.toast("Registro eliminado.", "success");
                load();
              } else {
                tpModal.toast("No se pudo eliminar.", "error");
              }
            });
        });
    });

    load();
  }

  function wireCamerasTab(scope) {
    var root = scope.querySelector("[data-tp-cameras]");
    if (!root) return;

    var cfg;
    try {
      cfg = JSON.parse(root.querySelector("[data-cameras-cfg]").textContent || "null");
    } catch (e) {
      cfg = null;
    }
    if (!cfg) return;

    var rowsEl = root.querySelector("[data-cameras-rows]");

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function renderRows(items) {
      if (!items.length) {
        rowsEl.innerHTML =
          '<tr data-cameras-empty><td colspan="4" class="tp-text-muted" style="padding:14px 12px;font-size:13px;">Sin datos</td></tr>';
        return;
      }
      rowsEl.innerHTML = items
        .map(function (c) {
          return (
            '<tr data-camera-id="' + c.id + '">' +
            "<td>" + esc(c.name) + "</td>" +
            "<td>" + (c.show_widget ? "Sí" : "No") + "</td>" +
            "<td>" + esc(c.ftp_username || "") + "</td>" +
            '<td style="text-align:right;">' +
            kebabCell("data-camera-edit", "data-camera-del", c.id,
              ' data-name="' + esc(c.name) + '" data-widget="' + (c.show_widget ? 1 : 0) + '"') +
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

    function openForm(id, name, widget) {
      var isEdit = !!id;
      tpModal.open({
        title: isEdit ? "Editar cámara" : "Nueva cámara",
        size: "sm",
        static: true,
        body:
          '<form class="tp-form tp-camera-form">' +
          '<div class="tp-form__grid">' +
          '<label class="tp-form__field tp-form__field--required">' +
          '<span class="tp-form__label">Nombre</span>' +
          '<input type="text" name="name" value="' + esc(name || "") + '" data-tp-field>' +
          "</label>" +
          '<label class="tp-form__field tp-form__field--checkbox">' +
          '<input type="checkbox" name="show_widget" value="1"' + (widget ? " checked" : "") + ">" +
          '<span class="tp-form__label">Mostrar widget</span>' +
          "</label>" +
          "</div></form>",
        buttons: [
          { label: "Cancelar", variant: "ghost", close: true },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog) {
              var form = dialog.querySelector("form.tp-camera-form");
              var payload = {
                name: form.querySelector('[name="name"]').value.trim(),
                show_widget: form.querySelector('[name="show_widget"]').checked ? 1 : 0,
              };
              var url = isEdit ? cfg.update.replace("{id}", id) : cfg.store;
              tpModal.setBusy(dialog, true);
              tpModal
                .fetchJSON(url, { method: isEdit ? "PUT" : "POST", json: payload })
                .then(function (res) {
                  tpModal.setBusy(dialog, false);
                  if (res.status === 422) {
                    tpModal.showFieldErrors(dialog, (res.data && res.data.errors) || {});
                    return;
                  }
                  if (!res.ok || !res.data || !res.data.ok) {
                    tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
                    return;
                  }
                  tpModal.toast("Cámara guardada.", "success");
                  tpModal.close(dialog);
                  reload();
                });
              return false;
            },
          },
        ],
      });
    }

    root.addEventListener("click", function (e) {
      var add = e.target.closest("[data-camera-add]");
      if (add) {
        openForm(null);
        return;
      }
      var edit = e.target.closest("[data-camera-edit]");
      if (edit) {
        openForm(edit.getAttribute("data-camera-edit"), edit.getAttribute("data-name"), edit.getAttribute("data-widget") === "1");
        return;
      }
      var del = e.target.closest("[data-camera-del]");
      if (del) {
        var cid = del.getAttribute("data-camera-del");
        tpModal
          .confirm({ title: "Eliminar cámara", message: "¿Eliminar esta cámara?", danger: true, confirmLabel: "Eliminar" })
          .then(function (okGo) {
            if (!okGo) return;
            tpModal.fetchJSON(cfg.destroy.replace("{id}", cid), { method: "DELETE" }).then(function (res) {
              if (res.ok && res.data && res.data.ok) {
                tpModal.toast("Cámara eliminada.", "success");
                reload();
              } else {
                tpModal.toast("No se pudo eliminar.", "error");
              }
            });
          });
      }
    });
  }

  // --------------------------------------------------------------------------
  // ── Tab Gastos ──────────────────────────────────────────────────────
  // Form nativo con los campos del legacy DeviceExpenses (name, date,
  // type_id, quantity, unit_cost, supplier, buyer, additional).
  // --------------------------------------------------------------------------
  function wireExpensesTab(scope) {
    var root = scope.querySelector("[data-tp-expenses]");
    if (!root) return;

    var cfg;
    try {
      cfg = JSON.parse(root.querySelector("[data-expenses-cfg]").textContent || "null");
    } catch (e) {
      cfg = null;
    }
    if (!cfg) return;

    var rowsEl = root.querySelector("[data-expenses-rows]");
    var searchEl = root.querySelector("[data-expenses-search]");
    var cache = {}; // id -> item completo (para editar)

    function esc(s) {
      return tpModal.escapeHtml(s);
    }

    function applyFilter() {
      if (!searchEl) return;
      var q = searchEl.value.trim().toLowerCase();
      Array.prototype.forEach.call(rowsEl.querySelectorAll("tr[data-expense-id]"), function (tr) {
        tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) >= 0 ? "" : "none";
      });
    }
    if (searchEl) searchEl.addEventListener("input", applyFilter);

    function renderRows(items) {
      cache = {};
      items.forEach(function (it) {
        cache[String(it.id)] = it;
      });
      if (!items.length) {
        rowsEl.innerHTML =
          '<tr data-expenses-empty><td colspan="6" class="tp-text-muted" style="padding:14px 12px;font-size:13px;">Sin datos</td></tr>';
        return;
      }
      rowsEl.innerHTML = items
        .map(function (it) {
          return (
            '<tr data-expense-id="' + it.id + '">' +
            "<td>" + esc(it.name) + "</td>" +
            "<td>" + esc(it.quantity) + "</td>" +
            "<td>" + esc(it.unit_cost) + "</td>" +
            "<td>" + esc(it.total) + "</td>" +
            "<td>" + esc(it.date || "") + "</td>" +
            '<td style="text-align:right;">' + kebabCell("data-expense-edit", "data-expense-del", it.id) + "</td></tr>"
          );
        })
        .join("");
      applyFilter();
    }

    function reload() {
      tpModal.fetchJSON(cfg.list, { method: "GET" }).then(function (res) {
        if (res.ok && res.data && res.data.ok) renderRows(res.data.items || []);
      });
    }

    function typeOptions(sel) {
      return (cfg.types || [])
        .map(function (t) {
          return '<option value="' + t.id + '"' + (String(sel) === String(t.id) ? " selected" : "") + ">" + esc(t.title) + "</option>";
        })
        .join("");
    }

    function openForm(item) {
      var isEdit = !!(item && item.id);
      item = item || {};
      var dateVal = item.date ? String(item.date).substring(0, 10) : "";
      var timeVal = item.date && String(item.date).length >= 16 ? String(item.date).substring(11, 16) : "";
      tpModal.open({
        title: isEdit ? "Editar gasto" : "Nuevo gasto",
        static: true,
        body:
          '<form class="tp-form tp-expense-form">' +
          '<div class="tp-form__grid tp-form__grid--2">' +
          '<label class="tp-form__field tp-form__field--required tp-col-2">' +
          '<span class="tp-form__label">Nombre</span>' +
          '<input type="text" name="name" value="' + esc(item.name || "") + '" data-tp-field></label>' +
          '<label class="tp-form__field tp-form__field--required">' +
          '<span class="tp-form__label">Fecha</span>' +
          '<div style="display:flex;gap:8px;">' +
          // el input date lo mejora tp-datepicker (name pasa al hidden ISO)
          '<input type="date" name="date" value="' + esc(dateVal) + '" style="flex:1;" data-tp-field>' +
          '<input type="time" data-exp-time value="' + esc(timeVal) + '" style="width:104px;">' +
          "</div></label>" +
          '<label class="tp-form__field tp-form__field--required">' +
          '<span class="tp-form__label">Tipo</span>' +
          '<select class="tp-input" style="width:100%" name="type_id" data-tp-field>' + typeOptions(item.type_id) + "</select></label>" +
          '<label class="tp-form__field tp-form__field--required">' +
          '<span class="tp-form__label">Cantidad</span>' +
          '<input type="number" step="0.01" name="quantity" value="' + esc(item.quantity != null ? item.quantity : "") + '" data-tp-field></label>' +
          '<label class="tp-form__field tp-form__field--required">' +
          '<span class="tp-form__label">Costo unitario</span>' +
          '<input type="number" step="0.01" name="unit_cost" value="' + esc(item.unit_cost != null ? item.unit_cost : "") + '" data-tp-field></label>' +
          '<label class="tp-form__field">' +
          '<span class="tp-form__label">Proveedor</span>' +
          '<input type="text" name="supplier" value="' + esc(item.supplier || "") + '" data-tp-field></label>' +
          '<label class="tp-form__field">' +
          '<span class="tp-form__label">Comprador</span>' +
          '<input type="text" name="buyer" value="' + esc(item.buyer || "") + '" data-tp-field></label>' +
          '<label class="tp-form__field tp-col-2">' +
          '<span class="tp-form__label">Notas adicionales</span>' +
          '<textarea name="additional" rows="3" data-tp-field>' + esc(item.additional || "") + "</textarea></label>" +
          "</div></form>",
        buttons: [
          { label: "Cancelar", variant: "ghost", close: true },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog) {
              var form = dialog.querySelector("form.tp-expense-form");
              var payload = {};
              ["name", "type_id", "quantity", "unit_cost", "supplier", "buyer", "additional"].forEach(function (k) {
                var el = form.querySelector('[name="' + k + '"]');
                if (el) payload[k] = el.value;
              });
              // Fecha: [name=date] es el hidden ISO del tp-datepicker + hora aparte
              var dEl = form.querySelector('[name="date"]');
              var tEl = form.querySelector("[data-exp-time]");
              payload.date = dEl && dEl.value ? dEl.value + " " + ((tEl && tEl.value) || "00:00") : "";
              var url = isEdit ? cfg.update.replace("{id}", item.id) : cfg.store;
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
                    tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
                    return;
                  }
                  tpModal.toast("Gasto guardado.", "success");
                  tpModal.close(dialog);
                  reload();
                });
              return false;
            },
          },
        ],
      });
    }

    root.addEventListener("click", function (e) {
      var add = e.target.closest("[data-expense-add]");
      if (add) {
        openForm(null);
        return;
      }
      var edit = e.target.closest("[data-expense-edit]");
      if (edit) {
        var it = cache[edit.getAttribute("data-expense-edit")];
        if (it) openForm(it);
        else reload();
        return;
      }
      var del = e.target.closest("[data-expense-del]");
      if (del) {
        var xid = del.getAttribute("data-expense-del");
        tpModal
          .confirm({ title: "Eliminar gasto", message: "¿Eliminar este gasto?", danger: true, confirmLabel: "Eliminar" })
          .then(function (okGo) {
            if (!okGo) return;
            tpModal.fetchJSON(cfg.destroy.replace("{id}", xid), { method: "DELETE" }).then(function (res) {
              if (res.ok && res.data && res.data.ok) {
                tpModal.toast("Gasto eliminado.", "success");
                reload();
              } else {
                tpModal.toast("No se pudo eliminar.", "error");
              }
            });
          });
      }
    });

    // La lista server-rendered no trae los campos completos → precarga el caché.
    reload();
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

  /**
   * Modal de importacion de calibraciones (CSV). Port del legacy
   * Frontend/Sensors/partials/calibrations_import.blade.php: fichero +
   * casilla "añadir a los existentes". Devuelve los pares al callback;
   * quien decide que hacer con ellos es el panel de calibracion.
   */
  function openCalibrationsImport(cfg, onDone) {
    var dlg = tpModal.open({
      title: "Importar calibraciones",
      size: "sm",
      body:
        '<p class="tp-text-sm tp-text-muted" style="margin:0 0 12px;">' +
        "Fichero CSV con las columnas <code>x</code> e <code>y</code> separadas por <code>;</code>." +
        (cfg.calExample
          ? ' <a href="' + cfg.calExample + '" target="_blank" rel="noopener">Ver ejemplo</a>.'
          : "") +
        "</p>" +
        '<label class="tp-form__field" style="margin-bottom:12px;">' +
        '<span class="tp-form__label">Fichero</span>' +
        '<input type="file" class="tp-input" accept=".csv,text/csv" data-cal-file>' +
        "</label>" +
        '<label class="tp-choice">' +
        '<input type="checkbox" class="tp-choice-input" data-cal-append>' +
        "<span>Añadir a los valores actuales (si no, los reemplaza)</span>" +
        "</label>",
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Importar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            var fileEl = d.querySelector("[data-cal-file]");
            var append = d.querySelector("[data-cal-append]").checked;
            if (!fileEl.files || !fileEl.files[0]) {
              tpModal.toast("Elige un fichero CSV.", "warning");
              return false;
            }

            var fd = new FormData();
            fd.append("file", fileEl.files[0]);
            fd.append("append", append ? 1 : 0);

            tpModal.setBusy(d, true);
            tpModal
              .fetchJSON(cfg.calImport, { method: "POST", body: fd })
              .then(function (res) {
                tpModal.setBusy(d, false);
                if (res.status === 422) {
                  var errs = (res.data && res.data.errors) || {};
                  var first = Object.keys(errs)[0];
                  tpModal.toast(
                    first ? [].concat(errs[first])[0] : "El fichero no es válido.",
                    "error",
                  );
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast(
                    (res.data && res.data.message) || "No se pudo importar el fichero.",
                    "error",
                  );
                  return;
                }
                tpModal.close(d);
                onDone(res.data.items || [], append);
              })
              .catch(function () {
                tpModal.setBusy(d, false);
                tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
    });

    return dlg;
  }

  /**
   * Copiar los sensores de este vehiculo a otros. Se eligen que sensores y a
   * que vehiculos; el duplicado se detecta por tipo+nombre y por defecto se
   * respeta lo que ya existe en el destino.
   */
  function openSensorsCopy(cfg, sensors, onDone) {
    if (!sensors || !sensors.length) {
      tpModal.toast("Este vehículo no tiene sensores que copiar.", "warning");
      return;
    }

    var sensorRows = sensors
      .map(function (s) {
        return (
          '<label class="tp-copy__row"><input type="checkbox" data-cp-sensor="' + s.id + '" checked>' +
          "<span>" + esc(s.name || "#" + s.id) + '</span><small class="tp-text-muted">' +
          esc(s.type_title || s.type || "") + "</small></label>"
        );
      })
      .join("");

    var dlg = tpModal.open({
      title: "Copiar sensores a otros vehículos",
      size: "lg",
      body:
        '<div class="tp-copy">' +
        '<div class="tp-copy__col">' +
        '<h4 class="tp-copy__title">Sensores a copiar</h4>' +
        '<div class="tp-copy__list">' + sensorRows + "</div>" +
        "</div>" +
        '<div class="tp-copy__col">' +
        '<h4 class="tp-copy__title">Vehículos destino</h4>' +
        '<input type="search" class="tp-input tp-input--sm" data-cp-search placeholder="Buscar vehículo…" autocomplete="off">' +
        '<div class="tp-copy__list" data-cp-devices>' +
        '<p class="tp-text-sm tp-text-muted" style="padding:8px;">Cargando…</p>' +
        "</div>" +
        '<label class="tp-choice" style="margin-top:8px;">' +
        '<input type="checkbox" class="tp-choice-input" data-cp-overwrite>' +
        "<span>Reemplazar los sensores que ya existan en el destino</span></label>" +
        '<p class="tp-text-xs tp-text-muted" style="margin:6px 0 0;">' +
        "Sin marcar, los sensores que el destino ya tenga (mismo tipo y nombre) se dejan intactos.</p>" +
        "</div>" +
        "</div>",
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Copiar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            var sIds = [];
            d.querySelectorAll("[data-cp-sensor]:checked").forEach(function (i) {
              sIds.push(parseInt(i.getAttribute("data-cp-sensor"), 10));
            });
            var dIds = [];
            d.querySelectorAll("[data-cp-device]:checked").forEach(function (i) {
              dIds.push(parseInt(i.getAttribute("data-cp-device"), 10));
            });

            if (!sIds.length) {
              tpModal.toast("Elige al menos un sensor.", "warning");
              return false;
            }
            if (!dIds.length) {
              tpModal.toast("Elige al menos un vehículo destino.", "warning");
              return false;
            }

            var overwrite = d.querySelector("[data-cp-overwrite]").checked;

            tpModal.setBusy(d, true);
            tpModal
              .fetchJSON(cfg.copy, {
                method: "POST",
                json: { sensors: sIds, devices: dIds, overwrite: overwrite ? 1 : 0 },
              })
              .then(function (res) {
                tpModal.setBusy(d, false);
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast((res.data && res.data.message) || "No se pudo copiar.", "error");
                  return;
                }

                var r = res.data;
                var partes = [r.copied + " copiados"];
                if (r.replaced) partes.push(r.replaced + " reemplazados");
                if (r.skipped) partes.push(r.skipped + " ya existían");
                if (r.denied) partes.push(r.denied + " sin permiso");

                tpModal.close(d);
                tpModal.toast(partes.join(" · "), r.copied || r.replaced ? "success" : "warning");
                if (typeof onDone === "function") onDone();
              })
              .catch(function () {
                tpModal.setBusy(d, false);
                tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
      onMount: function (d) {
        var wrap = d.querySelector("[data-cp-devices]");
        var search = d.querySelector("[data-cp-search]");

        // La lista de destinos se pide al abrir, no con el formulario: son
        // cientos de vehiculos y solo hacen falta aqui.
        tpModal
          .fetchJSON(cfg.copyTargets, { method: "GET" })
          .then(function (res) {
            var items = (res.data && res.data.items) || [];
            if (!items.length) {
              wrap.innerHTML =
                '<p class="tp-text-sm tp-text-muted" style="padding:8px;">No hay otros vehículos que puedas editar.</p>';
              return;
            }
            wrap.innerHTML = items
              .map(function (it) {
                return (
                  '<label class="tp-copy__row" data-cp-item data-name="' +
                  esc((it.name + " " + (it.plate || "")).toLowerCase()) +
                  '"><input type="checkbox" data-cp-device="' + it.id + '">' +
                  "<span>" + esc(it.name) + "</span>" +
                  (it.plate ? '<small class="tp-text-muted">' + esc(it.plate) + "</small>" : "") +
                  "</label>"
                );
              })
              .join("");
          })
          .catch(function () {
            wrap.innerHTML =
              '<p class="tp-text-sm tp-text-error" style="padding:8px;">No se pudo cargar la lista.</p>';
          });

        if (search) {
          search.addEventListener("input", function () {
            var q = search.value.trim().toLowerCase();
            wrap.querySelectorAll("[data-cp-item]").forEach(function (row) {
              row.style.display = !q || row.getAttribute("data-name").indexOf(q) !== -1 ? "" : "none";
            });
          });
        }
      },
    });

    return dlg;
  }

  function openSensorForm(cfg, sensorId, onSaved) {
    var isEdit = !!sensorId;

    var dlg = tpModal.open({
      title: isEdit ? "Editar sensor" : "Nuevo sensor",
      size: "lg",
      static: true,
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
          // tp-select NO re-sincroniza su botón al togglear el disabled del
          // <select> nativo. Al habilitar un bloque, re-enhancemos para que el
          // dropdown vuelva a ser interactivo (bug: shown_value_by / on_type /
          // odómetro quedaban bloqueados al cambiar de plantilla de sensor).
          if (on && i.tagName === "SELECT" && i._tpdd && window.tpSelect && tpSelect.refresh) {
            try { tpSelect.refresh(i); } catch (e) {}
          }
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
        // El disabler legacy (hide-disable) hace .hide() = display:none, o sea
        // COLAPSA el hueco. Con visibility:hidden los dos campos de setflag
        // seguian ocupando 2/3 de la fila y el "Valor tag" quedaba lejisimos
        // del select de comparacion.
        if (el.getAttribute("data-enable-hide")) el.style.display = on ? "" : "none";
      });
    }

    function syncPanels() {
      var anyOn = false;

      ["calibration", "mapping"].forEach(function (k) {
        var panel = form.querySelector('[data-panel="' + k + '"]');
        if (!panel) return;
        var chk = form.querySelector('input[type="checkbox"][name="' + k + '"]');
        var on = !!activeInputs[k] && chk && chk.checked && !chk.disabled;
        panel.style.display = on ? "" : "none";
        if (on) anyOn = true;
        Array.prototype.forEach.call(panel.querySelectorAll("input, select, textarea"), function (i) {
          i.disabled = !on;
        });
      });

      // Paridad con calibationShow()/calibationHide() del legacy: los paneles
      // no son "mas campos" del formulario, son una seccion propia. Al activar
      // Calibracion o Cartografia el form se parte en dos y el modal se
      // ensancha; al apagarlos vuelve a una sola columna.
      var side = form.querySelector("[data-sensor-side]");
      if (side) side.style.display = anyOn ? "" : "none";
      form.classList.toggle("tp-sensor-form--split", anyOn);
      if (dlg && dlg.classList) {
        dlg.classList.toggle("tp-modal--xl", anyOn);
        dlg.classList.toggle("tp-modal--lg", !anyOn);
      }
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

    /** ¿Se puede insertar este par? (x numerica y no repetida, y numerica) */
    function calRowValid(x, y) {
      if (x === "" || isNaN(+x)) return false;
      if (y === "" || isNaN(+y)) return false;
      return !calRows.querySelector('input[name="calibrations[' + x + ']"]');
    }

    function addCalRow(x, y) {
      calRows.insertAdjacentHTML(
        "beforeend",
        "<tr>" +
          "<td>" + esc(x) + '<input type="hidden" name="calibrations[' + esc(x) + ']" value="' + esc(y) + '"></td>' +
          "<td>" + esc(y) + "</td>" +
          '<td style="text-align:center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-cal-del>×</button></td>' +
          "</tr>",
      );
    }

    form.addEventListener("click", function (e) {
      var addCal = e.target.closest("[data-cal-add]");
      if (addCal) {
        var xEl = form.querySelector("[data-cal-x]");
        var yEl = form.querySelector("[data-cal-y]");
        var x = (xEl.value || "").trim();
        var y = (yEl.value || "").trim();
        xEl.style.borderColor = "";
        yEl.style.borderColor = "";
        if (x === "" || isNaN(+x) || calRows.querySelector('input[name="calibrations[' + x + ']"]')) {
          xEl.style.borderColor = "#ef4444";
          return;
        }
        if (y === "" || isNaN(+y)) {
          yEl.style.borderColor = "#ef4444";
          return;
        }
        addCalRow(x, y);
        xEl.value = "";
        yEl.value = "";
        return;
      }

      // Importar calibraciones desde CSV (paridad con el modal
      // calibrations_import del legacy). No toca BD: solo rellena la tabla.
      if (e.target.closest("[data-cal-import]")) {
        openCalibrationsImport(cfg, function (items, append) {
          if (!append) calRows.innerHTML = "";
          var added = 0;
          items.forEach(function (it) {
            var x = String(it.x);
            var y = String(it.y);
            if (!calRowValid(x, y)) return;
            addCalRow(x, y);
            added++;
          });
          tpModal.toast(
            added + " de " + items.length + " valores importados.",
            added ? "success" : "warning",
          );
        });
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
      // Icono de una fila ya existente
      var rowIcon = e.target.closest("[data-map-row-icon]");
      if (rowIcon) {
        var hid = rowIcon.querySelector("[data-map-row-icon-id]");
        openIconPicker(hid ? hid.value : "").then(function (pick) {
          if (hid) hid.value = pick.id;
          paintIconButton(rowIcon, pick.url);
        });
        return;
      }
      // Icono de la fila que se va a crear
      var newIcon = e.target.closest("[data-map-new-icon]");
      if (newIcon) {
        var nhid = newIcon.querySelector("[data-map-new-icon-id]");
        openIconPicker(nhid ? nhid.value : "").then(function (pick) {
          if (nhid) {
            nhid.value = pick.id;
            nhid.setAttribute("data-url", pick.url || "");
          }
          paintIconButton(newIcon, pick.url);
        });
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
      // Paridad legacy: en modo Valor el campo se rotula "Valor", no "Desde".
      var ph = mapV.getAttribute(range ? "data-ph-from" : "data-ph-value");
      if (ph) mapV.placeholder = ph;
      var mt = mapMt.value;
      mapMv.style.display = mt === "3" ? "none" : "";
      if (mt === "2" && !mapMv.value) mapMv.value = "[value]";
    }
    if (mapVt) mapVt.addEventListener("change", syncMapControls);
    if (mapMt) mapMt.addEventListener("change", syncMapControls);
    syncMapControls();

    // ── Picker de icono por fila de mapeo (paridad legacy: icons-modal) ──
    // El icono se guarda como mappings[i][icon] = id de sensor_icons.
    var ICONS_CACHE = null;

    function loadSensorIcons() {
      if (ICONS_CACHE) return Promise.resolve(ICONS_CACHE);
      return tpModal.fetchJSON(cfg.sensorIcons).then(function (res) {
        ICONS_CACHE = (res.data && res.data.items) || [];
        return ICONS_CACHE;
      });
    }

    // Pinta la miniatura (o el "+" vacío) dentro de un botón del picker.
    function paintIconButton(btn, url) {
      var img = btn.querySelector("[data-map-row-icon-img], [data-map-new-icon-img]");
      if (!img) return;
      var attr = img.hasAttribute("data-map-new-icon-img") ? "data-map-new-icon-img" : "data-map-row-icon-img";
      img.outerHTML = url
        ? '<img src="' + esc(url) + '" alt="" ' + attr + ">"
        : '<span ' + attr + ' class="tp-map-icon__empty">+</span>';
    }

    function openIconPicker(currentId) {
      return new Promise(function (resolve) {
        loadSensorIcons().then(function (items) {
          var grid = items.length
            ? '<div class="tp-icon-grid">' +
              items
                .map(function (i) {
                  return (
                    '<button type="button" class="tp-icon-grid__cell' +
                    (String(i.id) === String(currentId) ? " is-active" : "") +
                    '" data-icon-id="' + i.id + '" data-icon-url="' + esc(i.url) + '">' +
                    '<img src="' + esc(i.url) + '" alt="" loading="lazy"></button>'
                  );
                })
                .join("") +
              "</div>"
            : '<p class="tp-text-muted tp-text-sm" style="margin:0;">No hay iconos de sensor cargados. ' +
              "El administrador puede subirlos en el panel de administración.</p>";

          tpModal.open({
            title: "Icono del valor mapeado",
            size: "sm",
            body: grid,
            buttons: [
              { label: "Quitar icono", variant: "ghost", close: true, onClick: function () { resolve({ id: "", url: "" }); } },
              { label: "Cancelar", variant: "ghost", close: true },
            ],
            onMount: function (d) {
              d.addEventListener("click", function (e) {
                var cell = e.target.closest("[data-icon-id]");
                if (!cell) return;
                resolve({ id: cell.getAttribute("data-icon-id"), url: cell.getAttribute("data-icon-url") });
                tpModal.close(d);
              });
            },
          });
        });
      });
    }

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

      // Icono elegido para esta fila (si lo hay)
      var newIconEl = form.querySelector("[data-map-new-icon]");
      var newIconHid = newIconEl ? newIconEl.querySelector("[data-map-new-icon-id]") : null;
      var iconId = newIconHid ? newIconHid.value : "";
      var iconUrl = newIconHid ? newIconHid.getAttribute("data-url") || "" : "";
      var iconCell =
        '<button type="button" class="tp-map-icon" data-map-row-icon>' +
        '<input type="hidden" name="mappings[' + i + '][icon]" value="' + esc(iconId) + '" data-map-row-icon-id>' +
        (iconUrl
          ? '<img src="' + esc(iconUrl) + '" alt="" data-map-row-icon-img>'
          : '<span data-map-row-icon-img class="tp-map-icon__empty">+</span>') +
        "</button>";

      mapRows.insertAdjacentHTML(
        "beforeend",
        "<tr>" +
          "<td>" + esc(vt === "2" ? v + " .. " + t : v) + hid + "</td>" +
          "<td>" + esc(mt === "3" ? "[value]" : mv) + hidMt + "</td>" +
          '<td style="text-align:center;">' + iconCell + "</td>" +
          '<td style="text-align:center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-map-del>×</button></td>' +
          "</tr>",
      );
      mapV.value = "";
      mapT.value = "";
      mapMv.value = "";
      if (newIconHid) {
        newIconHid.value = "";
        newIconHid.setAttribute("data-url", "");
        paintIconButton(newIconEl, "");
      }
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
      // Soporta name="custom_fields[slug][]" (multiselect) → data.custom_fields.slug = [v…]
      var nestedArr = /^([^\[]+)\[([^\]]+)\]\[\]$/.exec(key);
      if (nestedArr) {
        var nk = nestedArr[1];
        var nsub = nestedArr[2];
        if (!data[nk] || typeof data[nk] !== "object") data[nk] = {};
        if (!Array.isArray(data[nk][nsub])) data[nk][nsub] = [];
        data[nk][nsub].push(value);
        return;
      }
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
  function fetchFormHtml(id, kind) {
    var url = id
      ? CFG.formUrl.replace("{id}", encodeURIComponent(id))
      : CFG.formNewUrl;
    // El alta de beacon pide el form con el set de tabs reducido.
    if (!id && kind === "beacon") url += (url.indexOf("?") === -1 ? "?" : "&") + "kind=beacon";
    return tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        throw new Error((res.data && res.data.message) || "form_load_failed");
      }
      return res.data.html;
    });
  }

  // --------------------------------------------------------------------------
  // Borrado del vehículo (paridad Devices/destroy.blade + DevicesController@destroy
  // legacy): confirma y, si addon.object_delete_pass está activo (+admin), exige la
  // contraseña antes de hacer DELETE. El backend v2 revalida la contraseña.
  // --------------------------------------------------------------------------
  function confirmDelete(editDialog, id, onDeleted) {
    var needPass = !!CFG.objectDeletePass;
    var body =
      '<p style="margin:0;">¿Eliminar este vehículo? Esta acción no se puede deshacer.</p>' +
      (needPass
        ? '<div class="tp-field" style="margin-top:12px;">' +
          '<label class="tp-field__label">Confirma con tu contraseña de acceso *</label>' +
          '<input type="password" class="tp-input" data-del-pass autocomplete="off" style="width:100%;">' +
          '<span class="tp-field__hint" style="display:block;margin-top:4px;font-size:12px;opacity:.7;">La contraseña con la que inicias sesión.</span></div>'
        : "") +
      // Error inline (el toast queda detrás del modal apilado, no se ve).
      '<div data-del-error role="alert" style="display:none;margin-top:10px;padding:8px 10px;border-radius:8px;font-size:13px;background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.35);"></div>';

    function showErr(dlg2, msg) {
      var e = dlg2.querySelector("[data-del-error]");
      if (e) {
        e.textContent = msg;
        e.style.display = "block";
      }
    }
    function clearErr(dlg2) {
      var e = dlg2.querySelector("[data-del-error]");
      if (e) e.style.display = "none";
    }
    function focusPass(dlg2) {
      var i = dlg2.querySelector("[data-del-pass]");
      if (i) {
        i.focus();
        i.select();
      }
    }

    tpModal.open({
      title: "Eliminar vehículo",
      size: "sm",
      body: body,
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Eliminar",
          variant: "danger",
          close: false,
          onClick: function (dlg2) {
            clearErr(dlg2);
            var pass = needPass
              ? ((dlg2.querySelector("[data-del-pass]") || {}).value || "").trim()
              : "";
            if (needPass && !pass) {
              showErr(dlg2, "Ingresa tu contraseña de acceso.");
              focusPass(dlg2);
              return false;
            }
            tpModal.setBusy(dlg2, true);
            tpModal
              .fetchJSON(CFG.destroyUrl.replace("{id}", encodeURIComponent(id)), {
                method: "DELETE",
                json: needPass ? { password: pass } : {},
              })
              .then(function (res) {
                tpModal.setBusy(dlg2, false);
                if (res.status === 422) {
                  showErr(dlg2, (res.data && res.data.message) || "Contraseña incorrecta.");
                  focusPass(dlg2);
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  showErr(dlg2, (res.data && res.data.message) || "No se pudo eliminar el vehículo.");
                  return;
                }
                tpModal.toast("Vehículo eliminado.", "success");
                tpModal.close(dlg2);
                if (editDialog) tpModal.close(editDialog);
                if (typeof onDeleted === "function") {
                  try {
                    onDeleted();
                  } catch (_) {}
                }
              })
              .catch(function () {
                tpModal.setBusy(dlg2, false);
                showErr(dlg2, "Error de red. Inténtalo de nuevo.");
              });
            return false;
          },
        },
      ],
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

    // Botón Eliminar (izquierda) solo al editar y con permiso devices.remove.
    var deleteBtn = {
      label: "Eliminar",
      variant: "danger",
      close: false,
      onClick: function (dialog) {
        confirmDelete(dialog, opts.id, opts.onSaved);
        return false;
      },
    };

    var dlg = tpModal.open({
      title: (opts.kind === "beacon" ? (isEdit ? "Editar beacon" : "Nuevo beacon") : (isEdit ? "Editar vehículo" : "Nuevo vehículo")),
      size: "lg",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: (isEdit && CFG.canRemove ? [deleteBtn] : []).concat([
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            var form = dialog.querySelector("form.tp-device-form");
            if (!form) return;
            var payload = readForm(form);
            var isBeacon = opts.kind === "beacon" || !!form.getAttribute("data-beacon");
            var url = isEdit
              ? (isBeacon ? CFG.beaconUpdateUrl : CFG.updateUrl).replace("{id}", encodeURIComponent(opts.id))
              : (isBeacon ? CFG.beaconStoreUrl : CFG.storeUrl);
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
      ]),
    });

    fetchFormHtml(opts.id, opts.kind)
      .then(function (html) {
        var bodyEl = dlg.querySelector(".tp-modal__body");
        if (!bodyEl) return;
        bodyEl.innerHTML = html;

        // Cada pestaña se inicializa por separado: si una falla, el resto del
        // formulario sigue usable. Antes un error en cualquier wire* caía en el
        // catch del fetch y el modal entero mostraba "No se pudo cargar el
        // formulario", ocultando la causa real.
        [
          ["tabs", wireTabs],
          ["iconGallery", wireIconGallery],
          ["iconsTab", wireIconsTab],
          ["expiration", wireExpiration],
          ["enablers", wireEnablers],
          ["ignitionEngine", wireIgnitionEngine],
          ["fuelLabels", wireFuelLabels],
          ["routeColors", wireRouteColors],
          ["usersTab", wireUsersTab],
          ["sensorsTab", wireSensorsTab],
          ["servicesTab", wireServicesTab],
          ["camerasTab", wireCamerasTab],
          ["maintenances", wireMaintenances],
          ["configurator", wireConfigurator],
          ["expensesTab", wireExpensesTab],
        ].forEach(function (pair) {
          try {
            pair[1](bodyEl);
          } catch (e) {
            if (window.console && console.error) {
              console.error("[device-modal] fallo al inicializar '" + pair[0] + "':", e);
            }
          }
        });
      })
      .catch(function (e) {
        if (window.console && console.error) console.error("[device-modal] carga:", e);
        var bodyEl = dlg.querySelector(".tp-modal__body");
        if (bodyEl) {
          bodyEl.innerHTML =
            '<div class="tp-text-center tp-text-error" style="padding:24px;">' +
            "No se pudo cargar el formulario." +
            '<div class="tp-text-xs tp-text-muted" style="margin-top:6px;">' +
            ((e && e.message) ? String(e.message) : "") + "</div></div>";
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
    // Alta de beacon (plugin beacons): mismo modal, kind y endpoints distintos.
    openCreateBeacon: function (onSaved) {
      return open({ onSaved: onSaved, kind: "beacon" });
    },
    open: open,
    invalidateMeta: function () {
      /* compat: ya no hay caché client-side */
    },
  };
})();
