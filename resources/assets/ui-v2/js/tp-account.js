/* =====================================================
 * TalentusPro · tp-account — Ajustes de cuenta (modal con tabs)
 * -----------------------------------------------------
 * Paridad con el modal legacy MyAccountSettings (títulos exactos),
 * con el diseño v2. Se abre desde el topbar ([data-tp-account]).
 * Tabs (orden legacy):
 *   Principal · Grupos de vehículos · Conductores · Tipos de vehículo
 *   · Eventos · SMS gateway · Plantillas de SMS · Plantillas de GPRS
 *   · Widgets · Tablero · Cliente · Mapa
 * Los tabs de formulario guardan por su endpoint account/*; los tabs
 * CRUD renderizan una tabla + Añadir/Editar/Eliminar en diálogos
 * apilados, reutilizando endpoints ya existentes.
 * El cambio de contraseña NO está aquí (va en el menú de usuario).
 * ===================================================== */
(function () {
  "use strict";

  if (!window.tpModal) return;

  var BASE = "/ui/v2/api/account";
  var API = "/ui/v2/api";
  var META = null;

  function esc(s) {
    return tpModal.escapeHtml(s == null ? "" : s);
  }
  function options(list, selected) {
    return (list || [])
      .map(function (o) {
        return '<option value="' + esc(o.id) + '"' + (String(selected) === String(o.id) ? " selected" : "") + ">" + esc(o.label) + "</option>";
      })
      .join("");
  }
  function field(label, inner, required, span2) {
    return (
      '<label class="tp-form__field' + (required ? " tp-form__field--required" : "") + (span2 ? " tp-col-2" : "") + '">' +
      '<span class="tp-form__label">' + esc(label) + "</span>" + inner + "</label>"
    );
  }
  function sel(name, list, selected) {
    return '<select class="tp-input" name="' + name + '">' + options(list, selected) + "</select>";
  }

  /* ================= Tab: Principal (locale) ================= */

  function tabLocale() {
    var L = META.locale, D = META.dst, LS = META.lists;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="locale">' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Unidad de distancia", sel("unit_of_distance", LS.units_of_distance, L.unit_of_distance)) +
      field("Unidad de capacidad", sel("unit_of_capacity", LS.units_of_capacity, L.unit_of_capacity)) +
      field("Unidad de altitud", sel("unit_of_altitude", LS.units_of_altitude, L.unit_of_altitude)) +
      field("Formato de duración", sel("duration_format", LS.duration_formats, L.duration_format)) +
      field("Formato de fecha", sel("date_format", LS.date_formats, L.date_format)) +
      field("Formato de hora", sel("time_format", LS.time_formats, L.time_format)) +
      field("Inicio de semana", sel("week_start_day", LS.week_start_days, L.week_start_day)) +
      field("Zona horaria", sel("timezone_id", LS.timezones, L.timezone_id)) +
      '<div class="tp-col-2" style="border-top:1px solid var(--border-default,#e9eef5);margin-top:4px;padding-top:10px;">' +
      field("Horario de verano", sel("dst_type", LS.dst_types, D.type), false, false) +
      '<div data-acc-dst="automatic" style="display:none;margin-top:8px;">' +
      field("País", sel("dst_country_id", LS.dst_countries, D.country_id), false, false) + "</div>" +
      '<div data-acc-dst="exact" style="display:none;margin-top:8px;"><div class="tp-form__grid tp-form__grid--2">' +
      field("Desde (mm-dd hh:ii)", '<input type="text" name="date_from" value="' + esc(D.date_from) + '" data-tp-field>') +
      field("Hasta (mm-dd hh:ii)", '<input type="text" name="date_to" value="' + esc(D.date_to) + '" data-tp-field>') +
      "</div></div>" +
      '<div data-acc-dst="other" style="display:none;margin-top:8px;">' +
      '<div style="display:grid;grid-template-columns:2fr 1fr 2fr 1fr;gap:8px;margin-bottom:8px;">' +
      sel("month_from", LS.months, D.month_from) + sel("week_pos_from", LS.week_pos, D.week_pos_from) +
      sel("week_day_from", LS.weekdays, D.week_day_from) + '<input type="text" name="time_from" value="' + esc(D.time_from) + '" data-tp-field>' +
      "</div><div style=\"display:grid;grid-template-columns:2fr 1fr 2fr 1fr;gap:8px;\">" +
      sel("month_to", LS.months, D.month_to) + sel("week_pos_to", LS.week_pos, D.week_pos_to) +
      sel("week_day_to", LS.weekdays, D.week_day_to) + '<input type="text" name="time_to" value="' + esc(D.time_to) + '" data-tp-field>' +
      "</div></div></div></div></form>"
    );
  }

  /* ================= Tab: SMS gateway ================= */

  function tabSms() {
    var S = META.sms, LS = META.lists;
    var dis = META.caps.edit_sms ? "" : " disabled";
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="sms">' +
      '<label class="tp-acc-check" style="margin-bottom:12px;"><input type="checkbox" class="tp-choice-input" name="sms_gateway" value="1"' + (S.enabled ? " checked" : "") + dis + "> Habilitar SMS gateway</label>" +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Método de solicitud", '<select class="tp-input" name="request_method"' + dis + " data-acc-sms-method>" + options(LS.request_methods, S.request_method) + "</select>") +
      '<div data-acc-sms="post">' + field("Codificación", '<select class="tp-input" name="encoding"' + dis + ">" + options(LS.encodings, S.encoding) + "</select>") + "</div>" +
      '<div data-acc-sms="get post">' + field("Autenticación", '<select class="tp-input" name="authentication"' + dis + " data-acc-sms-auth>" + options(LS.authentications, S.authentication) + "</select>") + "</div>" +
      '<div data-acc-sms="get post" data-acc-sms-authblock>' + field("Usuario", '<input type="text" name="username" value="' + esc(S.username) + '"' + dis + " data-tp-field>") + "</div>" +
      '<div data-acc-sms="get post" data-acc-sms-authblock>' + field("Contraseña", '<input type="password" name="password" autocomplete="new-password"' + dis + " data-tp-field>") + "</div>" +
      '<div class="tp-col-2" data-acc-sms="get post">' + field("Cabeceras", '<textarea name="custom_headers" rows="2"' + dis + " data-tp-field>" + esc(S.custom_headers) + "</textarea>", false, false) +
      '<small class="tp-form__hint">Lista separada por punto y coma (p. ej. Accept: text/plain; Accept-Language: en-US;)</small></div>' +
      '<div class="tp-col-2" data-acc-sms="get post">' + field("URL del gateway", '<textarea name="sms_gateway_url" rows="2"' + dis + " data-tp-field>" + esc(S.url) + "</textarea>", false, false) + "</div>" +
      '<div data-acc-sms="plivo">' + field("Auth ID", '<input type="text" name="auth_id" value="' + esc(S.auth_id) + '"' + dis + " data-tp-field>") + "</div>" +
      '<div data-acc-sms="plivo">' + field("Auth Token", '<input type="text" name="auth_token" value="' + esc(S.auth_token) + '"' + dis + " data-tp-field>") + "</div>" +
      '<div data-acc-sms="plivo">' + field("Teléfono remitente", '<input type="text" name="senders_phone" value="' + esc(S.senders_phone) + '"' + dis + " data-tp-field>") + "</div>" +
      "</div>" +
      '<div class="tp-col-2" style="margin-top:10px;padding:10px 12px;background:var(--brand-bg-subtle,#eef3fb);border-radius:8px;font-size:12px;color:var(--fg-secondary,#475569);">' +
      "Variables: %NUMBER%, %MESSAGE%. Ejemplo: http://SMS_GATEWAY/sendsms.php?username=USER&password=PASS&number=%NUMBER%&message=%MESSAGE%" +
      '<div data-acc-sms="app" style="margin-top:6px;">SMS en cola: <b>' + S.queue_count + "</b></div></div></form>"
    );
  }

  /* ================= Tab: Widgets ================= */

  function tabWidgets() {
    var W = META.widgets;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="widgets">' +
      '<label class="tp-acc-check" style="margin-bottom:10px;"><input type="checkbox" class="tp-choice-input" name="widgets_default" value="1"' + (W.default ? " checked" : "") + " data-acc-wdefault> Widgets por defecto</label>" +
      '<div data-acc-wbody' + (W.default ? ' style="opacity:.5;pointer-events:none;"' : "") + ">" +
      '<label class="tp-acc-check" style="margin-bottom:12px;"><input type="checkbox" class="tp-choice-input" name="widgets_status" value="1"' + (W.status ? " checked" : "") + "> Habilitar widgets</label>" +
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">' +
      W.items
        .map(function (it) {
          return '<label class="tp-acc-tile"><input type="checkbox" class="tp-choice-input" name="widgets_list" value="' + esc(it.key) + '"' + (it.on ? " checked" : "") + "> " + esc(it.label) + "</label>";
        })
        .join("") +
      "</div></div></form>"
    );
  }

  /* ================= Tab: Tablero (dashboard) ================= */

  function tabDashboard() {
    var D = META.dashboard;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="dashboard">' +
      '<label class="tp-acc-check" style="margin-bottom:12px;"><input type="checkbox" class="tp-choice-input" name="dashboard_enabled" value="1"' + (D.enabled ? " checked" : "") + "> Habilitar tablero</label>" +
      '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--fg-muted,#94a3b8);margin-bottom:8px;">Widgets del tablero</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
      D.blocks
        .map(function (b) {
          return '<label class="tp-acc-tile"><input type="checkbox" class="tp-choice-input" name="dashboard_blocks" value="' + esc(b.key) + '"' + (b.enabled ? " checked" : "") + "> " + esc(b.label) + "</label>";
        })
        .join("") +
      "</div></form>"
    );
  }

  /* ================= Tab: Cliente / Empresa ================= */

  function tabClient() {
    var C = META.client, K = META.company;
    var dis = META.caps.edit_client ? "" : " disabled";
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="client">' +
      '<div class="tp-acc-legend">Cliente</div>' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Nombre", '<input type="text" name="client[first_name]" value="' + esc(C.first_name) + '"' + dis + " data-tp-field>") +
      field("Apellido", '<input type="text" name="client[last_name]" value="' + esc(C.last_name) + '"' + dis + " data-tp-field>") +
      field("Código personal", '<input type="text" name="client[personal_code]" value="' + esc(C.personal_code) + '"' + dis + " data-tp-field>") +
      field("Fecha de nacimiento", '<input type="date" name="client[birth_date]" value="' + esc(C.birth_date) + '"' + dis + ">") +
      field("Dirección", '<input type="text" name="client[address]" value="' + esc(C.address) + '"' + dis + " data-tp-field>", false, true) +
      "</div>" +
      '<div class="tp-acc-legend" style="margin-top:12px;">Empresa</div>' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Nombre", '<input type="text" name="company[name]" value="' + esc(K.name) + '"' + dis + " data-tp-field>", false, true) +
      field("Código de registro", '<input type="text" name="company[registration_code]" value="' + esc(K.registration_code) + '"' + dis + " data-tp-field>") +
      field("N° de IVA / RUC", '<input type="text" name="company[vat_number]" value="' + esc(K.vat_number) + '"' + dis + " data-tp-field>") +
      field("Dirección", '<input type="text" name="company[address]" value="' + esc(K.address) + '"' + dis + " data-tp-field>", false, true) +
      "</div></form>"
    );
  }

  /* ================= Tab: Mapa (2 toggles) ================= */

  function tabMap() {
    var M = META.map;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="map">' +
      '<label class="tp-acc-toggle"><span><b>Mostrar velocidad en el marcador</b><small>Muestra la velocidad al costado del nombre del vehículo en el mapa.</small></span>' +
      '<input type="checkbox" class="tp-choice-input" name="show_speed" value="1"' + (M.show_speed ? " checked" : "") + "></label>" +
      '<label class="tp-acc-toggle"><span><b>Colorear etiqueta según el estado</b><small>La etiqueta del marcador toma el color del estado del vehículo (movimiento, ralentí, detenido…).</small></span>' +
      '<input type="checkbox" class="tp-choice-input" name="status_color" value="1"' + (M.status_color ? " checked" : "") + "></label>" +
      "</form>"
    );
  }

  /* ================= Tabs CRUD ================= */
  // Cada uno: tabla en el panel + "Añadir" que abre un diálogo apilado.

  function crudPanel(key, addLabel, cols) {
    return (
      '<div class="tp-acc-crud" data-acc-crud="' + key + '">' +
      '<div class="tp-acc-crud__head">' +
      '<input type="search" class="tp-input" data-acc-crud-search placeholder="Buscar…" style="max-width:240px;">' +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-acc-crud-add>+ ' + esc(addLabel) + "</button>" +
      "</div>" +
      '<div class="tp-acc-crud__wrap"><table class="tp-svc-table"><thead><tr>' +
      cols.map(function (c) { return "<th>" + esc(c) + "</th>"; }).join("") +
      '<th class="tp-svc-table__actions"></th></tr></thead>' +
      '<tbody data-acc-crud-rows><tr><td colspan="' + (cols.length + 1) + '" class="tp-svc-table__empty">…</td></tr></tbody></table></div></div>'
    );
  }

  // Cableado genérico de un tab CRUD
  function wireCrud(panel, cfg) {
    var rowsEl = panel.querySelector("[data-acc-crud-rows]");
    var searchEl = panel.querySelector("[data-acc-crud-search]");
    var addBtn = panel.querySelector("[data-acc-crud-add]");
    var colspan = cfg.cols.length + 1;
    var q = "";
    var timer = null;

    function load() {
      var url = cfg.listUrl + (cfg.listUrl.indexOf("?") >= 0 ? "&" : "?") + "s=" + encodeURIComponent(q) + "&per_page=100";
      tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          rowsEl.innerHTML = '<tr><td colspan="' + colspan + '" class="tp-svc-table__empty">No se pudo cargar.</td></tr>';
          return;
        }
        var items = res.data.items || [];
        rowsEl.innerHTML = items.length
          ? items
              .map(function (r) {
                return (
                  "<tr>" +
                  cfg.row(r) +
                  '<td class="tp-svc-table__actions">' +
                  '<button type="button" class="tp-icon-btn" data-acc-edit title="Editar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg></button>' +
                  '<button type="button" class="tp-icon-btn tp-icon-btn--danger" data-acc-del title="Eliminar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>' +
                  "</td></tr>"
                ).replace("<tr>", '<tr data-acc-item=\'' + esc(JSON.stringify(r)) + "'>");
              })
              .join("")
          : '<tr><td colspan="' + colspan + '" class="tp-svc-table__empty">Sin datos</td></tr>';
      });
    }

    searchEl.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        q = searchEl.value.trim();
        load();
      }, 300);
    });
    addBtn.addEventListener("click", function () {
      cfg.form(null, load);
    });
    rowsEl.addEventListener("click", function (e) {
      var tr = e.target.closest("[data-acc-item]");
      if (!tr) return;
      var row = JSON.parse(tr.getAttribute("data-acc-item"));
      if (e.target.closest("[data-acc-edit]")) {
        cfg.form(row, load);
      } else if (e.target.closest("[data-acc-del]")) {
        tpModal
          .confirm({ title: cfg.delTitle || "Eliminar", message: cfg.delMsg || "¿Eliminar este registro?", danger: true, confirmLabel: "Eliminar" })
          .then(function (go) {
            if (!go) return;
            tpModal.fetchJSON(cfg.itemUrl(row.id), { method: "DELETE" }).then(function (res) {
              if (res.ok && res.data && res.data.ok) {
                tpModal.toast("Eliminado.", "success");
                load();
              } else tpModal.toast("No se pudo eliminar.", "error");
            });
          });
      }
    });

    load();
  }

  // Diálogo genérico de formulario CRUD (apilado sobre el modal de cuenta)
  function crudDialog(title, bodyHtml, onSave, onMount) {
    var dlg = tpModal.open({
      title: title,
      size: "md",
      static: true,
      body: bodyHtml,
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            onSave(d);
            return false;
          },
        },
      ],
    });
    if (onMount) onMount(dlg);
    return dlg;
  }

  function crudSave(dlg, url, method, payload, onSaved) {
    tpModal.setBusy(dlg, true);
    tpModal
      .fetchJSON(url, { method: method, json: payload })
      .then(function (res) {
        tpModal.setBusy(dlg, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          var first = Object.keys(errors)[0];
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.", "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
          return;
        }
        tpModal.toast("Guardado.", "success");
        tpModal.close(dlg);
        if (onSaved) onSaved();
      })
      .catch(function () {
        tpModal.setBusy(dlg, false);
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ---- Grupos de vehículos ---- */

  function tabGroups() {
    return crudPanel("groups", "Añadir grupo", ["Título", "Vehículos"]);
  }
  function wireGroups(panel) {
    wireCrud(panel, {
      listUrl: API + "/device-groups",
      itemUrl: function (id) { return API + "/device-groups/" + id; },
      cols: ["Título", "Vehículos"],
      row: function (r) { return "<td>" + esc(r.title) + "</td><td>" + (r.count || 0) + "</td>"; },
      delTitle: "Eliminar grupo",
      delMsg: "Los vehículos quedarán sin grupo. ¿Eliminar?",
      form: function (row, onSaved) {
        var isEdit = !!(row && row.id);
        crudDialog(
          isEdit ? "Editar grupo" : "Nuevo grupo",
          '<div class="tp-form">' + field("Título", '<input type="text" name="g_title" value="' + esc(row ? row.title : "") + '" data-tp-field>', true) + "</div>",
          function (d) {
            var title = d.querySelector('[name="g_title"]').value.trim();
            if (!title) { tpModal.toast("El título es obligatorio.", "warning"); return; }
            crudSave(d, isEdit ? API + "/device-groups/" + row.id : API + "/device-groups", isEdit ? "PUT" : "POST", { title: title }, onSaved);
          },
        );
      },
    });
  }

  /* ---- Conductores ---- */

  function tabDrivers() {
    return crudPanel("drivers", "Añadir conductor", ["Nombre", "RFID", "Teléfono", "Email"]);
  }
  function wireDrivers(panel) {
    wireCrud(panel, {
      listUrl: API + "/drivers",
      itemUrl: function (id) { return API + "/drivers/" + id; },
      cols: ["Nombre", "RFID", "Teléfono", "Email"],
      row: function (r) {
        return "<td>" + esc(r.name) + "</td><td>" + esc(r.rfid || "–") + "</td><td>" + esc(r.phone || "–") + "</td><td>" + esc(r.email || "–") + "</td>";
      },
      delTitle: "Eliminar conductor",
      delMsg: "¿Eliminar este conductor?",
      form: function (row, onSaved) {
        var isEdit = !!(row && row.id);
        crudDialog(
          isEdit ? "Editar conductor" : "Nuevo conductor",
          '<div class="tp-form"><div class="tp-form__grid tp-form__grid--2">' +
            field("Nombre", '<input type="text" name="d_name" value="' + esc(row ? row.name : "") + '" data-tp-field>', true, true) +
            field("RFID", '<input type="text" name="d_rfid" value="' + esc(row ? row.rfid : "") + '" data-tp-field>') +
            field("Teléfono", '<input type="text" name="d_phone" value="' + esc(row ? row.phone : "") + '" data-tp-field>') +
            field("Email", '<input type="text" name="d_email" value="' + esc(row ? row.email : "") + '" data-tp-field>') +
            field("Descripción", '<input type="text" name="d_desc" value="' + esc(row ? row.description : "") + '" data-tp-field>') +
            "</div></div>",
          function (d) {
            var name = d.querySelector('[name="d_name"]').value.trim();
            if (!name) { tpModal.toast("El nombre es obligatorio.", "warning"); return; }
            var payload = {
              name: name,
              rfid: d.querySelector('[name="d_rfid"]').value.trim(),
              phone: d.querySelector('[name="d_phone"]').value.trim(),
              email: d.querySelector('[name="d_email"]').value.trim(),
              description: d.querySelector('[name="d_desc"]').value.trim(),
            };
            crudSave(d, isEdit ? API + "/drivers/" + row.id : API + "/drivers", isEdit ? "PUT" : "POST", payload, onSaved);
          },
        );
      },
    });
  }

  /* ---- Tipos de vehículo (con estados) ---- */

  function tabVehicleTypes() {
    return crudPanel("vtypes", "Añadir tipo", ["Nombre", "Estados"]);
  }
  function wireVehicleTypes(panel) {
    wireCrud(panel, {
      listUrl: API + "/vehicle-types",
      itemUrl: function (id) { return API + "/vehicle-types/" + id; },
      cols: ["Nombre", "Estados"],
      row: function (r) {
        var st = (r.states || []).map(function (s) { return esc(s.name); }).join(", ");
        return "<td>" + esc(r.name) + "</td><td>" + (st || "–") + "</td>";
      },
      delTitle: "Eliminar tipo de vehículo",
      delMsg: "¿Eliminar este tipo y sus estados?",
      form: function (row, onSaved) {
        var isEdit = !!(row && row.id);
        var states = (row && row.states) || [];
        var stateRows = states
          .map(function (s) {
            return (
              '<div class="tp-acc-strow" data-state-id="' + s.id + '">' +
              '<input type="text" class="tp-input" data-edit-state="' + s.id + '" value="' + esc(s.name) + '">' +
              '<button type="button" class="tp-icon-btn tp-icon-btn--danger" data-rm-state title="Quitar">×</button></div>'
            );
          })
          .join("");
        crudDialog(
          isEdit ? "Editar tipo de vehículo" : "Nuevo tipo de vehículo",
          '<div class="tp-form">' +
            field("Nombre", '<input type="text" name="vt_name" value="' + esc(row ? row.name : "") + '" data-tp-field>', true) +
            '<div class="tp-form__field"><span class="tp-form__label">Estados</span>' +
            '<div data-states-wrap>' + stateRows + "</div>" +
            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-add-state style="margin-top:6px;">+ Añadir estado</button>' +
            "</div></div>",
          function (d) {
            var name = d.querySelector('[name="vt_name"]').value.trim();
            if (!name) { tpModal.toast("El nombre es obligatorio.", "warning"); return; }
            var payload = { name: name, edit_state: {}, add_state: [] };
            Array.prototype.forEach.call(d.querySelectorAll("[data-edit-state]"), function (i) {
              if (i.value.trim()) payload.edit_state[i.getAttribute("data-edit-state")] = i.value.trim();
            });
            Array.prototype.forEach.call(d.querySelectorAll("[data-new-state]"), function (i) {
              if (i.value.trim()) payload.add_state.push(i.value.trim());
            });
            crudSave(d, isEdit ? API + "/vehicle-types/" + row.id : API + "/vehicle-types", isEdit ? "PUT" : "POST", payload, onSaved);
          },
          function (d) {
            var wrap = d.querySelector("[data-states-wrap]");
            d.querySelector("[data-add-state]").addEventListener("click", function () {
              var div = document.createElement("div");
              div.className = "tp-acc-strow";
              div.innerHTML = '<input type="text" class="tp-input" data-new-state placeholder="Nuevo estado"><button type="button" class="tp-icon-btn tp-icon-btn--danger" data-rm-state title="Quitar">×</button>';
              wrap.appendChild(div);
            });
            d.addEventListener("click", function (e) {
              if (e.target.closest("[data-rm-state]")) e.target.closest(".tp-acc-strow").remove();
            });
          },
        );
      },
    });
  }

  /* ---- Eventos (custom_events) ---- */

  var CE_META = null;
  function tabEvents() {
    return crudPanel("events", "Añadir evento", ["Mensaje", "Protocolo", "Condiciones"]);
  }
  function wireEvents(panel) {
    tpModal.fetchJSON(API + "/custom-events/meta", { method: "GET" }).then(function (res) {
      CE_META = res.ok && res.data && res.data.ok ? res.data : { protocols: [], types: [] };
    });
    wireCrud(panel, {
      listUrl: API + "/custom-events",
      itemUrl: function (id) { return API + "/custom-events/" + id; },
      cols: ["Mensaje", "Protocolo", "Condiciones"],
      row: function (r) {
        return "<td>" + esc(r.message) + "</td><td>" + esc(r.protocol) + "</td><td>" + (r.conditions ? r.conditions.length : 0) + "</td>";
      },
      delTitle: "Eliminar evento",
      delMsg: "¿Eliminar este evento?",
      form: function (row, onSaved) {
        var isEdit = !!(row && row.id);
        var meta = CE_META || { protocols: [], types: [] };
        var conds = (row && row.conditions && row.conditions.length ? row.conditions : [{ tag: "", type: "", tag_value: "" }]);
        function condRow(c) {
          return (
            '<div class="tp-acc-cond">' +
            '<input type="text" class="tp-input" data-c-tag value="' + esc(c.tag) + '" placeholder="Parámetro">' +
            '<select class="tp-input" data-c-type>' + options(meta.types, c.type) + "</select>" +
            '<input type="text" class="tp-input" data-c-val value="' + esc(c.tag_value) + '" placeholder="Valor">' +
            '<button type="button" class="tp-icon-btn tp-icon-btn--danger" data-rm-cond title="Quitar">×</button></div>'
          );
        }
        crudDialog(
          isEdit ? "Editar evento" : "Nuevo evento",
          '<div class="tp-form">' +
            field("Protocolo", '<select class="tp-input" name="ce_protocol">' + options(meta.protocols, row ? row.protocol : "") + "</select>", true) +
            field("Mensaje", '<input type="text" name="ce_message" value="' + esc(row ? row.message : "") + '" data-tp-field>', true) +
            '<label class="tp-acc-check" style="margin:4px 0 8px;"><input type="checkbox" class="tp-choice-input" name="ce_always" value="1"' + (row && row.always ? " checked" : "") + "> Siempre</label>" +
            '<div class="tp-form__field"><span class="tp-form__label">Condiciones</span>' +
            '<div data-conds-wrap>' + conds.map(condRow).join("") + "</div>" +
            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-add-cond style="margin-top:6px;">+ Añadir condición</button></div>' +
            "</div>",
          function (d) {
            var message = d.querySelector('[name="ce_message"]').value.trim();
            var protocol = d.querySelector('[name="ce_protocol"]').value;
            if (!protocol || !message) { tpModal.toast("Protocolo y mensaje son obligatorios.", "warning"); return; }
            var conditions = [];
            Array.prototype.forEach.call(d.querySelectorAll(".tp-acc-cond"), function (c) {
              var tag = c.querySelector("[data-c-tag]").value.trim();
              var type = c.querySelector("[data-c-type]").value;
              var val = c.querySelector("[data-c-val]").value.trim();
              if (tag || val) conditions.push({ tag: tag, type: type, tag_value: val });
            });
            if (!conditions.length) { tpModal.toast("Añade al menos una condición.", "warning"); return; }
            var payload = { protocol: protocol, message: message, always: d.querySelector('[name="ce_always"]').checked ? 1 : 0, conditions: conditions };
            crudSave(d, isEdit ? API + "/custom-events/" + row.id : API + "/custom-events", isEdit ? "PUT" : "POST", payload, onSaved);
          },
          function (d) {
            var wrap = d.querySelector("[data-conds-wrap]");
            d.querySelector("[data-add-cond]").addEventListener("click", function () {
              var div = document.createElement("div");
              div.innerHTML = condRow({ tag: "", type: "", tag_value: "" });
              wrap.appendChild(div.firstChild);
            });
            d.addEventListener("click", function (e) {
              if (e.target.closest("[data-rm-cond]")) e.target.closest(".tp-acc-cond").remove();
            });
          },
        );
      },
    });
  }

  /* ---- Plantillas de SMS / GPRS ---- */

  function tabTemplates(type) {
    return crudPanel("tpl_" + type, type === "sms" ? "Añadir plantilla" : "Añadir plantilla", ["Título", "Mensaje"]);
  }
  function wireTemplates(panel, type) {
    var base = API + "/commands/templates";
    wireCrud(panel, {
      listUrl: base + "?type=" + type,
      itemUrl: function (id) { return base + "/" + id + "?type=" + type; },
      cols: ["Título", "Mensaje"],
      row: function (r) { return "<td>" + esc(r.title) + "</td><td>" + esc(r.message) + "</td>"; },
      delTitle: "Eliminar plantilla",
      delMsg: "¿Eliminar esta plantilla?",
      form: function (row, onSaved) {
        var isEdit = !!(row && row.id);
        crudDialog(
          isEdit ? "Editar plantilla" : "Nueva plantilla",
          '<div class="tp-form">' +
            field("Título", '<input type="text" name="t_title" value="' + esc(row ? row.title : "") + '" data-tp-field>', true) +
            field("Mensaje", '<textarea name="t_message" rows="3" data-tp-field>' + esc(row ? row.message : "") + "</textarea>", true) +
            "</div>",
          function (d) {
            var title = d.querySelector('[name="t_title"]').value.trim();
            var message = d.querySelector('[name="t_message"]').value.trim();
            if (!title || !message) { tpModal.toast("Título y mensaje son obligatorios.", "warning"); return; }
            var payload = { type: type, title: title, message: message };
            crudSave(d, isEdit ? base + "/" + row.id : base, isEdit ? "PUT" : "POST", payload, onSaved);
          },
        );
      },
    });
  }

  /* ================= Definición de tabs ================= */

  var TABS = [
    { key: "locale", label: "Principal", build: tabLocale, save: "locale" },
    { key: "groups", label: "Grupos de vehículos", build: tabGroups, wire: wireGroups },
    { key: "drivers", label: "Conductores", build: tabDrivers, wire: wireDrivers, cap: "view_drivers" },
    { key: "vtypes", label: "Tipos de vehículo", build: tabVehicleTypes, wire: wireVehicleTypes },
    { key: "events", label: "Eventos", build: tabEvents, wire: wireEvents, cap: "view_custom_events" },
    { key: "sms", label: "SMS gateway", build: tabSms, save: "sms", cap: "view_sms" },
    { key: "tpl_sms", label: "Plantillas de SMS", build: function () { return tabTemplates("sms"); }, wire: function (p) { wireTemplates(p, "sms"); }, cap: "view_sms_templates" },
    { key: "tpl_gprs", label: "Plantillas de GPRS", build: function () { return tabTemplates("gprs"); }, wire: function (p) { wireTemplates(p, "gprs"); }, cap: "view_gprs_templates" },
    { key: "widgets", label: "Widgets", build: tabWidgets, save: "widgets" },
    { key: "dashboard", label: "Tablero", build: tabDashboard, save: "dashboard" },
    { key: "client", label: "Cliente", build: tabClient, save: "client", cap: "view_client" },
    { key: "map", label: "Mapa", build: tabMap, save: "map" },
  ];

  function visibleTabs() {
    return TABS.filter(function (t) {
      return !t.cap || META.caps[t.cap];
    });
  }

  /* ================= Modal ================= */

  function open(initialTab) {
    var dlg = tpModal.open({
      title: "Ajustes de cuenta",
      size: "xl",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:32px;">Cargando…</div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
    });

    tpModal.fetchJSON(BASE + "/meta", { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        var b = dlg.querySelector(".tp-modal__body");
        if (b) b.innerHTML = '<div class="tp-text-center tp-text-error" style="padding:24px;">No se pudo cargar.</div>';
        return;
      }
      META = res.data;
      render(dlg, initialTab);
    });
  }

  function render(dlg, initialTab) {
    var tabs = visibleTabs();
    var body = dlg.querySelector(".tp-modal__body");
    body.innerHTML =
      '<div class="tp-acc">' +
      '<div class="tp-acc__tabs" role="tablist">' +
      tabs.map(function (t) { return '<button type="button" class="tp-acc__tab" data-acc-go="' + t.key + '">' + esc(t.label) + "</button>"; }).join("") +
      "</div>" +
      '<div class="tp-acc__panel" data-acc-panel></div>' +
      '<div class="tp-acc__foot" data-acc-foot></div>' +
      "</div>";

    body.querySelector(".tp-acc__tabs").addEventListener("click", function (e) {
      var b = e.target.closest("[data-acc-go]");
      if (b) showTab(dlg, b.getAttribute("data-acc-go"));
    });

    var start = initialTab && tabs.some(function (t) { return t.key === initialTab; }) ? initialTab : tabs[0].key;
    showTab(dlg, start);
  }

  function showTab(dlg, key) {
    var tab = TABS.filter(function (t) { return t.key === key; })[0];
    if (!tab) return;
    dlg._accActive = tab;
    Array.prototype.forEach.call(dlg.querySelectorAll("[data-acc-go]"), function (b) {
      b.classList.toggle("is-active", b.getAttribute("data-acc-go") === key);
    });
    var panel = dlg.querySelector("[data-acc-panel]");
    panel.innerHTML = tab.build();

    // Footer: los tabs de formulario tienen "Guardar"; los CRUD no.
    var foot = dlg.querySelector("[data-acc-foot]");
    if (tab.save) {
      foot.innerHTML = '<button type="button" class="tp-btn tp-btn--primary" data-acc-save>Guardar</button>';
      foot.querySelector("[data-acc-save]").addEventListener("click", function () { saveForm(dlg, tab); });
    } else {
      foot.innerHTML = "";
    }

    if (key === "locale") wireDst(panel);
    else if (key === "sms") wireSmsTab(panel);
    else if (key === "widgets") wireWidgetsTab(panel);
    else if (tab.wire) tab.wire(panel);
  }

  /* ---- Interacciones de los tabs de formulario ---- */

  function wireDst(panel) {
    var s = panel.querySelector('[name="dst_type"]');
    function sync() {
      Array.prototype.forEach.call(panel.querySelectorAll("[data-acc-dst]"), function (el) {
        el.style.display = el.getAttribute("data-acc-dst") === s.value ? "" : "none";
      });
    }
    s.addEventListener("change", sync);
    sync();
  }
  function wireSmsTab(panel) {
    var method = panel.querySelector("[data-acc-sms-method]");
    var auth = panel.querySelector("[data-acc-sms-auth]");
    function sync() {
      var v = method.value;
      var authOn = auth && String(auth.value) === "1";
      Array.prototype.forEach.call(panel.querySelectorAll("[data-acc-sms]"), function (el) {
        var forMethod = el.getAttribute("data-acc-sms").split(" ").indexOf(v) >= 0;
        el.style.display = forMethod && (!el.hasAttribute("data-acc-sms-authblock") || authOn) ? "" : "none";
      });
    }
    method.addEventListener("change", sync);
    if (auth) auth.addEventListener("change", sync);
    sync();
  }
  function wireWidgetsTab(panel) {
    var def = panel.querySelector("[data-acc-wdefault]");
    var body = panel.querySelector("[data-acc-wbody]");
    def.addEventListener("change", function () {
      body.style.opacity = def.checked ? ".5" : "";
      body.style.pointerEvents = def.checked ? "none" : "";
    });
  }

  /* ---- Guardar tab de formulario ---- */

  function collect(form) {
    var data = {};
    Array.prototype.forEach.call(form.querySelectorAll("input, select, textarea"), function (el) {
      if (!el.name) return;
      if (el.type === "checkbox") {
        if (el.name === "widgets_list" || el.name === "dashboard_blocks") {
          data[el.name] = data[el.name] || [];
          if (el.checked) data[el.name].push(el.value);
        } else data[el.name] = el.checked ? 1 : 0;
      } else data[el.name] = el.value;
    });
    return data;
  }
  function toNested(flat) {
    var out = {};
    Object.keys(flat).forEach(function (k) {
      var m = k.match(/^(\w+)\[(\w+)\]$/);
      if (m) { out[m[1]] = out[m[1]] || {}; out[m[1]][m[2]] = flat[k]; }
      else out[k] = flat[k];
    });
    return out;
  }
  function saveForm(dlg, tab) {
    var form = dlg.querySelector(".tp-acc-form");
    if (!form) return;
    var flat = collect(form);
    var payload;
    if (tab.key === "client") payload = toNested(flat);
    else if (tab.key === "widgets") payload = { widgets: { default: flat.widgets_default, status: flat.widgets_status, list: flat.widgets_list || [] } };
    else if (tab.key === "dashboard") payload = { dashboard: { enabled: flat.dashboard_enabled, blocks: flat.dashboard_blocks || [] } };
    else payload = flat;

    tpModal.setBusy(dlg, true);
    tpModal
      .fetchJSON(BASE + "/" + tab.save, { method: "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(dlg, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          var first = Object.keys(errors)[0];
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.", "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
          return;
        }
        tpModal.toast("Cambios guardados.", "success");
        if (tab.key === "map") setTimeout(function () { window.location.reload(); }, 700);
      })
      .catch(function () {
        tpModal.setBusy(dlg, false);
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ================= Cambiar contraseña (menú de usuario) ================= */

  function openPassword() {
    // Necesita saber si se exige la contraseña actual
    tpModal.fetchJSON(BASE + "/meta", { method: "GET" }).then(function (res) {
      var reqCur = res.ok && res.data && res.data.ok && res.data.caps && res.data.caps.require_current;
      var dlg = tpModal.open({
        title: "Cambiar contraseña",
        size: "sm",
        static: true,
        body:
          '<form class="tp-form tp-pass-form" autocomplete="off"><div class="tp-form__grid">' +
          (reqCur ? field("Contraseña actual", '<input type="password" name="current_password" autocomplete="current-password" data-tp-field>', true) : "") +
          field("Nueva contraseña", '<input type="password" name="password" autocomplete="new-password" data-tp-field>', true) +
          field("Confirmar contraseña", '<input type="password" name="password_confirmation" autocomplete="new-password" data-tp-field>', true) +
          "</div></form>",
        buttons: [
          { label: "Cancelar", variant: "ghost", close: true },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (d) {
              var f = d.querySelector(".tp-pass-form");
              var payload = {};
              Array.prototype.forEach.call(f.querySelectorAll("input"), function (i) { payload[i.name] = i.value; });
              if (!payload.password) { tpModal.toast("Ingresa la nueva contraseña.", "warning"); return false; }
              crudSave(d, BASE + "/password", "POST", payload);
              return false;
            },
          },
        ],
      });
      return dlg;
    });
  }

  /* ================= Triggers globales ================= */

  document.addEventListener("click", function (e) {
    var acc = e.target.closest("[data-tp-account]");
    if (acc) {
      e.preventDefault();
      var menu = document.getElementById("tp-user-menu");
      if (menu) menu.hidden = true;
      open(acc.getAttribute("data-tp-account-tab") || null);
      return;
    }
    var pass = e.target.closest("[data-tp-password]");
    if (pass) {
      e.preventDefault();
      var m = document.getElementById("tp-user-menu");
      if (m) m.hidden = true;
      openPassword();
    }
  });

  window.tpAccount = { open: open, openPassword: openPassword };
})();
