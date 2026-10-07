/* =====================================================
 * TalentusPro · tp-operations — Control de vueltas
 * -----------------------------------------------------
 * Tres pestañas sobre tablas ya calculadas (operation_*):
 *   · En vivo:      estado de cada vehículo y vueltas del día. Se refresca
 *                   por sondeo (como el mapa en vivo): no calcula nada.
 *   · Vueltas:      historial por rango, con tramos y paradas desplegables.
 *   · Operaciones:  alta/edición/borrado, activar, recalcular.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-ops-page");
  if (!root || !window.tpModal) return;

  var URLS = {
    data: root.getAttribute("data-url"),
    meta: root.getAttribute("data-meta-url"),
    live: root.getAttribute("data-live-url"),
    cycles: root.getAttribute("data-cycles-url"),
    cycle: root.getAttribute("data-cycle-url"),
    store: root.getAttribute("data-store-url"),
    item: root.getAttribute("data-item-url"),
    toggle: root.getAttribute("data-toggle-url"),
    recalcPreview: root.getAttribute("data-recalc-preview-url"),
    recalc: root.getAttribute("data-recalc-url"),
    recalcs: root.getAttribute("data-recalcs-url"),
    history: root.getAttribute("data-history-url"),
    report: root.getAttribute("data-report-url"),
  };
  var canEdit = root.getAttribute("data-can-edit") === "1";
  var canRemove = root.getAttribute("data-can-remove") === "1";

  var LIVE_EVERY_MS = 20000;
  var REPORT_TYPE = 910;

  var state = {
    tab: "live",
    meta: null,
    byId: {},
    list: { page: 1, s: "" },
    cycles: { page: 1 },
    liveTimer: null,
    liveOperation: null,
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return tpModal.escapeHtml(s == null ? "" : String(s)); }
  function t(k, r) { return tpT(k, r); }

  /* "Hoy" en hora local: toISOString() va en UTC y en Perú después de las
     19:00 daría el día siguiente. */
  function ymd(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + "-" + (m < 10 ? "0" : "") + m + "-" + (day < 10 ? "0" : "") + day;
  }
  function today() { return ymd(new Date()); }

  function fillSelect(sel, items, placeholder, selected) {
    if (!sel) return;
    sel.innerHTML = (placeholder != null ? '<option value="">' + esc(placeholder) + "</option>" : "") +
      items.map(function (i) {
        return '<option value="' + i.id + '"' + (String(selected) === String(i.id) ? " selected" : "") + ">" + esc(i.name) + "</option>";
      }).join("");
    if (window.tpSelect && tpSelect.refresh) tpSelect.refresh(sel);
  }

  function pill(kind, label) {
    return '<span class="tp-ops__pill tp-ops__pill--' + esc(kind) + '">' + esc(label) + "</span>";
  }

  /* ---------- Pestañas ---------- */
  root.querySelectorAll("[data-ops-tab]").forEach(function (btn) {
    btn.addEventListener("click", function () { showTab(btn.getAttribute("data-ops-tab")); });
  });

  function showTab(key) {
    state.tab = key;
    root.querySelectorAll("[data-ops-tab]").forEach(function (b) {
      var on = b.getAttribute("data-ops-tab") === key;
      b.classList.toggle("is-active", on);
      b.setAttribute("aria-selected", on ? "true" : "false");
    });
    root.querySelectorAll("[data-ops-panel]").forEach(function (p) {
      p.hidden = p.getAttribute("data-ops-panel") !== key;
    });
    if (key === "live") startLive(); else stopLive();
  }

  /* ---------- Meta ---------- */
  function loadMeta() {
    return tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      if (!d.ok) return;
      state.meta = d;

      var ops = d.operations || [];
      var active = ops.filter(function (o) { return o.active; });

      fillSelect($("tp-ops-live-operation"), ops, ops.length ? null : t("ui.ops_pick_operation"), state.liveOperation || (active[0] && active[0].id));
      fillSelect($("tp-ops-filter-operation"), ops, t("ui.all_f"));
      fillSelect($("tp-ops-filter-device"), d.devices || [], t("front.all"));
      fillShiftFilter();

      state.liveOperation = $("tp-ops-live-operation").value || null;
    });
  }

  function fillShiftFilter() {
    var opId = Number($("tp-ops-filter-operation").value);
    var op = state.byId[opId];
    var shifts = op ? op.shifts.map(function (s) { return { id: s.id, name: s.name }; }) : [];
    if (op) shifts.push({ id: "none", name: t("ui.ops_no_shift") });
    fillSelect($("tp-ops-filter-shift"), shifts, t("front.all"));
  }

  /* =====================================================
   * En vivo
   * ===================================================== */
  function startLive() {
    stopLive();
    loadLive();
    state.liveTimer = setInterval(function () {
      if (document.visibilityState === "visible") loadLive();
    }, LIVE_EVERY_MS);
  }
  function stopLive() {
    if (state.liveTimer) clearInterval(state.liveTimer);
    state.liveTimer = null;
  }

  function loadLive() {
    var opId = $("tp-ops-live-operation").value;
    var rows = $("tp-ops-live-rows");
    if (!opId) {
      rows.innerHTML = '<tr><td colspan="8" class="tp-svc-table__empty">' + esc(t("ui.ops_pick_operation")) + "</td></tr>";
      return;
    }
    var date = $("tp-ops-live-date").value || today();
    var qs = "?operation_id=" + encodeURIComponent(opId) + "&date=" + encodeURIComponent(date);

    tpModal.fetchJSON(URLS.live + qs, { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      if (!d.ok) return;

      Object.keys(d.kpis || {}).forEach(function (k) {
        var el = root.querySelector('[data-kpi="' + k + '"]');
        if (el) el.textContent = d.kpis[k];
      });
      $("tp-ops-live-updated").textContent = d.updated_at ? (t("ui.updated") + " " + d.updated_at) : "";

      var items = d.rows || [];
      rows.innerHTML = items.length ? items.map(liveRow).join("")
        : '<tr><td colspan="8" class="tp-svc-table__empty">' + esc(t("ui.ops_no_vehicles")) + "</td></tr>";
    });
  }

  function liveRow(r) {
    var status = pill(r.state, r.state_label) + (r.point ? ' <span class="tp-text-muted">' + esc(r.point) + "</span>" : "");
    if (r.live_stop) {
      status += " " + pill(r.stopped_outside ? "stopped" : "no_data", t("ui.ops_stopped_for", { time: r.live_stop }));
    }
    return '<tr class="is-clickable" data-live-device="' + r.device_id + '">' +
      "<td>" + esc(r.device) + "</td>" +
      "<td>" + status + "</td>" +
      "<td>" + r.cycles + "</td>" +
      "<td>" + esc(r.last_cycle || "—") + "</td>" +
      "<td>" + esc(r.stop) + "</td>" +
      "<td>" + esc(r.idle) + "</td>" +
      '<td class="' + (r.unauthorized ? "tp-ops__num--bad" : "") + '">' + r.unauthorized + "</td>" +
      "<td>" + esc(r.last_time || "—") + "</td>" +
      "</tr>";
  }

  $("tp-ops-live-operation").addEventListener("change", function () { state.liveOperation = this.value; loadLive(); });
  $("tp-ops-live-date").addEventListener("change", loadLive);

  // Un clic en la fila abre sus vueltas del día en la otra pestaña.
  $("tp-ops-live-rows").addEventListener("click", function (e) {
    var tr = e.target.closest("[data-live-device]");
    if (!tr) return;
    var date = $("tp-ops-live-date").value || today();
    $("tp-ops-from").value = date;
    $("tp-ops-to").value = date;
    $("tp-ops-filter-operation").value = $("tp-ops-live-operation").value;
    fillShiftFilter();
    $("tp-ops-filter-device").value = tr.getAttribute("data-live-device");
    if (window.tpSelect && tpSelect.refresh) {
      tpSelect.refresh($("tp-ops-filter-operation"));
      tpSelect.refresh($("tp-ops-filter-device"));
    }
    showTab("cycles");
    state.cycles.page = 1;
    loadCycles();
  });

  /* =====================================================
   * Vueltas
   * ===================================================== */
  function cyclesQuery() {
    var p = {
      from: $("tp-ops-from").value,
      to: $("tp-ops-to").value,
      operation_id: $("tp-ops-filter-operation").value,
      device_id: $("tp-ops-filter-device").value,
      shift_id: $("tp-ops-filter-shift").value,
      only_unauthorized: $("tp-ops-only-unauthorized").checked ? 1 : "",
      page: state.cycles.page,
    };
    return Object.keys(p).filter(function (k) { return p[k] !== "" && p[k] != null; })
      .map(function (k) { return k + "=" + encodeURIComponent(p[k]); }).join("&");
  }

  function loadCycles() {
    var rows = $("tp-ops-cycle-rows");
    if (!$("tp-ops-from").value || !$("tp-ops-to").value) {
      rows.innerHTML = '<tr><td colspan="12" class="tp-svc-table__empty">' + esc(t("ui.pick_date_range")) + "</td></tr>";
      return;
    }
    rows.innerHTML = '<tr><td colspan="12" class="tp-svc-table__empty">' + esc(t("front.loading")) + "…</td></tr>";

    tpModal.fetchJSON(URLS.cycles + "?" + cyclesQuery(), { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      var items = d.items || [];
      rows.innerHTML = items.length ? items.map(cycleRow).join("")
        : '<tr><td colspan="12" class="tp-svc-table__empty">' + esc(t("ui.ops_no_cycles")) + "</td></tr>";
      $("tp-ops-cycles-range").textContent = t("ui.page_short") + " " + (d.page || 1) + " / " + (d.pages || 1);
      $("tp-ops-cycles-total").textContent = (d.total || 0) + " " + t("ui.ops_cycles").toLowerCase();
      state.cycles.pages = d.pages || 1;
    });
  }

  function cycleRow(c) {
    var statuses = (state.meta && state.meta.statuses) || {};
    var status = pill(c.status, statuses[c.status] || c.status);
    if (c.pending) status += ' <span class="tp-ops__hint">' + esc(t("ui.ops_pending_calc")) + "</span>";

    return '<tr class="is-clickable" data-cycle="' + c.id + '">' +
      '<td><span class="tp-ops__toggle" aria-hidden="true">›</span></td>' +
      "<td>" + c.number + "</td>" +
      "<td>" + esc(c.device) + (c.operation ? '<div class="tp-ops__hint">' + esc(c.operation) + "</div>" : "") + "</td>" +
      "<td>" + esc(c.started_at) + "</td>" +
      "<td>" + esc(c.shift || t("ui.ops_no_shift")) + "</td>" +
      "<td>" + esc(c.duration || "—") + "</td>" +
      "<td>" + esc(c.outbound || "—") + "</td>" +
      "<td>" + esc(c.inbound || "—") + "</td>" +
      "<td>" + esc(c.stop) + "</td>" +
      "<td>" + esc(c.idle || "—") + "</td>" +
      '<td class="' + (c.unauthorized ? "tp-ops__num--bad" : "") + '">' + c.unauthorized + "</td>" +
      "<td>" + status + "</td>" +
      "</tr>";
  }

  $("tp-ops-cycle-rows").addEventListener("click", function (e) {
    var link = e.target.closest("[data-map-device]");
    if (link) {
      e.preventDefault();
      var url = URLS.history + "?device=" + link.getAttribute("data-map-device") +
        "&from=" + encodeURIComponent(link.getAttribute("data-map-from")) +
        "&to=" + encodeURIComponent(link.getAttribute("data-map-to"));
      window.open(url, "_blank");
      return;
    }
    var tr = e.target.closest("[data-cycle]");
    if (!tr) return;
    var open = tr.classList.toggle("is-open");
    var next = tr.nextElementSibling;
    if (next && next.classList.contains("tp-ops__detail")) next.remove();
    if (!open) return;

    var detail = document.createElement("tr");
    detail.className = "tp-ops__detail";
    detail.innerHTML = '<td colspan="12" class="tp-text-muted">' + esc(t("front.loading")) + "…</td>";
    tr.parentNode.insertBefore(detail, tr.nextSibling);

    tpModal.fetchJSON(URLS.cycle.replace("{id}", tr.getAttribute("data-cycle")), { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      if (!d.ok) { detail.innerHTML = '<td colspan="12">' + esc(t("ui.network_error")) + "</td>"; return; }
      detail.innerHTML = '<td colspan="12">' + cycleDetail(d.item) + "</td>";
    });
  });

  function cycleDetail(c) {
    if (!c.legs || !c.legs.length) return '<span class="tp-text-muted">' + esc(t("ui.ops_legs_open")) + "</span>";
    return c.legs.map(function (l) {
      var head = '<div class="tp-ops__leg-head">' +
        "<strong>" + esc(l.direction === "outbound" ? t("ui.ops_outbound") : t("ui.ops_inbound")) + "</strong>" +
        "<span>" + esc(t("ui.ops_leg_from_to", { from: l.from || "?", to: l.to || "?" })) + "</span>" +
        "<span>" + esc(l.started_at) + " → " + esc(l.ended_at || "…") + "</span>" +
        "<span>" + esc(l.duration) + "</span>" +
        (l.distance ? "<span>" + esc(t("ui.ops_leg_distance")) + ": " + esc(l.distance) + "</span>" : "") +
        (l.gap ? "<span>" + esc(t("ui.ops_leg_gap")) + ": " + esc(l.gap) + "</span>" : "") +
        (l.ended_local ? '<a href="#" class="tp-btn tp-btn--ghost tp-btn--sm" data-map-device="' + c.device_id + '" data-map-from="' + esc(l.started_local) + '" data-map-to="' + esc(l.ended_local) + '">' + esc(t("ui.ops_view_on_map")) + "</a>" : "") +
        (!l.calculated && l.ended_at ? '<span class="tp-ops__hint">' + esc(t("ui.ops_pending_calc")) + "</span>" : "") +
        "</div>";

      var stops = l.stops && l.stops.length ? '<table class="tp-ops__stops"><thead><tr>' +
        "<th>" + esc(t("ui.ops_stop_start")) + "</th><th>" + esc(t("ui.ops_stop_duration")) + "</th>" +
        "<th>" + esc(t("ui.ops_engine_on")) + "</th><th>" + esc(t("ui.ops_engine_off")) + "</th>" +
        "<th>" + esc(t("ui.ops_geofence")) + "</th><th>" + esc(t("front.status")) + "</th><th></th></tr></thead><tbody>" +
        l.stops.map(function (s) {
          var cls = s.doubtful ? "is-doubtful" : (s.authorized ? "" : "is-unauthorized");
          var st = s.doubtful ? t("ui.ops_doubtful") : (s.authorized ? t("ui.ops_authorized") : t("ui.ops_unauthorized"));
          return '<tr class="' + cls + '"><td>' + esc(s.started_at) + "</td><td>" + esc(s.duration) + "</td>" +
            "<td>" + esc(s.engine_on || "—") + "</td><td>" + esc(s.engine_off || "—") + "</td>" +
            "<td>" + esc(s.geofence || "—") + "</td><td>" + esc(st) + "</td>" +
            '<td><a href="#" data-map-device="' + c.device_id + '" data-map-from="' + esc(s.started_local) + '" data-map-to="' + esc(s.ended_local) + '">' + esc(t("ui.ops_view_on_map")) + "</a></td></tr>";
        }).join("") + "</tbody></table>"
        : '<div class="tp-ops__hint">' + esc(l.ended_at ? t("ui.ops_no_stops") : t("ui.ops_legs_open")) + "</div>";

      return '<div class="tp-ops__leg">' + head + stops + "</div>";
    }).join("");
  }

  $("tp-ops-run").addEventListener("click", function () { state.cycles.page = 1; loadCycles(); });
  $("tp-ops-filter-operation").addEventListener("change", fillShiftFilter);
  $("tp-ops-cycles-prev").addEventListener("click", function () { if (state.cycles.page > 1) { state.cycles.page--; loadCycles(); } });
  $("tp-ops-cycles-next").addEventListener("click", function () { if (state.cycles.page < (state.cycles.pages || 1)) { state.cycles.page++; loadCycles(); } });

  /* Exportar: el mismo POST oculto que usa la pagina de Reportes. */
  var exportBtn = $("tp-ops-export");
  if (exportBtn) exportBtn.addEventListener("click", function () {
    if (!$("tp-ops-from").value || !$("tp-ops-to").value) { tpModal.toast(t("ui.pick_date_range"), "error"); return; }
    var devIds = $("tp-ops-filter-device").value ? [Number($("tp-ops-filter-device").value)]
      : (state.meta.devices || []).map(function (d) { return d.id; });
    var campos = {
      type: REPORT_TYPE, format: "html", generate: 1,
      date_from: $("tp-ops-from").value, from_time: "00:00",
      date_to: $("tp-ops-to").value, to_time: "23:59",
      devices: devIds,
      selected_devices: ["s;0;"].concat(devIds.map(function (i) { return "id;1;" + i; })),
      operation_id: $("tp-ops-filter-operation").value ? [$("tp-ops-filter-operation").value] : [],
      only_unauthorized: $("tp-ops-only-unauthorized").checked ? 1 : 0,
    };
    postDescarga(URLS.report, campos);
  });

  function postDescarga(url, campos) {
    var form = document.createElement("form");
    form.method = "POST"; form.action = url; form.target = "_blank"; form.style.display = "none";
    function campo(n, v) { var i = document.createElement("input"); i.type = "hidden"; i.name = n; i.value = v; form.appendChild(i); }
    campo("_token", tpModal.csrf());
    Object.keys(campos).forEach(function (k) {
      var v = campos[k];
      if (Array.isArray(v)) v.forEach(function (x) { campo(/\[\]$/.test(k) ? k : k + "[]", x); });
      else if (v != null && v !== "") campo(k, v);
    });
    document.body.appendChild(form); form.submit(); form.remove();
  }

  /* =====================================================
   * Operaciones
   * ===================================================== */
  function loadList() {
    var qs = "?page=" + state.list.page + "&s=" + encodeURIComponent(state.list.s);
    tpModal.fetchJSON(URLS.data + qs, { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      var items = d.items || [];
      state.byId = {};
      items.forEach(function (i) { state.byId[i.id] = i; });

      $("tp-ops-rows").innerHTML = items.length ? items.map(listRow).join("")
        : '<tr><td colspan="7" class="tp-svc-table__empty">' + esc(t("ui.ops_no_operations_yet")) + "</td></tr>";
      $("tp-ops-range").textContent = t("ui.page_short") + " " + (d.page || 1) + " / " + (d.pages || 1);
      $("tp-ops-total").textContent = (d.total || 0) + " " + t("front.operations").toLowerCase();
      state.list.pages = d.pages || 1;
      fillShiftFilter();
    });
  }

  function listRow(o) {
    var loads = o.points.filter(function (p) { return p.role === "load"; }).map(function (p) { return p.name; });
    var unloads = o.points.filter(function (p) { return p.role === "unload"; }).map(function (p) { return p.name; });
    var acciones = "";
    if (canEdit) {
      acciones += '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-ops-edit="' + o.id + '">' + esc(t("global.edit")) + "</button> ";
      acciones += '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-ops-toggle="' + o.id + '">' + esc(o.active ? t("ui.deactivate") : t("ui.activate")) + "</button> ";
    }
    if (canRemove) acciones += '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-ops-del="' + o.id + '">' + esc(t("global.delete")) + "</button>";

    return "<tr>" +
      "<td>" + esc(o.name) + "</td>" +
      "<td>" + esc(o.group || "—") + "</td>" +
      "<td>" + o.devices_count + "</td>" +
      "<td>" + (o.points.length ? "<div><strong>" + esc(t("ui.ops_load_points")) + ":</strong> " + esc(loads.join(", ")) + "</div><div><strong>" + esc(t("ui.ops_unload_points")) + ":</strong> " + esc(unloads.join(", ")) + "</div>" : esc(t("ui.ops_points_none"))) + "</td>" +
      "<td>" + (o.shifts.length ? o.shifts.map(function (s) { return esc(s.name) + " " + esc(s.start_time) + "–" + esc(s.end_time); }).join("<br>") : "—") + "</td>" +
      "<td>" + pill(o.active ? "completed" : "no_data", o.active ? t("global.yes") : t("global.no")) + "</td>" +
      '<td class="tp-svc-table__actions" style="white-space:nowrap;text-align:right;">' + acciones + "</td>" +
      "</tr>";
  }

  $("tp-ops-rows").addEventListener("click", function (e) {
    var b = e.target.closest("button");
    if (!b) return;
    if (b.hasAttribute("data-ops-edit")) openForm(state.byId[Number(b.getAttribute("data-ops-edit"))]);
    else if (b.hasAttribute("data-ops-del")) del(Number(b.getAttribute("data-ops-del")));
    else if (b.hasAttribute("data-ops-toggle")) {
      var o = state.byId[Number(b.getAttribute("data-ops-toggle"))];
      tpModal.fetchJSON(URLS.toggle.replace("{id}", o.id), { method: "POST", json: { active: o.active ? 0 : 1 } })
        .then(function (res) { if (res.ok) { loadList(); loadMeta(); } else tpModal.errorToast(res, t("ui.save_failed")); });
    }
  });

  var searchTimer = null;
  $("tp-ops-search").addEventListener("input", function () {
    clearTimeout(searchTimer);
    var v = this.value;
    searchTimer = setTimeout(function () { state.list.s = v.trim(); state.list.page = 1; loadList(); }, 300);
  });
  $("tp-ops-prev").addEventListener("click", function () { if (state.list.page > 1) { state.list.page--; loadList(); } });
  $("tp-ops-next").addEventListener("click", function () { if (state.list.page < (state.list.pages || 1)) { state.list.page++; loadList(); } });

  var addBtn = $("tp-ops-add");
  if (addBtn) addBtn.addEventListener("click", function () { openForm(null); });
  var recalcBtn = $("tp-ops-recalc");
  if (recalcBtn) recalcBtn.addEventListener("click", function () { openRecalc(null); });

  /* ---------- Formulario ---------- */
  function field(label, input, full) {
    return '<div class="tp-field"' + (full ? ' style="grid-column:1/-1;"' : "") + ">" +
      (label ? '<label class="tp-field__label">' + esc(label) + "</label>" : "") + input + "</div>";
  }

  function geofenceOptions(role, selectedId) {
    var cats = state.meta.categories || {};
    var preferred = role === "load" ? "carga" : "descarga";
    var list = (state.meta.geofences || []).slice().sort(function (a, b) {
      var pa = a.category === preferred ? 0 : 1, pb = b.category === preferred ? 0 : 1;
      return pa - pb || a.name.localeCompare(b.name);
    });
    return '<option value="">' + esc(t("ui.select_ellipsis")) + "</option>" + list.map(function (g) {
      var label = g.name + (g.category ? " · " + (cats[g.category] || g.category) : "");
      return '<option value="' + g.id + '"' + (selectedId === g.id ? " selected" : "") + ">" + esc(label) + "</option>";
    }).join("");
  }

  function pointRow(role, point) {
    return '<div class="tp-ops__point" data-x-point data-role="' + role + '">' +
      '<select class="tp-input" data-x-geofence aria-label="' + esc(t("ui.ops_geofence")) + '">' + geofenceOptions(role, point ? point.geofence_id : null) + "</select>" +
      '<span class="tp-ops__hint">' + esc(role === "load" ? t("ui.ops_state_at_load") : t("ui.ops_state_at_unload")) + "</span>" +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm tp-btn--icon" data-x-remove aria-label="' + esc(t("global.delete")) + '">×</button>' +
      "</div>";
  }

  function shiftRow(s) {
    s = s || { name: "", start_time: "07:00", end_time: "19:00" };
    return '<div class="tp-ops__shift" data-x-shift' + (s.id ? ' data-id="' + s.id + '"' : "") + ">" +
      '<input type="text" class="tp-input" data-x-sname value="' + esc(s.name) + '" placeholder="' + esc(t("ui.ops_shift_name")) + '" aria-label="' + esc(t("ui.ops_shift_name")) + '">' +
      '<input type="time" class="tp-input" data-x-sstart value="' + esc(s.start_time) + '" aria-label="' + esc(t("ui.ops_shift_start")) + '">' +
      '<input type="time" class="tp-input" data-x-send value="' + esc(s.end_time) + '" aria-label="' + esc(t("ui.ops_shift_end")) + '">' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm tp-btn--icon" data-x-remove aria-label="' + esc(t("global.delete")) + '">×</button>' +
      "</div>";
  }

  function openForm(item) {
    var m = state.meta;
    var groups = m.groups || [];
    var groupOpts = '<option value="">' + esc(t("ui.select_ellipsis")) + "</option>" + groups.map(function (g) {
      return '<option value="' + g.id + '" data-count="' + g.count + '"' + (item && item.device_group_id === g.id ? " selected" : "") + ">" + esc(g.name) + " (" + g.count + ")</option>";
    }).join("");

    var cats = m.categories || {};
    var auth = item ? item.authorized_categories : ["grifo", "taller", "descanso"];
    var catsHtml = Object.keys(cats).filter(function (k) { return k !== "carga" && k !== "descarga"; }).map(function (k) {
      return '<label><input type="checkbox" data-x-cat value="' + k + '"' + (auth.indexOf(k) >= 0 ? " checked" : "") + "> " + esc(cats[k]) + "</label>";
    }).join("");

    var loads = item ? item.points.filter(function (p) { return p.role === "load"; }) : [null];
    var unloads = item ? item.points.filter(function (p) { return p.role === "unload"; }) : [null];

    var body =
      '<div class="tp-scope" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
      field(t("ui.name_required_field"), '<input type="text" class="tp-input" name="name" data-x-name value="' + (item ? esc(item.name) : "") + '" style="width:100%;">') +
      field(t("ui.ops_group_required"), '<select class="tp-input" name="device_group_id" data-x-group style="width:100%;">' + groupOpts + "</select>" +
        '<span class="tp-ops__hint" data-x-group-hint></span>') +
      field(t("ui.ops_load_points"), '<div class="tp-ops__points" data-x-points="load">' + loads.map(function (p) { return pointRow("load", p); }).join("") + "</div>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-x-add-point="load" style="margin-top:6px;">' + esc(t("ui.ops_add_point")) + "</button>", true) +
      field(t("ui.ops_unload_points"), '<div class="tp-ops__points" data-x-points="unload">' + unloads.map(function (p) { return pointRow("unload", p); }).join("") + "</div>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-x-add-point="unload" style="margin-top:6px;">' + esc(t("ui.ops_add_point")) + "</button>", true) +
      field(t("ui.ops_shifts"), '<div class="tp-ops__shifts" data-x-shifts>' + (item ? item.shifts.map(shiftRow).join("") : "") + "</div>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-x-add-shift style="margin-top:6px;">' + esc(t("ui.ops_add_shift")) + "</button>" +
        '<p class="tp-ops__sub">' + esc(t("ui.ops_shift_crosses_midnight")) + "</p>", true) +
      '<details style="grid-column:1/-1;"><summary class="tp-ops__hint" style="cursor:pointer;">' + esc(t("ui.ops_advanced")) + "</summary>" +
        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:8px;">' +
        field(t("ui.ops_stop_threshold"), '<input type="number" min="10" class="tp-input" name="stop_threshold_seconds" data-x-stop value="' + (item ? item.stop_threshold_seconds : 60) + '" style="width:100%;">') +
        field(t("ui.ops_min_dwell"), '<input type="number" min="0" class="tp-input" name="min_dwell_seconds" data-x-dwell value="' + (item ? item.min_dwell_seconds : 30) + '" style="width:100%;">') +
        field(t("ui.ops_max_leg_hours"), '<input type="number" min="1" class="tp-input" name="max_leg_hours" data-x-maxleg value="' + (item ? item.max_leg_hours : 6) + '" style="width:100%;">') +
        "</div>" +
        field(t("ui.ops_authorized_categories"), '<div class="tp-ops__cats">' + catsHtml + "</div>", true) +
      "</details>" +
      field("", '<label class="tp-ops__check"><input type="checkbox" data-x-active' + (!item || item.active ? " checked" : "") + "> " + esc(t("ui.ops_active")) + "</label>", true) +
      '<div class="tp-ops__err" data-x-err></div></div>';

    var dlg = tpModal.open({
      title: item ? t("ui.ops_edit") : t("ui.ops_add"),
      size: "lg",
      body: body,
      buttons: [
        { label: t("global.cancel"), variant: "ghost", close: true },
        { label: t("global.save"), variant: "primary", close: false, onClick: function (d) { save(d, item); return false; } },
      ],
    });
    if (!dlg) return;

    function groupHint() {
      var sel = dlg.querySelector("[data-x-group]");
      var opt = sel.options[sel.selectedIndex];
      dlg.querySelector("[data-x-group-hint]").textContent = opt && opt.value ? t("ui.ops_group_hint", { count: opt.getAttribute("data-count") }) : "";
    }
    dlg.querySelector("[data-x-group]").addEventListener("change", groupHint);
    groupHint();

    dlg.addEventListener("click", function (e) {
      var add = e.target.closest("[data-x-add-point]");
      if (add) {
        var role = add.getAttribute("data-x-add-point");
        dlg.querySelector('[data-x-points="' + role + '"]').insertAdjacentHTML("beforeend", pointRow(role, null));
        return;
      }
      if (e.target.closest("[data-x-add-shift]")) {
        dlg.querySelector("[data-x-shifts]").insertAdjacentHTML("beforeend", shiftRow(null));
        return;
      }
      var rm = e.target.closest("[data-x-remove]");
      if (rm) { var row = rm.closest("[data-x-point],[data-x-shift]"); if (row) row.remove(); }
    });
  }

  function save(d, item) {
    var err = d.querySelector("[data-x-err]");
    function showErr(m) { err.textContent = m; err.classList.add("is-visible"); return false; }
    err.classList.remove("is-visible");

    var points = [];
    d.querySelectorAll("[data-x-point]").forEach(function (row) {
      var gid = row.querySelector("[data-x-geofence]").value;
      if (gid) points.push({ geofence_id: Number(gid), role: row.getAttribute("data-role") });
    });
    var shifts = [];
    d.querySelectorAll("[data-x-shift]").forEach(function (row) {
      var s = { name: row.querySelector("[data-x-sname]").value.trim(), start_time: row.querySelector("[data-x-sstart]").value, end_time: row.querySelector("[data-x-send]").value };
      if (row.getAttribute("data-id")) s.id = Number(row.getAttribute("data-id"));
      if (s.name || s.start_time) shifts.push(s);
    });
    var cats = [];
    d.querySelectorAll("[data-x-cat]:checked").forEach(function (c) { cats.push(c.value); });

    var payload = {
      name: d.querySelector("[data-x-name]").value.trim(),
      device_group_id: d.querySelector("[data-x-group]").value,
      points: points,
      shifts: shifts,
      authorized_categories: cats,
      stop_threshold_seconds: d.querySelector("[data-x-stop]").value,
      min_dwell_seconds: d.querySelector("[data-x-dwell]").value,
      max_leg_hours: d.querySelector("[data-x-maxleg]").value,
      active: d.querySelector("[data-x-active]").checked ? 1 : 0,
    };

    if (!payload.name) return showErr(t("ui.name_required"));
    if (!payload.device_group_id) return showErr(t("ui.ops_group_missing"));
    var roles = {};
    points.forEach(function (p) { roles[p.role] = true; });
    if (!roles.load || !roles.unload) return showErr(t("ui.ops_points_required"));

    var pointsChanged = item && JSON.stringify(item.points.map(function (p) { return p.geofence_id + ":" + p.role; }).sort()) !==
      JSON.stringify(points.map(function (p) { return p.geofence_id + ":" + p.role; }).sort());

    tpModal.setBusy(d, true);
    var url = item ? URLS.item.replace("{id}", item.id) : URLS.store;

    tpModal.fetchJSON(url, { method: item ? "PUT" : "POST", json: payload }).then(function (res) {
      tpModal.setBusy(d, false);
      if (res.status === 422) {
        var errors = (res.data && res.data.errors) || {};
        tpModal.showFieldErrors(d, errors);
        var first = Object.keys(errors)[0];
        return showErr(first ? [].concat(errors[first])[0] : t("ui.check_required_fields"));
      }
      if (!res.ok || !res.data || !res.data.ok) return showErr((res.data && res.data.message) || t("ui.save_failed"));
      tpModal.close(d);
      loadList();
      loadMeta();
      if (pointsChanged) {
        tpModal.confirm({ title: t("ui.ops_recalculate"), message: t("ui.ops_points_changed_recalc"), confirmLabel: t("ui.ops_recalculate") })
          .then(function (ok) { if (ok) openRecalc(res.data.item); });
      }
    }).catch(function () { tpModal.setBusy(d, false); showErr(t("ui.network_error")); });
  }

  function del(id) {
    tpModal.confirm({ title: t("ui.ops_delete"), message: t("ui.ops_confirm_delete"), confirmLabel: t("global.delete"), danger: true })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON(URLS.item.replace("{id}", id), { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) { loadList(); loadMeta(); }
          else tpModal.errorToast(res, t("ui.delete_failed"));
        });
      });
  }

  /* ---------- Recalcular ---------- */
  function openRecalc(item) {
    var ops = (state.meta.operations || []);
    var opOpts = ops.map(function (o) {
      return '<option value="' + o.id + '"' + (item && item.id === o.id ? " selected" : "") + ">" + esc(o.name) + "</option>";
    }).join("");

    var body = '<div class="tp-scope" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
      field(t("ui.ops_operation"), '<select class="tp-input" data-x-op style="width:100%;">' + opOpts + "</select>") +
      field(t("ui.ops_recalc_from"), '<input type="date" class="tp-input" data-x-from value="' + today() + '" style="width:100%;">') +
      field(t("ui.vehicles"), '<div data-x-devices></div>', true) +
      field(t("ui.ops_recalc_points"),
        '<label class="tp-ops__check"><input type="radio" name="x-points" value="0" checked> ' + esc(t("ui.ops_recalc_points_historic")) + "</label> " +
        '<label class="tp-ops__check"><input type="radio" name="x-points" value="1"> ' + esc(t("ui.ops_recalc_points_current")) + "</label>", true) +
      '<div class="tp-ops__hint" data-x-preview style="grid-column:1/-1;"></div>' +
      '<div class="tp-ops__err" data-x-err></div></div>';

    var dlg = tpModal.open({
      title: t("ui.ops_recalc_title"),
      size: "md",
      body: body,
      buttons: [
        { label: t("global.cancel"), variant: "ghost", close: true },
        { label: t("ui.ops_recalculate"), variant: "primary", close: false, onClick: function (d) { runRecalc(d); return false; } },
      ],
    });
    if (!dlg) return;

    if (window.tpChipsSelect) {
      dlg._devices = tpChipsSelect({ mount: dlg.querySelector("[data-x-devices]"), items: state.meta.devices || [], selected: [], placeholder: t("ui.select_vehicles_ellipsis"), host: dlg });
    }

    function preview() {
      var opId = dlg.querySelector("[data-x-op]").value, from = dlg.querySelector("[data-x-from]").value;
      if (!opId || !from) return;
      var devs = dlg._devices ? dlg._devices.getIds() : [];
      var qs = "?operation_id=" + opId + "&from=" + from + devs.map(function (i) { return "&devices[]=" + i; }).join("");
      tpModal.fetchJSON(URLS.recalcPreview + qs, { method: "GET" }).then(function (res) {
        var d = (res && res.data) || {};
        if (!d.ok) return;
        var msg = t("ui.ops_recalc_preview", { cycles: d.cycles, devices: d.devices });
        if (d.kept_days) msg += " " + t("ui.ops_recalc_kept_days", { days: d.kept_days });
        dlg.querySelector("[data-x-preview]").textContent = msg;
      });
    }
    dlg.querySelector("[data-x-op]").addEventListener("change", preview);
    dlg.querySelector("[data-x-from]").addEventListener("change", preview);
    dlg.addEventListener("click", function () { clearTimeout(dlg._pv); dlg._pv = setTimeout(preview, 300); });
    preview();
  }

  function runRecalc(d) {
    var err = d.querySelector("[data-x-err]");
    var payload = {
      operation_id: d.querySelector("[data-x-op]").value,
      from: d.querySelector("[data-x-from]").value,
      devices: d._devices ? d._devices.getIds() : [],
      use_current_points: d.querySelector('[name="x-points"]:checked').value,
    };
    if (!payload.operation_id || !payload.from) { err.textContent = t("ui.check_required_fields"); err.classList.add("is-visible"); return; }
    tpModal.setBusy(d, true);
    tpModal.fetchJSON(URLS.recalc, { method: "POST", json: payload }).then(function (res) {
      tpModal.setBusy(d, false);
      if (!res.ok || !res.data || !res.data.ok) { err.textContent = (res.data && res.data.message) || t("ui.save_failed"); err.classList.add("is-visible"); return; }
      tpModal.close(d);
      tpModal.toast(t("ui.ops_recalc_queued", { count: res.data.queued }), "success");
    });
  }

  /* ---------- Arranque ---------- */
  $("tp-ops-live-date").value = today();
  $("tp-ops-from").value = today();
  $("tp-ops-to").value = today();

  loadMeta().then(function () {
    loadList();
    startLive();
  });
})();
