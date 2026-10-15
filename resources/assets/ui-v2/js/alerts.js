/* =====================================================
   TalentusPro · Alertas · tabla índice de reglas
   - Tabla de reglas (estado, nombre, tipo, alcance, canales, horario)
   - Toggle de estado + kebab ⋮ (Editar / Activar-Desactivar / Eliminar)
   - Modal CRUD de 6 tabs (Vehículos·Tipo·Geocercas·Horario·Notificaciones·Comando)
   El feed de eventos vive en su propio módulo (/ui/v2/events).
   ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-alerts");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
  var META_URL = root.getAttribute("data-meta-url");
  var STORE_URL = root.getAttribute("data-store-url");
  var COMMANDS_URL = root.getAttribute("data-commands-url");
  var BASE = root.getAttribute("data-toggle-url") || "/ui/v2/api/alerts";
  var CAN_EDIT = root.getAttribute("data-can-edit") === "1";
  var CAN_CREATE = root.getAttribute("data-can-create") === "1";
  var CAN_REMOVE = root.getAttribute("data-can-remove") === "1";
  var IS_ADMIN = root.getAttribute("data-is-admin") === "1";
  var META_CACHE = null;

  var WORKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"];
  var WEEKEND = ["saturday", "sunday"];

  var state = { q: "", rules: [], loading: false };

  /* ----- helpers ----- */
  function $(s, c) {
    return (c || document).querySelector(s);
  }
  function esc(s) {
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
  var escapeHtml = esc;

  var ICON_DOTS =
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';
  var ICON_EDIT =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 113 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
  var ICON_POWER =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>';
  var ICON_TRASH =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>';

  var TYPE_LABELS = {
    sos: "SOS",
    overspeed: tpT('front.overspeed'),
    geofence_overspeed: tpT('ui.geofence_overspeed'),
    zone_in: tpT('ui.zone_entry_alt'),
    zone_out: tpT('ui.zone_exit_alt'),
    idle_duration: tpT('ui.long_idle'),
    stop_duration: "Detenido prolongado",
    operation_stop: tpT('ui.ops_alert_operation_stop'),
    offline_duration: "Offline prolongado",
    move_duration: "Movimiento prolongado",
    move_start: tpT('ui.movement_start'),
    ignition_on: tpT('ui.engine_on'),
    ignition_off: tpT('ui.engine_off'),
    fuel_fill: tpT('ui.refuel'),
    fuel_theft: tpT('ui.fuel_theft'),
    driver: tpT('front.driver'),
    driver_authorized: tpT('ui.authorized_driver'),
    driver_unauthorized: tpT('ui.unauthorized_driver'),
    expiring_device: tpT('ui.device_about_to_expire'),
    expired_device: "Dispositivo expirado",
    expiring_sim: tpT('ui.sim_about_to_expire'),
    expired_sim: "SIM expirada",
    expiring_user: tpT('ui.user_about_to_expire'),
    expired_user: "Usuario expirado",
    unplugged: "Desconectado",
    plugged_in: "Conectado",
    ghost_speed: tpT('ui.ghost_speed'),
    jump_from_stop: tpT('ui.jump_from_stop'),
    task_new: tpT('ui.new_task'),
    task_complete: "Tarea completa",
    task_failed: "Tarea fallida",
    custom: "Evento personalizado",
    distance: tpT('front.distance'),
    time_duration: tpT('ui.temporary_duration'),
  };

  // Localized, human labels for notification channels (badges de la tabla).
  var CHAN_LABEL = {
    color: tpT('ui.color'),
    silent: tpT('ui.anti_repeat'),
    sound: "Sonido",
    popup: "Popup",
    auto_hide: "Auto-ocultar",
    push: "Push",
    email: "Email",
    sms: "SMS",
    webhook: "Webhook",
    sharing_email: "Email comp.",
    sharing_sms: "SMS comp.",
    command: "Comando",
  };

  function typeLabel(t) {
    return TYPE_LABELS[t] || String(t || "").replace(/_/g, " ");
  }

  var ICON_COPY =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';

  /* ----- Kebab de fila ----- */
  function kebabHtml(r) {
    var items = "";
    if (CAN_EDIT)
      items +=
        '<button type="button" role="menuitem" data-act="edit" data-id="' +
        r.id +
        '">' +
        ICON_EDIT +
        " Editar</button>";
    if (CAN_EDIT)
      items +=
        '<button type="button" role="menuitem" data-act="toggle" data-id="' +
        r.id +
        '">' +
        ICON_POWER +
        " " +
        (r.active ? tpT('ui.deactivate') : tpT('ui.activate')) +
        "</button>";
    // Guardar como plantilla: se captura ESTA alerta ya configurada, para
    // sembrarla despues a los clientes que se den de alta.
    if (IS_ADMIN)
      items +=
        '<button type="button" role="menuitem" data-act="template" data-id="' +
        r.id +
        '">' +
        ICON_COPY +
        " " + tpT('ui.save_as_template') + "</button>";
    if (CAN_REMOVE)
      items +=
        '<button type="button" role="menuitem" class="is-danger" data-act="delete" data-id="' +
        r.id +
        '">' +
        ICON_TRASH +
        " Eliminar</button>";
    if (!items) return "";
    return (
      '<div class="tp-svc-kebab"><button type="button" class="tp-svc-kebab__btn" data-alert-kebab aria-haspopup="menu" aria-label="Opciones">' +
      ICON_DOTS +
      '</button><div class="tp-svc-kebab__menu" role="menu" hidden>' +
      items +
      "</div></div>"
    );
  }

  /* ----- Render table ----- */
  function renderTable() {
    var tbody = $("#tp-alerts-rows");
    var count = $("#tp-alerts-count");
    var q = state.q.toLowerCase();
    var rules = state.rules.filter(function (r) {
      return (
        !q ||
        (r.name || "").toLowerCase().indexOf(q) !== -1 ||
        typeLabel(r.type).toLowerCase().indexOf(q) !== -1
      );
    });
    if (count)
      count.textContent = tpT(rules.length === 1 ? 'ui.rules_count_one' : 'ui.rules_count',
        { count: rules.length });

    if (!rules.length) {
      tbody.innerHTML =
        '<tr><td colspan="7" class="tp-svc-table__empty">' +
        (state.rules.length ? tpT('ui.no_matches') : tpT('ui.no_alert_rules_yet')) +
        "</td></tr>";
      return;
    }

    var html = "";
    rules.forEach(function (r) {
      var scope = r.all
        ? tpT('front.all')
        : (r.devices_count || 0) +
          " " +
          ((r.devices_count || 0) === 1 ? tpT('ui.vehicle_lower') : tpT('ui.vehicles_lower'));

      var chans = (r.channels || []).filter(function (c) {
        return c !== "auto_hide";
      });
      var shown = chans.slice(0, 4);
      var extra = chans.length - shown.length;
      var chHtml = shown
        .map(function (c) {
          return '<span class="tp-alerts__chan">' + esc(CHAN_LABEL[c] || c) + "</span>";
        })
        .join("");
      if (extra > 0)
        chHtml +=
          '<span class="tp-alerts__chan tp-alerts__chan--more">+' + extra + "</span>";
      if (!chHtml) chHtml = '<span class="tp-text-muted tp-text-sm">—</span>';

      var sched = r.schedule
        ? '<span class="tp-alerts__rule-type">Restringido</span>'
        : '<span class="tp-text-muted tp-text-sm">' + tpT('global.always') + '</span>';

      var toggle = CAN_EDIT
        ? '<button type="button" class="tp-alert-toggle' +
          (r.active ? " is-on" : "") +
          '" data-toggle-rule="' +
          r.id +
          '" title="' +
          (r.active ? tpT('ui.deactivate') : tpT('ui.activate')) +
          '"></button>'
        : '<span class="tp-alert-toggle' + (r.active ? " is-on" : "") + '"></span>';

      html +=
        '<tr class="tp-alerts__trow' +
        (r.active ? "" : " is-off") +
        '" data-id="' +
        r.id +
        '">' +
        "<td>" +
        toggle +
        "</td>" +
        '<td><span class="tp-alerts__tname">' +
        esc(r.name) +
        "</span></td>" +
        '<td><span class="tp-alerts__rule-type">' +
        esc(typeLabel(r.type)) +
        "</span></td>" +
        "<td>" +
        esc(scope) +
        "</td>" +
        '<td><div class="tp-alerts__rule-chans" style="margin:0;">' +
        chHtml +
        "</div></td>" +
        "<td>" +
        sched +
        "</td>" +
        '<td class="tp-svc-table__actions">' +
        kebabHtml(r) +
        "</td>" +
        "</tr>";
    });
    tbody.innerHTML = html;
  }

  /* ----- Load ----- */
  function load() {
    // Si ya hay una carga en vuelo NO se descarta la nueva: se encola. Antes
    // se devolvia una promesa vacia, asi que al crear una regla mientras
    // corria la carga inicial la tabla (y el contador) se quedaban con los
    // datos viejos hasta recargar la pagina a mano.
    if (state.loading) {
      state.reloadPendiente = true;
      return Promise.resolve();
    }
    state.loading = true;
    return fetch(DATA_URL, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (p) {
        state.loading = false;
        if (state.reloadPendiente) {
          state.reloadPendiente = false;
          return load();
        }
        if (!p || !p.ok) return;
        state.rules = p.rules || [];
        renderTable();
      })
      .catch(function (err) {
        state.loading = false;
        console.warn("[alerts] load failed", err);
      });
  }

  /* ----- Kebab open/close (portal fixed; no lo recorta el overflow de la tabla) ----- */
  function closeKebabs(except) {
    document
      .querySelectorAll(".tp-svc-kebab__menu:not([hidden])")
      .forEach(function (m) {
        if (m !== except) m.setAttribute("hidden", "");
      });
  }
  function positionKebab(btn, menu) {
    var r = btn.getBoundingClientRect();
    menu.style.position = "fixed";
    menu.style.right = "auto";
    var w = menu.offsetWidth,
      h = menu.offsetHeight;
    menu.style.left =
      Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + "px";
    if (r.bottom + 4 + h > window.innerHeight - 8 && r.top - h - 4 > 8)
      menu.style.top = r.top - h - 4 + "px";
    else menu.style.top = r.bottom + 4 + "px";
  }
  document.addEventListener("click", function (e) {
    var kb = e.target.closest("[data-alert-kebab]");
    if (kb) {
      var menu = kb.parentNode.querySelector(".tp-svc-kebab__menu");
      var was = menu && !menu.hasAttribute("hidden");
      closeKebabs();
      if (menu && !was) {
        menu.removeAttribute("hidden");
        positionKebab(kb, menu);
      }
      e.stopPropagation();
      return;
    }
    var act = e.target.closest(".tp-svc-kebab__menu [data-act]");
    if (act) {
      e.stopPropagation();
      var id = act.getAttribute("data-id");
      var a = act.getAttribute("data-act");
      closeKebabs();
      if (a === "edit") openAlertModal(id);
    else if (a === "template") saveAsTemplate(id);
      else if (a === "toggle") toggleRule(id);
      else if (a === "delete") deleteRule(id);
      return;
    }
    closeKebabs();
  });
  window.addEventListener(
    "scroll",
    function (e) {
      if (e.target && e.target.closest && e.target.closest(".tp-svc-kebab__menu"))
        return;
      closeKebabs();
    },
    true,
  );

  /* ----- Wiring ----- */
  var sTimer;
  var searchEl = $("#tp-alerts-search");
  if (searchEl)
    searchEl.addEventListener("input", function (e) {
      clearTimeout(sTimer);
      sTimer = setTimeout(function () {
        state.q = e.target.value.trim();
        renderTable();
      }, 150);
    });

  var refreshEl = $("#tp-alerts-refresh");
  if (refreshEl) refreshEl.addEventListener("click", load);

  var newBtn = $("#tp-alerts-new");
  if (newBtn)
    newBtn.addEventListener("click", function () {
      openAlertModal();
    });

  $("#tp-alerts-rows").addEventListener("click", function (e) {
    var sw = e.target.closest("[data-toggle-rule]");
    if (sw) {
      e.stopPropagation();
      toggleRule(sw.getAttribute("data-toggle-rule"), sw);
      return;
    }
    if (e.target.closest(".tp-svc-kebab")) return; // el kebab lo maneja el listener global
    var tr = e.target.closest("tr[data-id]");
    if (tr && CAN_EDIT) openAlertModal(tr.getAttribute("data-id"));
  });

  var btnTpl = document.getElementById("tp-alerts-templates");
  if (btnTpl) btnTpl.addEventListener("click", openTemplates);

  /* ----- Boot ----- */
  load();

  // Llegada desde el kebab del vehiculo ("Editar alerta"): /ui/v2/alerts?edit=N
  // abre esa regla en el editor completo. Se limpia la URL para que un
  // refresco no vuelva a abrirlo.
  (function abrirDesdeUrl() {
    if (!CAN_EDIT) return;
    var id;
    try {
      id = new URLSearchParams(location.search).get("edit");
    } catch (e) {
      return;
    }
    if (!id) return;

    try {
      history.replaceState(null, "", location.pathname);
    } catch (e) {}

    openAlertModal(id);
  })();

  /* =====================================================
   * Rule modal (full 6-tab CRUD, parity with legacy)
   * ===================================================== */

  function loadMeta() {
    if (META_CACHE) return Promise.resolve(META_CACHE);
    return window.tpModal.fetchJSON(META_URL).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) throw new Error("meta_failed");
      META_CACHE = res.data;
      return META_CACHE;
    });
  }

  function pickName(opt) {
    return opt.name || opt.title || opt.value || "#" + (opt.id || "");
  }

  function buildTypeOptions(types, selectedKey) {
    return (types || [])
      .map(function (t) {
        return (
          '<option value="' +
          esc(t.key) +
          '"' +
          (t.key === selectedKey ? " selected" : "") +
          ">" +
          esc(t.label) +
          "</option>"
        );
      })
      .join("");
  }

  // Per-type attribute fields (from AlertModalHelper). { name,type,title,value,options? }
  /** id del picker de un campo multiselect del tipo de alerta */
  function tfPickId(name) {
    return "tp-alert-tf-" + String(name).replace(/[^a-z0-9_-]/gi, "");
  }

  function renderTypeFields(typeObj) {
    if (!typeObj || !typeObj.fields || !typeObj.fields.length) {
      return '<div class="tp-text-muted tp-text-sm">' + tpT('ui.type_needs_no_config') + '</div>';
    }
    // Los multiselect (geocercas, sensores...) van a lo ancho: en la rejilla
    // de dos columnas el listado queda inservible.
    var anchos = [];
    var cortos = [];
    typeObj.fields.forEach(function (f) {
      (f.type === "multiselect" ? anchos : cortos).push(f);
    });

    var html = "";
    if (cortos.length) {
      html += '<div class="tp-grid-2">';
      cortos.forEach(function (f) { html += fieldHtml(f, null, f.name); });
      html += "</div>";
    }
    anchos.forEach(function (f) { html += fieldHtml(f, null, f.name); });
    return html;
  }

  // Generic field renderer used by type + command attributes.
  function fieldHtml(f, val, nameAttr, cmd) {
    val = val == null ? (f.value == null ? "" : f.value) : val;
    var attr = cmd
      ? 'data-cmd-field="' + esc(f.name) + '"'
      : 'name="' + esc(nameAttr || f.name) + '"';
    var h =
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(f.title || f.name) +
      (f.required ? " *" : "") +
      "</label>";
    // Multiselect: el mismo picker del resto del modal (todo / nada, buscador
    // y casillas), como el selector multiple del legacy. Antes caia en el
    // <select> de abajo por tener opciones y solo dejaba elegir UNA.
    if (f.type === "multiselect") {
      h +=
        picklistHtml(tfPickId(f.name), f.options || [], {
          empty: tpT('ui.no_items'),
        }) + "</div>";
      return h;
    }
    if (f.type === "select" || (f.options && f.options.length)) {
      var opts = (f.options || [])
        .map(function (o) {
          var k = o.id != null ? o.id : o.value;
          var lbl = o.title != null ? o.title : o.value != null ? o.value : k;
          return (
            '<option value="' +
            esc(k) +
            '"' +
            (String(k) === String(val) ? " selected" : "") +
            ">" +
            esc(lbl) +
            "</option>"
          );
        })
        .join("");
      h +=
        '<select class="tp-input" data-no-ts="1" ' + attr + ">" + opts + "</select>";
    } else {
      var it = f.type === "number" || f.type === "integer" ? "number" : "text";
      h +=
        '<input class="tp-input" type="' +
        it +
        '" ' +
        attr +
        ' value="' +
        esc(val) +
        '">';
    }
    if (f.description) h += '<div class="tp-field__hint">' + f.description + "</div>";
    return h + "</div>";
  }

  /* ---------- pick lists (devices / geofences) ---------- */
  function picklistHtml(id, items, opts) {
    opts = opts || {};
    var searchable = items.length > 8;
    var body;
    if (!items.length) {
      body =
        '<div class="tp-text-muted tp-text-sm" style="padding:10px;">' +
        esc(opts.empty || tpT('ui.no_items')) +
        "</div>";
    } else {
      body = items
        .map(function (it) {
          var v = it.id != null ? it.id : it.value;
          var label = pickName(it) + (it.plate ? " · " + it.plate : "");
          return (
            '<label class="tp-picklist__item"><input type="checkbox" value="' +
            esc(v) +
            '"><span>' +
            esc(label) +
            "</span></label>"
          );
        })
        .join("");
    }
    return (
      '<div class="tp-picklist" id="' +
      id +
      '">' +
      '<div class="tp-picklist__bar">' +
      (searchable
        ? '<input type="search" class="tp-input tp-input--sm tp-picklist__search" placeholder="' + tpT('ui.search_ellipsis') + '">'
        : "<span></span>") +
      '<button type="button" class="tp-picklist__act" data-pick="all">' + tpT('front.all') + '</button>' +
      '<button type="button" class="tp-picklist__act" data-pick="none">' + tpT('ui.none_m') + '</button>' +
      '<span class="tp-picklist__count">0</span>' +
      "</div>" +
      '<div class="tp-picklist__list">' +
      body +
      "</div>" +
      "</div>"
    );
  }

  function picklistValues(dlg, sel) {
    var wrap = dlg.querySelector(sel);
    if (!wrap) return [];
    return Array.from(
      wrap.querySelectorAll(".tp-picklist__item input[type=checkbox]:checked"),
    ).map(function (cb) {
      return parseInt(cb.value, 10);
    });
  }

  function checkPicklist(dlg, sel, ids) {
    var set = {};
    (ids || []).forEach(function (i) {
      set[String(i)] = 1;
    });
    var wrap = dlg.querySelector(sel);
    if (!wrap) return;
    wrap
      .querySelectorAll(".tp-picklist__item input[type=checkbox]")
      .forEach(function (cb) {
        cb.checked = !!set[cb.value];
      });
    updatePickCount(wrap);
  }

  function updatePickCount(wrap) {
    var count = wrap.querySelector(".tp-picklist__count");
    if (!count) return;
    count.textContent = wrap.querySelectorAll(
      ".tp-picklist__item input[type=checkbox]:checked",
    ).length;
  }

  function wirePicklist(dlg, id) {
    var wrap = dlg.querySelector("#" + id);
    if (!wrap) return;
    wrap.addEventListener("change", function (e) {
      if (e.target.matches('input[type="checkbox"]')) updatePickCount(wrap);
    });
    wrap.addEventListener("click", function (e) {
      var a = e.target.closest("[data-pick]");
      if (!a) return;
      e.preventDefault();
      var boxes = wrap.querySelectorAll(
        ".tp-picklist__item input[type=checkbox]",
      );
      if (a.getAttribute("data-pick") === "all") {
        boxes.forEach(function (b) {
          if (!b.closest(".tp-picklist__item").hidden) b.checked = true;
        });
      } else {
        boxes.forEach(function (b) {
          b.checked = false;
        });
      }
      updatePickCount(wrap);
    });
    var search = wrap.querySelector(".tp-picklist__search");
    if (search)
      search.addEventListener("input", function () {
        var q = this.value.toLowerCase();
        wrap.querySelectorAll(".tp-picklist__item").forEach(function (it) {
          it.hidden = q && it.textContent.toLowerCase().indexOf(q) === -1;
        });
      });
    updatePickCount(wrap);
  }

  /* ---------- devices (agrupado + paginado + select-all legacy) ---------- */
  function selectedDeviceIds(dlg) {
    if (dlg._devAll) return (dlg._devList || []).map(function (d) { return d.id; });
    return Object.keys(dlg._devSel || {}).map(Number);
  }

  var DEV_PAGE = 100;

  function wireDevices(dlg, meta) {
    var box = dlg.querySelector("#tp-alert-devices");
    if (!box) return;

    var devices = meta.devices || [];
    var groupsMap = {};
    (meta.groups || []).forEach(function (g) {
      groupsMap[g.id] = g.title;
    });
    dlg._devList = devices;
    dlg._devSel = dlg._devSel || {};
    if (typeof dlg._devAll !== "boolean") dlg._devAll = false;

    var page = 1;
    var collapsed = {};
    var listEl = box.querySelector("[data-dev-list]");
    var searchEl = box.querySelector("[data-dev-search]");
    var bannerEl = box.querySelector("[data-dev-banner]");
    var countEl = box.querySelector("[data-dev-count]");
    var pagerEl = box.querySelector("[data-dev-pager]");

    function ordered() {
      var q = (searchEl.value || "").toLowerCase();
      var list = devices.filter(function (d) {
        return (
          !q ||
          d.name.toLowerCase().indexOf(q) !== -1 ||
          (d.plate || "").toLowerCase().indexOf(q) !== -1
        );
      });
      list.sort(function (a, b) {
        if (a.group_id !== b.group_id) {
          if (a.group_id === 0) return -1;
          if (b.group_id === 0) return 1;
          return (groupsMap[a.group_id] || "").localeCompare(groupsMap[b.group_id] || "");
        }
        return a.name.localeCompare(b.name);
      });
      return list;
    }

    function groupDeviceIds(gid) {
      return devices
        .filter(function (d) { return d.group_id === gid; })
        .map(function (d) { return d.id; });
    }
    function groupState(gid) {
      var ids = groupDeviceIds(gid);
      if (!ids.length) return "none";
      var sel = ids.filter(function (id) { return dlg._devSel[id]; }).length;
      return sel === 0 ? "none" : sel === ids.length ? "all" : "some";
    }

    function render() {
      var list = ordered();
      var pages = Math.max(1, Math.ceil(list.length / DEV_PAGE));
      if (page > pages) page = pages;
      var slice = list.slice((page - 1) * DEV_PAGE, page * DEV_PAGE);
      var html = "";
      var lastG = null;
      slice.forEach(function (d) {
        if (d.group_id !== lastG) {
          lastG = d.group_id;
          var gs = groupState(d.group_id);
          html +=
            '<div class="tp-devbox__group">' +
            '<button type="button" class="tp-devbox__gchev" data-dev-collapse="' +
            d.group_id +
            '">' +
            (collapsed[d.group_id] ? "▸" : "▾") +
            "</button>" +
            '<label class="tp-devbox__gcheck"><input type="checkbox" data-dev-group="' +
            d.group_id +
            '"' +
            (gs === "all" ? " checked" : "") +
            (gs === "some" ? ' data-indet="1"' : "") +
            "> <span>" +
            esc(groupsMap[d.group_id] || tpT('front.ungrouped')) +
            "</span></label>" +
            "</div>";
        }
        if (collapsed[d.group_id]) return;
        html +=
          '<label class="tp-devbox__item"><input type="checkbox" data-dev="' +
          d.id +
          '"' +
          (dlg._devSel[d.id] ? " checked" : "") +
          "> <span>" +
          esc(d.name) +
          (d.plate ? ' <em>· ' + esc(d.plate) + "</em>" : "") +
          "</span></label>";
      });
      if (!slice.length)
        html =
          '<div class="tp-text-muted tp-text-sm" style="padding:14px;">' + tpT('ui.no_vehicles') + '</div>';
      listEl.innerHTML = html;
      listEl.querySelectorAll("[data-dev-group][data-indet]").forEach(function (cb) {
        cb.indeterminate = true;
      });

      var selCount = dlg._devAll ? devices.length : Object.keys(dlg._devSel).length;
      countEl.textContent = selCount + " " + tpT('ui.selected_plural');
      bannerEl.hidden = !dlg._devAll;

      pagerEl.innerHTML =
        pages > 1
          ? '<button type="button" class="tp-devbox__pg" data-dev-prev' +
            (page <= 1 ? " disabled" : "") +
            ">‹</button><span>" +
            page +
            " / " +
            pages +
            '</span><button type="button" class="tp-devbox__pg" data-dev-next' +
            (page >= pages ? " disabled" : "") +
            ">›</button>"
          : "";
    }

    dlg._devRender = render;

    searchEl.addEventListener("input", function () {
      page = 1;
      render();
    });
    box.querySelector("[data-dev-all]").addEventListener("click", function () {
      dlg._devAll = true;
      dlg._devSel = {};
      devices.forEach(function (d) { dlg._devSel[d.id] = 1; });
      render();
      box.dispatchEvent(new Event("dev-change"));
    });
    box.querySelector("[data-dev-none]").addEventListener("click", function () {
      dlg._devAll = false;
      dlg._devSel = {};
      render();
      box.dispatchEvent(new Event("dev-change"));
    });
    listEl.addEventListener("change", function (e) {
      var dev = e.target.closest("[data-dev]");
      if (dev) {
        var id = dev.getAttribute("data-dev");
        if (dev.checked) dlg._devSel[id] = 1;
        else {
          delete dlg._devSel[id];
          dlg._devAll = false;
        }
        render();
        box.dispatchEvent(new Event("dev-change"));
        return;
      }
      var grp = e.target.closest("[data-dev-group]");
      if (grp) {
        var gid = parseInt(grp.getAttribute("data-dev-group"), 10);
        var ids = groupDeviceIds(gid);
        if (grp.checked) ids.forEach(function (i) { dlg._devSel[i] = 1; });
        else {
          ids.forEach(function (i) { delete dlg._devSel[i]; });
          dlg._devAll = false;
        }
        render();
        box.dispatchEvent(new Event("dev-change"));
      }
    });
    listEl.addEventListener("click", function (e) {
      var col = e.target.closest("[data-dev-collapse]");
      if (col) {
        var g = col.getAttribute("data-dev-collapse");
        collapsed[g] = !collapsed[g];
        render();
      }
    });
    pagerEl.addEventListener("click", function (e) {
      if (e.target.closest("[data-dev-prev]")) {
        if (page > 1) { page--; render(); }
      } else if (e.target.closest("[data-dev-next]")) {
        page++;
        render();
      }
    });

    render();
  }

  /* ---------- schedule grid ---------- */
  function scheduleGridHtml(schedules) {
    if (!schedules || !schedules.length)
      return '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_schedule_data') + '</div>';

    var items = schedules[0].items || [];
    var hours =
      '<div class="tp-sched__row tp-sched__hours"><span class="tp-sched__daylabel tp-sched__corner"></span><div class="tp-sched__cells">';
    items.forEach(function (it) {
      var h = parseInt(String(it.id).slice(0, 2), 10);
      var isHour = /:00$/.test(it.id);
      var label = isHour && h % 3 === 0 ? it.id : "";
      hours +=
        '<span class="tp-sched__hour' +
        (isHour ? " is-hour" : "") +
        '">' +
        label +
        "</span>";
    });
    hours += "</div></div>";

    var rows = schedules
      .map(function (day) {
        var cells = (day.items || [])
          .map(function (it) {
            var cls =
              "tp-sched__cell" +
              (it.active ? " is-on" : "") +
              (/:00$/.test(it.id) ? " is-hour" : "");
            return (
              '<span class="' +
              cls +
              '" data-day="' +
              esc(day.id) +
              '" data-time="' +
              esc(it.id) +
              '"></span>'
            );
          })
          .join("");
        return (
          '<div class="tp-sched__row">' +
          '<button type="button" class="tp-sched__daylabel" data-day-toggle="' +
          esc(day.id) +
          '" title="' +
          esc(day.title) +
          '">' +
          esc(String(day.title).slice(0, 3).toUpperCase()) +
          "</button>" +
          '<div class="tp-sched__cells">' +
          cells +
          "</div>" +
          "</div>"
        );
      })
      .join("");

    var quick =
      '<div class="tp-sched__quick">' +
      '<button type="button" data-sched-set="workdays">' + tpT('ui.working_days') + '</button>' +
      '<button type="button" data-sched-set="weekend">' + tpT('ui.weekend') + '</button>' +
      '<button type="button" data-sched-set="always">' + tpT('global.always') + '</button>' +
      '<button type="button" data-sched-set="clear">' + tpT('global.clear') + '</button>' +
      "</div>";

    return '<div class="tp-sched__scroll">' + hours + rows + "</div>" + quick;
  }

  function collectSchedules(dlg) {
    var out = {};
    dlg
      .querySelectorAll("#tp-alert-sched .tp-sched__cell.is-on")
      .forEach(function (c) {
        var d = c.getAttribute("data-day");
        var t = c.getAttribute("data-time");
        (out[d] = out[d] || []).push(t);
      });
    Object.keys(out).forEach(function (d) {
      out[d].sort();
    });
    return out;
  }

  function wireSchedule(dlg) {
    var toggle = dlg.querySelector("#tp-alert-sched-toggle");
    var grid = dlg.querySelector("#tp-alert-sched");
    if (toggle && grid) {
      var sync = function () {
        grid.hidden = !toggle.checked;
      };
      toggle.addEventListener("change", sync);
      sync();
    }
    if (!grid) return;

    // Tooltip de hora (cuelga del dlg → se elimina al cerrar el modal).
    var tip = document.createElement("div");
    tip.className = "tp-sched__tip";
    tip.hidden = true;
    dlg.appendChild(tip);
    grid.addEventListener("mousemove", function (e) {
      var c = e.target.closest(".tp-sched__cell");
      if (!c) {
        tip.hidden = true;
        return;
      }
      tip.textContent = c.getAttribute("data-time");
      tip.hidden = false;
      tip.style.left = e.clientX + "px";
      tip.style.top = e.clientY - 30 + "px";
    });
    grid.addEventListener("mouseleave", function () {
      tip.hidden = true;
    });

    var painting = false,
      paintOn = true;
    grid.addEventListener("mousedown", function (e) {
      var c = e.target.closest(".tp-sched__cell");
      if (!c) return;
      e.preventDefault();
      painting = true;
      paintOn = !c.classList.contains("is-on");
      c.classList.toggle("is-on", paintOn);
    });
    grid.addEventListener("mouseover", function (e) {
      if (!painting) return;
      var c = e.target.closest(".tp-sched__cell");
      if (c) c.classList.toggle("is-on", paintOn);
    });
    document.addEventListener("mouseup", function () {
      painting = false;
    });

    grid.addEventListener("click", function (e) {
      var d = e.target.closest("[data-day-toggle]");
      if (d) {
        var day = d.getAttribute("data-day-toggle");
        var cells = grid.querySelectorAll(
          '.tp-sched__cell[data-day="' + day + '"]',
        );
        var allOn = Array.prototype.every.call(cells, function (c) {
          return c.classList.contains("is-on");
        });
        cells.forEach(function (c) {
          c.classList.toggle("is-on", !allOn);
        });
        return;
      }
      var b = e.target.closest("[data-sched-set]");
      if (!b) return;
      var mode = b.getAttribute("data-sched-set");
      var all = grid.querySelectorAll(".tp-sched__cell");
      if (mode === "clear") {
        all.forEach(function (c) {
          c.classList.remove("is-on");
        });
      } else if (mode === "always") {
        all.forEach(function (c) {
          c.classList.add("is-on");
        });
      } else {
        var days = mode === "workdays" ? WORKDAYS : WEEKEND;
        all.forEach(function (c) {
          c.classList.toggle(
            "is-on",
            days.indexOf(c.getAttribute("data-day")) !== -1,
          );
        });
      }
    });
  }

  /* ---------- notifications ---------- */
  function notifInput(n) {
    var val = n.input == null ? "" : n.input;
    var t = n.input_type;
    if (!t) return "";
    if (t === "select") {
      var opts = (n.options || [])
        .map(function (o) {
          var k = o.id != null ? o.id : o.value;
          var lbl = o.title != null ? o.title : o.value != null ? o.value : k;
          return (
            '<option value="' +
            esc(k) +
            '"' +
            (String(k) === String(val) ? " selected" : "") +
            ">" +
            esc(lbl) +
            "</option>"
          );
        })
        .join("");
      return (
        '<select class="tp-input tp-input--sm" data-no-ts="1" data-notif-input>' +
        opts +
        "</select>"
      );
    }
    if (t === "color") {
      var hex = /^#[0-9a-fA-F]{6}/.test(val) ? val : "#2563eb";
      return (
        '<input type="color" class="tp-notif__color" data-notif-input value="' +
        esc(hex) +
        '">'
      );
    }
    if (t === "integer") {
      return (
        '<input type="number" min="0" class="tp-input tp-input--sm" data-notif-input value="' +
        esc(val) +
        '" placeholder="min">'
      );
    }
    return (
      '<input type="text" class="tp-input tp-input--sm" data-notif-input value="' +
      esc(val) +
      '">'
    );
  }

  function reportSettings(meta) {
    var it = (meta && meta.item) || {};

    var tipos = (meta.report_types || [])
      .map(function (t) {
        return (
          '<option value="' + esc(t.id) + '"' +
          (String(t.id) === String(it.report_type || "") ? " selected" : "") +
          ">" + esc(t.name) + "</option>"
        );
      })
      .join("");

    var formatos = Object.keys(meta.report_formats || {})
      .map(function (k) {
        return (
          '<option value="' + esc(k) + '"' +
          (k === (it.report_format || "pdf") ? " selected" : "") +
          ">" + esc(meta.report_formats[k]) + "</option>"
        );
      })
      .join("");

    return (
      '<div class="tp-notif__report">' +
      '<label>' + tpT('front.report') + '<select class="tp-input tp-input--sm" data-no-ts="1" data-report-type>' +
      '<option value="">—</option>' + tipos + "</select></label>" +
      '<label>Formato<select class="tp-input tp-input--sm" data-no-ts="1" data-report-format>' +
      formatos + "</select></label>" +
      '<label>Min. antes<input type="number" min="0" max="1440" class="tp-input tp-input--sm" data-report-before value="' +
      esc(it.report_minutes_before == null ? 15 : it.report_minutes_before) + '"></label>' +
      '<label>' + tpT('ui.min_after') + '<input type="number" min="0" max="1440" class="tp-input tp-input--sm" data-report-after value="' +
      esc(it.report_minutes_after == null ? 15 : it.report_minutes_after) + '"></label>' +
      "</div>"
    );
  }

  function panelNotifications(meta) {
    var list = meta.notifications || [];
    if (!list.length)
      return '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_notification_channels') + '</div>';
    return list
      .map(function (n) {
        return (
          '<div class="tp-notif" data-notif="' +
          esc(n.name) +
          '">' +
          '<label class="tp-checkbox tp-notif__head"><input type="checkbox" data-notif-active' +
          (n.active ? " checked" : "") +
          "> <span>" +
          esc(n.title || n.name) +
          "</span></label>" +
          (n.input_type
            ? '<div class="tp-notif__input">' + notifInput(n) + "</div>"
            : "") +
          (n.description
            ? '<small class="tp-notif__hint">' + n.description + "</small>"
            : "") +
          // El canal de reporte necesita mas que una lista de correos: que
          // reporte, en que formato y que ventana alrededor del evento.
          (n.name === "report" ? reportSettings(meta) : "") +
          "</div>"
        );
      })
      .join("");
  }

  function wireNotifications(dlg) {
    dlg.querySelectorAll(".tp-notif").forEach(function (row) {
      var cb = row.querySelector("[data-notif-active]");
      var inp = row.querySelector("[data-notif-input]");
      if (!cb) return;
      var sync = function () {
        row.classList.toggle("is-off", !cb.checked);
        if (inp) inp.disabled = !cb.checked;
      };
      cb.addEventListener("change", sync);
      sync();
      if (row.getAttribute("data-notif") === "sound" && inp) {
        inp.addEventListener("change", function () {
          try {
            if (this.value)
              new Audio("/" + this.value.replace(/^\/+/, "")).play();
          } catch (e) {}
        });
      }
    });
  }

  /* ---------- command ---------- */
  function wireCommand(dlg) {
    var active = dlg.querySelector("#tp-alert-cmd-active");
    var body = dlg.querySelector("#tp-alert-cmd-body");
    if (!active || !body) return;
    var typeSel = dlg.querySelector("#tp-alert-cmd-type");
    var fieldsWrap = dlg.querySelector("#tp-alert-cmd-fields");
    var loaded = false;

    function renderCmdFields(type, vals) {
      var cmds = dlg._alertCmds || [];
      var c = cmds.filter(function (x) {
        return x.type === type;
      })[0];
      if (!c || !c.fields || !c.fields.length) {
        fieldsWrap.innerHTML = "";
        return;
      }
      fieldsWrap.innerHTML =
        '<div class="tp-grid-2">' +
        c.fields
          .map(function (f) {
            return fieldHtml(f, vals ? vals[f.name] : null, f.name, true);
          })
          .join("") +
        "</div>";
    }

    function loadCmds(preType, preVals) {
      var ids = selectedDeviceIds(dlg);
      typeSel.innerHTML = '<option value="">Cargando…</option>';
      window.tpModal
        .fetchJSON(COMMANDS_URL, { method: "POST", json: { devices: ids } })
        .then(function (res) {
          var cmds = (res.data && res.data.commands) || [];
          dlg._alertCmds = cmds;
          if (!cmds.length) {
            typeSel.innerHTML =
              '<option value="">' + tpT('ui.no_commands_for_vehicles') + '</option>';
            fieldsWrap.innerHTML = "";
            return;
          }
          typeSel.innerHTML = cmds
            .map(function (c) {
              return (
                '<option value="' +
                esc(c.type) +
                '"' +
                (c.type === preType ? " selected" : "") +
                ">" +
                esc(c.title) +
                "</option>"
              );
            })
            .join("");
          renderCmdFields(preType || cmds[0].type, preVals);
        })
        .catch(function () {
          typeSel.innerHTML =
            '<option value="">' + tpT('ui.commands_load_failed') + '</option>';
        });
    }

    var sync = function () {
      body.hidden = !active.checked;
      if (active.checked && !loaded) {
        loaded = true;
        loadCmds();
      }
    };
    active.addEventListener("change", sync);
    typeSel.addEventListener("change", function () {
      renderCmdFields(typeSel.value);
    });

    var devWrap = dlg.querySelector("#tp-alert-devices");
    if (devWrap)
      devWrap.addEventListener("dev-change", function () {
        if (active.checked) {
          loaded = true;
          loadCmds(typeSel.value);
        }
      });

    dlg._alertLoadCmds = loadCmds;
    sync();
  }

  /* ---------- panels ---------- */
  function panelDevices() {
    return (
      '<div class="tp-devbox" id="tp-alert-devices">' +
      '<div class="tp-devbox__bar">' +
      '<div class="tp-devbox__search">' +
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>' +
      '<input type="search" class="tp-input tp-input--sm" data-dev-search placeholder="' + tpT('ui.search_vehicle') + '" autocomplete="off">' +
      "</div>" +
      '<div class="tp-devbox__acts">' +
      '<button type="button" class="tp-devbox__link" data-dev-all>' + tpT('ui.select_all_m') + '</button>' +
      '<span class="tp-devbox__sep">|</span>' +
      '<button type="button" class="tp-devbox__link" data-dev-none>' + tpT('ui.remove_all') + '</button>' +
      "</div>" +
      "</div>" +
      '<div class="tp-devbox__banner" data-dev-banner hidden><b>' + tpT('ui.all_vehicles') + '</b> ' + tpT('ui.selected_will_include_later') + '</div>' +
      '<div class="tp-devbox__list" data-dev-list></div>' +
      '<div class="tp-devbox__foot"><span class="tp-devbox__count" data-dev-count>0 ' + tpT('ui.selected_plural') + '</span><div class="tp-devbox__pager" data-dev-pager></div></div>' +
      "</div>"
    );
  }

  function panelType(meta) {
    var first = meta.types && meta.types[0];
    return (
      '<div class="tp-field"><label class="tp-field__label">Disparador *</label>' +
      '<select class="tp-input" name="type" id="tp-alert-type">' +
      buildTypeOptions(meta.types, first && first.key) +
      "</select>" +
      '<div class="tp-field__hint">' + tpT('ui.define_trigger_event') + '</div></div>' +
      '<div id="tp-alert-type-fields">' +
      renderTypeFields(first) +
      "</div>"
    );
  }

  function panelGeofences(meta) {
    var gf = meta.geofences || [];
    var zones =
      '<label class="tp-checkbox"><input type="checkbox" data-zone="1"> <span>' + tpT('ui.zone_entry') + '</span></label>' +
      '<label class="tp-checkbox"><input type="checkbox" data-zone="2"> <span>' + tpT('ui.zone_exit') + '</span></label>';
    return (
      '<div class="tp-field"><label class="tp-field__label">Sentido</label>' +
      '<div class="tp-row" style="gap:18px;flex-wrap:wrap;">' +
      zones +
      "</div>" +
      '<div class="tp-field__hint">' + tpT('ui.applies_to_geofence_alerts') + '</div></div>' +
      '<div class="tp-field"><label class="tp-field__label">Geocercas</label>' +
      picklistHtml("tp-alert-zones", gf, {
        empty: tpT('ui.no_geofences_created'),
      }) +
      "</div>"
    );
  }

  function panelSchedule(meta) {
    return (
      '<label class="tp-checkbox" style="margin-bottom:6px;"><input type="checkbox" id="tp-alert-sched-toggle"> <span>' + tpT('ui.restrict_by_schedule') + '</span></label>' +
      '<div class="tp-field__hint" style="margin-bottom:10px;">' + tpT('ui.schedule_paint_help') + '</div>' +
      '<div id="tp-alert-sched" hidden>' +
      scheduleGridHtml(meta.schedules) +
      "</div>"
    );
  }

  function panelCommand() {
    return (
      '<label class="tp-checkbox" style="margin-bottom:6px;"><input type="checkbox" id="tp-alert-cmd-active"> <span>' + tpT('ui.send_command_on_trigger') + '</span></label>' +
      '<div class="tp-field__hint" style="margin-bottom:10px;">' + tpT('ui.command_sent_on_condition') + '</div>' +
      '<div id="tp-alert-cmd-body" hidden>' +
      '<div class="tp-field"><label class="tp-field__label">Comando</label>' +
      '<select class="tp-input" data-no-ts="1" id="tp-alert-cmd-type"><option value="">' + tpT('ui.select_vehicles_first') + '</option></select></div>' +
      '<div id="tp-alert-cmd-fields"></div>' +
      "</div>"
    );
  }

  function buildAlertFormHtml() {
    return (
      '<form id="tp-alert-form" autocomplete="off">' +
      '<div class="tp-alert-head">' +
      '<div class="tp-field tp-alert-head__name"><label class="tp-field__label">' + tpT('ui.name_required_field') + '</label>' +
      '<input class="tp-input" type="text" name="name" maxlength="80" required placeholder="' + tpT('ui.alert_name_example') + '"></div>' +
      '<label class="tp-checkbox tp-alert-head__active"><input type="checkbox" name="active" checked> <span>Activa</span></label>' +
      "</div>" +
      '<div id="tp-alert-tabs"></div>' +
      "</form>"
    );
  }

  function mountAlertTabs(modal, meta) {
    var holder = modal.querySelector("#tp-alert-tabs");
    var tabs = [
      { key: "devices", label: tpT('ui.vehicles'), html: panelDevices(meta) },
      { key: "type", label: tpT('front.type'), html: panelType(meta) },
      { key: "geofences", label: "Geocercas", html: panelGeofences(meta) },
      { key: "schedule", label: "Horario", html: panelSchedule(meta) },
      { key: "notifs", label: "Notificaciones", html: panelNotifications(meta) },
    ];
    if (meta.can_command)
      tabs.push({ key: "command", label: "Comando", html: panelCommand() });
    window.tpModal.tabs(holder, tabs, { active: "devices" });
  }

  /**
   * Engancha los pickers de los campos multiselect del tipo.
   *
   * Hay que rellamarla cada vez que cambia el disparador, porque el
   * contenedor se rehace con innerHTML y se lleva por delante los eventos.
   */
  function wireTypeFieldPicklists(dlg, typeObj) {
    ((typeObj && typeObj.fields) || []).forEach(function (f) {
      if (f.type !== "multiselect") return;
      wirePicklist(dlg, tfPickId(f.name));
      // Las geocercas del tipo y las de la pestaña "Geocercas" son la misma
      // lista: se reflejan para que no haya dos verdades segun la pestaña.
      if (f.name === "geofences") espejoGeocercas(dlg);
    });
  }

  /** Mantiene marcadas las mismas geocercas en los dos sitios. */
  function espejoGeocercas(dlg) {
    var a = dlg.querySelector("#" + tfPickId("geofences"));
    var b = dlg.querySelector("#tp-alert-zones");
    if (!a || !b || a._tpEspejo) return;
    a._tpEspejo = b._tpEspejo = true;

    function copia(desde, hacia) {
      var ids = {};
      desde.querySelectorAll(".tp-picklist__item input[type=checkbox]:checked")
        .forEach(function (cb) { ids[cb.value] = 1; });
      hacia.querySelectorAll(".tp-picklist__item input[type=checkbox]")
        .forEach(function (cb) { cb.checked = !!ids[cb.value]; });
      updatePickCount(hacia);
    }

    // 'change' cubre las casillas; 'click' los botones de todo / nada, que
    // cambian las casillas sin disparar change.
    ["change", "click"].forEach(function (ev) {
      a.addEventListener(ev, function () { setTimeout(function () { copia(a, b); }, 0); });
      b.addEventListener(ev, function () { setTimeout(function () { copia(b, a); }, 0); });
    });
  }

  function wireFormBehaviour(dlg, meta) {
    var sel = dlg.querySelector("#tp-alert-type");
    if (sel)
      sel.addEventListener("change", function () {
        var t = (meta.types || []).filter(function (x) {
          return x.key === sel.value;
        })[0];
        var holder = dlg.querySelector("#tp-alert-type-fields");
        if (holder) {
          holder.innerHTML = renderTypeFields(t);
          wireTypeFieldPicklists(dlg, t);
        }
      });

    wireDevices(dlg, meta);
    wirePicklist(dlg, "tp-alert-zones");
    wireTypeFieldPicklists(dlg, (meta.types || [])[0]);
    wireSchedule(dlg);
    wireNotifications(dlg);
    if (meta.can_command) wireCommand(dlg);
  }

  function readForm(dlg, meta) {
    var form = dlg.querySelector("#tp-alert-form");
    var data = {};

    data.name = (form.querySelector('[name="name"]').value || "").trim();
    var actEl = dlg.querySelector('[name="active"]');
    data.active = actEl && actEl.checked ? 1 : 0;

    var typeSel = dlg.querySelector("#tp-alert-type");
    data.type = typeSel ? typeSel.value : "";

    // Devices — semántica legacy: "Seleccionar todos" ⇒ selected_devices=["s;1;"]
    // ⇒ el loader marca for_all_user_devices=1 (incluye vehículos futuros).
    // Selección específica ⇒ ["s;0;", "devices;1;<id>"…] ⇒ for_all=0.
    if (dlg._devAll) {
      data.selected_devices = ["s;1;"];
      data.devices = (dlg._devList || []).map(function (d) { return d.id; });
      data.for_all_user_devices = 1;
    } else {
      var _ids = Object.keys(dlg._devSel || {}).map(Number);
      data.selected_devices = ["s;0;"].concat(
        _ids.map(function (id) { return "devices;1;" + id; }),
      );
      data.devices = _ids;
      data.for_all_user_devices = 0;
    }

    var zoneIds = picklistValues(dlg, "#tp-alert-zones");
    data.zones = zoneIds;
    data.geofences = zoneIds;
    var zone = 0;
    dlg.querySelectorAll("[data-zone]").forEach(function (cb) {
      if (cb.checked) zone |= parseInt(cb.getAttribute("data-zone"), 10);
    });
    data.zone = zone;

    var schedToggle = dlg.querySelector("#tp-alert-sched-toggle");
    data.schedule = schedToggle && schedToggle.checked ? 1 : 0;
    data.schedules = data.schedule ? collectSchedules(dlg) : [];

    data.notifications = {};
    dlg.querySelectorAll(".tp-notif").forEach(function (row) {
      var key = row.getAttribute("data-notif");
      var act = row.querySelector("[data-notif-active]");
      var inp = row.querySelector("[data-notif-input]");
      var o = { active: act && act.checked ? 1 : 0 };
      if (inp) o.input = inp.value;
      data.notifications[key] = o;
    });

    // Los ajustes del reporte viajan sueltos (son campos de la alerta, no del
    // canal: el canal solo guarda active + input).
    var rt = dlg.querySelector("[data-report-type]");
    if (rt) {
      data.report_type = rt.value || null;
      data.report_format = dlg.querySelector("[data-report-format]").value;
      data.report_minutes_before = dlg.querySelector("[data-report-before]").value;
      data.report_minutes_after = dlg.querySelector("[data-report-after]").value;
    }

    var typeObj = (meta.types || []).filter(function (t) {
      return t.key === data.type;
    })[0];
    if (typeObj)
      (typeObj.fields || []).forEach(function (f) {
        // Multiselect: LISTA, no el value de un control. Antes caia en la
        // rama de abajo y mandaba un escalar que pisaba data.geofences, con
        // lo que el backend respondia "El geofences debe ser un listado".
        if (f.type === "multiselect") {
          var ids = picklistValues(dlg, "#" + tfPickId(f.name));
          if (f.name === "geofences") {
            // Los dos selectores estan reflejados; si el del tipo esta vacio
            // -el disparador no las usa- se respeta el de la pestaña.
            if (ids.length) {
              data.geofences = ids;
              data.zones = ids;
            }
          } else {
            data[f.name] = ids;
          }
          return;
        }

        var el = form.querySelector('[name="' + f.name + '"]');
        if (el && el.value !== "")
          data[f.name] = f.type === "number" ? parseFloat(el.value) : el.value;
      });

    var cmdActive = dlg.querySelector("#tp-alert-cmd-active");
    if (cmdActive && cmdActive.checked) {
      var cmdType = dlg.querySelector("#tp-alert-cmd-type");
      data.command = { active: 1, type: cmdType ? cmdType.value : "" };
      dlg
        .querySelectorAll("#tp-alert-cmd-fields [data-cmd-field]")
        .forEach(function (el) {
          if (el.value !== "") data[el.getAttribute("data-cmd-field")] = el.value;
        });
    } else {
      data.command = { active: 0 };
    }

    return data;
  }

  function prefillForm(dlg, meta, item) {
    if (!item) return;
    var form = dlg.querySelector("#tp-alert-form");

    var nameEl = form.querySelector('[name="name"]');
    if (nameEl) nameEl.value = item.name || "";
    var actEl = dlg.querySelector('[name="active"]');
    if (actEl) actEl.checked = !!item.active;

    var typeSel = dlg.querySelector("#tp-alert-type");
    if (typeSel) {
      typeSel.value = item.type || "";
      if (window.tpSelect && typeSel._tpdd) tpSelect.refresh(typeSel);
      typeSel.dispatchEvent(new Event("change", { bubbles: true }));
    }
    var fields = item.fields || {};
    Object.keys(fields).forEach(function (n) {
      if (fields[n] == null) return;
      // Un multiselect no tiene .value: se marcan sus casillas.
      var pick = dlg.querySelector("#" + tfPickId(n));
      if (pick) {
        checkPicklist(dlg, "#" + tfPickId(n),
          Array.isArray(fields[n]) ? fields[n] : [fields[n]]);
        return;
      }
      var el = form.querySelector('[name="' + n + '"]');
      if (el) el.value = fields[n];
    });

    if (item.for_all_user_devices) {
      dlg._devAll = true;
      dlg._devSel = {};
      (dlg._devList || []).forEach(function (d) { dlg._devSel[d.id] = 1; });
    } else {
      dlg._devAll = false;
      dlg._devSel = {};
      (item.devices || []).forEach(function (id) { dlg._devSel[id] = 1; });
    }
    if (dlg._devRender) dlg._devRender();
    checkPicklist(dlg, "#tp-alert-zones", item.geofences || []);

    var zone = parseInt(item.zone || 0, 10);
    dlg.querySelectorAll("[data-zone]").forEach(function (cb) {
      cb.checked = !!(zone & parseInt(cb.getAttribute("data-zone"), 10));
    });

    var st = dlg.querySelector("#tp-alert-sched-toggle");
    if (st) {
      st.checked = !!item.schedule;
      st.dispatchEvent(new Event("change", { bubbles: true }));
    }
    // notifications & schedule grid already reflect the alert (editData meta).

    var cmd = item.command || {};
    var cmdActive = dlg.querySelector("#tp-alert-cmd-active");
    if (cmdActive && cmd && cmd.active) {
      cmdActive.checked = true;
      var body = dlg.querySelector("#tp-alert-cmd-body");
      if (body) body.hidden = false;
      if (dlg._alertLoadCmds) dlg._alertLoadCmds(cmd.type, cmd);
    }
  }

  function openAlertModal(id) {
    if (!CAN_CREATE && !id) return;
    if (!CAN_EDIT && id) return;
    var metaRef = null;
    var itemRef = null;
    var isEdit = !!id;

    var modal = window.tpModal.open({
      title: isEdit ? tpT('ui.edit_alert_rule') : tpT('ui.new_alert_rule'),
      sub: tpT('ui.alert_wizard_steps'),
      size: "xl",
      // Restyle scopeado (alerts.css .tp-alert-modal) — no afecta otros modales.
      className: "tp-alert-modal",
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: isEdit ? tpT('ui.save_changes') : tpT('ui.create_alert'),
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            if (!metaRef) return false;
            var data = readForm(dlg, metaRef);
            if (!data.name) {
              // Resumen bajo la cabecera + campo marcado, como un 422 del servidor.
              window.tpModal.showFieldErrors(dlg, { name: [tpT('ui.name_the_rule')] });
              window.tpModal.toast(tpT('ui.name_the_rule'), "warning");
              return false;
            }
            var url = isEdit ? BASE + "/" + encodeURIComponent(id) : STORE_URL;
            var method = isEdit ? "PUT" : "POST";
            window.tpModal.setBusy(dlg, true);
            window.tpModal
              .fetchJSON(url, { method: method, json: data })
              .then(function (res) {
                window.tpModal.setBusy(dlg, false);
                if (res.status === 422) {
                  var errs = (res.data && res.data.errors) || {};
                  window.tpModal.showFieldErrors(dlg, errs);
                  var first = Object.keys(errs)[0];
                  var msg = tpModal.firstError(errs, tpT('ui.check_marked_fields'));
                  window.tpModal.toast(msg, "warning");
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  window.tpModal.toast(
                    (res.data && res.data.message) ||
                      (isEdit
                        ? tpT('ui.save_failed')
                        : tpT('ui.alert_create_failed')),
                    "error",
                  );
                  return;
                }
                window.tpModal.toast(
                  isEdit ? tpT('ui.changes_saved') : tpT('ui.alert_created'),
                  "success",
                );
                window.tpModal.close(dlg);
                load();
              })
              .catch(function () {
                window.tpModal.setBusy(dlg, false);
                window.tpModal.toast(tpT('ui.network_error'), "error");
              });
            return false;
          },
        },
      ],
    });

    var loader = isEdit
      ? window.tpModal
          .fetchJSON(BASE + "/" + encodeURIComponent(id) + "/edit", {
            method: "GET",
          })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok)
              throw new Error("edit_meta_failed");
            itemRef = res.data.item;
            return res.data;
          })
      : loadMeta();

    loader
      .then(function (meta) {
        metaRef = meta;
        var body = modal.querySelector("[data-tp-body]");
        body.innerHTML = buildAlertFormHtml();
        mountAlertTabs(modal, meta);
        wireFormBehaviour(modal, meta);
        if (itemRef) prefillForm(modal, meta, itemRef);
      })
      .catch(function () {
        var body = modal.querySelector("[data-tp-body]");
        body.innerHTML =
          '<div class="tp-text-center" style="padding:24px; color:var(--status-alert,#ef4444);">' + tpT('ui.alert_catalog_load_failed') + '</div>';
      });
  }

  function toggleRule(id, btn) {
    if (!CAN_EDIT) return;
    if (btn) btn.disabled = true;
    window.tpModal
      .fetchJSON(BASE + "/" + encodeURIComponent(id) + "/toggle", {
        method: "POST",
      })
      .then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok || !res.data || !res.data.ok) {
          window.tpModal.toast(tpT('ui.state_change_failed'), "error");
          return;
        }
        window.tpModal.toast(
          res.data.active ? "Regla activada." : "Regla pausada.",
          "success",
        );
        load();
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        window.tpModal.toast(tpT('ui.network_error'), "error");
      });
  }

  /* =====================================================
   * Plantillas de alerta
   * -----------------------------------------------------
   * Se capturan de una regla ya configurada, para no mantener un segundo
   * formulario. Al dar de alta un cliente se le crean las alertas de las
   * plantillas de su plan, con "todos los vehiculos" activado, asi que los
   * que se le asignen despues entran solos.
   * ===================================================== */

  var TPL_URL = "/ui/v2/api/alert-templates";

  function saveAsTemplate(id) {
    var regla = (state.rules || []).filter(function (r) {
      return String(r.id) === String(id);
    })[0];

    window.tpModal
      .fetchJSON(TPL_URL, { method: "GET" })
      .then(function (res) {
        var planes = (res.data && res.data.plans) || [];
        var opciones =
          '<option value="">' + tpT('ui.all_plans') + '</option>' +
          planes
            .map(function (p) {
              return '<option value="' + p.id + '">' + esc(p.title) + "</option>";
            })
            .join("");

        window.tpModal.open({
          title: tpT('ui.save_as_template'),
          size: "sm",
          body:
            '<p class="tp-text-sm tp-text-muted" style="margin:0 0 12px;">' + tpT('ui.will_be_saved') + ' <b>' +
            esc(regla ? regla.name : tpT('ui.this_rule')) +
            "</b> " + tpT('ui.as_template_note') + "</p>" +
            '<label class="tp-form__field" style="margin-bottom:10px;">' +
            '<span class="tp-form__label">Aplicar a</span>' +
            '<select class="tp-input" data-tpl-plan>' + opciones + "</select></label>" +
            '<label class="tp-form__field">' +
            '<span class="tp-form__label">Nota (opcional)</span>' +
            '<input type="text" class="tp-input" data-tpl-desc placeholder="' + tpT('ui.what_is_it_for') + '"></label>',
          buttons: [
            { label: tpT('global.cancel'), variant: "ghost", close: true },
            {
              label: tpT('global.save'),
              variant: "primary",
              close: false,
              onClick: function (d) {
                var plan = d.querySelector("[data-tpl-plan]").value;
                var desc = d.querySelector("[data-tpl-desc]").value.trim();

                window.tpModal.setBusy(d, true);
                window.tpModal
                  .fetchJSON(BASE + "/" + encodeURIComponent(id) + "/template", {
                    method: "POST",
                    json: { plan_id: plan || null, description: desc },
                  })
                  .then(function (r) {
                    window.tpModal.setBusy(d, false);
                    if (!r.ok || !r.data || !r.data.ok) {
                      window.tpModal.toast(tpT('ui.template_save_failed'), "error");
                      return;
                    }
                    window.tpModal.close(d);
                    window.tpModal.toast("Plantilla guardada.", "success");
                  })
                  .catch(function () {
                    window.tpModal.setBusy(d, false);
                    window.tpModal.toast(tpT('ui.network_error'), "error");
                  });
                return false;
              },
            },
          ],
        });
      })
      .catch(function () {
        window.tpModal.toast(tpT('ui.plans_load_failed'), "error");
      });
  }

  function openTemplates() {
    var dlg = window.tpModal.open({
      title: tpT('ui.alert_templates'),
      size: "lg",
      body: '<div data-tpl-list><p class="tp-text-sm tp-text-muted">Cargando…</p></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      onMount: function (d) {
        var host = d.querySelector("[data-tpl-list]");

        function cargar() {
          window.tpModal.fetchJSON(TPL_URL, { method: "GET" }).then(function (res) {
            var items = (res.data && res.data.items) || [];

            if (!items.length) {
              host.innerHTML =
                '<p class="tp-text-sm tp-text-muted">' + tpT('ui.no_templates_yet_long') + '</p>';
              return;
            }

            host.innerHTML =
              '<p class="tp-text-xs tp-text-muted" style="margin:0 0 10px;">' + tpT('ui.plan_templates_note') + '</p>' +
              items
                .map(function (t) {
                  return (
                    '<div class="tp-acc-row"><span><b>' + esc(t.name) + "</b>" +
                    (t.active ? "" : ' <span class="tp-text-xs tp-text-muted">(inactiva)</span>') +
                    '<br><span class="tp-text-xs tp-text-muted">' +
                    esc(t.type) + " · " + esc(t.plan || tpT('ui.all_plans_lower')) +
                    (t.description ? " · " + esc(t.description) : "") +
                    "</span></span>" +
                    '<span class="tp-acc-row__actions">' +
                    '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tpl-toggle="' + t.id +
                    '" data-active="' + (t.active ? 1 : 0) + '">' +
                    (t.active ? tpT('ui.deactivate') : tpT('ui.activate')) + "</button>" +
                    '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tpl-del="' + t.id +
                    '">' + tpT('global.delete') + '</button></span></div>'
                  );
                })
                .join("");
          });
        }

        cargar();

        host.addEventListener("click", function (e) {
          var tg = e.target.closest("[data-tpl-toggle]");
          if (tg) {
            window.tpModal
              .fetchJSON(TPL_URL + "/" + tg.getAttribute("data-tpl-toggle"), {
                method: "PUT",
                json: { active: tg.getAttribute("data-active") === "1" ? 0 : 1 },
              })
              .then(cargar);
            return;
          }

          var del = e.target.closest("[data-tpl-del]");
          if (del) {
            window.tpModal
              .confirm({
                title: tpT('ui.delete_template'),
                message:
                  tpT('ui.stop_seeding_new_clients'),
                danger: true,
                confirmLabel: tpT('global.delete'),
              })
              .then(function (ok) {
                if (!ok) return;
                window.tpModal
                  .fetchJSON(TPL_URL + "/" + del.getAttribute("data-tpl-del"), { method: "DELETE" })
                  .then(cargar);
              });
          }
        });
      },
    });

    return dlg;
  }

  function deleteRule(id) {
    if (!CAN_REMOVE) return;
    window.tpModal
      .confirm({
        title: tpT('ui.delete_rule'),
        message: tpT('ui.confirm_delete_rule'),
        danger: true,
        confirmLabel: tpT('global.delete'),
      })
      .then(function (ok) {
        if (!ok) return;
        window.tpModal
          .fetchJSON(BASE + "/" + encodeURIComponent(id), { method: "DELETE" })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              window.tpModal.toast(tpT('ui.delete_failed'), "error");
              return;
            }
            window.tpModal.toast("Regla eliminada.", "success");
            load();
          })
          .catch(function () {
            window.tpModal.toast(tpT('ui.network_error'), "error");
          });
      });
  }
})();
