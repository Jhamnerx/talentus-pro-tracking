/* =====================================================
 * TalentusPro · tp-select · Custom select 100% Tailwind
 * Sin dependencias. Reemplaza los <select> nativos (cuyo
 * dropdown pinta el SO y desalinea el UI) por un componente
 * propio: botón + panel de opciones estilados con utilidades
 * Tailwind (tailwind.config.js escanea este archivo).
 *
 * - Enhancea todo `select.tp-input` / `select.tp-select`
 *   (incluye los inyectados por AJAX vía MutationObserver).
 * - El <select> original queda oculto y SINCRONIZADO: el código
 *   existente que lee .value o escucha 'change' sigue igual.
 * - Buscador integrado cuando hay más de 8 opciones.
 * - Teclado: Enter/Espacio abre, ↑/↓ navega, Enter elige, Esc cierra.
 * - Opt-out: data-no-ts="1". Los <select multiple> no se tocan.
 *
 *   window.tpSelect.enhance(sel)  — enhancea un select
 *   window.tpSelect.init(root)    — enhancea un contenedor
 *   window.tpSelect.refresh(sel)  — re-lee <option> (tras cambiarlos por JS)
 * ===================================================== */
(function () {
  "use strict";

  var SELECTOR = "select.tp-input, select.tp-select";

  /* ---------- Clases Tailwind (dark: sigue <html data-theme="dark">) ---------- */
  var C = {
    wrap: "tp-dd relative min-w-0",
    btn:
      // appearance-none + border-solid: sin preflight, el <button> nativo
      // conserva border-style outset (borde inferior/derecho más oscuro).
      "tp-dd__btn appearance-none w-full h-9 pl-3 pr-8 rounded-lg border border-solid border-slate-300 bg-white " +
      "text-[13px] text-slate-800 text-left truncate cursor-pointer relative " +
      "transition-colors hover:border-slate-400 " +
      "focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 " +
      "disabled:opacity-60 disabled:cursor-not-allowed " +
      "dark:bg-slate-800 dark:border-slate-600 dark:text-slate-100 dark:hover:border-slate-500",
    chev:
      "tp-dd__chev pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 " +
      "text-slate-500 transition-transform duration-150 dark:text-slate-400",
    // El panel vive como PORTAL en document.body (position:fixed): así nunca
    // lo recorta un contenedor con overflow (cards, modales) ni altera el
    // layout de tablas al abrirse.
    panel:
      "tp-dd__panel fixed z-[5000] rounded-xl w-max max-w-[18rem] " +
      "border border-solid border-slate-200 bg-white shadow-xl shadow-slate-900/15 " +
      "p-1.5 max-h-64 overflow-y-auto overscroll-contain " +
      "dark:bg-slate-800 dark:border-slate-600 dark:shadow-black/40",
    searchWrap: "tp-dd__search sticky top-0 z-10 px-1.5 pb-1.5 pt-0.5 bg-inherit",
    search:
      "w-full h-8 px-2.5 rounded-md border border-solid border-slate-200 bg-slate-50 text-[13px] " +
      "text-slate-800 placeholder:text-slate-400 " +
      "focus:outline-none focus:border-brand-500 " +
      "dark:bg-slate-700 dark:border-slate-600 dark:text-slate-100",
    opt:
      "tp-dd__opt flex items-center gap-2 px-2.5 py-2 rounded-lg text-[13px] " +
      "text-slate-700 cursor-pointer select-none dark:text-slate-200",
    optHover: ["bg-slate-100", "dark:bg-slate-700"],
    optSelected:
      "is-selected font-semibold text-slate-900 ring-1 ring-inset ring-brand-400 " +
      "dark:text-white dark:ring-brand-500",
    optGroup:
      "px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide " +
      "text-slate-400 dark:text-slate-500",
    empty: "px-3 py-2.5 text-[13px] text-slate-400 dark:text-slate-500",
  };

  var CHECK =
    '<svg class="shrink-0 text-brand-600 dark:text-brand-400" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
  // hueco para alinear las opciones no seleccionadas con la seleccionada
  var CHECK_PAD = '<span class="shrink-0 w-[14px]"></span>';
  var CHEVRON =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  var openInst = null; // solo un panel abierto a la vez

  function closeOpen() {
    if (openInst) openInst.close();
  }
  document.addEventListener("click", function (e) {
    if (openInst && !openInst.wrap.contains(e.target) && !openInst.panel.contains(e.target)) closeOpen();
  });
  document.addEventListener(
    "keydown",
    function (e) {
      if (e.key === "Escape" && openInst) {
        closeOpen();
        e.stopPropagation();
      }
    },
    true,
  );

  function Dd(sel) {
    var self = this;
    this.sel = sel;
    this.searchable = false;
    this.activeIdx = -1;

    var wrap = document.createElement("div");
    wrap.className = C.wrap;
    this.wrap = wrap;

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = C.btn;
    btn.setAttribute("role", "combobox");
    btn.setAttribute("aria-haspopup", "listbox");
    btn.setAttribute("aria-expanded", "false");
    this.btn = btn;

    var chev = document.createElement("span");
    chev.className = C.chev;
    chev.innerHTML = CHEVRON;
    // La chevron va DENTRO del botón para heredar estados
    btn.appendChild(document.createElement("span")); // label
    btn.firstChild.className = "block truncate";
    btn.appendChild(chev);

    var panel = document.createElement("div");
    panel.className = C.panel;
    panel.setAttribute("role", "listbox");
    panel.hidden = true;
    this.panel = panel;

    // Ancho: hereda el del select (los full ocupan todo; los pequeños, lo suyo
    // + margen extra porque nuestro botón reserva más espacio para el chevron).
    // Un width inline explícito manda SIEMPRE (cubre selects ocultos al
    // enhancear, donde offsetWidth es 0 y el heurístico no puede medir).
    var cs = window.getComputedStyle(sel);
    var w = sel.offsetWidth;
    var parentW = sel.parentNode ? sel.parentNode.clientWidth : 0;
    if (sel.style.width) {
      wrap.style.width = sel.style.width;
      wrap.classList.add("inline-block");
    } else if (w > 0 && parentW > 0 && w < parentW - 8) {
      wrap.style.width = Math.max(w + 26, 68) + "px";
      wrap.classList.add("inline-block");
    }
    if (cs.margin && cs.margin !== "0px") wrap.style.margin = cs.margin;

    sel.style.display = "none";
    sel.setAttribute("data-ts-enhanced", "1");
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    wrap.appendChild(btn);
    // PORTAL: el panel se monta fuera del flujo (position:fixed) para no
    // quedar recortado por overflow de cards ni empujar el layout. Dentro de
    // un <dialog> (top layer) debe colgar del propio dialog: un hijo de body
    // se pintaría DEBAJO del modal por más z-index que tenga.
    (sel.closest("dialog") || document.body).appendChild(panel);
    sel._tpdd = this;
    this._reposition = this.position.bind(this);

    btn.disabled = sel.disabled;

    // El <select> nativo se habilita/deshabilita por JS mucho despues de
    // montarse el control (paneles del form de sensor, bloques data-enable,
    // etc.). Sin observar el atributo, el boton se quedaba con el estado del
    // primer render: el select se veia bloqueado para siempre aunque el
    // nativo ya estuviera habilitado.
    this._mo = new MutationObserver(function () {
      btn.disabled = sel.disabled;
    });
    this._mo.observe(sel, { attributes: true, attributeFilter: ["disabled"] });

    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      self.isOpen() ? self.close() : self.open();
    });
    btn.addEventListener("keydown", function (e) {
      if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        e.preventDefault();
        if (!self.isOpen()) self.open();
        self.move(e.key === "ArrowDown" ? 1 : -1);
      } else if ((e.key === "Enter" || e.key === " ") && self.isOpen()) {
        e.preventDefault();
        self.chooseActive();
      }
    });
    panel.addEventListener("click", function (e) {
      var o = e.target.closest("[data-dd-val]");
      if (!o) return;
      e.stopPropagation();
      self.choose(o.getAttribute("data-dd-val"));
    });
    panel.addEventListener("mousemove", function (e) {
      var o = e.target.closest("[data-dd-val]");
      if (!o) return;
      var rows = self.rows();
      var idx = rows.indexOf(o);
      if (idx !== -1 && idx !== self.activeIdx) self.setActive(idx);
    });

    // Si otro código cambia sel.value y dispara 'change', reflejarlo
    sel.addEventListener("change", function () {
      self.syncLabel();
      if (self.isOpen()) self.renderOptions(self.query());
    });

    this.build();
  }

  Dd.prototype.opts = function () {
    // [{value,label,group,disabled,selected}]
    var out = [];
    Array.prototype.forEach.call(this.sel.children, function (node) {
      if (node.tagName === "OPTGROUP") {
        Array.prototype.forEach.call(node.children, function (o) {
          out.push({ value: o.value, label: o.text, group: node.label, disabled: o.disabled, selected: o.selected });
        });
      } else if (node.tagName === "OPTION") {
        out.push({ value: node.value, label: node.text, group: null, disabled: node.disabled, selected: node.selected });
      }
    });
    return out;
  };

  Dd.prototype.build = function () {
    // Modo "creatable" (opt-in data-ts-create="1"): permite escribir un valor
    // libre que no está en la lista (paridad legacy data-custom-input). Fuerza
    // el buscador para poder teclear el valor.
    this.creatable = this.sel.getAttribute("data-ts-create") === "1";
    this.searchable = this.creatable || this.opts().length > 8;
    this.panel.innerHTML =
      (this.searchable
        ? '<div class="' + C.searchWrap + '"><input type="text" class="' + C.search + '" data-dd-search autocomplete="off"></div>'
        : "") + '<div data-dd-list></div>';
    this.searchEl = this.panel.querySelector("[data-dd-search]");
    if (this.searchEl) {
      var self = this;
      this.searchEl.addEventListener("input", function () {
        self.renderOptions(self.query());
        self.setActive(self.rows().length ? 0 : -1);
      });
      this.searchEl.addEventListener("keydown", function (e) {
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
          e.preventDefault();
          self.move(e.key === "ArrowDown" ? 1 : -1);
        } else if (e.key === "Enter") {
          e.preventDefault();
          self.chooseActive();
        }
      });
    }
    this.renderOptions("");
    this.syncLabel();
  };

  Dd.prototype.query = function () {
    return this.searchEl ? this.searchEl.value.trim().toLowerCase() : "";
  };

  Dd.prototype.renderOptions = function (q) {
    var list = this.panel.querySelector("[data-dd-list]");
    var cur = this.sel.value;
    var html = "";
    var lastGroup = null;
    var exact = false;
    this.opts().forEach(function (o) {
      if (q && o.label.toLowerCase().indexOf(q) === -1) return;
      if (q && o.label.toLowerCase() === q) exact = true;
      if (o.group && o.group !== lastGroup) {
        html += '<div class="' + C.optGroup + '">' + esc(o.group) + "</div>";
        lastGroup = o.group;
      }
      var isSel = String(o.value) === String(cur);
      html +=
        '<div class="' + C.opt + (isSel ? " " + C.optSelected : "") + (o.disabled ? " opacity-50 pointer-events-none" : "") +
        '" role="option" aria-selected="' + isSel + '" data-dd-val="' + esc(o.value) + '">' +
        (isSel ? CHECK : CHECK_PAD) +
        '<span class="truncate">' + esc(o.label) + "</span></div>";
    });
    // Creatable: fila para usar el texto tecleado como valor libre (si no existe).
    if (this.creatable && q && !exact) {
      var raw = this.searchEl ? this.searchEl.value.trim() : q;
      html =
        '<div class="' + C.opt + '" role="option" data-dd-val="' + esc(raw) + '">' +
        CHECK_PAD + '<span class="truncate">+ ' + esc('Usar "' + raw + '"') + "</span></div>" + html;
    }
    list.innerHTML = html || '<div class="' + C.empty + '">—</div>';
    this.activeIdx = -1;
  };

  Dd.prototype.rows = function () {
    return Array.prototype.slice.call(this.panel.querySelectorAll("[data-dd-val]"));
  };

  Dd.prototype.setActive = function (idx) {
    var rows = this.rows();
    var self = this;
    rows.forEach(function (r, i) {
      C.optHover.forEach(function (cls) {
        r.classList.toggle(cls, i === idx && !r.classList.contains("is-selected"));
      });
    });
    this.activeIdx = idx;
    if (idx >= 0 && rows[idx]) rows[idx].scrollIntoView({ block: "nearest" });
  };

  Dd.prototype.move = function (dir) {
    var rows = this.rows();
    if (!rows.length) return;
    var idx = this.activeIdx + dir;
    if (idx < 0) idx = rows.length - 1;
    if (idx >= rows.length) idx = 0;
    this.setActive(idx);
  };

  Dd.prototype.chooseActive = function () {
    var rows = this.rows();
    var row = rows[this.activeIdx] || this.panel.querySelector("[data-dd-val].is-selected") || rows[0];
    if (row) this.choose(row.getAttribute("data-dd-val"));
  };

  Dd.prototype.hasOption = function (value) {
    return this.opts().some(function (o) {
      return String(o.value) === String(value);
    });
  };

  Dd.prototype.choose = function (value) {
    var sel = this.sel;
    // Creatable: si el valor tecleado no existe como opción, se añade (valor libre).
    if (this.creatable && value !== "" && !this.hasOption(value)) {
      var opt = document.createElement("option");
      opt.value = value;
      opt.textContent = value;
      sel.appendChild(opt);
    }
    if (String(sel.value) !== String(value)) {
      sel.value = value;
      sel.dispatchEvent(new Event("change", { bubbles: true }));
    }
    this.syncLabel();
    this.close();
    this.btn.focus();
  };

  Dd.prototype.syncLabel = function () {
    var o = this.sel.selectedOptions && this.sel.selectedOptions[0];
    this.btn.firstChild.textContent = o ? o.text : "";
    this.btn.disabled = this.sel.disabled;
  };

  Dd.prototype.isOpen = function () {
    return !this.panel.hidden;
  };

  /** Posiciona el panel (fixed) pegado al botón; hacia arriba si no cabe. */
  Dd.prototype.position = function () {
    if (this.panel.hidden) return;
    var r = this.btn.getBoundingClientRect();
    var p = this.panel;
    p.style.minWidth = r.width + "px";
    p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - p.offsetWidth - 8)) + "px";
    var below = r.bottom + 4;
    if (below + p.offsetHeight > window.innerHeight - 8 && r.top - p.offsetHeight - 4 > 8) {
      p.style.top = r.top - p.offsetHeight - 4 + "px"; // hacia arriba
    } else {
      p.style.top = below + "px";
    }
  };

  Dd.prototype.open = function () {
    if (openInst && openInst !== this) openInst.close();
    openInst = this;
    if (this.searchEl) this.searchEl.value = "";
    this.renderOptions("");
    this.panel.hidden = false;
    this.position();
    this.btn.setAttribute("aria-expanded", "true");
    this.btn.querySelector(".tp-dd__chev").classList.add("rotate-180");
    // Scroll de un contenedor EXTERNO → cerrar (comportamiento nativo de un
    // select): el panel es fixed y si no, queda flotando sobre el contenido.
    // El scroll interno del propio panel no lo cierra.
    var self = this;
    this._onOuterScroll = function (e) {
      if (self.panel.contains(e.target)) return;
      self.close();
    };
    window.addEventListener("scroll", this._onOuterScroll, true);
    window.addEventListener("resize", this._reposition);
    if (this.searchEl) this.searchEl.focus();
    var selRow = this.panel.querySelector("[data-dd-val].is-selected");
    if (selRow) selRow.scrollIntoView({ block: "nearest" });
  };

  Dd.prototype.close = function () {
    this.panel.hidden = true;
    this.btn.setAttribute("aria-expanded", "false");
    this.btn.querySelector(".tp-dd__chev").classList.remove("rotate-180");
    if (this._onOuterScroll) {
      window.removeEventListener("scroll", this._onOuterScroll, true);
      this._onOuterScroll = null;
    }
    window.removeEventListener("resize", this._reposition);
    if (openInst === this) openInst = null;
  };

  Dd.prototype.destroy = function () {
    this.close();
    if (this._mo) this._mo.disconnect();
    if (this.panel.parentNode) this.panel.parentNode.removeChild(this.panel);
    if (this.wrap.parentNode) this.wrap.parentNode.removeChild(this.wrap);
    this.sel.style.display = "";
    this.sel.removeAttribute("data-ts-enhanced");
    delete this.sel._tpdd;
  };

  /* ---------- API ---------- */
  function enhance(sel) {
    if (!sel || sel._tpdd || sel.multiple) return;
    if (sel.getAttribute("data-no-ts") === "1") return;
    if (!sel.parentNode) return;
    try {
      new Dd(sel);
    } catch (e) {}
  }

  function init(root) {
    (root || document).querySelectorAll(SELECTOR).forEach(enhance);
  }

  window.tpSelect = {
    enhance: enhance,
    init: init,
    refresh: function (sel) {
      if (sel && sel._tpdd) sel._tpdd.destroy();
      enhance(sel);
    },
  };

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    init(document);
    var mo = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (n.nodeType !== 1) continue;
          if (n.matches && n.matches(SELECTOR)) enhance(n);
          if (n.querySelectorAll) n.querySelectorAll(SELECTOR).forEach(enhance);
        }
      }
    });
    mo.observe(document.body, { childList: true, subtree: true });
  });
})();
