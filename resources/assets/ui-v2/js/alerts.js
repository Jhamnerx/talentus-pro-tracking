/* =====================================================
   TalentusPro · Alerts Center
   - Rules list (left) + events feed (right)
   - Full 6-tab rule modal (parity with legacy Alerts):
       Vehículos · Tipo · Geocercas · Horario · Notificaciones · Comando
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
  var META_CACHE = null;

  var WORKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"];
  var WEEKEND = ["saturday", "sunday"];

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

  // Localized, human labels for notification channels (rules list badges).
  var CHAN_LABEL = {
    color: "Color",
    silent: "Anti-repetición",
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

  var TYPE_ICONS = {
    critical:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>',
    warning:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
  };

  function typeLabel(t) {
    return TYPE_LABELS[t] || String(t || "").replace(/_/g, " ");
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

  /* ----- Render rules (redesigned: type chip + scope + only-active channels) ----- */
  function renderRules() {
    var list = $("#tp-alerts-rules-list");
    var count = $("#tp-alerts-rules-count");
    var q = state.rulesQ.toLowerCase();
    var rules = state.rules.filter(function (r) {
      return (
        !q ||
        (r.name || "").toLowerCase().indexOf(q) !== -1 ||
        typeLabel(r.type).toLowerCase().indexOf(q) !== -1
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

      var scope = r.all
        ? "Todos los vehículos"
        : (r.devices_count || 0) +
          " " +
          ((r.devices_count || 0) === 1 ? "vehículo" : "vehículos");

      var chans = (r.channels || []).filter(function (c) {
        return c !== "auto_hide"; // detalle interno de popup, no un canal en sí
      });
      var shown = chans.slice(0, 4);
      var extra = chans.length - shown.length;
      var chanHtml = shown
        .map(function (c) {
          return (
            '<span class="tp-alerts__chan">' +
            esc(CHAN_LABEL[c] || c) +
            "</span>"
          );
        })
        .join("");
      if (extra > 0)
        chanHtml +=
          '<span class="tp-alerts__chan tp-alerts__chan--more">+' +
          extra +
          "</span>";

      html +=
        '<div class="' +
        cls +
        '" data-rule-id="' +
        r.id +
        '" role="listitem">' +
        '<div class="tp-alerts__rule-row">' +
        '<div class="tp-alerts__rule-name" title="' +
        esc(r.name) +
        '">' +
        esc(r.name) +
        "</div>" +
        '<div class="tp-alerts__rule-actions">' +
        (CAN_EDIT
          ? '<button type="button" class="tp-alerts__rule-toggle" data-rule-action="toggle" data-rule-id="' +
            r.id +
            '" title="' +
            (r.active ? "Desactivar" : "Activar") +
            '"></button>'
          : '<span class="tp-alerts__rule-toggle"></span>') +
        (CAN_EDIT
          ? '<button type="button" class="tp-alerts__rule-edit" data-rule-action="edit" data-rule-id="' +
            r.id +
            '" title="Editar"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 113 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>' +
            '<button type="button" class="tp-alerts__rule-del" data-rule-action="delete" data-rule-id="' +
            r.id +
            '" title="Eliminar"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></button>'
          : "") +
        "</div>" +
        "</div>" +
        '<div class="tp-alerts__rule-meta">' +
        '<span class="tp-alerts__rule-type">' +
        esc(typeLabel(r.type)) +
        "</span>" +
        '<span class="tp-alerts__rule-scope">' +
        esc(scope) +
        "</span>" +
        "</div>" +
        (chanHtml
          ? '<div class="tp-alerts__rule-chans">' + chanHtml + "</div>"
          : "") +
        "</div>";
    }
    list.innerHTML = html;
  }

  /* ----- Render events feed ----- */
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
        esc(typeLabel(e.type)) +
        "</div>" +
        '<div class="tp-alerts__event-meta">' +
        (e.message ? esc(e.message) : "") +
        (e.address
          ? (e.message ? " · " : "") + "<b>" + esc(e.address) + "</b>"
          : "") +
        (e.speed != null && e.speed > 0
          ? " · " + Number(e.speed).toFixed(1) + " km/h"
          : "") +
        "</div>" +
        '<div class="tp-alerts__event-tags">' +
        '<span class="tp-alerts__event-tag">' +
        esc(e.type) +
        "</span>" +
        (e.device_id
          ? '<span class="tp-alerts__event-tag">#' + e.device_id + "</span>"
          : "") +
        "</div>" +
        "</div>" +
        '<div class="tp-alerts__event-side">' +
        '<div class="tp-alerts__event-time" title="' +
        esc(fmtTime(e.time)) +
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
      new URLSearchParams({ hours: state.hours, q: state.q }).toString();

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

  /* ----- Feed wiring ----- */
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

  var newBtn = document.getElementById("tp-alerts-new");
  if (newBtn)
    newBtn.addEventListener("click", function () {
      openAlertModal();
    });

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

  $("#tp-alerts-feed-list").addEventListener("click", function (e) {
    var btn = e.target.closest("[data-action]");
    if (!btn) return;
    e.stopPropagation();
    var act = btn.getAttribute("data-action");
    if (act === "map") {
      var lat = btn.getAttribute("data-lat");
      var lng = btn.getAttribute("data-lng");
      var dev = btn.getAttribute("data-device");
      var url = "/ui/v2/live?";
      if (dev) url += "device=" + encodeURIComponent(dev) + "&";
      url +=
        "lat=" + encodeURIComponent(lat) + "&lng=" + encodeURIComponent(lng);
      window.location.href = url;
    } else if (act === "device") {
      window.location.href =
        "/ui/v2/live?device=" +
        encodeURIComponent(btn.getAttribute("data-device"));
    }
  });

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
    var blob = new Blob(["﻿" + lines.join("\n")], {
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
  function renderTypeFields(typeObj) {
    if (!typeObj || !typeObj.fields || !typeObj.fields.length) {
      return '<div class="tp-text-muted tp-text-sm">Este tipo no requiere configuración adicional.</div>';
    }
    var html = '<div class="tp-grid-2">';
    typeObj.fields.forEach(function (f) {
      html += fieldHtml(f, null, f.name);
    });
    html += "</div>";
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
        esc(opts.empty || "Sin elementos.") +
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
        ? '<input type="search" class="tp-input tp-input--sm tp-picklist__search" placeholder="Buscar…">'
        : "<span></span>") +
      '<button type="button" class="tp-picklist__act" data-pick="all">Todos</button>' +
      '<button type="button" class="tp-picklist__act" data-pick="none">Ninguno</button>' +
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

  /* ---------- schedule grid ---------- */
  function scheduleGridHtml(schedules) {
    if (!schedules || !schedules.length)
      return '<div class="tp-text-muted tp-text-sm">Sin datos de horario.</div>';

    var items = schedules[0].items || [];
    var hours =
      '<div class="tp-sched__row tp-sched__hours"><span class="tp-sched__daylabel tp-sched__corner"></span><div class="tp-sched__cells">';
    items.forEach(function (it) {
      var h = parseInt(String(it.id).slice(0, 2), 10);
      var isHour = /:00$/.test(it.id);
      var label = isHour && h % 3 === 0 ? String(it.id).slice(0, 2) : "";
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
              '" title="' +
              esc(day.title + " " + it.id) +
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
          esc(String(day.title).charAt(0).toUpperCase()) +
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
      '<button type="button" data-sched-set="workdays">Días hábiles</button>' +
      '<button type="button" data-sched-set="weekend">Fin de semana</button>' +
      '<button type="button" data-sched-set="always">Siempre</button>' +
      '<button type="button" data-sched-set="clear">Limpiar</button>' +
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

  function panelNotifications(meta) {
    var list = meta.notifications || [];
    if (!list.length)
      return '<div class="tp-text-muted tp-text-sm">No hay canales de notificación disponibles.</div>';
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
      var ids = picklistValues(dlg, "#tp-alert-devices");
      typeSel.innerHTML = '<option value="">Cargando…</option>';
      window.tpModal
        .fetchJSON(COMMANDS_URL, { method: "POST", json: { devices: ids } })
        .then(function (res) {
          var cmds = (res.data && res.data.commands) || [];
          dlg._alertCmds = cmds;
          if (!cmds.length) {
            typeSel.innerHTML =
              '<option value="">Sin comandos disponibles para los vehículos elegidos</option>';
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
            '<option value="">No se pudieron cargar los comandos</option>';
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
      devWrap.addEventListener("change", function (e) {
        if (active.checked && e.target.matches('input[type="checkbox"]')) {
          loaded = true;
          loadCmds(typeSel.value);
        }
      });

    dlg._alertLoadCmds = loadCmds;
    sync();
  }

  /* ---------- panels ---------- */
  function panelDevices(meta) {
    return (
      '<label class="tp-checkbox" style="margin-bottom:10px;"><input type="checkbox" id="tp-alert-all"> <span>Aplicar a <strong>todos mis vehículos</strong></span></label>' +
      picklistHtml("tp-alert-devices", meta.devices || [], {
        empty: "No tienes vehículos.",
      })
    );
  }

  function panelType(meta) {
    var first = meta.types && meta.types[0];
    return (
      '<div class="tp-field"><label class="tp-field__label">Disparador *</label>' +
      '<select class="tp-input" name="type" id="tp-alert-type">' +
      buildTypeOptions(meta.types, first && first.key) +
      "</select>" +
      '<div class="tp-field__hint">Define el evento que dispara la alerta.</div></div>' +
      '<div id="tp-alert-type-fields">' +
      renderTypeFields(first) +
      "</div>"
    );
  }

  function panelGeofences(meta) {
    var gf = meta.geofences || [];
    var zones =
      '<label class="tp-checkbox"><input type="checkbox" data-zone="1"> <span>Entrada a la zona</span></label>' +
      '<label class="tp-checkbox"><input type="checkbox" data-zone="2"> <span>Salida de la zona</span></label>';
    return (
      '<div class="tp-field"><label class="tp-field__label">Sentido</label>' +
      '<div class="tp-row" style="gap:18px;flex-wrap:wrap;">' +
      zones +
      "</div>" +
      '<div class="tp-field__hint">Aplica a las alertas basadas en geocercas (entrada / salida).</div></div>' +
      '<div class="tp-field"><label class="tp-field__label">Geocercas</label>' +
      picklistHtml("tp-alert-zones", gf, {
        empty: "No tienes geocercas creadas.",
      }) +
      "</div>"
    );
  }

  function panelSchedule(meta) {
    return (
      '<label class="tp-checkbox" style="margin-bottom:6px;"><input type="checkbox" id="tp-alert-sched-toggle"> <span>Restringir por horario</span></label>' +
      '<div class="tp-field__hint" style="margin-bottom:10px;">Si se activa, la alerta solo se dispara dentro de las franjas marcadas. Arrastra para pintar; toca la inicial del día para todo el día.</div>' +
      '<div id="tp-alert-sched" hidden>' +
      scheduleGridHtml(meta.schedules) +
      "</div>"
    );
  }

  function panelCommand() {
    return (
      '<label class="tp-checkbox" style="margin-bottom:6px;"><input type="checkbox" id="tp-alert-cmd-active"> <span>Enviar comando al dispararse</span></label>' +
      '<div class="tp-field__hint" style="margin-bottom:10px;">Se enviará a los vehículos de la alerta cuando se cumpla la condición.</div>' +
      '<div id="tp-alert-cmd-body" hidden>' +
      '<div class="tp-field"><label class="tp-field__label">Comando</label>' +
      '<select class="tp-input" data-no-ts="1" id="tp-alert-cmd-type"><option value="">Selecciona vehículos primero…</option></select></div>' +
      '<div id="tp-alert-cmd-fields"></div>' +
      "</div>"
    );
  }

  function buildAlertFormHtml() {
    return (
      '<form id="tp-alert-form" autocomplete="off">' +
      '<div class="tp-alert-head">' +
      '<div class="tp-field tp-alert-head__name"><label class="tp-field__label">Nombre *</label>' +
      '<input class="tp-input" type="text" name="name" maxlength="80" required placeholder="Ej. Exceso de velocidad — flota norte"></div>' +
      '<label class="tp-checkbox tp-alert-head__active"><input type="checkbox" name="active" checked> <span>Activa</span></label>' +
      "</div>" +
      '<div id="tp-alert-tabs"></div>' +
      "</form>"
    );
  }

  function mountAlertTabs(modal, meta) {
    var holder = modal.querySelector("#tp-alert-tabs");
    var tabs = [
      { key: "devices", label: "Vehículos", html: panelDevices(meta) },
      { key: "type", label: "Tipo", html: panelType(meta) },
      { key: "geofences", label: "Geocercas", html: panelGeofences(meta) },
      { key: "schedule", label: "Horario", html: panelSchedule(meta) },
      { key: "notifs", label: "Notificaciones", html: panelNotifications(meta) },
    ];
    if (meta.can_command)
      tabs.push({ key: "command", label: "Comando", html: panelCommand() });
    window.tpModal.tabs(holder, tabs, { active: "devices" });
  }

  function wireFormBehaviour(dlg, meta) {
    var sel = dlg.querySelector("#tp-alert-type");
    if (sel)
      sel.addEventListener("change", function () {
        var t = (meta.types || []).filter(function (x) {
          return x.key === sel.value;
        })[0];
        var holder = dlg.querySelector("#tp-alert-type-fields");
        if (holder) holder.innerHTML = renderTypeFields(t);
      });

    var allCb = dlg.querySelector("#tp-alert-all"),
      devWrap = dlg.querySelector("#tp-alert-devices");
    if (allCb && devWrap) {
      var s = function () {
        devWrap.classList.toggle("is-disabled", allCb.checked);
      };
      allCb.addEventListener("change", s);
      s();
    }

    wirePicklist(dlg, "tp-alert-devices");
    wirePicklist(dlg, "tp-alert-zones");
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

    var allCb = dlg.querySelector("#tp-alert-all");
    data.for_all_user_devices = allCb && allCb.checked ? 1 : 0;
    data.devices = picklistValues(dlg, "#tp-alert-devices");

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

    var typeObj = (meta.types || []).filter(function (t) {
      return t.key === data.type;
    })[0];
    if (typeObj)
      (typeObj.fields || []).forEach(function (f) {
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
      var el = form.querySelector('[name="' + n + '"]');
      if (el && fields[n] != null) el.value = fields[n];
    });

    var allCb = dlg.querySelector("#tp-alert-all");
    if (allCb) {
      allCb.checked = !!item.for_all_user_devices;
      allCb.dispatchEvent(new Event("change", { bubbles: true }));
    }
    checkPicklist(dlg, "#tp-alert-devices", item.devices || []);
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
      title: isEdit ? "Editar regla de alerta" : "Nueva regla de alerta",
      sub: "Vehículos, disparador, geocercas, horario, notificaciones y comando.",
      size: "lg",
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: isEdit ? "Guardar cambios" : "Crear alerta",
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            if (!metaRef) return false;
            var data = readForm(dlg, metaRef);
            if (!data.name) {
              window.tpModal.toast("Ponle un nombre a la regla.", "warning");
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
                  var msg = first
                    ? [].concat(errs[first])[0]
                    : "Revisa los campos marcados.";
                  window.tpModal.toast(msg, "warning");
                  return;
                }
                if (!res.ok || !res.data || !res.data.ok) {
                  window.tpModal.toast(
                    (res.data && res.data.message) ||
                      (isEdit
                        ? "No se pudo guardar."
                        : "No se pudo crear la alerta."),
                    "error",
                  );
                  return;
                }
                window.tpModal.toast(
                  isEdit ? "Cambios guardados." : "Alerta creada.",
                  "success",
                );
                window.tpModal.close(dlg);
                load();
              })
              .catch(function () {
                window.tpModal.setBusy(dlg, false);
                window.tpModal.toast("Error de red.", "error");
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
          '<div class="tp-text-center" style="padding:24px; color:var(--status-alert,#ef4444);">No se pudo cargar el catálogo de la alerta.</div>';
      });
  }

  function toggleRule(id, btn) {
    if (!CAN_EDIT) return;
    btn.disabled = true;
    window.tpModal
      .fetchJSON(BASE + "/" + encodeURIComponent(id) + "/toggle", {
        method: "POST",
      })
      .then(function (res) {
        btn.disabled = false;
        if (!res.ok || !res.data || !res.data.ok) {
          window.tpModal.toast("No se pudo cambiar el estado.", "error");
          return;
        }
        window.tpModal.toast(
          res.data.active ? "Regla activada." : "Regla pausada.",
          "success",
        );
        load();
      })
      .catch(function () {
        btn.disabled = false;
        window.tpModal.toast("Error de red.", "error");
      });
  }

  function deleteRule(id) {
    if (!CAN_EDIT) return;
    window.tpModal
      .confirm({
        title: "Eliminar regla",
        message: "Esta acción no se puede deshacer. ¿Eliminar la regla?",
        danger: true,
        confirmLabel: "Eliminar",
      })
      .then(function (ok) {
        if (!ok) return;
        window.tpModal
          .fetchJSON(BASE + "/" + encodeURIComponent(id), { method: "DELETE" })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              window.tpModal.toast("No se pudo eliminar.", "error");
              return;
            }
            window.tpModal.toast("Regla eliminada.", "success");
            load();
          })
          .catch(function () {
            window.tpModal.toast("Error de red.", "error");
          });
      });
  }
})();
