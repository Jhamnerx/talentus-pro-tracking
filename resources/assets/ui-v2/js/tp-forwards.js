/* =====================================================
 * TalentusPro · tp-forwards — Reenvíos (modal desde Herramientas)
 * -----------------------------------------------------
 * Lista + formulario con campos dinámicos por tipo (Custom/Pegasus/
 * MacroPoint). Los tipos disponibles vienen del backend (filtrados por
 * addon.forward_*). Expone window.tpForwards.open(dataUrl).
 * ===================================================== */
(function () {
  "use strict";
  if (!window.tpModal) return;

  var BASE = null; // /ui/v2/api/forwards
  var TYPES = [];
  var byId = {};
  var listDlg = null;

  function esc(s) {
    return tpModal.escapeHtml(s == null ? "" : String(s));
  }

  // Resumen legible de la config por tipo (para la lista).
  function summary(f) {
    var p = f.payload || {};
    if (f.type === "custom") return (p.ip || "") + (p.port ? ":" + p.port : "");
    if (f.type === "pegasus") return p.domain ? p.domain + ".peginstances.com" : "";
    if (f.type === "macropoint") return p.mpid ? "MPID " + p.mpid : p.username || "";
    return "";
  }

  var EDIT_ICO =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>';
  var DEL_ICO =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';

  /* ---------- Lista ---------- */
  function openList() {
    tpModal.fetchJSON(BASE, { method: "GET" }).then(function (res) {
      var items = (res.data && res.data.items) || [];
      TYPES = (res.data && res.data.types) || [];
      byId = {};
      items.forEach(function (f) { byId[f.id] = f; });

      var rows = items.length
        ? items
            .map(function (f) {
              var s = summary(f);
              return (
                '<div class="tp-fwd__row">' +
                '<span class="tp-fwd-badge tp-fwd-badge--' + (f.active ? "on" : "off") + '">' + (f.active ? tpT('attr.active') : tpT('attr.inactive')) + "</span>" +
                '<div class="tp-fwd__main"><div class="tp-fwd__title">' + esc(f.title) + "</div>" +
                '<div class="tp-fwd__meta"><span class="tp-fwd-type">' + esc(f.type) + "</span>" + (s ? " · " + esc(s) : "") + "</div></div>" +
                '<div class="tp-fwd__actions">' +
                '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-fwd-edit="' + f.id + '" title="Editar">' + EDIT_ICO + "</button>" +
                '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-fwd-del="' + f.id + '" title="Eliminar" style="color:#ef4444;">' + DEL_ICO + "</button>" +
                "</div></div>"
              );
            })
            .join("")
        : '<div class="tp-fwd__empty">' + tpT('ui.no_forwards_yet') + '</div>';

      var body =
        '<div class="tp-scope tp-fwd">' +
        '<div class="tp-fwd__head">' +
        '<p class="tp-fwd__hint">' + tpT('ui.forwards_intro') + '</p>' +
        '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-fwd-add>' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg> ' + tpT('global.add') + '</button></div>' +
        '<div class="tp-fwd__list">' + rows + "</div></div>";

      if (listDlg && listDlg.isConnected) {
        var b = listDlg.querySelector(".tp-modal__body");
        if (b) b.innerHTML = body;
      } else {
        listDlg = tpModal.open({
          title: tpT('front.forwards'),
          size: "md",
          body: body,
          buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
        });
        listDlg.addEventListener("click", function (e) {
          if (e.target.closest("[data-fwd-add]")) return openForm(null);
          var ed = e.target.closest("[data-fwd-edit]");
          if (ed) return openForm(byId[ed.getAttribute("data-fwd-edit")]);
          var del = e.target.closest("[data-fwd-del]");
          if (del) return doDelete(del.getAttribute("data-fwd-del"));
        });
      }
    });
  }

  function doDelete(id) {
    tpModal
      .confirm({ title: tpT('ui.delete_forward'), message: tpT('ui.confirm_delete_forward'), confirmLabel: tpT('global.delete'), danger: true })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON(BASE + "/" + id, { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) openList();
          else tpModal.toast("No se pudo eliminar.", "error");
        });
      });
  }

  /* ---------- Formulario ---------- */
  function fieldInput(f, val) {
    var t = f.type === "number" ? "number" : f.type === "password" ? "password" : "text";
    return (
      '<div class="tp-field"><label class="tp-field__label">' + esc(f.label) + (f.required ? " *" : "") + "</label>" +
      '<input type="' + t + '" class="tp-input" data-fwd-field="' + esc(f.name) + '" value="' + esc(val) + '" style="width:100%;">' +
      (f.help ? '<span class="tp-field__hint" style="display:block;font-size:12px;opacity:.7;margin-top:2px;">' + esc(f.help) + "</span>" : "") +
      "</div>"
    );
  }

  function openForm(item) {
    var isEdit = !!item;
    var payload = (item && item.payload) || {};
    var typeOpts =
      '<option value="">— Selecciona —</option>' +
      TYPES.map(function (t) {
        return '<option value="' + esc(t.type) + '"' + (item && item.type === t.type ? " selected" : "") + ">" + esc(t.title) + "</option>";
      }).join("");

    var body =
      '<div class="tp-scope">' +
      '<label class="tp-check" style="display:flex;align-items:center;gap:8px;margin-bottom:10px;"><input type="checkbox" data-fwd-active' +
      (!item || item.active ? " checked" : "") + "> " + tpT('attr.active') + "</label>" +
      '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.title_required_field') + '</label><input type="text" class="tp-input" data-fwd-title value="' +
      (item ? esc(item.title) : "") + '" style="width:100%;"></div>' +
      '<div class="tp-field"><label class="tp-field__label">Tipo *</label><select class="tp-input" data-no-ts="1" data-fwd-type style="width:100%;"' +
      (isEdit ? " disabled" : "") + ">" + typeOpts + "</select></div>" +
      "<div data-fwd-fields></div>" +
      (TYPES.length
        ? ""
        : '<p style="color:#ef4444;font-size:13px;">' + tpT('ui.no_forward_types_enabled') + ' <code>ENABLE_FORWARD_CUSTOM</code> / <code>ENABLE_FORWARD_PEGASUS</code> / <code>ENABLE_FORWARD_MACROPOINT</code> en el .env.</p>') +
      '<div data-fwd-err style="display:none;margin-top:10px;color:#ef4444;font-size:13px;"></div></div>';

    var dlg = tpModal.open({
      title: isEdit ? tpT('ui.edit_forward') : tpT('ui.new_forward'),
      size: "sm",
      body: body,
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('global.save'),
          variant: "primary",
          close: false,
          onClick: function (d) {
            saveForm(d, item);
            return false;
          },
        },
      ],
    });

    var typeSel = dlg.querySelector("[data-fwd-type]");
    var fieldsBox = dlg.querySelector("[data-fwd-fields]");
    function renderFields() {
      var t = TYPES.filter(function (x) { return x.type === typeSel.value; })[0];
      fieldsBox.innerHTML = t ? t.fields.map(function (f) { return fieldInput(f, payload[f.name]); }).join("") : "";
    }
    typeSel.addEventListener("change", renderFields);
    renderFields();
  }

  function saveForm(d, item) {
    var err = d.querySelector("[data-fwd-err]");
    function showErr(m) {
      if (err) {
        err.textContent = m;
        err.style.display = "block";
      }
    }
    if (err) err.style.display = "none";
    var type = (d.querySelector("[data-fwd-type]") || {}).value || (item && item.type) || "";
    var title = ((d.querySelector("[data-fwd-title]") || {}).value || "").trim();
    if (!title) return showErr(tpT('ui.title_required'));
    if (!type) return showErr("Selecciona un tipo.");

    var payload = { active: d.querySelector("[data-fwd-active]").checked ? 1 : 0, title: title, type: type };
    Array.prototype.forEach.call(d.querySelectorAll("[data-fwd-field]"), function (i) {
      payload[i.getAttribute("data-fwd-field")] = i.value;
    });

    tpModal.setBusy(d, true);
    var url = item ? BASE + "/" + item.id : BASE;
    tpModal
      .fetchJSON(url, { method: item ? "PUT" : "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(d, false);
        if (res.status === 422) return showErr("Revisa los campos obligatorios.");
        if (!res.ok || !res.data || !res.data.ok) return showErr((res.data && res.data.message) || "No se pudo guardar.");
        tpModal.close(d);
        openList();
      })
      .catch(function () {
        tpModal.setBusy(d, false);
        showErr("Error de red.");
      });
  }

  window.tpForwards = {
    open: function (url) {
      BASE = url;
      openList();
    },
  };
})();
