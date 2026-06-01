/* =====================================================
 * TalentusPro · Co-Pilot IA · Phase 10
 * Heuristic insights + NL intent matching (no external LLM).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-copilot");
  if (!root) return;

  var INSIGHTS_URL = root.getAttribute("data-insights-url");
  var ASK_URL = root.getAttribute("data-ask-url");

  function $(s, c) {
    return (c || document).querySelector(s);
  }
  function $$(s, c) {
    return Array.prototype.slice.call((c || document).querySelectorAll(s));
  }
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

  var ICONS = {
    alert:
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>',
    idle: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
    gps: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s-7-7.5-7-12a7 7 0 1114 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="10" r="3"/></svg>',
    sos: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v10M4.93 4.93l7.07 7.07M2 12h10M4.93 19.07l7.07-7.07"/></svg>',
    speed:
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12a10 10 0 0120 0M12 12l4-4"/></svg>',
    pattern:
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="19" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><path d="M14 10l4-4M10 14l-4 4"/></svg>',
    chart:
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18M7 14l3-3 4 4 5-7"/></svg>',
    check:
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg>',
    rocket:
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.5 16.5L3 21l4.5-1.5M19 5l-7 7M9 11l4 4M14.5 4l5.5 5.5L13 16l-5-5z"/></svg>',
    map: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 6v15l7-3 8 3 7-3V3l-7 3-8-3z"/></svg>',
    eye: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    bell: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M13.7 21a2 2 0 01-3.4 0"/></svg>',
  };

  /* ---------- INSIGHTS ---------- */
  function renderKpis(k) {
    $("#cp-kpis").hidden = false;
    $("#kpi-total").textContent = (k.total || 0).toLocaleString();
    $("#kpi-util").textContent =
      k.utilization != null ? k.utilization + "%" : "—";
    $("#kpi-evt").textContent = (k.events_24h || 0).toLocaleString();
    $("#kpi-ins").textContent = (k.insights || 0).toLocaleString();
  }

  function renderInsights(list) {
    var wrap = $("#cp-insights");
    if (!list || !list.length) {
      wrap.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:32px;">—</div>';
      return;
    }
    wrap.innerHTML = list
      .map(function (i) {
        var icon = ICONS[i.icon] || ICONS.alert;
        var action = i.action
          ? '<a class="tp-copilot__insight-action" href="' +
            escapeHtml(i.action.url) +
            '">' +
            escapeHtml(i.action.label) +
            " →</a>"
          : "";
        return (
          '<article class="tp-copilot__insight tp-copilot__insight--' +
          escapeHtml(i.severity) +
          '">' +
          '<div class="tp-copilot__insight-icon">' +
          icon +
          "</div>" +
          '<div class="tp-copilot__insight-body">' +
          '<div class="tp-copilot__insight-title">' +
          escapeHtml(i.title) +
          "</div>" +
          '<div class="tp-copilot__insight-detail">' +
          escapeHtml(i.detail) +
          "</div>" +
          action +
          "</div>" +
          "</article>"
        );
      })
      .join("");
  }

  function renderShortcuts(list) {
    var wrap = $("#cp-shortcuts");
    if (!list || !list.length) {
      wrap.innerHTML = "";
      return;
    }
    wrap.innerHTML = list
      .map(function (s) {
        var icon = ICONS[s.icon] || ICONS.rocket;
        return (
          '<a class="tp-copilot__shortcut" href="' +
          escapeHtml(s.url) +
          '">' +
          '<span class="tp-copilot__shortcut-icon">' +
          icon +
          "</span>" +
          "<span>" +
          escapeHtml(s.label) +
          "</span>" +
          "</a>"
        );
      })
      .join("");
  }

  function renderMeta(when) {
    try {
      var d = new Date(when);
      $("#cp-meta").innerHTML =
        '<span class="tp-text-muted tp-text-xs">Analizado ' +
        d.toLocaleTimeString() +
        "</span>";
    } catch (e) {
      $("#cp-meta").innerHTML =
        '<span class="tp-text-muted tp-text-xs">Listo</span>';
    }
  }

  function loadInsights() {
    $("#cp-insights").innerHTML =
      '<div class="tp-text-muted tp-text-center" style="padding:32px;">Analizando tu operación…</div>';
    return fetch(INSIGHTS_URL, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.ok) throw new Error("load_failed");
        renderKpis(j.kpis || {});
        renderInsights(j.insights || []);
        renderShortcuts(j.suggestions || []);
        renderMeta(j.generated_at);
      })
      .catch(function (e) {
        $("#cp-insights").innerHTML =
          '<div class="tp-text-muted tp-text-center" style="padding:32px;">Error: ' +
          escapeHtml(e.message || e) +
          "</div>";
      });
  }

  /* ---------- ASK ---------- */
  function appendBubble(role, html) {
    $("#cp-chat").hidden = false;
    var b = document.createElement("div");
    b.className = "tp-copilot__bubble tp-copilot__bubble--" + role;
    b.innerHTML = html;
    $("#cp-chat").appendChild(b);
    $("#cp-chat").scrollTop = $("#cp-chat").scrollHeight;
  }

  function ask(q) {
    q = String(q || "").trim();
    if (!q) return;
    appendBubble("user", escapeHtml(q));

    // Typing indicator
    var typing = document.createElement("div");
    typing.className = "tp-copilot__bubble tp-copilot__bubble--bot";
    typing.innerHTML = '<em class="tp-text-muted">pensando…</em>';
    $("#cp-chat").hidden = false;
    $("#cp-chat").appendChild(typing);
    $("#cp-chat").scrollTop = $("#cp-chat").scrollHeight;

    fetch(ASK_URL + "?q=" + encodeURIComponent(q), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        typing.remove();
        if (!j || !j.ok) {
          appendBubble("bot", "Hubo un problema procesando tu pregunta.");
          return;
        }
        var html = escapeHtml(j.reply);
        if (j.action && j.action.url) {
          html +=
            '<br><a class="tp-copilot__bubble-action" href="' +
            escapeHtml(j.action.url) +
            '">' +
            escapeHtml(j.action.label) +
            " →</a>";
        }
        appendBubble("bot", html);
      })
      .catch(function () {
        typing.remove();
        appendBubble("bot", "Sin conexión con el servidor.");
      });
  }

  /* ---------- EVENTS ---------- */
  function bind() {
    $("#cp-go").addEventListener("click", function () {
      ask($("#cp-q").value);
      $("#cp-q").value = "";
    });
    $("#cp-q").addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        ask($("#cp-q").value);
        $("#cp-q").value = "";
      }
    });
    $("#cp-quickq").addEventListener("click", function (e) {
      var b = e.target.closest("[data-q]");
      if (!b) return;
      ask(b.getAttribute("data-q"));
    });
    $("#cp-refresh").addEventListener("click", loadInsights);
  }

  function init() {
    bind();
    loadInsights();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
