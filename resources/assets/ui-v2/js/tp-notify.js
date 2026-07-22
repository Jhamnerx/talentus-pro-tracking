/* =====================================================
 * TalentusPro · tp-notify — notificaciones de eventos EN VIVO
 * -----------------------------------------------------
 * Paridad con el legacy (EventSys.notice()/sound() + Notifications()):
 *  - POLLING a ui.v2.api.events.live (mismo mecanismo que el legacy:
 *    no hay socket para eventos; el backend acota la ventana a 5 min
 *    y solo devuelve alertas con el canal popup activo y no
 *    silenciadas). El "visto" = avanzar el timestamp (localStorage,
 *    sobrevive la navegación entre páginas → sin popups duplicados).
 *  - POPUP: toast rico apilado (color de la alerta, mensaje, device,
 *    geocerca, velocidad); auto-cierre según `delay` de la alerta
 *    (0 = fijo hasta cerrarlo). Click → página de Eventos.
 *  - SONIDO: new Audio(ev.sound) — la URL del mp3 llega resuelta del
 *    backend (AlertSoundService) según la config de la alerta.
 *  - Notification API del navegador (permiso pedido al cargar, como
 *    el legacy; si está denegado simplemente no se usa).
 * Se carga global en el layout: funciona en TODAS las páginas v2.
 * ===================================================== */
(function () {
  "use strict";

  var T = window.Talentus || {};
  var url = T.routes && T.routes.eventsLive;
  if (!url) return;

  var POLL_MS = 15000;
  var MAX_TOASTS = 6;
  var LS_KEY = "tp_notify_time";

  var seenIds = {};
  var host = null;

  function getTime() {
    try {
      return parseInt(localStorage.getItem(LS_KEY), 10) || 0;
    } catch (e) {
      return 0;
    }
  }

  function setTime(t) {
    try {
      localStorage.setItem(LS_KEY, String(t));
    } catch (e) {}
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function ensureHost() {
    if (host) return host;
    host = document.createElement("div");
    host.id = "tp-notify-host";
    host.style.cssText =
      "position:fixed;top:64px;right:16px;z-index:6000;display:flex;flex-direction:column;gap:10px;" +
      "width:340px;max-width:calc(100vw - 32px);pointer-events:none;";
    document.body.appendChild(host);
    return host;
  }

  function showToast(ev) {
    var h = ensureHost();
    while (h.children.length >= MAX_TOASTS) h.removeChild(h.firstChild);

    var accent = ev.color || "#2e63d9";
    var parts = [];
    if (ev.device && ev.device.name) parts.push(esc(ev.device.name));
    if (ev.geofence && ev.geofence.name) parts.push(esc(ev.geofence.name));
    if (ev.speed != null && ev.speed !== "") parts.push(esc(ev.speed));

    var el = document.createElement("div");
    el.style.cssText =
      "pointer-events:auto;display:flex;gap:10px;align-items:flex-start;cursor:pointer;" +
      "background:var(--surface-raised,#fff);color:var(--fg-primary,#0f172a);" +
      "border:1px solid var(--border-default,#e2e8f0);border-left:4px solid " + accent + ";" +
      "border-radius:12px;padding:10px 12px;box-shadow:0 10px 30px rgba(15,23,42,.18);" +
      "font-size:13px;line-height:1.45;";
    el.innerHTML =
      '<div style="flex:1;min-width:0;">' +
      '<div style="font-weight:600;">' + esc(ev.message || "") + "</div>" +
      (parts.length ? '<div style="color:var(--fg-secondary,#475569);font-size:12px;margin-top:2px;">' + parts.join(" · ") + "</div>" : "") +
      (ev.time ? '<div style="color:var(--fg-muted,#94a3b8);font-size:11px;margin-top:2px;">' + esc(ev.time) + "</div>" : "") +
      "</div>" +
      '<button type="button" data-tpn-close aria-label="Cerrar" ' +
      'style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--fg-muted,#94a3b8);font-size:15px;line-height:1;padding:2px;">×</button>';

    el.addEventListener("click", function (e) {
      if (e.target.closest("[data-tpn-close]")) {
        el.remove();
        return;
      }
      window.location.href = "/ui/v2/events";
    });

    h.appendChild(el);

    // delay de la alerta: 0 = fijo; 5/10 = segundos (default 5 como el legacy)
    var delay = ev.delay == null ? 5 : parseInt(ev.delay, 10);
    if (delay > 0) {
      setTimeout(function () {
        if (el.parentNode) el.remove();
      }, delay * 1000);
    }
  }

  function playSound(ev) {
    if (!ev.sound) return false;
    try {
      new Audio(ev.sound).play().catch(function () {});
      return true;
    } catch (e) {
      return false;
    }
  }

  function desktopNotify(ev) {
    if (!("Notification" in window) || Notification.permission !== "granted") return;
    try {
      var body = [];
      if (ev.device && ev.device.name) body.push(ev.device.name);
      if (ev.geofence && ev.geofence.name) body.push(ev.geofence.name);
      var n = new Notification(ev.message || "Evento", {
        body: body.join(" · "),
        icon: "/assets/images/marker-icon-2x.png",
      });
      n.onclick = function () {
        window.focus();
      };
    } catch (e) {}
  }

  var polling = false;

  function poll() {
    if (polling || document.hidden) return;
    polling = true;
    var q = url + "?time=" + getTime();
    fetch(q, {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) {
        return r.ok ? r.json() : Promise.reject(r.status);
      })
      .then(function (res) {
        polling = false;
        if (!res || !res.ok) return;
        if (res.time) setTime(res.time);
        var events = res.events || [];
        var soundPlayed = false;
        events.forEach(function (ev) {
          if (!ev || seenIds[ev.id]) return;
          seenIds[ev.id] = true;
          showToast(ev);
          // Un solo sonido por lote para no encimar audios
          if (!soundPlayed) soundPlayed = playSound(ev);
          desktopNotify(ev);
        });
        if (events.length && window.tpNotifications && window.tpNotifications.refresh) {
          window.tpNotifications.refresh(); // refresca el tray al instante
        }
      })
      .catch(function () {
        polling = false;
      });
  }

  function start() {
    // Permiso de Notification API (mismo momento que el legacy: al iniciar)
    if ("Notification" in window && Notification.permission === "default") {
      try {
        Notification.requestPermission();
      } catch (e) {}
    }

    poll();
    setInterval(poll, POLL_MS);
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) poll();
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
