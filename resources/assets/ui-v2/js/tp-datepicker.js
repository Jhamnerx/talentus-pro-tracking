/* =====================================================
 * TalentusPro · tp-datepicker — datepicker propio global
 * -----------------------------------------------------
 * Reemplaza el picker nativo de los input[type="date"] de la v2 por el
 * calendario propio (mismo look del date-range de Historial: mes con
 * semana iniciando en lunes, nombres vía Intl, Borrar/Hoy).
 *
 * Cómo funciona (patrón display + hidden):
 *  - El input original pasa a type="text" readonly y muestra dd/mm/aaaa.
 *  - El name se mueve a un <input hidden> con el valor ISO (YYYY-MM-DD),
 *    así FormData/el backend reciben exactamente lo mismo que antes.
 *  - El estado disabled del visible se replica en el hidden (observer),
 *    para respetar los checkbox habilitadores (data-enable).
 *  - El panel es un PORTAL (fixed) colgado del <dialog> más cercano si lo
 *    hay (top layer) o de <body>; se cierra con click fuera, ESC, scroll
 *    externo o al elegir día — igual que tp-select.
 *
 * API: window.tpDate = { enhance(input), init(root) }
 * Opt-out: data-no-dp="1". Los datetime-local no se tocan.
 * ===================================================== */
(function () {
  "use strict";

  var LOCALE = document.documentElement.lang || "es";

  var MONTHS = (function () {
    try {
      var f = new Intl.DateTimeFormat(LOCALE, { month: "long" });
      var a = [];
      for (var i = 0; i < 12; i++) {
        var n = f.format(new Date(2000, i, 1));
        a.push(n.charAt(0).toUpperCase() + n.slice(1));
      }
      return a;
    } catch (e) {
      return ["01", "02", "03", "04", "05", "06", "07", "08", "09", "10", "11", "12"];
    }
  })();

  var DOWS = (function () {
    try {
      var f = new Intl.DateTimeFormat(LOCALE, { weekday: "short" });
      var a = [];
      // 2024-01-01 fue lunes (la semana inicia en lunes, como el legacy)
      for (var d = 0; d < 7; d++) a.push(f.format(new Date(2024, 0, 1 + d)).slice(0, 2).toUpperCase());
      return a;
    } catch (e) {
      return ["LU", "MA", "MI", "JU", "VI", "SA", "DO"];
    }
  })();

  var ICON_CAL =
    "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cpath d='M16 2v4M8 2v4M3 10h18'/%3E%3C/svg%3E\")";

  var C = {
    panel:
      "tp-dp__panel fixed z-[5000] w-[252px] rounded-xl p-3 " +
      "border border-solid border-slate-200 bg-white shadow-xl shadow-slate-900/15 " +
      "dark:bg-slate-800 dark:border-slate-600 dark:shadow-black/40",
    head: "flex items-center justify-between mb-2",
    navBtn:
      "appearance-none border-0 bg-transparent cursor-pointer rounded-lg w-7 h-7 " +
      "text-slate-500 hover:bg-slate-100 hover:text-slate-800 text-[15px] leading-none " +
      "dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100",
    title: "text-[13px] font-semibold text-slate-800 dark:text-slate-100",
    grid: "grid grid-cols-7 gap-0.5",
    dow: "h-7 flex items-center justify-center text-[10px] font-semibold text-slate-400 dark:text-slate-500",
    day:
      "appearance-none border-0 bg-transparent cursor-pointer h-8 rounded-lg text-[13px] " +
      "text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700",
    daySel: "bg-brand-600 text-white font-semibold hover:bg-brand-600 dark:hover:bg-brand-600",
    dayToday: "ring-1 ring-inset ring-brand-400",
    foot: "flex items-center justify-between mt-2 pt-2 border-t border-solid border-slate-100 dark:border-slate-700",
    link:
      "appearance-none border-0 bg-transparent cursor-pointer p-0 text-[13px] font-semibold " +
      "text-brand-600 hover:underline dark:text-brand-400",
  };

  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function parseIso(s) {
    if (!s || !/^\d{4}-\d{2}-\d{2}/.test(s)) return null;
    var p = s.split("-");
    return new Date(+p[0], +p[1] - 1, +p[2].substring(0, 2));
  }

  function toIso(d) {
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  function toDisplay(iso) {
    var d = parseIso(iso);
    if (!d) return "";
    return pad2(d.getDate()) + "/" + pad2(d.getMonth() + 1) + "/" + d.getFullYear();
  }

  var openInst = null;

  function Dp(input) {
    var self = this;

    this.input = input;

    // name → hidden (valor ISO); el visible es solo display
    var hidden = document.createElement("input");
    hidden.type = "hidden";
    hidden.name = input.name;
    hidden.value = input.value || "";
    hidden.disabled = input.disabled;
    input.removeAttribute("name");
    input.parentNode.insertBefore(hidden, input.nextSibling);
    this.hidden = hidden;

    input.type = "text";
    input.readOnly = true;
    input.placeholder = "dd/mm/aaaa";
    input.value = toDisplay(hidden.value);
    input.autocomplete = "off";
    input.style.cursor = "pointer";
    input.style.backgroundImage = ICON_CAL;
    input.style.backgroundRepeat = "no-repeat";
    input.style.backgroundPosition = "right 10px center";
    input.style.backgroundSize = "16px 16px";
    input.style.paddingRight = "34px";

    // Replicar disabled del visible en el hidden (checkbox habilitadores)
    this._obs = new MutationObserver(function () {
      hidden.disabled = input.disabled;
    });
    this._obs.observe(input, { attributes: true, attributeFilter: ["disabled"] });

    // Panel portal (dialog top-layer si aplica)
    var host = input.closest("dialog") || document.body;
    var panel = document.createElement("div");
    panel.className = C.panel;
    panel.hidden = true;
    host.appendChild(panel);
    this.panel = panel;

    var today = new Date();
    this.calY = today.getFullYear();
    this.calM = today.getMonth();

    input.addEventListener("click", function () {
      if (input.disabled) return;
      self.isOpen() ? self.close() : self.open();
    });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        if (!input.disabled) self.open();
      }
      if (e.key === "Escape") self.close();
    });

    panel.addEventListener("click", function (e) {
      var prev = e.target.closest("[data-dp-prev]");
      var next = e.target.closest("[data-dp-next]");
      var day = e.target.closest("[data-dp-day]");
      var clear = e.target.closest("[data-dp-clear]");
      var todayBtn = e.target.closest("[data-dp-today]");
      if (prev) {
        self.calM--;
        if (self.calM < 0) {
          self.calM = 11;
          self.calY--;
        }
        self.render();
        return;
      }
      if (next) {
        self.calM++;
        if (self.calM > 11) {
          self.calM = 0;
          self.calY++;
        }
        self.render();
        return;
      }
      if (day) {
        self.set(day.getAttribute("data-dp-day"));
        self.close();
        return;
      }
      if (clear) {
        self.set("");
        self.close();
        return;
      }
      if (todayBtn) {
        self.set(toIso(new Date()));
        self.close();
      }
    });

    this._onDocClick = function (e) {
      if (self.panel.hidden) return;
      if (self.panel.contains(e.target) || e.target === self.input) return;
      self.close();
    };
    document.addEventListener("mousedown", this._onDocClick, true);

    input._tpdp = this;
    input.setAttribute("data-dp-enhanced", "1");
  }

  Dp.prototype.isOpen = function () {
    return !this.panel.hidden;
  };

  Dp.prototype.set = function (iso) {
    this.hidden.value = iso || "";
    this.input.value = toDisplay(iso);
    // Notificar como lo haría el input original
    this.hidden.dispatchEvent(new Event("change", { bubbles: true }));
    this.input.dispatchEvent(new Event("change", { bubbles: true }));
  };

  Dp.prototype.render = function () {
    var sel = parseIso(this.hidden.value);
    var now = new Date();
    var todayIso = toIso(now);
    var y = this.calY;
    var m = this.calM;
    var first = new Date(y, m, 1);
    var startDow = (first.getDay() + 6) % 7; // lunes = 0
    var days = new Date(y, m + 1, 0).getDate();

    var html =
      '<div class="' + C.head + '">' +
      '<button type="button" class="' + C.navBtn + '" data-dp-prev>‹</button>' +
      '<span class="' + C.title + '">' + MONTHS[m] + " " + y + "</span>" +
      '<button type="button" class="' + C.navBtn + '" data-dp-next>›</button>' +
      "</div>" +
      '<div class="' + C.grid + '">' +
      DOWS.map(function (d) {
        return '<span class="' + C.dow + '">' + d + "</span>";
      }).join("");

    for (var i = 0; i < startDow; i++) html += "<span></span>";
    for (var d = 1; d <= days; d++) {
      var iso = y + "-" + pad2(m + 1) + "-" + pad2(d);
      var cls = C.day;
      if (sel && iso === toIso(sel)) cls += " " + C.daySel;
      else if (iso === todayIso) cls += " " + C.dayToday;
      html += '<button type="button" class="' + cls + '" data-dp-day="' + iso + '">' + d + "</button>";
    }
    html += "</div>";

    html +=
      '<div class="' + C.foot + '">' +
      '<button type="button" class="' + C.link + '" data-dp-clear>Borrar</button>' +
      '<button type="button" class="' + C.link + '" data-dp-today>Hoy</button>' +
      "</div>";

    this.panel.innerHTML = html;
  };

  Dp.prototype.position = function () {
    var r = this.input.getBoundingClientRect();
    var p = this.panel;
    p.style.minWidth = Math.min(r.width, 252) + "px";
    p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - p.offsetWidth - 8)) + "px";
    var below = r.bottom + 4;
    if (below + p.offsetHeight > window.innerHeight - 8 && r.top - p.offsetHeight - 4 > 8) {
      p.style.top = r.top - p.offsetHeight - 4 + "px";
    } else {
      p.style.top = below + "px";
    }
  };

  Dp.prototype.open = function () {
    if (openInst && openInst !== this) openInst.close();
    openInst = this;
    var sel = parseIso(this.hidden.value);
    var base = sel || new Date();
    this.calY = base.getFullYear();
    this.calM = base.getMonth();
    this.render();
    this.panel.hidden = false;
    this.position();

    var self = this;
    this._onOuterScroll = function (e) {
      if (self.panel.contains(e.target)) return;
      self.close();
    };
    window.addEventListener("scroll", this._onOuterScroll, true);
    window.addEventListener("resize", this._onOuterScroll);
  };

  Dp.prototype.close = function () {
    this.panel.hidden = true;
    if (this._onOuterScroll) {
      window.removeEventListener("scroll", this._onOuterScroll, true);
      window.removeEventListener("resize", this._onOuterScroll);
      this._onOuterScroll = null;
    }
    if (openInst === this) openInst = null;
  };

  /* ---------- API + auto-init ---------- */
  function enhance(input) {
    if (!input || input._tpdp) return;
    if (input.getAttribute("data-no-dp") === "1") return;
    if (!input.parentNode) return;
    try {
      new Dp(input);
    } catch (e) {}
  }

  function init(root) {
    (root || document).querySelectorAll('input[type="date"]').forEach(enhance);
  }

  window.tpDate = { enhance: enhance, init: init };

  // Inputs date insertados por AJAX (modales) — mismo patrón que tp-select
  var mo = new MutationObserver(function (muts) {
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var n = added[j];
        if (n.nodeType !== 1) continue;
        if (n.matches && n.matches('input[type="date"]')) enhance(n);
        else if (n.querySelectorAll) init(n);
      }
    }
  });

  function start() {
    init(document);
    mo.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
