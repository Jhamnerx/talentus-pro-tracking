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
    // El dashboard enlaza aquí con ?status=… (equivalente a las tablas Lookup
    // del legacy), así que el filtro puede venir dado en la URL.
    status: (function () {
      try {
        return new URLSearchParams(location.search).get("status") || "";
      } catch (e) {
        return "";
      }
    })(),
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

  /* ===== Columnas configurables (paridad ObjectsList) =====
   * El set de columnas se guarda en el mismo ajuste del usuario que la vista
   * de lista legacy, así que lo que se configure aquí se ve allá y viceversa.
   */
  var COLS_URL = "/ui/v2/api/fleet/columns";
  var columnsState = { available: [], selected: [], labels: {} };

  // Cómo se pinta cada columna: valor + alineación.
  var COL_RENDER = {
    name: function (d) {
      return (
        '<div class="tp-fleet__row-name">' +
        '<span class="tp-fleet__row-dot" style="background:' + escapeHtml(d.icon_color || "#94a3b8") + '"></span>' +
        '<span class="tp-fleet__row-name-text" title="' + escapeHtml(d.name) + '">' + escapeHtml(d.name) + "</span>" +
        "</div>"
      );
    },
    status: function (d) {
      return pillFor(d.status_group);
    },
    imei: function (d) {
      return '<code class="tp-text-sm">' + escapeHtml(d.imei || "—") + "</code>";
    },
    speed: function (d) {
      return d.speed != null ? Number(d.speed).toFixed(1) : "—";
    },
    time: function (d) {
      return escapeHtml(d.time || "—");
    },
    active: function (d) {
      return d.active ? "Sí" : "No";
    },
    plate_number: function (d) {
      return escapeHtml(d.plate || "—");
    },
    device_model: function (d) {
      return escapeHtml(d.model || "—");
    },
    sim_number: function (d) {
      return escapeHtml(d.sim || "—");
    },
    device_type_id: function (d) {
      return escapeHtml(d.device_type || "—");
    },
    expiration_date: function (d) {
      return escapeHtml(d.expiration_date || "—");
    },
  };
  var COL_RIGHT = { speed: true };

  function colValue(field, d) {
    if (COL_RENDER[field]) return COL_RENDER[field](d);
    var v = d[field];
    return escapeHtml(v == null || v === "" ? "—" : v);
  }

  function colLabel(field) {
    return columnsState.labels[field] || field;
  }

  function renderHead() {
    var thead = $table.querySelector("thead tr");
    if (!thead) return;
    // El sort del backend solo cubre estas claves.
    var SORTABLE = { name: "name", status: "status", plate_number: "plate", speed: "speed" };

    thead.innerHTML =
      '<th class="tp-fleet__th-check"><input type="checkbox" id="tp-fleet-check-all" aria-label="Seleccionar todo"></th>' +
      columnsState.selected
        .map(function (f) {
          var sort = SORTABLE[f] ? ' data-sort="' + SORTABLE[f] + '"' : "";
          return "<th" + (COL_RIGHT[f] ? ' class="tp-text-right"' : "") + sort + ">" + escapeHtml(colLabel(f)) + "</th>";
        })
        .join("") +
      '<th class="tp-fleet__th-actions"></th>';
  }

  function loadColumns(done) {
    fetch(COLS_URL, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          columnsState.available = j.available || [];
          columnsState.selected = j.selected || [];
          columnsState.labels = {};
          columnsState.available.forEach(function (c) {
            columnsState.labels[c.field] = c.label;
          });
          renderHead();
        }
        if (typeof done === "function") done();
      })
      .catch(function () {
        if (typeof done === "function") done();
      });
  }

  function openColumnsModal() {
    if (!window.tpModal) return;
    var chosen = {};
    columnsState.selected.forEach(function (f) { chosen[f] = true; });

    tpModal.open({
      title: "Columnas de la lista",
      size: "md",
      body:
        '<div class="tp-colpick">' +
        '<div class="tp-colpick__head">' +
        '<span class="tp-colpick__hint">Se comparte con la vista de lista clásica.</span>' +
        '<span class="tp-colpick__bulk">' +
        '<button type="button" class="tp-linkbtn" data-col-all>Todas</button>' +
        '<button type="button" class="tp-linkbtn" data-col-none>Ninguna</button>' +
        "</span></div>" +
        '<div class="tp-colpick__grid">' +
        columnsState.available
          .map(function (c) {
            return (
              '<label class="tp-colpick__item"><input type="checkbox" data-col="' + escapeHtml(c.field) + '"' +
              (chosen[c.field] ? " checked" : "") + "><span>" + escapeHtml(c.label) + "</span></label>"
            );
          })
          .join("") +
        "</div></div>",
      onMount: function (d) {
        d.addEventListener("click", function (e) {
          var all = e.target.closest("[data-col-all]");
          var none = e.target.closest("[data-col-none]");
          if (!all && !none) return;
          d.querySelectorAll("[data-col]").forEach(function (c) { c.checked = !!all; });
        });
      },
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            var cols = [];
            d.querySelectorAll("[data-col]:checked").forEach(function (c) {
              cols.push(c.getAttribute("data-col"));
            });
            if (!cols.length) {
              tpModal.toast("Elige al menos una columna.", "warning");
              return false;
            }
            tpModal
              .fetchJSON(COLS_URL, { method: "PUT", json: { columns: cols } })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Columnas guardadas.", "success");
                  tpModal.close(d);
                  columnsState.selected = cols;
                  renderHead();
                  render();
                } else {
                  tpModal.toast("No se pudo guardar.", "error");
                }
              });
            return false;
          },
        },
      ],
    });
  }

  function render() {
    var items = sortItems(state.items);
    if (!items.length) {
      $tbody.innerHTML =
        '<tr><td colspan="' + (columnsState.selected.length + 2) + '" class="tp-text-muted tp-text-center" style="padding:32px;">Sin resultados.</td></tr>';
    } else {
      var html = "";
      for (var i = 0; i < items.length; i++) {
        var d = items[i];
        var isSel = state.selected.has(d.id);
        html +=
          '<tr class="' + (isSel ? "is-selected" : "") + '" data-id="' + d.id + '">' +
          '<td class="tp-fleet__th-check"><input type="checkbox" data-select="' + d.id + '"' +
          (isSel ? " checked" : "") + "></td>" +
          columnsState.selected
            .map(function (f) {
              return "<td" + (COL_RIGHT[f] ? ' class="tp-text-right"' : "") + ">" + colValue(f, d) + "</td>";
            })
            .join("") +
          '<td><button class="tp-fleet__row-action" data-view="' + d.id +
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

  /* Si el estado vino en la URL, refleja el botón activo del segmentado */
  if (state.status) {
    document.querySelectorAll("[data-status]").forEach(function (b) {
      b.classList.toggle("is-active", b.getAttribute("data-status") === state.status);
    });
  }

  /* Botón de configuración de columnas (toolbar) */
  var colsBtn = document.getElementById("tp-fleet-columns");
  if (colsBtn) colsBtn.addEventListener("click", openColumnsModal);

  /* Boot: primero las columnas (definen la cabecera), luego los datos */
  loadColumns(function () {
    load();
  });
})();
