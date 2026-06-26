/* =====================================================
   TalentusPro · Fleet · Phase 4
   - Server-side paginated table
   - Client-side sort/select on current page
   - Debounced search + status segmented filter
   - CSV export of current page
   ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-fleet");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");

  var state = {
    page: 1,
    limit: 100,
    total: 0,
    pages: 1,
    q: "",
    status: "",
    sort: "name",
    dir: "asc",
    items: [],
    selected: new Set(),
    loading: false,
  };

  /* DOM */
  var $tbody = document.getElementById("tp-fleet-tbody");
  var $count = document.getElementById("tp-fleet-count");
  var $search = document.getElementById("tp-fleet-search");
  var $page = document.getElementById("tp-fleet-page");
  var $prev = document.getElementById("tp-fleet-prev");
  var $next = document.getElementById("tp-fleet-next");
  var $all = document.getElementById("tp-fleet-check-all");
  var $sel = document.getElementById("tp-fleet-selection");
  var $selN = document.getElementById("tp-fleet-selection-count");
  var $selClr = document.getElementById("tp-fleet-selection-clear");
  var $exp = document.getElementById("tp-fleet-export");
  var $rfr = document.getElementById("tp-fleet-refresh");
  var $table = document.getElementById("tp-fleet-table");

  var STATUS_LABELS = {
    moving: "Moviendo",
    idle: "Inactivo",
    stopped: "Detenido",
    offline: "Offline",
  };

  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  function fmtDate(iso) {
    if (!iso) return "—";
    try {
      var d = new Date(iso.replace(" ", "T"));
      if (isNaN(d.getTime())) return "—";
      return d.toLocaleString();
    } catch (e) {
      return "—";
    }
  }

  function pillFor(group) {
    var label = STATUS_LABELS[group] || group;
    return '<span class="tp-pill tp-pill--' + group + '">' + label + "</span>";
  }

  function sortItems(items) {
    var key = state.sort,
      dir = state.dir === "desc" ? -1 : 1;
    return items.slice().sort(function (a, b) {
      var av = a[key],
        bv = b[key];
      if (key === "status") {
        av = a.status_group;
        bv = b.status_group;
      }
      if (av == null) av = "";
      if (bv == null) bv = "";
      if (typeof av === "number" && typeof bv === "number")
        return (av - bv) * dir;
      return String(av).localeCompare(String(bv)) * dir;
    });
  }

  function render() {
    var items = sortItems(state.items);
    if (!items.length) {
      $tbody.innerHTML =
        '<tr><td colspan="12" class="tp-text-muted tp-text-center" style="padding:32px;">Sin resultados.</td></tr>';
    } else {
      var html = "";
      for (var i = 0; i < items.length; i++) {
        var d = items[i];
        var isSel = state.selected.has(d.id);
        html +=
          '<tr class="' +
          (isSel ? "is-selected" : "") +
          '" data-id="' +
          d.id +
          '">' +
          '<td class="tp-fleet__th-check"><input type="checkbox" data-select="' +
          d.id +
          '"' +
          (isSel ? " checked" : "") +
          "></td>" +
          '<td><div class="tp-fleet__row-name">' +
          '<span class="tp-fleet__row-dot" style="background:' +
          escapeHtml(d.icon_color || "#94a3b8") +
          '"></span>' +
          '<span class="tp-fleet__row-name-text" title="' +
          escapeHtml(d.name) +
          '">' +
          escapeHtml(d.name) +
          "</span>" +
          "</div></td>" +
          "<td>" +
          pillFor(d.status_group) +
          "</td>" +
          "<td>" +
          escapeHtml(d.plate || "—") +
          "</td>" +
          '<td><code class="tp-text-sm">' +
          escapeHtml(d.imei || "—") +
          "</code></td>" +
          "<td>" +
          escapeHtml(d.model || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(d.sim || "—") +
          "</td>" +
          '<td class="tp-text-right">' +
          (d.speed != null ? Number(d.speed).toFixed(1) : "—") +
          "</td>" +
          '<td class="tp-text-right">' +
          (d.engine_hours != null ? Number(d.engine_hours).toFixed(1) : "—") +
          "</td>" +
          "<td>" +
          escapeHtml(fmtDate(d.moved_at)) +
          "</td>" +
          "<td>" +
          escapeHtml(d.expiration_date || "—") +
          "</td>" +
          '<td><button class="tp-fleet__row-action" data-view="' +
          d.id +
          '" title="Ver en mapa" aria-label="Ver en mapa">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>' +
          "</button></td>" +
          "</tr>";
      }
      $tbody.innerHTML = html;
    }

    // Header sort indicators
    var ths = $table.querySelectorAll("thead th[data-sort]");
    for (var k = 0; k < ths.length; k++) {
      ths[k].classList.toggle(
        "is-sorted",
        ths[k].getAttribute("data-sort") === state.sort,
      );
      ths[k].classList.toggle(
        "is-desc",
        ths[k].getAttribute("data-sort") === state.sort && state.dir === "desc",
      );
    }

    // Footer
    $count.textContent =
      state.total > 0
        ? state.total.toLocaleString() + " dispositivos"
        : "Sin dispositivos";
    $page.textContent =
      "Página " + state.page + " / " + Math.max(1, state.pages);
    $prev.disabled = state.page <= 1 || state.loading;
    $next.disabled = state.page >= state.pages || state.loading;

    // Selection chip
    if (state.selected.size > 0) {
      $sel.hidden = false;
      $selN.textContent = state.selected.size;
    } else {
      $sel.hidden = true;
    }

    // Master checkbox
    if ($all) {
      var visibleIds = state.items.map(function (d) {
        return d.id;
      });
      var allChecked =
        visibleIds.length > 0 &&
        visibleIds.every(function (id) {
          return state.selected.has(id);
        });
      $all.checked = allChecked;
      $all.indeterminate =
        !allChecked &&
        visibleIds.some(function (id) {
          return state.selected.has(id);
        });
    }
  }

  function load() {
    state.loading = true;
    $page.textContent = "Cargando…";
    var url =
      DATA_URL +
      "?" +
      new URLSearchParams({
        page: state.page,
        limit: state.limit,
        q: state.q,
        status: state.status,
      }).toString();

    return fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (p) {
        if (!p || !p.ok) return;
        state.items = p.items || [];
        state.total = p.total || 0;
        state.pages = p.pages || 1;
        state.loading = false;
        render();
      })
      .catch(function (err) {
        state.loading = false;
        console.warn("[fleet] load failed", err);
      });
  }

  /* Events */
  var searchTimer;
  $search.addEventListener("input", function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      state.q = $search.value.trim();
      state.page = 1;
      load();
    }, 250);
  });

  var segBtns = root.querySelectorAll(".tp-fleet__filters .tp-seg__btn");
  segBtns.forEach(function (btn) {
    btn.addEventListener("click", function () {
      segBtns.forEach(function (b) {
        b.classList.remove("is-active");
      });
      btn.classList.add("is-active");
      state.status = btn.getAttribute("data-status") || "";
      state.page = 1;
      load();
    });
  });

  $prev.addEventListener("click", function () {
    if (state.page > 1) {
      state.page--;
      load();
    }
  });
  $next.addEventListener("click", function () {
    if (state.page < state.pages) {
      state.page++;
      load();
    }
  });
  $rfr.addEventListener("click", function () {
    load();
  });

  // Sort
  $table.querySelectorAll("thead th[data-sort]").forEach(function (th) {
    th.addEventListener("click", function () {
      var k = th.getAttribute("data-sort");
      if (state.sort === k) state.dir = state.dir === "asc" ? "desc" : "asc";
      else {
        state.sort = k;
        state.dir = "asc";
      }
      render();
    });
  });

  // Selection (delegated)
  $tbody.addEventListener("change", function (e) {
    var cb = e.target.closest("input[type=checkbox][data-select]");
    if (!cb) return;
    var id = parseInt(cb.getAttribute("data-select"), 10);
    if (cb.checked) state.selected.add(id);
    else state.selected.delete(id);
    render();
  });
  $all.addEventListener("change", function () {
    if ($all.checked)
      state.items.forEach(function (d) {
        state.selected.add(d.id);
      });
    else
      state.items.forEach(function (d) {
        state.selected.delete(d.id);
      });
    render();
  });
  $selClr.addEventListener("click", function () {
    state.selected.clear();
    render();
  });

  // Row "view on map"
  $tbody.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-view]");
    if (!btn) return;
    var id = btn.getAttribute("data-view");
    window.location.href = "/ui/v2/live?device=" + encodeURIComponent(id);
  });

  // CSV export (current page)
  $exp.addEventListener("click", function () {
    var headers = [
      "ID",
      "Nombre",
      "Estado",
      "Placa",
      "IMEI",
      "Modelo",
      "SIM",
      "km/h",
      "Motor (h)",
      "Último movimiento",
      "Expira",
    ];
    var lines = [headers.join(",")];
    state.items.forEach(function (d) {
      var row = [
        d.id,
        d.name,
        STATUS_LABELS[d.status_group] || d.status_group,
        d.plate || "",
        d.imei || "",
        d.model || "",
        d.sim || "",
        d.speed != null ? d.speed.toFixed(1) : "",
        d.engine_hours != null ? d.engine_hours.toFixed(1) : "",
        d.moved_at || "",
        d.expiration_date || "",
      ].map(function (v) {
        var s = String(v == null ? "" : v).replace(/"/g, '""');
        return /[",\n]/.test(s) ? '"' + s + '"' : s;
      });
      lines.push(row.join(","));
    });
    var blob = new Blob(["\uFEFF" + lines.join("\n")], {
      type: "text/csv;charset=utf-8;",
    });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "fleet-page-" + state.page + "-" + Date.now() + ".csv";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 100);
  });

  /* Boot */
  load();
})();
