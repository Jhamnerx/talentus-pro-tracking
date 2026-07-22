/* =====================================================
 * TalentusPro · maintenance.js · Página /ui/v2/maintenance
 * Tabla global de servicios (paridad con /maintenance legacy):
 * Device | Name | Distance(+left) | Engine hours(+left) | Days(+left)
 * con barra de progreso por tipo de expiración y kebab
 * Editar/Eliminar (modales compartidos de tp-services.js).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-maintenance");
  if (!root || !window.tpServices) return;

  var state = {
    items: [],
    q: "",
    page: 1,
    limit: 25,
    sortKey: "device_name",
    sortDir: 1,
  };

  var els = {
    rows: document.getElementById("tp-maint-rows"),
    search: document.getElementById("tp-maint-search"),
    limit: document.getElementById("tp-maint-limit"),
    range: document.getElementById("tp-maint-range"),
    prev: document.getElementById("tp-maint-prev"),
    next: document.getElementById("tp-maint-next"),
    table: document.getElementById("tp-maint-table"),
  };

  function esc(s) {
    return window.tpModal ? tpModal.escapeHtml(s) : String(s == null ? "" : s);
  }

  /* Valores derivados por columna (para orden): pct por tipo, left numérico. */
  function colVal(r, key) {
    switch (key) {
      case "device_name":
        return (r.device_name || "").toLowerCase();
      case "name":
        return (r.name || "").toLowerCase();
      case "odometer_pct":
        return r.expiration_by === "odometer" ? r.percentage : -1;
      case "odometer_left":
        return r.expiration_by === "odometer" ? leftNum(r) : -Infinity;
      case "engine_hours_pct":
        return r.expiration_by === "engine_hours" ? r.percentage : -1;
      case "engine_hours_left":
        return r.expiration_by === "engine_hours" ? leftNum(r) : -Infinity;
      case "days_pct":
        return r.expiration_by === "days" ? r.percentage : -1;
      case "days_left":
        return r.expiration_by === "days" ? leftNum(r) : -Infinity;
      default:
        return 0;
    }
  }

  function leftNum(r) {
    var m = String(r.left_text || "").match(/-?\d+(\.\d+)?/);
    return m ? parseFloat(m[0]) : -Infinity;
  }

  function filtered() {
    var rows = state.items;
    if (state.q) {
      var q = state.q.toLowerCase();
      rows = rows.filter(function (r) {
        return (
          (r.device_name || "").toLowerCase().indexOf(q) !== -1 ||
          (r.name || "").toLowerCase().indexOf(q) !== -1
        );
      });
    }
    rows = rows.slice().sort(function (a, b) {
      var va = colVal(a, state.sortKey);
      var vb = colVal(b, state.sortKey);
      if (va < vb) return -1 * state.sortDir;
      if (va > vb) return 1 * state.sortDir;
      return 0;
    });
    return rows;
  }

  /* Celdas <pct-bar> + <left> para un tipo; "-" si el servicio no es de ese tipo. */
  function typeCells(r, type) {
    if (r.expiration_by !== type) return "<td>-</td><td>-</td>";
    var pct = r.percentage || 0;
    var cls = tpServices.barClass(pct);
    return (
      '<td><div class="tp-svc-prog">' +
      '<div class="tp-svc-bar"><div class="tp-svc-bar__fill ' + cls +
      '" style="width:' + Math.max(pct, 2) + '%"></div></div>' +
      '<span class="tp-svc-prog__val ' + cls + '">' + pct + "%</span></div></td>" +
      '<td class="tp-svc-left' + (r.expired ? " is-expired" : "") + '">' +
      esc(r.left_text) + "</td>"
    );
  }

  function render() {
    var rows = filtered();
    var total = rows.length;
    var pages = Math.max(1, Math.ceil(total / state.limit));
    if (state.page > pages) state.page = pages;
    var start = (state.page - 1) * state.limit;
    var slice = rows.slice(start, start + state.limit);

    var html = "";
    slice.forEach(function (r) {
      html +=
        "<tr><td>" + esc(r.device_name) + "</td><td>" + esc(r.name) + "</td>" +
        typeCells(r, "odometer") +
        typeCells(r, "engine_hours") +
        typeCells(r, "days") +
        '<td class="tp-svc-table__actions">' + tpServices.kebabHtml(r) + "</td></tr>";
    });
    if (!html)
      html =
        '<tr><td colspan="9" class="tp-svc-table__empty">' +
        esc(tpServices.t("no_services")) + "</td></tr>";
    els.rows.innerHTML = html;

    els.range.textContent = total
      ? start + 1 + "–" + Math.min(start + state.limit, total) + " / " + total
      : "0";
    els.prev.disabled = state.page <= 1;
    els.next.disabled = state.page >= pages;

    // Indicador de orden en cabeceras
    els.table.querySelectorAll("th[data-sort]").forEach(function (th) {
      var arrow = th.querySelector(".tp-maint__arrow");
      if (arrow) arrow.remove();
      if (th.getAttribute("data-sort") === state.sortKey) {
        var s = document.createElement("span");
        s.className = "tp-maint__arrow";
        s.textContent = state.sortDir > 0 ? "▲" : "▼";
        th.appendChild(s);
      }
    });
  }

  function load() {
    tpModal.fetchJSON(tpServices.url("data")).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        els.rows.innerHTML =
          '<tr><td colspan="9" class="tp-svc-table__empty">' +
          esc(tpServices.t("error")) + "</td></tr>";
        return;
      }
      state.items = res.data.items || [];
      render();
    });
  }

  /* ---------- Eventos ---------- */
  els.search.addEventListener("input", function () {
    state.q = this.value.trim();
    state.page = 1;
    render();
  });
  els.limit.addEventListener("change", function () {
    state.limit = parseInt(this.value, 10) || 25;
    state.page = 1;
    render();
  });
  els.prev.addEventListener("click", function () {
    state.page--;
    render();
  });
  els.next.addEventListener("click", function () {
    state.page++;
    render();
  });
  els.table.querySelectorAll("th[data-sort]").forEach(function (th) {
    th.addEventListener("click", function () {
      var key = th.getAttribute("data-sort");
      if (state.sortKey === key) state.sortDir *= -1;
      else {
        state.sortKey = key;
        state.sortDir = 1;
      }
      render();
    });
  });

  // Acciones del kebab (el abrir/cerrar lo maneja tp-services.js)
  root.addEventListener("click", function (e) {
    var act = e.target.closest("[data-svc-act]");
    if (!act) return;
    var id = parseInt(act.getAttribute("data-id"), 10);
    var deviceId = parseInt(act.getAttribute("data-device"), 10);
    if (act.getAttribute("data-svc-act") === "edit") {
      tpServices.openForm(deviceId, id, load);
    } else {
      tpServices.remove(id, act.getAttribute("data-name"), load);
    }
  });

  load();
})();
