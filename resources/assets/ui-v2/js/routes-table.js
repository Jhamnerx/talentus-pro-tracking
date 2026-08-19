/* =====================================================
 * TalentusPro · routes-table.js · Página /ui/v2/routes/table
 * Tabla completa de rutas (☰ del panel de Live):
 * Visible | Name | Color + buscador, crear/exportar/importar,
 * kebab Editar/Eliminar y paginación. Reusa los endpoints v2
 * (rutas + grupos + toggle) y los legacy de export/import.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-routes-page");
  if (!root || !window.tpModal) return;

  var BASE = root.getAttribute("data-url");
  var GROUPS_BASE = BASE.replace(/\/routes$/, "/route-groups");
  var LIVE_URL = root.getAttribute("data-live-url");
  var EXPORT_URL = root.getAttribute("data-export-url") || "";
  var IMPORT_URL = root.getAttribute("data-import-url") || "";
  var CAN_EDIT = root.getAttribute("data-can-edit") === "1";

  var state = {
    items: [],
    groups: [],
    canGroups: false,
    q: "",
    page: 1,
    limit: 20,
    sortKey: "name",
    sortDir: 1,
  };

  var els = {
    rows: document.getElementById("tp-routes-tbl-rows"),
    search: document.getElementById("tp-routes-tbl-search"),
    limit: document.getElementById("tp-routes-tbl-limit"),
    range: document.getElementById("tp-routes-tbl-range"),
    prev: document.getElementById("tp-routes-tbl-prev"),
    next: document.getElementById("tp-routes-tbl-next"),
    table: document.getElementById("tp-routes-tbl"),
  };

  function esc(s) {
    return tpModal.escapeHtml(s);
  }
  function csrf() {
    return tpModal.csrf();
  }
  function T(k, fb) {
    return (window.tpI18n && window.tpI18n[k]) || fb;
  }
  function coordsForSave(r) {
    return (r.coordinates || [])
      .map(function (c) {
        if (Array.isArray(c)) return { lat: +c[1], lng: +c[0] };
        if (c && c.lat != null) return { lat: +c.lat, lng: +c.lng };
        return null;
      })
      .filter(Boolean);
  }

  /* ---------- Kebab ⋮ ---------- */
  var DOTS =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
  function kebabHtml(r) {
    if (!CAN_EDIT) return "";
    return (
      '<div class="tp-svc-kebab"><button type="button" class="tp-svc-kebab__btn" data-kebab aria-haspopup="menu">' +
      DOTS +
      '</button><div class="tp-svc-kebab__menu" role="menu" hidden>' +
      '<button type="button" role="menuitem" data-act="edit" data-id="' + r.id + '">' + esc(T("edit", "Editar")) + "</button>" +
      '<button type="button" role="menuitem" class="is-danger" data-act="del" data-id="' + r.id + '">' + esc(T("delete", "Eliminar")) + "</button>" +
      "</div></div>"
    );
  }
  function closeKebabs(except) {
    root.querySelectorAll(".tp-svc-kebab__menu:not([hidden])").forEach(function (m) {
      if (m !== except) m.setAttribute("hidden", "");
    });
  }
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-kebab]");
    if (btn) {
      var menu = btn.parentNode.querySelector(".tp-svc-kebab__menu");
      var wasOpen = menu && !menu.hasAttribute("hidden");
      closeKebabs();
      if (menu && !wasOpen) menu.removeAttribute("hidden");
      e.stopPropagation();
      return;
    }
    closeKebabs();
  });

  /* ---------- Render ---------- */
  function filtered() {
    var rows = state.items;
    if (state.q) {
      var q = state.q.toLowerCase();
      rows = rows.filter(function (r) {
        return (r.name || "").toLowerCase().indexOf(q) !== -1;
      });
    }
    rows = rows.slice().sort(function (a, b) {
      var va, vb;
      if (state.sortKey === "active") {
        va = a.active ? 1 : 0;
        vb = b.active ? 1 : 0;
      } else if (state.sortKey === "color") {
        va = (a.color || "").toLowerCase();
        vb = (b.color || "").toLowerCase();
      } else {
        va = (a.name || "").toLowerCase();
        vb = (b.name || "").toLowerCase();
      }
      if (va < vb) return -1 * state.sortDir;
      if (va > vb) return 1 * state.sortDir;
      return 0;
    });
    return rows;
  }

  function render() {
    var rows = filtered();
    var total = rows.length;
    var pages = Math.max(1, Math.ceil(total / state.limit));
    if (state.page > pages) state.page = pages;
    var start = (state.page - 1) * state.limit;
    var slice = rows.slice(start, start + state.limit);

    var html = "";
    slice.forEach(function (r) {
      var badge = r.active
        ? '<span class="tp-rt-badge is-on"' + (CAN_EDIT ? ' data-toggle="' + r.id + '"' : "") + ">" + esc(T("active", "Activo")) + "</span>"
        : '<span class="tp-rt-badge"' + (CAN_EDIT ? ' data-toggle="' + r.id + '"' : "") + ">" + esc(T("inactive", "Inactivo")) + "</span>";
      html +=
        "<tr><td>" + badge + "</td><td>" + esc(r.name) + "</td>" +
        '<td><span class="tp-rt-swatch" style="background:' + (r.color || "#7c3aed") + '"></span> <span class="tp-rt-hex">' + esc((r.color || "").toLowerCase()) + "</span></td>" +
        '<td class="tp-svc-table__actions">' + kebabHtml(r) + "</td></tr>";
    });
    if (!html)
      html = '<tr><td colspan="4" class="tp-svc-table__empty">' + esc(T("no_routes", "No hay rutas.")) + "</td></tr>";
    els.rows.innerHTML = html;

    els.range.textContent = total
      ? start + 1 + "–" + Math.min(start + state.limit, total) + " / " + total
      : "0";
    els.prev.disabled = state.page <= 1;
    els.next.disabled = state.page >= pages;

    els.table.querySelectorAll("th[data-sort]").forEach(function (th) {
      var a = th.querySelector(".tp-maint__arrow");
      if (a) a.remove();
      if (th.getAttribute("data-sort") === state.sortKey) {
        var s = document.createElement("span");
        s.className = "tp-maint__arrow";
        s.textContent = state.sortDir > 0 ? "▲" : "▼";
        th.appendChild(s);
      }
    });
  }

  function load() {
    tpModal.fetchJSON(BASE).then(function (res) {
      if (!res.ok || !res.data) {
        els.rows.innerHTML = '<tr><td colspan="4" class="tp-svc-table__empty">' + esc(T("error", "Error")) + "</td></tr>";
        return;
      }
      state.items = res.data.items || [];
      state.groups = res.data.groups || [];
      state.canGroups = !!res.data.can_groups;
      render();
    });
  }

  function byId(id) {
    return state.items.filter(function (r) { return r.id === id; })[0];
  }

  /* ---------- Toggle visible ---------- */
  function toggleActive(id) {
    var r = byId(id);
    if (!r) return;
    var next = !r.active;
    r.active = next;
    render();
    tpModal
      .fetchJSON(BASE + "/" + id + "/toggle", { method: "POST", json: { active: next ? 1 : 0 } })
      .then(function (res) {
        if (!(res.ok && res.data && res.data.ok)) {
          r.active = !next;
          render();
          tpModal.toast(T("error", "Error"), "error");
        }
      });
  }

  /* ---------- Editar metadatos (name/color/group) ---------- */
  function openEdit(r) {
    var groupOpts =
      '<option value="0">' + esc(T("ungrouped", "Sin grupo")) + "</option>" +
      state.groups
        .map(function (g) {
          return '<option value="' + g.id + '"' + (String(r.group_id || 0) === String(g.id) ? " selected" : "") + ">" + esc(g.title) + "</option>";
        })
        .join("");
    var body =
      '<form class="tp-poi-form" data-rt-form>' +
      '<div class="tp-field"><label class="tp-field__label">' + esc(T("name", "Nombre")) +
      '</label><input type="text" name="name" class="tp-input" value="' + esc(r.name) + '"></div>' +
      '<div class="tp-field"><label class="tp-field__label">' + esc(T("color", "Color")) +
      '</label><div class="tp-colorfield"><input type="color" name="color" value="' + esc(r.color || "#1938FF") +
      '"><span class="tp-colorfield__hex" data-hex>' + esc((r.color || "#1938ff").toLowerCase()) + "</span></div></div>" +
      '<div class="tp-field"><label class="tp-field__label">' + esc(T("group", "Grupo")) +
      '</label><select name="group_id" class="tp-input">' + groupOpts + "</select></div></form>";
    tpModal.open({
      title: T("edit_route", "Editar ruta"),
      size: "md",
      body: body,
      buttons: [
        { label: T("cancel", "Cancelar"), variant: "ghost" },
        {
          label: T("save", "Guardar"),
          variant: "primary",
          close: false,
          onClick: function (dlg, btn) {
            var f = dlg.querySelector("[data-rt-form]");
            var name = (f.querySelector('[name="name"]').value || "").trim();
            if (!name) {
              tpModal.toast(T("name_required", "El nombre es obligatorio."), "warning");
              return false;
            }
            var payload = {
              name: name,
              color: f.querySelector('[name="color"]').value,
              group_id: parseInt(f.querySelector('[name="group_id"]').value, 10) || 0,
              coordinates: coordsForSave(r),
            };
            btn.disabled = true;
            tpModal.fetchJSON(BASE + "/" + r.id, { method: "PUT", json: payload }).then(function (res) {
              btn.disabled = false;
              if (res.ok && res.data && res.data.ok) {
                tpModal.toast(T("route_updated", "Ruta actualizada."), "success");
                tpModal.close(dlg);
                load();
              } else tpModal.toast((res.data && res.data.message) || T("could_not_save", "No se pudo guardar."), "error");
            });
            return false;
          },
        },
      ],
      onMount: function (dlg) {
        var inp = dlg.querySelector('input[type="color"]');
        var hex = dlg.querySelector("[data-hex]");
        if (inp && hex) inp.addEventListener("input", function () { hex.textContent = inp.value.toLowerCase(); });
      },
    });
  }

  function del(r) {
    tpModal
      .confirm({
        title: T("delete_route", "Eliminar ruta"),
        message: '"' + r.name + '" — ' + T("delete_route_confirm", "¿Eliminar esta ruta?"),
        confirmLabel: T("delete", "Eliminar"),
        danger: true,
      })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON(BASE + "/" + r.id, { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) {
            tpModal.toast(T("route_deleted", "Ruta eliminada."), "success");
            load();
          } else tpModal.toast(T("error", "Error"), "error");
        });
      });
  }

  /* ---------- Export / Import (endpoints legacy) ---------- */
  function openExport() {
    if (!EXPORT_URL) return;
    var formats = { csv: "CSV", gexp: "GEXP", kml: "KML" };
    var types = {
      export_single: T("export_single", "Exportar uno solo"),
      export_active: T("export_active", "Exportar activas"),
      export_inactive: T("export_inactive", "Exportar inactivas"),
    };
    var opt = function (obj, sel) {
      return Object.keys(obj).map(function (k) {
        return '<option value="' + k + '"' + (k === sel ? " selected" : "") + ">" + esc(obj[k]) + "</option>";
      }).join("");
    };
    var checks = state.items
      .map(function (r) {
        return '<label class="tp-rgroup-item"><input type="checkbox" data-exp-route value="' + r.id + '"><span class="tp-rt-swatch" style="background:' + (r.color || "#7c3aed") + '"></span><span>' + esc(r.name) + "</span></label>";
      })
      .join("");
    var body =
      '<div class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-field__label">' + esc(T("format", "Formato")) + '</label><select class="tp-input" data-exp-format>' + opt(formats, "csv") + "</select></div>" +
      '<div class="tp-field"><label class="tp-field__label">' + esc(T("export_type_label", "Tipo de exportación")) + '</label><select class="tp-input" data-exp-type>' + opt(types, "export_single") + "</select></div>" +
      '<div class="tp-field" data-exp-wrap><label class="tp-field__label">' + esc(T("routes", "Rutas")) + '</label><div class="tp-rgroup-list">' + checks + "</div></div></div>";
    tpModal.open({
      title: T("export", "Exportar"),
      size: "md",
      body: body,
      buttons: [
        { label: T("cancel", "Cancelar"), variant: "ghost" },
        {
          label: T("export", "Exportar"),
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            var fmt = dlg.querySelector("[data-exp-format]").value;
            var type = dlg.querySelector("[data-exp-type]").value;
            var ids = [];
            dlg.querySelectorAll("[data-exp-route]:checked").forEach(function (c) { ids.push(c.value); });
            // El filtro del backend (SinglesRouteFilter) SIEMPRE va por ids: en
            // activas/inactivas hay que mandarlos (el legacy los preselecciona
            // en getType). Sin ids el filtro queda vacío = exporta TODAS.
            if (type === "export_active" || type === "export_inactive") {
              var want = type === "export_active";
              ids = (state.items || [])
                .filter(function (r) { return !!r.active === want; })
                .map(function (r) { return r.id; });
            }
            submitExport(fmt, type, ids);
            tpModal.close(dlg);
            return false;
          },
        },
      ],
      onMount: function (dlg) {
        var sel = dlg.querySelector("[data-exp-type]");
        var wrap = dlg.querySelector("[data-exp-wrap]");
        var sync = function () { wrap.hidden = sel.value !== "export_single"; };
        sel.addEventListener("change", sync);
        sync();
      },
    });
  }
  function submitExport(format, type, ids) {
    var form = document.createElement("form");
    form.method = "POST";
    form.action = EXPORT_URL;
    form.style.display = "none";
    var add = function (n, v) {
      var i = document.createElement("input");
      i.type = "hidden";
      i.name = n;
      i.value = v;
      form.appendChild(i);
    };
    add("_token", csrf());
    add("export_format", format);
    add("export_type", type);
    (ids || []).forEach(function (id) { add("routes[]", id); });
    document.body.appendChild(form);
    form.submit();
    setTimeout(function () { if (form.parentNode) form.parentNode.removeChild(form); }, 1000);
  }

  function openImport() {
    if (!IMPORT_URL) return;
    var body =
      '<div class="tp-poi-form"><div class="tp-field"><label class="tp-field__label">' + esc(T("file", "Archivo")) +
      ' (CSV / GEXP / KML)</label><input type="file" class="tp-input" data-imp-file accept=".csv,.gexp,.kml,.json"></div></div>';
    tpModal.open({
      title: T("import", "Importar"),
      size: "sm",
      body: body,
      buttons: [
        { label: T("cancel", "Cancelar"), variant: "ghost" },
        {
          label: T("import", "Importar"),
          variant: "primary",
          close: false,
          onClick: function (dlg, btn) {
            var input = dlg.querySelector("[data-imp-file]");
            if (!input.files || !input.files.length) {
              tpModal.toast(T("select_file", "Selecciona un archivo."), "warning");
              return false;
            }
            var fd = new FormData();
            fd.append("file", input.files[0]);
            btn.disabled = true;
            fetch(IMPORT_URL, {
              method: "POST",
              credentials: "same-origin",
              headers: { "X-CSRF-TOKEN": csrf(), "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
              body: fd,
            })
              .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
              .then(function (res) {
                btn.disabled = false;
                if (res.ok && res.data && res.data.status == 1) {
                  tpModal.toast(res.data.message || T("changes_saved", "Cambios guardados."), "success");
                  tpModal.close(dlg);
                  load();
                } else {
                  var errs = res.data && res.data.errors;
                  tpModal.toast(
                    (errs && errs.file && (Array.isArray(errs.file) ? errs.file[0] : errs.file)) ||
                      (res.data && res.data.message) ||
                      T("import_failed", "No se pudo importar."),
                    "error",
                  );
                }
              })
              .catch(function () {
                btn.disabled = false;
                tpModal.toast(T("network_error", "Error de red."), "error");
              });
            return false;
          },
        },
      ],
    });
  }

  /* ---------- Eventos ---------- */
  els.search.addEventListener("input", function () {
    state.q = this.value.trim();
    state.page = 1;
    render();
  });
  els.limit.addEventListener("change", function () {
    state.limit = parseInt(this.value, 10) || 20;
    state.page = 1;
    render();
  });
  els.prev.addEventListener("click", function () { state.page--; render(); });
  els.next.addEventListener("click", function () { state.page++; render(); });
  els.table.querySelectorAll("th[data-sort]").forEach(function (th) {
    th.addEventListener("click", function () {
      var key = th.getAttribute("data-sort");
      if (state.sortKey === key) state.sortDir *= -1;
      else { state.sortKey = key; state.sortDir = 1; }
      render();
    });
  });

  els.rows.addEventListener("click", function (e) {
    var tg = e.target.closest("[data-toggle]");
    if (tg) { toggleActive(parseInt(tg.getAttribute("data-toggle"), 10)); return; }
    var act = e.target.closest("[data-act]");
    if (!act) return;
    closeKebabs();
    var r = byId(parseInt(act.getAttribute("data-id"), 10));
    if (!r) return;
    if (act.getAttribute("data-act") === "edit") openEdit(r);
    else del(r);
  });

  var newBtn = document.getElementById("tp-routes-tbl-new");
  if (newBtn) newBtn.addEventListener("click", function () { window.location.href = LIVE_URL; });
  var expBtn = document.getElementById("tp-routes-tbl-export");
  if (expBtn) expBtn.addEventListener("click", openExport);
  var impBtn = document.getElementById("tp-routes-tbl-import");
  if (impBtn) impBtn.addEventListener("click", openImport);

  load();
})();
