/* =====================================================
 * TalentusPro · tp-security — Seguridad · Control de flota
 * -----------------------------------------------------
 * Reproduce el tablero Excel del cliente sobre las tablas ya calculadas
 * (security_daily_stats) y el estado en vivo de cada unidad:
 *   · Filtros: flota (tipo), turno, rango, unidad, vigencia y limite manual.
 *   · Ocho tarjetas, estado actual por unidad, resumen diario, graficos
 *     (ECharts) y detalle por unidad / por unidad y dia.
 *   · Ajustes (turno dia, parada, corte) y recalculo de un rango corto.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-security");
  if (!root || !window.tpModal) return;

  var URLS = {
    meta: root.getAttribute("data-meta-url"),
    data: root.getAttribute("data-data-url"),
    settings: root.getAttribute("data-settings-url"),
    recalc: root.getAttribute("data-recalc-url"),
    export: root.getAttribute("data-export-url"),
  };

  var charts = {}; // id -> instancia de echarts
  var meta = null;
  var lastData = null; // ultima respuesta de /data, para repintar los graficos al cambiar de tema
  var seq = 0; // peticion de /data en vuelo: una respuesta vieja y lenta no pisa a la nueva

  function $(id) { return document.getElementById(id); }
  function esc(s) { return tpModal.escapeHtml(s == null ? "" : String(s)); }
  function t(k, r) { return tpT(k, r); }

  /* "Hoy" en hora local: toISOString() va en UTC y en Peru despues de las
     19:00 daria el dia siguiente. */
  function ymd(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + "-" + (m < 10 ? "0" : "") + m + "-" + (day < 10 ? "0" : "") + day;
  }

  /* tp-datepicker convierte los input[type=date] en texto dd/mm/aaaa y guarda
     el ISO aparte: leer .value a pelo mandaria "08/07/2026" al servidor. */
  function dval(el) { return window.tpDate ? tpDate.value(el) : ((el && el.value) || ""); }
  function dateSet(el, iso) {
    if (!el) return;
    if (el._tpdp) el._tpdp.set(iso); else el.value = iso;
  }

  /* "2026-10-14" -> "14/10/2026" para tablas y ejes. */
  function dmy(iso) {
    if (!iso) return "—";
    var p = String(iso).slice(0, 10).split("-");
    return p.length === 3 ? p[2] + "/" + p[1] + "/" + p[0] : iso;
  }

  /* Devuelve texto YA escapado (el fallback pasa por esc); no volver a escapar. */
  function num(v, dec) {
    if (v == null || v === "") return "—";
    var n = Number(v);
    if (isNaN(n)) return esc(v);
    return new Intl.NumberFormat(undefined, { minimumFractionDigits: dec || 0, maximumFractionDigits: dec || 0 }).format(n);
  }

  function oneDec(v) { return v == null ? "—" : num(v, 1); }

  function cssVar(name, fb) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return (v && v.trim()) || fb;
  }
  function textColor() { return cssVar("--fg-secondary", "#888"); }
  function gridColor() { return cssVar("--border-subtle", "#e5e7eb"); }

  /* Colores por estado: los mismos tokens que el mapa y la lista de flota. */
  function stateColor(state) {
    switch (state) {
      case "moving": return cssVar("--status-moving", "#10b981");
      case "standby": return cssVar("--status-idle", "#f59e0b");
      case "stopped":
      case "inactive": return cssVar("--status-stopped", "#6b7280");
      case "expired":
      case "no_data": return cssVar("--status-offline", "#b45309");
      default: return cssVar("--fg-tertiary", "#64748b");
    }
  }
  function liveStateLabel(state) {
    return { moving: t("ui.security_state_moving"), standby: t("ui.security_state_standby"), stopped: t("ui.security_state_stopped"), expired: t("ui.security_state_expired") }[state] || state || "—";
  }
  function dailyStateLabel(state) {
    return { moving: t("ui.security_daily_moving"), standby: t("ui.security_daily_standby"), inactive: t("ui.security_daily_inactive"), no_data: t("ui.security_daily_no_data") }[state] || state || "—";
  }
  function pill(state, label) {
    return '<span class="tp-sec__pill tp-sec__pill--' + esc(state || "none") + '">' + esc(label) + "</span>";
  }

  function chart(id) {
    var el = $(id);
    if (!el || typeof echarts === "undefined") return null;
    if (!charts[id]) charts[id] = echarts.init(el, null, { renderer: "svg" });
    return charts[id];
  }
  function axisBase() {
    return { axisLine: { lineStyle: { color: gridColor() } }, axisLabel: { color: textColor() }, splitLine: { lineStyle: { color: gridColor() } } };
  }

  /* ---------- Filtros ---------- */
  function filters() {
    return {
      from: dval($("tp-sec-from")),
      to: dval($("tp-sec-to")),
      shift: $("tp-sec-shift").value,
      type_id: $("tp-sec-type").value,
      device_id: $("tp-sec-device").value,
    };
  }
  function qs(o) {
    return Object.keys(o).filter(function (k) { return o[k] !== "" && o[k] != null; })
      .map(function (k) { return k + "=" + encodeURIComponent(o[k]); }).join("&");
  }
  function fill(sel, pairs) {
    pairs.forEach(function (p) {
      var o = document.createElement("option");
      o.value = p[0];
      o.textContent = p[1];
      sel.appendChild(o);
    });
    if (window.tpSelect && tpSelect.refresh) tpSelect.refresh(sel);
  }

  /* Al elegir una flota, el selector de unidad solo muestra las de ese tipo. */
  function filterDevicesByType() {
    var typeId = $("tp-sec-type").value;
    var sel = $("tp-sec-device");
    var cur = sel.value;
    sel.innerHTML = '<option value="">' + esc(t("front.all")) + "</option>";
    fill(sel, (meta.devices || []).filter(function (d) {
      return !typeId || String(d.vehicle_type_id) === String(typeId);
    }).map(function (d) { return [d.id, d.name]; }));
    sel.value = cur;
    if (sel.value !== cur) sel.value = "";
    if (window.tpSelect && tpSelect.refresh) tpSelect.refresh(sel);
  }

  /* ---------- Carga ---------- */
  function loadMeta() {
    return tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (r) {
      if (!r.ok || !r.data || !r.data.ok) { tpModal.errorToast(r, t("ui.load_failed")); return false; }
      meta = r.data;
      fill($("tp-sec-type"), (meta.types || []).map(function (x) { return [x.id, x.name]; }));
      fill($("tp-sec-device"), (meta.devices || []).map(function (d) { return [d.id, d.name]; }));
      $("tp-sec-validity").value = meta.settings.validity_minutes;
      $("tp-sec-speed-limit").value = meta.settings.speed_limit || "";
      renderLastCalculated();
      if (!meta.can_edit) {
        ["tp-sec-validity", "tp-sec-speed-limit", "tp-sec-settings", "tp-sec-recalc"].forEach(function (id) { $(id).disabled = true; });
      }
      var to = new Date(), from = new Date();
      from.setDate(to.getDate() - 15);
      dateSet($("tp-sec-from"), ymd(from));
      dateSet($("tp-sec-to"), ymd(to));
      return true;
    }).catch(function () { tpModal.toast(t("ui.load_failed"), "error"); return false; });
  }
  function renderLastCalculated() {
    // Solo si llega como cadena: optional() en el servidor lo serializa como {}.
    var lc = meta && typeof meta.last_calculated === "string" ? meta.last_calculated : "";
    $("tp-sec-last-calc").textContent = lc ? t("ui.security_last_calculated") + " " + lc : "";
  }

  function load() {
    if (!meta) return Promise.resolve();
    if (!dval($("tp-sec-from")) || !dval($("tp-sec-to"))) { tpModal.toast(t("ui.pick_date_range"), "error"); return Promise.resolve(); }
    var mine = ++seq;
    root.classList.add("is-loading");
    function done() { if (mine === seq) root.classList.remove("is-loading"); }
    return tpModal.fetchJSON(URLS.data + "?" + qs(filters()), { method: "GET" }).then(function (r) {
      done();
      if (mine !== seq) return; // ya hay una busqueda mas reciente
      if (!r.ok || !r.data || !r.data.ok) return tpModal.errorToast(r, t("ui.load_failed"));
      var d = lastData = r.data;
      renderCards(d.cards || {}, d.live || []);
      renderLive(d.live || []);
      renderDaily(d.daily || []);
      renderUnits(d.per_unit || []);
      renderUnitDaily(d.per_unit_daily || []);
      drawCharts(d);
    }).catch(function () {
      done();
      if (mine === seq) tpModal.toast(t("ui.load_failed"), "error");
    });
  }
  function drawCharts(d) {
    chartLive(d.live || []);
    chartStates(d.per_unit_daily || []);
    chartAvg(d.per_unit || []);
    chartOverspeed(d.daily || []);
  }

  /* ---------- Tarjetas ---------- */
  function renderCards(cards, live) {
    var count = function (state) { return live.filter(function (l) { return l.state === state; }).length; };
    var valid = live.filter(function (l) { return l.state !== "expired"; }).length;

    // Ultima lectura de la flota: la mas reciente por last_time (ISO), mostrada
    // con el formato del usuario que ya trae el servidor.
    var last = null;
    live.forEach(function (l) {
      if (!l.last_time) return;
      if (!last || String(l.last_time) > String(last.last_time)) last = l;
    });

    var items = [
      { key: "overspeed", label: t("ui.security_overspeed_events"), value: num(cards.overspeed), tone: "danger" },
      { key: "seatbelt", label: t("ui.security_seatbelt_alerts"), value: num(cards.seatbelt), tone: "warn" },
      { key: "zone_exits", label: t("ui.security_zone_exits"), value: num(cards.zone_exits), tone: "warn" },
      { key: "valid", label: t("ui.security_units_valid"), value: num(valid), tone: "ok" },
      { key: "moving", label: t("ui.security_units_moving"), value: num(count("moving")), tone: "moving" },
      { key: "standby", label: t("ui.security_units_standby"), value: num(count("standby")), tone: "standby" },
      { key: "expired", label: t("ui.security_units_expired"), value: num(count("expired")), tone: "expired" },
      { key: "last", label: t("ui.security_last_reading"), value: last && last.last_time_formatted ? esc(last.last_time_formatted) : "—", tone: "info", small: true },
    ];

    $("tp-sec-cards").innerHTML = items.map(function (c) {
      return '<div class="tp-sec__card tp-sec__card--' + c.tone + '" data-card="' + c.key + '">' +
        '<span class="tp-sec__card-label">' + esc(c.label) + "</span>" +
        '<span class="tp-sec__card-value' + (c.small ? " tp-sec__card-value--sm" : "") + '">' + c.value + "</span>" +
        "</div>";
    }).join("");
  }

  /* ---------- Tablas ---------- */
  function table(id, heads, rows, colspanMsg) {
    var html = "<thead><tr>" + heads.map(function (h) {
      return "<th" + (h.num ? ' class="tp-sec__num"' : "") + ">" + esc(h.label) + "</th>";
    }).join("") + "</tr></thead><tbody>";
    if (!rows.length) {
      html += '<tr><td colspan="' + heads.length + '" class="tp-sec__empty">' + esc(colspanMsg || t("ui.security_no_rows")) + "</td></tr>";
    } else {
      html += rows.join("");
    }
    $(id).innerHTML = html + "</tbody>";
  }
  function td(v, numeric) { return "<td" + (numeric ? ' class="tp-sec__num"' : "") + ">" + v + "</td>"; }
  function typeName(v) { return v == null || v === "" ? "—" : esc(v); }
  function seatbelt(v) { return v == null ? '<span class="tp-text-muted">' + esc(t("ui.security_no_sensor")) + "</span>" : num(v); }

  function renderLive(live) {
    var heads = [
      { label: t("ui.vehicle") }, { label: t("front.type") }, { label: t("ui.security_last_time") },
      { label: t("ui.security_gps_speed"), num: true }, { label: t("front.engine") },
      { label: t("ui.security_age_minutes"), num: true }, { label: t("ui.security_state") },
    ];
    var rows = live.map(function (l) {
      var engine = l.engine == null ? "—" : (l.engine ? t("ui.security_on") : t("ui.security_off"));
      return '<tr class="tp-sec__row tp-sec__row--' + esc(l.state) + '">' +
        td(esc(l.unit)) + td(typeName(l.type)) + td(esc(l.last_time_formatted || "—")) +
        td(num(l.speed), true) + td(esc(engine)) + td(num(l.age_minutes), true) +
        td(pill(l.state, liveStateLabel(l.state))) + "</tr>";
    });
    table("tp-sec-live", heads, rows);
  }

  function renderDaily(daily) {
    var heads = [
      { label: t("front.date") }, { label: t("ui.security_km"), num: true }, { label: t("ui.security_moving_h"), num: true },
      { label: t("ui.security_avg_kmh"), num: true }, { label: t("ui.security_max_kmh"), num: true },
      { label: t("ui.security_daily_moving"), num: true }, { label: t("ui.security_daily_standby"), num: true },
      { label: t("ui.security_overspeed_short"), num: true },
    ];
    var rows = daily.map(function (d) {
      return "<tr>" + td(esc(dmy(d.date))) + td(num(d.km, 1), true) + td(num(d.moving_h, 2), true) +
        td(num(d.avg_kmh, 1), true) + td(num(d.max_kmh), true) + td(num(d.moving), true) +
        td(num(d.standby), true) + td(num(d.overspeed), true) + "</tr>";
    });
    table("tp-sec-daily", heads, rows);
  }

  function renderUnits(units) {
    var heads = [
      { label: t("ui.vehicle") }, { label: t("front.type") }, { label: t("ui.security_km"), num: true },
      { label: t("ui.security_engine_h"), num: true }, { label: t("ui.security_moving_h"), num: true },
      { label: t("ui.security_stops"), num: true }, { label: t("ui.security_records"), num: true },
      { label: t("ui.security_overspeed_short"), num: true }, { label: t("ui.security_avg_kmh"), num: true },
    ];
    var rows = units.map(function (u) {
      return "<tr>" + td(esc(u.unit)) + td(typeName(u.type)) + td(num(u.km, 1), true) +
        td(num(u.engine_h, 2), true) + td(num(u.moving_h, 2), true) + td(num(u.stops), true) +
        td(num(u.records), true) + td(num(u.overspeed), true) + td(num(u.avg_kmh, 1), true) + "</tr>";
    });
    table("tp-sec-units", heads, rows);
  }

  function renderUnitDaily(rowsIn) {
    var heads = [
      { label: t("front.date") }, { label: t("ui.vehicle") }, { label: t("front.type") },
      { label: t("ui.security_km"), num: true }, { label: t("ui.security_moving_h"), num: true },
      { label: t("ui.security_engine_h"), num: true }, { label: t("ui.security_avg_kmh"), num: true },
      { label: t("ui.security_max_kmh"), num: true }, { label: t("ui.security_state") },
      { label: t("ui.security_records"), num: true }, { label: t("ui.security_stops"), num: true },
      { label: t("ui.security_overspeed_short"), num: true }, { label: t("ui.security_seatbelt_short"), num: true },
    ];
    var rows = rowsIn.map(function (r) {
      return "<tr>" + td(esc(dmy(r.date))) + td(esc(r.unit)) + td(typeName(r.type)) +
        td(num(r.km, 1), true) + td(num(r.moving_h, 2), true) + td(num(r.engine_h, 2), true) +
        td(num(r.avg_kmh, 1), true) + td(num(r.max_kmh), true) + td(pill(r.state, dailyStateLabel(r.state))) +
        td(num(r.records), true) + td(num(r.stops), true) + td(num(r.overspeed), true) +
        td(seatbelt(r.seatbelt), true) + "</tr>";
    });
    table("tp-sec-unit-daily", heads, rows);
  }

  /* ---------- Graficos ---------- */
  function chartLive(live) {
    var c = chart("tp-sec-chart-live");
    if (!c) return;
    var states = ["moving", "standby", "stopped", "expired"];
    var data = states.map(function (s) {
      return { value: live.filter(function (l) { return l.state === s; }).length, itemStyle: { color: stateColor(s) } };
    });
    c.setOption({
      tooltip: { trigger: "axis", axisPointer: { type: "shadow" } },
      grid: { left: 40, right: 16, top: 16, bottom: 40, containLabel: false },
      xAxis: Object.assign(axisBase(), { type: "category", data: states.map(liveStateLabel), axisLabel: { color: textColor(), interval: 0, fontSize: 11 } }),
      yAxis: Object.assign(axisBase(), { type: "value", minInterval: 1 }),
      series: [{ type: "bar", data: data, barMaxWidth: 48, label: { show: true, position: "top", color: textColor() } }],
    }, true);
  }

  function chartStates(perUnitDaily) {
    var c = chart("tp-sec-chart-states");
    if (!c) return;
    var byDate = {};
    perUnitDaily.forEach(function (r) {
      var k = String(r.date).slice(0, 10);
      if (!byDate[k]) byDate[k] = { moving: 0, standby: 0, inactive: 0, no_data: 0 };
      if (byDate[k][r.state] != null) byDate[k][r.state]++;
    });
    var dates = Object.keys(byDate).sort();
    var series = [
      ["moving", t("ui.security_daily_moving")],
      ["standby", t("ui.security_daily_standby")],
      ["inactive", t("ui.security_daily_inactive")],
    ].map(function (s) {
      return { name: s[1], type: "line", smooth: true, symbolSize: 6, data: dates.map(function (d) { return byDate[d][s[0]]; }), itemStyle: { color: stateColor(s[0]) }, lineStyle: { color: stateColor(s[0]) } };
    });
    c.setOption({
      tooltip: { trigger: "axis" },
      legend: { bottom: 0, textStyle: { color: textColor() } },
      grid: { left: 40, right: 16, top: 16, bottom: 56 },
      xAxis: Object.assign(axisBase(), { type: "category", data: dates.map(dmy), boundaryGap: false }),
      yAxis: Object.assign(axisBase(), { type: "value", minInterval: 1 }),
      series: series,
    }, true);
  }

  function chartAvg(units) {
    var c = chart("tp-sec-chart-avg");
    if (!c) return;
    var rows = units.slice().sort(function (a, b) { return (b.avg_kmh || 0) - (a.avg_kmh || 0); });
    c.setOption({
      tooltip: { trigger: "axis", axisPointer: { type: "shadow" }, valueFormatter: oneDec },
      grid: { left: 40, right: 16, top: 16, bottom: 60 },
      xAxis: Object.assign(axisBase(), { type: "category", data: rows.map(function (u) { return u.unit; }), axisLabel: { color: textColor(), rotate: rows.length > 6 ? 35 : 0, fontSize: 11 } }),
      yAxis: Object.assign(axisBase(), { type: "value", name: "km/h", nameTextStyle: { color: textColor() } }),
      series: [{ type: "bar", data: rows.map(function (u) { return u.avg_kmh == null ? 0 : Math.round(Number(u.avg_kmh) * 10) / 10; }), barMaxWidth: 40, itemStyle: { color: cssVar("--brand-accent", "#1b99bd") }, label: { show: true, position: "top", color: textColor(), fontSize: 10, formatter: function (p) { return oneDec(p.value); } } }],
    }, true);
  }

  function chartOverspeed(daily) {
    var c = chart("tp-sec-chart-overspeed");
    if (!c) return;
    var color = cssVar("--status-alert", "#ef4444");
    c.setOption({
      tooltip: { trigger: "axis" },
      grid: { left: 40, right: 16, top: 16, bottom: 40 },
      xAxis: Object.assign(axisBase(), { type: "category", data: daily.map(function (d) { return dmy(d.date); }), boundaryGap: false }),
      yAxis: Object.assign(axisBase(), { type: "value", minInterval: 1 }),
      series: [{ name: t("ui.security_overspeed_events"), type: "line", smooth: true, symbolSize: 6, data: daily.map(function (d) { return d.overspeed; }), itemStyle: { color: color }, lineStyle: { color: color }, areaStyle: { color: color, opacity: 0.12 } }],
    }, true);
  }

  function resizeCharts() {
    Object.keys(charts).forEach(function (k) { if (charts[k]) charts[k].resize(); });
  }

  /* ---------- Ajustes en linea (vigencia, limite manual) ---------- */
  function saveSettings() {
    if (!meta || !meta.can_edit) return;
    var before = String(meta.settings.speed_limit || "");
    var body = Object.assign({}, meta.settings, {
      validity_minutes: $("tp-sec-validity").value,
      speed_limit: $("tp-sec-speed-limit").value || null,
    });
    return postSettings(body).then(function (r) {
      if (!r.ok || !r.data || !r.data.ok) return tpModal.errorToast(r, t("ui.save_failed"));
      meta.settings = body;
      tpModal.toast(t("ui.changes_saved"), "success");
      // El limite manual cambia los excesos ya calculados: hay que recalcular.
      if (String(body.speed_limit || "") !== before) tpModal.toast(t("ui.security_recalc_needed"), "warning");
      // La vigencia afecta al estado en vivo: se refresca sin mas.
      load();
    });
  }
  function postSettings(body) {
    return tpModal.fetchJSON(URLS.settings, { method: "POST", json: body });
  }

  /* ---------- Modal de ajustes ---------- */
  function field(label, input, full) {
    return '<div class="tp-field"' + (full ? ' style="grid-column:1/-1;"' : "") + '><label class="tp-field__label">' + esc(label) + "</label>" + input + "</div>";
  }
  function openSettings() {
    if (!meta) return;
    var s = meta.settings;
    var body = '<div class="tp-scope tp-sec__form">' +
      field(t("ui.security_day_start"), '<input type="time" class="tp-input" name="day_start" value="' + esc(s.day_start || "") + '">') +
      field(t("ui.security_day_end"), '<input type="time" class="tp-input" name="day_end" value="' + esc(s.day_end || "") + '">') +
      field(t("ui.security_stop_minutes"), '<input type="number" class="tp-input" name="stop_minutes" min="1" max="120" value="' + esc(s.stop_minutes) + '">') +
      field(t("ui.security_gap_minutes"), '<input type="number" class="tp-input" name="gap_minutes" min="1" max="240" value="' + esc(s.gap_minutes) + '">') +
      "</div>";

    var dlg = tpModal.open({
      title: t("ui.security_settings"),
      size: "md",
      body: body,
      buttons: [
        { label: t("global.cancel"), variant: "ghost", close: true },
        { label: t("global.save"), variant: "primary", close: false, onClick: function (d) { submitSettings(d); return false; } },
      ],
    });
    return dlg;
  }
  function submitSettings(d) {
    var val = function (n) { var el = d.querySelector('[name="' + n + '"]'); return el ? el.value : ""; };
    var body = Object.assign({}, meta.settings, {
      day_start: val("day_start").slice(0, 5),
      day_end: val("day_end").slice(0, 5),
      stop_minutes: val("stop_minutes"),
      gap_minutes: val("gap_minutes"),
    });
    // Los cuatro campos del modal entran en el calculo diario: si cambia
    // alguno hay que recalcular, igual que con el limite manual.
    var changed = ["day_start", "day_end", "stop_minutes", "gap_minutes"].some(function (k) {
      return String(body[k]) !== String(meta.settings[k]);
    });
    tpModal.setBusy(d, true);
    postSettings(body).then(function (r) {
      tpModal.setBusy(d, false);
      if (r.status === 422) return tpModal.showFieldErrors(d, (r.data && r.data.errors) || {});
      if (!r.ok || !r.data || !r.data.ok) return tpModal.errorToast(r, t("ui.save_failed"));
      meta.settings = body;
      tpModal.close(d);
      tpModal.toast(t("ui.changes_saved"), "success");
      if (changed) tpModal.toast(t("ui.security_recalc_needed"), "warning");
      load();
    }).catch(function () { tpModal.setBusy(d, false); tpModal.toast(t("ui.save_failed"), "error"); });
  }

  /* ---------- Modal de recalculo ---------- */
  function openRecalc() {
    if (!meta) return;
    var f = filters();
    var body = '<div class="tp-scope tp-sec__form">' +
      field(t("global.from"), '<input type="date" class="tp-input" name="from" value="' + esc(f.from) + '">') +
      field(t("global.to"), '<input type="date" class="tp-input" name="to" value="' + esc(f.to) + '">') +
      '<p class="tp-sec__hint" style="grid-column:1/-1;">' + esc(t("ui.security_range_too_long")) + "</p>" +
      "</div>";

    var dlg = tpModal.open({
      title: t("ui.security_recalculate"),
      size: "sm",
      body: body,
      buttons: [
        { label: t("global.cancel"), variant: "ghost", close: true },
        { label: t("ui.security_recalculate"), variant: "primary", close: false, onClick: function (d) { submitRecalc(d); return false; } },
      ],
    });
    return dlg;
  }
  function submitRecalc(d) {
    var payload = { from: dval(d.querySelector('[name="from"]')), to: dval(d.querySelector('[name="to"]')) };
    tpModal.setBusy(d, true);
    tpModal.fetchJSON(URLS.recalc, { method: "POST", json: payload }).then(function (r) {
      tpModal.setBusy(d, false);
      if (r.status === 422) return tpModal.showFieldErrors(d, (r.data && r.data.errors) || {});
      if (!r.ok || !r.data || !r.data.ok) return tpModal.errorToast(r, t("ui.save_failed"));
      tpModal.close(d);
      tpModal.toast(t("ui.security_recalculated", { rows: r.data.rows }), "success");
      loadMetaLight().then(load);
    });
  }
  /* Tras recalcular solo cambia "datos calculados hasta". */
  function loadMetaLight() {
    return tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (r) {
      if (r.ok && r.data && r.data.ok) { meta.last_calculated = r.data.last_calculated; renderLastCalculated(); }
    });
  }

  /* ---------- Eventos ---------- */
  function wire() {
    $("tp-sec-run").addEventListener("click", load);
    $("tp-sec-type").addEventListener("change", filterDevicesByType);
    $("tp-sec-export").addEventListener("click", function () {
      window.location.href = URLS.export + "?" + qs(filters());
    });
    $("tp-sec-validity").addEventListener("change", saveSettings);
    $("tp-sec-speed-limit").addEventListener("change", saveSettings);
    $("tp-sec-settings").addEventListener("click", openSettings);
    $("tp-sec-recalc").addEventListener("click", openRecalc);
    window.addEventListener("resize", resizeCharts);
    // Cambio de tema claro/oscuro: los colores se leen de las variables CSS,
    // asi que basta con repintar los graficos con los datos ya cargados.
    new MutationObserver(function () { if (lastData) drawCharts(lastData); })
      .observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
  }

  /* ---------- Arranque ---------- */
  wire();
  loadMeta().then(function (ok) { if (ok) return load(); });
})();
