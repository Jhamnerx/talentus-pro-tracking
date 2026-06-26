/* ============================================================
   TalentusPro · Shell JS v1.0
   ------------------------------------------------------------
   Theme + density persistence, dropdown menus, tray, toasts.
   Vanilla JS, no dependencies. Loaded with `defer`.
   ============================================================ */
(function () {
  "use strict";

  var html = document.documentElement;
  var COOKIE_MAX_AGE = 60 * 60 * 24 * 365; // 1 year

  /* ---------- preferences ---------- */
  function setCookie(name, value) {
    try {
      document.cookie =
        name +
        "=" +
        encodeURIComponent(value) +
        "; path=/; max-age=" +
        COOKIE_MAX_AGE +
        "; samesite=lax";
    } catch (e) {}
  }
  function persistPref(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (e) {}
    setCookie(key, value);

    // Persist to backend if user is authed (best-effort, non-blocking)
    if (
      window.Talentus &&
      window.Talentus.routes &&
      window.Talentus.routes.preferences
    ) {
      fetch(window.Talentus.routes.preferences, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": window.Talentus.csrf,
          Accept: "application/json",
        },
        body: JSON.stringify({ key: key, value: value }),
      }).catch(function () {
        /* silent */
      });
    }
  }

  function setTheme(t) {
    if (t !== "light" && t !== "dark") return;
    html.setAttribute("data-theme", t);
    persistPref("tp_theme", t);
  }
  function setDensity(d) {
    if (d !== "compact" && d !== "comfortable") return;
    html.setAttribute("data-density", d);
    persistPref("tp_density", d);
  }

  /* ---------- toggles ---------- */
  var themeBtn = document.getElementById("tp-theme-toggle");
  if (themeBtn) {
    themeBtn.addEventListener("click", function () {
      var current = html.getAttribute("data-theme") || "dark";
      setTheme(current === "dark" ? "light" : "dark");
    });
  }

  document.querySelectorAll("[data-density-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var current = html.getAttribute("data-density") || "comfortable";
      setDensity(current === "comfortable" ? "compact" : "comfortable");
      closeAllMenus();
    });
  });

  /* ---------- generic menu (dropdowns) ---------- */
  function bindMenu(btnId, menuId, opts) {
    var btn = document.getElementById(btnId);
    var menu = document.getElementById(menuId);
    if (!btn || !menu) return;
    opts = opts || {};

    function open() {
      // position relative to button
      var rect = btn.getBoundingClientRect();
      menu.style.position = "fixed";
      menu.style.top = rect.bottom + "px";
      if (opts.alignRight) {
        menu.style.right = window.innerWidth - rect.right + "px";
        menu.style.left = "auto";
      } else {
        menu.style.left = rect.left + "px";
        menu.style.right = "auto";
      }
      menu.hidden = false;
      btn.setAttribute("aria-expanded", "true");
    }
    function close() {
      menu.hidden = true;
      btn.setAttribute("aria-expanded", "false");
    }
    btn.__tpMenuClose = close;

    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      if (menu.hidden) {
        closeAllMenus();
        open();
      } else {
        close();
      }
    });

    document.addEventListener("click", function (e) {
      if (!menu.hidden && !menu.contains(e.target)) close();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !menu.hidden) close();
    });
  }

  function closeAllMenus() {
    ["tp-workspace-btn", "tp-user-btn"].forEach(function (id) {
      var b = document.getElementById(id);
      if (b && typeof b.__tpMenuClose === "function") b.__tpMenuClose();
    });
  }

  bindMenu("tp-workspace-btn", "tp-workspace-menu", { alignRight: false });
  bindMenu("tp-user-btn", "tp-user-menu", { alignRight: true });

  /* ---------- tray (notifications) ---------- */
  var tray = document.getElementById("tp-notif-tray");
  var trayBtn = document.getElementById("tp-notif-btn");
  function openTray() {
    if (tray) tray.hidden = false;
  }
  function closeTray() {
    if (tray) tray.hidden = true;
  }
  if (trayBtn) trayBtn.addEventListener("click", openTray);
  if (tray) {
    tray.querySelectorAll("[data-tray-close]").forEach(function (el) {
      el.addEventListener("click", closeTray);
    });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && tray && !tray.hidden) closeTray();
  });

  /* ---------- toast API ---------- */
  var host = document.getElementById("tp-toast-host");
  function makeIcon(variant) {
    var icons = {
      success: "✓",
      warning: "⚠",
      danger: "✕",
      critical: "🚨",
      info: "ℹ",
    };
    return icons[variant] || icons.info;
  }
  window.tpToast = function (opts) {
    if (!host) return;
    opts = opts || {};
    var el = document.createElement("div");
    el.className = "tp-toast tp-toast--" + (opts.variant || "info");
    el.setAttribute(
      "role",
      opts.variant === "critical" || opts.variant === "danger"
        ? "alert"
        : "status",
    );
    el.innerHTML =
      '<div class="tp-toast__icon">' +
      makeIcon(opts.variant) +
      "</div>" +
      '<div class="tp-toast__body">' +
      '<div class="tp-toast__title"></div>' +
      (opts.description ? '<div class="tp-toast__desc"></div>' : "") +
      "</div>" +
      '<button class="tp-toast__close" aria-label="Cerrar">✕</button>';
    el.querySelector(".tp-toast__title").textContent = opts.title || "";
    if (opts.description)
      el.querySelector(".tp-toast__desc").textContent = opts.description;

    var dismiss = function () {
      el.classList.add("is-leaving");
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 220);
    };
    el.querySelector(".tp-toast__close").addEventListener("click", dismiss);
    host.appendChild(el);
    if (opts.duration !== 0) setTimeout(dismiss, opts.duration || 4500);
    return dismiss;
  };

  /* ---------- live status placeholder updater ---------- */
  // Stub: replace with real socket data binding in Phase 2.
  function updateLiveStatus(counts) {
    ["moving", "idle", "offline"].forEach(function (key) {
      document
        .querySelectorAll('[data-live-count="' + key + '"]')
        .forEach(function (el) {
          el.textContent = counts[key] != null ? counts[key] : "0";
        });
    });
  }
  window.tpUpdateLiveStatus = updateLiveStatus;
  // Provide sensible default zeros so the topbar doesn't show "—" forever.
  updateLiveStatus({ moving: 0, idle: 0, offline: 0 });

  /* ---------- Expose helpers ---------- */
  window.Talentus = window.Talentus || {};
  window.Talentus.setTheme = setTheme;
  window.Talentus.setDensity = setDensity;
  window.Talentus.toast = window.tpToast;
})();
