/* =====================================================
 * TalentusPro · tp-operations-shift — Pestaña «Turno» de Operaciones
 * -----------------------------------------------------
 * Reporte de turno en UNA sola vista (fase C del plan 2026-10-21):
 *   1. tarjetas (rodando, velocidad vacío/cargado, distancia)
 *   2. cinco gráficas por hora (canvas propio, módulo lineChart)
 *   3. estados por equipo (barra apilada con divs, 24 h)
 *   4. tabla «Horas y horómetro»
 *   5. tablas «Kilometraje» y «Abastecimientos»
 *
 * Consulta bajo demanda: GET operations/shift-report?operation&date&shift.
 * Nada se calcula aquí; si el día no tiene resúmenes, el botón «Calcular
 * ahora» llama a POST operations/shift-report/build y recarga.
 *
 * Lo expone tp-operations.js: showTab("shift") → tpOpsShift.activate(meta).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-ops-page");
  var panel = root && root.querySelector('[data-ops-panel="shift"]');
  if (!root || !panel || !window.tpModal) return;

  var URLS = {
    report: root.getAttribute("data-shift-report-url"),
    build: root.getAttribute("data-shift-build-url"),
    history: root.getAttribute("data-history-url"),
  };
  var canEdit = root.getAttribute("data-can-edit") === "1";

  // Colores del tablero del cliente (los de estado se repiten en el backend,
  // que manda la leyenda; aquí solo los de las series).
  var COLORS = {
    rolling: "#16a34a",
    empty: "#1e3a8a",
    loaded: "#f97316",
    fleetMax: "#dc2626",
    fleetAvg: "#2563eb",
    fleetMin: "#16a34a",
  };

  var state = {
    inited: false,
    loaded: false,
    loading: false,
    meta: null,
    data: null,
    charts: {},
    hover: -1,
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return tpModal.escapeHtml(s == null ? "" : String(s)); }
  function t(k, r) { return tpT(k, r); }

  function ymd(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + "-" + (m < 10 ? "0" : "") + m + "-" + (day < 10 ? "0" : "") + day;
  }
  function today() { return ymd(new Date()); }
  function dval(el) { return window.tpDate ? tpDate.value(el) : ((el && el.value) || ""); }
  function dateGet() { return dval($("tp-ops-shift-date")) || today(); }
  function dateSet(iso) {
    var el = $("tp-ops-shift-date");
    if (!el) return;
    if (el._tpdp) el._tpdp.set(iso); else el.value = iso;
  }
  function shiftDate(days) {
    var p = dateGet().split("-");
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    d.setDate(d.getDate() + days);
    dateSet(ymd(d));
  }

  function fillSelect(sel, items, selected) {
    if (!sel) return;
    sel.innerHTML = items.map(function (i) {
      return '<option value="' + esc(i.id) + '"' + (String(selected) === String(i.id) ? " selected" : "") + ">" + esc(i.name) + "</option>";
    }).join("");
    if (window.tpSelect && tpSelect.refresh) tpSelect.refresh(sel);
  }

  function num(v, dec) {
    if (v == null || isNaN(+v)) return "—";
    return (+v).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }
  function hoursOf(seconds) {
    return seconds == null ? "—" : num(seconds / 3600, 1) + " " + t("ui.ops_shift_hours_short");
  }
  function hms(seconds) {
    if (seconds == null) return "—";
    var s = Math.max(0, Math.round(seconds));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    return h + ":" + (m < 10 ? "0" : "") + m;
  }
  function dash(v) { return v == null || v === "" ? "—" : esc(v); }

  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return (v || "").trim() || fallback;
  }

  /* =====================================================
   * lineChart(canvas, opts) — gráfica de líneas reutilizable
   * -----------------------------------------------------
   * opts.labels   : etiquetas del eje X (24 horas)
   * opts.series   : [{ name, color, values[], meta[] (opcional), link (bool) }]
   * opts.unit     : sufijo del valor ("km/h", "unid.")
   * opts.decimals : decimales de la etiqueta de valor
   * opts.onHover  : function(index|-1)  → selector de hora compartido
   * opts.onClick  : function(seriesIdx, index)
   * Devuelve { draw(), setHover(i), update(opts) }.
   * ===================================================== */
  function lineChart(canvas, opts) {
    var wrap = canvas.parentElement;
    var tip = wrap.querySelector(".tp-ops-shift__tip");
    var hover = -1;
    var geom = null;

    function fmt(v) { return num(v, opts.decimals || 0); }

    function draw() {
      var W = wrap.clientWidth, H = wrap.clientHeight;
      if (!W || !H) return;
      var dpr = window.devicePixelRatio || 1;
      canvas.width = W * dpr; canvas.height = H * dpr;
      canvas.style.width = W + "px"; canvas.style.height = H + "px";
      var ctx = canvas.getContext("2d");
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);

      var labels = opts.labels || [], series = opts.series || [], n = labels.length;
      if (!n) return;

      var padL = 40, padR = 14, padT = 22, padB = 24;
      var gw = W - padL - padR, gh = H - padT - padB;

      var vmax = 0;
      series.forEach(function (s) { s.values.forEach(function (v) { if (v != null && v > vmax) vmax = v; }); });
      vmax = niceMax(vmax);

      var gridC = cssVar("--border-default", "#e5e7eb");
      var textC = cssVar("--fg-tertiary", "#94a3b8");
      var labelC = cssVar("--fg-secondary", "#475569");

      function xAt(i) { return padL + (n === 1 ? gw / 2 : gw * i / (n - 1)); }
      function yAt(v) { return padT + gh * (1 - Math.min(v, vmax) / vmax); }
      geom = { padL: padL, gw: gw, n: n, xAt: xAt, yAt: yAt, W: W, H: H };

      ctx.font = "10px Inter, system-ui, sans-serif";
      ctx.lineWidth = 1;

      // Rejilla horizontal + eje Y
      ctx.textAlign = "right"; ctx.textBaseline = "middle";
      for (var g = 0; g <= 4; g++) {
        var yy = padT + gh * g / 4;
        ctx.strokeStyle = gridC; ctx.globalAlpha = 0.6;
        ctx.beginPath(); ctx.moveTo(padL, yy); ctx.lineTo(W - padR, yy); ctx.stroke();
        ctx.globalAlpha = 1; ctx.fillStyle = textC;
        ctx.fillText(fmt(vmax * (1 - g / 4)), padL - 6, yy);
      }

      // Eje X: las 24 etiquetas (si no caben, una de cada dos)
      var every = gw / n < 22 ? 2 : 1;
      ctx.textAlign = "center"; ctx.textBaseline = "alphabetic";
      for (var i = 0; i < n; i++) {
        var x = xAt(i);
        ctx.strokeStyle = gridC; ctx.globalAlpha = 0.3;
        ctx.beginPath(); ctx.moveTo(x, padT); ctx.lineTo(x, padT + gh); ctx.stroke();
        ctx.globalAlpha = 1;
        if (i % every === 0) { ctx.fillStyle = textC; ctx.fillText(labels[i], x, H - 8); }
      }

      // Hora resaltada (selector compartido)
      if (hover >= 0 && hover < n) {
        var hx = xAt(hover);
        ctx.strokeStyle = labelC; ctx.globalAlpha = 0.7; ctx.setLineDash([4, 3]);
        ctx.beginPath(); ctx.moveTo(hx, padT - 4); ctx.lineTo(hx, padT + gh); ctx.stroke();
        ctx.setLineDash([]); ctx.globalAlpha = 1;
      }

      // Series: línea + puntos + etiqueta de valor sobre cada punto
      series.forEach(function (s, si) {
        var vals = s.values;
        ctx.strokeStyle = s.color; ctx.lineWidth = 2; ctx.lineJoin = "round";
        ctx.beginPath();
        var open = false;
        for (var k = 0; k < n; k++) {
          if (vals[k] == null) { open = false; continue; }
          var px = xAt(k), py = yAt(vals[k]);
          if (!open) { ctx.moveTo(px, py); open = true; } else ctx.lineTo(px, py);
        }
        ctx.stroke();

        ctx.font = (series.length > 1 ? "9px" : "10px") + " Inter, system-ui, sans-serif";
        ctx.textAlign = "center"; ctx.textBaseline = "alphabetic";
        for (var m = 0; m < n; m++) {
          if (vals[m] == null) continue;
          var cx = xAt(m), cy = yAt(vals[m]);
          ctx.fillStyle = s.color;
          ctx.beginPath(); ctx.arc(cx, cy, hover === m ? 4.5 : 3, 0, Math.PI * 2); ctx.fill();
          // Etiqueta: en gráficas de varias series se alterna arriba/abajo
          // para que las de valores cercanos no se pisen.
          var below = series.length > 1 && si % 2 === 1;
          ctx.fillStyle = s.color;
          ctx.fillText(fmt(vals[m]), cx, below ? cy + 13 : cy - 7);
        }
        ctx.lineWidth = 1;
      });
    }

    function niceMax(v) {
      if (!v || v <= 0) return opts.decimals ? 10 : 4;
      // Valores enteros (unidades): tope multiplo de 4 para que las cuatro
      // divisiones del eje Y caigan en enteros.
      if (!opts.decimals) return Math.max(4, Math.ceil(v * 1.15 / 4) * 4);
      var mag = Math.pow(10, Math.floor(Math.log(v) / Math.LN10));
      var r = v / mag;
      var step = r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10;
      var top = step * mag;
      if (top <= v) top = step * mag * 2;
      // Un poco de aire para la etiqueta del punto más alto.
      return top === v ? top * 1.1 : top;
    }

    function idxAt(clientX) {
      if (!geom) return -1;
      var r = canvas.getBoundingClientRect();
      var rel = (clientX - r.left - geom.padL) / geom.gw;
      var i = Math.round(rel * (geom.n - 1));
      return i < 0 || i >= geom.n ? -1 : i;
    }

    function nearestSeries(clientY, i) {
      var r = canvas.getBoundingClientRect();
      var y = clientY - r.top, best = -1, bd = Infinity;
      (opts.series || []).forEach(function (s, si) {
        if (!s.link || s.values[i] == null) return;
        var d = Math.abs(geom.yAt(s.values[i]) - y);
        if (d < bd) { bd = d; best = si; }
      });
      return bd <= 18 ? best : -1;
    }

    function showTip(i, clientX) {
      if (!tip || i < 0) { if (tip) tip.hidden = true; return; }
      var rows = (opts.series || []).map(function (s) {
        var v = s.values[i];
        if (v == null) return "";
        var text = fmt(v) + (opts.unit ? " " + opts.unit : "");
        var meta = s.meta && s.meta[i];
        if (meta && meta.device_name) text = esc(meta.device_name) + " · " + esc(meta.time) + " · " + text;
        return '<div class="tp-ops-shift__tip-row"><i class="tp-ops-shift__dot" style="background:' + s.color + '"></i>' +
          (opts.series.length > 1 ? esc(s.name) + ": " : "") + "<b>" + text + "</b></div>";
      }).join("");
      var head = "<div>" + esc(t("ui.ops_shift_hour")) + " " + esc(opts.labels[i]) + ":00" +
        (opts.shifts && opts.shifts[i] ? ' <span style="opacity:.7">· ' + esc(opts.shifts[i]) + "</span>" : "") + "</div>";
      tip.innerHTML = head + (rows || '<div style="opacity:.7">—</div>');
      tip.hidden = false;
      var r = canvas.getBoundingClientRect();
      var x = clientX - r.left + 12;
      if (x + tip.offsetWidth > r.width - 4) x = clientX - r.left - tip.offsetWidth - 12;
      tip.style.left = Math.max(0, x) + "px";
      tip.style.top = "6px";
    }

    canvas.addEventListener("mousemove", function (e) {
      var i = idxAt(e.clientX);
      if (i !== hover) { hover = i; draw(); if (opts.onHover) opts.onHover(i); }
      showTip(i, e.clientX);
      if (opts.onClick && geom) wrap.classList.toggle("is-link", i >= 0 && nearestSeries(e.clientY, i) >= 0);
    });
    canvas.addEventListener("mouseleave", function () {
      hover = -1; draw(); showTip(-1); wrap.classList.remove("is-link");
      if (opts.onHover) opts.onHover(-1);
    });
    canvas.addEventListener("click", function (e) {
      if (!opts.onClick || !geom) return;
      var i = idxAt(e.clientX);
      if (i < 0) return;
      var si = nearestSeries(e.clientY, i);
      if (si >= 0) opts.onClick(si, i);
    });

    return {
      draw: draw,
      setHover: function (i) { if (i !== hover) { hover = i; draw(); } },
      update: function (next) { Object.assign(opts, next); draw(); },
    };
  }

  /* =====================================================
   * Carga y render
   * ===================================================== */
  function setStatus(html, kind) {
    var el = $("tp-ops-shift-status");
    el.innerHTML = html || "";
    el.hidden = !html;
    el.classList.toggle("is-error", kind === "error");
    $("tp-ops-shift-body").hidden = !!html;
  }

  function load() {
    var opId = $("tp-ops-shift-operation").value;
    if (!opId) { setStatus(esc(t("ui.ops_pick_operation"))); return; }
    if (state.loading) return;
    state.loading = true;

    var qs = "?operation=" + encodeURIComponent(opId) +
      "&date=" + encodeURIComponent(dateGet()) +
      "&shift=" + encodeURIComponent($("tp-ops-shift-shift").value || "all");

    setStatus(esc(t("ui.ops_shift_loading")));

    tpModal.fetchJSON(URLS.report + qs, { method: "GET" }).then(function (res) {
      state.loading = false;
      var d = (res && res.data) || {};
      if (!res.ok || !d.ok) {
        setStatus(esc((d && d.message) || t("ui.ops_shift_load_failed")), "error");
        return;
      }
      state.loaded = true;
      state.data = d;
      fillShifts(d);
      $("tp-ops-shift-updated").textContent = d.generated_at ? (t("ui.ops_shift_generated") + " " + d.generated_at) : "";

      if (d.empty) { renderEmpty(d); return; }

      setStatus("");
      renderCards(d);
      renderCharts(d);
      renderStates(d);
      renderTables(d);
    }).catch(function () {
      state.loading = false;
      setStatus(esc(t("ui.ops_shift_load_failed")), "error");
    });
  }

  function fillShifts(d) {
    var sel = $("tp-ops-shift-shift");
    var current = sel.value || "all";
    var items = [{ id: "all", name: t("ui.ops_shift_all") }].concat((d.shifts || []).map(function (s) {
      return { id: s.id, name: s.name + " (" + s.start_time + "–" + s.end_time + ")" };
    }));
    var keep = items.some(function (i) { return String(i.id) === String(current); }) ? current : "all";
    fillSelect(sel, items, keep);
  }

  function renderEmpty(d) {
    var html = "<div>" + esc(d.hint || t("ui.ops_shift_empty")) + "</div>";
    if (canEdit && d.date <= today()) {
      html += '<button type="button" class="tp-btn tp-btn--accent" id="tp-ops-shift-build">' + esc(t("ui.ops_shift_build")) + "</button>";
    }
    setStatus(html);
    var btn = $("tp-ops-shift-build");
    if (btn) btn.addEventListener("click", function () { build(btn); });
  }

  function build(btn) {
    btn.disabled = true;
    btn.textContent = t("ui.ops_shift_building");
    tpModal.fetchJSON(URLS.build, {
      method: "POST",
      json: { operation: $("tp-ops-shift-operation").value, date: dateGet() },
    }).then(function (res) {
      var d = (res && res.data) || {};
      if (!res.ok || !d.ok) {
        btn.disabled = false;
        btn.textContent = t("ui.ops_shift_build");
        tpModal.toast((d && d.message) || t("ui.ops_shift_build_failed"), "error");
        return;
      }
      load();
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = t("ui.ops_shift_build");
      tpModal.toast(t("ui.ops_shift_build_failed"), "error");
    });
  }

  /* ---------- 1. Tarjetas ---------- */
  function renderCards(d) {
    var c = d.cards || {}, u = d.units || {};
    var hasNight = (d.shifts || []).length > 1;

    function kpi(label, value, unit, dec) {
      return '<div class="tp-ops-shift__kpi"><span class="tp-ops-shift__kpi-label">' + esc(label) + "</span>" +
        '<span class="tp-ops-shift__kpi-value">' + num(value, dec) + (value != null && unit ? "<small>" + esc(unit) + "</small>" : "") + "</span></div>";
    }
    function group(title, m, unit, dec, thirdLabel) {
      m = m || {};
      var third = "total" in m ? m.total : m.avg;
      return '<div class="tp-ops-shift__group"><div class="tp-ops-shift__group-title">' + esc(title) + "</div>" +
        '<div class="tp-ops-shift__group-row">' +
        kpi(t("ui.ops_shift_day"), m.day, unit, dec) +
        (hasNight ? kpi(t("ui.ops_shift_night"), m.night, unit, dec) : "") +
        kpi(thirdLabel, third, unit, dec) +
        "</div></div>";
    }

    $("tp-ops-shift-cards").innerHTML =
      group(t("ui.ops_shift_rolling"), c.rolling, t("ui.ops_shift_units"), 1, t("ui.ops_shift_total")) +
      group(t("ui.ops_shift_speed_empty"), c.speed_empty, u.speed, 1, t("ui.ops_shift_avg")) +
      group(t("ui.ops_shift_speed_loaded"), c.speed_loaded, u.speed, 1, t("ui.ops_shift_avg")) +
      group(t("ui.ops_shift_distance"), c.distance, u.distance, 1, t("ui.ops_shift_avg"));
  }

  /* ---------- 2. Gráficas ---------- */
  function renderCharts(d) {
    var hours = d.hours || [], u = d.units || {};
    var labels = hours.map(function (h) { return h.label; });
    var shifts = hours.map(function (h) { return h.shift; });
    var pick = function (k) { return hours.map(function (h) { return h[k]; }); };
    var fleet = function (k, f) { return hours.map(function (h) { return h.fleet && h.fleet[k] != null ? (f ? h.fleet[k][f] : h.fleet[k]) : null; }); };
    var fleetMeta = function (k) {
      return hours.map(function (h) {
        var p = h.fleet && h.fleet[k];
        return p ? { device_id: p.device_id, device_name: p.device_name, at: p.at, time: p.at ? p.at.substr(11, 8) : "" } : null;
      });
    };

    var defs = {
      rolling: { unit: t("ui.ops_shift_units"), decimals: 0, series: [{ name: t("ui.ops_shift_rolling"), color: COLORS.rolling, values: pick("rolling") }] },
      empty: { unit: u.speed, decimals: 1, series: [{ name: t("ui.ops_shift_series_empty"), color: COLORS.empty, values: pick("speed_empty") }] },
      loaded: { unit: u.speed, decimals: 1, series: [{ name: t("ui.ops_shift_series_loaded"), color: COLORS.loaded, values: pick("speed_loaded") }] },
      both: { unit: u.speed, decimals: 1, series: [
        { name: t("ui.ops_shift_series_loaded"), color: COLORS.loaded, values: pick("speed_loaded") },
        { name: t("ui.ops_shift_series_empty"), color: COLORS.empty, values: pick("speed_empty") },
      ] },
      fleet: { unit: u.speed, decimals: 0, series: [
        { name: t("ui.ops_shift_max"), color: COLORS.fleetMax, values: fleet("max", "speed"), meta: fleetMeta("max"), link: true },
        { name: t("ui.ops_shift_mean"), color: COLORS.fleetAvg, values: fleet("avg") },
        { name: t("ui.ops_shift_min"), color: COLORS.fleetMin, values: fleet("min", "speed"), meta: fleetMeta("min"), link: true },
      ] },
    };

    Object.keys(defs).forEach(function (key) {
      var fig = panel.querySelector('[data-chart="' + key + '"]');
      if (!fig) return;
      var def = defs[key];
      def.labels = labels;
      def.shifts = shifts;
      def.onHover = setHoverAll;
      if (key === "fleet") def.onClick = function (si, i) { openHistory(def.series[si].meta[i]); };

      var legend = fig.querySelector("[data-legend]");
      if (legend) {
        legend.innerHTML = def.series.map(function (s) {
          return "<span><i style=\"background:" + s.color + '"></i>' + esc(s.name) + "</span>";
        }).join("");
      }

      if (state.charts[key]) state.charts[key].update(def);
      else state.charts[key] = lineChart(fig.querySelector("canvas"), def);
    });

    // El panel acaba de hacerse visible: medir en el siguiente frame.
    requestAnimationFrame(drawAll);
  }

  function drawAll() {
    Object.keys(state.charts).forEach(function (k) { state.charts[k].draw(); });
  }

  function setHoverAll(i) {
    state.hover = i;
    Object.keys(state.charts).forEach(function (k) { state.charts[k].setHover(i); });
  }

  /* Historial del vehículo ±5 min alrededor del instante del punto. */
  function openHistory(meta) {
    if (!meta || !meta.device_id || !meta.at) return;
    var p = meta.at.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
    if (!p) return;
    var at = new Date(+p[1], +p[2] - 1, +p[3], +p[4], +p[5], +p[6]);
    function fmt(dt) {
      function z(n) { return (n < 10 ? "0" : "") + n; }
      return ymd(dt) + " " + z(dt.getHours()) + ":" + z(dt.getMinutes());
    }
    var from = new Date(at.getTime() - 5 * 60000), to = new Date(at.getTime() + 5 * 60000);
    var url = URLS.history + "?device=" + encodeURIComponent(meta.device_id) +
      "&from=" + encodeURIComponent(fmt(from)) + "&to=" + encodeURIComponent(fmt(to));
    window.open(url, "_blank", "noopener");
  }

  /* ---------- 3. Estados por equipo ---------- */
  function renderStates(d) {
    var st = d.states || {}, legend = st.legend || [], rows = st.rows || [];
    var total = st.total_seconds || 86400;

    $("tp-ops-shift-states-legend").innerHTML = legend.map(function (l) {
      return "<span><i style=\"background:" + esc(l.color) + '"></i>' + esc(l.label) + "</span>";
    }).join("");

    var html = rows.map(function (r) {
      var segs = legend.map(function (l) {
        var sec = r[l.key] || 0;
        if (sec <= 0) return "";
        var pct = sec / total * 100;
        var hrs = num(sec / 3600, 1);
        return '<div class="tp-ops-shift__seg tp-ops-shift__seg--' + esc(l.key) + '" style="width:' + pct.toFixed(2) + "%;background:" + esc(l.color) + '" ' +
          'title="' + esc(l.label) + ": " + hrs + " " + t("ui.ops_shift_hours_short") + " (" + hms(sec) + ")\">" +
          (pct >= 5 ? hrs : "") + "</div>";
      }).join("");
      return '<div class="tp-ops-shift__row"><div class="tp-ops-shift__row-name" title="' + esc(r.name) + '">' + esc(r.name) + "</div>" +
        '<div class="tp-ops-shift__bar-track" role="img" aria-label="' + esc(r.name) + '">' + segs + "</div></div>";
    }).join("");

    if (!html) html = '<div class="tp-text-muted">' + esc(t("ui.ops_no_vehicles")) + "</div>";
    else html += '<div class="tp-ops-shift__scale"><span></span><div class="tp-ops-shift__scale-ticks">' +
      [0, 4, 8, 12, 16, 20, 24].map(function (h) { return "<span>" + h + " " + t("ui.ops_shift_hours_short") + "</span>"; }).join("") + "</div></div>";

    $("tp-ops-shift-states").innerHTML = html;
  }

  /* ---------- 4 y 5. Tablas ---------- */
  function renderTables(d) {
    var tb = d.tables || {};

    function numCell(v, dec) {
      return '<td class="is-num' + (v == null ? " is-muted" : "") + '">' + num(v, dec) + "</td>";
    }
    function empty(cols, text) {
      return '<tr><td colspan="' + cols + '" class="tp-svc-table__empty">' + esc(text) + "</td></tr>";
    }

    var hm = tb.hours_meter || [];
    $("tp-ops-shift-hours-rows").innerHTML = hm.length ? hm.map(function (r) {
      return "<tr>" +
        "<td>" + esc(r.device) + "</td>" +
        "<td>" + esc(r.shift) + "</td>" +
        "<td>" + dash(r.first_move) + "</td>" +
        "<td>" + dash(r.break_start) + "</td>" +
        "<td>" + dash(r.break_end) + "</td>" +
        "<td>" + dash(r.last_move) + "</td>" +
        '<td class="is-num">' + hms(r.effective_seconds) + "</td>" +
        '<td class="is-num">' + hms(r.idle_seconds) + "</td>" +
        numCell(r.engine_start, 2) + numCell(r.engine_before, 2) + numCell(r.engine_after, 2) + numCell(r.engine_end, 2) +
        numCell(r.engine_var, 2) +
        "</tr>";
    }).join("") : empty(13, t("ui.ops_shift_no_rows"));

    var od = tb.odometer || [];
    $("tp-ops-shift-odo-rows").innerHTML = od.length ? od.map(function (r) {
      return "<tr><td>" + esc(r.device) + "</td><td>" + esc(r.shift) + "</td>" +
        numCell(r.start, 2) + numCell(r.end, 2) + numCell(r.var, 2) + "</tr>";
    }).join("") : empty(5, t("ui.ops_shift_no_rows"));

    var rf = tb.refuelings || [];
    $("tp-ops-shift-refuel-rows").innerHTML = rf.length ? rf.map(function (r) {
      return "<tr><td>" + esc(r.device) + "</td><td>" + esc(r.shift) + "</td>" +
        "<td>" + dash(r.started) + "</td><td>" + dash(r.ended) + "</td>" +
        '<td class="is-num">' + hms(r.duration_seconds) + "</td>" +
        numCell(r.level_before, 1) + numCell(r.level_after, 1) + numCell(r.refuel_pct, 1) +
        "<td>" + esc(r.engine_off ? t("ui.ops_shift_yes") : t("ui.ops_shift_no")) + "</td></tr>";
    }).join("") : empty(9, t("ui.ops_shift_no_refuelings"));
  }

  /* ---------- Pantalla completa ---------- */
  function setFullscreen(on) {
    document.body.classList.toggle("is-fullscreen", on);
    var btn = $("tp-ops-shift-full");
    btn.setAttribute("aria-pressed", on ? "true" : "false");
    btn.textContent = t(on ? "ui.ops_shift_exit_fullscreen" : "ui.ops_shift_fullscreen");
    requestAnimationFrame(drawAll);
  }

  /* ---------- Arranque ---------- */
  /* Lista de operaciones del meta (la manda tp-operations.js al cargarlo o
     al activar la pestaña). Idempotente: solo rellena si aún no hay opciones. */
  function setMeta(meta) {
    if (!meta) return;
    state.meta = meta;
    var sel = $("tp-ops-shift-operation");
    if (sel.options.length) return;
    var ops = meta.operations || [];
    var active = ops.filter(function (o) { return o.active; });
    fillSelect(sel, ops, (active[0] || ops[0] || {}).id);
    // Si la pestaña ya estaba abierta esperando la lista, carga ahora.
    if (state.inited && !state.loaded && !panel.hidden) load();
  }

  function init(meta) {
    state.inited = true;
    setMeta(meta);
    dateSet(today());

    $("tp-ops-shift-operation").addEventListener("change", function () {
      $("tp-ops-shift-shift").value = "all";
      load();
    });
    $("tp-ops-shift-date").addEventListener("change", load);
    $("tp-ops-shift-shift").addEventListener("change", load);
    $("tp-ops-shift-refresh").addEventListener("click", load);
    $("tp-ops-shift-prev").addEventListener("click", function () { shiftDate(-1); load(); });
    $("tp-ops-shift-next").addEventListener("click", function () { shiftDate(1); load(); });
    $("tp-ops-shift-full").addEventListener("click", function () {
      setFullscreen(!document.body.classList.contains("is-fullscreen"));
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && document.body.classList.contains("is-fullscreen")) setFullscreen(false);
    });

    var resizeTimer = null;
    window.addEventListener("resize", function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { if (!panel.hidden) drawAll(); }, 120);
    });
  }

  window.tpOpsShift = {
    activate: function (meta) {
      if (!state.inited) init(meta); else setMeta(meta);
      if (!state.loaded) load(); else requestAnimationFrame(drawAll);
    },
    setMeta: setMeta,
    reload: load,
  };
})();
