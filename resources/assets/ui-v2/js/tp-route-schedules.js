/* =====================================================
 * TalentusPro · tp-route-schedules — Programaciones de ruta
 * -----------------------------------------------------
 * Dos pestañas sobre el mismo backend:
 *   · Programaciones: alta/edición/borrado y editor de puntos de control.
 *   · Cumplimiento:   vueltas del rango con su estado, calculadas por
 *                     RouteAdherenceService (el mismo del reporte 909).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-rsch-page");
  if (!root || !window.tpModal) return;

  var URLS = {
    data: root.getAttribute("data-url"),
    meta: root.getAttribute("data-meta-url"),
    store: root.getAttribute("data-store-url"),
    item: root.getAttribute("data-item-url"),
    adherence: root.getAttribute("data-adherence-url"),
    checkpoints: root.getAttribute("data-checkpoints-url"),
  };
  var canEdit = root.getAttribute("data-can-edit") === "1";

  var state = {
    page: 1,
    s: "",
    byId: {},
    routes: [],
    devices: [],
    weekdays: [],
    roundStates: {},
    pointStates: {},
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return tpModal.escapeHtml(s == null ? "" : String(s)); }

  /* ---------- Pestañas ---------- */
  root.querySelectorAll("[data-rsch-tab]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var key = btn.getAttribute("data-rsch-tab");
      root.querySelectorAll("[data-rsch-tab]").forEach(function (b) {
        b.classList.toggle("is-active", b === btn);
        b.setAttribute("aria-selected", b === btn ? "true" : "false");
      });
      root.querySelectorAll("[data-rsch-panel]").forEach(function (p) {
        p.hidden = p.getAttribute("data-rsch-panel") !== key;
      });
    });
  });

  /* ---------- Meta ---------- */
  function fillSelect(sel, items, placeholder) {
    if (!sel) return;
    sel.innerHTML = '<option value="">' + esc(placeholder) + "</option>" +
      items.map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + "</option>"; }).join("");
    if (window.tpSelect && tpSelect.refresh) tpSelect.refresh(sel);
  }

  tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
    var d = (res && res.data) || {};
    if (!d.ok) return;

    state.routes = d.routes || [];
    state.devices = d.devices || [];
    state.weekdays = d.weekdays || [];
    state.roundStates = d.round_states || {};
    state.pointStates = d.point_states || {};

    fillSelect($("tp-rsch-filter-device"), state.devices, tpT('front.all'));
    load();
  });

  /* ---------- Listado ---------- */
  function diaCorto(iso) {
    var d = state.weekdays.filter(function (w) { return w.id === iso; })[0];
    return d ? d.name.slice(0, 3) : iso;
  }

  function rowHtml(s) {
    var acciones = canEdit
      ? '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-rsch-edit="' + s.id + '">' + tpT('global.edit') + '</button> ' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-rsch-cp="' + s.route_id + '">' + tpT('front.points') + '</button> ' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-rsch-del="' + s.id + '">' + tpT('global.delete') + '</button>'
      : "";

    return "<tr>" +
      "<td>" + esc(s.name) + "</td>" +
      "<td>" + esc(s.route) + "</td>" +
      "<td>" + s.weekdays.map(diaCorto).map(esc).join(", ") + "</td>" +
      "<td>" + esc(s.start_time) + "</td>" +
      "<td>" + esc(s.return_start_time || "—") + "</td>" +
      "<td>" + s.devices.length + "</td>" +
      '<td><span class="tp-pill ' + (s.active ? "tp-pill--ok" : "tp-pill--off") + '">' +
        (s.active ? tpT('ui.yes') : "No") + "</span></td>" +
      '<td class="tp-svc-table__actions" style="white-space:nowrap;text-align:right;">' + acciones + "</td>" +
      "</tr>";
  }

  function load() {
    var qs = "?page=" + state.page + "&s=" + encodeURIComponent(state.s);

    tpModal.fetchJSON(URLS.data + qs, { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      var items = d.items || [];

      state.byId = {};
      items.forEach(function (i) { state.byId[i.id] = i; });

      $("tp-rsch-rows").innerHTML = items.length
        ? items.map(rowHtml).join("")
        : '<tr><td colspan="8" class="tp-svc-table__empty">' + tpT('ui.no_schedules_yet') + '</td></tr>';

      $("tp-rsch-range").textContent = tpT('ui.page_short') + (d.page || 1) + " / " + (d.pages || 1);
      $("tp-rsch-total").textContent = (d.total || 0) + " programaciones";

      // El filtro de la otra pestaña se alimenta de lo mismo
      fillSelect($("tp-rsch-filter-schedule"), items.map(function (i) {
        return { id: i.id, name: i.name };
      }), tpT('ui.all_f'));
    });
  }

  /* ---------- Formulario ---------- */
  function field(label, input, full) {
    // Sin etiqueta no se pinta el <label>: uno vacio dejaba un hueco encima
    // de la casilla "Activa" que descuadraba la rejilla.
    return '<div class="tp-field"' + (full ? ' style="grid-column:1/-1;"' : "") + ">" +
      (label ? '<label class="tp-field__label">' + label + "</label>" : "") +
      input + "</div>";
  }

  function openForm(item) {
    var rutaOpts = '<option value="">' + tpT('ui.select_ellipsis') + '</option>' + state.routes.map(function (r) {
      return '<option value="' + r.id + '"' + (item && item.route_id === r.id ? " selected" : "") + ">" + esc(r.name) + "</option>";
    }).join("");

    var elegidos = item ? item.devices.map(function (d) { return d.id; }) : [];

    var dias = item ? item.weekdays : [1, 2, 3, 4, 5];
    var diasHtml = state.weekdays.map(function (w) {
      return '<label class="tp-rsch__day"><input type="checkbox" data-x-day value="' + w.id + '"' +
        (dias.indexOf(w.id) >= 0 ? " checked" : "") + "> " + esc(w.name) + "</label>";
    }).join("");

    var body =
      '<div class="tp-scope" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
      field(tpT('ui.name_required_field'), '<input type="text" class="tp-input" data-x-name value="' + (item ? esc(item.name) : "") + '" style="width:100%;">') +
      field("Ruta *", '<select class="tp-input" data-x-route style="width:100%;">' + rutaOpts + "</select>") +
      field(tpT('ui.days_required'), '<div class="tp-rsch__days">' + diasHtml + "</div>", true) +
      field(tpT('ui.outbound_departure_required'), '<input type="time" class="tp-input" data-x-start value="' + (item ? esc(item.start_time) : "07:00") + '" style="width:100%;">') +
      field(tpT('ui.return_departure'), '<input type="time" class="tp-input" data-x-return value="' + (item && item.return_start_time ? esc(item.return_start_time) : "") + '" style="width:100%;">' +
        '<span class="tp-field__hint">' + tpT('ui.empty_means_one_way') + '</span>') +
      field(tpT('ui.tolerance_early_required'), '<input type="number" min="0" class="tp-input" data-x-early value="' + (item ? item.tolerance_early : 5) + '" style="width:100%;">') +
      field(tpT('ui.tolerance_late_required'), '<input type="number" min="0" class="tp-input" data-x-late value="' + (item ? item.tolerance_late : 10) + '" style="width:100%;">') +
      field(tpT('ui.vehicles'), '<div data-x-devices></div>', true) +
      field("", '<label class="tp-rsch__check"><input type="checkbox" data-x-active' + (!item || item.active ? " checked" : "") + "> Activa</label>", true) +
      '<div data-x-err style="grid-column:1/-1;display:none;color:#ef4444;font-size:13px;"></div></div>';

    var dlg = tpModal.open({
      title: item ? tpT('ui.edit_schedule') : tpT('ui.new_schedule'),
      size: "md",
      body: body,
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        { label: tpT('global.save'), variant: "primary", close: false, onClick: function (d) { save(d, item); return false; } },
      ],
    });

    // El mismo selector que el modal de reportes: chips, buscador, paginado y
    // seleccionar / quitar todos. Un <select multiple> con 1.400 vehiculos no
    // se puede buscar ni deja ver lo elegido.
    if (dlg && window.tpChipsSelect) {
      dlg._devices = tpChipsSelect({
        mount: dlg.querySelector("[data-x-devices]"),
        items: state.devices || [],
        selected: elegidos,
        placeholder: tpT('ui.select_vehicles_ellipsis'),
        host: dlg,
      });
    }
  }

  function save(d, item) {
    var err = d.querySelector("[data-x-err]");
    function showErr(m) { if (err) { err.textContent = m; err.style.display = "block"; } return false; }
    if (err) err.style.display = "none";

    function v(sel) { var el = d.querySelector(sel); return el ? el.value : ""; }

    var dias = [];
    d.querySelectorAll("[data-x-day]:checked").forEach(function (c) { dias.push(Number(c.value)); });

    var vehiculos = (d._devices ? d._devices.getIds() : []).map(Number);

    var payload = {
      name: v("[data-x-name]").trim(),
      route_id: v("[data-x-route]"),
      weekdays: dias,
      start_time: v("[data-x-start]"),
      return_start_time: v("[data-x-return]") || null,
      tolerance_early: v("[data-x-early]"),
      tolerance_late: v("[data-x-late]"),
      devices: vehiculos,
      active: d.querySelector("[data-x-active]").checked ? 1 : 0,
    };

    if (!payload.name) return showErr(tpT('ui.name_required'));
    if (!payload.route_id) return showErr(tpT('ui.pick_a_route'));
    if (!dias.length) return showErr(tpT('ui.pick_at_least_one_day'));
    if (!payload.start_time) return showErr(tpT('ui.outbound_time_missing'));
    if (payload.return_start_time && payload.return_start_time <= payload.start_time) {
      return showErr(tpT('ui.return_after_outbound'));
    }

    tpModal.setBusy(d, true);

    var url = item ? URLS.item.replace("{id}", item.id) : URLS.store;

    tpModal.fetchJSON(url, { method: item ? "PUT" : "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(d, false);
        if (res.status === 422) return showErr(tpT('ui.check_required_fields'));
        if (!res.ok || !res.data || !res.data.ok) return showErr((res.data && res.data.message) || tpT('ui.save_failed'));
        tpModal.close(d);
        load();
      })
      .catch(function () { tpModal.setBusy(d, false); showErr(tpT('ui.network_error')); });
  }

  function del(id) {
    tpModal.confirm({
      title: tpT('ui.delete_schedule'),
      message: tpT('ui.confirm_delete_schedule'),
      confirmLabel: tpT('global.delete'),
      danger: true,
    }).then(function (ok) {
      if (!ok) return;
      tpModal.fetchJSON(URLS.item.replace("{id}", id), { method: "DELETE" }).then(function (res) {
        if (res.ok && res.data && res.data.ok) load();
        else tpModal.toast(tpT('ui.delete_failed'), "error");
      });
    });
  }

  /* ---------- Puntos de control ---------- */
  var SENTIDOS = [
    { key: "outbound", label: "Ida" },
    { key: "inbound", label: "Vuelta" },
  ];

  /* Cabecera de columnas: sin ella los campos son cuatro numeros sueltos y
     no hay forma de saber cual es el minuto y cual la tolerancia. */
  var CP_CABECERA =
    '<div class="tp-rsch__cphead" aria-hidden="true">' +
      "<span></span>" +
      "<span>Geocerca</span>" +
      "<span>Min.</span>" +
      "<span>Antes</span>" +
      "<span>" + tpT('ui.after') + "</span>" +
      "<span>Etiqueta</span>" +
      "<span></span>" +
    "</div>";

  function cpRow(geofences, punto) {
    var opts = '<option value="">—</option>' + geofences.map(function (g) {
      return '<option value="' + g.id + '"' + (punto && punto.geofence_id === g.id ? " selected" : "") + ">" + esc(g.name) + "</option>";
    }).join("");

    // El numero lo pinta el CSS con un contador, asi se renumera solo al
    // reordenar o borrar sin que el JS tenga que repasar las filas.
    return '<div class="tp-rsch__cp" data-cp-row>' +
      '<span class="tp-rsch__cpnum" aria-hidden="true"></span>' +
      '<select class="tp-input" data-cp-geofence aria-label="' + tpT('ui.point_geofence') + '">' + opts + "</select>" +
      '<input type="number" min="0" class="tp-input" data-cp-offset placeholder="0" aria-label="Minutos desde la salida" value="' +
        (punto ? punto.planned_offset : "") + '">' +
      '<input type="number" min="0" class="tp-input" data-cp-early placeholder="—" aria-label="Tolerancia de adelanto, en minutos" value="' +
        (punto && punto.tolerance_early != null ? punto.tolerance_early : "") + '">' +
      '<input type="number" min="0" class="tp-input" data-cp-late placeholder="—" aria-label="Tolerancia de retraso, en minutos" value="' +
        (punto && punto.tolerance_late != null ? punto.tolerance_late : "") + '">' +
      '<input type="text" class="tp-input" data-cp-name placeholder="Opcional" aria-label="Etiqueta del punto" value="' +
        (punto && punto.name ? esc(punto.name) : "") + '">' +
      '<span class="tp-rsch__cpacts">' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-cp-up title="Subir">↑</button>' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-cp-down title="Bajar">↓</button>' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-cp-del title="Quitar">×</button>' +
      "</span>" +
      "</div>";
  }

  function openCheckpoints(routeId) {
    tpModal.fetchJSON(URLS.checkpoints.replace("{id}", routeId), { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};
      if (!d.ok) return tpModal.toast(tpT('ui.points_load_failed'), "error");

      var geofences = d.geofences || [];

      var body = '<div class="tp-scope tp-rsch__cps">' +
        '<p class="tp-text-muted">' + tpT('ui.checkpoints_help') + ' ' +
        '<strong>Min.</strong> ' + tpT('ui.are_minutes_from_departure') + ' <strong>Antes</strong> y <strong>' + tpT('ui.after') + '</strong> ' + tpT('ui.are_tolerance_minutes') + ' ' +
        '' + tpT('ui.blank_uses_schedule') + '</p>' +
        SENTIDOS.map(function (s) {
          var filas = (d.checkpoints[s.key] || []).map(function (p) { return cpRow(geofences, p); }).join("");
          return '<section data-cp-dir="' + s.key + '">' +
            "<h4>" + s.label + "</h4>" +
            CP_CABECERA +
            '<div data-cp-list>' + filas + "</div>" +
            '<p class="tp-rsch__cpempty">' + tpT('ui.no_points_this_way') + '</p>' +
            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-cp-add>' + tpT('ui.add_point_plus') + '</button>' +
            "</section>";
        }).join("") +
        '<div data-x-err style="display:none;color:#ef4444;font-size:13px;"></div></div>';

      var dlg = tpModal.open({
        title: tpT('ui.checkpoints_dash') + (d.route ? d.route.name : ""),
        size: "lg",
        body: body,
        buttons: [
          { label: tpT('global.cancel'), variant: "ghost", close: true },
          { label: tpT('global.save'), variant: "primary", close: false, onClick: function (el) { saveCheckpoints(el, routeId); return false; } },
        ],
      });

      var cont = dlg && dlg.querySelector ? dlg : document;

      cont.addEventListener("click", function (e) {
        var add = e.target.closest("[data-cp-add]");
        if (add) {
          add.parentNode.querySelector("[data-cp-list]").insertAdjacentHTML("beforeend", cpRow(geofences, null));
          return;
        }
        // Subir y bajar: el orden de las filas ES el orden de paso, asi que
        // sin esto un punto olvidado a medio recorrido obligaba a rehacer
        // todos los de detras.
        var sube = e.target.closest("[data-cp-up]");
        if (sube) {
          var fs = sube.closest("[data-cp-row]");
          if (fs.previousElementSibling) fs.parentNode.insertBefore(fs, fs.previousElementSibling);
          return;
        }

        var baja = e.target.closest("[data-cp-down]");
        if (baja) {
          var fb = baja.closest("[data-cp-row]");
          if (fb.nextElementSibling) fb.parentNode.insertBefore(fb.nextElementSibling, fb);
          return;
        }

        var quita = e.target.closest("[data-cp-del]");
        if (quita) quita.closest("[data-cp-row]").remove();
      });
    });
  }

  function saveCheckpoints(el, routeId) {
    var err = el.querySelector("[data-x-err]");
    function showErr(m) { if (err) { err.textContent = m; err.style.display = "block"; } return false; }
    if (err) err.style.display = "none";

    var payload = { checkpoints: {} };

    SENTIDOS.forEach(function (s) {
      var sec = el.querySelector('[data-cp-dir="' + s.key + '"]');
      var filas = [];

      if (sec) {
        sec.querySelectorAll("[data-cp-row]").forEach(function (r) {
          var geo = r.querySelector("[data-cp-geofence]").value;
          if (!geo) return; // fila vacía: no cuenta

          filas.push({
            geofence_id: Number(geo),
            planned_offset: r.querySelector("[data-cp-offset]").value || 0,
            tolerance_early: r.querySelector("[data-cp-early]").value,
            tolerance_late: r.querySelector("[data-cp-late]").value,
            name: r.querySelector("[data-cp-name]").value,
          });
        });
      }

      payload.checkpoints[s.key] = filas;
    });

    tpModal.setBusy(el, true);

    tpModal.fetchJSON(URLS.checkpoints.replace("{id}", routeId), { method: "PUT", json: payload })
      .then(function (res) {
        tpModal.setBusy(el, false);
        if (!res.ok || !res.data || !res.data.ok) return showErr(tpT('ui.save_failed'));
        tpModal.close(el);
        tpModal.toast(tpT('ui.checkpoints_saved'), "success");
      })
      .catch(function () { tpModal.setBusy(el, false); showErr(tpT('ui.network_error')); });
  }

  /* ---------- Cumplimiento ---------- */
  var CLASE_VUELTA = {
    completed: "ok",
    completed_late: "warn",
    incomplete: "warn",
    not_started: "bad",
  };
  var CLASE_PUNTO = { on_time: "ok", early: "warn", late: "warn", missed: "bad" };

  function vueltaHtml(v) {
    var clase = CLASE_VUELTA[v.status] || "";
    var puntos = v.points.map(function (p, i) {
      var desvio = p.deviation == null ? "—" : (p.deviation > 0 ? "+" + p.deviation : p.deviation);
      return "<tr>" +
        "<td>" + (i + 1) + "</td>" +
        "<td>" + esc(p.label) + "</td>" +
        "<td>" + esc(p.planned) + "</td>" +
        "<td>" + esc(p.actual || "—") + "</td>" +
        '<td style="text-align:right;">' + desvio + "</td>" +
        '<td class="tp-rsch__st tp-rsch__st--' + (CLASE_PUNTO[p.status] || "") + '">' +
          esc(state.pointStates[p.status] || p.status) + "</td>" +
        "</tr>";
    }).join("");

    return '<details class="tp-rsch__round tp-rsch__round--' + clase + '">' +
      "<summary>" +
        '<span class="tp-rsch__round-st tp-rsch__st--' + clase + '">' +
          esc(state.roundStates[v.status] || v.status) +
          " (" + v.summary.passed + "/" + v.summary.total + ")</span>" +
        "<strong>" + esc(v.device) + "</strong> · " + esc(v.schedule) +
        " · " + esc(v.route) +
        " · " + (v.direction === "inbound" ? "Vuelta" : "Ida") +
        " · " + esc(v.date) + " " + esc(v.start) +
      "</summary>" +
      '<table class="tp-svc-table"><thead><tr>' +
        "<th>#</th><th>" + tpT('ui.checkpoint') + "</th><th>Previsto</th><th>Real</th>" +
        '<th style="text-align:right;">' + tpT('ui.detour_min') + '</th><th>' + tpT('front.status') + '</th>' +
      "</tr></thead><tbody>" + puntos + "</tbody></table></details>";
  }

  function runAdherence() {
    var desde = $("tp-rsch-from").value;
    var hasta = $("tp-rsch-to").value;
    var caja = $("tp-rsch-adherence");

    if (!desde || !hasta) {
      caja.innerHTML = '<p class="tp-svc-table__empty">' + tpT('ui.pick_date_range') + '</p>';
      return;
    }

    caja.innerHTML = '<p class="tp-svc-table__empty">Calculando…</p>';

    var qs = "?from=" + encodeURIComponent(desde) + "&to=" + encodeURIComponent(hasta) +
      "&device_id=" + encodeURIComponent($("tp-rsch-filter-device").value) +
      ($("tp-rsch-only-breaches").checked ? "&only_breaches=1" : "");

    var sel = $("tp-rsch-filter-schedule").value;
    if (sel) qs += "&schedules[]=" + encodeURIComponent(sel);

    tpModal.fetchJSON(URLS.adherence + qs, { method: "GET" }).then(function (res) {
      var d = (res && res.data) || {};

      if (!d.ok) {
        caja.innerHTML = '<p class="tp-svc-table__empty">' + tpT('ui.calculation_failed') + '</p>';
        return;
      }

      caja.innerHTML = (d.items || []).length
        ? d.items.map(vueltaHtml).join("")
        : '<p class="tp-svc-table__empty">' + tpT('ui.no_laps_in_range') + '</p>';
    });
  }

  /* ---------- Wiring ---------- */
  var t = null;
  $("tp-rsch-search").addEventListener("input", function () {
    clearTimeout(t);
    t = setTimeout(function () { state.s = $("tp-rsch-search").value.trim(); state.page = 1; load(); }, 300);
  });

  if ($("tp-rsch-add")) $("tp-rsch-add").addEventListener("click", function () { openForm(null); });
  $("tp-rsch-prev").addEventListener("click", function () { if (state.page > 1) { state.page--; load(); } });
  $("tp-rsch-next").addEventListener("click", function () { state.page++; load(); });
  $("tp-rsch-run").addEventListener("click", runAdherence);

  $("tp-rsch-rows").addEventListener("click", function (e) {
    var ed = e.target.closest("[data-rsch-edit]");
    if (ed) return openForm(state.byId[ed.getAttribute("data-rsch-edit")]);

    var cp = e.target.closest("[data-rsch-cp]");
    if (cp) return openCheckpoints(cp.getAttribute("data-rsch-cp"));

    var dl = e.target.closest("[data-rsch-del]");
    if (dl) return del(dl.getAttribute("data-rsch-del"));
  });

  // Por defecto, el rango es el mes en curso hasta hoy
  (function () {
    var hoy = new Date();
    var primero = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    function iso(d) { return d.toISOString().slice(0, 10); }
    $("tp-rsch-from").value = iso(primero);
    $("tp-rsch-to").value = iso(hoy);
  })();
})();
