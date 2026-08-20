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
  var LANG_URL = "/ui/v2/api/languages";
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
      '<div data-acc-sms="app" style="margin-top:6px;">SMS en cola: <b>' + S.queue_count + "</b></div></div>" +
      // Acciones del gateway (paridad legacy: "Enviar SMS de prueba" y "Vaciar cola")
      (META.caps.edit_sms
        ? '<div class="tp-row" style="gap:8px;margin-top:12px;flex-wrap:wrap;">' +
          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-acc-sms-test>Enviar SMS de prueba</button>' +
          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-acc-sms-clear>Vaciar cola</button></div>'
        : "") +
      "</form>"
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
          // 'period' solo lo soportan algunos bloques (latest_events/tasks):
          // si viene null no se pinta el selector.
          var per = b.period == null ? "" :
            '<select class="tp-input tp-input--sm" data-no-ts="1" data-dash-period="' + esc(b.key) + '" style="margin-left:auto;width:104px;" onclick="event.preventDefault();event.stopPropagation();">' +
            ["day", "week", "month"].map(function (v) {
              var lbl = v === "day" ? "Día" : v === "week" ? "Semana" : "Mes";
              return '<option value="' + v + '"' + (b.period === v ? " selected" : "") + ">" + lbl + "</option>";
            }).join("") + "</select>";
          return '<label class="tp-acc-tile" style="display:flex;align-items:center;gap:8px;"><input type="checkbox" class="tp-choice-input" name="dashboard_blocks" value="' + esc(b.key) + '"' + (b.enabled ? " checked" : "") + "> <span>" + esc(b.label) + "</span>" + per + "</label>";
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
                  '<td class="tp-acc-crud__actions">' +
                  '<div class="tp-acc-crud__act">' +
                  '<button type="button" class="tp-icon-btn" data-acc-edit title="Editar" aria-label="Editar"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>' +
                  '<button type="button" class="tp-icon-btn tp-icon-btn--danger" data-acc-del title="Eliminar" aria-label="Eliminar"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg></button>' +
                  "</div></td></tr>"
                ).replace("<tr>", '<tr data-acc-item="' + esc(JSON.stringify(r)) + '">');
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

  /* Catálogos para el form de grupo (vehículos y reenvíos), cacheados. */
  var GROUP_CAT = null;

  function loadGroupCatalogs() {
    if (GROUP_CAT) return Promise.resolve(GROUP_CAT);

    return Promise.all([
      tpModal.fetchJSON(API + "/devices?limit=1000"),
      tpModal.fetchJSON(API + "/forwards"),
    ]).then(function (res) {
      // El feed de vehículos devuelve la lista en `data` (no en `items`).
      var devs = (res[0].data && (res[0].data.data || res[0].data.items || [])) || [];
      var fwds = (res[1].ok && res[1].data && (res[1].data.items || [])) || [];
      GROUP_CAT = {
        devices: devs.map(function (d) { return { id: d.id, name: d.name || "#" + d.id }; }),
        // Si el usuario no tiene permiso de reenvíos, el endpoint responde 403
        // y la lista queda vacía: el bloque no se pinta.
        forwards: fwds.map(function (f) { return { id: f.id, title: f.title || "#" + f.id }; }),
      };
      return GROUP_CAT;
    });
  }

  function checkListHtml(attr, items, labelKey, checked) {
    if (!items.length) return "";
    return (
      '<div class="tp-rgroup-list" style="max-height:190px;">' +
      items
        .map(function (i) {
          return (
            '<label class="tp-rgroup-item"><input type="checkbox" ' + attr + '="' + i.id + '"' +
            (checked[i.id] ? " checked" : "") + "><span>" + esc(i[labelKey]) + "</span></label>"
          );
        })
        .join("") +
      "</div>"
    );
  }

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

        // Vehículos y reenvíos del grupo: el backend ya los sincroniza
        // (syncGroupDevices / syncGroupForwards); faltaba ofrecerlos aquí.
        Promise.all([
          loadGroupCatalogs(),
          isEdit
            ? tpModal.fetchJSON(API + "/device-groups/" + row.id + "/items").then(function (r) {
                return (r.data && r.data.ok) ? r.data : { device_ids: [], forward_ids: [] };
              })
            : Promise.resolve({ device_ids: [], forward_ids: [] }),
        ]).then(function (out) {
          var cat = out[0];
          var cur = out[1];
          var devChecked = {};
          (cur.device_ids || []).forEach(function (id) { devChecked[id] = true; });
          var fwdChecked = {};
          (cur.forward_ids || []).forEach(function (id) { fwdChecked[id] = true; });

          var body =
            '<div class="tp-form">' +
            field("Título", '<input type="text" name="g_title" value="' + esc(row ? row.title : "") + '" data-tp-field>', true) +
            field("Vehículos", checkListHtml("data-g-dev", cat.devices, "name", devChecked)) +
            (cat.forwards.length ? field("Reenvíos", checkListHtml("data-g-fwd", cat.forwards, "title", fwdChecked)) : "") +
            "</div>";

          crudDialog(
            isEdit ? "Editar grupo" : "Nuevo grupo",
            body,
            function (d) {
              var title = d.querySelector('[name="g_title"]').value.trim();
              if (!title) { tpModal.toast("El título es obligatorio.", "warning"); return; }

              var devices = [];
              d.querySelectorAll("[data-g-dev]:checked").forEach(function (c) {
                devices.push(parseInt(c.getAttribute("data-g-dev"), 10));
              });
              var forwards = [];
              d.querySelectorAll("[data-g-fwd]:checked").forEach(function (c) {
                forwards.push(parseInt(c.getAttribute("data-g-fwd"), 10));
              });

              var payload = { title: title, devices: devices };
              // Solo se manda si el bloque se pintó: si el usuario no ve
              // reenvíos, no debe borrar los que el grupo ya tuviera.
              if (cat.forwards.length) payload.forwards = forwards;

              crudSave(d, isEdit ? API + "/device-groups/" + row.id : API + "/device-groups", isEdit ? "PUT" : "POST", payload, onSaved);
            }
          );
        });
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
  // Fila de estado en el form: nombre + label corto (badge) + color.
  function stateRow(s) {
    s = s || {};
    var id = s.id || "";
    var color = /^#[0-9a-fA-F]{6}/.test(s.color || "") ? s.color : "#3b82f6";
    return (
      '<div class="tp-acc-strow" data-state-row' + (id ? ' data-state-id="' + id + '"' : "") + ">" +
      '<input type="text" class="tp-input" data-st-name value="' + esc(s.name || "") + '" placeholder="Estado (ej. Cargado)" style="flex:1;min-width:0;">' +
      '<input type="text" class="tp-input" data-st-label value="' + esc(s.label || "") + '" placeholder="Label" maxlength="24" style="width:84px;flex:0 0 auto;">' +
      '<input type="color" data-st-color value="' + esc(color) + '" title="Color del estado" style="width:40px;height:34px;flex:0 0 auto;padding:2px;border:1px solid var(--border-default,#e2e8f0);border-radius:8px;cursor:pointer;">' +
      '<button type="button" class="tp-icon-btn tp-icon-btn--danger" data-rm-state title="Quitar">×</button></div>'
    );
  }

  function wireVehicleTypes(panel) {
    wireCrud(panel, {
      listUrl: API + "/vehicle-types",
      itemUrl: function (id) { return API + "/vehicle-types/" + id; },
      cols: ["Nombre", "Estados"],
      row: function (r) {
        var st = (r.states || []).map(function (s) {
          var c = /^#[0-9a-fA-F]{6}/.test(s.color || "") ? s.color : "#64748b";
          return '<span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700;color:#fff;background:' + esc(c) + ';margin:1px 4px 1px 0;" title="' + esc(s.name) + '">' + esc(s.label || s.name) + "</span>";
        }).join("");
        return "<td>" + esc(r.name) + "</td><td>" + (st || "–") + "</td>";
      },
      delTitle: "Eliminar tipo de vehículo",
      delMsg: "¿Eliminar este tipo y sus estados?",
      form: function (row, onSaved) {
        var isEdit = !!(row && row.id);
        var states = (row && row.states) || [];
        var stateRows = states.map(stateRow).join("");
        crudDialog(
          isEdit ? "Editar tipo de vehículo" : "Nuevo tipo de vehículo",
          '<div class="tp-form">' +
            field("Nombre", '<input type="text" name="vt_name" value="' + esc(row ? row.name : "") + '" data-tp-field>', true) +
            '<div class="tp-form__field"><span class="tp-form__label">Estados</span>' +
            '<small class="tp-form__hint" style="display:block;margin:-2px 0 6px;">Nombre · label corto (badge) · color</small>' +
            '<div data-states-wrap>' + stateRows + "</div>" +
            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-add-state style="margin-top:6px;">+ Añadir estado</button>' +
            "</div></div>",
          function (d) {
            var name = d.querySelector('[name="vt_name"]').value.trim();
            if (!name) { tpModal.toast("El nombre es obligatorio.", "warning"); return; }
            var payload = { name: name, edit_state: {}, add_state: [] };
            Array.prototype.forEach.call(d.querySelectorAll("[data-state-row]"), function (rowEl) {
              var nm = (rowEl.querySelector("[data-st-name]").value || "").trim();
              if (!nm) return;
              var st = {
                name: nm,
                label: (rowEl.querySelector("[data-st-label]").value || "").trim(),
                color: rowEl.querySelector("[data-st-color]").value || "",
              };
              var sid = rowEl.getAttribute("data-state-id");
              if (sid) payload.edit_state[sid] = st;
              else payload.add_state.push(st);
            });
            crudSave(d, isEdit ? API + "/vehicle-types/" + row.id : API + "/vehicle-types", isEdit ? "PUT" : "POST", payload, onSaved);
          },
          function (d) {
            var wrap = d.querySelector("[data-states-wrap]");
            d.querySelector("[data-add-state]").addEventListener("click", function () {
              var div = document.createElement("div");
              div.innerHTML = stateRow({});
              wrap.appendChild(div.firstChild);
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
  // La COLUMNA de protocolo va tras el permiso device.protocol, como en
  // CustomEvents/table.blade del legacy. El CAMPO del formulario no: ahi el
  // legacy lo pide siempre (es obligatorio para crear el evento).
  function ceShowProtocol() {
    return !CE_META || CE_META.can_protocol !== false;
  }
  function ceCols() {
    return ceShowProtocol() ? ["Mensaje", "Protocolo", "Condiciones"] : ["Mensaje", "Condiciones"];
  }
  function tabEvents() {
    return crudPanel("events", "Añadir evento", ceCols());
  }
  function wireEvents(panel) {
    tpModal.fetchJSON(API + "/custom-events/meta", { method: "GET" }).then(function (res) {
      CE_META = res.ok && res.data && res.data.ok ? res.data : { protocols: [], types: [] };
    });
    wireCrud(panel, {
      listUrl: API + "/custom-events",
      itemUrl: function (id) { return API + "/custom-events/" + id; },
      cols: ceCols(),
      row: function (r) {
        return (
          "<td>" + esc(r.message) + "</td>" +
          (ceShowProtocol() ? "<td>" + esc(r.protocol) + "</td>" : "") +
          "<td>" + (r.conditions ? r.conditions.length : 0) + "</td>"
        );
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

  /* ========= Tab: Categorías de medios (addon media_categories) ========= */

  function tabMediaCats() {
    return (
      '<div class="tp-acc-crud" data-acc-mcats>' +
      '<div class="tp-row" style="justify-content:flex-end;margin-bottom:8px;">' +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-mc-add>＋ Nueva categoría</button></div>' +
      '<div data-mc-list><div class="tp-text-muted tp-text-sm">Cargando…</div></div></div>'
    );
  }
  function wireMediaCats(panel) {
    var listEl = panel.querySelector("[data-mc-list]");
    function load() {
      tpModal.fetchJSON(BASE + "/media-categories").then(function (res) {
        var items = (res.data && res.data.items) || [];
        listEl.innerHTML = items.length
          ? items
              .map(function (i) {
                return (
                  '<div class="tp-acc-row"><span>' + esc(i.title) + "</span>" +
                  '<span class="tp-row" style="gap:6px;">' +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-mc-edit="' + i.id + '" data-title="' + esc(i.title) + '">Editar</button>' +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-mc-del="' + i.id + '" style="color:#ef4444;">Eliminar</button>' +
                  "</span></div>"
                );
              })
              .join("")
          : '<div class="tp-text-muted tp-text-sm">Sin categorías.</div>';
      });
    }
    function form(id, title) {
      tpModal.open({
        title: id ? "Editar categoría" : "Nueva categoría",
        size: "sm",
        body:
          '<div class="tp-form"><div class="tp-form__field"><span class="tp-form__label">Título</span>' +
          '<input type="text" data-mc-title value="' + esc(title || "") + '" data-tp-field></div></div>',
        buttons: [
          { label: "Cancelar", variant: "ghost", close: true },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (d) {
              var v = d.querySelector("[data-mc-title]").value.trim();
              if (!v) {
                tpModal.toast("El título es obligatorio.", "warning");
                return false;
              }
              tpModal
                .fetchJSON(BASE + "/media-categories" + (id ? "/" + id : ""), {
                  method: id ? "PUT" : "POST",
                  json: { title: v },
                })
                .then(function (res) {
                  if (res.ok && res.data && res.data.ok) {
                    tpModal.toast("Guardado.", "success");
                    tpModal.close(d);
                    load();
                  } else {
                    var errs = (res.data && res.data.errors) || {};
                    var first = Object.keys(errs)[0];
                    tpModal.toast(first ? [].concat(errs[first])[0] : "No se pudo guardar.", "error");
                  }
                });
              return false;
            },
          },
        ],
      });
    }
    panel.querySelector("[data-mc-add]").addEventListener("click", function () {
      form(null, "");
    });
    listEl.addEventListener("click", function (e) {
      var ed = e.target.closest("[data-mc-edit]");
      if (ed) return form(ed.getAttribute("data-mc-edit"), ed.getAttribute("data-title"));
      var del = e.target.closest("[data-mc-del]");
      if (del)
        tpModal
          .confirm({ title: "Eliminar categoría", message: "¿Eliminar esta categoría?", danger: true, confirmLabel: "Eliminar" })
          .then(function (ok) {
            if (!ok) return;
            tpModal
              .fetchJSON(BASE + "/media-categories/" + del.getAttribute("data-mc-del"), { method: "DELETE" })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Eliminada.", "success");
                  load();
                } else tpModal.toast("No se pudo eliminar.", "error");
              });
          });
    });
    load();
  }

  /* ====== Tab: Credenciales secundarias (auth.secondary_credentials) ====== */

  function tabSecondary() {
    return (
      '<div class="tp-acc-crud" data-acc-sec>' +
      '<div class="tp-text-muted tp-text-sm" style="margin-bottom:8px;">Logins adicionales para tu cuenta (opcionalmente de solo lectura).</div>' +
      '<div class="tp-row" style="justify-content:flex-end;margin-bottom:8px;">' +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-sec-add>＋ Nueva credencial</button></div>' +
      '<div data-sec-list><div class="tp-text-muted tp-text-sm">Cargando…</div></div></div>'
    );
  }
  function wireSecondary(panel) {
    var listEl = panel.querySelector("[data-sec-list]");
    function load() {
      tpModal.fetchJSON(BASE + "/secondary").then(function (res) {
        var items = (res.data && res.data.items) || [];
        listEl.innerHTML = items.length
          ? items
              .map(function (i) {
                return (
                  '<div class="tp-acc-row"><span>' + esc(i.email) +
                  (i.readonly ? ' <span class="tp-text-xs tp-text-muted">(solo lectura)</span>' : "") + "</span>" +
                  '<span class="tp-row" style="gap:6px;">' +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sec-edit="' + i.id + '" data-email="' + esc(i.email) + '" data-ro="' + (i.readonly ? 1 : 0) + '">Editar</button>' +
                  '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sec-del="' + i.id + '" style="color:#ef4444;">Eliminar</button>' +
                  "</span></div>"
                );
              })
              .join("")
          : '<div class="tp-text-muted tp-text-sm">Sin credenciales secundarias.</div>';
      });
    }
    function form(id, email, ro) {
      var isEdit = !!id;
      tpModal.open({
        title: isEdit ? "Editar credencial" : "Nueva credencial",
        size: "sm",
        body:
          '<div class="tp-form">' +
          '<div class="tp-form__field"><span class="tp-form__label">Email</span>' +
          '<input type="email" data-sec-email value="' + esc(email || "") + '" data-tp-field></div>' +
          '<label class="tp-checkbox" style="margin:6px 0;"><input type="checkbox" data-sec-ro' + (ro ? " checked" : "") + "> <span>Solo lectura</span></label>" +
          '<label class="tp-checkbox" style="margin:6px 0;"><input type="checkbox" data-sec-gen' + (isEdit ? "" : " checked") + "> <span>Generar contraseña y enviarla por email</span></label>" +
          '<div class="tp-form__field" data-sec-pwrap hidden><span class="tp-form__label">Contraseña</span>' +
          '<input type="password" data-sec-pass autocomplete="new-password" data-tp-field></div>' +
          (isEdit ? "" : '<label class="tp-checkbox"><input type="checkbox" data-sec-notify checked> <span>Avisar por email de la cuenta creada</span></label>') +
          "</div>",
        buttons: [
          { label: "Cancelar", variant: "ghost", close: true },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (d) {
              var payload = {
                email: d.querySelector("[data-sec-email]").value.trim(),
                readonly: d.querySelector("[data-sec-ro]").checked ? 1 : 0,
                password_generate: d.querySelector("[data-sec-gen]").checked ? 1 : 0,
              };
              var pass = d.querySelector("[data-sec-pass]");
              if (pass && pass.value) {
                payload.password = pass.value;
                payload.password_confirmation = pass.value;
              }
              var nt = d.querySelector("[data-sec-notify]");
              if (nt) payload.account_created = nt.checked ? 1 : 0;
              tpModal
                .fetchJSON(BASE + "/secondary" + (id ? "/" + id : ""), { method: id ? "PUT" : "POST", json: payload })
                .then(function (res) {
                  if (res.ok && res.data && res.data.ok) {
                    tpModal.toast("Guardado.", "success");
                    tpModal.close(d);
                    load();
                    return;
                  }
                  var errs = (res.data && res.data.errors) || {};
                  var first = Object.keys(errs)[0];
                  tpModal.toast(first ? [].concat(errs[first])[0] : "No se pudo guardar.", "error");
                });
              return false;
            },
          },
        ],
        onMount: function (d) {
          var gen = d.querySelector("[data-sec-gen]");
          var wrap = d.querySelector("[data-sec-pwrap]");
          var sync = function () {
            wrap.hidden = gen.checked;
          };
          gen.addEventListener("change", sync);
          sync();
        },
      });
    }
    panel.querySelector("[data-sec-add]").addEventListener("click", function () {
      form(null, "", false);
    });
    listEl.addEventListener("click", function (e) {
      var ed = e.target.closest("[data-sec-edit]");
      if (ed) return form(ed.getAttribute("data-sec-edit"), ed.getAttribute("data-email"), ed.getAttribute("data-ro") === "1");
      var del = e.target.closest("[data-sec-del]");
      if (del)
        tpModal
          .confirm({ title: "Eliminar credencial", message: "¿Eliminar esta credencial secundaria?", danger: true, confirmLabel: "Eliminar" })
          .then(function (ok) {
            if (!ok) return;
            tpModal
              .fetchJSON(BASE + "/secondary/" + del.getAttribute("data-sec-del"), { method: "DELETE" })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Eliminada.", "success");
                  load();
                } else tpModal.toast("No se pudo eliminar.", "error");
              });
          });
    });
    load();
  }

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
    { key: "media_cats", label: "Categorías de medios", build: tabMediaCats, wire: wireMediaCats, cap: "view_media_cats" },
    { key: "secondary", label: "Credenciales secundarias", build: tabSecondary, wire: wireSecondary, cap: "view_secondary" },
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
    // --- Enviar SMS de prueba / Vaciar cola (paridad legacy) ---
    var testBtn = panel.querySelector("[data-acc-sms-test]");
    if (testBtn)
      testBtn.addEventListener("click", function () {
        var form = panel.querySelector(".tp-acc-form");
        var cur = {};
        Array.prototype.forEach.call(form.querySelectorAll("input,select,textarea"), function (el) {
          if (el.name) cur[el.name] = el.type === "checkbox" ? (el.checked ? 1 : 0) : el.value;
        });
        tpModal.open({
          title: "Enviar SMS de prueba",
          size: "sm",
          body:
            '<div class="tp-form"><div class="tp-form__field"><span class="tp-form__label">Teléfono</span>' +
            '<input type="text" data-tst-phone placeholder="+51..." data-tp-field></div>' +
            '<div class="tp-form__field"><span class="tp-form__label">Mensaje</span>' +
            '<textarea data-tst-msg rows="2" data-tp-field>Test</textarea></div></div>',
          buttons: [
            { label: "Cancelar", variant: "ghost", close: true },
            {
              label: "Enviar",
              variant: "primary",
              close: false,
              onClick: function (d) {
                var payload = Object.assign({}, cur, {
                  mobile_phone: d.querySelector("[data-tst-phone]").value.trim(),
                  message: d.querySelector("[data-tst-msg]").value,
                });
                tpModal.setBusy(d, true);
                tpModal.fetchJSON(BASE + "/sms/test", { method: "POST", json: payload }).then(function (res) {
                  tpModal.setBusy(d, false);
                  if (res.ok && res.data && res.data.ok) {
                    tpModal.toast("SMS de prueba enviado.", "success");
                    tpModal.close(d);
                    return;
                  }
                  var errs = (res.data && res.data.errors) || {};
                  var warn = (res.data && res.data.warnings) || [];
                  var first = Object.keys(errs)[0];
                  tpModal.toast(
                    warn.length ? String(warn[0]) : first ? [].concat(errs[first])[0] : "No se pudo enviar.",
                    "error",
                  );
                }).catch(function () { tpModal.setBusy(d, false); tpModal.toast("Error de red.", "error"); });
                return false;
              },
            },
          ],
        });
      });
    var clearBtn = panel.querySelector("[data-acc-sms-clear]");
    if (clearBtn)
      clearBtn.addEventListener("click", function () {
        tpModal.confirm({ title: "Vaciar cola", message: "¿Eliminar los SMS pendientes en cola?", danger: true, confirmLabel: "Vaciar" })
          .then(function (ok) {
            if (!ok) return;
            tpModal.fetchJSON(BASE + "/sms/queue", { method: "POST", json: {} }).then(function (res) {
              if (res.ok && res.data && res.data.ok) tpModal.toast("Cola vaciada.", "success");
              else tpModal.toast("No se pudo vaciar.", "error");
            });
          });
      });

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
    else if (tab.key === "dashboard") {
      var opts = {};
      Array.prototype.forEach.call(document.querySelectorAll("[data-dash-period]"), function (sel) {
        opts[sel.getAttribute("data-dash-period")] = { period: sel.value };
      });
      payload = { dashboard: { enabled: flat.dashboard_enabled, blocks: flat.dashboard_blocks || [], options: opts } };
    }
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

  /* ===== Selector de idioma (paridad LanguageController) ===== */
  function openLanguage() {
    if (!window.tpModal) return;

    tpModal.open({
      title: "Idioma",
      size: "sm",
      body: '<div data-lang-list><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      onMount: function (dialog) {
        var host = dialog.querySelector("[data-lang-list]");

        tpModal.fetchJSON(LANG_URL).then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            host.innerHTML = '<div class="tp-text-muted tp-text-sm">No se pudo cargar.</div>';
            return;
          }
          var cur = res.data.current;
          host.innerHTML =
            '<div class="tp-lang-grid">' +
            (res.data.items || [])
              .map(function (l) {
                return (
                  '<button type="button" class="tp-lang-item' + (l.key === cur ? " is-active" : "") +
                  '" data-lang="' + esc(l.key) + '">' +
                  (l.flag ? '<img src="' + esc(l.flag) + '" alt="" loading="lazy">' : "") +
                  "<span>" + esc(l.title) + "</span></button>"
                );
              })
              .join("") +
            "</div>";
        });

        host.addEventListener("click", function (e) {
          var b = e.target.closest("[data-lang]");
          if (!b) return;
          tpModal.setBusy(dialog, true);
          tpModal
            .fetchJSON(LANG_URL, { method: "POST", json: { lang: b.getAttribute("data-lang") } })
            .then(function (res) {
              if (res.ok && res.data && res.data.ok) {
                // El idioma se aplica al renderizar: se recarga la página.
                location.reload();
                return;
              }
              tpModal.setBusy(dialog, false);
              tpModal.toast("No se pudo cambiar el idioma.", "error");
            });
        });
      },
    });
  }

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
      return;
    }
    var lang = e.target.closest("[data-tp-language]");
    if (lang) {
      e.preventDefault();
      var ml = document.getElementById("tp-user-menu");
      if (ml) ml.hidden = true;
      openLanguage();
    }
  });

  window.tpAccount = { open: open, openPassword: openPassword, openLanguage: openLanguage };
})();

/* =====================================================
   Confirmar email (código único) — paridad EmailConfirmation legacy.
   Ítem del menú de usuario [data-tp-email-confirm] (solo si email_active=0).
   Modal: código de 5 dígitos + "Reenviar código" (rate limit 60 s server-side).
   ===================================================== */
(function () {
  "use strict";

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-tp-email-confirm]");
    if (!btn) return;
    e.preventDefault();
    openEmailConfirm(btn);
  });

  function openEmailConfirm(btn) {
    var confirmUrl = btn.getAttribute("data-confirm-url");
    var resendUrl = btn.getAttribute("data-resend-url");
    var email =
      (window.Talentus && Talentus.user && Talentus.user.email) || "";

    var dlg = tpModal.open({
      title: "Confirmar email",
      sub: email
        ? "Ingresa el código que enviamos a " + email
        : "Ingresa el código que enviamos a tu correo",
      size: "sm",
      body:
        '<div class="tp-field"><label class="tp-field__label">Código</label>' +
        '<input class="tp-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="•••••" style="text-align:center;font-size:22px;letter-spacing:.35em;font-weight:700;">' +
        "</div>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-ec-resend style="width:100%;">Reenviar código</button>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Confirmar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            var code = (d.querySelector('[name="code"]').value || "").trim();
            if (!code) {
              tpModal.toast("Ingresa el código.", "warning");
              return false;
            }
            tpModal.setBusy(d, true);
            tpModal
              .fetchJSON(confirmUrl, { method: "POST", json: { code: code } })
              .then(function (res) {
                tpModal.setBusy(d, false);
                if (!res.ok || !res.data || !res.data.ok) {
                  var errs = (res.data && res.data.errors) || {};
                  tpModal.showFieldErrors(d, errs);
                  tpModal.toast(
                    errs.code || errs.id || "No se pudo confirmar.",
                    "error",
                  );
                  return;
                }
                tpModal.toast("Email confirmado.", "success");
                tpModal.close(d);
                btn.remove(); // quita el ítem (y su indicador) del menú
              })
              .catch(function () {
                tpModal.setBusy(d, false);
                tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
    });

    var resend = dlg.querySelector("[data-ec-resend]");
    if (resend)
      resend.addEventListener("click", function () {
        resend.disabled = true;
        tpModal
          .fetchJSON(resendUrl, { method: "POST", json: {} })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              var errs = (res.data && res.data.errors) || {};
              tpModal.toast(errs.id || "No se pudo reenviar.", "warning");
              resend.disabled = false;
              return;
            }
            tpModal.toast("Código enviado a tu correo.", "success");
            // rate limit legacy: 60 s entre reenvíos
            setTimeout(function () {
              resend.disabled = false;
            }, 60000);
          })
          .catch(function () {
            resend.disabled = false;
            tpModal.toast("Error de red.", "error");
          });
      });
  }
})();
