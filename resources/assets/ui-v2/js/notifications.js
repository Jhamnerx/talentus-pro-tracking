/* ============================================================
   TalentusPro · Notifications JS v1.0
   ------------------------------------------------------------
   Fetches notifications from backend; renders into tray.
   Polling fallback while WebSocket wiring is in Phase 2.
   ============================================================ */
(function () {
  "use strict";

  var T = window.Talentus || {};
  var list = document.getElementById("tp-notif-list");
  var badge = document.getElementById("tp-notif-count");
  var markAll = document.getElementById("tp-notif-mark-all");
  var filterButtons = document.querySelectorAll("[data-notif-filter]");

  if (!list) return;

  var currentFilter = "all";
  var data = [];
  var pollMs = 30000;

  // Estado "leído" client-side: timestamp last-seen en localStorage (la
  // tabla events no tiene marcador de lectura; el server manda read=false).
  var SEEN_KEY = "tp_notif_seen";
  function getSeen() {
    try {
      return parseInt(localStorage.getItem(SEEN_KEY), 10) || 0;
    } catch (e) {
      return 0;
    }
  }
  function setSeen(ms) {
    try {
      localStorage.setItem(SEEN_KEY, String(ms));
    } catch (e) {}
  }
  function applySeen() {
    var seen = getSeen();
    data.forEach(function (n) {
      if (n.read) return;
      var t = n.created_at ? new Date(n.created_at).getTime() : 0;
      n.read = t > 0 && t <= seen;
    });
  }

  var iconByVariant = {
    critical: "🚨",
    warning: "⚠",
    success: "✓",
    info: "ℹ",
    danger: "✕",
  };

  function fmtTime(iso) {
    if (!iso) return "";
    try {
      var d = new Date(iso);
      var diff = (Date.now() - d.getTime()) / 1000;
      if (diff < 60) return "hace " + Math.floor(diff) + "s";
      if (diff < 3600) return "hace " + Math.floor(diff / 60) + "m";
      if (diff < 86400) return "hace " + Math.floor(diff / 3600) + "h";
      return d.toLocaleString();
    } catch (e) {
      return iso;
    }
  }

  function render() {
    var filtered = data.filter(function (n) {
      if (currentFilter === "all") return true;
      if (currentFilter === "critical")
        return n.variant === "critical" || n.variant === "danger";
      if (currentFilter === "alerts")
        return ["critical", "warning", "danger"].indexOf(n.variant) !== -1;
      if (currentFilter === "system")
        return n.category === "system" || n.variant === "info";
      return true;
    });

    if (!filtered.length) {
      list.innerHTML =
        '<div class="tp-tray__empty"><div class="tp-text-muted">No hay notificaciones para este filtro.</div></div>';
      return;
    }

    list.innerHTML = filtered
      .map(function (n) {
        var classes = ["tp-notif-item"];
        if (!n.read) classes.push("is-unread");
        if (n.variant) classes.push("tp-notif-item--" + n.variant);
        var icon = iconByVariant[n.variant] || "•";
        return (
          '<div class="' +
          classes.join(" ") +
          '" data-id="' +
          escapeAttr(n.id) +
          '" ' +
          (n.url ? 'data-url="' + escapeAttr(n.url) + '"' : "") +
          ">" +
          '<div class="tp-notif-item__icon">' +
          icon +
          "</div>" +
          '<div class="tp-notif-item__main">' +
          '<div class="tp-notif-item__title">' +
          escapeHtml(n.title || "") +
          "</div>" +
          (n.description
            ? '<div class="tp-notif-item__desc">' +
              escapeHtml(n.description) +
              "</div>"
            : "") +
          '<div class="tp-notif-item__time">' +
          fmtTime(n.created_at) +
          "</div>" +
          "</div>" +
          "</div>"
        );
      })
      .join("");

    list.querySelectorAll(".tp-notif-item").forEach(function (el) {
      el.addEventListener("click", function () {
        var url = el.getAttribute("data-url");
        if (url) window.location.href = url;
      });
    });
  }

  function refreshBadge() {
    var unread = data.filter(function (n) {
      return !n.read;
    }).length;
    if (!badge) return;
    if (unread > 0) {
      badge.textContent = unread > 99 ? "99+" : String(unread);
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function fetchNotifications() {
    if (!T.routes || !T.routes.notifications) {
      // No endpoint wired yet — render empty state.
      data = [];
      render();
      refreshBadge();
      return;
    }
    fetch(T.routes.notifications, {
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    })
      .then(function (r) {
        return r.ok ? r.json() : Promise.reject(r.status);
      })
      .then(function (json) {
        data = (json && json.notifications) || [];
        applySeen();
        render();
        refreshBadge();
      })
      .catch(function () {
        /* silent */
      });
  }

  filterButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      filterButtons.forEach(function (b) {
        b.setAttribute("aria-selected", b === btn ? "true" : "false");
      });
      currentFilter = btn.getAttribute("data-notif-filter") || "all";
      render();
    });
  });

  if (markAll) {
    markAll.addEventListener("click", function () {
      setSeen(Date.now());
      data.forEach(function (n) {
        n.read = true;
      });
      render();
      refreshBadge();
      window.tpToast &&
        window.tpToast({
          title: "Notificaciones marcadas como leídas",
          variant: "success",
          duration: 2000,
        });
    });
  }

  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
  function escapeAttr(s) {
    return escapeHtml(s);
  }

  // Initial fetch + polling
  fetchNotifications();
  setInterval(fetchNotifications, pollMs);

  // Expose for debugging
  window.tpNotifications = { refresh: fetchNotifications };
})();
