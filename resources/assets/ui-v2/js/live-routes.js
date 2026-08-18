/* ============================================================
 * UI v2 · Panel de RUTAS sobre el mapa Leaflet compartido (live.js)
 * ------------------------------------------------------------
 * Reusa el motor Leaflet del workspace (window.tpLive.map()) y leaflet-draw
 * para crear/editar rutas (polilíneas) con edición de vértices + punto medio.
 * CRUD de rutas y grupos vía endpoints v2 (MapLayersController → RouteUserService
 * / RouteGroupService). Lista agrupable (folder toggle) con casillas de
 * visibilidad, modales de grupo, export/import (reusa endpoints legacy) y
 * acceso a la tabla completa. Se activa al abrir el panel "Rutas".
 * ============================================================ */
(function () {
  "use strict";
  var root = document.getElementById("tp-live");
  if (!root || !window.L) return;

  var BASE = root.getAttribute("data-routes-url") || "/ui/v2/api/routes";
  var GROUPS_BASE = BASE.replace(/\/routes$/, "/route-groups");
  var TABLE_URL = root.getAttribute("data-routes-table-url") || "/ui/v2/routes/table";
  var EXPORT_URL = root.getAttribute("data-routes-export-url") || "";
  var IMPORT_URL = root.getAttribute("data-routes-import-url") || "";

  var S = {
    loaded: false,
    items: [],
    groups: [],
    canEdit: false,
    canGroups: false,
    byId: {},
    layer: null,
    layersById: {},
    q: "",
    activeId: null,
    visible: false,
    grouped: true,
    collapsed: {}, // group id (o 'un') → true si colapsado
    // dibujo / edición
    drawer: null,
    draft: null,
    editId: null,
    editName: null,
    bar: null,
  };

  function map() {
    return window.tpLive && window.tpLive.map();
  }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function csrf() {
    return (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  }
  function T(k, fb) {
    return (window.tpI18n && window.tpI18n[k]) || fb;
  }
  // coords del backend = pares [lng, lat] → Leaflet [lat, lng]
  function toLatLngs(coords) {
    return (coords || [])
      .map(function (c) {
        if (Array.isArray(c)) return [+c[1], +c[0]];
        if (c && c.lat != null) return [+c.lat, +c.lng];
        return null;
      })
      .filter(Boolean);
  }
  // [{lat,lng}] a partir del formato backend [lng,lat] (para reenviar geometría).
  function coordsForSave(r) {
    return (r.coordinates || [])
      .map(function (c) {
        if (Array.isArray(c)) return { lat: +c[1], lng: +c[0] };
        if (c && c.lat != null) return { lat: +c.lat, lng: +c.lng };
        return null;
      })
      .filter(Boolean);
  }

  // ----- Capa Leaflet de una ruta guardada -----
  function buildLayerFor(r) {
    var pts = toLatLngs(r.coordinates);
    if (pts.length < 2) return null;
    return L.polyline(pts, {
      color: r.color || "#7c3aed",
      weight: r.id === S.activeId ? 7 : 5,
      opacity: r.active ? 0.9 : 0.35,
      // Punteado siempre (mismo look que Historial); las inactivas se
      // distinguen por la opacidad.
      dashArray: "6 6",
    });
  }

  function renderLayers() {
    var m = map();
    if (!m) return;
    if (!S.layer) S.layer = L.layerGroup();
    // Con el formulario acoplado abierto se edita solo el borrador: no
    // reañadir la capa estática (evita duplicar la ruta en edición).
    if (S.visible && !formMode && !m.hasLayer(S.layer)) S.layer.addTo(m);
    S.layer.clearLayers();
    S.layersById = {};
    // En el mapa solo mostramos las rutas activas (como el legacy).
    S.filtered().forEach(function (r) {
      if (!r.active) return;
      var layer = buildLayerFor(r);
      if (!layer) return;
      layer._rid = r.id;
      layer.on("click", function () {
        openRoutePopup(r);
      });
      layer.bindTooltip(r.name || T("route", "Ruta"));
      S.layer.addLayer(layer);
      S.layersById[r.id] = layer;
    });
  }

  S.filtered = function () {
    var q = S.q.toLowerCase();
    return S.items.filter(function (r) {
      if (q && (r.name || "").toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  };

  // ----- Iconos -----
  var GO =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.16 7-12a7 7 0 0 0-14 0c0 4.84 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>';
  var EDIT =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>';
  var DEL =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';
  var DOTS =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>';
  var CHEV =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';

  // ----- Lista del panel (agrupable / plana) -----
  function routeRowHtml(r) {
    return (
      '<div class="tp-poi-row tp-route-row" data-id="' +
      r.id +
      '" role="option">' +
      (S.canEdit
        ? '<input type="checkbox" class="tp-route-row__chk" data-r-active ' +
          (r.active ? "checked" : "") +
          ' title="' + esc(T("visible", "Visible")) + '">'
        : "") +
      '<span class="tp-zone-row__swatch" style="background:' +
      (r.color || "#7c3aed") +
      '"></span><div class="tp-poi-row__main"><div class="tp-poi-row__name">' +
      esc(r.name || "#" + r.id) +
      '</div><div class="tp-poi-row__group">' +
      (r.coordinates || []).length +
      " pts</div></div><div class=\"tp-poi-row__actions\">" +
      '<button type="button" class="tp-poi-row__btn" data-r-go title="' +
      esc(T("center_on_map", "Centrar")) +
      '">' +
      GO +
      "</button>" +
      (S.canEdit
        ? '<button type="button" class="tp-poi-row__btn" data-r-edit title="' +
          esc(T("edit", "Editar")) +
          '">' +
          EDIT +
          '</button><button type="button" class="tp-poi-row__btn tp-poi-row__btn--danger" data-r-del title="' +
          esc(T("delete", "Eliminar")) +
          '">' +
          DEL +
          "</button>"
        : "") +
      "</div></div>"
    );
  }

  function groupHeadHtml(key, title, count, isReal, collapsed) {
    return (
      '<div class="tp-rgrp__head" data-grp="' +
      esc(key) +
      '">' +
      '<button type="button" class="tp-rgrp__chev' +
      (collapsed ? " is-collapsed" : "") +
      '" data-grp-toggle aria-label="' + esc(T("expand", "Expandir")) + '">' +
      CHEV +
      "</button>" +
      '<svg class="tp-rgrp__folder" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>' +
      '<span class="tp-rgrp__name">' +
      esc(title) +
      '</span><span class="tp-rgrp__count">' +
      count +
      "</span>" +
      (isReal && S.canGroups
        ? '<div class="tp-rgrp__actions"><button type="button" class="tp-poi-row__btn" data-grp-edit title="' +
          esc(T("edit", "Editar")) +
          '">' +
          EDIT +
          '</button><button type="button" class="tp-poi-row__btn tp-poi-row__btn--danger" data-grp-del title="' +
          esc(T("delete", "Eliminar")) +
          '">' +
          DEL +
          "</button></div>"
        : "") +
      "</div>"
    );
  }

  function renderList() {
    var list = document.getElementById("tp-routes-list");
    if (!list) return;
    var rows = S.filtered();
    var count = document.getElementById("tp-routes-count");
    if (count) count.textContent = rows.length ? "· " + rows.length : "";
    var empty = document.getElementById("tp-routes-empty");
    if (empty) empty.hidden = rows.length > 0;

    var fold = document.getElementById("tp-routes-fold");
    if (fold) fold.setAttribute("aria-pressed", S.grouped ? "true" : "false");

    if (!S.grouped) {
      list.innerHTML = rows.map(routeRowHtml).join("");
      return;
    }

    // Agrupado: grupos reales (orden del backend) + "Sin grupo".
    var html = "";
    S.groups.forEach(function (g) {
      var gr = rows.filter(function (r) {
        return (r.group_id || 0) === g.id;
      });
      if (!gr.length && S.q) return; // al buscar, oculta grupos vacíos
      var collapsed = !!S.collapsed[g.id];
      html +=
        '<div class="tp-rgrp">' +
        groupHeadHtml(g.id, g.title, gr.length, true, collapsed) +
        '<div class="tp-rgrp__body"' +
        (collapsed ? " hidden" : "") +
        ">" +
        gr.map(routeRowHtml).join("") +
        "</div></div>";
    });
    var ung = rows.filter(function (r) {
      return !(r.group_id || 0);
    });
    if (ung.length) {
      var col = !!S.collapsed.un;
      html +=
        '<div class="tp-rgrp">' +
        groupHeadHtml("un", T("ungrouped", "Sin grupo"), ung.length, false, col) +
        '<div class="tp-rgrp__body"' +
        (col ? " hidden" : "") +
        ">" +
        ung.map(routeRowHtml).join("") +
        "</div></div>";
    }
    list.innerHTML = html;
  }

  function flyTo(r) {
    var m = map(),
      layer = S.layersById[r.id];
    if (!m) return;
    if (!layer) {
      // ruta inactiva (no dibujada): construir bounds al vuelo
      var pts = toLatLngs(r.coordinates);
      if (pts.length < 2) return;
      m.fitBounds(L.polyline(pts).getBounds(), { padding: [60, 60], maxZoom: 16 });
      return;
    }
    var b = layer.getBounds();
    if (b && b.isValid()) m.fitBounds(b, { padding: [60, 60], maxZoom: 16 });
  }

  // ----- Popup Editar / Editar forma / Eliminar -----
  var popup = null;
  function openRoutePopup(r) {
    var m = map();
    if (!m) return;
    S.activeId = r.id;
    renderLayers();
    var layer = S.layersById[r.id];
    var center = layer && layer.getBounds ? layer.getBounds().getCenter() : null;
    if (!center) return;
    var html =
      '<div class="tp-dev-popup"><div class="tp-dev-popup__head"><span class="tp-dev-popup__dot" style="background:' +
      (r.color || "#7c3aed") +
      '"></span><span class="tp-dev-popup__name">' +
      esc(r.name || T("route", "Ruta")) +
      '</span></div><div class="tp-dev-popup__status">' +
      (r.coordinates || []).length +
      " pts</div>" +
      (S.canEdit
        ? '<div class="tp-poi-popup__actions"><button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-r-edit>' +
          esc(T("edit", "Editar")) +
          '</button><button type="button" class="tp-btn tp-btn--sm tp-btn--danger" data-r-del>' +
          esc(T("delete", "Eliminar")) +
          "</button></div>"
        : "") +
      "</div>";
    if (!popup) popup = L.popup({ className: "tp-dev-popup-wrap", maxWidth: 280 });
    popup.setLatLng(center).setContent(html).openOn(m);
    var el = popup.getElement();
    var be = el && el.querySelector("[data-r-edit]");
    var bd = el && el.querySelector("[data-r-del]");
    if (be) be.addEventListener("click", function () { m.closePopup(); openForm("edit", r); });
    if (bd) bd.addEventListener("click", function () { m.closePopup(); del(r.id); });
  }

  // ----- Campo de color (swatch + hex + limpiar), estilo Ubimak -----
  function colorFieldHtml(value) {
    var v = value || "#1938FF";
    return (
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(T("color", "Color")) +
      '</label><div class="tp-colorfield"><input type="color" name="color" value="' +
      esc(v) +
      '"><span class="tp-colorfield__hex" data-color-hex>' +
      esc(v.toLowerCase()) +
      '</span><button type="button" class="tp-colorfield__clear" data-color-clear title="' +
      esc(T("clear", "Limpiar")) +
      '">&times;</button></div></div>'
    );
  }
  function groupSelectHtml(r) {
    var opts =
      '<option value="0">' +
      esc(T("ungrouped", "Sin grupo")) +
      "</option>" +
      S.groups
        .map(function (gr) {
          return (
            '<option value="' +
            gr.id +
            '"' +
            (r && String(r.group_id || 0) === String(gr.id) ? " selected" : "") +
            ">" +
            esc(gr.title) +
            "</option>"
          );
        })
        .join("");
    return (
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(T("group", "Grupo")) +
      '</label><div class="tp-route-groupsel"><select name="group_id" class="tp-input" data-route-group>' +
      opts +
      "</select>" +
      (S.canGroups
        ? '<button type="button" class="tp-btn tp-btn--accent tp-btn--icon" data-route-group-new title="' +
          esc(T("add_group", "Nuevo grupo")) +
          '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></button>'
        : "") +
      "</div></div>"
    );
  }
  function metaBody(r) {
    return (
      '<form id="tp-route-form" class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(T("name", "Nombre")) +
      '</label><input type="text" name="name" class="tp-input" placeholder="' +
      esc(T("insert_name", "Insertar nombre…")) +
      '" value="' +
      esc(r ? r.name : "") +
      '"></div>' +
      colorFieldHtml(r && r.color) +
      groupSelectHtml(r) +
      "</form>"
    );
  }
  function wireColorField(dialog) {
    var inp = dialog.querySelector('input[type="color"][name="color"]');
    var hex = dialog.querySelector("[data-color-hex]");
    var clr = dialog.querySelector("[data-color-clear]");
    if (inp && hex)
      inp.addEventListener("input", function () {
        hex.textContent = inp.value.toLowerCase();
      });
    if (clr && inp && hex)
      clr.addEventListener("click", function () {
        inp.value = "#1938FF";
        hex.textContent = "#1938ff";
      });
  }
  function wireGroupNew(dialog, r) {
    var btn = dialog.querySelector("[data-route-group-new]");
    if (!btn) return;
    btn.addEventListener("click", function () {
      openGroupModal(null, function (newId) {
        // refresca el select del formulario de ruta y selecciona el grupo nuevo
        var sel = dialog.querySelector("[data-route-group]");
        if (!sel) return;
        var tmp = document.createElement("div");
        tmp.innerHTML = groupSelectHtml(r ? Object.assign({}, r, { group_id: newId }) : { group_id: newId });
        var fresh = tmp.querySelector("[data-route-group]");
        if (fresh) sel.innerHTML = fresh.innerHTML;
        sel.value = String(newId);
        // tp-select: re-leer las <option> tras cambiarlas por JS
        if (window.tpSelect) tpSelect.refresh(sel);
      });
    });
  }
  function readMeta(dialog) {
    var f = dialog.querySelector("#tp-route-form");
    var name = (f.querySelector('[name="name"]').value || "").trim();
    if (!name) {
      window.tpModal && tpModal.toast && tpModal.toast(T("name_required", "El nombre es obligatorio."), "warning");
      return null;
    }
    return {
      name: name,
      color: f.querySelector('[name="color"]').value,
      group_id: parseInt(f.querySelector('[name="group_id"]').value, 10) || 0,
    };
  }

  /* =========================================================
   * Formulario ACOPLADO (crear / editar) en el sidebar, con la
   * geometría editable en el mapa al mismo tiempo (estilo Ubimak).
   * ========================================================= */
  var formMode = null; // 'create' | 'edit'
  var formRoute = null;

  function panelEl() {
    return document.getElementById("tp-panel-routes");
  }
  function formWrap() {
    return document.getElementById("tp-routes-formwrap");
  }

  function openForm(mode, r) {
    var wrap = formWrap();
    if (!wrap) return;
    formMode = mode;
    formRoute = r || null;
    var head =
      '<div class="tp-routes-form__head"><button type="button" class="tp-routes-form__back" data-rf-cancel aria-label="' +
      esc(T("cancel", "Cancelar")) +
      '">&lsaquo;</button><h4 class="tp-routes-form__title">' +
      esc(mode === "create" ? T("new_route", "Nueva ruta") : T("edit_route", "Editar ruta")) +
      "</h4></div>";
    var hint =
      '<div class="tp-routes-form__hint" data-rf-hint>' +
      esc(
        mode === "create"
          ? T("route_draw_hint2", "Dibuja la ruta en el mapa: clic para añadir puntos, doble clic para terminar.")
          : T("route_edit_hint", "Arrastra los puntos del mapa para ajustar la ruta."),
      ) +
      "</div>";
    var actions =
      '<div class="tp-routes-form__actions"><button type="button" class="tp-btn tp-btn--ghost" data-rf-cancel>' +
      esc(T("cancel", "Cancelar")) +
      '</button><button type="button" class="tp-btn tp-btn--primary" data-rf-save>' +
      esc(T("save", "Guardar")) +
      "</button></div>";
    wrap.innerHTML = head + hint + metaBody(r) + actions;
    wrap.hidden = false;
    panelEl().classList.add("is-form");

    wireColorField(wrap);
    wireGroupNew(wrap, r);
    wrap.querySelectorAll("[data-rf-cancel]").forEach(function (b) {
      b.addEventListener("click", closeForm);
    });
    wrap.querySelector("[data-rf-save]").addEventListener("click", saveForm);

    // ----- Mapa -----
    var m = map();
    disposeDraft();
    // Ocultar la capa estática para editar solo el borrador (sin duplicados).
    if (m && S.layer && m.hasLayer(S.layer)) m.removeLayer(S.layer);

    if (mode === "edit" && r) {
      S.editId = r.id;
      var pts = toLatLngs(r.coordinates);
      var layer = L.polyline(pts, { color: r.color || "#1938FF", weight: 5, opacity: 0.95 });
      S.draft = layer.addTo(m);
      if (layer.editing) {
        try {
          layer.editing.enable();
        } catch (e) {}
      }
      var b = layer.getBounds && layer.getBounds();
      if (b && b.isValid()) m.fitBounds(b, { padding: [60, 60], maxZoom: 16 });
    } else {
      S.editId = null;
      S.drawer = new L.Draw.Polyline(m, {
        shapeOptions: { color: "#1938FF", weight: 4 },
        allowIntersection: true,
      });
      S.drawer.enable();
    }
  }

  function closeForm() {
    disposeDraft();
    var wrap = formWrap();
    if (wrap) {
      wrap.hidden = true;
      wrap.innerHTML = "";
    }
    var p = panelEl();
    if (p) p.classList.remove("is-form");
    formMode = null;
    formRoute = null;
    S.editId = null;
    renderLayers(); // restaura la capa estática
    renderList();
  }

  function saveForm() {
    var wrap = formWrap();
    if (!wrap) return;
    var payload = readMeta(wrap);
    if (!payload) return;
    if (!S.draft) {
      tpModal.toast && tpModal.toast(T("draw_route_first", "Dibuja la ruta en el mapa primero."), "warning");
      return;
    }
    var coords = coordsFromLayer(S.draft);
    if (coords.length < 2) {
      tpModal.toast && tpModal.toast(T("route_min_points", "Añade al menos 2 puntos."), "warning");
      return;
    }
    payload.coordinates = coords;
    var isEdit = formMode === "edit" && formRoute;
    var url = isEdit ? BASE + "/" + formRoute.id : BASE;
    var btn = wrap.querySelector("[data-rf-save]");
    if (btn) btn.disabled = true;
    tpModal
      .fetchJSON(url, { method: isEdit ? "PUT" : "POST", json: payload })
      .then(function (res) {
        if (btn) btn.disabled = false;
        if (res && res.ok && res.data && res.data.ok) {
          tpModal.toast &&
            tpModal.toast(isEdit ? T("route_updated", "Ruta actualizada.") : T("route_created", "Ruta creada."), "success");
          closeForm();
          load(true);
        } else showError(res);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        tpModal.toast && tpModal.toast(T("network_error", "Error de red."), "error");
      });
  }
  function showError(res) {
    var msg = T("could_not_save", "No se pudo guardar.");
    if (res && res.data) {
      if (res.data.errors) {
        var k = Object.keys(res.data.errors)[0];
        var e = res.data.errors[k];
        msg = Array.isArray(e) ? e[0] : e || msg;
      } else if (res.data.message) {
        msg = res.data.message;
      }
    }
    window.tpModal && tpModal.toast && tpModal.toast(msg, "error");
  }

  function del(id) {
    if (!window.tpModal) return;
    var doDel = function () {
      tpModal.fetchJSON(BASE + "/" + id, { method: "DELETE" }).then(function (res) {
        if (res && res.ok && res.data && res.data.ok) {
          tpModal.toast && tpModal.toast(T("route_deleted", "Ruta eliminada."), "success");
          load(true);
        } else showError(res);
      });
    };
    if (tpModal.confirm)
      tpModal
        .confirm({
          title: T("delete_route", "Eliminar ruta"),
          message: T("delete_route_confirm", "¿Eliminar esta ruta?"),
          confirmLabel: T("delete", "Eliminar"),
          danger: true,
        })
        .then(function (ok) {
          if (ok) doDel();
        });
    else doDel();
  }

  // ----- Visibilidad (checkbox) -----
  function toggleActive(id, on) {
    var r = S.byId[id];
    if (r) r.active = on; // optimista
    renderLayers();
    tpModal
      .fetchJSON(BASE + "/" + id + "/toggle", { method: "POST", json: { active: on ? 1 : 0 } })
      .then(function (res) {
        if (!(res && res.ok && res.data && res.data.ok)) {
          if (r) r.active = !on; // revertir
          renderList();
          renderLayers();
          showError(res);
        }
      })
      .catch(function () {
        if (r) r.active = !on;
        renderList();
        renderLayers();
      });
  }

  /* =========================================================
   * Modal de GRUPO (crear / editar): título + selección de rutas
   * ========================================================= */
  function openGroupModal(group, onSaved) {
    if (!window.tpModal) return;
    var isEdit = !!group;
    var checkedIds = {};
    if (isEdit)
      S.items.forEach(function (r) {
        if ((r.group_id || 0) === group.id) checkedIds[r.id] = true;
      });

    var body =
      '<div class="tp-rgroup-form">' +
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(T("title", "Título")) +
      '</label><input type="text" class="tp-input" data-grp-title placeholder="' +
      esc(T("insert_title", "Insertar título…")) +
      '" value="' +
      esc(isEdit ? group.title : "") +
      '"></div>' +
      '<div class="tp-field"><div class="tp-rgroup-form__bar"><label class="tp-field__label" style="margin:0;">' +
      esc(T("routes", "Rutas")) +
      '</label><div class="tp-rgroup-form__sel"><button type="button" class="tp-linkbtn" data-grp-all>' +
      esc(T("select_all", "Seleccionar todo")) +
      '</button><button type="button" class="tp-linkbtn" data-grp-none>' +
      esc(T("deselect_all", "Deseleccionar todo")) +
      "</button></div></div>" +
      '<div class="tp-input-wrap tp-mt-2"><input type="text" class="tp-input tp-input--sm" data-grp-search placeholder="' +
      esc(T("search", "Buscar")) +
      '…"></div>' +
      '<div class="tp-rgroup-list" data-grp-list></div></div>' +
      "</div>";

    tpModal.open({
      title: T("routes", "Rutas"),
      size: "md",
      body: body,
      buttons: [
        { label: T("cancel", "Cancelar"), variant: "ghost" },
        {
          label: T("save", "Guardar"),
          variant: "primary",
          close: false,
          onClick: function (dialog, btn) {
            var title = (dialog.querySelector("[data-grp-title]").value || "").trim();
            if (!title) {
              tpModal.toast && tpModal.toast(T("title_required", "El título es obligatorio."), "warning");
              return false;
            }
            var ids = [];
            dialog.querySelectorAll("[data-grp-route]:checked").forEach(function (c) {
              ids.push(parseInt(c.value, 10));
            });
            btn.disabled = true;
            var url = isEdit ? GROUPS_BASE + "/" + group.id : GROUPS_BASE;
            tpModal
              .fetchJSON(url, { method: isEdit ? "PUT" : "POST", json: { title: title, routes: ids } })
              .then(function (res) {
                btn.disabled = false;
                if (res && res.ok && res.data && res.data.ok) {
                  tpModal.toast && tpModal.toast(T("changes_saved", "Cambios guardados."), "success");
                  tpModal.close(dialog);
                  var newId = res.data.id;
                  load(true, function () {
                    if (typeof onSaved === "function") onSaved(newId);
                  });
                } else showError(res);
              })
              .catch(function () {
                btn.disabled = false;
                tpModal.toast && tpModal.toast(T("network_error", "Error de red."), "error");
              });
            return false;
          },
        },
      ],
      onMount: function (dialog) {
        var listEl = dialog.querySelector("[data-grp-list]");
        var renderRoutes = function (q) {
          q = (q || "").toLowerCase();
          listEl.innerHTML = S.items
            .filter(function (r) {
              return !q || (r.name || "").toLowerCase().indexOf(q) !== -1;
            })
            .map(function (r) {
              return (
                '<label class="tp-rgroup-item"><input type="checkbox" data-grp-route value="' +
                r.id +
                '"' +
                (checkedIds[r.id] ? " checked" : "") +
                '><span class="tp-zone-row__swatch" style="background:' +
                (r.color || "#7c3aed") +
                '"></span><span>' +
                esc(r.name || "#" + r.id) +
                "</span></label>"
              );
            })
            .join("") || '<div class="tp-text-xs tp-text-muted" style="padding:8px;">' + esc(T("no_routes", "No hay rutas.")) + "</div>";
        };
        renderRoutes("");
        // recuerda selección al filtrar
        listEl.addEventListener("change", function (e) {
          var c = e.target.closest("[data-grp-route]");
          if (c) checkedIds[parseInt(c.value, 10)] = c.checked;
        });
        var s = dialog.querySelector("[data-grp-search]");
        if (s) s.addEventListener("input", function () { renderRoutes(s.value); });
        var all = dialog.querySelector("[data-grp-all]");
        if (all)
          all.addEventListener("click", function () {
            listEl.querySelectorAll("[data-grp-route]").forEach(function (c) {
              c.checked = true;
              checkedIds[parseInt(c.value, 10)] = true;
            });
          });
        var none = dialog.querySelector("[data-grp-none]");
        if (none)
          none.addEventListener("click", function () {
            listEl.querySelectorAll("[data-grp-route]").forEach(function (c) {
              c.checked = false;
              checkedIds[parseInt(c.value, 10)] = false;
            });
          });
      },
    });
  }

  function delGroup(g) {
    if (!window.tpModal) return;
    tpModal
      .confirm({
        title: T("delete_group", "Eliminar grupo"),
        message: T("delete_group_confirm", "¿Eliminar el grupo? Las rutas quedarán sin grupo."),
        confirmLabel: T("delete", "Eliminar"),
        danger: true,
      })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON(GROUPS_BASE + "/" + g.id, { method: "DELETE" }).then(function (res) {
          if (res && res.ok && res.data && res.data.ok) {
            tpModal.toast && tpModal.toast(T("changes_saved", "Cambios guardados."), "success");
            load(true);
          } else showError(res);
        });
      });
  }

  /* =========================================================
   * Export / Import (reusa endpoints legacy)
   * ========================================================= */
  function openExportModal() {
    if (!window.tpModal || !EXPORT_URL) return;
    var formats = { csv: "CSV", gexp: "GEXP", kml: "KML" };
    var types = {
      export_single: T("export_single", "Exportar uno solo"),
      export_active: T("export_active", "Exportar activas"),
      export_inactive: T("export_inactive", "Exportar inactivas"),
      export_groups: T("export_groups", "Exportar grupos"),
    };
    var opt = function (obj, sel) {
      return Object.keys(obj)
        .map(function (k) {
          return '<option value="' + k + '"' + (k === sel ? " selected" : "") + ">" + esc(obj[k]) + "</option>";
        })
        .join("");
    };
    var routesChecks = S.items
      .map(function (r) {
        return (
          '<label class="tp-rgroup-item"><input type="checkbox" data-exp-route value="' +
          r.id +
          '"><span class="tp-zone-row__swatch" style="background:' +
          (r.color || "#7c3aed") +
          '"></span><span>' +
          esc(r.name || "#" + r.id) +
          "</span></label>"
        );
      })
      .join("");
    var body =
      '<div class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(T("format", "Formato")) +
      '</label><select class="tp-input" data-exp-format>' +
      opt(formats, "csv") +
      "</select></div>" +
      '<div class="tp-field"><label class="tp-field__label">' +
      esc(T("export_type_label", "Tipo de exportación")) +
      '</label><select class="tp-input" data-exp-type>' +
      opt(types, "export_single") +
      "</select></div>" +
      '<div class="tp-field" data-exp-routes-wrap><label class="tp-field__label">' +
      esc(T("routes", "Rutas")) +
      '</label><div class="tp-rgroup-list">' +
      routesChecks +
      "</div></div></div>";

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
          onClick: function (dialog) {
            var fmt = dialog.querySelector("[data-exp-format]").value;
            var type = dialog.querySelector("[data-exp-type]").value;
            var ids = [];
            dialog.querySelectorAll("[data-exp-route]:checked").forEach(function (c) {
              ids.push(c.value);
            });
            submitExport(fmt, type, ids);
            tpModal.close(dialog);
            return false;
          },
        },
      ],
      onMount: function (dialog) {
        var typeSel = dialog.querySelector("[data-exp-type]");
        var wrap = dialog.querySelector("[data-exp-routes-wrap]");
        var sync = function () {
          wrap.hidden = typeSel.value !== "export_single";
        };
        typeSel.addEventListener("change", sync);
        sync();
      },
    });
  }
  // Descarga real: form POST al endpoint legacy (el navegador baja el archivo).
  function submitExport(format, type, ids) {
    var form = document.createElement("form");
    form.method = "POST";
    form.action = EXPORT_URL;
    form.style.display = "none";
    var add = function (name, value) {
      var i = document.createElement("input");
      i.type = "hidden";
      i.name = name;
      i.value = value;
      form.appendChild(i);
    };
    add("_token", csrf());
    add("export_format", format);
    add("export_type", type);
    (ids || []).forEach(function (id) {
      add("routes[]", id);
    });
    document.body.appendChild(form);
    form.submit();
    setTimeout(function () {
      if (form.parentNode) form.parentNode.removeChild(form);
    }, 1000);
  }

  function openImportModal() {
    if (!window.tpModal || !IMPORT_URL) return;
    var body =
      '<div class="tp-poi-form"><div class="tp-field"><label class="tp-field__label">' +
      esc(T("file", "Archivo")) +
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
          onClick: function (dialog, btn) {
            var input = dialog.querySelector("[data-imp-file]");
            if (!input.files || !input.files.length) {
              tpModal.toast && tpModal.toast(T("select_file", "Selecciona un archivo."), "warning");
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
              .then(function (r) {
                return r.json().then(function (j) {
                  return { ok: r.ok, data: j };
                });
              })
              .then(function (res) {
                btn.disabled = false;
                if (res.ok && res.data && res.data.status == 1) {
                  tpModal.toast && tpModal.toast(res.data.message || T("changes_saved", "Cambios guardados."), "success");
                  tpModal.close(dialog);
                  load(true);
                } else {
                  var errs = res.data && res.data.errors;
                  tpModal.toast &&
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
                tpModal.toast && tpModal.toast(T("network_error", "Error de red."), "error");
              });
            return false;
          },
        },
      ],
    });
  }

  /* =========================================================
   * Dibujo / edición de geometría con leaflet-draw (el formulario
   * está acoplado en el sidebar; aquí solo se gestiona el borrador).
   * ========================================================= */
  function disposeDraft() {
    var m = map();
    if (S.drawer) {
      try {
        S.drawer.disable();
      } catch (e) {}
      S.drawer = null;
    }
    if (S.draft && m) {
      try {
        if (S.draft.editing && S.draft.editing.disable) S.draft.editing.disable();
      } catch (e) {}
      try {
        m.removeLayer(S.draft);
      } catch (e) {}
    }
    S.draft = null;
  }
  function coordsFromLayer(layer) {
    var pts = layer.getLatLngs();
    if (pts.length && Array.isArray(pts[0])) pts = pts[0];
    return pts.map(function (p) {
      return { lat: p.lat, lng: p.lng };
    });
  }
  // Al terminar de dibujar (modo crear): convierte el trazo en un borrador
  // editable (vértices arrastrables) SIN cerrar el formulario acoplado.
  function bindDrawCreated() {
    var m = map();
    if (!m || m.__routeDrawBound) return;
    m.__routeDrawBound = true;
    m.on(L.Draw.Event.CREATED, function (e) {
      if (formMode !== "create") return;
      if (e.layerType !== "polyline") return;
      if (S.drawer) {
        try {
          S.drawer.disable();
        } catch (err) {}
        S.drawer = null;
      }
      S.draft = e.layer;
      S.draft.setStyle && S.draft.setStyle({ color: "#1938FF", weight: 4 });
      S.draft.addTo(m);
      if (S.draft.editing) {
        try {
          S.draft.editing.enable();
        } catch (err) {}
      }
      var hint = document.querySelector("#tp-routes-formwrap [data-rf-hint]");
      if (hint) hint.textContent = T("route_adjust_hint", "Ajusta los puntos y pulsa Guardar. Doble clic en un punto lo elimina.");
    });
  }

  // ----- Carga -----
  function load(force, cb) {
    if (S.loaded && !force) {
      renderList();
      renderLayers();
      if (cb) cb();
      return;
    }
    fetch(BASE, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        S.items = (j && j.items) || [];
        S.groups = (j && j.groups) || [];
        S.canEdit = !!(j && j.can_edit);
        S.canGroups = !!(j && j.can_groups);
        S.byId = {};
        S.items.forEach(function (r) {
          S.byId[r.id] = r;
        });
        S.loaded = true;
        renderList();
        renderLayers();
        settleRedraw();
        if (cb) cb();
      })
      .catch(function () {});
  }

  // Fix 1ª carga: el fitBounds inicial de la flota mueve la vista justo cuando
  // se añaden estas polilíneas (SVG) y a veces no se pintan hasta interactuar →
  // re-render UNA vez al asentarse el mapa.
  var _settled = false;
  function settleRedraw() {
    if (_settled) return;
    _settled = true;
    var m = map();
    if (!m) return;
    // Quitar + re-agregar la capa (idéntico al toggle manual, que sí redibuja).
    var redo = function () {
      if (S.layer && m.hasLayer(S.layer)) m.removeLayer(S.layer);
      renderLayers();
    };
    m.once("moveend zoomend", redo);
    setTimeout(redo, 500);
  }

  // ----- Wiring del panel -----
  var wired = false;
  function wire() {
    if (wired) return;
    wired = true;
    var list = document.getElementById("tp-routes-list");
    if (list)
      list.addEventListener("click", function (e) {
        // Cabeceras de grupo
        var head = e.target.closest(".tp-rgrp__head");
        if (head) {
          var key = head.getAttribute("data-grp");
          if (e.target.closest("[data-grp-edit]")) {
            var gid = parseInt(key, 10);
            var g = S.groups.filter(function (x) { return x.id === gid; })[0];
            if (g) openGroupModal(g);
            return;
          }
          if (e.target.closest("[data-grp-del]")) {
            var gid2 = parseInt(key, 10);
            var g2 = S.groups.filter(function (x) { return x.id === gid2; })[0];
            if (g2) delGroup(g2);
            return;
          }
          // toggle colapsar
          var ck = key === "un" ? "un" : parseInt(key, 10);
          S.collapsed[ck] = !S.collapsed[ck];
          renderList();
          return;
        }
        // Filas de ruta
        var row = e.target.closest(".tp-poi-row");
        if (!row) return;
        var r = S.byId[parseInt(row.getAttribute("data-id"), 10)];
        if (!r) return;
        if (e.target.closest("[data-r-active]")) return; // lo maneja 'change'
        if (e.target.closest("[data-r-edit]")) return openForm("edit", r);
        if (e.target.closest("[data-r-del]")) return del(r.id);
        S.activeId = r.id;
        renderLayers();
        flyTo(r);
        if (r.active) openRoutePopup(r);
      });
    if (list)
      list.addEventListener("change", function (e) {
        var chk = e.target.closest("[data-r-active]");
        if (!chk) return;
        var row = chk.closest(".tp-poi-row");
        var r = row && S.byId[parseInt(row.getAttribute("data-id"), 10)];
        if (r) toggleActive(r.id, chk.checked);
      });
    var search = document.getElementById("tp-routes-search");
    if (search)
      search.addEventListener("input", function () {
        S.q = search.value || "";
        renderList();
      });
    var fold = document.getElementById("tp-routes-fold");
    if (fold)
      fold.addEventListener("click", function () {
        S.grouped = !S.grouped;
        renderList();
      });
    var table = document.getElementById("tp-routes-table");
    if (table)
      table.addEventListener("click", function () {
        window.location.href = TABLE_URL;
      });
    var nw = document.getElementById("tp-routes-new");
    if (nw) nw.addEventListener("click", function () { openForm("create", null); });
    var exp = document.getElementById("tp-routes-export");
    if (exp) exp.addEventListener("click", openExportModal);
    var imp = document.getElementById("tp-routes-import");
    if (imp) imp.addEventListener("click", openImportModal);
  }

  // ----- API para live.js -----
  window.tpLiveRoutes = {
    activate: function () {
      wire();
      bindDrawCreated();
      S.visible = true;
      load(false);
    },
    setVisible: function (on) {
      var m = map();
      S.visible = !!on;
      if (on) {
        wire();
        bindDrawCreated();
        if (S.loaded) renderLayers();
        else load(true);
      } else if (m && S.layer) {
        m.removeLayer(S.layer);
      }
    },
  };

  // Deep-link ?panel=routes.
  var rp = document.getElementById("tp-panel-routes");
  if (rp && !rp.hidden) window.tpLiveRoutes.activate();
})();
