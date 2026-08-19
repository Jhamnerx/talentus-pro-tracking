/* ============================================================
 * UI v2 · Panel de POIs sobre el mapa Leaflet compartido (live.js)
 * ------------------------------------------------------------
 * Marcadores L.marker con el icono real del POI, arrastrables (mover →
 * persiste coords). CRUD vía endpoints v2 (MapLayersController/PoiUserService),
 * modal server-rendered (ui.v2.modals.poi-form). Reusa el motor del legacy poi.js.
 * ============================================================ */
(function () {
  "use strict";
  var root = document.getElementById("tp-live");
  if (!root || !window.L) return;

  var BASE = root.getAttribute("data-pois-url") || "/ui/v2/api/pois";

  var S = {
    loaded: false,
    items: [],
    groups: [],
    byId: {},
    layer: null,
    markersById: {},
    q: "",
    groupId: "",
    collapsed: {},
    placeMode: false,
    clickBound: false,
    canEdit: !!document.getElementById("tp-pois-new"),
  };

  function map() {
    return window.tpLive && window.tpLive.map();
  }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function assetPath(p) {
    if (!p) return "";
    if (/^https?:\/\//.test(p) || p.charAt(0) === "/") return p;
    return "/" + p;
  }

  // ----- Icono Leaflet del POI -----
  function poiIcon(p) {
    var icon = p.icon || null;
    if (icon && icon.path) {
      var w = icon.width || 28,
        h = icon.height || 28,
        max = Math.max(w, h),
        sc = max > 40 ? 40 / max : 1;
      w = Math.round(w * sc);
      h = Math.round(h * sc);
      return L.icon({ iconUrl: assetPath(icon.path), iconSize: [w, h], iconAnchor: [w / 2, h / 2] });
    }
    return L.divIcon({ className: "tp-poi-ldot", html: '<span class="tp-poi-marker__dot"></span>', iconSize: [14, 14], iconAnchor: [7, 7] });
  }

  function filtered() {
    var q = S.q.toLowerCase();
    return S.items.filter(function (p) {
      if (S.groupId && String(p.group_id || 0) !== String(S.groupId)) return false;
      if (q && (p.name || "").toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  }

  function renderMarkers() {
    var m = map();
    if (!m) return;
    if (!S.layer) S.layer = L.layerGroup();
    if (!m.hasLayer(S.layer)) S.layer.addTo(m);
    S.layer.clearLayers();
    S.markersById = {};
    filtered().forEach(function (p) {
      if (p.lat == null || p.lng == null) return;
      if (p.active === false) return; // POIs desactivados no se pintan (paridad legacy)
      var mk = L.marker([p.lat, p.lng], { icon: poiIcon(p), draggable: S.canEdit });
      mk.on("click", function () {
        openPopup(p);
      });
      if (S.canEdit)
        mk.on("dragend", function () {
          var ll = mk.getLatLng();
          updateCoords(p.id, ll);
        });
      mk.addTo(S.layer);
      S.markersById[p.id] = mk;
    });
  }

  function updateCoords(id, ll) {
    if (!window.tpModal) return;
    tpModal
      .fetchJSON(BASE + "/" + id, { method: "PUT", json: { coordinates: { lat: ll.lat, lng: ll.lng } } })
      .then(function (r) {
        if (r && r.ok && r.data && r.data.ok) {
          tpModal.toast && tpModal.toast("POI movido.", "success");
          var it = S.byId[id];
          if (it) {
            it.lat = ll.lat;
            it.lng = ll.lng;
          }
        } else {
          tpModal.toast && tpModal.toast("No se pudo mover el POI.", "error");
          load(true);
        }
      })
      .catch(function () {
        load(true);
      });
  }

  // ----- Popup Editar / Eliminar -----
  var popup = null;
  function openPopup(p) {
    var m = map();
    if (!m || p.lat == null) return;
    var html =
      '<div class="tp-dev-popup"><div class="tp-dev-popup__head"><span class="tp-dev-popup__name">' +
      esc(p.name || "POI") +
      "</span></div>" +
      (S.canEdit
        ? '<div class="tp-poi-popup__actions"><button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-p-edit>Editar</button>' +
          '<button type="button" class="tp-btn tp-btn--sm tp-btn--danger" data-p-del>Eliminar</button></div>'
        : "") +
      "</div>";
    if (!popup) popup = L.popup({ className: "tp-dev-popup-wrap", maxWidth: 240, offset: [0, -8] });
    popup.setLatLng([p.lat, p.lng]).setContent(html).openOn(m);
    var el = popup.getElement();
    var be = el && el.querySelector("[data-p-edit]");
    var bd = el && el.querySelector("[data-p-del]");
    if (be) be.addEventListener("click", function () { m.closePopup(); openModal({ lat: p.lat, lng: p.lng }, p.id); });
    if (bd) bd.addEventListener("click", function () { m.closePopup(); del(p.id); });
  }

  // ----- Modal de POI (form server-rendered) -----
  function openModal(coords, id) {
    if (!window.tpModal) return;
    var url = id ? BASE + "/" + id + "/form" : BASE + "/form";
    tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      if (!res || !res.ok || !res.data || !res.data.ok) {
        tpModal.toast && tpModal.toast("No se pudo cargar el formulario.", "error");
        return;
      }
      tpModal.open({
        title: id ? "Editar POI" : "Nuevo POI",
        size: "md",
        body: res.data.html,
        buttons: [
          { label: "Cancelar", variant: "ghost" },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog, btn) {
              var f = dialog.querySelector("#tp-poi-form");
              if (!f) return false;
              var icon = f.querySelector('input[name="map_icon_id"]:checked');
              var val = function (sel) {
                var el = f.querySelector(sel);
                return el ? el.value : "";
              };
              var name = val('[name="name"]').trim();
              if (!name) {
                tpModal.toast && tpModal.toast("El nombre es obligatorio.", "warning");
                return false;
              }
              var payload = {
                name: name,
                description: val('[name="description"]'),
                group_id: val('[name="group_id"]') || "0",
                map_icon_id: icon ? icon.value : null,
                coordinates: { lat: coords.lat, lng: coords.lng },
              };
              btn.disabled = true;
              tpModal
                .fetchJSON(id ? BASE + "/" + id : BASE, { method: id ? "PUT" : "POST", json: payload })
                .then(function (r) {
                  btn.disabled = false;
                  if (r && r.ok && r.data && r.data.ok) {
                    tpModal.toast && tpModal.toast(id ? "POI actualizado." : "POI creado.", "success");
                    tpModal.close(dialog);
                    load(true);
                  } else tpModal.toast && tpModal.toast("No se pudo guardar.", "error");
                })
                .catch(function () {
                  btn.disabled = false;
                  tpModal.toast && tpModal.toast("Error de red.", "error");
                });
              return false;
            },
          },
        ],
      });
    });
  }

  function del(id) {
    if (!window.tpModal) return;
    var doDel = function () {
      tpModal.fetchJSON(BASE + "/" + id, { method: "DELETE" }).then(function (r) {
        if (r && r.ok && r.data && r.data.ok) {
          tpModal.toast && tpModal.toast("POI eliminado.", "success");
          load(true);
        } else tpModal.toast && tpModal.toast("No se pudo eliminar.", "error");
      });
    };
    if (tpModal.confirm)
      tpModal
        .confirm({ title: "Eliminar POI", message: "¿Eliminar este POI?", confirmLabel: "Eliminar", danger: true })
        .then(function (ok) {
          if (ok) doDel();
        });
    else doDel();
  }

  // ----- Modo "colocar" para crear -----
  function bindPlace() {
    var m = map();
    if (!m || m.__poiPlaceBound) return;
    m.__poiPlaceBound = true;
    m.on("click", function (e) {
      if (!S.placeMode) return;
      S.placeMode = false;
      m.getContainer().style.cursor = "";
      openModal({ lat: e.latlng.lat, lng: e.latlng.lng });
    });
  }
  function startPlace() {
    var m = map();
    if (!m) return;
    setVisible(true);
    S.placeMode = true;
    m.getContainer().style.cursor = "crosshair";
    window.tpModal && tpModal.toast && tpModal.toast("Haz clic en el mapa para ubicar el POI.", "info");
  }

  function setVisible(on) {
    var m = map();
    if (on) {
      if (S.loaded) renderMarkers();
      else load(true);
    } else if (m && S.layer) {
      m.removeLayer(S.layer);
    }
    var btn = document.querySelector('.tp-live__toggle[data-toggle="poi"]');
    if (btn) btn.setAttribute("aria-pressed", on ? "true" : "false");
  }

  // ----- Lista del panel (agrupada como Objetos) -----
  var GO =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.16 7-12a7 7 0 0 0-14 0c0 4.84 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>';
  var EDIT =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>';
  var DEL =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';

  function groupTitle(gid) {
    if (!gid) return "Sin grupo";
    for (var i = 0; i < S.groups.length; i++) if (S.groups[i].id === gid) return S.groups[i].title;
    return "Grupo " + gid;
  }
  function fillGroupSelect() {
    var sel = document.getElementById("tp-pois-group");
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML =
      '<option value="">Todos los grupos</option>' +
      S.groups.map(function (g) { return '<option value="' + g.id + '">' + esc(g.title) + "</option>"; }).join("");
    sel.value = cur;
  }
  function rowHtml(p) {
    return (
      '<div class="tp-poi-row" data-id="' +
      p.id +
      '" role="option">' +
      (S.canEdit
        ? '<input type="checkbox" class="tp-zone-row__vis" data-p-vis title="Mostrar en el mapa"' + (p.active === false ? "" : " checked") + ">"
        : "") +
      '<div class="tp-poi-row__main"><div class="tp-poi-row__name">' +
      esc(p.name || "#" + p.id) +
      "</div></div><div class=\"tp-poi-row__actions\">" +
      '<button type="button" class="tp-poi-row__btn" data-p-go title="Centrar">' + GO + "</button>" +
      (S.canEdit
        ? '<button type="button" class="tp-poi-row__btn" data-p-edit title="Editar">' + EDIT + "</button>" +
          '<button type="button" class="tp-poi-row__btn tp-poi-row__btn--danger" data-p-del title="Eliminar">' + DEL + "</button>"
        : "") +
      "</div></div>"
    );
  }
  /** Visibilidad del POI (paridad legacy pois.change_active). */
  function toggleActive(p, on) {
    tpModal
      .fetchJSON(BASE + "/" + p.id + "/toggle", { method: "POST", json: { active: on ? 1 : 0 } })
      .then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast("No se pudo cambiar la visibilidad.", "error");
          renderList();
          return;
        }
        p.active = !!res.data.active;
        renderMarkers();
      })
      .catch(function () {
        tpModal.toast("Error de red.", "error");
        renderList();
      });
  }

  /* ---------- Export / Import nativos (endpoints legacy) ---------- */
  function poiIoUrls() {
    return {
      create: root.getAttribute("data-pois-export-url") || "/pois/export",
    };
  }
  function openExport() {
    var formats = { csv: "CSV", gexp: "GEXP", kml: "KML" };
    var types = {
      export_single: "Exportar seleccionados",
      export_active: "Exportar activos",
      export_inactive: "Exportar inactivos",
      export_groups: "Exportar por grupos",
    };
    var opt = function (obj) {
      return Object.keys(obj).map(function (k) { return '<option value="' + k + '">' + esc(obj[k]) + "</option>"; }).join("");
    };
    var checks = S.items
      .map(function (p) { return '<label class="tp-zexp-item"><input type="checkbox" data-exp-id value="' + p.id + '"><span>' + esc(p.name) + "</span></label>"; })
      .join("");
    tpModal.open({
      title: "Exportar POIs",
      size: "md",
      body:
        '<div class="tp-field"><label class="tp-field__label">Formato</label><select class="tp-input" data-exp-format>' + opt(formats) + "</select></div>" +
        '<div class="tp-field"><label class="tp-field__label">Tipo de exportación</label><select class="tp-input" data-exp-type>' + opt(types) + "</select></div>" +
        '<div class="tp-field" data-exp-wrap><label class="tp-field__label">POIs</label><div class="tp-zexp-list">' + (checks || '<div class="tp-text-muted tp-text-sm">Sin POIs.</div>') + "</div></div>" +
        '<div class="tp-field" data-exp-gwrap hidden><label class="tp-field__label">Grupos</label><div class="tp-zexp-list">' +
        ((S.groups || []).map(function (g) {
          return '<label class="tp-zexp-item"><input type="checkbox" data-exp-group value="' + g.id + '"><span>' + esc(g.title) + "</span></label>";
        }).join("") || '<div class="tp-text-muted tp-text-sm">Sin grupos.</div>') + "</div></div>",
      buttons: [
        { label: "Cancelar", variant: "ghost" },
        {
          label: "Exportar",
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            var fmt = dlg.querySelector("[data-exp-format]").value;
            var type = dlg.querySelector("[data-exp-type]").value;
            var ids = [];
            dlg.querySelectorAll("[data-exp-id]:checked").forEach(function (c) { ids.push(c.value); });
            // SinglesPoiFilter filtra por 'pois' (ids) también en activos/
            // inactivos; sin ids exportaría TODOS.
            if (type === "export_active" || type === "export_inactive") {
              var want = type === "export_active";
              ids = (S.items || [])
                .filter(function (p2) { return (p2.active !== false) === want; })
                .map(function (p2) { return p2.id; });
            }
            var groupIds = [];
            if (type === "export_groups") {
              dlg.querySelectorAll("[data-exp-group]:checked").forEach(function (c) { groupIds.push(c.value); });
            }
            var form = document.createElement("form");
            form.method = "POST";
            form.action = poiIoUrls().create;
            form.style.display = "none";
            var add = function (n, v) {
              var i = document.createElement("input");
              i.type = "hidden"; i.name = n; i.value = v;
              form.appendChild(i);
            };
            var tok = document.querySelector('meta[name="csrf-token"]');
            if (tok) add("_token", tok.getAttribute("content"));
            add("export_type", type);
            add("export_format", fmt);
            ids.forEach(function (id) { add("pois[]", id); });
            groupIds.forEach(function (id) { add("groups[]", id); });
            document.body.appendChild(form);
            form.submit();
            setTimeout(function () { form.remove(); }, 1000);
            tpModal.close(dlg);
            return false;
          },
        },
      ],
      onMount: function (dlg) {
        var sel = dlg.querySelector("[data-exp-type]");
        var wrap = dlg.querySelector("[data-exp-wrap]");
        var gwrap = dlg.querySelector("[data-exp-gwrap]");
        var sync = function () {
          wrap.hidden = sel.value !== "export_single";
          if (gwrap) gwrap.hidden = sel.value !== "export_groups";
        };
        sel.addEventListener("change", sync);
        sync();
      },
    });
  }
  function openImport() {
    // El importador legacy exige map_icon_id (icono por defecto de los POIs).
    var icons = (S.icons || []).map(function (ic) {
      return '<option value="' + ic.id + '">' + esc(ic.name || ("Icono " + ic.id)) + "</option>";
    }).join("");
    tpModal.open({
      title: "Importar POIs",
      size: "sm",
      body:
        '<div class="tp-field"><label class="tp-field__label">Archivo</label>' +
        '<input type="file" class="tp-input" data-imp-file accept=".csv,.gexp,.kml"></div>' +
        '<div class="tp-field"><label class="tp-field__label">Icono por defecto</label>' +
        '<select class="tp-input" data-imp-icon>' + icons + "</select></div>" +
        '<div class="tp-field__hint">Formatos: CSV, GEXP o KML.</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost" },
        {
          label: "Importar",
          variant: "primary",
          close: false,
          onClick: function (dlg, btn) {
            var f = dlg.querySelector("[data-imp-file]").files[0];
            var iconSel = dlg.querySelector("[data-imp-icon]");
            if (!f) { tpModal.toast("Elige un archivo.", "warning"); return false; }
            if (!iconSel || !iconSel.value) { tpModal.toast("Elige un icono por defecto.", "warning"); return false; }
            var fd = new FormData();
            fd.append("file", f);
            fd.append("map_icon_id", iconSel.value);
            btn.disabled = true;
            tpModal
              .fetchJSON(BASE + "/import", { method: "POST", body: fd })
              .then(function (r) {
                btn.disabled = false;
                if (r && r.ok && r.data && r.data.ok) {
                  tpModal.toast((r.data.message) || "POIs importados.", "success");
                  tpModal.close(dlg);
                  load(true);
                } else {
                  tpModal.toast((r.data && (r.data.message || r.data.error)) || "No se pudo importar.", "error");
                }
              })
              .catch(function () { btn.disabled = false; tpModal.toast("Error de red.", "error"); });
            return false;
          },
        },
      ],
    });
  }
  window.tpLivePoisIO = { exportModal: openExport, importModal: openImport };

  function renderList() {
    var list = document.getElementById("tp-pois-list");
    if (!list) return;
    var rows = filtered();
    var count = document.getElementById("tp-pois-count");
    if (count) count.textContent = rows.length ? "· " + rows.length : "";
    var empty = document.getElementById("tp-pois-empty");
    if (empty) empty.hidden = rows.length > 0;
    var byG = {};
    rows.forEach(function (p) {
      var g = p.group_id || 0;
      (byG[g] = byG[g] || []).push(p);
    });
    var order = [];
    S.groups.forEach(function (g) {
      if (byG[g.id]) order.push(g.id);
    });
    Object.keys(byG).forEach(function (k) {
      var g = parseInt(k, 10);
      if (order.indexOf(g) === -1) order.push(g);
    });
    var html = "";
    order.forEach(function (g) {
      var collapsed = !!S.collapsed[g];
      html +=
        '<div class="tp-live__group" data-pgid="' +
        g +
        '"' +
        (collapsed ? ' data-collapsed="1"' : "") +
        '><svg class="tp-live__group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg><span class="tp-live__group-title">' +
        esc(groupTitle(g)) +
        '</span><span class="tp-live__group-count">' +
        byG[g].length +
        "</span></div>";
      if (!collapsed) html += byG[g].map(rowHtml).join("");
    });
    list.innerHTML = html;
  }

  function flyTo(p) {
    var m = map();
    if (m && p.lat != null) m.setView([p.lat, p.lng], Math.max(m.getZoom(), 15));
  }

  function load(force) {
    if (S.loaded && !force) {
      renderList();
      renderMarkers();
      return;
    }
    fetch(BASE, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        S.items = (j && j.items) || [];
        S.groups = (j && j.groups) || [];
        S.icons = (j && j.map_icons) || [];
        S.byId = {};
        S.items.forEach(function (p) {
          S.byId[p.id] = p;
        });
        S.loaded = true;
        fillGroupSelect();
        renderList();
        renderMarkers();
        settleRedraw();
      })
      .catch(function () {});
  }

  // Fix 1ª carga: el fitBounds inicial de la flota puede mover la vista justo
  // cuando se añaden estos marcadores → re-render una vez al asentarse el mapa.
  var _settled = false;
  function settleRedraw() {
    if (_settled) return;
    _settled = true;
    var m = map();
    if (!m) return;
    // Quitar + re-agregar la capa (idéntico al toggle manual, que sí redibuja).
    var redo = function () {
      if (S.layer && m.hasLayer(S.layer)) m.removeLayer(S.layer);
      renderMarkers();
    };
    m.once("moveend zoomend", redo);
    setTimeout(redo, 500);
  }

  var wired = false;
  function wire() {
    if (wired) return;
    wired = true;
    var list = document.getElementById("tp-pois-list");
    if (list)
      list.addEventListener("click", function (e) {
        var gh = e.target.closest(".tp-live__group");
        if (gh) {
          var g = parseInt(gh.getAttribute("data-pgid"), 10);
          S.collapsed[g] = !S.collapsed[g];
          renderList();
          return;
        }
        var row = e.target.closest(".tp-poi-row");
        if (!row) return;
        var p = S.byId[parseInt(row.getAttribute("data-id"), 10)];
        if (!p) return;
        if (e.target.closest("[data-p-vis]")) return toggleActive(p, e.target.checked);
        if (e.target.closest("[data-p-edit]")) return openModal({ lat: p.lat, lng: p.lng }, p.id);
        if (e.target.closest("[data-p-del]")) return del(p.id);
        setVisible(true);
        flyTo(p);
        openPopup(p);
      });
    var search = document.getElementById("tp-pois-search");
    if (search)
      search.addEventListener("input", function () {
        S.q = search.value || "";
        renderList();
        renderMarkers();
      });
    var grp = document.getElementById("tp-pois-group");
    if (grp)
      grp.addEventListener("change", function () {
        S.groupId = grp.value || "";
        renderList();
        renderMarkers();
      });
    var nw = document.getElementById("tp-pois-new");
    if (nw) nw.addEventListener("click", startPlace);
    var add = document.getElementById("tp-live-add-poi");
    if (add) add.addEventListener("click", startPlace);
  }

  window.tpLivePois = {
    activate: function () {
      wire();
      bindPlace();
      load(false);
    },
    setVisible: setVisible,
  };

  var pp = document.getElementById("tp-panel-pois");
  if (pp && !pp.hidden) window.tpLivePois.activate();
})();
