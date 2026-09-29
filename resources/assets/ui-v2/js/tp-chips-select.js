/* =====================================================
 * TalentusPro · tp-chips-select — selector múltiple con chips
 * -----------------------------------------------------
 * El selector de vehiculos (y de cualquier lista larga) de la UI v2: chips de
 * lo elegido, buscador, paginado y seleccionar / quitar todos.
 *
 * Vivia dentro de reports.js y solo lo tenia el modal de reportes. Un <select
 * multiple> del navegador no sirve con 1.400 vehiculos: no se busca, no se ve
 * lo elegido y hay que hacer ctrl+clic. Al sacarlo aqui, cualquier pantalla
 * que necesite elegir vehiculos usa EL MISMO selector.
 *
 * Uso:
 *   var sel = tpChipsSelect({
 *     mount: nodoDondeSeInserta,
 *     items: [{id, name, group_id?}],
 *     groups: {gid: titulo} | null,   // agrupado: no se pagina
 *     selected: [ids],
 *     placeholder: 'Todos los vehiculos',
 *     host: document.body              // donde cuelga el panel flotante
 *   });
 *   sel.getIds();          // ids elegidos
 *   sel.setDisabled(true); // se atenua, no se oculta
 *   sel.root;              // la caja, por si hay que medirla o moverla
 * ===================================================== */
(function () {
  "use strict";

  function esc(s) {
    return window.tpModal && tpModal.escapeHtml
      ? tpModal.escapeHtml(s)
      : String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
          return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
  }

  function chipsSelect(opts) {
    var items = opts.items || []; // [{id,name,group_id?}]
    var groups = opts.groups || null; // {gid:title} | null
    var selected = {};
    (opts.selected || []).forEach(function (i) {
      selected[i] = true;
    });
    var byId = {};
    items.forEach(function (d) {
      byId[d.id] = d.name;
    });

    // Paginado, como el selector del legacy: con 1.400 vehiculos volcar la
    // lista entera en el panel lo vuelve inmanejable.
    var POR_PAGINA = 100;
    var pagina = 1;
    var deshabilitado = false;

    var box = document.createElement("div");
    box.className = "tp-input";
    box.tabIndex = 0;
    box.style.cssText =
      "height:auto;min-height:36px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;" +
      "padding:4px 34px 4px 8px;cursor:pointer;position:relative;";
    box.innerHTML =
      '<span data-cs-ph style="color:var(--fg-muted,#94a3b8);font-size:13px;padding-left:4px;">' +
      esc(opts.placeholder || "Seleccionar…") + "</span>" +
      '<svg style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#64748b;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    opts.mount.appendChild(box);

    var panel = document.createElement("div");
    panel.className =
      "tp-dd__panel fixed z-[5000] rounded-xl w-max max-w-[30rem] " +
      "border border-solid border-slate-200 bg-white shadow-xl shadow-slate-900/15 " +
      "p-1.5 max-h-64 overflow-y-auto overscroll-contain " +
      "dark:bg-slate-800 dark:border-slate-600 dark:shadow-black/40";
    panel.hidden = true;
    (opts.host || document.body).appendChild(panel);

    function renderChips() {
      Array.prototype.forEach.call(box.querySelectorAll("[data-cs-chip]"), function (c) {
        c.remove();
      });
      var ids = Object.keys(selected);
      box.querySelector("[data-cs-ph]").style.display = ids.length ? "none" : "";
      ids.forEach(function (i) {
        var chip = document.createElement("span");
        chip.setAttribute("data-cs-chip", i);
        chip.style.cssText =
          "display:inline-flex;align-items:center;gap:6px;background:var(--brand-bg-subtle,#eef3fb);" +
          "border:1px solid var(--border-default,#cbd5e1);border-radius:999px;padding:3px 10px;" +
          "font-size:12px;font-weight:500;color:var(--fg-primary,#0f172a);line-height:1.3;";
        chip.innerHTML =
          esc(byId[i] || "#" + i) +
          '<button type="button" data-cs-un="' + i + '" style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--fg-muted,#94a3b8);padding:0;line-height:1;font-size:13px;">×</button>';
        box.insertBefore(chip, box.lastElementChild);
      });
    }

    /** Los que pasan la busqueda: sobre ellos actuan paginado y los botones. */
    function filtrados(q) {
      q = (q || "").toLowerCase();

      return items.filter(function (d) {
        return !q || d.name.toLowerCase().indexOf(q) >= 0;
      });
    }

    function renderPanel(q, mantenerPagina) {
      q = (q || "").toLowerCase();
      if (!mantenerPagina) pagina = 1;

      var coincidencias = filtrados(q);
      var paginas = Math.max(1, Math.ceil(coincidencias.length / POR_PAGINA));
      if (pagina > paginas) pagina = paginas;

      // Sin agrupar se pagina; agrupado se respetan los grupos enteros, que
      // partir un grupo por la mitad no ayuda a nadie.
      var visibles = groups ? coincidencias : coincidencias.slice((pagina - 1) * POR_PAGINA, pagina * POR_PAGINA);
      var enPagina = {};
      visibles.forEach(function (d) { enPagina[d.id] = true; });

      var body = "";

      function rows(list) {
        return list
          .filter(function (d) {
            return enPagina[d.id];
          })
          .map(function (d) {
            return (
              '<label style="display:flex;align-items:center;gap:9px;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:13px;">' +
              '<input type="checkbox" class="tp-choice-input" data-cs-opt="' + d.id + '"' +
              (selected[d.id] ? " checked" : "") + ">" +
              "<span>" + esc(d.name) + "</span></label>"
            );
          })
          .join("");
      }

      if (groups) {
        var byGroup = {};
        coincidencias.forEach(function (d) {
          var gid = d.group_id || 0;
          (byGroup[gid] = byGroup[gid] || []).push(d);
        });
        Object.keys(byGroup).forEach(function (gid) {
          var r = rows(byGroup[gid]);
          if (!r) return;
          body +=
            '<div style="border:1px solid var(--border-default,#eef2f7);border-radius:10px;margin:0 4px 8px;overflow:hidden;">' +
            '<div style="padding:6px 10px;background:var(--surface-sunken,#f8fafc);font-size:12px;font-weight:600;color:var(--fg-secondary,#475569);">' +
            esc(groups[gid] || "Sin grupo") + "</div>" +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;padding:4px 6px;">' + r + "</div></div>";
        });
      } else {
        body = rows(coincidencias);
      }

      var BTN =
        "appearance:none;cursor:pointer;flex:1;border:1px solid var(--border-default,#cbd5e1);" +
        "background:var(--surface-raised,#fff);border-radius:8px;padding:5px 10px;font-size:12.5px;" +
        "font-weight:600;color:var(--fg-secondary,#475569);";

      panel.innerHTML =
        '<div style="position:sticky;top:0;background:inherit;padding:2px 4px 6px;z-index:1;">' +
        '<input type="search" data-cs-search placeholder="Búsqueda" autocomplete="off" ' +
        'style="width:100%;height:30px;padding:0 10px;border:1px solid var(--border-default,#e2e8f0);border-radius:8px;font-size:13px;background:var(--surface-sunken,#f8fafc);">' +
        // Accesos del legacy: marcar y desmarcar de golpe. Actuan sobre lo
        // que la busqueda deja a la vista, no sobre toda la flota, que es lo
        // que se espera al haber filtrado antes.
        '<div style="display:flex;gap:6px;margin-top:6px;">' +
        '<button type="button" data-cs-all style="' + BTN + '">Seleccionar todo</button>' +
        '<button type="button" data-cs-none style="' + BTN + '">Deseleccionar todo</button>' +
        "</div></div>" +
        (body || '<div style="padding:8px 10px;font-size:13px;color:var(--fg-muted,#94a3b8);">Sin resultados.</div>') +
        '<div style="position:sticky;bottom:0;background:inherit;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 6px 4px;border-top:1px solid var(--border-default,#eef2f7);z-index:1;">' +
        '<span style="display:flex;gap:4px;align-items:center;">' + paginacionHtml(paginas) + "</span>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-cs-close>' + tpT('global.close') + '</button></div>';

      var buscador = panel.querySelector("[data-cs-search]");
      if (buscador) buscador.value = q;
    }

    /** Primera, alrededor de la actual y ultima: con 15 paginas no caben todas. */
    function paginacionHtml(paginas) {
      if (paginas < 2) return "";

      var nums = [];
      [1, pagina - 1, pagina, pagina + 1, paginas].forEach(function (n) {
        if (n >= 1 && n <= paginas && nums.indexOf(n) < 0) nums.push(n);
      });
      nums.sort(function (a, b) { return a - b; });

      return nums
        .map(function (n) {
          var activa = n === pagina;
          return (
            '<button type="button" data-cs-page="' + n + '" style="appearance:none;cursor:pointer;' +
            "min-width:26px;height:26px;border-radius:7px;font-size:12px;font-weight:600;padding:0 6px;" +
            (activa
              ? "border:1px solid var(--brand-500,#2e63d9);background:var(--brand-500,#2e63d9);color:#fff;"
              : "border:1px solid var(--border-default,#cbd5e1);background:var(--surface-raised,#fff);color:var(--fg-secondary,#475569);") +
            '">' + n + "</button>"
          );
        })
        .join("");
    }

    function position() {
      var r = box.getBoundingClientRect();
      panel.style.minWidth = r.width + "px";
      panel.style.left = Math.max(8, Math.min(r.left, window.innerWidth - panel.offsetWidth - 8)) + "px";
      var below = r.bottom + 4;
      if (below + panel.offsetHeight > window.innerHeight - 8 && r.top - panel.offsetHeight - 4 > 8) {
        panel.style.top = r.top - panel.offsetHeight - 4 + "px";
      } else {
        panel.style.top = below + "px";
      }
    }

    box.addEventListener("click", function (e) {
      var un = e.target.closest("[data-cs-un]");
      if (un) {
        delete selected[un.getAttribute("data-cs-un")];
        renderChips();
        e.stopPropagation();
        return;
      }
      if (panel.hidden) {
        renderPanel("");
        panel.hidden = false;
        position();
        var s = panel.querySelector("[data-cs-search]");
        if (s) s.focus();
      } else {
        panel.hidden = true;
      }
    });

    panel.addEventListener("change", function (e) {
      var opt = e.target.closest("[data-cs-opt]");
      if (!opt) return;

      var did = opt.getAttribute("data-cs-opt");
      if (opt.checked) selected[did] = true;
      else delete selected[did];
      renderChips();
    });

    panel.addEventListener("click", function (e) {
      if (e.target.closest("[data-cs-close]")) {
        panel.hidden = true;
        return;
      }

      var pg = e.target.closest("[data-cs-page]");
      if (pg) {
        pagina = parseInt(pg.getAttribute("data-cs-page"), 10) || 1;
        renderPanel(consulta(), true);
        position();
        return;
      }

      // Marcar / desmarcar de golpe lo que la busqueda deja a la vista.
      var todos = e.target.closest("[data-cs-all]");
      var ninguno = e.target.closest("[data-cs-none]");
      if (!todos && !ninguno) return;

      filtrados(consulta()).forEach(function (d) {
        if (todos) selected[d.id] = true;
        else delete selected[d.id];
      });

      Array.prototype.forEach.call(panel.querySelectorAll("[data-cs-opt]"), function (c) {
        c.checked = !!todos;
      });
      renderChips();
    });

    /** Lo que hay escrito ahora mismo en el buscador del panel. */
    function consulta() {
      var s = panel.querySelector("[data-cs-search]");
      return s ? s.value : "";
    }

    panel.addEventListener("input", function (e) {
      if (e.target.matches("[data-cs-search]")) {
        var q = e.target.value;
        renderPanel(q);
        position();
        var s = panel.querySelector("[data-cs-search]");
        s.focus();
        s.setSelectionRange(q.length, q.length);
      }
    });

    document.addEventListener("mousedown", function (e) {
      if (panel.hidden) return;
      if (panel.contains(e.target) || box.contains(e.target)) return;
      panel.hidden = true;
    });

    renderChips();

    /**
     * Deshabilitar, no ocultar: es lo que hace el legacy con los campos que
     * no aplican al tipo de reporte elegido. Ocultarlos movia el formulario
     * entero al cambiar de tipo y escondia opciones que el usuario esperaba
     * encontrar siempre en el mismo sitio.
     */
    function setDisabled(v) {
      deshabilitado = !!v;
      box.style.opacity = deshabilitado ? "0.55" : "";
      box.style.pointerEvents = deshabilitado ? "none" : "";
      box.tabIndex = deshabilitado ? -1 : 0;
      if (deshabilitado) panel.hidden = true;
    }

    return {
      getIds: function () {
        return deshabilitado ? [] : Object.keys(selected);
      },
      setDisabled: setDisabled,
      isDisabled: function () {
        return deshabilitado;
      },
      root: box,
    };
  }

  window.tpChipsSelect = chipsSelect;
})();
