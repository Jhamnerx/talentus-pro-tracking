/* =====================================================
 * TalentusPro · commands-page.js · Página /ui/v2/commands
 * Hub de Enviar comando con tabs (paridad Ubimak):
 *   Horario (CommandSchedule + Scheduler legacy)
 *   Registros (SentCommand, paginado en servidor)
 *   Plantillas GPRS / SMS (UserXTemplateModalHelper legacy)
 * El botón "Enviar comando" abre el modal compartido tp-commands.js.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-cmdhub");
  if (!root || !window.tpModal) return;

  var CFG = null;
  try {
    CFG = JSON.parse(document.getElementById("tp-cmdhub-config").textContent);
  } catch (e) {
    return;
  }
  function T(k, fb) {
    return (CFG.i18n && CFG.i18n[k]) || fb || k;
  }
  function esc(s) {
    return tpModal.escapeHtml(s);
  }

  /* Clases Tailwind autocontenidas (preflight off → resets explícitos) */
  var UI = {
    linkBtn:
      "appearance-none border-0 bg-transparent p-0 cursor-pointer text-[12px] font-semibold " +
      "text-[color:var(--brand-600,#0d9488)] hover:underline",
    list:
      "mt-2 max-h-40 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 " +
      "dark:border-slate-600 dark:bg-slate-800",
    item:
      "flex items-center gap-2.5 px-2.5 py-2 rounded-md text-[13px] text-slate-700 cursor-pointer " +
      "hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700/50",
    chk: "tp-choice-input",
    radio: "tp-choice",
    label: "block text-[12px] font-semibold text-slate-600 dark:text-slate-300 mb-1.5",
    bar: "flex items-center justify-between gap-3",
    sel: "inline-flex gap-3",
  };
  // Listas apiArray legacy: [{id, value}]
  function optListToMap(list) {
    var out = [];
    (list || []).forEach(function (o) {
      if (o && typeof o === "object" && "id" in o) out.push({ id: String(o.id), title: String(o.value != null ? o.value : o.title) });
    });
    return out;
  }

  var state = {
    tab: "schedule",
    q: "",
    conn: "", // filtro por conexión (tab Registros)
    page: 1,
    limit: 20,
    // datos por tab
    schedules: null,
    pendings: null,
    templates: { gprs: null, sms: null },
    tplMeta: { gprs: null, sms: null }, // adapties/protocols/device_types
    logs: { items: [], page: 1, last: 1, total: 0 },
    cmdMeta: null, // meta de devices (modal schedule)
  };

  var els = {
    thead: document.getElementById("tp-cmdhub-thead"),
    rows: document.getElementById("tp-cmdhub-rows"),
    search: document.getElementById("tp-cmdhub-search"),
    add: document.getElementById("tp-cmdhub-add"),
    limit: document.getElementById("tp-cmdhub-limit"),
    range: document.getElementById("tp-cmdhub-range"),
    prev: document.getElementById("tp-cmdhub-prev"),
    next: document.getElementById("tp-cmdhub-next"),
  };

  var DOTS =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
  function kebab(id, withLogs) {
    return (
      '<div class="tp-svc-kebab"><button type="button" class="tp-svc-kebab__btn" data-kebab>' + DOTS +
      '</button><div class="tp-svc-kebab__menu" hidden>' +
      '<button type="button" data-hub-act="edit" data-id="' + id + '">' + esc(T("edit")) + "</button>" +
      (withLogs
        ? '<button type="button" data-hub-act="logs" data-id="' + id + '">' + esc(T("logs")) + "</button>"
        : "") +
      '<button type="button" class="is-danger" data-hub-act="del" data-id="' + id + '">' + esc(T("delete")) + "</button>" +
      "</div></div>"
    );
  }
  function closeKebabs(except) {
    root.querySelectorAll(".tp-svc-kebab__menu:not([hidden])").forEach(function (m) {
      if (m !== except) m.setAttribute("hidden", "");
    });
  }
  // Menú como "portal" fixed pegado al botón (mismo patrón que tp-services):
  // sin esto el overflow de la tabla lo recorta cuando hay pocas filas.
  function positionKebab(btn, menu) {
    var r = btn.getBoundingClientRect();
    menu.style.position = "fixed";
    menu.style.right = "auto";
    var w = menu.offsetWidth;
    var h = menu.offsetHeight;
    menu.style.left =
      Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + "px";
    if (r.bottom + 4 + h > window.innerHeight - 8 && r.top - h - 4 > 8) {
      menu.style.top = r.top - h - 4 + "px";
    } else {
      menu.style.top = r.bottom + 4 + "px";
    }
  }
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-kebab]");
    if (b) {
      var menu = b.parentNode.querySelector(".tp-svc-kebab__menu");
      var open = menu && !menu.hasAttribute("hidden");
      closeKebabs();
      if (menu && !open) {
        menu.removeAttribute("hidden");
        positionKebab(b, menu);
      }
      e.stopPropagation();
      return;
    }
    closeKebabs();
  });
  // El menú es fixed: al scrollear cualquier contenedor se cierra (si no,
  // quedaría flotando desanclado del botón).
  window.addEventListener(
    "scroll",
    function (e) {
      if (e.target && e.target.closest && e.target.closest(".tp-svc-kebab__menu")) return;
      closeKebabs();
    },
    true,
  );

  /* ============================ Render ============================ */

  function setHead(cols) {
    els.thead.innerHTML =
      "<tr>" +
      cols
        .map(function (c) {
          return "<th" + (c === "" ? ' class="tp-svc-table__actions"' : "") + ">" + esc(c) + "</th>";
        })
        .join("") +
      "</tr>";
  }

  function emptyRow(cols, text) {
    return '<tr><td colspan="' + cols + '" class="tp-svc-table__empty">' + esc(text || T("not_found")) + "</td></tr>";
  }

  function pagedClient(items) {
    var q = state.q.toLowerCase();
    return items.filter(function (r) {
      return !q || (r._search || "").indexOf(q) !== -1;
    });
  }

  function renderPager(total, page, pages) {
    els.range.textContent = total ? (page - 1) * state.limit + 1 + "–" + Math.min(page * state.limit, total) + " / " + total : "0";
    els.prev.disabled = page <= 1;
    els.next.disabled = page >= pages;
  }

  function render() {
    if (state.tab === "schedule") return renderSchedules();
    if (state.tab === "logs") return renderLogs();
    if (state.tab === "pending") return renderPendings();
    return renderTemplates(state.tab);
  }

  /* ---------- En cola (auto-send when online) ---------- */
  var TRASH =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';
  function renderPendings() {
    setHead([T("device"), T("command"), T("parameters"), T("attempts"), T("response"), T("date"), ""]);
    var items = state.pendings;
    if (!items) {
      els.rows.innerHTML = emptyRow(7, T("loading") + "…");
      return tpModal.fetchJSON(CFG.urls.pendings).then(function (res) {
        state.pendings = ((res.data && res.data.items) || []).map(function (r) {
          r._search = (r.device + " " + r.command + " " + r.parameters).toLowerCase();
          return r;
        });
        if (state.tab === "pending") renderPendings();
      });
    }
    var rows = pagedClient(items);
    var pages = Math.max(1, Math.ceil(rows.length / state.limit));
    if (state.page > pages) state.page = pages;
    var slice = rows.slice((state.page - 1) * state.limit, state.page * state.limit);
    els.rows.innerHTML =
      slice
        .map(function (r) {
          return (
            "<tr><td>" + esc(r.device) + "</td><td>" + esc(r.command) + "</td><td>" +
            esc(r.parameters || "-") + "</td><td>" + r.attempts + "</td>" +
            '<td class="text-slate-500">' + esc(r.last_error || "—") + "</td><td>" + esc(r.date) + "</td>" +
            '<td class="tp-svc-table__actions"><button type="button" class="tp-svc-kebab__btn" data-pend-del="' +
            r.id + '" title="' + esc(T("delete")) + '" style="color:var(--danger-500,#ef4444);">' + TRASH + "</button></td></tr>"
          );
        })
        .join("") || emptyRow(7);
    renderPager(rows.length, state.page, pages);
  }

  /* ---------- Schedules ---------- */
  function renderSchedules() {
    setHead([T("connection"), T("command"), T("parameters"), T("schedule_at"), T("type"), ""]);
    var items = state.schedules;
    if (!items) {
      els.rows.innerHTML = emptyRow(6, T("loading") + "…");
      return loadSchedules();
    }
    var rows = pagedClient(items);
    var pages = Math.max(1, Math.ceil(rows.length / state.limit));
    if (state.page > pages) state.page = pages;
    var slice = rows.slice((state.page - 1) * state.limit, state.page * state.limit);
    els.rows.innerHTML =
      slice
        .map(function (r) {
          return (
            "<tr><td>" + esc(r.connection) + "</td><td>" + esc(r.command) + "</td><td>" +
            esc(r.parameters || "-") + "</td><td>" + esc(r.schedule_at || "-") + "</td><td>" +
            esc(T(r.type, r.type)) + '</td><td class="tp-svc-table__actions">' + kebab(r.id, true) + "</td></tr>"
          );
        })
        .join("") || emptyRow(6);
    renderPager(rows.length, state.page, pages);
  }
  function loadSchedules() {
    tpModal.fetchJSON(CFG.urls.schedules).then(function (res) {
      state.schedules = ((res.data && res.data.items) || []).map(function (r) {
        r._search = (r.connection + " " + r.command + " " + r.parameters).toLowerCase();
        return r;
      });
      if (state.tab === "schedule") renderSchedules();
    });
  }

  /* ---------- Logs (server-side) ---------- */
  function logBadge(ok) {
    return ok
      ? '<span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">' +
          esc(T("sent", "Enviado")) + "</span>"
      : '<span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300">' +
          esc(T("failed", "Fallido")) + "</span>";
  }
  var EYE =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  function viewBtn(attr, idx) {
    return (
      '<button type="button" class="tp-svc-kebab__btn" ' + attr + '="' + idx + '" title="' +
      esc(T("command", "Comando")) + '">' + EYE + "</button>"
    );
  }
  /** Modal de detalle: cuerpo del comando (parámetros) + respuesta. */
  function openLogDetail(r, showUser) {
    var line = function (label, valueHtml) {
      return (
        '<div class="flex gap-3 py-2 border-0 border-t border-solid border-slate-100 dark:border-slate-700 first:border-t-0">' +
        '<span class="w-28 shrink-0 text-[12px] font-semibold text-slate-500 dark:text-slate-400">' + esc(label) + "</span>" +
        '<span class="flex-1 min-w-0 text-[13px] text-slate-800 dark:text-slate-100 break-words">' + valueHtml + "</span></div>"
      );
    };
    var mono = function (v) {
      return v
        ? '<pre class="m-0 whitespace-pre-wrap break-words font-mono text-[12px] bg-slate-50 dark:bg-slate-700/50 rounded-md px-2.5 py-2">' + esc(v) + "</pre>"
        : "—";
    };
    var body =
      '<div>' +
      line(T("device", "Dispositivo"), esc(r.device || "—")) +
      line(T("connection", tpT('ui.connection')), esc(r.connection)) +
      line(T("command", "Comando"), esc(r.command)) +
      line(T("status", "Estado"), logBadge(r.status)) +
      line(T("date", "Fecha"), esc(r.date)) +
      (showUser && r.user ? line(T("user", "Usuario"), esc(r.user)) : "") +
      line(T("parameters", tpT('ui.parameters')), mono(r.parameters)) +
      line(T("response", "Respuesta"), mono(r.response)) +
      "</div>";
    tpModal.open({
      title: T("command", "Comando"),
      size: "md",
      body: body,
      buttons: [{ label: T("cancel"), variant: "ghost" }],
    });
  }
  function renderLogs() {
    setHead([T("device"), T("connection"), T("command"), T("status"), T("date")]);
    els.rows.innerHTML = emptyRow(5, T("loading") + "…");
    var u =
      CFG.urls.logs + "?page=" + state.page + "&limit=" + state.limit +
      "&q=" + encodeURIComponent(state.q) + "&connection=" + encodeURIComponent(state.conn || "");
    tpModal.fetchJSON(u).then(function (res) {
      if (state.tab !== "logs") return;
      var d = res.data || {};
      var showUser = !!d.show_user;
      state.logs = { items: d.items || [], page: d.page || 1, last: d.last || 1, total: d.total || 0 };
      // La cabecera depende de show_user (columna Usuario para admin/manager)
      var cols = [T("device"), T("connection"), T("command"), T("status"), T("date")];
      if (showUser) cols.push(T("user"));
      cols.push(""); // acciones (ver cuerpo del comando)
      setHead(cols);
      state.logs.showUser = showUser;
      els.rows.innerHTML =
        state.logs.items
          .map(function (r, i) {
            return (
              "<tr><td>" + esc(r.device) + "</td><td>" + esc(r.connection) + "</td><td>" +
              esc(r.command) + "</td><td>" + logBadge(r.status) + "</td><td>" + esc(r.date) + "</td>" +
              (showUser ? "<td>" + esc(r.user || "—") + "</td>" : "") +
              '<td class="tp-svc-table__actions">' + viewBtn("data-log-view", i) + "</td></tr>"
            );
          })
          .join("") || emptyRow(cols.length);
      renderPager(state.logs.total, state.logs.page, state.logs.last);
    });
  }

  /* ---------- Logs de UN schedule (modal, paridad command_schedules.logs) ---------- */
  function openScheduleLogs(id) {
    var pg = { page: 1, limit: 15 };
    var dlg = tpModal.open({
      title: T("command_schedule") + " · " + T("logs"),
      size: "lg",
      body:
        '<div class="rounded-lg border border-slate-200 dark:border-slate-600 overflow-x-auto">' +
        '<table class="tp-svc-table"><thead><tr><th>' +
        [T("device"), T("connection"), T("command"), T("status"), T("date")].map(esc).join("</th><th>") +
        '</th><th class="tp-svc-table__actions"></th></tr></thead>' +
        '<tbody data-slog-rows><tr><td colspan="6" class="tp-svc-table__empty">' +
        esc(T("loading")) + "…</td></tr></tbody></table></div>" +
        '<div class="flex items-center justify-end gap-2 mt-3 text-[12px] text-slate-500">' +
        '<span data-slog-range>—</span>' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-slog-prev>‹</button>' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-slog-next>›</button></div>',
      buttons: [{ label: T("cancel"), variant: "ghost" }],
      onMount: function (d) {
        var current = [];
        d.querySelector("[data-slog-rows]").addEventListener("click", function (e) {
          var b = e.target.closest("[data-slog-view]");
          if (!b) return;
          var r = current[parseInt(b.getAttribute("data-slog-view"), 10)];
          if (r) openLogDetail(r, false);
        });
        var load = function () {
          tpModal
            .fetchJSON(CFG.urls.schedules + "/" + id + "/logs?page=" + pg.page + "&limit=" + pg.limit)
            .then(function (res) {
              var data = res.data || {};
              var rows = data.items || [];
              current = rows;
              d.querySelector("[data-slog-rows]").innerHTML =
                rows
                  .map(function (r, i) {
                    return (
                      "<tr><td>" + esc(r.device) + "</td><td>" + esc(r.connection) + "</td><td>" +
                      esc(r.command) + "</td><td>" + logBadge(r.status) + "</td><td>" + esc(r.date) + "</td>" +
                      '<td class="tp-svc-table__actions">' + viewBtn("data-slog-view", i) + "</td></tr>"
                    );
                  })
                  .join("") || '<tr><td colspan="6" class="tp-svc-table__empty">' + esc(T("not_found")) + "</td></tr>";
              d.querySelector("[data-slog-range]").textContent = data.total
                ? (data.page - 1) * pg.limit + 1 + "–" + Math.min(data.page * pg.limit, data.total) + " / " + data.total
                : "0";
              d.querySelector("[data-slog-prev]").disabled = (data.page || 1) <= 1;
              d.querySelector("[data-slog-next]").disabled = (data.page || 1) >= (data.last || 1);
            });
        };
        d.querySelector("[data-slog-prev]").addEventListener("click", function () {
          pg.page--;
          load();
        });
        d.querySelector("[data-slog-next]").addEventListener("click", function () {
          pg.page++;
          load();
        });
        load();
      },
    });
    return dlg;
  }

  /* ---------- Templates ---------- */
  function renderTemplates(type) {
    // La columna de protocolo tambien va tras device.protocol en el legacy
    // (UserGprsTemplates/table.blade). Si el meta aun no ha llegado se asume
    // visible, igual que antes.
    var tplMeta = state.tplMeta[type];
    var showProto = !tplMeta || tplMeta.can_protocol !== false;

    setHead(showProto ? [T("title"), T("adapted"), T("protocol"), ""] : [T("title"), T("adapted"), ""]);
    // El colspan de la fila vacia tiene que seguir al numero real de columnas.
    var cols = showProto ? 4 : 3;

    var items = state.templates[type];
    if (!items) {
      els.rows.innerHTML = emptyRow(cols, T("loading") + "…");
      return loadTemplates(type);
    }
    var rows = pagedClient(items);
    var pages = Math.max(1, Math.ceil(rows.length / state.limit));
    if (state.page > pages) state.page = pages;
    var slice = rows.slice((state.page - 1) * state.limit, state.page * state.limit);
    els.rows.innerHTML =
      slice
        .map(function (r) {
          return (
            "<tr><td>" + esc(r.title) + "</td><td>" + esc(r.adapted_title) + "</td>" +
            (showProto ? "<td>" + esc(r.protocol || "-") + "</td>" : "") +
            '<td class="tp-svc-table__actions">' + kebab(r.id) + "</td></tr>"
          );
        })
        .join("") || emptyRow(cols);
    renderPager(rows.length, state.page, pages);
  }
  function loadTemplates(type) {
    tpModal.fetchJSON(CFG.urls.templates + "?type=" + type).then(function (res) {
      var d = res.data || {};
      state.templates[type] = (d.items || []).map(function (r) {
        r._search = (r.title + " " + r.message).toLowerCase();
        return r;
      });
      state.tplMeta[type] = {
        adapties: optListToMap(d.adapties),
        protocols: optListToMap(d.protocols),
        device_types: optListToMap(d.device_types),
        can_protocol: d.can_protocol,
        can_device_type: d.can_device_type,
      };
      if (state.tab === type) renderTemplates(type);
    });
  }

  /* ============================ Modal: plantilla ============================ */

  function openTemplateModal(type, item) {
    var meta = state.tplMeta[type] || { adapties: [], protocols: [], device_types: [] };
    var devices = (state.cmdMeta && state.cmdMeta.devices) || [];
    var checked = {};
    (item ? item.devices || [] : []).forEach(function (id) {
      checked[id] = true;
    });

    var adaptedOpts = meta.adapties
      .map(function (o) {
        return '<option value="' + esc(o.id === "0" ? "" : o.id) + '"' + (item && String(item.adapted || "") === (o.id === "0" ? "" : o.id) ? " selected" : "") + ">" + esc(o.title) + "</option>";
      })
      .join("");
    var protoOpts = meta.protocols
      .map(function (o) {
        return '<option value="' + esc(o.id) + '"' + (item && item.protocol === o.id ? " selected" : "") + ">" + esc(o.title) + "</option>";
      })
      .join("");
    var dtypeOpts = meta.device_types
      .map(function (o) {
        return '<option value="' + esc(o.id) + '">' + esc(o.title) + "</option>";
      })
      .join("");

    var body =
      '<div class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-label">' + esc(T("title")) +
      '</label><input type="text" name="title" class="tp-input" value="' + esc(item ? item.title : "") + '"></div>' +
      '<div class="tp-field"><label class="tp-label">' + esc(T("adapted")) +
      '</label><select name="adapted" class="tp-input" data-tpl-adapted>' + adaptedOpts + "</select></div>" +
      // Permiso de propiedad device.protocol: sin el, el legacy no pinta el
      // campo (no lo pinta deshabilitado: no existe).
      (meta.can_protocol === false
        ? ""
        : '<div class="tp-field" data-tpl-proto hidden><label class="tp-label">' + esc(T("protocol")) +
          '</label><select name="protocol" class="tp-input" data-no-ts="1">' + protoOpts + "</select></div>") +
      '<div class="tp-field" data-tpl-devices hidden><label class="tp-label">' + esc(T("devices")) +
      '</label><div class="tp-input-wrap"><input type="text" class="tp-input tp-input--sm" data-tpl-devsearch placeholder="' +
      esc(T("search")) + '…"></div><div class="' + UI.list + '" data-tpl-devlist></div></div>' +
      // Idem con device.device_type_id.
      (meta.can_device_type === false
        ? ""
        : '<div class="tp-field" data-tpl-dtypes hidden><label class="tp-label">' + esc(T("device_types")) +
          '</label><select name="device_types" class="tp-input" multiple size="5" data-no-ts="1">' + dtypeOpts + "</select></div>") +
      '<div class="tp-field"><label class="tp-label">' + esc(T("message")) +
      '</label><textarea name="message" class="tp-textarea" rows="3">' + esc(item ? item.message : "") + "</textarea></div>" +
      // Uso en el widget de bloqueo de motor (solo GPRS). El equipo elige la
      // plantilla adaptada a su TIPO de dispositivo.
      (type === "gprs"
        ? '<div class="tp-field"><label class="tp-label">' + tpT('ui.use_in_engine_lock') + '</label>' +
          '<select name="lock_action" class="tp-input" data-no-ts="1">' +
          '<option value="">' + tpT('ui.none_m') + '</option>' +
          '<option value="block"' + (item && item.lock_action === "block" ? " selected" : "") + ">" + tpT('ui.cut_block') + "</option>" +
          '<option value="unblock"' + (item && item.lock_action === "unblock" ? " selected" : "") + ">" + tpT('ui.restore_unblock') + "</option>" +
          "</select></div>"
        : "") +
      '<div class="rounded-lg bg-sky-50 text-sky-800 dark:bg-sky-900/30 dark:text-sky-200 px-3 py-2.5 text-[12.5px] leading-relaxed">' +
      esc(T("raw_hint")) + "<br>" + esc(T("variables_hint")) + "</div></div>";

    tpModal.open({
      title: type === "sms" ? T("sms_templates") : T("gprs_templates"),
      size: "lg",
      body: body,
      buttons: [
        { label: T("cancel"), variant: "ghost" },
        {
          label: T("save"),
          variant: "accent",
          close: false,
          onClick: function (dlg, btn) {
            var payload = {
              type: type,
              title: dlg.querySelector('[name="title"]').value.trim(),
              adapted: dlg.querySelector('[name="adapted"]').value,
              protocol: dlg.querySelector('[name="protocol"]').value,
              message: dlg.querySelector('[name="message"]').value,
              devices: Object.keys(checked).filter(function (id) {
                return checked[id];
              }),
              device_types: Array.prototype.map.call(
                dlg.querySelector('[name="device_types"]').selectedOptions,
                function (o) {
                  return o.value;
                },
              ),
              lock_action: (dlg.querySelector('[name="lock_action"]') || {}).value || "",
            };
            btn.disabled = true;
            var url = CFG.urls.templates + (item ? "/" + item.id : "");
            tpModal.fetchJSON(url, { method: item ? "PUT" : "POST", json: payload }).then(function (res) {
              btn.disabled = false;
              if (res.ok && res.data && res.data.ok) {
                tpModal.toast(T("changes_saved"), "success");
                tpModal.close(dlg);
                state.templates[type] = null;
                render();
              } else {
                var errs = res.data && res.data.errors;
                if (errs) tpModal.showFieldErrors(dlg, errs);
                else tpModal.toast((res.data && res.data.message) || T("error"), "error");
              }
            });
            return false;
          },
        },
      ],
      onMount: function (dlg) {
        var adaptedSel = dlg.querySelector("[data-tpl-adapted]");
        var renderDevs = function (q) {
          q = (q || "").toLowerCase();
          dlg.querySelector("[data-tpl-devlist]").innerHTML = devices
            .filter(function (d) {
              return !q || d.name.toLowerCase().indexOf(q) !== -1;
            })
            .map(function (d) {
              return (
                '<label class="' + UI.item + '"><input type="checkbox" class="' + UI.chk + '" data-tpl-dev value="' + d.id + '"' +
                (checked[d.id] ? " checked" : "") + '><span class="truncate">' + esc(d.name) + "</span></label>"
              );
            })
            .join("");
        };
        var sync = function () {
          var v = adaptedSel.value;
          dlg.querySelector("[data-tpl-proto]").hidden = v !== "protocol";
          dlg.querySelector("[data-tpl-devices]").hidden = v !== "devices";
          dlg.querySelector("[data-tpl-dtypes]").hidden = v !== "device_types";
          if (v === "devices") renderDevs("");
        };
        adaptedSel.addEventListener("change", sync);
        sync();
        dlg.querySelector("[data-tpl-devlist]").addEventListener("change", function (e) {
          var c = e.target.closest("[data-tpl-dev]");
          if (c) checked[c.value] = c.checked;
        });
        var ds = dlg.querySelector("[data-tpl-devsearch]");
        if (ds)
          ds.addEventListener("input", function () {
            renderDevs(ds.value);
          });
      },
    });
  }

  /* ============================ Modal: schedule ============================ */

  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }
  /** <option> numéricas from..to (inyecta selected si no está en el rango). */
  function numOptions(from, to, selected) {
    var html = "";
    var have = false;
    for (var i = from; i <= to; i++) {
      var sel = String(selected) === String(i);
      if (sel) have = true;
      html += '<option value="' + i + '"' + (sel ? " selected" : "") + ">" + i + "</option>";
    }
    if (selected != null && selected !== "" && !have)
      html = '<option value="' + esc(selected) + '" selected>' + esc(selected) + "</option>" + html;
    return html;
  }
  /** <option> de horas cada 5 min (00:00..23:55); inyecta el valor si no calza. */
  function timeOptions(selected) {
    var html = "";
    var have = false;
    for (var h = 0; h < 24; h++) {
      for (var m = 0; m < 60; m += 5) {
        var t = pad2(h) + ":" + pad2(m);
        var sel = selected === t;
        if (sel) have = true;
        html += '<option value="' + t + '"' + (sel ? " selected" : "") + ">" + t + "</option>";
      }
    }
    if (selected && !have)
      html = '<option value="' + esc(selected) + '" selected>' + esc(selected) + "</option>" + html;
    return html;
  }

  var WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"];
  function weekdayLabel(day) {
    // Etiqueta localizada vía Intl (misma técnica que History)
    var base = new Date(Date.UTC(2024, 0, 1)); // lunes
    var idx = WEEKDAYS.indexOf(day);
    var d = new Date(base.getTime() + idx * 86400000);
    try {
      var s = new Intl.DateTimeFormat(document.documentElement.lang || "es", { weekday: "long" }).format(d);
      return s.charAt(0).toUpperCase() + s.slice(1);
    } catch (e) {
      return day;
    }
  }

  function openScheduleModal(item) {
    var S = {
      conn: item ? item.connection : "gprs",
      checked: {},
      commands: [],
      seq: 0,
    };
    (item ? item.devices || [] : []).forEach(function (id) {
      S.checked[id] = true;
    });

    var schedType = item ? item.type || "exact_time" : "exact_time";
    var tp = (item && item.type_params) || {};

    function schedRadio(key, label) {
      return (
        '<label class="' + UI.radio + '"><input type="radio" class="' + UI.chk + '" name="schedule_type" value="' + key + '"' +
        (schedType === key ? " checked" : "") + "><span>" + esc(label) + "</span></label>"
      );
    }

    // Semanal estilo referencia: fila por día con SU propia hora
    var weeklyRows = WEEKDAYS.map(function (d) {
      var dayCfg = tp.days && tp.days[d];
      var on = !!dayCfg;
      return (
        '<div class="flex items-center gap-3 py-1">' +
        '<input type="checkbox" class="' + UI.chk + '" data-sch-day value="' + d + '"' + (on ? " checked" : "") + ">" +
        '<span class="w-24 shrink-0 text-[13px] text-slate-700 dark:text-slate-200">' + esc(weekdayLabel(d)) + "</span>" +
        '<select class="tp-input" data-sch-daytime="' + d + '" style="width:120px;"' + (on ? "" : " disabled") + ">" +
        timeOptions((dayCfg && dayCfg.time) || "00:00") + "</select></div>"
      );
    }).join("");
    var autoOn = !!(item && item.params_raw && item.params_raw.auto_online);

    var body =
      '<div class="tp-cmd grid grid-cols-1 md:grid-cols-2 gap-5">' +
      // Columna izquierda: comando
      "<div>" +
      '<div class="tp-field"><label class="tp-label">' + esc(T("connection")) + "</label>" +
      '<div class="flex gap-5 mt-1.5">' +
      '<label class="' + UI.radio + '"><input type="radio" class="' + UI.chk + '" name="sch_conn" value="gprs"' +
      (S.conn !== "sms" ? " checked" : "") + "><span>GPRS</span></label>" +
      '<label class="' + UI.radio + '"><input type="radio" class="' + UI.chk + '" name="sch_conn" value="sms"' +
      (S.conn === "sms" ? " checked" : "") + "><span>SMS</span></label>" +
      "</div></div>" +
      // Auto-send when online (solo GPRS)
      '<label class="' + UI.radio + ' mt-3" data-sch-autowrap' + (S.conn === "sms" ? ' style="display:none;"' : "") + ">" +
      '<input type="checkbox" class="' + UI.chk + '" data-sch-auto' + (autoOn ? " checked" : "") + "><span>" +
      esc(T("auto_send_when_online")) + "</span></label>" +
      '<div class="mt-4"><div class="' + UI.bar + '"><label class="' + UI.label + '" style="margin:0;">' +
      esc(T("devices")) + '</label><div class="' + UI.sel + '">' +
      '<button type="button" class="' + UI.linkBtn + '" data-sch-all>' + esc(T("select_all")) + "</button>" +
      '<button type="button" class="' + UI.linkBtn + '" data-sch-none>' + esc(T("deselect_all")) + "</button>" +
      "</div></div>" +
      '<div class="mt-2"><input type="text" class="tp-input" data-sch-search placeholder="' +
      esc(T("search")) + '…"></div>' +
      '<div class="' + UI.list + '" data-sch-devices></div></div>' +
      // Tipo + atributos: SOLO visibles con dispositivos seleccionados
      '<div data-sch-typewrap' + (Object.keys(S.checked).length ? "" : " hidden") + ">" +
      '<div class="mt-4"><label class="' + UI.label + '">' + esc(T("type")) +
      '</label><select class="tp-input" data-sch-type></select></div>' +
      '<div class="grid grid-cols-1 gap-3 tp-mt-3" data-sch-attrs></div>' +
      "</div>" +
      "</div>" +
      // Columna derecha: programación
      "<div>" +
      '<div class="tp-field"><label class="tp-label">' + esc(T("schedule")) + "</label>" +
      '<div class="flex flex-col gap-2 mt-1" data-sch-types>' +
      schedRadio("exact_time", T("exact_time")) +
      '<div data-sch-box="exact_time" class="pl-6"' + (schedType !== "exact_time" ? " hidden" : "") + ">" +
      '<input type="datetime-local" step="60" class="tp-input" data-sch-exact value="' +
      esc(tp.time ? String(tp.time).replace(" ", "T").slice(0, 16) : "") + '"></div>' +
      schedRadio("hourly", T("hourly")) +
      '<div data-sch-box="hourly" class="pl-6"' + (schedType !== "hourly" ? " hidden" : "") + ">" +
      '<label class="' + UI.label + '">' + esc(T("minute")) +
      '</label><select class="tp-input" data-sch-minute style="width:120px;">' +
      numOptions(0, 59, tp.minute != null ? tp.minute : 0) + "</select></div>" +
      schedRadio("daily", T("daily")) +
      '<div data-sch-box="daily" class="pl-6"' + (schedType !== "daily" ? " hidden" : "") + ">" +
      '<label class="' + UI.label + '">' + esc(T("time")) +
      '</label><select class="tp-input" data-sch-daily style="width:120px;">' +
      timeOptions(schedType === "daily" && tp.time ? tp.time : "00:00") + "</select></div>" +
      schedRadio("weekly", T("weekly")) +
      '<div data-sch-box="weekly" class="pl-6"' + (schedType !== "weekly" ? " hidden" : "") + ">" +
      weeklyRows + "</div>" +
      schedRadio("monthly", T("monthly")) +
      '<div data-sch-box="monthly" class="pl-6"' + (schedType !== "monthly" ? " hidden" : "") + ">" +
      '<div class="flex gap-3">' +
      '<div><label class="' + UI.label + '">' + esc(T("day")) +
      '</label><select class="tp-input" data-sch-monthday style="width:100px;">' +
      numOptions(1, 31, tp.day != null ? tp.day : 1) + "</select></div>" +
      '<div><label class="' + UI.label + '">' + esc(T("time")) +
      '</label><select class="tp-input" data-sch-monthtime style="width:120px;">' +
      timeOptions(schedType === "monthly" && tp.time ? tp.time : "00:00") + "</select></div>" +
      "</div></div>" +
      "</div></div>" +
      "</div></div>";

    tpModal.open({
      title: T("command_schedule"),
      size: "xl",
      body: body,
      buttons: [
        { label: T("cancel"), variant: "ghost" },
        {
          label: T("save"),
          variant: "accent",
          close: false,
          onClick: function (dlg, btn) {
            saveSchedule(dlg, btn);
            return false;
          },
        },
      ],
      onMount: function (dlg) {
        // Devices desde el meta compartido de comandos
        var ensureMeta = state.cmdMeta
          ? Promise.resolve()
          : tpModal.fetchJSON(CFG.urls.meta).then(function (res) {
              state.cmdMeta = (res.data && res.data.ok && res.data) || { devices: [], sms_gateway: false };
            });
        ensureMeta.then(function () {
          renderDevs(dlg, "");
          loadCmds(dlg, item ? item.command : null);
        });

        dlg.querySelectorAll('[name="sch_conn"]').forEach(function (r) {
          r.addEventListener("change", function () {
            S.conn = dlg.querySelector('[name="sch_conn"]:checked').value;
            // Auto-send solo GPRS ([hidden] pierde vs inline-flex → display)
            var aw = dlg.querySelector("[data-sch-autowrap]");
            if (aw) aw.style.display = S.conn === "sms" ? "none" : "";
            renderDevs(dlg, dlg.querySelector("[data-sch-search]").value);
            loadCmds(dlg, null);
          });
        });
        // Semanal: cada día habilita/deshabilita SU select de hora
        dlg.addEventListener("change", function (e) {
          var day = e.target.closest("[data-sch-day]");
          if (!day) return;
          var sel = dlg.querySelector('[data-sch-daytime="' + day.value + '"]');
          if (!sel) return;
          sel.disabled = !day.checked;
          if (sel._tpdd) sel._tpdd.btn.disabled = !day.checked;
        });
        dlg.querySelector("[data-sch-search]").addEventListener("input", function () {
          renderDevs(dlg, this.value);
        });
        dlg.querySelector("[data-sch-devices]").addEventListener("change", function (e) {
          var c = e.target.closest("[data-sch-dev]");
          if (!c) return;
          S.checked[c.value] = c.checked;
          loadCmds(dlg, null);
        });
        dlg.querySelector("[data-sch-all]").addEventListener("click", function () {
          devsFor().forEach(function (d) {
            S.checked[d.id] = true;
          });
          renderDevs(dlg, "");
          loadCmds(dlg, null);
        });
        dlg.querySelector("[data-sch-none]").addEventListener("click", function () {
          S.checked = {};
          renderDevs(dlg, "");
          loadCmds(dlg, null);
        });
        dlg.querySelector("[data-sch-type]").addEventListener("change", function () {
          renderAttrs(dlg, null);
        });
        dlg.querySelectorAll('[name="schedule_type"]').forEach(function (r) {
          r.addEventListener("change", function () {
            dlg.querySelectorAll("[data-sch-box]").forEach(function (b) {
              b.hidden = b.getAttribute("data-sch-box") !== r.value;
            });
          });
        });
      },
    });

    function devsFor() {
      var all = (state.cmdMeta && state.cmdMeta.devices) || [];
      return S.conn === "sms"
        ? all.filter(function (d) {
            return d.sms;
          })
        : all;
    }
    function renderDevs(dlg, q) {
      q = (q || "").toLowerCase();
      dlg.querySelector("[data-sch-devices]").innerHTML = devsFor()
        .filter(function (d) {
          return !q || d.name.toLowerCase().indexOf(q) !== -1;
        })
        .map(function (d) {
          return (
            '<label class="' + UI.item + '"><input type="checkbox" class="' + UI.chk + '" data-sch-dev value="' + d.id + '"' +
            (S.checked[d.id] ? " checked" : "") + '><span class="truncate">' + esc(d.name) + "</span></label>"
          );
        })
        .join("");
    }
    function loadCmds(dlg, presetType) {
      var ids = Object.keys(S.checked).filter(function (k) {
        return S.checked[k];
      });
      var sel = dlg.querySelector("[data-sch-type]");
      var seq = ++S.seq;
      // Tipo/atributos solo con dispositivos seleccionados
      var wrap = dlg.querySelector("[data-sch-typewrap]");
      if (wrap) wrap.hidden = !ids.length;
      if (!ids.length) {
        S.commands = [];
        sel.innerHTML = "";
        if (window.tpSelect) tpSelect.refresh(sel);
        renderAttrs(dlg, null);
        return;
      }
      tpModal.fetchJSON(CFG.urls.list, { method: "POST", json: { devices: ids, connection: S.conn } }).then(function (res) {
        if (seq !== S.seq) return;
        S.commands = res.ok && Array.isArray(res.data) ? res.data : [];
        var cur = presetType || sel.value;
        sel.innerHTML = S.commands
          .map(function (c) {
            return '<option value="' + esc(c.type) + '">' + esc(c.title) + "</option>";
          })
          .join("");
        if (cur && sel.querySelector('option[value="' + CSS.escape(cur) + '"]')) sel.value = cur;
        if (window.tpSelect) tpSelect.refresh(sel);
        renderAttrs(dlg, item && presetType ? item.params_raw : null);
      });
    }
    function renderAttrs(dlg, values) {
      var box = dlg.querySelector("[data-sch-attrs]");
      var t = dlg.querySelector("[data-sch-type]").value;
      var cmd = S.commands.filter(function (c) {
        return String(c.type) === String(t);
      })[0];
      var attrs = (cmd && cmd.attributes) || [];
      box.innerHTML = attrs
        .map(function (a) {
          var val = values && values[a.html_name] != null ? values[a.html_name] : a.default != null ? a.default : "";
          var field;
          switch (a.type) {
            case "text":
              field = '<textarea class="tp-textarea" rows="2" name="' + esc(a.html_name) + '">' + esc(val) + "</textarea>";
              break;
            case "select":
            case "multiselect":
              field =
                '<select class="tp-input" name="' + esc(a.html_name) + '" data-no-ts="1"' +
                (a.type === "multiselect" ? " multiple" : "") + ">" +
                (a.options || [])
                  .map(function (o) {
                    return '<option value="' + esc(o.id) + '"' + (String(val) === String(o.id) ? " selected" : "") + ">" + esc(o.title) + "</option>";
                  })
                  .join("") +
                "</select>";
              break;
            default:
              field = '<input type="text" class="tp-input" name="' + esc(a.html_name) + '" value="' + esc(val) + '">';
          }
          return (
            '<div class="tp-field"><label class="tp-label">' + esc(a.title) + "</label>" + field +
            (a.description ? '<div class="tp-hint">' + esc(a.description) + "</div>" : "") +
            "</div>"
          );
        })
        .join("");
    }
    function saveSchedule(dlg, btn) {
      var ids = Object.keys(S.checked).filter(function (k) {
        return S.checked[k];
      });
      var st = dlg.querySelector('[name="schedule_type"]:checked').value;
      var payload = {
        connection: S.conn,
        devices: ids,
        type: dlg.querySelector("[data-sch-type]").value,
        schedule_type: st,
      };
      var auto = dlg.querySelector("[data-sch-auto]");
      if (S.conn === "gprs" && auto && auto.checked) payload.auto_online = 1;
      dlg.querySelectorAll("[data-sch-attrs] [name]").forEach(function (el) {
        payload[el.name] = el.multiple
          ? Array.prototype.map.call(el.selectedOptions, function (o) {
              return o.value;
            })
          : el.value;
      });
      if (st === "exact_time") {
        var v = dlg.querySelector("[data-sch-exact]").value;
        payload.exact_time = { time: v ? v.replace("T", " ") + ":00" : "" };
      } else if (st === "hourly") {
        payload.hourly = { minute: dlg.querySelector("[data-sch-minute]").value };
      } else if (st === "daily") {
        payload.daily = { time: dlg.querySelector("[data-sch-daily]").value };
      } else if (st === "weekly") {
        // Hora POR DÍA (paridad Scheduler: lee options.time de cada weekday)
        var days = {};
        dlg.querySelectorAll("[data-sch-day]:checked").forEach(function (c) {
          var tsel = dlg.querySelector('[data-sch-daytime="' + c.value + '"]');
          days[c.value] = { checked: 1, time: (tsel && tsel.value) || "00:00" };
        });
        payload.weekly = { days: days };
      } else if (st === "monthly") {
        payload.monthly = {
          day: dlg.querySelector("[data-sch-monthday]").value,
          time: dlg.querySelector("[data-sch-monthtime]").value,
        };
      }
      btn.disabled = true;
      var url = CFG.urls.schedules + (item ? "/" + item.id : "");
      tpModal.fetchJSON(url, { method: item ? "PUT" : "POST", json: payload }).then(function (res) {
        btn.disabled = false;
        if (res.ok && res.data && res.data.ok) {
          tpModal.toast(T("changes_saved"), "success");
          tpModal.close(dlg);
          state.schedules = null;
          render();
        } else {
          var errs = res.data && res.data.errors;
          if (errs) {
            var k = Object.keys(errs)[0];
            var e = errs[k];
            tpModal.toast(Array.isArray(e) ? e[0] : e, "error");
          } else tpModal.toast((res.data && res.data.message) || T("error"), "error");
        }
      });
    }
  }

  /* ============================ Acciones ============================ */

  function byId(list, id) {
    return (list || []).filter(function (r) {
      return r.id === id;
    })[0];
  }

  els.rows.addEventListener("click", function (e) {
    var lv = e.target.closest("[data-log-view]");
    if (lv) {
      var r = state.logs.items[parseInt(lv.getAttribute("data-log-view"), 10)];
      if (r) openLogDetail(r, state.logs.showUser);
      return;
    }
    var pd = e.target.closest("[data-pend-del]");
    if (pd) {
      var pid = parseInt(pd.getAttribute("data-pend-del"), 10);
      tpModal
        .confirm({ title: T("delete"), message: T("are_you_sure"), confirmLabel: T("delete"), cancelLabel: T("cancel"), danger: true })
        .then(function (ok) {
          if (!ok) return;
          tpModal.fetchJSON(CFG.urls.pendings + "/" + pid, { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast(T("changes_saved"), "success");
              state.pendings = null;
              render();
            } else tpModal.toast(T("error"), "error");
          });
        });
      return;
    }
    var act = e.target.closest("[data-hub-act]");
    if (!act) return;
    closeKebabs();
    var id = parseInt(act.getAttribute("data-id"), 10);
    var isDel = act.getAttribute("data-hub-act") === "del";

    if (state.tab === "schedule") {
      var s = byId(state.schedules, id);
      if (!s) return;
      if (isDel) return del(CFG.urls.schedules + "/" + id, "schedules");
      if (act.getAttribute("data-hub-act") === "logs") return openScheduleLogs(id);
      return openScheduleModal(s);
    }
    if (state.tab === "gprs" || state.tab === "sms") {
      var t = byId(state.templates[state.tab], id);
      if (!t) return;
      if (isDel) return del(CFG.urls.templates + "/" + id + "?type=" + state.tab, "templates");
      return ensureCmdMeta().then(function () {
        openTemplateModal(state.tab, t);
      });
    }
  });

  function del(url, kind) {
    tpModal
      .confirm({ title: T("delete"), message: T("are_you_sure"), confirmLabel: T("delete"), cancelLabel: T("cancel"), danger: true })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON(url, { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) {
            tpModal.toast(T("changes_saved"), "success");
            if (kind === "schedules") state.schedules = null;
            else state.templates[state.tab] = null;
            render();
          } else tpModal.toast(T("error"), "error");
        });
      });
  }

  function ensureCmdMeta() {
    if (state.cmdMeta) return Promise.resolve();
    return tpModal.fetchJSON(CFG.urls.meta).then(function (res) {
      state.cmdMeta = (res.data && res.data.ok && res.data) || { devices: [], sms_gateway: false };
    });
  }

  /* ============================ Tabs + toolbar ============================ */

  // Tabs subrayadas a lo ancho (estilo referencia): activa = blanca con
  // subrayado teal; inactivas = gris sobre fondo suave.
  var TAB_BASE =
    "appearance-none cursor-pointer border-0 flex-1 max-w-[250px] py-3 px-4 text-[13px] font-semibold text-center transition-colors ";
  var TAB_ON =
    TAB_BASE +
    "bg-white text-[color:var(--brand-600,#0d9488)] " +
    "shadow-[inset_0_-2px_0_0_var(--brand-500,#0d9488)] dark:bg-slate-800 dark:text-[color:var(--brand-400,#2dd4bf)]";
  var TAB_OFF =
    TAB_BASE +
    "bg-slate-50 text-slate-500 hover:text-slate-700 " +
    "shadow-[inset_0_-1px_0_0_theme(colors.slate.200)] dark:bg-slate-800/60 dark:text-slate-400 dark:shadow-[inset_0_-1px_0_0_theme(colors.slate.600)]";

  function styleTabs() {
    document.querySelectorAll("[data-hub-tab]").forEach(function (b) {
      var on = b.getAttribute("data-hub-tab") === state.tab;
      b.setAttribute("aria-selected", on ? "true" : "false");
      b.className = on ? TAB_ON : TAB_OFF;
    });
    els.add.hidden = state.tab === "logs" || state.tab === "pending";
    var lbl = document.getElementById("tp-cmdhub-add-label");
    if (lbl) lbl.textContent = state.tab === "schedule" ? T("add_schedule") : T("add_template");
    syncConn();
  }

  // El filtro por conexión solo aplica en Registros. tp-select envuelve el
  // <select>: hay que ocultar el WRAPPER (el hidden del select original no
  // afecta al componente), y re-sincronizar tras el enhance (DOMContentLoaded).
  function syncConn() {
    var sel = document.getElementById("tp-cmdhub-conn");
    if (!sel) return;
    var target = sel._tpdd ? sel._tpdd.wrap : sel;
    target.hidden = state.tab !== "logs";
  }
  document.addEventListener("DOMContentLoaded", syncConn);

  document.getElementById("tp-cmdhub-tabs").addEventListener("click", function (e) {
    var b = e.target.closest("[data-hub-tab]");
    if (!b) return;
    state.tab = b.getAttribute("data-hub-tab");
    state.page = 1;
    state.q = "";
    els.search.value = "";
    // La cola cambia con cada envío/despacho: recargar al entrar
    if (state.tab === "pending") state.pendings = null;
    styleTabs();
    render();
  });

  els.add.addEventListener("click", function () {
    ensureCmdMeta().then(function () {
      if (state.tab === "schedule") return openScheduleModal(null);
      if (state.tab === "gprs" || state.tab === "sms") {
        var t = state.tab;
        if (!state.tplMeta[t]) {
          tpModal.fetchJSON(CFG.urls.templates + "?type=" + t).then(function (res) {
            var d = res.data || {};
            state.tplMeta[t] = {
              adapties: optListToMap(d.adapties),
              protocols: optListToMap(d.protocols),
              device_types: optListToMap(d.device_types),
            };
            openTemplateModal(t, null);
          });
        } else openTemplateModal(t, null);
      }
    });
  });

  var searchTimer = null;
  els.search.addEventListener("input", function () {
    state.q = this.value.trim();
    state.page = 1;
    if (state.tab === "logs") {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(render, 350);
    } else render();
  });
  els.limit.addEventListener("change", function () {
    state.limit = parseInt(this.value, 10) || 20;
    state.page = 1;
    render();
  });
  var connSel = document.getElementById("tp-cmdhub-conn");
  if (connSel)
    connSel.addEventListener("change", function () {
      state.conn = this.value;
      state.page = 1;
      render();
    });
  els.prev.addEventListener("click", function () {
    state.page--;
    render();
  });
  els.next.addEventListener("click", function () {
    state.page++;
    render();
  });

  styleTabs();
  render();
})();
