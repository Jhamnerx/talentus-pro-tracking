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
    // Filtros del panel: grupo (pivot por usuario), modelo de equipo y
    // protocolo. Las opciones vienen del propio feed (with_filters).
    group: "",
    model: "",
    protocol: "",
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
  var $status = document.getElementById("tp-fleet-status");
  var $group = document.getElementById("tp-fleet-group");
  var $model = document.getElementById("tp-fleet-model");
  var $protocol = document.getElementById("tp-fleet-protocol");

  var STATUS_LABELS = {
    moving: tpT('front.move'),
    idle: tpT('front.idle'),
    stopped: tpT('front.stopped'),
    offline: tpT('front.offline'),
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
  var columnsState = { available: [], selected: [], labels: {}, defs: {}, colors: {} };

  /* Icono asignado al vehiculo. Si no tiene uno propio (o es del tipo
     'arrow', que el mapa dibuja por estado), la flecha del color de estado:
     misma regla que el monitoreo de grupo, para que un vehiculo se vea igual
     en las dos pantallas. */
  function deviceIconSrc(d) {
    var icon = d.icon || {};

    if (!icon.path || (icon.type || "arrow") === "arrow") {
      return "/images/device_icons/estados/" + (d.icon_color || "gray") + ".png";
    }

    return icon.path.charAt(0) === "/" ? icon.path : "/" + icon.path;
  }

  // Cómo se pinta cada columna: valor + alineación.
  var COL_RENDER = {
    name: function (d) {
      // Celda de identidad: chip con el color de estado + nombre y, debajo, la
      // placa (o el IMEI si no hay). Dos líneas, como la lista del ERP.
      var sub = d.plate || d.imei || "";
      return (
        '<div class="tp-fleet__ident">' +
        '<span class="tp-fleet__ident-chip" style="background:' +
        escapeHtml(statusSoft(d.icon_color)) + '">' +
        '<img class="tp-fleet__ident-icon" src="' + escapeHtml(deviceIconSrc(d)) + '" alt="" loading="lazy">' +
        "</span>" +
        '<span class="tp-fleet__ident-text">' +
        '<button type="button" class="tp-fleet__ident-name" data-view="' + d.id + '" title="' + escapeHtml(d.name) + '">' +
        escapeHtml(d.name) + "</button>" +
        '<span class="tp-fleet__ident-sub">' + escapeHtml(sub || "—") + "</span>" +
        "</span></div>"
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
      return d.active ? tpT('ui.yes') : "No";
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
  // Columnas añadidas 19-ago (las mismas que ofrece el listado configurable
  // del legacy). `fuel` se despliega en tres celdas, como allí.
  COL_RENDER.stop_duration = function (d) { return escapeHtml(d.stop_duration || "—"); };
  COL_RENDER.idle_duration = function (d) { return escapeHtml(d.idle_duration || "—"); };
  COL_RENDER.ignition_duration = function (d) { return escapeHtml(d.ignition_duration || "—"); };
  COL_RENDER.last_event_title = function (d) { return escapeHtml(d.last_event_title || "—"); };
  COL_RENDER.last_event_type = function (d) { return escapeHtml(d.last_event_type || "—"); };
  COL_RENDER.last_event_time = function (d) { return escapeHtml(d.last_event_time || "—"); };
  COL_RENDER.address = function (d) {
    if (d.lat == null || d.lng == null) return "—";
    var key = Number(d.lat).toFixed(5) + "," + Number(d.lng).toFixed(5);
    var cached = geoCache[key];
    return '<span data-addr-key="' + key + '">' + escapeHtml(cached !== undefined ? cached : "…") + "</span>";
  };
  COL_RENDER.fuel = function (d) {
    var pct = d.fuel_percentage;
    if (!pct || pct === "-") return "—";
    return (
      '<span class="tp-fuel"><span class="tp-fuel__bar"><span class="tp-fuel__fill" style="width:' +
      (parseInt(pct, 10) || 0) + '%"></span></span>' +
      "<span>" + escapeHtml(pct) + "</span></span>"
    );
  };

  var COL_RIGHT = { speed: true, fuel: true };

  // El estado del vehículo llega como nombre de color (green, yellow…), que es
  // lo que guarda el legacy, no como hex.
  // Mismo criterio que live.js: getStatusColor() devuelve el color por NOMBRE
  // y aqui se traduce al token del tema. "ink" es el tono para texto.
  // Se repite en cada fichero porque shell.js va con defer y se ejecuta
  // despues que estos.
  var SC_NOMBRES = {
    green: 1, yellow: 1, orange: 1, red: 1,
    blue: 1, cyan: 1, gray: 1, black: 1, white: 1,
  };
  function statusVar(c, variante) {
    if (!c) return "";
    c = String(c).toLowerCase();
    if (SC_NOMBRES[c]) return "var(--sc-" + c + (variante ? "-" + variante : "") + ")";
    return c.charAt(0) === "#" ? c : "";
  }

  // Fondo suave del chip de identidad, a partir del color de estado. Antes se
  // pasaba por una tabla de seis nombres -faltaban gray, white y cyan, que
  // acababan en el gris de respaldo- y se mezclaba a mano con parseInt. Con el
  // token basta color-mix, que ademas sigue al tema.
  function statusSoft(c) {
    var v = statusVar(c);
    return v ? "color-mix(in srgb, " + v + " 14%, transparent)" : "rgba(100,116,139,.12)";
  }

  function colValue(field, d) {
    if (COL_RENDER[field]) return COL_RENDER[field](d);

    // Columna de sensor: el valor viene en d.sensors[hash] y se colorea según
    // los tramos definidos (mismo criterio que renderHtmlSensor del legacy).
    var def = columnsState.defs[field];
    if (def && def.class === "sensor") {
      var sv = d.sensors && d.sensors[field];
      if (!sv) return "—";

      var color = "";
      var ranges = columnsState.colors[field];
      if (ranges && sv.value !== null && sv.value !== undefined && isFinite(Number(sv.value))) {
        var n = Number(sv.value);
        ranges.forEach(function (r) {
          if (n >= Number(r.from) && n <= Number(r.to)) color = r.color;
        });
      }
      return color
        ? '<span style="color:' + escapeHtml(color) + ';font-weight:600;">' + escapeHtml(sv.text || "—") + "</span>"
        : escapeHtml(sv.text || "—");
    }

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
      '<th class="tp-fleet__th-check"><input type="checkbox" id="tp-fleet-check-all" aria-label="' + tpT('ui.select_all_n') + '"></th>' +
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
          columnsState.colors = j.colors || {};
          columnsState.labels = {};
          columnsState.defs = {};
          columnsState.available.forEach(function (c) {
            columnsState.labels[c.field] = c.label;
            columnsState.defs[c.field] = c;
          });
          renderHead();
        }
        if (typeof done === "function") done();
      })
      .catch(function () {
        if (typeof done === "function") done();
      });
  }

  /* ===== Rangos de color por sensor =====
   * Para las columnas de sensor numérico se pueden definir tramos
   * (de X a Y en tal color) y la celda se pinta según el valor. Es el mismo
   * formato que guarda el configurador del legacy: columns[i].color[].
   */
  function rangeRowHtml(r) {
    r = r || { from: "", to: "", color: "#ef4444" };
    return (
      '<div class="tp-range" data-range>' +
      '<input type="number" step="any" class="tp-input tp-input--sm" data-range-from placeholder="Desde" value="' +
      escapeHtml(r.from) + '">' +
      '<input type="number" step="any" class="tp-input tp-input--sm" data-range-to placeholder="Hasta" value="' +
      escapeHtml(r.to) + '">' +
      '<input type="color" class="tp-range__color" data-range-color value="' + escapeHtml(r.color || "#ef4444") + '">' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-range-del title="Quitar tramo">×</button>' +
      "</div>"
    );
  }

  function openRangesModal(field) {
    var def = columnsState.defs[field];
    if (!def) return;

    var current = (columnsState.colors[field] || []).slice();

    tpModal.open({
      title: "Colores · " + def.label,
      size: "sm",
      body:
        '<div class="tp-ranges">' +
        '<p class="tp-text-sm tp-text-muted" style="margin:0 0 10px;">' + tpT('ui.cell_color_from_range') + '</p>' +
        '<div data-ranges-list>' +
        (current.length ? current.map(rangeRowHtml).join("") : rangeRowHtml(null)) +
        "</div>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-range-add style="margin-top:8px;">' + tpT('ui.add_range_plus') + '</button>' +
        "</div>",
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('global.apply'),
          variant: "primary",
          close: false,
          onClick: function (d) {
            var ranges = [];
            d.querySelectorAll("[data-range]").forEach(function (row) {
              var from = row.querySelector("[data-range-from]").value;
              var to = row.querySelector("[data-range-to]").value;
              var color = row.querySelector("[data-range-color]").value;
              if (from === "" || to === "") return;
              ranges.push({ from: Number(from), to: Number(to), color: color });
            });

            columnsState.colors[field] = ranges;
            tpModal.close(d);
            tpModal.toast(tpT('ui.applied_on_save_columns'), "success");
            return false;
          },
        },
      ],
      onMount: function (d) {
        var list = d.querySelector("[data-ranges-list]");
        d.querySelector("[data-range-add]").addEventListener("click", function () {
          list.insertAdjacentHTML("beforeend", rangeRowHtml(null));
        });
        list.addEventListener("click", function (e) {
          var del = e.target.closest("[data-range-del]");
          if (!del) return;
          if (list.querySelectorAll("[data-range]").length > 1) del.closest("[data-range]").remove();
        });
      },
    });
  }

  function openColumnsModal() {
    if (!window.tpModal) return;
    var chosen = {};
    columnsState.selected.forEach(function (f) { chosen[f] = true; });

    tpModal.open({
      title: tpT('ui.list_columns'),
      size: "md",
      body:
        '<div class="tp-colpick">' +
        '<div class="tp-colpick__head">' +
        '<span class="tp-colpick__hint">' + tpT('ui.shared_with_classic_list') + '</span>' +
        '<span class="tp-colpick__bulk">' +
        '<button type="button" class="tp-linkbtn" data-col-all>' + tpT('ui.all_f') + '</button>' +
        '<button type="button" class="tp-linkbtn" data-col-none>' + tpT('front.none') + '</button>' +
        "</span></div>" +
        '<div class="tp-colpick__grid">' +
        columnsState.available
          .map(function (c) {
            var hasRanges = (columnsState.colors[c.field] || []).length;
            return (
              '<label class="tp-colpick__item"><input type="checkbox" data-col="' + escapeHtml(c.field) + '"' +
              (chosen[c.field] ? " checked" : "") + "><span>" + escapeHtml(c.label) + "</span>" +
              // Solo los sensores numéricos admiten tramos de color
              (c.numeric
                ? '<button type="button" class="tp-colpick__ranges' + (hasRanges ? " is-set" : "") +
                  '" data-ranges="' + escapeHtml(c.field) + '" title="' + tpT('ui.colors_by_range') + '">🎨</button>'
                : "") +
              "</label>"
            );
          })
          .join("") +
        "</div></div>",
      onMount: function (d) {
        d.addEventListener("click", function (e) {
          var ranges = e.target.closest("[data-ranges]");
          if (ranges) {
            // el botón vive dentro del <label>: sin esto marcaría la casilla
            e.preventDefault();
            e.stopPropagation();
            return openRangesModal(ranges.getAttribute("data-ranges"));
          }
          var all = e.target.closest("[data-col-all]");
          var none = e.target.closest("[data-col-none]");
          if (!all && !none) return;
          d.querySelectorAll("[data-col]").forEach(function (c) { c.checked = !!all; });
        });
      },
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('global.save'),
          variant: "primary",
          close: false,
          onClick: function (d) {
            var cols = [];
            d.querySelectorAll("[data-col]:checked").forEach(function (c) {
              cols.push(c.getAttribute("data-col"));
            });
            if (!cols.length) {
              tpModal.toast(tpT('ui.pick_one_column'), "warning");
              return false;
            }
            // Solo los rangos de las columnas que quedan activas
            var colors = {};
            cols.forEach(function (f) {
              if ((columnsState.colors[f] || []).length) colors[f] = columnsState.colors[f];
            });

            tpModal
              .fetchJSON(COLS_URL, { method: "PUT", json: { columns: cols, colors: colors } })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Columnas guardadas.", "success");
                  tpModal.close(d);
                  columnsState.selected = cols;
                  renderHead();
                  render();
                } else {
                  tpModal.toast(tpT('ui.save_failed'), "error");
                }
              });
            return false;
          },
        },
      ],
    });
  }

  /* ===== Columna Dirección =====
   * El legacy la geocodifica en servidor, fila a fila (~0,5 s cada una). Aquí se
   * resuelve en el cliente y solo para las filas que se están viendo, así la
   * tabla aparece al instante y las direcciones se van rellenando.
   */
  var GEO_URL = "/address";
  var geoCache = {};
  var geoQueue = [];
  var geoBusy = false;

  function parseGeo(text) {
    if (text == null) return "";
    text = String(text).trim();
    if (!text) return "";
    try {
      var j = JSON.parse(text);
      if (typeof j === "string") return j;
      if (j && typeof j === "object") return j.address || j.name || j.title || "";
    } catch (e) {}
    var lt = text.indexOf("<");
    if (lt !== -1) text = text.slice(0, lt).trim();
    return text;
  }

  function geoKey(lat, lng) {
    return Number(lat).toFixed(5) + "," + Number(lng).toFixed(5);
  }

  // Cola de uno en uno: el geocodificador es lento y no conviene lanzarle
  // 100 peticiones a la vez.
  function geoNext() {
    if (geoBusy || !geoQueue.length) return;
    geoBusy = true;

    var job = geoQueue.shift();
    fetch(GEO_URL + "?lat=" + job.lat + "&lng=" + job.lng, {
      credentials: "same-origin",
      headers: { Accept: "*/*", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        var addr = parseGeo(text) || "—";
        geoCache[job.key] = addr;
        document.querySelectorAll('[data-addr-key="' + job.key + '"]').forEach(function (el) {
          el.textContent = addr;
        });
      })
      .catch(function () {
        geoCache[job.key] = "—";
      })
      .then(function () {
        geoBusy = false;
        geoNext();
      });
  }

  function resolveVisibleAddresses() {
    if (columnsState.selected.indexOf("address") === -1) return;

    geoQueue = [];
    $tbody.querySelectorAll("[data-addr-key]").forEach(function (el) {
      var key = el.getAttribute("data-addr-key");
      if (geoCache[key] !== undefined) {
        el.textContent = geoCache[key];
        return;
      }
      var parts = key.split(",");
      if (geoQueue.some(function (j) { return j.key === key; })) return;
      geoQueue.push({ key: key, lat: parts[0], lng: parts[1] });
    });
    geoNext();
  }

  function render() {
    var items = sortItems(state.items);
    if (!items.length) {
      $tbody.innerHTML =
        '<tr><td colspan="' + (columnsState.selected.length + 2) + '" class="tp-text-muted tp-text-center" style="padding:32px;">' + tpT('ui.no_results') + '</td></tr>';
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
          '<td class="tp-fleet__th-actions">' +
          '<div class="tp-fleet__kebab">' +
          '<button type="button" class="tp-fleet__kebab-btn" data-kebab="' + d.id + '" aria-haspopup="menu" aria-label="Acciones">' +
          '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
          "</button>" +
          '<div class="tp-fleet__kebab-menu" role="menu" hidden>' +
          '<button type="button" role="menuitem" data-view="' + d.id + '">' + tpT('ui.see_on_map') + '</button>' +
          '<button type="button" role="menuitem" data-edit="' + d.id + '">' + tpT('global.edit') + '</button>' +
          '<button type="button" role="menuitem" data-history="' + d.id + '">Historial</button>' +
          "</div></div></td>" +
          "</tr>";
      }
      $tbody.innerHTML = html;
      resolveVisibleAddresses();
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
        : tpT('ui.no_devices');
    $page.textContent =
      tpT('ui.page') + state.page + " / " + Math.max(1, state.pages);
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

  function load(opts) {
    var silent = !!(opts && opts.silent);
    state.loading = true;
    // En el refresco automático no se toca el pie: parpadear "Cargando…" cada
    // 30 s distrae y no aporta nada.
    if (!silent) $page.textContent = "Cargando…";
    var params = {
      page: state.page,
      limit: state.limit,
      q: state.q,
      status: state.status,
      group: state.group,
      model: state.model,
      protocol: state.protocol,
    };
    // Las opciones de los desplegables solo se piden una vez: no cambian al
    // paginar y salen de consultas DISTINCT sobre toda la flota.
    if (!filtersLoaded) params.with_filters = 1;

    var url = DATA_URL + "?" + new URLSearchParams(params).toString();

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
        if (p.filters) fillFilters(p.filters);
        render();
      })
      .catch(function (err) {
        state.loading = false;
        console.warn("[fleet] load failed", err);
      });
  }

  /**
   * Rellena los desplegables con lo que hay realmente en la flota. Conserva
   * el valor elegido por si el usuario ya habia filtrado antes de que llegue
   * la respuesta.
   */
  var filtersLoaded = false;

  function fillOptions(sel, options) {
    if (!sel) return;
    var keep = sel.value;
    // La primera opcion ("Todos") se mantiene siempre.
    while (sel.options.length > 1) sel.remove(1);
    options.forEach(function (o) {
      var el = document.createElement("option");
      el.value = o.value;
      el.textContent = o.label;
      sel.appendChild(el);
    });
    if (keep) sel.value = keep;
    if (window.tpSelect && tpSelect.refresh) {
      try {
        tpSelect.refresh(sel);
      } catch (e) {}
    }
  }

  function fillFilters(f) {
    filtersLoaded = true;

    fillOptions(
      $group,
      (f.groups || []).map(function (g) {
        return { value: String(g.id), label: g.title + (g.count ? " (" + g.count + ")" : "") };
      }),
    );
    fillOptions(
      $model,
      (f.models || []).map(function (m) {
        return { value: m, label: m };
      }),
    );
    fillOptions(
      $protocol,
      (f.protocols || []).map(function (p) {
        return { value: p, label: p };
      }),
    );
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

  // El estado paso de botones segmentados a desplegable, junto al resto de
  // filtros. El dashboard sigue enlazando con ?status=..., asi que el select
  // arranca con lo que traiga la URL.
  if ($status) {
    if (state.status) $status.value = state.status;
    $status.addEventListener("change", function () {
      state.status = $status.value || "";
      state.page = 1;
      load();
    });
  }

  [
    [$group, "group"],
    [$model, "model"],
    [$protocol, "protocol"],
  ].forEach(function (pair) {
    var sel = pair[0];
    var key = pair[1];
    if (!sel) return;
    sel.addEventListener("change", function () {
      state[key] = sel.value || "";
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

  // Acciones de fila: kebab (ver en el mapa / editar / historial)
  function closeKebabs(except) {
    $tbody.querySelectorAll(".tp-fleet__kebab-menu:not([hidden])").forEach(function (m) {
      if (m !== except) m.hidden = true;
    });
  }
  document.addEventListener("click", function () { closeKebabs(); });

  $tbody.addEventListener("click", function (e) {
    var kebab = e.target.closest("[data-kebab]");
    if (kebab) {
      e.stopPropagation();
      var menu = kebab.parentNode.querySelector(".tp-fleet__kebab-menu");
      var willOpen = menu.hidden;
      closeKebabs(menu);
      menu.hidden = !willOpen;
      return;
    }

    var view = e.target.closest("[data-view]");
    if (view) {
      window.location.href = "/ui/v2/live?device=" + encodeURIComponent(view.getAttribute("data-view"));
      return;
    }

    var edit = e.target.closest("[data-edit]");
    if (edit) {
      closeKebabs();
      if (window.tpDeviceModal) tpDeviceModal.openEdit(edit.getAttribute("data-edit"), function () { load(); });
      return;
    }

    var hist = e.target.closest("[data-history]");
    if (hist) {
      window.location.href = "/ui/v2/history?device=" + encodeURIComponent(hist.getAttribute("data-history")) + "&range=today";
    }
  });

  // CSV export (current page)
  $exp.addEventListener("click", function () {
    var headers = [
      "ID",
      tpT('ui.name'),
      tpT('front.status'),
      tpT('ui.plate'),
      "IMEI",
      tpT('front.model'),
      "SIM",
      "km/h",
      tpT('ui.engine_h'),
      tpT('ui.last_movement_js'),
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

  /* ===== Refresco automático =====
   * La lista muestra estado, velocidad y duraciones, que cambian solas. Se
   * recarga en segundo plano, pero NO mientras hay un modal abierto o filas
   * seleccionadas (repintar bajo el usuario le haría perder el trabajo), ni
   * con la pestaña en segundo plano.
   */
  var POLL_MS = parseInt(root.getAttribute("data-poll-interval"), 10) || 0;
  var pollHandle = null;

  function canAutoRefresh() {
    if (document.hidden) return false;
    if (state.selected && state.selected.size) return false;
    if (document.querySelector(".tp-modal, [data-tp-modal]")) return false;
    return true;
  }

  function startPolling() {
    if (!POLL_MS || pollHandle) return;
    pollHandle = setInterval(function () {
      if (canAutoRefresh()) load({ silent: true });
    }, POLL_MS);
  }

  document.addEventListener("visibilitychange", function () {
    // Al volver a la pestaña, refresca de inmediato en vez de esperar al ciclo.
    if (!document.hidden && POLL_MS && canAutoRefresh()) load({ silent: true });
  });

  /* Filas por página */
  var limitSel = document.getElementById("tp-fleet-limit");
  if (limitSel) {
    limitSel.value = String(state.limit);
    limitSel.addEventListener("change", function () {
      state.limit = parseInt(limitSel.value, 10) || 100;
      state.page = 1;
      load();
    });
  }

  /* Botón de configuración de columnas (toolbar) */
  var colsBtn = document.getElementById("tp-fleet-columns");
  if (colsBtn) colsBtn.addEventListener("click", openColumnsModal);

  /* Boot: primero las columnas (definen la cabecera), luego los datos */
  loadColumns(function () {
    load();
    startPolling();
  });
})();
