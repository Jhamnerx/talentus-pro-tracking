/* =====================================================
   TalentusPro · Dashboard · Phase 3
   - Fetches /talentus/api/dashboard
   - Renders KPIs, ECharts (pie + bar), top table, health bars
   - Polls every 60s; reacts to theme changes
   ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-dash");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
  var POLL = parseInt(root.getAttribute("data-poll-interval"), 10) || 60000;

  var charts = {
    distribution: null,
    activity: null,
  };

  /* ----- helpers ----- */
  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }
  function $$(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  }

  function statusGroup(status) {
    if (
      status === "online" ||
      status === "move" ||
      status === "excessive_speed"
    )
      return "moving";
    if (status === "idle" || status === "ack" || status === "engine")
      return "idle";
    if (status === "stop" || status === "park") return "stopped";
    return "offline";
  }

  function fmtNumber(n, suffix) {
    if (n === null || n === undefined || isNaN(n)) return "—";
    return new Intl.NumberFormat().format(n) + (suffix || "");
  }

  function relativeTime(iso) {
    try {
      var d = new Date(iso);
      return d.toLocaleTimeString();
    } catch (e) {
      return "";
    }
  }

  function chartTextColor() {
    var styles = getComputedStyle(document.documentElement);
    return (
      (styles.getPropertyValue("--fg-secondary") || "#888").trim() || "#888"
    );
  }

  /* ----- KPI rendering ----- */
  function renderKpis(kpis) {
    $$("[data-kpi]").forEach(function (el) {
      var key = el.getAttribute("data-kpi");
      var suf = el.getAttribute("data-suffix") || "";
      var valEl = el.querySelector("[data-kpi-value]");
      if (!valEl) return;
      valEl.textContent = fmtNumber(kpis[key], suf);
    });
  }

  /* ----- Charts ----- */
  function renderDistribution(distribution) {
    var el = document.getElementById("tp-chart-distribution");
    if (!el || typeof echarts === "undefined") return;
    if (!charts.distribution)
      charts.distribution = echarts.init(el, null, { renderer: "svg" });

    charts.distribution.setOption({
      tooltip: { trigger: "item", formatter: "{b}: <b>{c}</b> ({d}%)" },
      legend: {
        bottom: 0,
        icon: "circle",
        textStyle: { color: chartTextColor(), fontSize: 12 },
      },
      series: [
        {
          type: "pie",
          radius: ["55%", "78%"],
          avoidLabelOverlap: true,
          itemStyle: {
            borderRadius: 4,
            borderColor: "transparent",
            borderWidth: 2,
          },
          label: { show: false },
          data: distribution.map(function (d) {
            return {
              name: d.name,
              value: d.value,
              itemStyle: { color: d.color },
            };
          }),
        },
      ],
    });
  }

  function renderActivity(activity) {
    var el = document.getElementById("tp-chart-activity");
    if (!el || typeof echarts === "undefined") return;
    if (!charts.activity)
      charts.activity = echarts.init(el, null, { renderer: "svg" });

    var color = chartTextColor();
    charts.activity.setOption({
      grid: { left: 32, right: 16, top: 24, bottom: 28 },
      tooltip: { trigger: "axis", axisPointer: { type: "shadow" } },
      xAxis: {
        type: "category",
        data: activity.map(function (a) {
          return a.hour;
        }),
        axisLabel: { color: color, fontSize: 10, interval: 2 },
        axisLine: { lineStyle: { color: color, opacity: 0.3 } },
      },
      yAxis: {
        type: "value",
        axisLabel: { color: color, fontSize: 10 },
        splitLine: { lineStyle: { color: color, opacity: 0.1 } },
      },
      series: [
        {
          type: "bar",
          data: activity.map(function (a) {
            return a.count;
          }),
          itemStyle: { color: "#3b82f6", borderRadius: [3, 3, 0, 0] },
          barMaxWidth: 18,
        },
      ],
    });
  }

  /* ----- Top speed table ----- */
  function renderTop(top) {
    var tbody = $("#tp-dash-top-table tbody");
    if (!tbody) return;
    if (!top || !top.length) {
      tbody.innerHTML =
        '<tr><td colspan="4" class="tp-text-muted tp-text-center">Sin datos.</td></tr>';
      return;
    }
    var labels = {
      moving: "Moviendo",
      idle: "Inactivo",
      stopped: "Detenido",
      offline: "Offline",
    };
    var rows = top
      .map(function (d, i) {
        var grp = statusGroup(d.status);
        return (
          "<tr>" +
          "<td>" +
          (i + 1) +
          "</td>" +
          "<td>" +
          escapeHtml(d.name || "#" + d.id) +
          "</td>" +
          '<td><span class="tp-pill tp-pill--' +
          grp +
          '">' +
          labels[grp] +
          "</span></td>" +
          '<td class="tp-text-right">' +
          (d.speed != null ? Number(d.speed).toFixed(1) : "—") +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
    tbody.innerHTML = rows;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  /* ----- Health bars ----- */
  function renderHealth(kpis) {
    var total = kpis.total || 0;
    var connectivity =
      total > 0 ? Math.round(((total - kpis.offline) / total) * 100) : 0;
    var utilization = kpis.utilization || 0;
    var active =
      total > 0 ? Math.round(((kpis.moving + kpis.idle) / total) * 100) : 0;

    var values = {
      connectivity: connectivity,
      utilization: utilization,
      active: active,
    };
    Object.keys(values).forEach(function (k) {
      var fill = document.querySelector('[data-health="' + k + '"]');
      var val = document.querySelector('[data-health-val="' + k + '"]');
      if (fill) fill.style.width = values[k] + "%";
      if (val) val.textContent = values[k] + "%";
    });
  }

  /* ----- Fetch + apply ----- */
  function load() {
    return fetch(DATA_URL, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (payload) {
        if (!payload || !payload.ok) return;
        renderKpis(payload.kpis || {});
        renderDistribution(payload.distribution || []);
        renderActivity(payload.activity || []);
        renderTop(payload.topSpeed || []);
        renderHealth(payload.kpis || {});
        var fetched = $("#tp-dash-fetched");
        if (fetched)
          fetched.textContent =
            "Actualizado · " + relativeTime(payload.fetchedAt);
      })
      .catch(function (err) {
        console.warn("[dashboard] load failed", err);
      });
  }

  /* ----- Theme reactivity ----- */
  new MutationObserver(function () {
    Object.values(charts).forEach(function (c) {
      if (c) c.resize();
    });
    // Re-apply text colors by re-running set on existing options
    if (charts.distribution)
      charts.distribution.setOption({
        legend: { textStyle: { color: chartTextColor() } },
      });
    if (charts.activity) {
      var col = chartTextColor();
      charts.activity.setOption({
        xAxis: {
          axisLabel: { color: col },
          axisLine: { lineStyle: { color: col, opacity: 0.3 } },
        },
        yAxis: {
          axisLabel: { color: col },
          splitLine: { lineStyle: { color: col, opacity: 0.1 } },
        },
      });
    }
  }).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-theme"],
  });

  /* ----- Resize ----- */
  window.addEventListener("resize", function () {
    Object.values(charts).forEach(function (c) {
      if (c) c.resize();
    });
  });

  /* ----- Refresh button ----- */
  var refreshBtn = $("#tp-dash-refresh");
  if (refreshBtn)
    refreshBtn.addEventListener("click", function () {
      load();
    });

  /* ----- Boot + poll ----- */
  load();
  setInterval(load, POLL);
})();
