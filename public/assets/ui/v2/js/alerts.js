/* =====================================================
   TalentusPro · Alerts Center · Phase 6
   - Rules list (left) + events feed (right)
   - Hours window, severity filter, search, CSV export
   - Auto-refresh every 60s
   ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-alerts");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
  var META_URL = root.getAttribute("data-meta-url");
  var STORE_URL = root.getAttribute("data-store-url");
  var TOGGLE_BASE =
    root.getAttribute("data-toggle-url") || "/talentus/api/alerts";
  var CAN_EDIT = root.getAttribute("data-can-edit") === "1";
  var CAN_CREATE = root.getAttribute("data-can-create") === "1";
  var META_CACHE = null;

  var state = {
    hours: 24,
    sev: "",
    q: "",
    rulesQ: "",
    rules: [],
    events: [],
    loading: false,
  };

  /* ----- helpers ----- */
  function $(s, c) {
    return (c || document).querySelector(s);
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

  var TYPE_LABELS = {
    sos: "SOS",
    overspeed: "Exceso de velocidad",
    geofence_overspeed: "Exceso en geocerca",
    zone_in: "Entrada a zona",
    zone_out: "Salida de zona",
    idle_duration: "Ralentí prolongado",
    stop_duration: "Detenido prolongado",
    offline_duration: "Offline prolongado",
    move_duration: "Movimiento prolongado",
    move_start: "Inicio de movimiento",
    ignition_on: "Motor encendido",
    ignition_off: "Motor apagado",
    fuel_fill: "Recarga de combustible",
    fuel_theft: "Robo de combustible",
    driver: "Conductor",
    driver_authorized: "Conductor autorizado",
    driver_unauthorized: "Conductor no autorizado",
    expiring_device: "Dispositivo por expirar",
    expired_device: "Dispositivo expirado",
    expiring_sim: "SIM por expirar",
    expired_sim: "SIM expirada",
    expiring_user: "Usuario por expirar",
    expired_user: "Usuario expirado",
    unplugged: "Desconectado",
    plugged_in: "Conectado",
    ghost_speed: "Velocidad fantasma",
    jump_from_stop: "Salto desde parado",
    task_new: "Nueva tarea",
    task_complete: "Tarea completa",
    task_failed: "Tarea fallida",
    custom: "Evento personalizado",
    distance: "Distancia",
    time_duration: "Duración temporal",
  };

  var TYPE_ICONS = {
    critical:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>',
    warning:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
  };

  function typeLabel(t) {
    return TYPE_LABELS[t] || t.replace(/_/g, " ");
  }

  function relTime(iso) {
    if (!iso) return "";
    var d = new Date(String(iso).replace(" ", "T"));
    if (isNaN(d.getTime())) return "";
    var diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return Math.round(diff) + " s";
    if (diff < 3600) return Math.round(diff / 60) + " min";
    if (diff < 86400) return Math.round(diff / 3600) + " h";
    return Math.round(diff / 86400) + " d";
  }

  function fmtTime(iso) {
    if (!iso) return "—";
    try {
      return new Date(String(iso).replace(" ", "T")).toLocaleString();
    } catch (e) {
      return iso;
    }
  }

  /* ----- Render rules ----- */
  function renderRules() {
    var list = $("#tp-alerts-rules-list");
    var count = $("#tp-alerts-rules-count");
    var q = state.rulesQ.toLowerCase();
    var rules = state.rules.filter(function (r) {
      return (
        !q ||
        (r.name || "").toLowerCase().indexOf(q) !== -1 ||
        (r.type || "").toLowerCase().indexOf(q) !== -1
      );
    });
    count.textContent = rules.length + " de " + state.rules.length;

    if (!rules.length) {
      list.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:20px;">Sin reglas.</div>';
      return;
    }
    var html = "";
    for (var i = 0; i < rules.length; i++) {
      var r = rules[i];
      var cls = "tp-alerts__rule" + (r.active ? " is-on" : " is-inactive");
      html +=
        '<div class="' +
        cls +
        '" data-rule-id="' +
        r.id +
        '" role="listitem">' +
        '<div class="tp-alerts__rule-row">' +
        '<div class="tp-alerts__rule-name" title="' +
        escapeHtml(r.name) +
        '">' +
        escapeHtml(r.name) +
        "</div>" +
        '<div class="tp-alerts__rule-actions">' +
        (CAN_EDIT
          ? '<button type="button" class="tp-alerts__rule-toggle" data-rule-action="toggle" data-rule-id="' +
            r.id +
            '" aria-label="' +
            (r.active ? "Desactivar" : "Activar") +
            '" title="' +
            (r.active ? "Desactivar" : "Activar") +
            '"></button>'
          : '<span class="tp-alerts__rule-toggle" aria-label="' +
            (r.active ? "Activa" : "Inactiva") +
            '"></span>') +
        (CAN_EDIT
          ? '<button type="button" class="tp-alerts__rule-edit" data-rule-action="edit" data-rule-id="' +
            r.id +
            '" aria-label="Editar" title="Editar"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 113 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
          : "") +
        (CAN_EDIT
          ? '<button type="button" class="tp-alerts__rule-del" data-rule-action="delete" data-rule-id="' +
            r.id +
            '" aria-label="Eliminar" title="Eliminar"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></button>'
          : "") +
        "</div>" +
        "</div>" +
        '<div class="tp-alerts__rule-meta">' +
        '<span class="tp-alerts__rule-chip">' +
        escapeHtml(typeLabel(r.type)) +
        "</span>" +
        (r.all ? '<span class="tp-alerts__rule-chip">Todos</span>' : "") +
        (r.channels || [])
          .map(function (c) {
            return (
              '<span class="tp-alerts__rule-chip">' + escapeHtml(c) + "</span>"
            );
          })
          .join("") +
        "</div>" +
        "</div>";
    }
    list.innerHTML = html;
  }

  /* ----- Render events ----- */
  function renderEvents() {
    var list = $("#tp-alerts-feed-list");
    var count = $("#tp-alerts-feed-count");
    var events = state.events.filter(function (e) {
      return !state.sev || e.severity === state.sev;
    });
    count.textContent =
      events.length + " eventos · últimas " + state.hours + "h";

    if (!events.length) {
      list.innerHTML =
        '<div class="tp-alerts__empty">' +
        '<svg class="tp-alerts__empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>' +
        "<div>Sin eventos en este rango.</div>" +
        "</div>";
      return;
    }

    var html = "";
    for (var i = 0; i < events.length; i++) {
      var e = events[i];
      var sev = e.severity || "info";
      var hasCoord = e.lat != null && e.lng != null;
      html +=
        '<article class="tp-alerts__event tp-alerts__event--' +
        sev +
        '" data-event-id="' +
        e.id +
        '" role="listitem">' +
        '<div class="tp-alerts__event-stripe"></div>' +
        '<div class="tp-alerts__event-icon">' +
        TYPE_ICONS[sev] +
        "</div>" +
        '<div class="tp-alerts__event-body">' +
        '<div class="tp-alerts__event-title">' +
        escapeHtml(typeLabel(e.type)) +
        "</div>" +
        '<div class="tp-alerts__event-meta">' +
        (e.message ? escapeHtml(e.message) : "") +
        (e.address
          ? (e.message ? " · " : "") + "<b>" + escapeHtml(e.address) + "</b>"
          : "") +
        (e.speed != null && e.speed > 0
          ? " · " + Number(e.speed).toFixed(1) + " km/h"
          : "") +
        "</div>" +
        '<div class="tp-alerts__event-tags">' +
        '<span class="tp-alerts__event-tag">' +
        escapeHtml(e.type) +
        "</span>" +
        (e.device_id
          ? '<span class="tp-alerts__event-tag">#' + e.device_id + "</span>"
          : "") +
        "</div>" +
        "</div>" +
        '<div class="tp-alerts__event-side">' +
        '<div class="tp-alerts__event-time" title="' +
        escapeHtml(fmtTime(e.time)) +
        '">' +
        relTime(e.time) +
        "</div>" +
        '<div class="tp-alerts__event-actions">' +
        (hasCoord
          ? '<button class="tp-alerts__event-action" data-action="map" data-lat="' +
            e.lat +
            '" data-lng="' +
            e.lng +
            '" data-device="' +
            (e.device_id || "") +
            '">Mapa</button>'
          : "") +
        (e.device_id
          ? '<button class="tp-alerts__event-action" data-action="device" data-device="' +
            e.device_id +
            '">Vehículo</button>'
          : "") +
        "</div>" +
        "</div>" +
        "</article>";
    }
    list.innerHTML = html;
  }

  /* ----- Load ----- */
  function load() {
    if (state.loading) return Promise.resolve();
    state.loading = true;
    var url =
      DATA_URL +
      "?" +
      new URLSearchParams({
        hours: state.hours,
        q: state.q,
      }).toString();

    return fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (p) {
        state.loading = false;
        if (!p || !p.ok) return;
        state.rules = p.rules || [];
        state.events = p.events || [];
        renderRules();
        renderEvents();
      })
      .catch(function (err) {
        state.loading = false;
        console.warn("[alerts] load failed", err);
      });
  }

  /* ----- Events wiring ----- */
  var sTimer;
  $("#tp-alerts-feed-search").addEventListener("input", function (e) {
    clearTimeout(sTimer);
    sTimer = setTimeout(function () {
      state.q = e.target.value.trim();
      load();
    }, 250);
  });

  var rTimer;
  $("#tp-alerts-rules-search").addEventListener("input", function (e) {
    clearTimeout(rTimer);
    rTimer = setTimeout(function () {
      state.rulesQ = e.target.value.trim();
      renderRules();
    }, 150);
  });

  root
    .querySelectorAll(
      '.tp-alerts__seg[aria-label="Ventana de tiempo"] .tp-seg__btn',
    )
    .forEach(function (btn) {
      btn.addEventListener("click", function () {
        root
          .querySelectorAll(
            '.tp-alerts__seg[aria-label="Ventana de tiempo"] .tp-seg__btn',
          )
          .forEach(function (b) {
            b.classList.remove("is-active");
          });
        btn.classList.add("is-active");
        state.hours = parseInt(btn.getAttribute("data-hours"), 10) || 24;
        load();
      });
    });

  root
    .querySelectorAll('.tp-alerts__seg[aria-label="Severidad"] .tp-seg__btn')
    .forEach(function (btn) {
      btn.addEventListener("click", function () {
        root
          .querySelectorAll(
            '.tp-alerts__seg[aria-label="Severidad"] .tp-seg__btn',
          )
          .forEach(function (b) {
            b.classList.remove("is-active");
          });
        btn.classList.add("is-active");
        state.sev = btn.getAttribute("data-sev") || "";
        renderEvents();
      });
    });

  $("#tp-alerts-refresh").addEventListener("click", load);

  // "+ Nueva" → tp-modal create
  var newBtn = document.getElementById("tp-alerts-new");
  if (newBtn)
    newBtn.addEventListener("click", function () {
      openAlertModal();
    });

  // Rule actions (toggle / delete)
  $("#tp-alerts-rules-list").addEventListener("click", function (e) {
    var btn = e.target.closest("[data-rule-action]");
    if (!btn) return;
    e.stopPropagation();
    var id = btn.getAttribute("data-rule-id");
    var action = btn.getAttribute("data-rule-action");
    if (action === "toggle") return toggleRule(id, btn);
    if (action === "edit") return openAlertModal(id);
    if (action === "delete") return deleteRule(id);
  });

  // Event actions
  $("#tp-alerts-feed-list").addEventListener("click", function (e) {
    var btn = e.target.closest("[data-action]");
    if (!btn) return;
    e.stopPropagation();
    var act = btn.getAttribute("data-action");
    if (act === "map") {
      var lat = btn.getAttribute("data-lat");
      var lng = btn.getAttribute("data-lng");
      var dev = btn.getAttribute("data-device");
      var url = "/talentus/live?";
      if (dev) url += "device=" + encodeURIComponent(dev) + "&";
      url +=
        "lat=" + encodeURIComponent(lat) + "&lng=" + encodeURIComponent(lng);
      window.location.href = url;
    } else if (act === "device") {
      window.location.href =
        "/talentus/live?device=" +
        encodeURIComponent(btn.getAttribute("data-device"));
    }
  });

  // CSV export (visible severity-filtered subset)
  $("#tp-alerts-export").addEventListener("click", function () {
    var events = state.events.filter(function (e) {
      return !state.sev || e.severity === state.sev;
    });
    var headers = [
      "ID",
      "Tipo",
      "Severidad",
      "Mensaje",
      "Dirección",
      "Dispositivo",
      "Velocidad",
      "Latitud",
      "Longitud",
      "Hora",
    ];
    var lines = [headers.join(",")];
    events.forEach(function (ev) {
      var row = [
        ev.id,
        typeLabel(ev.type),
        ev.severity,
        ev.message || "",
        ev.address || "",
        ev.device_id || "",
        ev.speed != null ? ev.speed.toFixed(1) : "",
        ev.lat != null ? ev.lat : "",
        ev.lng != null ? ev.lng : "",
        ev.time || "",
      ].map(function (v) {
        var s = String(v == null ? "" : v).replace(/"/g, '""');
        return /[",\n]/.test(s) ? '"' + s + '"' : s;
      });
      lines.push(row.join(","));
    });
    var blob = new Blob(["\uFEFF" + lines.join("\n")], {
      type: "text/csv;charset=utf-8;",
    });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "alerts-" + Date.now() + ".csv";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 100);
  });

  /* ----- Boot + poll ----- */
  load();
  setInterval(load, 60000);

  /* =====================================================
   * PHASE 11 · Native modal CRUD (replaces legacy /alerts/create)
   * ===================================================== */

  function loadMeta() {
    if (META_CACHE) return Promise.resolve(META_CACHE);
    return window.tpModal.fetchJSON(META_URL).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        throw new Error("meta_failed");
      }
      META_CACHE = res.data;
      return META_CACHE;
    });
  }

  function buildTypeOptions(types, selectedKey) {
    var out = "";
    types.forEach(function (t) {
      var sel = t.key === selectedKey ? " selected" : "";
      out +=
        '<option value="' +
        tpModal.escapeHtml(t.key) +
        '"' +
        sel +
        ">" +
        tpModal.escapeHtml(t.label) +
        "</option>";
    });
    return out;
  }

  /**
   * Render the per-type attribute fields (from AlertModalHelper).
   * Each `field` shape: { name, type, title, value, options? }
   */
  function renderTypeFields(typeObj) {
    if (!typeObj || !typeObj.fields || !typeObj.fields.length) {
      return '<div class="tp-text-muted tp-text-sm">Esta alerta no requiere configuración adicional.</div>';
    }
    var html = '<div class="tp-grid-2">';
    typeObj.fields.forEach(function (f) {
      var val = f.value == null ? "" : f.value;
      html +=
        '<div class="tp-field"><label class="tp-field__label">' +
        tpModal.escapeHtml(f.title || f.name) +
        "</label>";
      if (f.type === "select" || (f.options && f.options.length)) {
        html += '<select class="tp-select" name="' + f.name + '">';
        (f.options || []).forEach(function (o) {
          var k = o.id != null ? o.id : o.value;
          var label = o.title != null ? o.title : o.value != null ? o.value : k;
          var sel = String(k) === String(val) ? " selected" : "";
          html +=
            '<option value="' +
            tpModal.escapeHtml(k) +
            '"' +
            sel +
            ">" +
            tpModal.escapeHtml(label) +
            "</option>";
        });
        html += "</select>";
      } else {
        var inputType = f.type === "number" ? "number" : "text";
        html +=
          '<input class="tp-input" type="' +
          inputType +
          '" name="' +
          f.name +
          '" value="' +
          tpModal.escapeHtml(val) +
          '">';
      }
      html += "</div>";
    });
    html += "</div>";
    return html;
  }

  function pickName(opt) {
    // meta arrays may come as {id, name} or apiArray {id, value, title}
    return opt.name || opt.title || opt.value || "#" + (opt.id || "");
  }

  function chipsHtml(items, multi, emptyMsg) {
    if (!items || !items.length) {
      return (
        '<span class="tp-text-muted tp-text-sm">' +
        tpModal.escapeHtml(emptyMsg || "Sin elementos.") +
        "</span>"
      );
    }
    return items
      .map(function (it) {
        var id = it.id != null ? it.id : it.value;
        var label = pickName(it) + (it.plate ? " · " + it.plate : "");
        return (
          '<button type="button" class="tp-chips__chip" data-val="' +
          id +
          '">' +
          tpModal.escapeHtml(label) +
          "</button>"
        );
      })
      .join("");
  }

  /* ---------- Tab panels ---------- */

  function panelGeneral(meta) {
    return (
      "" +
      '<div class="tp-field">' +
      '<label class="tp-field__label">Nombre *</label>' +
      '<input class="tp-input" type="text" name="name" maxlength="80" required placeholder="Ej. SOS flota norte">' +
      "</div>" +
      '<div class="tp-grid-2">' +
      '<div class="tp-field">' +
      '<label class="tp-field__label">Tipo *</label>' +
      '<select class="tp-select" name="type" id="tp-alert-type">' +
      buildTypeOptions(meta.types, meta.types[0] && meta.types[0].key) +
      "</select>" +
      '<div class="tp-field__hint">Define el disparador. Los campos extra aparecen en la pestaña "Tipo".</div>' +
      "</div>" +
      '<div class="tp-field">' +
      '<label class="tp-field__label">Estado</label>' +
      '<label class="tp-checkbox"><input type="checkbox" name="active" checked> <span>Activa al guardar</span></label>' +
      "</div>" +
      "</div>"
    );
  }

  function panelDevices(meta) {
    return (
      "" +
      '<label class="tp-checkbox" style="margin-bottom:10px;"><input type="checkbox" name="for_all_user_devices" id="tp-alert-all"> <span>Aplicar a <strong>todos mis vehículos</strong></span></label>' +
      '<div class="tp-field__hint" style="margin-bottom:8px;">O selecciona específicos:</div>' +
      '<div class="tp-chips" data-multi="devices" id="tp-alert-devices">' +
      chipsHtml(meta.devices, true, "No hay vehículos.") +
      "</div>"
    );
  }

  function panelType(meta) {
    var first = meta.types[0];
    return (
      '<div id="tp-alert-type-fields">' + renderTypeFields(first) + "</div>"
    );
  }

  function panelGeofences(meta) {
    var zones =
      meta.alert_zones && meta.alert_zones.length
        ? meta.alert_zones
            .map(function (z) {
              var id = z.id != null ? z.id : z.value;
              return (
                '<label class="tp-checkbox"><input type="checkbox" name="zone_' +
                id +
                '" value="' +
                id +
                '"> <span>' +
                tpModal.escapeHtml(pickName(z)) +
                "</span></label>"
              );
            })
            .join("")
        : '<label class="tp-checkbox"><input type="checkbox" name="zone_1" value="1"> <span>Entrada</span></label>' +
          '<label class="tp-checkbox"><input type="checkbox" name="zone_2" value="2"> <span>Salida</span></label>';

    return (
      "" +
      '<div class="tp-field">' +
      '<label class="tp-field__label">Direcciones</label>' +
      '<div class="tp-row" style="gap:14px; flex-wrap:wrap;">' +
      zones +
      "</div>" +
      '<div class="tp-field__hint">Aplica solo a tipos de alerta basados en geocercas.</div>' +
      "</div>" +
      '<div class="tp-field">' +
      '<label class="tp-field__label">Geocercas</label>' +
      '<div class="tp-chips" data-multi="zones" id="tp-alert-zones">' +
      chipsHtml(meta.geofences, true, "No tienes geocercas creadas.") +
      "</div>" +
      "</div>"
    );
  }

  function panelNotifications(meta) {
    var list = meta.notifications || [];
    if (!list.length) {
      // Fallback minimal channel set if helper returned nothing.
      list = [
        { name: "web", title: "Mostrar en feed", active: true },
        { name: "email", title: "Correo electrónico" },
        { name: "sms", title: "SMS" },
        { name: "push", title: "Notificación push" },
      ];
    }
    return list
      .map(function (n) {
        var checked = n.active ? " checked" : "";
        var hint = n.description
          ? '<div class="tp-field__hint">' +
            tpModal.escapeHtml(n.description) +
            "</div>"
          : "";
        return (
          "" +
          '<div class="tp-field" style="margin-bottom:10px;">' +
          '<label class="tp-checkbox"><input type="checkbox" name="notif_' +
          n.name +
          '"' +
          checked +
          "> <span><strong>" +
          tpModal.escapeHtml(n.title || n.name) +
          "</strong></span></label>" +
          hint +
          "</div>"
        );
      })
      .join("");
  }

  function buildAlertFormHtml(meta) {
    return '<form id="tp-alert-form" autocomplete="off"><div id="tp-alert-tabs"></div></form>';
  }

  function mountAlertTabs(modal, meta) {
    var holder = modal.querySelector("#tp-alert-tabs");
    tpModal.tabs(
      holder,
      [
        { key: "general", label: "General", html: panelGeneral(meta) },
        { key: "devices", label: "Vehículos", html: panelDevices(meta) },
        { key: "type", label: "Tipo", html: panelType(meta) },
        { key: "geofences", label: "Geocercas", html: panelGeofences(meta) },
        {
          key: "notifs",
          label: "Notificaciones",
          html: panelNotifications(meta),
        },
      ],
      { active: "general" },
    );
  }

  function collectChips(scope, selector) {
    return Array.from(
      scope.querySelectorAll(selector + " .tp-chips__chip.is-on"),
    ).map(function (el) {
      return parseInt(el.getAttribute("data-val"), 10);
    });
  }

  function readForm(dlg, meta) {
    var form = dlg.querySelector("#tp-alert-form");
    // Build notifications payload in the shape AlertModalHelper expects:
    //   notifications: { web: { active: 1 }, email: { active: 0 }, ... }
    var notifications = {};
    (meta.notifications || []).forEach(function (n) {
      var cb = form.querySelector('[name="notif_' + n.name + '"]');
      notifications[n.name] = { active: cb && cb.checked ? 1 : 0 };
    });
    // Always ensure web channel present
    if (!notifications.web) {
      var web = form.querySelector('[name="notif_web"]');
      notifications.web = { active: web ? (web.checked ? 1 : 0) : 1 };
    }

    // Zones direction bitmap (1=in, 2=out) — legacy uses single int field
    var zone = 0;
    (meta.alert_zones || [{ id: 1 }, { id: 2 }]).forEach(function (z) {
      var id = z.id != null ? z.id : z.value;
      var cb = form.querySelector('[name="zone_' + id + '"]');
      if (cb && cb.checked) zone = zone | parseInt(id, 10);
    });

    var data = {
      name: (form.name.value || "").trim(),
      type: form.type.value,
      active: form.active.checked ? 1 : 0,
      for_all_user_devices: form.querySelector("#tp-alert-all").checked ? 1 : 0,
      devices: collectChips(dlg, '[data-multi="devices"]'),
      zones: collectChips(dlg, '[data-multi="zones"]'),
      geofences: collectChips(dlg, '[data-multi="zones"]'), // legacy alias
      zone: zone,
      notifications: notifications,
      schedule: 0,
      schedules: [],
    };

    // Per-type fields → top-level keys.
    var typeObj = (meta.types || []).find(function (t) {
      return t.key === data.type;
    });
    if (typeObj) {
      (typeObj.fields || []).forEach(function (f) {
        var el = form[f.name];
        if (el && typeof el.value !== "undefined") {
          var v = el.value;
          if (v !== "") data[f.name] = f.type === "number" ? parseFloat(v) : v;
        }
      });
    }
    return data;
  }

  function wireFormBehaviour(dlg, meta) {
    // Chips toggle
    dlg.addEventListener("click", function (e) {
      var chip = e.target.closest(".tp-chips__chip");
      if (!chip) return;
      e.preventDefault();
      chip.classList.toggle("is-on");
    });
    // "Todos" disables device chips
    var allCb = dlg.querySelector("#tp-alert-all");
    var devsWrap = dlg.querySelector("#tp-alert-devices");
    if (allCb && devsWrap) {
      allCb.addEventListener("change", function () {
        devsWrap.style.opacity = allCb.checked ? "0.4" : "1";
        devsWrap.style.pointerEvents = allCb.checked ? "none" : "";
      });
    }
    // Type change → re-render fields
    var sel = dlg.querySelector("#tp-alert-type");
    if (sel) {
      sel.addEventListener("change", function () {
        var t = (meta.types || []).find(function (x) {
          return x.key === sel.value;
        });
        var holder = dlg.querySelector("#tp-alert-type-fields");
        if (holder) holder.innerHTML = renderTypeFields(t);
      });
    }
  }

  function openAlertModal(id) {
    if (!CAN_CREATE && !id) return;
    if (!CAN_EDIT && id) return;
    var metaRef = null;
    var itemRef = null;
    var isEdit = !!id;
    var modal = tpModal.open({
      title: isEdit ? "Editar regla de alerta" : "Nueva regla de alerta",
      sub: "Configura disparadores, vehículos, geocercas y notificaciones.",
      size: "lg",
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: isEdit ? "Guardar cambios" : "Crear alerta",
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            if (!metaRef) return;
            var data = readForm(dlg, metaRef);
            var url = isEdit
              ? TOGGLE_BASE + "/" + encodeURIComponent(id)
              : STORE_URL;
            var method = isEdit ? "PUT" : "POST";
            tpModal.setBusy(dlg, true);
            tpModal
              .fetchJSON(url, { method: method, json: data })
              .then(function (res) {
                tpModal.setBusy(dlg, false);
                if (res.status === 422) {
                  tpModal.showFieldErrors(
                    dlg,
                    (res.data && res.data.errors) || {},
                  );
                  tpModal.toast("Revisa los campos marcados.", "warning");
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast(
                    (res.data && res.data.message) ||
                      (isEdit
                        ? "No se pudo guardar."
                        : "No se pudo crear la alerta."),
                    "error",
                  );
                  return;
                }
                tpModal.toast(
                  isEdit ? "Cambios guardados." : "Alerta creada.",
                  "success",
                );
                tpModal.close(dlg);
                load();
              })
              .catch(function () {
                tpModal.setBusy(dlg, false);
                tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
    });

    var loader = isEdit
      ? tpModal
          .fetchJSON(TOGGLE_BASE + "/" + encodeURIComponent(id) + "/edit", {
            method: "GET",
          })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              throw new Error("edit_meta_failed");
            }
            itemRef = res.data.item;
            // Edit endpoint returns the same shape as meta + item.
            META_CACHE = null; // refresh so create after edit pulls fresh defaults
            return res.data;
          })
      : loadMeta();

    loader
      .then(function (meta) {
        metaRef = meta;
        var body = modal.querySelector("[data-tp-body]");
        body.innerHTML = buildAlertFormHtml(meta);
        mountAlertTabs(modal, meta);
        wireFormBehaviour(modal, meta);
        if (itemRef) prefillForm(modal, meta, itemRef);
      })
      .catch(function () {
        var body = modal.querySelector("[data-tp-body]");
        body.innerHTML =
          '<div class="tp-text-center" style="padding:24px; color:var(--status-alert,#ef4444);">No se pudo cargar el catálogo.</div>';
      });
  }

  /**
   * Pre-fill the form for edit. `item` shape from /alerts/{id}/edit:
   *   { id, name, type, active, for_all_user_devices,
   *     zone, devices:[ids], geofences:[ids],
   *     notifications:{name:{active}}, fields:{name:value} }
   */
  function prefillForm(dlg, meta, item) {
    var form = dlg.querySelector("#tp-alert-form");
    if (!form) return;

    if (form.name) form.name.value = item.name || "";
    if (form.type) {
      form.type.value = item.type || "";
      // Trigger change so type-specific fields render.
      var ev = new Event("change", { bubbles: true });
      form.type.dispatchEvent(ev);
    }
    if (form.active) form.active.checked = !!item.active;

    var allCb = dlg.querySelector("#tp-alert-all");
    if (allCb) {
      allCb.checked = !!item.for_all_user_devices;
      allCb.dispatchEvent(new Event("change", { bubbles: true }));
    }

    var devSet = {};
    (item.devices || []).forEach(function (id) {
      devSet[String(id)] = 1;
    });
    var zoneSet = {};
    (item.geofences || []).forEach(function (id) {
      zoneSet[String(id)] = 1;
    });
    Array.from(
      dlg.querySelectorAll('[data-multi="devices"] .tp-chips__chip'),
    ).forEach(function (chip) {
      if (devSet[chip.getAttribute("data-val")]) chip.classList.add("is-on");
    });
    Array.from(
      dlg.querySelectorAll('[data-multi="zones"] .tp-chips__chip'),
    ).forEach(function (chip) {
      if (zoneSet[chip.getAttribute("data-val")]) chip.classList.add("is-on");
    });

    // Zone direction bitmap
    var zone = parseInt(item.zone || 0, 10);
    Array.from(dlg.querySelectorAll('[name^="zone_"]')).forEach(function (cb) {
      var id = parseInt(cb.value, 10);
      if (zone & id) cb.checked = true;
    });

    // Notifications
    var notifs = item.notifications || {};
    Object.keys(notifs).forEach(function (key) {
      var cb = form.querySelector('[name="notif_' + key + '"]');
      var n = notifs[key];
      var active = n && typeof n === "object" ? n.active : n;
      if (cb) cb.checked = !!active;
    });

    // Type-specific fields (re-applied after the change event populated them)
    var fields = item.fields || {};
    Object.keys(fields).forEach(function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      if (el != null && fields[name] != null) el.value = fields[name];
    });
  }

  function toggleRule(id, btn) {
    if (!CAN_EDIT) return;
    btn.disabled = true;
    tpModal
      .fetchJSON(TOGGLE_BASE + "/" + encodeURIComponent(id) + "/toggle", {
        method: "POST",
      })
      .then(function (res) {
        btn.disabled = false;
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast("No se pudo cambiar el estado.", "error");
          return;
        }
        tpModal.toast(
          res.data.active ? "Regla activada." : "Regla pausada.",
          "success",
        );
        load();
      })
      .catch(function () {
        btn.disabled = false;
        tpModal.toast("Error de red.", "error");
      });
  }

  function deleteRule(id) {
    if (!CAN_EDIT) return;
    tpModal
      .confirm({
        title: "Eliminar regla",
        message: "Esta acción no se puede deshacer. ¿Eliminar la regla?",
        danger: true,
        confirmLabel: "Eliminar",
      })
      .then(function (ok) {
        if (!ok) return;
        tpModal
          .fetchJSON(TOGGLE_BASE + "/" + encodeURIComponent(id), {
            method: "DELETE",
          })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              tpModal.toast("No se pudo eliminar.", "error");
              return;
            }
            tpModal.toast("Regla eliminada.", "success");
            load();
          })
          .catch(function () {
            tpModal.toast("Error de red.", "error");
          });
      });
  }
})();
