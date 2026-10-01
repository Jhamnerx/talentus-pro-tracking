/* =====================================================
 * TalentusPro · tp-sharing — Compartir vehículos por link público
 * -----------------------------------------------------
 * Global: window.tpSharing.openForm(id?, { deviceId?, onSaved? })
 *  - Modal crear/editar (estilo referencia): Activo, Expira por
 *    (Ninguna/Duración/Fecha con campos condicionales), Nombre,
 *    Descripción, Eliminar al expirar, caja de Devices agrupada
 *    (buscador + Seleccionar/Deseleccionar todo) y Email opcional.
 *  - La selección de devices viaja como secuencia del EntityLoader
 *    (selected_devices[]: "s;0;" + "id;1;<id>"…) — el backend v2
 *    delega en SharingService + SharingFormValidator legacy.
 *  - Si existe #tp-sharing-page monta además la página (tabla con
 *    búsqueda, filtro por device, paginación y kebab de acciones).
 * Depende de tpModal; usa tp-datepicker (input date) y tp-select.
 * El toggle del kebab (.tp-svc-kebab) lo aporta tp-services.js.
 * ===================================================== */
(function () {
  "use strict";

  var API = {
    list: "/ui/v2/api/sharing",
    meta: "/ui/v2/api/sharing/meta",
    store: "/ui/v2/api/sharing",
    item: "/ui/v2/api/sharing/{id}",
  };

  function esc(s) {
    return window.tpModal ? tpModal.escapeHtml(s) : String(s == null ? "" : s);
  }

  /* ================= Modal crear / editar ================= */

  function openForm(id, opts) {
    opts = opts || {};
    var isEdit = !!id;

    var dlg = tpModal.open({
      title: isEdit ? tpT('ui.edit_share') : tpT('ui.share_vehicles'),
      size: "lg",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('global.save'),
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            submitForm(dialog, id, opts);
            return false;
          },
        },
      ],
    });

    var metaUrl = API.meta + (isEdit ? "?id=" + id : "");
    tpModal.fetchJSON(metaUrl, { method: "GET" }).then(function (res) {
      var bodyEl = dlg.querySelector(".tp-modal__body");
      if (!bodyEl) return;
      if (!res.ok || !res.data || !res.data.ok) {
        bodyEl.innerHTML =
          '<div class="tp-text-center tp-text-error" style="padding:24px;">' + tpT('ui.form_load_failed') + '</div>';
        return;
      }
      bodyEl.innerHTML = buildFormHtml(res.data, opts);
      wireForm(dlg, res.data, opts);
    });

    return dlg;
  }

  function buildFormHtml(meta, opts) {
    var item = meta.item || {};
    var by = item.expiration_by || "none";

    var html =
      '<form class="tp-form tp-sharing-form">' +
      '<div class="tp-form__grid">' +
      // Activo
      '<label class="tp-form__field tp-form__field--checkbox">' +
      '<input type="checkbox" name="active" value="1"' + (item.active !== false ? " checked" : "") + ">" +
      '<span class="tp-form__label">' + tpT('attr.active') + '</span>' +
      "</label>" +
      // Expira por
      '<div class="tp-form__field">' +
      '<span class="tp-form__label">' + tpT('ui.expires_by') + '</span>' +
      '<div style="display:flex;gap:18px;align-items:center;">' +
      shxRadio("none", tpT('front.none'), by === "none") +
      shxRadio("duration", tpT('ui.duration'), by === "duration") +
      shxRadio("date", tpT('front.date'), by === "date") +
      "</div></div>" +
      // Duración (condicional)
      '<div data-shx-block="duration" style="display:none;">' +
      '<label class="tp-form__field">' +
      '<span class="tp-form__label">' + tpT('ui.duration') + '</span>' +
      '<select class="tp-input" style="width:100%" name="duration">' +
      (meta.durations || [])
        .map(function (d) {
          return '<option value="' + d.value + '">' + esc(d.label) + "</option>";
        })
        .join("") +
      "</select></label></div>" +
      // Fecha (condicional): fecha + hora
      '<div data-shx-block="date" style="display:none;">' +
      '<div class="tp-form__field">' +
      '<span class="tp-form__label">' + tpT('ui.expiry_date') + '</span>' +
      '<div style="display:flex;gap:8px;">' +
      '<input type="date" name="expiration_date" value="' + esc(item.expiration_date || "") + '" style="flex:1;" data-tp-field>' +
      '<input type="time" data-sh-exptime value="' + esc(item.expiration_time || "") + '" style="width:104px;">' +
      "</div></div></div>" +
      // Nombre
      '<label class="tp-form__field">' +
      '<span class="tp-form__label">' + tpT('ui.name') + '</span>' +
      '<input type="text" name="name" maxlength="120" value="' + esc(item.name || "") + '" data-tp-field>' +
      "</label>" +
      // Descripción
      '<label class="tp-form__field">' +
      '<span class="tp-form__label">' + tpT('attr.description') + '</span>' +
      '<textarea name="description" rows="2" maxlength="500" data-tp-field>' + esc(item.description || "") + "</textarea>" +
      "</label>" +
      // Eliminar al expirar
      '<label class="tp-form__field tp-form__field--checkbox">' +
      '<input type="checkbox" name="delete_after_expiration" value="1"' +
      (item.delete_after_expiration ? " checked" : "") + ">" +
      '<span class="tp-form__label">' + tpT('ui.delete_on_expiry') + '</span>' +
      "</label>" +
      // Devices
      '<div class="tp-form__field">' +
      '<span class="tp-form__label">' + tpT('ui.vehicles') + '</span>' +
      '<div class="tp-group-box" data-sh-devices>' +
      '<div class="tp-group-box__head">' +
      '<span class="tp-search">' +
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>' +
      '<input type="search" data-sh-search placeholder="' + tpT('front.search') + '" autocomplete="off">' +
      "</span>" +
      '<button type="button" class="tp-link-action" data-sh-all>' + tpT('ui.select_all_n') + '</button>' +
      '<span class="tp-group-box__sep">|</span>' +
      '<button type="button" class="tp-link-action" data-sh-none>Deseleccionar todo</button>' +
      "</div>" +
      '<div data-sh-list style="max-height:280px;overflow-y:auto;"></div>' +
      "</div></div>" +
      // Email
      '<div class="tp-form__field">' +
      '<span class="tp-form__label">Email</span>' +
      '<div class="tp-input-group">' +
      '<input type="checkbox" name="send_email" value="1" id="tp-sh-email-chk">' +
      '<input type="text" name="email" placeholder="usuario@ejemplo.com" data-enable="tp-sh-email-chk" disabled data-tp-field>' +
      "</div>" +
      '<small class="tp-form__hint">' + tpT('ui.several_emails_help') + '</small>' +
      "</div>" +
      // SMS: mismo par casilla + campo que el correo. Solo se pinta si el
      // usuario puede enviar SMS (meta.can_sms), como el legacy.
      (meta && meta.can_sms
        ? '<div class="tp-form__field">' +
          '<span class="tp-form__label">SMS</span>' +
          '<div class="tp-input-group">' +
          '<input type="checkbox" name="send_sms" value="1" id="tp-sh-sms-chk">' +
          '<input type="text" name="sms" placeholder="+51999999999" data-enable="tp-sh-sms-chk" disabled data-tp-field>' +
          "</div>" +
          '<small class="tp-form__hint">' + tpT('ui.several_numbers_help') + '</small>' +
          "</div>"
        : "") +
      "</div></form>";

    return html;
  }

  function shxRadio(value, label, checked) {
    return (
      '<label class="tp-choice" style="margin:0;">' +
      '<input type="radio" class="tp-choice-input" name="expiration_by" value="' + value + '"' +
      (checked ? " checked" : "") + ">" +
      "<span>" + label + "</span>" +
      "</label>"
    );
  }

  function wireForm(dlg, meta, opts) {
    var form = dlg.querySelector("form.tp-sharing-form");
    if (!form) return;

    var item = meta.item || {};

    // ── Expira por: bloques condicionales ──
    function syncExpiration() {
      var by = (form.querySelector('input[name="expiration_by"]:checked') || {}).value || "none";
      ["duration", "date"].forEach(function (k) {
        var block = form.querySelector('[data-shx-block="' + k + '"]');
        if (!block) return;
        var on = by === k;
        block.style.display = on ? "" : "none";
        Array.prototype.forEach.call(block.querySelectorAll("input, select"), function (i) {
          i.disabled = !on;
          // El botón de tp-select copia el disabled del select solo en
          // refresh — sin esto quedaba bloqueado al habilitar el bloque.
          if (i.tagName === "SELECT" && i._tpdd && window.tpSelect && tpSelect.refresh) {
            try {
              tpSelect.refresh(i);
            } catch (e) {}
          }
        });
      });
    }
    form.addEventListener("change", function (e) {
      if (e.target.name === "expiration_by") syncExpiration();
      if (e.target.id === "tp-sh-email-chk") {
        var email = form.querySelector('input[name="email"]');
        if (email) email.disabled = !e.target.checked;
      }
      if (e.target.id === "tp-sh-sms-chk") {
        var sms = form.querySelector('input[name="sms"]');
        if (sms) sms.disabled = !e.target.checked;
      }
    });
    syncExpiration();

    // ── Devices agrupados ──
    var selected = {};
    (item.device_ids || []).forEach(function (i) {
      selected[i] = true;
    });
    if (!item.id && opts.deviceId) selected[opts.deviceId] = true;
    form._shSelected = selected;

    var groups = {};
    (meta.groups || []).forEach(function (g) {
      groups[g.id] = g.title;
    });

    var byGroup = {};
    (meta.devices || []).forEach(function (d) {
      var gid = d.group_id || 0;
      (byGroup[gid] = byGroup[gid] || []).push(d);
    });

    var listEl = form.querySelector("[data-sh-list]");
    var html = "";
    Object.keys(byGroup).forEach(function (gid) {
      var title = groups[gid] || tpT('front.ungrouped');
      html +=
        '<div data-sh-group="' + gid + '" style="border-bottom:1px solid var(--border-default,#eef2f7);">' +
        '<div style="display:flex;align-items:center;gap:9px;padding:7px 4px;background:var(--surface-sunken,#f8fafc);border-radius:8px;">' +
        '<button type="button" data-sh-toggle style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--fg-muted,#94a3b8);width:20px;">▾</button>' +
        '<input type="checkbox" class="tp-choice-input" data-sh-gchk>' +
        '<span style="font-size:13px;font-weight:600;color:var(--fg-secondary,#475569);">' + esc(title) + "</span>" +
        '<span style="font-size:11px;color:var(--fg-muted,#94a3b8);">(' + byGroup[gid].length + ")</span>" +
        "</div>" +
        '<div data-sh-gbody style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;padding:4px 8px 8px 30px;">' +
        byGroup[gid]
          .map(function (d) {
            return (
              '<label class="tp-choice-row" data-sh-row data-name="' + esc(d.name.toLowerCase()) +
              '" style="display:flex;align-items:center;gap:9px;padding:4px 0;cursor:pointer;">' +
              '<input type="checkbox" class="tp-choice-input" data-sh-dev="' + d.id + '"' +
              (selected[d.id] ? " checked" : "") + ">" +
              '<span style="font-size:13px;">' + esc(d.name) + "</span>" +
              "</label>"
            );
          })
          .join("") +
        "</div></div>";
    });
    listEl.innerHTML = html || '<div class="tp-text-muted" style="padding:10px;font-size:13px;">' + tpT('ui.no_vehicles') + '</div>';

    syncGroupChecks();

    function syncGroupChecks() {
      Array.prototype.forEach.call(listEl.querySelectorAll("[data-sh-group]"), function (g) {
        var devs = g.querySelectorAll("[data-sh-dev]");
        var on = 0;
        Array.prototype.forEach.call(devs, function (c) {
          if (c.checked) on++;
        });
        var gchk = g.querySelector("[data-sh-gchk]");
        gchk.checked = on > 0 && on === devs.length;
        gchk.indeterminate = on > 0 && on < devs.length;
      });
    }

    listEl.addEventListener("change", function (e) {
      var dev = e.target.closest("[data-sh-dev]");
      if (dev) {
        var did = +dev.getAttribute("data-sh-dev");
        if (dev.checked) selected[did] = true;
        else delete selected[did];
        syncGroupChecks();
        return;
      }
      var gchk = e.target.closest("[data-sh-gchk]");
      if (gchk) {
        var g = gchk.closest("[data-sh-group]");
        Array.prototype.forEach.call(g.querySelectorAll("[data-sh-dev]"), function (c) {
          c.checked = gchk.checked;
          var cid = +c.getAttribute("data-sh-dev");
          if (gchk.checked) selected[cid] = true;
          else delete selected[cid];
        });
        syncGroupChecks();
      }
    });

    listEl.addEventListener("click", function (e) {
      var t = e.target.closest("[data-sh-toggle]");
      if (!t) return;
      var g = t.closest("[data-sh-group]");
      var body = g.querySelector("[data-sh-gbody]");
      var open = body.style.display !== "none";
      body.style.display = open ? "none" : "";
      t.textContent = open ? "▸" : "▾";
    });

    function setAll(on) {
      Array.prototype.forEach.call(listEl.querySelectorAll("[data-sh-dev]"), function (c) {
        c.checked = on;
        var cid = +c.getAttribute("data-sh-dev");
        if (on) selected[cid] = true;
        else delete selected[cid];
      });
      syncGroupChecks();
    }
    form.querySelector("[data-sh-all]").addEventListener("click", function () {
      setAll(true);
    });
    form.querySelector("[data-sh-none]").addEventListener("click", function () {
      setAll(false);
    });

    var searchEl = form.querySelector("[data-sh-search]");
    if (searchEl)
      searchEl.addEventListener("input", function () {
        var q = searchEl.value.trim().toLowerCase();
        Array.prototype.forEach.call(listEl.querySelectorAll("[data-sh-row]"), function (row) {
          row.style.display = !q || row.getAttribute("data-name").indexOf(q) >= 0 ? "" : "none";
        });
        Array.prototype.forEach.call(listEl.querySelectorAll("[data-sh-group]"), function (g) {
          var visible = g.querySelectorAll('[data-sh-row]:not([style*="display: none"])').length;
          g.style.display = visible ? "" : "none";
        });
      });
  }

  function submitForm(dialog, id, opts) {
    var form = dialog.querySelector("form.tp-sharing-form");
    if (!form) return;

    var selected = form._shSelected || {};
    var ids = Object.keys(selected);
    if (!ids.length) {
      tpModal.toast(tpT('ui.select_at_least_one_vehicle'), "warning");
      return;
    }

    var by = (form.querySelector('input[name="expiration_by"]:checked') || {}).value || "none";
    var expEl = form.querySelector('input[name="expiration_date"]'); // hidden ISO del tp-datepicker
    var durEl = form.querySelector('select[name="duration"]');

    var payload = {
      active: form.querySelector('input[name="active"]').checked ? 1 : 0,
      expiration_by: by,
      name: form.querySelector('input[name="name"]').value.trim(),
      description: form.querySelector('textarea[name="description"]').value.trim(),
      delete_after_expiration: form.querySelector('input[name="delete_after_expiration"]').checked ? 1 : 0,
      send_email: form.querySelector('input[name="send_email"]').checked ? 1 : 0,
      email: (form.querySelector('input[name="email"]').value || "").trim(),
      send_sms: form.querySelector('input[name="send_sms"]')
        ? (form.querySelector('input[name="send_sms"]').checked ? 1 : 0) : 0,
      sms: form.querySelector('input[name="sms"]')
        ? (form.querySelector('input[name="sms"]').value || "").trim() : "",
      // Secuencia EntityLoader: deseleccionar todo + attaches = selección exacta
      selected_devices: ["s;0;"].concat(
        ids.map(function (i) {
          return "id;1;" + i;
        }),
      ),
    };

    // Solo incluir la clave del modo elegido: el validador legacy ejecuta
    // date/integer sobre null presente (sin nullable) y fallaría.
    if (by === "duration" && durEl) payload.duration = durEl.value;
    if (by === "date" && expEl) {
      var timeEl = form.querySelector("[data-sh-exptime]");
      payload.expiration_date = expEl.value
        ? expEl.value + " " + ((timeEl && timeEl.value) || "23:59")
        : "";
    }

    var url = id ? API.item.replace("{id}", id) : API.store;
    tpModal.setBusy(dialog, true);
    tpModal
      .fetchJSON(url, { method: id ? "PUT" : "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(dialog, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          tpModal.showFieldErrors(dialog, errors);
          var first = Object.keys(errors)[0];
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : tpT('ui.check_the_fields'), "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || tpT('ui.save_failed'), "error");
          return;
        }
        tpModal.toast(id ? "Compartir actualizado." : tpT('ui.share_link_created'), "success");
        tpModal.close(dialog);
        if (typeof opts.onSaved === "function") opts.onSaved(res.data.item);
      })
      .catch(function () {
        tpModal.setBusy(dialog, false);
        tpModal.toast(tpT('ui.network_error'), "error");
      });
  }

  window.tpSharing = { openForm: openForm };

  /* ================= Página /ui/v2/sharing ================= */

  function initPage() {
    var root = document.getElementById("tp-sharing-page");
    if (!root) return;

    var listUrl = root.getAttribute("data-list-url") || API.list;
    var itemUrl = root.getAttribute("data-item-url") || API.item;
    var canEdit = root.getAttribute("data-can-edit") === "1";
    var canRemove = root.getAttribute("data-can-remove") === "1";

    var rowsEl = document.getElementById("tp-sharing-rows");
    var searchEl = document.getElementById("tp-sharing-search");
    var deviceSel = document.getElementById("tp-sharing-device");
    var limitSel = document.getElementById("tp-sharing-limit");
    var rangeEl = document.getElementById("tp-sharing-range");
    var prevBtn = document.getElementById("tp-sharing-prev");
    var nextBtn = document.getElementById("tp-sharing-next");
    var addBtn = document.getElementById("tp-sharing-add");

    var state = {
      page: 1,
      per: 20,
      s: "",
      deviceId: root.getAttribute("data-device-id") || "",
      last: 1,
      total: 0,
    };
    var timer = null;

    function badge(txt, kind) {
      var colors = {
        ok: "background:#dcfce7;color:#15803d;",
        off: "background:#f1f5f9;color:#64748b;",
        bad: "background:#fee2e2;color:#b91c1c;",
      };
      return (
        '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;' +
        colors[kind] + '">' + esc(txt) + "</span>"
      );
    }

    function kebab(row) {
      var items = "";
      if (canEdit)
        items +=
          '<button type="button" role="menuitem" data-sh-edit="' + row.id + '">' +
          '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> ' + tpT('global.edit') + '</button>';
      items +=
        '<button type="button" role="menuitem" data-sh-preview="' + esc(row.link) + '">' +
        '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Vista previa</button>';
      if (canRemove)
        items +=
          '<button type="button" role="menuitem" class="is-danger" data-sh-del="' + row.id + '">' +
          '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> ' + tpT('global.delete') + '</button>';
      return (
        '<div class="tp-svc-kebab">' +
        '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="Acciones">' +
        '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
        "</button>" +
        '<div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>"
      );
    }

    function load() {
      var q =
        listUrl +
        "?s=" + encodeURIComponent(state.s) +
        "&device_id=" + encodeURIComponent(state.deviceId) +
        "&page=" + state.page +
        "&per_page=" + state.per;
      tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          rowsEl.innerHTML = '<tr><td colspan="6" class="tp-svc-table__empty">' + tpT('ui.load_failed') + '</td></tr>';
          return;
        }
        state.last = res.data.last || 1;
        state.total = res.data.total || 0;
        var items = res.data.items || [];
        if (!items.length) {
          rowsEl.innerHTML = '<tr><td colspan="6" class="tp-svc-table__empty">' + tpT('front.no_data') + '</td></tr>';
        } else {
          rowsEl.innerHTML = items
            .map(function (r) {
              return (
                "<tr>" +
                "<td>" + badge(r.active ? tpT('attr.active') : tpT('attr.inactive'), r.active ? "ok" : "off") + "</td>" +
                "<td>" + esc(r.name) + "</td>" +
                '<td><a href="' + esc(r.link) + '" target="_blank" rel="noopener" style="color:var(--brand-500,#2e63d9);text-decoration:none;">' +
                esc(r.link) + "</a></td>" +
                "<td>" + badge(r.expired ? tpT('ui.yes') : "No", r.expired ? "bad" : "ok") + "</td>" +
                "<td>" + (r.expiration_date ? esc(r.expiration_date) : "–") + "</td>" +
                '<td class="tp-svc-table__actions">' + kebab(r) + "</td>" +
                "</tr>"
              );
            })
            .join("");
        }
        var from = state.total ? (state.page - 1) * state.per + 1 : 0;
        var to = Math.min(state.page * state.per, state.total);
        rangeEl.textContent = from + "–" + to + " / " + state.total;
        prevBtn.disabled = state.page <= 1;
        nextBtn.disabled = state.page >= state.last;
      });
    }

    // Filtro por device (catálogo del meta)
    tpModal.fetchJSON(API.meta, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok || !deviceSel) return;
      var html = '<option value="">' + tpT('ui.all_vehicles') + '</option>';
      (res.data.devices || []).forEach(function (d) {
        html += '<option value="' + d.id + '"' + (String(state.deviceId) === String(d.id) ? " selected" : "") + ">" + esc(d.name) + "</option>";
      });
      deviceSel.innerHTML = html;
      if (window.tpSelect && tpSelect.refresh) {
        try {
          tpSelect.refresh(deviceSel);
        } catch (e) {}
      }
    });

    searchEl.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        state.s = searchEl.value.trim();
        state.page = 1;
        load();
      }, 300);
    });
    deviceSel.addEventListener("change", function () {
      state.deviceId = deviceSel.value;
      state.page = 1;
      load();
    });
    limitSel.addEventListener("change", function () {
      state.per = parseInt(limitSel.value, 10) || 20;
      state.page = 1;
      load();
    });
    prevBtn.addEventListener("click", function () {
      if (state.page > 1) {
        state.page--;
        load();
      }
    });
    nextBtn.addEventListener("click", function () {
      if (state.page < state.last) {
        state.page++;
        load();
      }
    });
    addBtn.addEventListener("click", function () {
      openForm(null, { deviceId: state.deviceId || null, onSaved: load });
    });

    rowsEl.addEventListener("click", function (e) {
      var edit = e.target.closest("[data-sh-edit]");
      if (edit) {
        openForm(edit.getAttribute("data-sh-edit"), { onSaved: load });
        return;
      }
      var prev = e.target.closest("[data-sh-preview]");
      if (prev) {
        window.open(prev.getAttribute("data-sh-preview"), "_blank", "noopener");
        return;
      }
      var del = e.target.closest("[data-sh-del]");
      if (del) {
        var sid = del.getAttribute("data-sh-del");
        tpModal
          .confirm({
            title: tpT('ui.delete_share'),
            message: tpT('ui.confirm_delete_share_link'),
            danger: true,
            confirmLabel: tpT('global.delete'),
          })
          .then(function (okGo) {
            if (!okGo) return;
            tpModal.fetchJSON(itemUrl.replace("{id}", sid), { method: "DELETE" }).then(function (res) {
              if (res.ok && res.data && res.data.ok) {
                tpModal.toast("Eliminado.", "success");
                load();
              } else {
                tpModal.toast(tpT('ui.delete_failed'), "error");
              }
            });
          });
      }
    });

    load();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initPage);
  else initPage();
})();
