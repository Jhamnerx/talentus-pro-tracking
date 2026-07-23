/* =====================================================
 * TalentusPro · Reportes (rediseño estilo referencia)
 * -----------------------------------------------------
 * Página con tabs: "Reportes generados" (tabla `reports` = definiciones
 * guardadas/programadas; click en fila = abrir/generar) y "Logs de
 * programados" (`report_logs`; click = descargar).
 *
 * Modal Generar/Guardar:
 *  - Título · Periodo (picker de rango con 2 meses + rangos rápidos +
 *    Desde/Hasta con hora, como Historial) · "Controlar días" (pills
 *    Lun–Dom → period_weekdays[]) · Tipo · Formato.
 *  - Campos por tipo (paridad con el modal legacy): el catálogo de
 *    fields habilitados y los PARÁMETROS dinámicos vienen de la ruta
 *    legacy reports/types/{type} (\Field: integer/string/text/checkbox/
 *    select/multiselect/multiselect-group → port de
 *    buildReportAttributes del create.blade legacy).
 *  - Devices (chips agrupados) · Geocercas (chips) · Metas · límite de
 *    velocidad · paradas · checkboxes.
 *  - Enviar a email (chips con "+", se envía como send_to_email con ;)
 *    y programación Diario/Semanal/Mensual con hora (los flags viven en
 *    la entidad Report; el cron reports:daily los procesa).
 *  - GENERAR = GET a la ruta legacy reports/update con generate=1
 *    (+view=1 para HTML) en pestaña nueva; GUARDAR = POST al backend v2
 *    que delega en ReportModalHelper::create() (los devices viajan como
 *    devices[] + secuencia selected_devices[] del EntityLoader).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-reports-page");
  if (!root) return;

  var URLS = {
    meta: root.getAttribute("data-meta-url"),
    saved: root.getAttribute("data-saved-url"),
    logs: root.getAttribute("data-logs-url"),
    item: root.getAttribute("data-item-url"),
    store: root.getAttribute("data-store-url"),
    destroy: root.getAttribute("data-destroy-url"),
    logDestroy: root.getAttribute("data-log-destroy-url"),
  };
  var canCreate = root.getAttribute("data-can-create") === "1";
  var canRemove = root.getAttribute("data-can-remove") === "1";

  var META = null; // catálogos compartidos (tipos, formatos, devices, geocercas…)

  function esc(s) {
    return tpModal.escapeHtml(s);
  }

  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function ymd(d) {
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  function loadMeta() {
    if (META) return Promise.resolve(META);
    return tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) throw new Error("meta");
      META = res.data;
      return META;
    });
  }

  /* ================= Chips multiselect (devices/geocercas/metas) ===== */
  // Caja de chips + panel portal con buscador y checkboxes (agrupado si
  // hay groups). Devuelve {getIds, setIds, root}.
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

    function renderPanel(q) {
      q = (q || "").toLowerCase();
      var body = "";

      function rows(list) {
        return list
          .filter(function (d) {
            return !q || d.name.toLowerCase().indexOf(q) >= 0;
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
        items.forEach(function (d) {
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
        body = rows(items);
      }

      panel.innerHTML =
        '<div style="position:sticky;top:0;background:inherit;padding:2px 4px 6px;z-index:1;">' +
        '<input type="search" data-cs-search placeholder="Búsqueda" autocomplete="off" ' +
        'style="width:100%;height:30px;padding:0 10px;border:1px solid var(--border-default,#e2e8f0);border-radius:8px;font-size:13px;background:var(--surface-sunken,#f8fafc);" value="' + esc(q) + '">' +
        "</div>" +
        (body || '<div style="padding:8px 10px;font-size:13px;color:var(--fg-muted,#94a3b8);">Sin resultados.</div>') +
        '<div style="position:sticky;bottom:0;background:inherit;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 6px 4px;border-top:1px solid var(--border-default,#eef2f7);z-index:1;">' +
        '<label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;">' +
        '<input type="checkbox" class="tp-choice-input" data-cs-all> Seleccionar todo</label>' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-cs-close>Cerrar</button></div>';
      syncAll();
    }

    function syncAll() {
      var all = panel.querySelector("[data-cs-all]");
      if (!all) return;
      var on = Object.keys(selected).length;
      all.checked = items.length > 0 && on === items.length;
      all.indeterminate = on > 0 && on < items.length;
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
      if (opt) {
        var did = opt.getAttribute("data-cs-opt");
        if (opt.checked) selected[did] = true;
        else delete selected[did];
        renderChips();
        syncAll();
        return;
      }
      if (e.target.closest("[data-cs-all]")) {
        var on = e.target.checked;
        items.forEach(function (d) {
          if (on) selected[d.id] = true;
          else delete selected[d.id];
        });
        Array.prototype.forEach.call(panel.querySelectorAll("[data-cs-opt]"), function (c) {
          c.checked = on;
        });
        renderChips();
        syncAll();
      }
    });

    panel.addEventListener("click", function (e) {
      if (e.target.closest("[data-cs-close]")) panel.hidden = true;
    });

    panel.addEventListener("input", function (e) {
      if (e.target.matches("[data-cs-search]")) {
        var q = e.target.value;
        renderPanel(q);
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

    return {
      getIds: function () {
        return Object.keys(selected);
      },
      root: box,
    };
  }

  /* ================= Picker de rango (2 meses + rangos rápidos) ====== */
  var LOCALE = document.documentElement.lang || "es";
  var MONTHS = (function () {
    try {
      var f = new Intl.DateTimeFormat(LOCALE, { month: "short" });
      var a = [];
      for (var i = 0; i < 12; i++) {
        var n = f.format(new Date(2000, i, 1));
        a.push(n.charAt(0).toUpperCase() + n.slice(1).replace(".", ""));
      }
      return a;
    } catch (e) {
      return ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
    }
  })();
  var DOWS = (function () {
    try {
      var f = new Intl.DateTimeFormat(LOCALE, { weekday: "short" });
      var a = [];
      for (var d = 0; d < 7; d++) a.push(f.format(new Date(2024, 0, 1 + d)).slice(0, 2).toUpperCase());
      return a;
    } catch (e) {
      return ["LU", "MA", "MI", "JU", "VI", "SA", "DO"];
    }
  })();

  // range = {from:'YYYY-MM-DD', fromTime:'HH:MM', to:'YYYY-MM-DD', toTime:'HH:MM'}
  function openRangePicker(range, onSet) {
    var dr = {
      from: range.from ? new Date(range.from + "T00:00") : new Date(),
      to: range.to ? new Date(range.to + "T00:00") : new Date(),
      // fin por defecto 23:59 = día completo (00:00 confundía: excluía el último día)
      ft: range.fromTime || "00:00",
      tt: range.toTime || "23:59",
    };
    var base = new Date(dr.from.getFullYear(), dr.from.getMonth(), 1);

    var QUICK = [
      ["today", "Hoy"],
      ["yesterday", "Ayer"],
      ["2days", "Hace 2 días"],
      ["3days", "Hace 3 días"],
      ["week", "Esta semana"],
      ["lastweek", "Semana pasada"],
      ["month", "Este mes"],
      ["lastmonth", "Mes pasado"],
    ];

    var dlg = tpModal.open({
      title: "Seleccionar fecha",
      size: "lg",
      static: true,
      body: '<div data-dr-body></div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Establecer",
          variant: "primary",
          close: true,
          onClick: function (dialog) {
            var ft = dialog.querySelector("[data-dr-fromtime]").value || dr.ft || "00:00";
            var tt = dialog.querySelector("[data-dr-totime]").value || dr.tt || "23:59";
            onSet({ from: ymd(dr.from), fromTime: ft, to: ymd(dr.to), toTime: tt });
          },
        },
      ],
    });

    var body = dlg.querySelector("[data-dr-body]");

    function calHtml(y, m) {
      var first = new Date(y, m, 1);
      var startDow = (first.getDay() + 6) % 7;
      var days = new Date(y, m + 1, 0).getDate();
      var html =
        '<div style="display:flex;justify-content:center;gap:8px;font-size:13px;font-weight:600;margin-bottom:6px;">' +
        MONTHS[m] + " " + y + "</div>" +
        '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;">' +
        DOWS.map(function (d) {
          return '<span style="height:26px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;color:var(--fg-muted,#94a3b8);">' + d + "</span>";
        }).join("");
      for (var i = 0; i < startDow; i++) html += "<span></span>";
      for (var d = 1; d <= days; d++) {
        var cur = new Date(y, m, d);
        var t = cur.getTime();
        var isStart = t === dr.from.getTime();
        var isEnd = t === dr.to.getTime();
        var inRange = t > dr.from.getTime() && t < dr.to.getTime();
        var st = "appearance:none;border:0;cursor:pointer;height:30px;border-radius:8px;font-size:13px;background:transparent;color:var(--fg-primary,#0f172a);";
        if (inRange) st += "background:var(--brand-bg-subtle,#e5edfb);";
        if (isStart || isEnd) st += "background:var(--brand-500,#2e63d9);color:#fff;font-weight:600;";
        html += '<button type="button" data-dr-day="' + ymd(cur) + '" style="' + st + '">' + d + "</button>";
      }
      return html + "</div>";
    }

    function render() {
      var y = base.getFullYear();
      var m = base.getMonth();
      var n = new Date(y, m + 1, 1);
      body.innerHTML =
        '<div style="display:flex;gap:14px;">' +
        '<div style="flex:0 0 130px;display:flex;flex-direction:column;gap:2px;border-right:1px solid var(--border-default,#eef2f7);padding-right:10px;">' +
        QUICK.map(function (q) {
          return '<button type="button" data-dr-quick="' + q[0] + '" style="appearance:none;border:0;background:transparent;cursor:pointer;text-align:left;padding:7px 10px;border-radius:8px;font-size:13px;color:var(--fg-secondary,#475569);">' + q[1] + "</button>";
        }).join("") +
        "</div>" +
        '<div style="flex:1;">' +
        '<div style="display:flex;gap:16px;align-items:flex-start;">' +
        '<button type="button" data-dr-prev style="appearance:none;border:0;background:transparent;cursor:pointer;font-size:16px;color:var(--fg-muted,#94a3b8);padding:4px;">‹</button>' +
        '<div style="flex:1;">' + calHtml(y, m) + "</div>" +
        '<div style="flex:1;">' + calHtml(n.getFullYear(), n.getMonth()) + "</div>" +
        '<button type="button" data-dr-next style="appearance:none;border:0;background:transparent;cursor:pointer;font-size:16px;color:var(--fg-muted,#94a3b8);padding:4px;">›</button>' +
        "</div>" +
        '<div style="display:flex;gap:10px;align-items:flex-end;margin-top:12px;">' +
        '<div style="flex:1;"><div style="font-size:12px;font-weight:600;margin-bottom:4px;">Desde</div>' +
        '<div style="display:flex;gap:8px;"><input type="text" class="tp-input" value="' + ymd(dr.from) + '" readonly style="flex:1;background:var(--surface-sunken,#f8fafc);">' +
        '<input type="time" class="tp-input" data-dr-fromtime value="' + dr.ft + '" style="width:96px;"></div></div>' +
        '<span style="padding-bottom:9px;color:var(--fg-muted,#94a3b8);">—</span>' +
        '<div style="flex:1;"><div style="font-size:12px;font-weight:600;margin-bottom:4px;">Hasta</div>' +
        '<div style="display:flex;gap:8px;"><input type="text" class="tp-input" value="' + ymd(dr.to) + '" readonly style="flex:1;background:var(--surface-sunken,#f8fafc);">' +
        '<input type="time" class="tp-input" data-dr-totime value="' + dr.tt + '" style="width:96px;"></div></div>' +
        "</div></div></div>";
    }

    // Persistir horas editadas (render() re-crea los inputs al navegar meses)
    body.addEventListener("change", function (e) {
      if (e.target.matches("[data-dr-fromtime]")) dr.ft = e.target.value || "00:00";
      if (e.target.matches("[data-dr-totime]")) dr.tt = e.target.value || "23:59";
    });

    body.addEventListener("click", function (e) {
      var prev = e.target.closest("[data-dr-prev]");
      var next = e.target.closest("[data-dr-next]");
      var day = e.target.closest("[data-dr-day]");
      var quick = e.target.closest("[data-dr-quick]");
      if (prev) {
        base = new Date(base.getFullYear(), base.getMonth() - 1, 1);
        render();
        return;
      }
      if (next) {
        base = new Date(base.getFullYear(), base.getMonth() + 1, 1);
        render();
        return;
      }
      if (day) {
        var p = day.getAttribute("data-dr-day").split("-");
        var picked = new Date(+p[0], +p[1] - 1, +p[2]);
        if (dr.to.getTime() !== dr.from.getTime() || picked.getTime() < dr.from.getTime()) {
          dr.from = picked;
          dr.to = picked;
        } else {
          dr.to = picked;
        }
        render();
        return;
      }
      if (quick) {
        var n2 = new Date();
        var a = new Date(n2.getFullYear(), n2.getMonth(), n2.getDate());
        var b = new Date(a);
        var k = quick.getAttribute("data-dr-quick");
        if (k === "yesterday") {
          a.setDate(a.getDate() - 1);
          b.setDate(b.getDate() - 1);
        } else if (k === "2days") a.setDate(a.getDate() - 2);
        else if (k === "3days") a.setDate(a.getDate() - 3);
        else if (k === "week") a.setDate(a.getDate() - ((a.getDay() + 6) % 7));
        else if (k === "lastweek") {
          a.setDate(a.getDate() - ((a.getDay() + 6) % 7) - 7);
          b = new Date(a);
          b.setDate(b.getDate() + 6);
        } else if (k === "month") a = new Date(n2.getFullYear(), n2.getMonth(), 1);
        else if (k === "lastmonth") {
          a = new Date(n2.getFullYear(), n2.getMonth() - 1, 1);
          b = new Date(n2.getFullYear(), n2.getMonth(), 0);
        }
        dr.from = a;
        dr.to = b;
        // Rango rápido = días completos
        dr.ft = "00:00";
        dr.tt = "23:59";
        base = new Date(a.getFullYear(), a.getMonth(), 1);
        render();
      }
    });

    render();
  }

  /* ================= Modal Generar / Guardar ========================= */

  function openReportModal(id) {
    var isEdit = !!id;

    var dlg = tpModal.open({
      title: "Reporte",
      size: "xl",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        {
          label: "Generar",
          variant: "ghost",
          close: false,
          onClick: function (dialog) {
            doGenerate(dialog);
            return false;
          },
        },
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            doSave(dialog, id);
            return false;
          },
        },
      ],
    });

    var itemPromise = isEdit
      ? tpModal.fetchJSON(URLS.item.replace("{id}", id), { method: "GET" }).then(function (r) {
          return r.ok && r.data && r.data.ok ? r.data.item : null;
        })
      : Promise.resolve(null);

    Promise.all([loadMeta(), itemPromise]).then(function (out) {
      buildModal(dlg, out[0], out[1]);
    }).catch(function () {
      var b = dlg.querySelector(".tp-modal__body");
      if (b) b.innerHTML = '<div class="tp-text-center tp-text-error" style="padding:24px;">No se pudo cargar.</div>';
    });

    return dlg;
  }

  function buildModal(dlg, meta, item) {
    item = item || {};
    var body = dlg.querySelector(".tp-modal__body");
    if (!body) return;

    var today = new Date();
    // Default: HOY completo (00:00 → 23:59) — el "hasta 00:00 de mañana"
    // del legacy confundía al usuario.
    var range = {
      from: item.date_from || ymd(today),
      fromTime: item.from_time || "00:00",
      to: item.date_to || ymd(today),
      toTime: item.to_time || "23:59",
    };

    var groupTitles = {};
    (meta.groups || []).forEach(function (g) {
      groupTitles[g.id] = g.title;
    });

    var DAY_PILLS = [
      [1, "Lun"], [2, "Mar"], [3, "Mié"], [4, "Jue"], [5, "Vie"], [6, "Sáb"], [7, "Dom"],
    ];

    var HR = '<hr style="border:0;border-top:1px solid var(--border-default,#eef2f7);margin:16px 0;">';
    var ROW = '<div class="tp-form__grid tp-form__grid--2">';

    body.innerHTML =
      '<form class="tp-form tp-report-form">' +
      (item.id ? '<input type="hidden" name="id" value="' + item.id + '">' : "") +
      // ── Fila 1 (layout referencia): izquierda Título/Tipo/Formato
      //    apilados; derecha el bloque de Periodo ──
      ROW +
      '<div style="display:flex;flex-direction:column;gap:12px;">' +
      '<label class="tp-form__field"><span class="tp-form__label">Título</span>' +
      '<input type="text" name="title" maxlength="120" value="' + esc(item.title || "") + '" data-tp-field></label>' +
      '<label class="tp-form__field"><span class="tp-form__label">Tipo</span>' +
      '<select class="tp-input" style="width:100%" name="type" data-tp-field>' +
      (meta.templates || []).map(function (t) {
        return '<option value="' + t.id + '"' + (String(item.type) === String(t.id) ? " selected" : "") + ">" + esc(t.title) + "</option>";
      }).join("") +
      "</select></label>" +
      '<label class="tp-form__field"><span class="tp-form__label">Formato</span>' +
      '<select class="tp-input" style="width:100%" name="format" data-tp-field>' +
      (meta.formats || []).map(function (f) {
        return '<option value="' + f.id + '"' + ((item.format || "html") === f.id ? " selected" : "") + ">" + esc(f.label) + "</option>";
      }).join("") +
      "</select></label>" +
      "</div>" +
      '<div class="tp-form__field"><span class="tp-form__label">Periodo</span>' +
      '<input type="text" data-rep-period readonly style="cursor:pointer;background:var(--surface-raised,#fff);" value="">' +
      '<button type="button" data-rep-days-toggle style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--brand-500,#2e63d9);font-size:12px;font-weight:600;text-align:center;padding:4px;">Controlar días y horas ▾</button>' +
      '<div data-rep-days hidden>' +
      '<div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;">' +
      DAY_PILLS.map(function (d) {
        return (
          '<button type="button" data-rep-day="' + d[0] + '" class="is-on" ' +
          'style="appearance:none;cursor:pointer;border:1px solid var(--border-default,#cbd5e1);background:var(--surface-raised,#fff);border-radius:10px;padding:6px 12px;font-size:12px;font-weight:600;color:var(--fg-secondary,#475569);">' +
          d[1] + "</button>"
        );
      }).join("") +
      "</div>" +
      // Slider de franja horaria (dos manijas)
      '<div style="display:flex;gap:12px;align-items:center;margin-top:12px;padding:0 4px;">' +
      '<span data-ts-label style="font-size:12px;font-weight:600;min-width:92px;color:var(--fg-primary,#0f172a);">00:00 - 24:00</span>' +
      '<div data-ts-track style="position:relative;flex:1;height:22px;cursor:pointer;touch-action:none;">' +
      '<div style="position:absolute;top:9px;left:0;right:0;height:4px;border-radius:999px;background:var(--border-default,#e2e8f0);"></div>' +
      '<div data-ts-fill style="position:absolute;top:9px;height:4px;border-radius:999px;background:var(--brand-500,#2e63d9);"></div>' +
      '<div data-ts-tip style="position:absolute;top:-24px;transform:translateX(-50%);background:var(--brand-500,#2e63d9);color:#fff;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;display:none;white-space:nowrap;">00:00</div>' +
      '<button type="button" data-ts-h="from" style="position:absolute;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;border:1px solid var(--border-strong,#cbd5e1);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:grab;transform:translateX(-50%);padding:0;appearance:none;"></button>' +
      '<button type="button" data-ts-h="to" style="position:absolute;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;border:1px solid var(--border-strong,#cbd5e1);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:grab;transform:translateX(-50%);padding:0;appearance:none;"></button>' +
      "</div></div></div></div>" +
      "</div>" + // /fila1
      HR +
      // ── Devices | Geocercas (fila fija: ocultar deja hueco, no re-fluye) ──
      ROW +
      '<div class="tp-form__field" data-rep-field="devices"><span class="tp-form__label">Dispositivos</span><div data-rep-devices></div></div>' +
      '<div class="tp-form__field" data-rep-field="geofences"><span class="tp-form__label">Geocercas</span><div data-rep-geofences></div></div>' +
      "</div>" +
      // Parámetros dinámicos por tipo
      '<div data-rep-extra class="tp-form__grid tp-form__grid--2" style="margin-top:12px;display:none;"></div>' +
      '<div class="tp-form__grid tp-form__grid--2" style="margin-top:12px;">' +
      '<div class="tp-form__field" data-rep-field="speed_limit"><span class="tp-form__label">Límite de velocidad</span>' +
      '<input type="text" name="speed_limit" placeholder="60" value="' + esc(item.speed_limit || "") + '" data-tp-field></div>' +
      '<div class="tp-form__field" data-rep-field="stops"><span class="tp-form__label">Paradas</span>' +
      '<select class="tp-input" style="width:100%" name="stops">' +
      (meta.stops || []).map(function (s) {
        return '<option value="' + esc(s.value) + '"' + (String(item.stops || "60") === String(s.value) ? " selected" : "") + ">" + esc(s.label) + "</option>";
      }).join("") +
      "</select></div>" +
      "</div>" +
      '<div class="tp-form__grid tp-form__grid--2" style="margin-top:12px;">' +
      '<div class="tp-form__field" data-rep-field="metas"><span class="tp-form__label">Metas</span><div data-rep-metas></div></div>' +
      '<div class="tp-form__field"><span class="tp-form__label">&nbsp;</span>' +
      '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;min-height:36px;">' +
      '<label class="tp-choice" style="margin:0;"><input type="checkbox" class="tp-choice-input" name="skip_blank_results" value="1"' + (item.skip_blank_results ? " checked" : "") + "><span>Omitir resultados vacíos</span></label>" +
      '<label class="tp-choice" data-rep-field="zones_instead" style="margin:0;"><input type="checkbox" class="tp-choice-input" name="zones_instead" value="1"' + (item.zones_instead ? " checked" : "") + "><span>Zonas en vez de direcciones</span></label>" +
      '<label class="tp-choice" data-rep-field="show_addresses" style="margin:0;"><input type="checkbox" class="tp-choice-input" name="show_addresses" value="1"' + (item.show_addresses ? " checked" : "") + "><span>Mostrar direcciones</span></label>" +
      "</div></div>" +
      "</div>" +
      HR +
      // ── Email (con + integrado) | Programación (alineada al input) ──
      ROW +
      '<div class="tp-form__field"><span class="tp-form__label">Enviar a email</span>' +
      '<div class="tp-input-group" style="padding-right:4px;padding-left:0;">' +
      '<input type="text" data-rep-email-input placeholder="Email…" style="padding-left:11px;">' +
      '<button type="button" data-rep-email-add title="Agregar" style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--brand-500,#2e63d9);font-size:16px;font-weight:700;padding:0 8px;line-height:1;">＋</button>' +
      "</div>" +
      '<div data-rep-emails style="display:flex;flex-direction:column;gap:6px;margin-top:6px;"></div>' +
      (meta.showFtp
        ? '<label class="tp-choice" style="margin-top:8px;"><input type="checkbox" class="tp-choice-input" name="send_to_ftp" value="1"' + (item.ftp ? " checked" : "") + "><span>Enviar a FTP</span></label>"
        : "") +
      "</div>" +
      '<div class="tp-form__field"><span class="tp-form__label">&nbsp;</span>' +
      '<div style="display:flex;flex-direction:column;gap:8px;">' +
      schedRow("daily", "Diario", item.daily, item.daily_time, "") +
      schedRow("weekly", "Semanal", item.weekly, item.weekly_time, daySelect("weekly_day", DAY_PILLS, item.weekly_day, "Día de semana…")) +
      schedRow("monthly", "Mensual", item.monthly, item.monthly_time, daySelect("monthly_day", null, item.monthly_day, "Día del mes…")) +
      "</div></div>" +
      "</div></form>";

    var form = body.querySelector("form.tp-report-form");

    // ── Periodo ──
    var periodEl = form.querySelector("[data-rep-period]");
    form._range = range;
    function syncPeriod() {
      periodEl.value = range.from + " " + range.fromTime + "  —  " + range.to + " " + range.toTime;
    }
    syncPeriod();
    periodEl.addEventListener("click", function () {
      openRangePicker(range, function (r) {
        range = r;
        form._range = r;
        syncPeriod();
      });
    });

    // ── Pills de días + franja ──
    var daysWrap = form.querySelector("[data-rep-days]");
    form.querySelector("[data-rep-days-toggle]").addEventListener("click", function () {
      daysWrap.hidden = !daysWrap.hidden;
    });
    function setPill(pill, on) {
      pill.classList.toggle("is-on", on);
      pill.style.background = on ? "var(--surface-raised,#fff)" : "var(--surface-sunken,#f1f5f9)";
      pill.style.opacity = on ? "1" : "0.55";
    }
    daysWrap.addEventListener("click", function (e) {
      var pill = e.target.closest("[data-rep-day]");
      if (!pill) return;
      setPill(pill, !pill.classList.contains("is-on"));
    });

    // Precarga del filtro persistido (edición)
    var savedDays = item.period_weekdays || [];
    if (savedDays.length) {
      Array.prototype.forEach.call(form.querySelectorAll("[data-rep-day]"), function (p) {
        setPill(p, savedDays.indexOf(p.getAttribute("data-rep-day")) >= 0 || savedDays.indexOf(+p.getAttribute("data-rep-day")) >= 0);
      });
      daysWrap.hidden = false;
    }

    // Slider de franja horaria
    wireTimeSlider(form, toMin(item.period_time_from), toMin(item.period_time_to));
    if (item.period_time_from && item.period_time_to) daysWrap.hidden = false;

    // ── Chips ──
    form._devices = chipsSelect({
      mount: form.querySelector("[data-rep-devices]"),
      items: meta.devices || [],
      groups: groupTitles,
      selected: item.device_ids || [],
      placeholder: "Seleccionar dispositivos…",
      host: dlg,
    });
    form._geofences = chipsSelect({
      mount: form.querySelector("[data-rep-geofences]"),
      items: (meta.geofences || []).map(function (g) {
        return { id: g.id, name: g.name };
      }),
      selected: item.geofence_ids || [],
      placeholder: "Seleccionar geocercas…",
      host: dlg,
    });
    form._metas = chipsSelect({
      mount: form.querySelector("[data-rep-metas]"),
      items: (meta.metas || []).map(function (m) {
        return { id: m.value, name: m.label };
      }),
      selected: item.metas || [],
      placeholder: "Seleccionar metas…",
      host: dlg,
    });

    // ── Emails (chips con +) ──
    var emailsWrap = form.querySelector("[data-rep-emails]");
    var emailInput = form.querySelector("[data-rep-email-input]");
    form._emails = [];
    (item.email ? item.email.split(";") : []).forEach(function (e) {
      if (e.trim()) form._emails.push(e.trim());
    });
    // Filas de email editables con "−" (estilo referencia)
    function renderEmails() {
      emailsWrap.innerHTML = form._emails
        .map(function (e, i) {
          return (
            '<div class="tp-input-group" style="padding-right:4px;padding-left:0;">' +
            '<input type="text" value="' + esc(e) + '" data-rep-email-idx="' + i + '" style="padding-left:11px;">' +
            '<button type="button" data-rep-email-rm="' + i + '" title="Quitar" ' +
            'style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--fg-muted,#94a3b8);font-size:16px;font-weight:700;padding:0 8px;line-height:1;">−</button>' +
            "</div>"
          );
        })
        .join("");
    }
    renderEmails();
    form.querySelector("[data-rep-email-add]").addEventListener("click", function () {
      var v = emailInput.value.trim();
      if (!v) return;
      form._emails.push(v);
      emailInput.value = "";
      renderEmails();
    });
    emailInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        form.querySelector("[data-rep-email-add]").click();
      }
    });
    emailsWrap.addEventListener("click", function (e) {
      var rm = e.target.closest("[data-rep-email-rm]");
      if (!rm) return;
      form._emails.splice(+rm.getAttribute("data-rep-email-rm"), 1);
      renderEmails();
    });
    emailsWrap.addEventListener("input", function (e) {
      var idx = e.target.getAttribute && e.target.getAttribute("data-rep-email-idx");
      if (idx != null) form._emails[+idx] = e.target.value.trim();
    });

    // ── Tipo → fields + parámetros dinámicos ──
    var typeSel = form.querySelector('select[name="type"]');
    typeSel.addEventListener("change", function () {
      applyType(form, meta, typeSel.value, null);
    });
    applyType(form, meta, typeSel.value, item);
  }

  /* ── Slider de franja horaria (dos manijas, paso 5 min) ── */
  function fmtMin(m) {
    return pad2(Math.floor(m / 60)) + ":" + pad2(m % 60);
  }

  function toMin(hhmm) {
    if (!hhmm || !/^\d{1,2}:\d{2}/.test(hhmm)) return null;
    var p = hhmm.split(":");
    return Math.min(1440, +p[0] * 60 + +p[1]);
  }

  function wireTimeSlider(form, initFrom, initTo) {
    var track = form.querySelector("[data-ts-track]");
    if (!track) return;
    var fill = form.querySelector("[data-ts-fill]");
    var tip = form.querySelector("[data-ts-tip]");
    var label = form.querySelector("[data-ts-label]");
    var hFrom = form.querySelector('[data-ts-h="from"]');
    var hTo = form.querySelector('[data-ts-h="to"]');

    var st = { from: initFrom != null ? initFrom : 0, to: initTo != null ? initTo : 1440 };
    form._tsGet = function () {
      return { from: st.from, to: st.to };
    };

    function render() {
      var pf = (st.from / 1440) * 100;
      var pt = (st.to / 1440) * 100;
      hFrom.style.left = pf + "%";
      hTo.style.left = pt + "%";
      fill.style.left = pf + "%";
      fill.style.width = pt - pf + "%";
      label.textContent = fmtMin(st.from) + " - " + fmtMin(st.to);
    }

    function minutesFromEvent(e) {
      var r = track.getBoundingClientRect();
      var x = Math.max(0, Math.min(e.clientX - r.left, r.width));
      return Math.round(((x / r.width) * 1440) / 5) * 5;
    }

    function showTip(handle, value) {
      tip.style.display = "";
      tip.style.left = handle.style.left;
      tip.textContent = fmtMin(value);
    }

    var dragging = null;

    function startDrag(which, e) {
      dragging = which;
      e.preventDefault();
      showTip(which === "from" ? hFrom : hTo, which === "from" ? st.from : st.to);
      document.addEventListener("pointermove", onMove);
      document.addEventListener("pointerup", onUp);
    }

    function onMove(e) {
      if (!dragging) return;
      var m = minutesFromEvent(e);
      if (dragging === "from") st.from = Math.min(m, st.to - 5);
      else st.to = Math.max(m, st.from + 5);
      st.from = Math.max(0, st.from);
      st.to = Math.min(1440, st.to);
      render();
      showTip(dragging === "from" ? hFrom : hTo, dragging === "from" ? st.from : st.to);
    }

    function onUp() {
      dragging = null;
      tip.style.display = "none";
      document.removeEventListener("pointermove", onMove);
      document.removeEventListener("pointerup", onUp);
    }

    hFrom.addEventListener("pointerdown", function (e) {
      startDrag("from", e);
    });
    hTo.addEventListener("pointerdown", function (e) {
      startDrag("to", e);
    });
    track.addEventListener("pointerdown", function (e) {
      if (e.target === hFrom || e.target === hTo) return;
      // mueve la manija más cercana al punto clicado
      var m = minutesFromEvent(e);
      var which = Math.abs(m - st.from) <= Math.abs(m - st.to) ? "from" : "to";
      if (which === "from") st.from = Math.min(m, st.to - 5);
      else st.to = Math.max(m, st.from + 5);
      render();
      startDrag(which, e);
    });

    render();
  }

  function schedRow(key, label, on, time, extra) {
    return (
      '<div style="display:flex;gap:9px;align-items:center;">' +
      '<label class="tp-choice" style="margin:0;min-width:96px;">' +
      '<input type="checkbox" class="tp-choice-input" name="' + key + '" value="1" id="tp-rep-' + key + '"' + (on ? " checked" : "") + ">" +
      "<span>" + label + "</span></label>" +
      '<input type="time" class="tp-input" name="' + key + '_time" value="' + esc(time || "00:00") + '" data-enable="tp-rep-' + key + '"' + (on ? "" : " disabled") + ' style="width:104px;">' +
      (extra || "") +
      "</div>"
    );
  }

  // Día concreto del schedule (extensión: weekly_day ISO 1-7 / monthly_day 1-31)
  function daySelect(name, pills, selected, placeholder) {
    var key = name === "weekly_day" ? "weekly" : "monthly";
    var html = '<select class="tp-input" name="' + name + '" data-enable="tp-rep-' + key + '"' +
      (selected ? "" : " disabled") + ' style="width:150px;">' +
      '<option value="">' + esc(placeholder) + "</option>";
    if (pills) {
      pills.forEach(function (d) {
        html += '<option value="' + d[0] + '"' + (String(selected) === String(d[0]) ? " selected" : "") + ">" + d[1] + "</option>";
      });
    } else {
      for (var i = 1; i <= 31; i++) {
        html += '<option value="' + i + '"' + (String(selected) === String(i) ? " selected" : "") + ">" + i + "</option>";
      }
    }
    return html + "</select>";
  }

  // Muestra/oculta campos según el tipo y renderiza sus parámetros
  // dinámicos (port de buildReportAttributes del create.blade legacy).
  function applyType(form, meta, typeId, item) {
    var url = meta.typeUrl.replace("{type}", typeId);
    tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      var type = res.data || {};
      var fields = type.fields || [];
      if (!Array.isArray(fields)) fields = Object.values(fields);

      ["devices", "geofences", "metas", "speed_limit", "stops", "zones_instead", "show_addresses"].forEach(function (f) {
        var block = form.querySelector('[data-rep-field="' + f + '"]');
        if (block) block.style.display = fields.indexOf(f) >= 0 ? "" : "none";
      });

      // Formatos permitidos por el tipo
      var formats = type.formats || null;
      if (formats) {
        var allowed = Array.isArray(formats) ? formats : Object.keys(formats);
        var fmtSel = form.querySelector('select[name="format"]');
        Array.prototype.forEach.call(fmtSel.options, function (o) {
          o.disabled = allowed.indexOf(o.value) < 0;
        });
        if (fmtSel.selectedOptions[0] && fmtSel.selectedOptions[0].disabled && allowed.length) {
          fmtSel.value = allowed[0];
        }
        if (fmtSel._tpdd && window.tpSelect && tpSelect.refresh) {
          try {
            tpSelect.refresh(fmtSel);
          } catch (e) {}
        }
      }

      // Parámetros dinámicos
      var extra = form.querySelector("[data-rep-extra]");
      var params = type.parameters || [];
      if (!Array.isArray(params)) params = Object.values(params);
      var saved = (item && item.parameters) || {};
      extra.innerHTML = params.map(function (p) {
        return paramHtml(p, saved[p.name]);
      }).join("");
      extra.style.display = params.length ? "" : "none";
      if (window.tpSelect && tpSelect.init) {
        try {
          tpSelect.init(extra);
        } catch (e) {}
      }
    });
  }

  function paramHtml(p, savedVal) {
    var val = savedVal != null ? savedVal : p.default;
    var name = p.html_name || p.name;
    var label = '<span class="tp-form__label">' + esc(p.title || p.name) + (p.required ? " *" : "") + "</span>";
    var hint = p.description ? '<small class="tp-form__hint">' + esc(p.description) + "</small>" : "";

    switch (p.type) {
      case "checkbox":
        var checked = savedVal != null ? !!+savedVal : !!p.checked;
        return (
          '<label class="tp-choice" data-rep-param style="align-self:end;margin:0 0 8px;">' +
          '<input type="hidden" name="' + esc(name) + '" value="0">' +
          '<input type="checkbox" class="tp-choice-input" name="' + esc(name) + '" value="1"' + (checked ? " checked" : "") + ">" +
          "<span>" + esc(p.title || p.name) + "</span></label>"
        );
      case "select":
      case "multiselect":
      case "multiselect-group":
        var multiple = p.type !== "select";
        var opts = p.options || {};
        var html = '<div class="tp-form__field" data-rep-param>' + label +
          '<select class="tp-input" style="width:100%" name="' + esc(name) + '"' + (multiple ? " multiple" : "") + ">";
        var selVals = Array.isArray(val) ? val.map(String) : [String(val == null ? "" : val)];
        Object.keys(opts).forEach(function (k) {
          var v = opts[k];
          if (v && typeof v === "object") {
            html += '<optgroup label="' + esc(k) + '">';
            Object.keys(v).forEach(function (k2) {
              html += '<option value="' + esc(k2) + '"' + (selVals.indexOf(String(k2)) >= 0 ? " selected" : "") + ">" + esc(v[k2]) + "</option>";
            });
            html += "</optgroup>";
          } else {
            html += '<option value="' + esc(k) + '"' + (selVals.indexOf(String(k)) >= 0 ? " selected" : "") + ">" + esc(v) + "</option>";
          }
        });
        return html + "</select>" + hint + "</div>";
      case "text":
        return '<div class="tp-form__field" data-rep-param style="grid-column:1/-1;">' + label +
          '<textarea name="' + esc(name) + '" rows="2">' + esc(val == null ? "" : val) + "</textarea>" + hint + "</div>";
      default: // integer | string | datetime
        return '<div class="tp-form__field" data-rep-param>' + label +
          '<input type="text" name="' + esc(name) + '" value="' + esc(val == null ? "" : val) + '">' + hint + "</div>";
    }
  }

  /* ── Payload compartido Generar/Guardar ── */
  function collectPayload(dialog) {
    var form = dialog.querySelector("form.tp-report-form");
    var range = form._range;
    var payload = {
      title: form.querySelector('input[name="title"]').value.trim(),
      type: form.querySelector('select[name="type"]').value,
      format: form.querySelector('select[name="format"]').value,
      date_from: range.from,
      from_time: range.fromTime,
      date_to: range.to,
      to_time: range.toTime,
      speed_limit: (form.querySelector('input[name="speed_limit"]') || {}).value || "",
      stops: (form.querySelector('select[name="stops"]') || {}).value || "",
      show_addresses: form.querySelector('input[name="show_addresses"]').checked ? 1 : 0,
      zones_instead: form.querySelector('input[name="zones_instead"]').checked ? 1 : 0,
      skip_blank_results: form.querySelector('input[name="skip_blank_results"]').checked ? 1 : 0,
      send_to_email: form._emails
        .filter(function (e) {
          return e && e.trim();
        })
        .join(";"),
      daily: form.querySelector('input[name="daily"]').checked ? 1 : 0,
      daily_time: form.querySelector('input[name="daily_time"]').value || "00:00",
      weekly: form.querySelector('input[name="weekly"]').checked ? 1 : 0,
      weekly_time: form.querySelector('input[name="weekly_time"]').value || "00:00",
      monthly: form.querySelector('input[name="monthly"]').checked ? 1 : 0,
      monthly_time: form.querySelector('input[name="monthly_time"]').value || "00:00",
    };

    var idEl = form.querySelector('input[name="id"]');
    if (idEl) payload.id = idEl.value;

    var devIds = form._devices.getIds();
    payload.devices = devIds.map(Number);
    payload.selected_devices = ["s;0;"].concat(devIds.map(function (i) {
      return "id;1;" + i;
    }));
    payload.geofences = form._geofences.getIds().map(Number);
    payload.metas = form._metas.getIds();

    // Pills de días: solo si el usuario desmarcó alguno
    var pills = form.querySelectorAll("[data-rep-day]");
    var onDays = [];
    Array.prototype.forEach.call(pills, function (p) {
      if (p.classList.contains("is-on")) onDays.push(p.getAttribute("data-rep-day"));
    });
    if (onDays.length && onDays.length < 7) payload.period_weekdays = onDays;

    // Franja horaria del slider: rango completo (00:00–24:00) = sin filtro
    if (form._tsGet) {
      var ts = form._tsGet();
      if (ts.from > 0 || ts.to < 1440) {
        payload.period_time_from = fmtMin(ts.from);
        payload.period_time_to = ts.to >= 1440 ? "23:59" : fmtMin(ts.to);
      }
    }

    var ftpEl = form.querySelector('input[name="send_to_ftp"]');
    if (ftpEl) payload.send_to_ftp = ftpEl.checked ? 1 : 0;

    // Día concreto del schedule (solo si su checkbox está activo)
    var wdEl = form.querySelector('select[name="weekly_day"]');
    payload.weekly_day = payload.weekly && wdEl ? wdEl.value : "";
    var mdEl = form.querySelector('select[name="monthly_day"]');
    payload.monthly_day = payload.monthly && mdEl ? mdEl.value : "";

    // Parámetros dinámicos
    Array.prototype.forEach.call(form.querySelectorAll("[data-rep-param] [name]"), function (el) {
      var n = el.name;
      if (el.type === "hidden") {
        if (payload[n.replace("[]", "")] == null) payload[n] = el.value;
        return;
      }
      if (el.type === "checkbox") {
        payload[n] = el.checked ? 1 : 0;
        return;
      }
      if (el.multiple) {
        payload[n.replace("[]", "")] = Array.prototype.map.call(el.selectedOptions, function (o) {
          return o.value;
        });
        return;
      }
      payload[n] = el.value;
    });

    return payload;
  }

  function doGenerate(dialog) {
    var payload = collectPayload(dialog);
    delete payload.id;
    payload.generate = 1;
    if (payload.format === "html" || payload.format === "json" || payload.format === "csv") {
      payload.view = 1;
    }

    var qs = [];
    Object.keys(payload).forEach(function (k) {
      var v = payload[k];
      if (Array.isArray(v)) {
        v.forEach(function (x) {
          qs.push(encodeURIComponent(k) + "[]=" + encodeURIComponent(x));
        });
      } else if (v != null && v !== "") {
        qs.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
      }
    });

    window.open(META.generateUrl + "?" + qs.join("&"), "_blank", "noopener");
  }

  function doSave(dialog, id) {
    var payload = collectPayload(dialog);
    if (id) payload.id = id;

    tpModal.setBusy(dialog, true);
    tpModal
      .fetchJSON(URLS.store, { method: "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(dialog, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          tpModal.showFieldErrors(dialog, errors);
          var first = Object.keys(errors)[0];
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.", "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
          return;
        }
        tpModal.toast("Reporte guardado.", "success");
        tpModal.close(dialog);
        loadTab();
      })
      .catch(function () {
        tpModal.setBusy(dialog, false);
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ================= Página (tabs + tablas) ========================== */

  var savedRows = document.getElementById("tp-rep-saved-rows");
  var logsRows = document.getElementById("tp-rep-logs-rows");
  var searchEl = document.getElementById("tp-rep-search");
  var typeFilter = document.getElementById("tp-rep-type-filter");
  var limitSel = document.getElementById("tp-rep-limit");
  var rangeEl = document.getElementById("tp-rep-range");
  var prevBtn = document.getElementById("tp-rep-prev");
  var nextBtn = document.getElementById("tp-rep-next");
  var addBtn = document.getElementById("tp-rep-add");

  var state = {
    tab: "saved",
    saved: { page: 1, last: 1, total: 0 },
    logs: { page: 1, last: 1, total: 0 },
    per: 20,
    s: "",
    type: "",
  };
  var timer = null;

  function kebab(items) {
    if (!items) return "";
    return (
      '<div class="tp-svc-kebab">' +
      '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="Acciones">' +
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
      "</button>" +
      '<div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>"
    );
  }

  function updatePager(t) {
    var st = state[t];
    var from = st.total ? (st.page - 1) * state.per + 1 : 0;
    var to = Math.min(st.page * state.per, st.total);
    rangeEl.textContent = from + "–" + to + " / " + st.total;
    prevBtn.disabled = st.page <= 1;
    nextBtn.disabled = st.page >= st.last;
  }

  function loadSaved() {
    var q = URLS.saved + "?s=" + encodeURIComponent(state.s) + "&type=" + encodeURIComponent(state.type) +
      "&page=" + state.saved.page + "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        savedRows.innerHTML = '<tr><td colspan="7" class="tp-svc-table__empty">No se pudo cargar.</td></tr>';
        return;
      }
      state.saved.last = res.data.last || 1;
      state.saved.total = res.data.total || 0;
      var items = res.data.items || [];
      savedRows.innerHTML = items.length
        ? items.map(function (r) {
            var acts = "";
            acts += '<button type="button" role="menuitem" data-rep-open="' + r.id + '" data-format="' + esc(r.format.toLowerCase()) + '">' +
              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Abrir</button>';
            if (canCreate)
              acts += '<button type="button" role="menuitem" data-rep-edit="' + r.id + '">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> Editar</button>';
            if (canRemove)
              acts += '<button type="button" role="menuitem" class="is-danger" data-rep-del="' + r.id + '">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> Eliminar</button>';
            return (
              '<tr data-rep-row="' + r.id + '" data-format="' + esc(r.format.toLowerCase()) + '" style="cursor:pointer;">' +
              "<td>" + esc(r.title || "–") + "</td>" +
              "<td>" + esc(r.type_label) + "</td>" +
              "<td>" + esc(r.format) + "</td>" +
              "<td>" + r.devices_count + "</td>" +
              "<td>" + r.geofences_count + "</td>" +
              "<td>" + esc(r.schedule || "–") + "</td>" +
              '<td class="tp-svc-table__actions">' + kebab(acts) + "</td></tr>"
            );
          }).join("")
        : '<tr><td colspan="7" class="tp-svc-table__empty">Sin datos</td></tr>';
      updatePager("saved");
    });
  }

  function loadLogs() {
    var q = URLS.logs + "?s=" + encodeURIComponent(state.s) + "&type=" + encodeURIComponent(state.type) +
      "&page=" + state.logs.page + "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        logsRows.innerHTML = '<tr><td colspan="6" class="tp-svc-table__empty">No se pudo cargar.</td></tr>';
        return;
      }
      state.logs.last = res.data.last || 1;
      state.logs.total = res.data.total || 0;
      var items = res.data.items || [];
      logsRows.innerHTML = items.length
        ? items.map(function (r) {
            var badge = r.is_send
              ? '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;background:#dcfce7;color:#15803d;">Sí</span>'
              : '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;background:#f1f5f9;color:#64748b;">No</span>';
            var acts = '<button type="button" role="menuitem" data-log-dl="' + esc(r.download) + '">' +
              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg> Descargar</button>';
            if (canRemove)
              acts += '<button type="button" role="menuitem" class="is-danger" data-log-del="' + r.id + '">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> Eliminar</button>';
            return (
              '<tr data-log-row="' + esc(r.download) + '" style="cursor:pointer;">' +
              "<td>" + esc(r.title || "–") + "</td>" +
              "<td>" + esc(r.type_label) + "</td>" +
              "<td>" + esc(r.format) + "</td>" +
              "<td>" + esc(r.size) + "</td>" +
              "<td>" + badge + "</td>" +
              '<td class="tp-svc-table__actions">' + kebab(acts) + "</td></tr>"
            );
          }).join("")
        : '<tr><td colspan="6" class="tp-svc-table__empty">Sin datos</td></tr>';
      updatePager("logs");
    });
  }

  function loadTab() {
    if (state.tab === "saved") loadSaved();
    else loadLogs();
  }

  function openSaved(id, format) {
    var url = META.generateUrl + "?id=" + id + "&generate=1";
    if (format === "html" || format === "json" || format === "csv") url += "&view=1";
    window.open(url, "_blank", "noopener");
  }

  // Tabs
  Array.prototype.forEach.call(document.querySelectorAll("[data-rep-tab]"), function (btn) {
    btn.addEventListener("click", function () {
      state.tab = btn.getAttribute("data-rep-tab");
      Array.prototype.forEach.call(document.querySelectorAll("[data-rep-tab]"), function (b) {
        var on = b === btn;
        b.classList.toggle("is-active", on);
        b.style.borderBottomColor = on ? "var(--brand-500,#2e63d9)" : "transparent";
        b.style.color = on ? "var(--brand-500,#2e63d9)" : "";
        b.style.fontWeight = on ? "600" : "500";
      });
      Array.prototype.forEach.call(document.querySelectorAll("[data-rep-panel]"), function (p) {
        p.hidden = p.getAttribute("data-rep-panel") !== state.tab;
      });
      loadTab();
    });
  });

  searchEl.addEventListener("input", function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      state.s = searchEl.value.trim();
      state[state.tab].page = 1;
      loadTab();
    }, 300);
  });
  typeFilter.addEventListener("change", function () {
    state.type = typeFilter.value;
    state[state.tab].page = 1;
    loadTab();
  });
  limitSel.addEventListener("change", function () {
    state.per = parseInt(limitSel.value, 10) || 20;
    state[state.tab].page = 1;
    loadTab();
  });
  prevBtn.addEventListener("click", function () {
    var st = state[state.tab];
    if (st.page > 1) {
      st.page--;
      loadTab();
    }
  });
  nextBtn.addEventListener("click", function () {
    var st = state[state.tab];
    if (st.page < st.last) {
      st.page++;
      loadTab();
    }
  });
  if (addBtn)
    addBtn.addEventListener("click", function () {
      openReportModal(null);
    });

  savedRows.addEventListener("click", function (e) {
    var open = e.target.closest("[data-rep-open]");
    if (open) {
      openSaved(open.getAttribute("data-rep-open"), open.getAttribute("data-format"));
      return;
    }
    var edit = e.target.closest("[data-rep-edit]");
    if (edit) {
      openReportModal(edit.getAttribute("data-rep-edit"));
      return;
    }
    var del = e.target.closest("[data-rep-del]");
    if (del) {
      var rid = del.getAttribute("data-rep-del");
      tpModal.confirm({ title: "Eliminar reporte", message: "¿Eliminar esta definición de reporte?", danger: true, confirmLabel: "Eliminar" })
        .then(function (okGo) {
          if (!okGo) return;
          tpModal.fetchJSON(URLS.destroy.replace("{id}", rid), { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast("Eliminado.", "success");
              loadSaved();
            } else tpModal.toast("No se pudo eliminar.", "error");
          });
        });
      return;
    }
    if (e.target.closest(".tp-svc-kebab")) return;
    var row = e.target.closest("[data-rep-row]");
    if (row) openSaved(row.getAttribute("data-rep-row"), row.getAttribute("data-format"));
  });

  logsRows.addEventListener("click", function (e) {
    var dl = e.target.closest("[data-log-dl]");
    if (dl) {
      window.open(dl.getAttribute("data-log-dl"), "_blank", "noopener");
      return;
    }
    var del = e.target.closest("[data-log-del]");
    if (del) {
      var lid = del.getAttribute("data-log-del");
      tpModal.confirm({ title: "Eliminar log", message: "¿Eliminar este reporte enviado?", danger: true, confirmLabel: "Eliminar" })
        .then(function (okGo) {
          if (!okGo) return;
          tpModal.fetchJSON(URLS.logDestroy.replace("{id}", lid), { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast("Eliminado.", "success");
              loadLogs();
            } else tpModal.toast("No se pudo eliminar.", "error");
          });
        });
      return;
    }
    if (e.target.closest(".tp-svc-kebab")) return;
    var row = e.target.closest("[data-log-row]");
    if (row) window.open(row.getAttribute("data-log-row"), "_blank", "noopener");
  });

  // Habilitadores del schedule (delegado: el modal se crea después).
  // Un checkbox puede habilitar varios controles (hora + select de día).
  document.addEventListener("change", function (e) {
    if (e.target.matches && e.target.matches('input[type="checkbox"][id^="tp-rep-"]')) {
      var form = e.target.closest("form.tp-report-form");
      if (!form) return;
      Array.prototype.forEach.call(form.querySelectorAll('[data-enable="' + e.target.id + '"]'), function (t) {
        t.disabled = !e.target.checked;
        if (t.tagName === "SELECT" && t._tpdd && window.tpSelect && tpSelect.refresh) {
          try {
            tpSelect.refresh(t);
          } catch (err) {}
        }
      });
    }
  });

  /* ── Init ── */
  loadMeta().then(function (meta) {
    var html = '<option value="">Todos los tipos</option>';
    (meta.templates || []).forEach(function (t) {
      html += '<option value="' + t.id + '">' + esc(t.title) + "</option>";
    });
    typeFilter.innerHTML = html;
    if (window.tpSelect && tpSelect.refresh) {
      try {
        tpSelect.refresh(typeFilter);
      } catch (e) {}
    }
  });
  loadSaved();

  if (root.getAttribute("data-open-modal") === "1" && canCreate) {
    openReportModal(null);
  }
})();
