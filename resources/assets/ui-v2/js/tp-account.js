/* =====================================================
 * TalentusPro · tp-account — Ajustes de cuenta (modal con tabs)
 * -----------------------------------------------------
 * Paridad con el modal legacy MyAccountSettings, con el diseño v2.
 * Se abre desde el topbar (engranaje / menú de usuario, [data-tp-account]).
 * Tabs: Regional, Cliente, Contraseña, SMS gateway, Widgets, Mapa
 * (Cliente y SMS se muestran según permisos). Cada tab guarda por
 * separado contra su endpoint (no se pisan entre sí):
 *   api.account.locale / .client / .password / .sms / .widgets / .map
 * Depende de tpModal; tp-select y tp-datepicker realzan selects/fechas
 * inyectados vía su MutationObserver global.
 * ===================================================== */
(function () {
  "use strict";

  if (!window.tpModal) return;

  var BASE = "/ui/v2/api/account";
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

  function sel(name, list, selected, width) {
    return '<select class="tp-input" name="' + name + '"' + (width ? ' style="width:' + width + '"' : "") + ">" + options(list, selected) + "</select>";
  }

  function txt(name, value, type) {
    return '<input type="' + (type || "text") + '" name="' + name + '" value="' + esc(value) + '" data-tp-field>';
  }

  /* ================= Tab: Regional (locale) ================= */

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
      // Horario de verano (DST)
      '<div class="tp-col-2" style="border-top:1px solid var(--border-default,#e9eef5);margin-top:4px;padding-top:10px;">' +
      field("Horario de verano", sel("dst_type", LS.dst_types, D.type), false, false) +
      '<div data-acc-dst="automatic" style="display:none;margin-top:8px;">' +
      field("País", sel("dst_country_id", LS.dst_countries, D.country_id), false, false) +
      "</div>" +
      '<div data-acc-dst="exact" style="display:none;margin-top:8px;">' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Desde (mm-dd hh:ii)", txt("date_from", D.date_from)) +
      field("Hasta (mm-dd hh:ii)", txt("date_to", D.date_to)) +
      "</div></div>" +
      '<div data-acc-dst="other" style="display:none;margin-top:8px;">' +
      '<div style="display:grid;grid-template-columns:2fr 1fr 2fr 1fr;gap:8px;margin-bottom:8px;">' +
      sel("month_from", LS.months, D.month_from) + sel("week_pos_from", LS.week_pos, D.week_pos_from) +
      sel("week_day_from", LS.weekdays, D.week_day_from) + txt("time_from", D.time_from) +
      "</div>" +
      '<div style="display:grid;grid-template-columns:2fr 1fr 2fr 1fr;gap:8px;">' +
      sel("month_to", LS.months, D.month_to) + sel("week_pos_to", LS.week_pos, D.week_pos_to) +
      sel("week_day_to", LS.weekdays, D.week_day_to) + txt("time_to", D.time_to) +
      "</div></div>" +
      "</div>" +
      "</div></form>"
    );
  }

  /* ================= Tab: Cliente / Empresa ================= */

  function tabClient() {
    var C = META.client, K = META.company;
    var ro = !META.caps.edit_client;
    var dis = ro ? " disabled" : "";
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="client">' +
      '<div class="tp-col-2" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--fg-muted,#94a3b8);margin-bottom:2px;">Cliente</div>' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Nombre", '<input type="text" name="client[first_name]" value="' + esc(C.first_name) + '"' + dis + " data-tp-field>") +
      field("Apellido", '<input type="text" name="client[last_name]" value="' + esc(C.last_name) + '"' + dis + " data-tp-field>") +
      field("Código personal", '<input type="text" name="client[personal_code]" value="' + esc(C.personal_code) + '"' + dis + " data-tp-field>") +
      field("Fecha de nacimiento", '<input type="date" name="client[birth_date]" value="' + esc(C.birth_date) + '"' + dis + ">") +
      field("Dirección", '<input type="text" name="client[address]" value="' + esc(C.address) + '"' + dis + " data-tp-field>", false, true) +
      "</div>" +
      '<div class="tp-col-2" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--fg-muted,#94a3b8);margin:12px 0 2px;">Empresa</div>' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Nombre", '<input type="text" name="company[name]" value="' + esc(K.name) + '"' + dis + " data-tp-field>", false, true) +
      field("Código de registro", '<input type="text" name="company[registration_code]" value="' + esc(K.registration_code) + '"' + dis + " data-tp-field>") +
      field("N° de IVA / RUC", '<input type="text" name="company[vat_number]" value="' + esc(K.vat_number) + '"' + dis + " data-tp-field>") +
      field("Dirección", '<input type="text" name="company[address]" value="' + esc(K.address) + '"' + dis + " data-tp-field>", false, true) +
      "</div></form>"
    );
  }

  /* ================= Tab: Contraseña ================= */

  function tabPassword() {
    var reqCur = META.caps.require_current;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="password" autocomplete="off">' +
      '<div class="tp-form__grid">' +
      (reqCur
        ? field("Contraseña actual", '<input type="password" name="current_password" autocomplete="current-password" data-tp-field>', true)
        : "") +
      field("Nueva contraseña", '<input type="password" name="password" autocomplete="new-password" data-tp-field>', true) +
      field("Confirmar contraseña", '<input type="password" name="password_confirmation" autocomplete="new-password" data-tp-field>', true) +
      "</div></form>"
    );
  }

  /* ================= Tab: SMS gateway ================= */

  function tabSms() {
    var S = META.sms, LS = META.lists;
    var ro = !META.caps.edit_sms;
    var dis = ro ? " disabled" : "";
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="sms">' +
      '<label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:12px;">' +
      '<input type="checkbox" class="tp-choice-input" name="sms_gateway" value="1"' + (S.enabled ? " checked" : "") + dis + "> Habilitar SMS gateway</label>" +
      '<div class="tp-form__grid tp-form__grid--2">' +
      field("Método de solicitud", '<select class="tp-input" name="request_method"' + dis + ' data-acc-sms-method>' + options(LS.request_methods, S.request_method) + "</select>") +
      '<div data-acc-sms="post">' + field("Codificación", '<select class="tp-input" name="encoding"' + dis + ">" + options(LS.encodings, S.encoding) + "</select>") + "</div>" +
      '<div data-acc-sms="get post">' + field("Autenticación", '<select class="tp-input" name="authentication"' + dis + " data-acc-sms-auth>" + options(LS.authentications, S.authentication) + "</select>") + "</div>" +
      '<div data-acc-sms="get post" data-acc-sms-authblock>' + field("Usuario", '<input type="text" name="username" value="' + esc(S.username) + '"' + dis + " data-tp-field>") + "</div>" +
      '<div data-acc-sms="get post" data-acc-sms-authblock>' + field("Contraseña", '<input type="password" name="password" autocomplete="new-password"' + dis + " data-tp-field>") + "</div>" +
      '<div class="tp-col-2" data-acc-sms="get post">' + field("Cabeceras", '<textarea name="custom_headers" rows="2"' + dis + " data-tp-field>" + esc(S.custom_headers) + "</textarea>", false, false) +
      '<small class="tp-form__hint">Lista separada por punto y coma (p. ej. Accept: text/plain; Accept-Language: en-US;)</small></div>' +
      '<div class="tp-col-2" data-acc-sms="get post">' + field("URL del gateway", '<textarea name="sms_gateway_url" rows="2"' + dis + " data-tp-field>" + esc(S.url) + "</textarea>", false, false) + "</div>" +
      // Plivo
      '<div data-acc-sms="plivo">' + field("Auth ID", '<input type="text" name="auth_id" value="' + esc(S.auth_id) + '"' + dis + " data-tp-field>") + "</div>" +
      '<div data-acc-sms="plivo">' + field("Auth Token", '<input type="text" name="auth_token" value="' + esc(S.auth_token) + '"' + dis + " data-tp-field>") + "</div>" +
      '<div data-acc-sms="plivo">' + field("Teléfono remitente", '<input type="text" name="senders_phone" value="' + esc(S.senders_phone) + '"' + dis + " data-tp-field>") + "</div>" +
      "</div>" +
      '<div class="tp-col-2" style="margin-top:10px;padding:10px 12px;background:var(--brand-bg-subtle,#eef3fb);border-radius:8px;font-size:12px;color:var(--fg-secondary,#475569);">' +
      "Variables: %NUMBER%, %MESSAGE%. Ejemplo: http://SMS_GATEWAY/sendsms.php?username=USER&password=PASS&number=%NUMBER%&message=%MESSAGE%" +
      '<div data-acc-sms="app" style="margin-top:6px;">SMS en cola: <b>' + S.queue_count + "</b></div></div>" +
      "</form>"
    );
  }

  /* ================= Tab: Widgets ================= */

  function tabWidgets() {
    var W = META.widgets;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="widgets">' +
      '<label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:10px;">' +
      '<input type="checkbox" class="tp-choice-input" name="widgets_default" value="1"' + (W.default ? " checked" : "") + " data-acc-wdefault> Widgets por defecto</label>" +
      '<div data-acc-wbody' + (W.default ? ' style="opacity:.5;pointer-events:none;"' : "") + ">" +
      '<label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:12px;">' +
      '<input type="checkbox" class="tp-choice-input" name="widgets_status" value="1"' + (W.status ? " checked" : "") + "> Habilitar widgets</label>" +
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">' +
      W.items
        .map(function (it) {
          return (
            '<label style="display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid var(--border-default,#e2e8f0);border-radius:10px;cursor:pointer;font-size:13px;font-weight:500;">' +
            '<input type="checkbox" class="tp-choice-input" name="widgets_list" value="' + esc(it.key) + '"' + (it.on ? " checked" : "") + "> " + esc(it.label) + "</label>"
          );
        })
        .join("") +
      "</div></div></form>"
    );
  }

  /* ================= Tab: Mapa ================= */

  function tabMap() {
    var M = META.map;
    return (
      '<form class="tp-form tp-acc-form" data-acc-tab="map">' +
      '<div class="tp-form__grid">' +
      field(
        "Capa base por defecto",
        '<select class="tp-input" name="map_id">' +
          M.available
            .map(function (m) {
              return '<option value="' + m.id + '"' + (M.map_id === m.id ? " selected" : "") + ">" + esc(m.name) + "</option>";
            })
            .join("") +
          "</select>",
        false,
      ) +
      '<small class="tp-form__hint">Capa que se carga por defecto al abrir el mapa de tu cuenta.</small>' +
      "</div></form>"
    );
  }

  /* ================= Modal ================= */

  var TABS = [
    { key: "locale", label: "Regional", build: tabLocale, save: "locale" },
    { key: "client", label: "Cliente", build: tabClient, save: "client", cap: "view_client" },
    { key: "password", label: "Contraseña", build: tabPassword, save: "password" },
    { key: "sms", label: "SMS gateway", build: tabSms, save: "sms", cap: "view_sms" },
    { key: "widgets", label: "Widgets", build: tabWidgets, save: "widgets" },
    { key: "map", label: "Mapa", build: tabMap, save: "map" },
  ];

  function visibleTabs() {
    return TABS.filter(function (t) {
      return !t.cap || META.caps[t.cap];
    });
  }

  function open() {
    var dlg = tpModal.open({
      title: "Ajustes de cuenta",
      size: "lg",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:32px;">Cargando…</div>',
      buttons: [
        { label: "Cerrar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            saveActive(dialog);
            return false;
          },
        },
      ],
    });

    tpModal.fetchJSON(BASE + "/meta", { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        var b = dlg.querySelector(".tp-modal__body");
        if (b) b.innerHTML = '<div class="tp-text-center tp-text-error" style="padding:24px;">No se pudo cargar.</div>';
        return;
      }
      META = res.data;
      render(dlg);
    });
  }

  function render(dlg) {
    var tabs = visibleTabs();
    var body = dlg.querySelector(".tp-modal__body");
    body.innerHTML =
      '<div class="tp-acc">' +
      '<div class="tp-acc__tabs" role="tablist">' +
      tabs
        .map(function (t, i) {
          return (
            '<button type="button" class="tp-acc__tab' + (i === 0 ? " is-active" : "") + '" data-acc-go="' + t.key + '">' +
            esc(t.label) + "</button>"
          );
        })
        .join("") +
      "</div>" +
      '<div class="tp-acc__panel" data-acc-panel></div>' +
      "</div>";

    showTab(dlg, tabs[0].key);

    dlg.querySelector(".tp-acc__tabs").addEventListener("click", function (e) {
      var b = e.target.closest("[data-acc-go]");
      if (b) showTab(dlg, b.getAttribute("data-acc-go"));
    });
  }

  function showTab(dlg, key) {
    var tab = TABS.filter(function (t) {
      return t.key === key;
    })[0];
    if (!tab) return;
    dlg._accActive = tab;

    Array.prototype.forEach.call(dlg.querySelectorAll("[data-acc-go]"), function (b) {
      b.classList.toggle("is-active", b.getAttribute("data-acc-go") === key);
    });
    var panel = dlg.querySelector("[data-acc-panel]");
    panel.innerHTML = tab.build();

    if (key === "locale") wireDst(panel);
    if (key === "sms") wireSms(panel);
    if (key === "widgets") wireWidgets(panel);
  }

  /* ---- Interacciones por tab ---- */

  function wireDst(panel) {
    var sel = panel.querySelector('[name="dst_type"]');
    function sync() {
      var v = sel.value;
      Array.prototype.forEach.call(panel.querySelectorAll("[data-acc-dst]"), function (el) {
        el.style.display = el.getAttribute("data-acc-dst") === v ? "" : "none";
      });
    }
    sel.addEventListener("change", sync);
    sync();
  }

  function wireSms(panel) {
    var method = panel.querySelector("[data-acc-sms-method]");
    var auth = panel.querySelector("[data-acc-sms-auth]");
    function sync() {
      var v = method.value;
      var authOn = auth && String(auth.value) === "1";
      Array.prototype.forEach.call(panel.querySelectorAll("[data-acc-sms]"), function (el) {
        var forMethod = el.getAttribute("data-acc-sms").split(" ").indexOf(v) >= 0;
        // Los bloques de usuario/clave requieren además auth = sí
        var show = forMethod && (!el.hasAttribute("data-acc-sms-authblock") || authOn);
        el.style.display = show ? "" : "none";
      });
    }
    method.addEventListener("change", sync);
    if (auth) auth.addEventListener("change", sync);
    sync();
  }

  function wireWidgets(panel) {
    var def = panel.querySelector("[data-acc-wdefault]");
    var body = panel.querySelector("[data-acc-wbody]");
    def.addEventListener("change", function () {
      body.style.opacity = def.checked ? ".5" : "";
      body.style.pointerEvents = def.checked ? "none" : "";
    });
  }

  /* ---- Guardar tab activo ---- */

  function collect(form) {
    var data = {};
    Array.prototype.forEach.call(form.querySelectorAll("input, select, textarea"), function (el) {
      if (!el.name) return;
      if (el.type === "checkbox") {
        if (el.name === "widgets_list") {
          data.widgets_list = data.widgets_list || [];
          if (el.checked) data.widgets_list.push(el.value);
        } else {
          data[el.name] = el.checked ? 1 : 0;
        }
      } else {
        data[el.name] = el.value;
      }
    });
    return data;
  }

  function toNested(flat) {
    // Convierte client[first_name] → {client:{first_name}} para JSON
    var out = {};
    Object.keys(flat).forEach(function (k) {
      var m = k.match(/^(\w+)\[(\w+)\]$/);
      if (m) {
        out[m[1]] = out[m[1]] || {};
        out[m[1]][m[2]] = flat[k];
      } else {
        out[k] = flat[k];
      }
    });
    return out;
  }

  function saveActive(dlg) {
    var tab = dlg._accActive;
    if (!tab) return;
    var form = dlg.querySelector(".tp-acc-form");
    if (!form) return;

    var flat = collect(form);
    var payload;
    if (tab.key === "client") {
      payload = toNested(flat);
    } else if (tab.key === "widgets") {
      payload = { widgets: { default: flat.widgets_default, status: flat.widgets_status, list: flat.widgets_list || [] } };
    } else {
      payload = flat;
    }

    tpModal.setBusy(dlg, true);
    tpModal
      .fetchJSON(BASE + "/" + tab.save, { method: "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(dlg, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          tpModal.showFieldErrors(dlg, errors);
          var first = Object.keys(errors)[0];
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.", "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
          return;
        }
        tpModal.toast("Cambios guardados.", "success");
        // El mapa y el idioma requieren recargar para verse aplicados
        if (tab.key === "map") {
          setTimeout(function () {
            window.location.reload();
          }, 700);
        }
      })
      .catch(function () {
        tpModal.setBusy(dlg, false);
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ================= Trigger global ================= */

  document.addEventListener("click", function (e) {
    var trigger = e.target.closest("[data-tp-account]");
    if (!trigger) return;
    e.preventDefault();
    // Cierra el menú de usuario si estaba abierto
    var menu = document.getElementById("tp-user-menu");
    if (menu) menu.hidden = true;
    open();
  });
})();
