/* =====================================================
   TalentusPro · Dashboard fusionado (flota + conducción + alertas)
   - GET /ui/v2/api/dashboard?range=today|yesterday|week|month|custom
   - Donuts de estado (dispositivos/conexión/movimiento) estilo Wialon
   - Card de Conducción: índice + frenados/aceleraciones/giros/exceso +
     tendencia de eventos de riesgo (desde la tabla events)
   - Alertas por tipo, top vehículos de riesgo, kilometraje, notificaciones
   - ECharts (svg) + reactividad de tema + polling
   ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-dash");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
  var POLL = parseInt(root.getAttribute("data-poll-interval"), 10) || 60000;
  // "Hoy" en la ZONA HORARIA DEL USUARIO (calculado en el servidor con
  // Formatter::time()->now()), no la del navegador.
  var USER_TODAY = root.getAttribute("data-user-today") || null;

  var state = { range: "week", from: null, to: null };
  var charts = {}; // id → echarts instance

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  function fmt(n, suffix) {
    if (n === null || n === undefined || isNaN(n)) return "—";
    return new Intl.NumberFormat().format(n) + (suffix || "");
  }
  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function cssVar(name, fb) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return (v && v.trim()) || fb;
  }
  function textColor() { return cssVar("--fg-secondary", "#888"); }

  function statusGroup(status) {
    if (status === "online" || status === "move" || status === "excessive_speed") return "moving";
    if (status === "idle" || status === "ack" || status === "engine") return "idle";
    if (status === "stop" || status === "park") return "stopped";
    return "offline";
  }
  function chart(id) {
    var el = document.getElementById(id);
    if (!el || typeof echarts === "undefined") return null;
    if (!charts[id]) charts[id] = echarts.init(el, null, { renderer: "svg" });
    return charts[id];
  }

  /* ----- KPIs ----- */
  function renderKpis(kpis) {
    $$("[data-kpi]").forEach(function (el) {
      var v = el.querySelector("[data-kpi-value]");
      if (v) v.textContent = fmt(kpis[el.getAttribute("data-kpi")], el.getAttribute("data-suffix") || "");
    });
  }

  /* ----- Donut + leyenda HTML (estilo Wialon) ----- */
  function renderDonut(chartId, legendId, items, total) {
    items = items || [];
    var c = chart(chartId);
    if (c) {
      var hasData = items.some(function (d) { return d.value > 0; });
      c.setOption({
        // appendToBody evita que la card (overflow:hidden) recorte el tooltip
        tooltip: { trigger: "item", formatter: "{b}: <b>{c}</b> ({d}%)", appendToBody: true, confine: true },
        series: [{
          type: "pie", radius: ["62%", "88%"], center: ["50%", "50%"],
          avoidLabelOverlap: true, silent: !hasData,
          itemStyle: { borderRadius: 3, borderColor: cssVar("--surface-raised", "#fff"), borderWidth: 2 },
          label: {
            show: true, position: "center",
            formatter: String(total || 0), fontSize: 24, fontWeight: 700,
            color: cssVar("--fg-primary", "#0f172a"),
          },
          emphasis: { scale: false, label: { show: true } },
          labelLine: { show: false },
          data: hasData
            ? items.map(function (d) { return { name: d.name, value: d.value, itemStyle: { color: d.color } }; })
            : [{ name: "Sin datos", value: 1, itemStyle: { color: cssVar("--surface-sunken", "#e2e8f0") }, tooltip: { show: false } }],
        }],
      });
    }
    // Leyenda HTML: cuadro de color + etiqueta + conteo alineado a la derecha
    var leg = document.getElementById(legendId);
    if (leg) {
      leg.innerHTML = items
        .map(function (d) {
          return (
            '<li class="tp-legend__row">' +
            '<span class="tp-legend__sw" style="background:' + d.color + '"></span>' +
            '<span class="tp-legend__lbl">' + escapeHtml(d.name) + "</span>" +
            '<span class="tp-legend__val">' + fmt(d.value) + "</span>" +
            "</li>"
          );
        })
        .join("");
    }
  }

  /* ----- Conducción ----- */
  function renderDriving(p) {
    var d = p.driving || {};
    $$("[data-behav]").forEach(function (el) {
      var v = el.querySelector("[data-behav-val]");
      if (v) v.textContent = fmt(d[el.getAttribute("data-behav")] || 0);
    });
    var eco = $("[data-eco-value]");
    if (eco) eco.textContent = p.ecoScore != null ? p.ecoScore : "—";
    var ecoBox = $("#tp-eco");
    if (ecoBox) {
      var s = p.ecoScore != null ? p.ecoScore : 0;
      ecoBox.setAttribute("data-tone", s >= 80 ? "good" : s >= 55 ? "warn" : "bad");
    }
    var risk = $("[data-risk-events]");
    if (risk) risk.textContent = fmt(p.riskEvents || 0);
    var rng = $("#tp-driving-range");
    if (rng) rng.textContent = (p.from || "") + " → " + (p.to || "");

    // Tendencia
    var c = chart("tp-chart-trend");
    if (c) {
      var col = textColor();
      var pts = p.trend || [];
      c.setOption({
        grid: { left: 30, right: 10, top: 12, bottom: 20 },
        tooltip: { trigger: "axis", appendToBody: true, confine: true },
        xAxis: {
          type: "category",
          data: pts.map(function (x) { return x.date.slice(5); }),
          axisLabel: { color: col, fontSize: 9, interval: Math.max(0, Math.floor(pts.length / 8)) },
          axisLine: { lineStyle: { color: col, opacity: 0.3 } },
        },
        yAxis: {
          type: "value", axisLabel: { color: col, fontSize: 9 },
          splitLine: { lineStyle: { color: col, opacity: 0.1 } },
        },
        series: [{
          type: "line", smooth: true, symbol: "none",
          data: pts.map(function (x) { return x.count; }),
          lineStyle: { color: "#ef4444", width: 2 },
          areaStyle: { color: "rgba(239,68,68,0.12)" },
        }],
      });
    }
  }

  function emptyState(msg) {
    return (
      '<div class="tp-empty">' +
      '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18.7 8l-5.1 5.2-2.8-2.8L7 14"/></svg>' +
      "<span>" + escapeHtml(msg) + "</span></div>"
    );
  }

  /* ----- Bar-list (categorías / top-risk / kilometraje) ----- */
  function renderBarList(id, rows, opts) {
    opts = opts || {};
    var el = document.getElementById(id);
    if (!el) return;
    if (!rows || !rows.length) {
      el.innerHTML = emptyState("Sin datos en el período.");
      return;
    }
    var max = rows.reduce(function (m, r) { return Math.max(m, opts.value(r)); }, 0) || 1;
    el.innerHTML = rows
      .map(function (r) {
        var val = opts.value(r);
        var pct = Math.max(2, Math.round((val / max) * 100));
        var color = opts.color ? opts.color(r) : "#3b82f6";
        return (
          '<div class="tp-barlist__row">' +
          '<span class="tp-barlist__label" title="' + escapeHtml(opts.label(r)) + '">' + escapeHtml(opts.label(r)) + "</span>" +
          '<span class="tp-barlist__track"><span class="tp-barlist__fill" style="width:' + pct + "%;background:" + color + '"></span></span>' +
          '<span class="tp-barlist__val">' + escapeHtml(opts.display(r)) + "</span>" +
          "</div>"
        );
      })
      .join("");
  }

  /* ----- Ranking de conducción -----
     Índice = eventos de riesgo ponderados por cada 100 km. Menos es mejor,
     así que la barra del ranking se dibuja invertida en "Mejor": la más
     larga es la del que mejor conduce, no la del que más eventos tiene. */
  function renderRanking(r) {
    if (!r) return;

    var hint = $("#tp-rank-hint");
    if (hint) {
      hint.textContent = r.indice != null
        ? r.indice + " eventos/100 km en la flota"
        : "Sin kilometraje en el período";
    }

    var peorMax = (r.peores || []).reduce(function (m, x) { return Math.max(m, x.index); }, 0) || 1;

    renderBarList("tp-dash-rank-worst", r.peores, {
      label: function (x) { return x.name || "#" + x.id; },
      value: function (x) { return x.index; },
      display: function (x) { return x.index + " · " + fmt(x.events) + " ev"; },
      color: function () { return "#ef4444"; },
    });

    // En "Mejor" el valor de la barra se invierte para que llene más el que
    // menos incidencias tiene por kilómetro.
    renderBarList("tp-dash-rank-best", r.mejores, {
      label: function (x) { return x.name || "#" + x.id; },
      value: function (x) { return Math.max(0.01, peorMax - x.index); },
      display: function (x) { return x.index + " · " + fmt(x.events) + " ev"; },
      color: function () { return "#16a34a"; },
    });

    var foot = $("#tp-rank-foot");
    if (foot) {
      var partes = [
        "Índice: eventos ponderados (choque ×10, exceso ×2, resto ×1) por cada 100 km.",
        "Medidos " + fmt(r.cubiertos) + " de " + fmt(r.flota) + " vehículos con kilometraje en el período.",
      ];
      if (r.acotado) {
        partes.push("En flotas grandes el ranking se calcula sobre los vehículos con incidencias y los de actividad más reciente.");
      }
      foot.textContent = partes.join(" ");
    }
  }

  /* ----- Notificaciones ----- */
  function renderTasks(items) {
    var host = $("#tp-dash-tasks");
    if (!host) return;
    if (!items || !items.length) {
      host.innerHTML = '<li class="tp-text-muted tp-text-sm">Sin tareas.</li>';
      return;
    }
    host.innerHTML = items
      .map(function (t) {
        // Mismo marcado que las notificaciones (clases .tp-note__* ya estilizadas)
        var PRIORITY_COLORS = ["#94a3b8", "#3b82f6", "#f59e0b", "#ef4444"];
        return (
          '<li class="tp-note">' +
          '<span class="tp-note__dot" style="background:' + (PRIORITY_COLORS[t.priority] || "#94a3b8") + '"></span>' +
          '<span class="tp-note__body"><span class="tp-note__dev">' + escapeHtml(t.title || "#" + t.id) + "</span>" +
          '<span class="tp-note__msg">' + escapeHtml(t.device || "") +
          (t.status_label ? " · " + escapeHtml(t.status_label) : "") + "</span></span>" +
          '<span class="tp-note__time">' + escapeHtml(t.delivery_to ? String(t.delivery_to).slice(5, 16) : "") + "</span>" +
          "</li>"
        );
      })
      .join("");
  }

  function renderNotes(rows) {
    var el = document.getElementById("tp-dash-notes");
    if (!el) return;
    if (!rows || !rows.length) {
      el.innerHTML = '<li class="tp-text-muted tp-text-sm">No hay información en el período.</li>';
      return;
    }
    el.innerHTML = rows
      .map(function (r) {
        var t = r.time ? r.time.slice(5, 16) : "";
        return (
          '<li class="tp-note">' +
          '<span class="tp-note__dot" style="background:' + (r.color || "#cbd5e1") + '"></span>' +
          '<span class="tp-note__body"><span class="tp-note__dev">' + escapeHtml(r.device) + "</span>" +
          '<span class="tp-note__msg">' + escapeHtml(r.message) + "</span></span>" +
          '<span class="tp-note__time">' + escapeHtml(t) + "</span>" +
          "</li>"
        );
      })
      .join("");
  }

  /* ----- Top velocidad + Actividad (heredados) ----- */
  function renderTop(top) {
    var tbody = $("#tp-dash-top-table tbody");
    if (!tbody) return;
    if (!top || !top.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="tp-text-muted tp-text-center">Sin datos.</td></tr>';
      return;
    }
    var labels = { moving: "Moviendo", idle: "Inactivo", stopped: "Detenido", offline: "Sin datos" };
    tbody.innerHTML = top
      .map(function (d, i) {
        var g = statusGroup(d.status);
        return (
          "<tr><td>" + (i + 1) + "</td><td>" + escapeHtml(d.name || "#" + d.id) + "</td>" +
          '<td><span class="tp-pill tp-pill--' + g + '">' + labels[g] + "</span></td>" +
          '<td class="tp-text-right">' + (d.speed != null ? Number(d.speed).toFixed(1) : "—") + "</td></tr>"
        );
      })
      .join("");
  }
  function renderActivity(activity) {
    var c = chart("tp-chart-activity");
    if (!c) return;
    var col = textColor();
    c.setOption({
      grid: { left: 32, right: 16, top: 16, bottom: 28 },
      tooltip: { trigger: "axis", axisPointer: { type: "shadow" }, appendToBody: true, confine: true },
      xAxis: {
        type: "category", data: (activity || []).map(function (a) { return a.hour; }),
        axisLabel: { color: col, fontSize: 10, interval: 2 },
        axisLine: { lineStyle: { color: col, opacity: 0.3 } },
      },
      yAxis: { type: "value", axisLabel: { color: col, fontSize: 10 }, splitLine: { lineStyle: { color: col, opacity: 0.1 } } },
      series: [{ type: "bar", data: (activity || []).map(function (a) { return a.count; }), itemStyle: { color: "#3b82f6", borderRadius: [3, 3, 0, 0] }, barMaxWidth: 18 }],
    });
  }

  var CAT_LABEL = {}; // key → label (para bycategory usamos el propio row)

  /* ----- Fetch + apply ----- */
  // El front SIEMPRE envía from/to (fuente única de verdad); range es solo la
  // etiqueta del preset para el resaltado. El backend consulta esas fechas.
  function buildUrl(scope) {
    var u = DATA_URL + (DATA_URL.indexOf("?") >= 0 ? "&" : "?") + "scope=" + scope + "&range=" + encodeURIComponent(state.range);
    if (state.from && state.to) {
      u += "&from=" + encodeURIComponent(state.from) + "&to=" + encodeURIComponent(state.to);
    }
    return u;
  }

  // Ejecuta un render de forma aislada: si un card falla, NO tumba al resto.
  function safe(name, fn) {
    try {
      fn();
    } catch (e) {
      console.warn("[dashboard] render '" + name + "' falló:", e);
    }
  }

  function setBusy(on) {
    root.classList.toggle("is-loading", !!on);
    $$("#tp-dash-range [data-range], #tp-dash-apply").forEach(function (b) { b.disabled = !!on; });
  }

  // Refleja en los inputs De/A las fechas calculadas por el preset (usa la
  // API de tp-datepicker si el input ya fue realzado; si no, el value nativo).
  function setDateInput(id, iso) {
    var el = document.getElementById(id);
    if (!el || !iso) return;
    if (el._tpdp && el._tpdp.set) el._tpdp.set(iso);
    else el.value = iso;
  }

  function isoDate(d) {
    return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
  }
  // Base "hoy" en la zona del usuario (inyectada por el servidor). Se
  // construye como fecha LOCAL a medianoche para que la aritmética de días
  // no dependa de la hora ni la zona del navegador.
  function baseToday() {
    if (USER_TODAY) {
      var p = USER_TODAY.split("-");
      return new Date(+p[0], +p[1] - 1, +p[2]);
    }
    return new Date();
  }
  // Calcula from/to del preset con la fecha del usuario (no la del navegador).
  function computeRange(preset) {
    var now = baseToday();
    var from = new Date(now), to = new Date(now);
    if (preset === "today") {
      // from = to = hoy
    } else if (preset === "yesterday") {
      from.setDate(now.getDate() - 1); to.setDate(now.getDate() - 1);
    } else if (preset === "month") {
      from.setDate(now.getDate() - 29);
    } else { // week
      from.setDate(now.getDate() - 6);
    }
    return { from: isoDate(from), to: isoDate(to) };
  }

  // scope: "all" (boot) · "period" (cambio de rango, rápido) · "live" (polling)
  // · "driving" (ranking, en paralelo porque calcula kilometraje por vehículo)
  function load(scope) {
    scope = scope || "all";
    // El ranking se pide aparte y en paralelo: no debe apagar el indicador
    // de carga del resto del panel ni parpadear al llegar.
    var showBusy = scope !== "live" && scope !== "driving";
    if (showBusy) setBusy(true);
    return fetch(buildUrl(scope), { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (p) {
        if (!p || !p.ok) return;
        // Solo se renderizan las secciones presentes en el payload (según el
        // scope), para no borrar los cards que no vinieron en esta respuesta.
        if (p.kpis) safe("kpis", function () { renderKpis(p.kpis); });
        if (p.health) safe("health", function () { renderDonut("tp-chart-health", "tp-legend-health", p.health.items, p.health.total); });
        if (p.connection) safe("connection", function () { renderDonut("tp-chart-connection", "tp-legend-connection", p.connection.items, p.connection.total); });
        if (p.distribution) safe("movement", function () { renderDonut("tp-chart-movement", "tp-legend-movement", p.distribution, (p.kpis || {}).total); });
        if (p.topSpeed) safe("top", function () { renderTop(p.topSpeed); });
        if (p.activity) safe("activity", function () { renderActivity(p.activity); });

        if (p.driving) safe("driving", function () { renderDriving(p); });
        if (p.byCategory) safe("bycategory", function () {
          renderBarList("tp-dash-bycategory", p.byCategory, {
            label: function (r) { return r.label; }, value: function (r) { return r.count; },
            display: function (r) { return fmt(r.count); }, color: function (r) { return r.color; },
          });
        });
        if (p.topRisk) safe("toprisk", function () {
          renderBarList("tp-dash-toprisk", p.topRisk, {
            label: function (r) { return r.name || "#" + r.id; }, value: function (r) { return r.count; },
            display: function (r) { return fmt(r.count); }, color: function () { return "#ef4444"; },
          });
        });
        if (p.mileage) safe("mileage", function () {
          renderBarList("tp-dash-mileage", p.mileage, {
            label: function (r) { return r.name || "#" + r.id; }, value: function (r) { return r.km; },
            display: function (r) { return fmt(r.km) + " km"; }, color: function () { return "#3b82f6"; },
          });
        });
        if (p.drivingRanking) safe("ranking", function () { renderRanking(p.drivingRanking); });
        if ("notifications" in p) safe("notes", function () { renderNotes(p.notifications); });

        // Estado de la flota: 6 grupos con los colores del usuario (device_overview)
        if (p.overview) safe("overview", function () {
          renderBarList("tp-dash-overview", p.overview, {
            label: function (r) { return r.label; },
            value: function (r) { return r.count; },
            display: function (r) { return fmt(r.count); },
            color: function (r) { return r.color || "#94a3b8"; },
          });
          // Cada barra lleva a la Flota filtrada por ese estado.
          var host = $("#tp-dash-overview");
          if (host) {
            host.querySelectorAll(".tp-barlist__row").forEach(function (row, i) {
              var item = p.overview[i];
              if (!item || !item.url) return;
              row.style.cursor = "pointer";
              row.title = "Ver en Flota";
              row.addEventListener("click", function () { location.href = item.url; });
            });
          }
        });

        // Últimas tareas
        if ("latest_tasks" in p) safe("tasks", function () { renderTasks(p.latest_tasks); });

        // El top de kilometraje se calcula sobre una muestra salvo que el
        // usuario haya elegido vehículos en las opciones del bloque.
        if ("mileage_sampled" in p) {
          var hint = $("#tp-dash-mileage-hint");
          if (hint) {
            hint.textContent = p.mileage_sampled ? "En el período · muestra" : "En el período";
            hint.title = p.mileage_sampled
              ? "Se calcula sobre los vehículos con actividad más reciente. Elige vehículos en Ajustes → Tablero para un cálculo exacto."
              : "";
          }
        }

        if ("totalEvents" in p) {
          var te = $("[data-total-events]");
          if (te) te.textContent = fmt(p.totalEvents || 0) + " eventos";
        }
        if (p.from) {
          // Los accesos rápidos (Hoy/Ayer/Semana/Mes) reflejan sus fechas en
          // los selectores De/A, como Wialon.
          setDateInput("tp-dash-from", p.from);
          setDateInput("tp-dash-to", p.to);
        }
        var fetched = $("#tp-dash-fetched");
        if (fetched && p.from) {
          fetched.textContent = "Rango " + p.from + " → " + p.to + " · actualizado " + new Date(p.fetchedAt).toLocaleTimeString();
        }
      })
      .catch(function (err) { console.warn("[dashboard] load failed", err); })
      .then(function () { if (showBusy) setBusy(false); });
  }

  // El periodo son dos peticiones en paralelo: la rápida pinta el panel y
  // el ranking llega cuando termina de medir el kilometraje.
  function loadPeriod() {
    load("period");
    load("driving");
  }

  /* ----- Toolbar de rango (solo recarga la data de PERÍODO → rápido) ----- */
  $$("#tp-dash-range [data-range]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      $$("#tp-dash-range [data-range]").forEach(function (b) { b.classList.remove("is-active"); });
      btn.classList.add("is-active");
      state.range = btn.getAttribute("data-range");
      // Calcula las fechas en el front, las refleja en De/A y las manda al back
      var r = computeRange(state.range);
      state.from = r.from; state.to = r.to;
      setDateInput("tp-dash-from", r.from);
      setDateInput("tp-dash-to", r.to);
      loadPeriod();
    });
  });
  var applyBtn = $("#tp-dash-apply");
  if (applyBtn)
    applyBtn.addEventListener("click", function () {
      var f = $("#tp-dash-from").value, t = $("#tp-dash-to").value;
      if (!f || !t) return;
      state.range = "custom"; state.from = f; state.to = t;
      $$("#tp-dash-range [data-range]").forEach(function (b) { b.classList.remove("is-active"); });
      loadPeriod();
    });
  var refreshBtn = $("#tp-dash-refresh");
  if (refreshBtn) refreshBtn.addEventListener("click", function () { load("all"); load("driving"); });

  /* ----- Tema / resize ----- */
  new MutationObserver(function () {
    Object.keys(charts).forEach(function (k) { if (charts[k]) charts[k].resize(); });
  }).observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });

  window.addEventListener("resize", function () {
    Object.keys(charts).forEach(function (k) { if (charts[k]) charts[k].resize(); });
  });

  // Pre-rellena las fechas del rango por defecto (Semana) al instante
  (function () {
    var r = computeRange(state.range);
    state.from = r.from; state.to = r.to;
    setDateInput("tp-dash-from", r.from);
    setDateInput("tp-dash-to", r.to);
  })();

  load("all");
  load("driving");
  // Polling: solo refresca la data EN VIVO (donuts/KPIs), el rango se mantiene.
  setInterval(function () { load("live"); }, POLL);
})();
