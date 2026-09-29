/* ============================================================
 * UI v2 · Panel de GEOCERCAS sobre el mapa Leaflet compartido (live.js)
 * ------------------------------------------------------------
 * Reusa el motor Leaflet del workspace (window.tpLive.map()) y leaflet-draw
 * para crear/editar geocercas con edición de vértices + punto medio (como el
 * legacy geofence.js/geofences.js). CRUD vía los endpoints v2 (ZonesController
 * → GeofenceUserService). Se activa al abrir el panel "Geocercas".
 * ============================================================ */
(function () {
  "use strict";
  var root = document.getElementById("tp-live");
  if (!root || !window.L) return;

  var BASE = root.getAttribute("data-zones-url") || "/ui/v2/api/zones";

  var S = {
    loaded: false,
    items: [],
    groups: [],
    vehicleTypes: [],
    speedPlugin: false,
    byId: {},
    layer: null, // L.layerGroup de geocercas guardadas
    layersById: {}, // id → leaflet layer
    type: "all",
    q: "",
    groupId: "",
    activeId: null,
    // edición/dibujo
    drawer: null, // L.Draw.* en curso
    draft: null, // layer en edición (nuevo o existente)
    editId: null, // id si se edita la forma de uno existente
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
    return (
      (window.Talentus && window.Talentus.csrf) ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ""
    );
  }
  function parseLatLng(c) {
    if (!c) return null;
    if (typeof c === "string") {
      var p = c.split(",");
      return { lat: parseFloat(p[0]), lng: parseFloat(p[1]) };
    }
    if (c.lat != null) return { lat: +c.lat, lng: +c.lng };
    if (c.y != null) return { lat: +c.y, lng: +c.x };
    if (Array.isArray(c)) return { lat: +c[0], lng: +c[1] };
    return null;
  }
  function normCoords(coords) {
    return (coords || [])
      .map(function (c) {
        if (Array.isArray(c)) return { lat: +c[0], lng: +c[1] };
        if (c && c.lat != null) return { lat: +c.lat, lng: +c.lng };
        if (c && c.y != null) return { lat: +c.y, lng: +c.x };
        return null;
      })
      .filter(Boolean);
  }

  // ----- Capa Leaflet de una geocerca guardada -----
  function buildLayerFor(g) {
    var style = {
      color: g.polygon_color || "#1e88e5",
      weight: g.id === S.activeId ? 3 : 2,
      opacity: g.active ? 1 : 0.55,
      fill: true,
      fillColor: g.polygon_color || "#1e88e5",
      fillOpacity: g.id === S.activeId ? 0.4 : 0.18,
    };
    if (g.type === "circle") {
      var c = parseLatLng(g.center);
      if (!c || !g.radius) return null;
      return L.circle([c.lat, c.lng], Object.assign({ radius: Number(g.radius) }, style));
    }
    if (g.type === "line") {
      var eje = normCoords(g.coordinates);
      if (eje.length < 2 || !g.radius) return null;
      // Una linea con extremos y uniones redondos ES el buffer de la linea:
      // basta con darle el grosor que corresponde a su anchura real (lo hace
      // updateCorridorWeights, que depende del zoom).
      var corredor = L.polyline(
        eje.map(function (pt) {
          return [pt.lat, pt.lng];
        }),
        Object.assign({}, style, { fill: false, lineCap: "round", lineJoin: "round" }),
      );
      corredor.corridorRadius = Number(g.radius);
      return corredor;
    }
    var coords = normCoords(g.coordinates);
    if (coords.length < 3) return null;
    return L.polygon(
      coords.map(function (p) {
        return [p.lat, p.lng];
      }),
      style,
    );
  }

  /**
   * Grosor de los corredores: los metros por pixel cambian con el zoom, asi
   * que hay que recalcularlo cada vez que se mueve.
   */
  function updateCorridorWeights() {
    var m = map();
    if (!m) return;
    var centro = m.getCenter();
    var a = m.latLngToLayerPoint(centro);
    var metrosPor100px = m.distance(centro, m.layerPointToLatLng(L.point(a.x + 100, a.y)));
    if (!metrosPor100px) return;
    Object.keys(S.layersById).forEach(function (id) {
      var capa = S.layersById[id];
      if (!capa || !capa.corridorRadius) return;
      capa.setStyle({ weight: (capa.corridorRadius * 2) / (metrosPor100px / 100) });
    });
  }

  function renderLayers() {
    var m = map();
    if (!m) return;
    if (!S.layer) S.layer = L.layerGroup();
    if (!m.hasLayer(S.layer)) S.layer.addTo(m);
    S.layer.clearLayers();
    S.layersById = {};
    S.filtered().forEach(function (g) {
      var layer = buildLayerFor(g);
      if (!layer) return;
      layer._zid = g.id;
      layer.on("click", function () {
        openZonePopup(g);
      });
      S.layer.addLayer(layer);
      S.layersById[g.id] = layer;
    });

    updateCorridorWeights();

    // Una sola vez: el grosor del corredor depende del zoom
    if (!m.__zoneCorridorBound) {
      m.__zoneCorridorBound = true;
      m.on("zoomend", updateCorridorWeights);
    }
  }

  S.filtered = function () {
    var q = S.q.toLowerCase();
    return S.items.filter(function (g) {
      if (S.type !== "all" && g.type !== S.type) return false;
      if (S.groupId && String(g.group_id || 0) !== String(S.groupId)) return false;
      if (q && (g.name || "").toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  };

  // ----- Lista del panel -----
  var GO =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.16 7-12a7 7 0 0 0-14 0c0 4.84 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>';
  var EDIT =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>';
  var DEL =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';

  function renderList() {
    var list = document.getElementById("tp-zones-list");
    if (!list) return;
    var rows = S.filtered();
    var count = document.getElementById("tp-zones-count");
    if (count) count.textContent = rows.length ? "· " + rows.length : "";
    var empty = document.getElementById("tp-zones-empty");
    if (empty) empty.hidden = rows.length > 0;
    list.innerHTML = rows
      .map(function (g) {
        var meta =
          g.type === "circle"
            ? tpT('ui.circle_dot') + (g.radius ? Math.round(g.radius) + " m" : "—")
            : tpT('ui.polygon_dot') + (g.coordinates || []).length + " pts";
        return (
          '<div class="tp-poi-row" data-id="' +
          g.id +
          '" role="option"><input type="checkbox" class="tp-zone-row__vis" data-z-vis title="' + tpT('ui.show_on_map') + '"' +
          (g.active ? " checked" : "") +
          '><span class="tp-zone-row__swatch" style="background:' +
          (g.polygon_color || "#1e88e5") +
          '"></span><div class="tp-poi-row__main"><div class="tp-poi-row__name">' +
          esc(g.name || "#" + g.id) +
          '</div><div class="tp-poi-row__group">' +
          meta +
          '</div></div><div class="tp-poi-row__actions">' +
          '<button type="button" class="tp-poi-row__btn" data-z-go title="Centrar">' +
          GO +
          '</button><button type="button" class="tp-poi-row__btn" data-z-edit title="Editar">' +
          EDIT +
          '</button><button type="button" class="tp-poi-row__btn tp-poi-row__btn--danger" data-z-del title="Eliminar">' +
          DEL +
          "</button></div></div>"
        );
      })
      .join("");
  }

  function fillGroupSelect() {
    var sel = document.getElementById("tp-zones-group");
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML =
      '<option value="">' + tpT('ui.all_groups') + '</option>' +
      S.groups
        .map(function (g) {
          return '<option value="' + g.id + '">' + esc(g.title) + "</option>";
        })
        .join("");
    sel.value = cur;
  }

  function flyTo(g) {
    var m = map(),
      layer = S.layersById[g.id];
    if (!m) return;
    if (layer && layer.getBounds) {
      var b = layer.getBounds();
      if (b && b.isValid()) {
        m.fitBounds(b, { padding: [60, 60], maxZoom: 16 });
        return;
      }
    }
    if (g.type === "circle") {
      var c = parseLatLng(g.center);
      if (c) m.setView([c.lat, c.lng], 14);
    }
  }

  // ----- Popup Editar / Editar forma / Eliminar -----
  var popup = null;
  function openZonePopup(g) {
    var m = map();
    if (!m) return;
    S.activeId = g.id;
    renderLayers();
    var center = null;
    var layer = S.layersById[g.id];
    if (layer && layer.getBounds) center = layer.getBounds().getCenter();
    else if (g.type === "circle") {
      var c = parseLatLng(g.center);
      if (c) center = L.latLng(c.lat, c.lng);
    }
    if (!center) return;
    var canEdit = !!document.getElementById("tp-zones-new");
    var html =
      '<div class="tp-dev-popup"><div class="tp-dev-popup__head"><span class="tp-dev-popup__dot" style="background:' +
      (g.polygon_color || "#1e88e5") +
      '"></span><span class="tp-dev-popup__name">' +
      esc(g.name || "Geocerca") +
      '</span></div><div class="tp-dev-popup__status">' +
      (g.type === "circle"
        ? tpT('ui.circle_dot') + (g.radius ? Math.round(g.radius) + " m" : "—")
        : g.type === "line"
          ? "Corredor · " + (g.coordinates || []).length + " pts · ±" + Math.round(g.radius || 0) + " m"
          : tpT('ui.polygon_dot') + (g.coordinates || []).length + " pts") +
      "</div>" +
      (canEdit
        ? '<div class="tp-poi-popup__actions"><button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-z-edit>' + tpT('global.edit') + '</button>' +
          (g.type === "polygon"
            ? '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-z-shape>' + tpT('ui.edit_shape') + '</button>'
            : '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-z-shape>' + tpT('ui.edit_shape') + '</button>') +
          '<button type="button" class="tp-btn tp-btn--sm tp-btn--danger" data-z-del>' + tpT('global.delete') + '</button></div>'
        : "") +
      "</div>";
    if (!popup) popup = L.popup({ className: "tp-dev-popup-wrap", maxWidth: 280 });
    popup.setLatLng(center).setContent(html).openOn(m);
    var el = popup.getElement();
    var be = el && el.querySelector("[data-z-edit]");
    var bs = el && el.querySelector("[data-z-shape]");
    var bd = el && el.querySelector("[data-z-del]");
    if (be) be.addEventListener("click", function () { m.closePopup(); openMetaModal(g); });
    if (bs) bs.addEventListener("click", function () { m.closePopup(); startEditShape(g); });
    if (bd) bd.addEventListener("click", function () { m.closePopup(); del(g.id); });
  }

  // ----- Matriz de límite por tipo/estado de vehículo -----
  function vslMatrixHtml(g) {
    if (!S.speedPlugin || !S.vehicleTypes.length) return "";
    var pre = (g && g.vehicle_speed_limits) || {};
    var val = function (t, s) {
      return pre[t] && pre[t][s] != null ? pre[t][s] : "";
    };
    var html =
      '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.limit_by_vehicle_type') + '</label><div class="tp-zone-vsl">';
    S.vehicleTypes.forEach(function (vt) {
      if (!vt.states || !vt.states.length) {
        html +=
          '<div class="tp-zone-vsl__row"><span class="tp-zone-vsl__name">' +
          esc(vt.name) +
          '</span><input type="number" min="0" class="tp-input tp-input--sm" data-vsl-type="' +
          vt.id +
          '" data-vsl-state="0" value="' +
          val(vt.id, 0) +
          '"></div>';
      } else {
        html += '<div class="tp-zone-vsl__group"><div class="tp-zone-vsl__title">' + esc(vt.name) + "</div>";
        vt.states.forEach(function (st) {
          html +=
            '<div class="tp-zone-vsl__row"><span class="tp-zone-vsl__name">' +
            esc(st.name) +
            '</span><input type="number" min="0" class="tp-input tp-input--sm" data-vsl-type="' +
            vt.id +
            '" data-vsl-state="' +
            st.id +
            '" value="' +
            val(vt.id, st.id) +
            '"></div>';
        });
        html += "</div>";
      }
    });
    return html + "</div></div>";
  }
  function gatherVsl(dialog) {
    if (!S.speedPlugin || !S.vehicleTypes.length) return null;
    var out = {};
    dialog.querySelectorAll("[data-vsl-type]").forEach(function (inp) {
      if (inp.value === "" || inp.value == null) return;
      var t = inp.getAttribute("data-vsl-type"),
        s = inp.getAttribute("data-vsl-state");
      (out[t] = out[t] || {})[s] = inp.value;
    });
    return out;
  }

  // Intervalos horarios con límite propio (addon reports_geofleet_in). El
  // validador legacy los exige cuando el addon está activo, por eso se pintan
  // con los mismos nombres: additional[intervals][i][from|to|speed_limit].
  function intervalsHtml(g) {
    if (!S.intervalsOn) return "";

    var saved = (g && g.intervals) || [];
    var opts = function (sel) {
      return S.timeRange
        .map(function (t) {
          return '<option value="' + esc(t) + '"' + (String(sel) === String(t) ? " selected" : "") + ">" + esc(t) + "</option>";
        })
        .join("");
    };

    return [0, 1]
      .map(function (i) {
        var iv = saved[i] || {};
        return (
          '<div class="tp-field"><label class="tp-field__label">Intervalo ' + (i + 1) + "</label>" +
          '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">' +
          '<select class="tp-input" data-iv="' + i + '" data-iv-k="from">' + opts(iv.from) + "</select>" +
          '<select class="tp-input" data-iv="' + i + '" data-iv-k="to">' + opts(iv.to) + "</select>" +
          '<input type="number" class="tp-input" min="0" placeholder="km/h" data-iv="' + i + '" data-iv-k="speed_limit" value="' +
          esc(iv.speed_limit == null ? "" : iv.speed_limit) + '">' +
          "</div></div>"
        );
      })
      .join("");
  }

  // → payload.additional.intervals = [{from,to,speed_limit}, …]
  function gatherIntervals(form) {
    if (!S.intervalsOn) return null;

    var out = [];
    [0, 1].forEach(function (i) {
      var row = {};
      form.querySelectorAll('[data-iv="' + i + '"]').forEach(function (el) {
        row[el.getAttribute("data-iv-k")] = el.value;
      });
      out[i] = row;
    });
    return out;
  }

  // ----- Modal de metadatos (crear con geometría dada, o editar) -----
  function metaBody(g, geometry) {
    var groupOpts =
      '<option value="0">' + tpT('front.ungrouped') + '</option>' +
      S.groups
        .map(function (gr) {
          return (
            '<option value="' +
            gr.id +
            '"' +
            (g && String(g.group_id || 0) === String(gr.id) ? " selected" : "") +
            ">" +
            esc(gr.title) +
            "</option>"
          );
        })
        .join("");
    var isCircle = (geometry && geometry.type === "circle") || (g && g.type === "circle");
    var isLine = (geometry && geometry.type === "line") || (g && g.type === "line");
    return (
      '<form id="tp-zone-form" class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.name') + '</label><input type="text" name="name" class="tp-input" value="' +
      esc(g ? g.name : "") +
      '"></div>' +
      '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.color') + '</label><input type="color" name="polygon_color" class="tp-input" value="' +
      ((g && g.polygon_color) || "#1e88e5") +
      '" style="height:38px;padding:4px;"></div>' +
      '<div class="tp-field"><label class="tp-field__label">' + tpT('front.group') + '</label><select name="group_id" class="tp-input">' +
      groupOpts +
      "</select></div>" +
      // Geocerca móvil: sigue al vehículo elegido (plugin moving_geofence).
      (S.movingPlugin
        ? '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.vehicle_moving_geofence') + '</label>' +
          '<select name="device_id" class="tp-input"><option value="">' + tpT('ui.no_vehicle') + '</option>' +
          S.devices.map(function (d) {
            return '<option value="' + d.id + '"' + (g && String(g.device_id || "") === String(d.id) ? " selected" : "") + ">" + esc(d.name) + "</option>";
          }).join("") +
          "</select></div>"
        : "") +
      (isCircle
        ? '<div class="tp-field"><label class="tp-field__label">Radio (m)</label><input type="number" name="radius" class="tp-input" value="' +
          (geometry ? geometry.radius : g ? Math.round(g.radius) : "") +
          '"></div>'
        : "") +
      (isLine
        ? '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.distance_to_axis_m') + '</label>' +
          '<input type="number" name="radius" class="tp-input" min="1" value="' +
          (g && g.radius ? Math.round(g.radius) : 100) +
          '"><small class="tp-field__hint">' + tpT('ui.corridor_width_help') + '</small></div>'
        : "") +
      '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.speed_limit_kmh') + '</label><input type="number" name="speed_limit" class="tp-input" min="0" value="' +
      (g && g.speed_limit != null ? g.speed_limit : "") +
      '"></div>' +
      vslMatrixHtml(g) +
      intervalsHtml(g) +
      "</form>"
    );
  }
  function readMeta(dialog) {
    var f = dialog.querySelector("#tp-zone-form");
    var name = (f.querySelector('[name="name"]').value || "").trim();
    if (!name) {
      window.tpModal.toast && tpModal.toast(tpT('ui.name_required'), "warning");
      return null;
    }
    var payload = {
      name: name,
      polygon_color: f.querySelector('[name="polygon_color"]').value,
      group_id: parseInt(f.querySelector('[name="group_id"]').value, 10) || 0,
    };
    var devSel = f.querySelector('[name="device_id"]');
    // Solo viaja si el plugin pinta el selector: GeofenceUserService::checkDevice
    // solo toca device_id cuando la clave existe (así no se pisa lo guardado).
    if (devSel) payload.device_id = devSel.value ? parseInt(devSel.value, 10) : null;
    // OJO: 'active' NO se incluye aquí. Antes iba fijo a 1 y cada edición
    // REACTIVABA las geocercas desactivadas. Solo el create lo fija (abajo)
    // y la visibilidad se cambia con el toggle de la lista.
    var sl = f.querySelector('[name="speed_limit"]').value;
    if (sl !== "") payload.speed_limit = parseFloat(sl);
    var rad = f.querySelector('[name="radius"]');
    if (rad && rad.value !== "") payload.radius = parseFloat(rad.value);
    var vsl = gatherVsl(dialog);
    if (vsl) payload.vehicle_speed_limits = vsl;
    var ivs = gatherIntervals(f);
    if (ivs) payload.additional = { intervals: ivs };
    return payload;
  }

  function openCreateModal(geometry) {
    if (!window.tpModal) return;
    tpModal.open({
      title: tpT('ui.new_geofence'),
      size: "md",
      body: metaBody(null, geometry),
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost" },
        {
          label: "Crear",
          variant: "primary",
          close: false,
          onClick: function (dialog, btn) {
            var payload = readMeta(dialog);
            if (!payload) return false;
            Object.assign(payload, geometry);
            payload.active = 1; // las nuevas nacen visibles
            btn.disabled = true;
            tpModal
              .fetchJSON(BASE, { method: "POST", json: payload })
              .then(function (r) {
                btn.disabled = false;
                if (r && r.ok && r.data && r.data.ok) {
                  tpModal.toast && tpModal.toast("Geocerca creada.", "success");
                  tpModal.close(dialog);
                  load(true);
                } else tpModal.toast && tpModal.toast(tpT('ui.create_failed'), "error");
              })
              .catch(function () {
                btn.disabled = false;
                tpModal.toast && tpModal.toast(tpT('ui.network_error'), "error");
              });
            return false;
          },
        },
      ],
    });
  }
  function openMetaModal(g) {
    if (!window.tpModal) return;
    tpModal.open({
      title: tpT('ui.edit_geofence'),
      size: "md",
      body: metaBody(g, null),
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost" },
        {
          label: tpT('global.save'),
          variant: "primary",
          close: false,
          onClick: function (dialog, btn) {
            var payload = readMeta(dialog);
            if (!payload) return false;
            // preservar geometría existente
            if (g.type === "circle") {
              var c = parseLatLng(g.center);
              payload.type = "circle";
              payload.center = c;
              payload.polygon = [];
              if (payload.radius == null && g.radius) payload.radius = g.radius;
            } else {
              payload.type = "polygon";
              payload.polygon = normCoords(g.coordinates);
            }
            btn.disabled = true;
            tpModal
              .fetchJSON(BASE + "/" + g.id, { method: "PUT", json: payload })
              .then(function (r) {
                btn.disabled = false;
                if (r && r.ok && r.data && r.data.ok) {
                  tpModal.toast && tpModal.toast("Geocerca actualizada.", "success");
                  tpModal.close(dialog);
                  load(true);
                } else tpModal.toast && tpModal.toast(tpT('ui.save_failed'), "error");
              })
              .catch(function () {
                btn.disabled = false;
                tpModal.toast && tpModal.toast(tpT('ui.network_error'), "error");
              });
            return false;
          },
        },
      ],
    });
  }

  function del(id) {
    if (!window.tpModal) return;
    var doDel = function () {
      tpModal.fetchJSON(BASE + "/" + id, { method: "DELETE" }).then(function (r) {
        if (r && r.ok && r.data && r.data.ok) {
          tpModal.toast && tpModal.toast("Geocerca eliminada.", "success");
          load(true);
        } else tpModal.toast && tpModal.toast(tpT('ui.delete_failed'), "error");
      });
    };
    if (tpModal.confirm)
      tpModal
        .confirm({ title: tpT('ui.delete_geofence'), message: tpT('ui.confirm_delete_geofence'), confirmLabel: tpT('global.delete'), danger: true })
        .then(function (ok) {
          if (ok) doDel();
        });
    else doDel();
  }

  // ----- Dibujo / edición de forma con leaflet-draw -----
  function showDrawBar(mode) {
    hideBar();
    S.bar = document.createElement("div");
    S.bar.className = "tp-zonedraw-bar";
    var hint = "",
      mid = "";
    if (mode === "edit") {
      hint = tpT('ui.drag_vertices_help');
      mid = '<button type="button" class="tp-btn tp-btn--sm tp-btn--accent" data-zd-save>' + tpT('global.save') + '</button>';
    } else if (mode === "circle") {
      hint = tpT('ui.click_drag_circle');
    } else {
      hint = tpT('ui.click_add_vertices');
      mid = '<button type="button" class="tp-btn tp-btn--sm tp-btn--accent" data-zd-finish>Terminar</button>';
    }
    S.bar.innerHTML =
      '<span class="tp-zonedraw-bar__hint">' +
      hint +
      "</span>" +
      mid +
      '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-zd-cancel>' + tpT('global.cancel') + '</button>';
    (root.querySelector(".tp-live__map-area") || document.body).appendChild(S.bar);
    var save = S.bar.querySelector("[data-zd-save]");
    if (save) save.addEventListener("click", finishEditShape);
    var fin = S.bar.querySelector("[data-zd-finish]");
    if (fin) fin.addEventListener("click", finishPolygon);
    S.bar.querySelector("[data-zd-cancel]").addEventListener("click", cancelDraw);
  }
  function hideBar() {
    if (S.bar) {
      S.bar.remove();
      S.bar = null;
    }
  }
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
  function geometryFromLayer(layer) {
    if (layer instanceof L.Circle) {
      var c = layer.getLatLng();
      return { type: "circle", center: { lat: c.lat, lng: c.lng }, radius: Math.round(layer.getRadius()), polygon: [] };
    }
    // L.Polygon extiende L.Polyline, asi que el corredor se comprueba antes
    if (layer instanceof L.Polyline && !(layer instanceof L.Polygon)) {
      return {
        type: "line",
        polygon: layer.getLatLngs().map(function (pt) {
          return { lat: pt.lat, lng: pt.lng };
        }),
      };
    }
    var rings = layer.getLatLngs();
    var pts = rings && rings.length ? rings[0] : [];
    if (Array.isArray(pts[0])) pts = pts[0];
    return {
      type: "polygon",
      polygon: pts.map(function (p) {
        return { lat: p.lat, lng: p.lng };
      }),
    };
  }
  // Termina un polígono en curso (equivale a doble clic en leaflet-draw).
  function finishPolygon() {
    if (S.drawer && S.drawer._finishShape) {
      try {
        S.drawer._finishShape();
        return;
      } catch (e) {}
    }
    window.tpModal &&
      tpModal.toast &&
      tpModal.toast(tpT('ui.double_click_finish_polygon'), "info");
  }
  // CREAR: dibujar con leaflet-draw; al terminar abre el modal de datos.
  function startCreate(type) {
    var m = map();
    if (!m) return;
    disposeDraft();
    S.editId = null;
    S.editName = null;
    var shape = { color: "#1e88e5", weight: 3, fill: true, fillOpacity: 0.3, fillColor: "#1e88e5" };
    S.drawer =
      type === "circle"
        ? new L.Draw.Circle(m, { shapeOptions: shape, metric: true })
        : type === "line"
          ? new L.Draw.Polyline(m, { shapeOptions: shape })
          : new L.Draw.Polygon(m, { shapeOptions: shape, allowIntersection: false, showArea: false });
    S.drawer.enable();
    showDrawBar(type === "circle" ? "circle" : "polygon");
  }
  function bindDrawCreated() {
    var m = map();
    if (!m || m.__zoneDrawBound) return;
    m.__zoneDrawBound = true;
    m.on(L.Draw.Event.CREATED, function (e) {
      if (S.editId) return; // la edición de forma no usa draw:created
      var t = e.layerType;
      if (t !== "polygon" && t !== "circle" && t !== "polyline") return;
      var geometry = geometryFromLayer(e.layer);
      try {
        m.removeLayer(e.layer);
      } catch (err) {}
      if (S.drawer) {
        try {
          S.drawer.disable();
        } catch (err) {}
        S.drawer = null;
      }
      hideBar();
      if (geometry.type === "polygon" && geometry.polygon.length < 3) {
        tpModal && tpModal.toast && tpModal.toast(tpT('ui.add_at_least_3_vertices'), "warning");
        return;
      }
      if (geometry.type === "line" && geometry.polygon.length < 2) {
        tpModal && tpModal.toast && tpModal.toast(tpT('ui.corridor_needs_2_points'), "warning");
        return;
      }
      openCreateModal(geometry); // ← formulario: nombre, color, grupo, velocidad, límites
    });
  }
  // EDITAR FORMA de una geocerca existente (vértices + punto medio).
  function startEditShape(g) {
    var m = map();
    if (!m) return;
    disposeDraft();
    S.editId = g.id;
    S.editName = g.name;
    // El corredor se valida con su anchura: si no viaja, el PUT se rechaza
    S.editRadius = g.type === "line" ? g.radius : null;
    var layer = buildLayerFor(g);
    if (!layer) return;
    S.draft = layer.addTo(m);
    if (layer.editing) {
      try {
        layer.editing.enable();
      } catch (e) {}
    }
    showDrawBar("edit");
  }
  function finishEditShape() {
    if (!S.draft) return;
    var geometry = geometryFromLayer(S.draft);
    if (geometry.type === "polygon" && geometry.polygon.length < 3) {
      tpModal && tpModal.toast && tpModal.toast(tpT('ui.add_at_least_3_vertices'), "warning");
      return;
    }
    if (geometry.type === "line") {
      if (geometry.polygon.length < 2) {
        tpModal && tpModal.toast && tpModal.toast(tpT('ui.corridor_needs_2_points'), "warning");
        return;
      }
      geometry.radius = S.editRadius;
    }
    var id = S.editId,
      name = S.editName;
    disposeDraft();
    hideBar();
    S.editId = null;
    S.editName = null;
    var payload = Object.assign({ name: name }, geometry);
    tpModal.fetchJSON(BASE + "/" + id, { method: "PUT", json: payload }).then(function (r) {
      if (r && r.ok && r.data && r.data.ok) {
        tpModal.toast && tpModal.toast("Forma actualizada.", "success");
        load(true);
      } else tpModal.toast && tpModal.toast(tpT('ui.shape_update_failed'), "error");
    });
  }
  function cancelDraw() {
    disposeDraft();
    hideBar();
    S.editId = null;
    S.editName = null;
  }

  function startCreateChooser() {
    if (!window.tpModal) return;
    var dlg = tpModal.open({
      title: tpT('ui.new_geofence'),
      size: "sm",
      body:
        '<p style="margin:0 0 12px;font-size:14px;">' + tpT('ui.which_shape_to_draw') + '</p>' +
        '<div class="tp-row" style="gap:8px;"><button type="button" class="tp-btn tp-btn--accent tp-full" data-zc="polygon">' + tpT('ui.polygon') + '</button>' +
        '<button type="button" class="tp-btn tp-btn--accent tp-full" data-zc="circle">' + tpT('ui.circle') + '</button>' +
        '<button type="button" class="tp-btn tp-btn--accent tp-full" data-zc="line">Corredor</button></div>',
      buttons: [{ label: tpT('global.cancel'), variant: "ghost" }],
    });
    if (dlg)
      dlg.querySelectorAll("[data-zc]").forEach(function (b) {
        b.addEventListener("click", function () {
          var t = b.getAttribute("data-zc");
          tpModal.close(dlg);
          startCreate(t);
        });
      });
  }

  // ----- Carga -----
  function load(force) {
    if (S.loaded && !force) {
      renderList();
      renderLayers();
      return;
    }
    fetch(BASE, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        S.items = (j && j.items) || [];
        S.groups = (j && j.groups) || [];
        S.vehicleTypes = (j && j.vehicle_types) || [];
        S.speedPlugin = !!(j && j.speed_limit_plugin);
        S.movingPlugin = !!(j && j.moving_plugin);
        S.devices = (j && j.devices) || [];
        S.intervalsOn = !!(j && j.intervals_enabled);
        S.timeRange = (j && j.time_range) || [];
        S.byId = {};
        S.items.forEach(function (g) {
          S.byId[g.id] = g;
        });
        S.loaded = true;
        fillGroupSelect();
        renderList();
        renderLayers();
        settleRedraw();
      })
      .catch(function () {});
  }

  // Fix 1ª carga: el fitBounds inicial de la flota mueve la vista justo cuando
  // se añaden estos vectores (SVG) y a veces no se pintan hasta interactuar con
  // el mapa. Forzamos UN re-render cuando el mapa se asienta.
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
  /** Visibilidad de la geocerca (paridad legacy geofences.change_active). */
  function toggleActive(g, on) {
    tpModal
      .fetchJSON(BASE + "/" + g.id + "/toggle", { method: "POST", json: { active: on ? 1 : 0 } })
      .then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast(tpT('ui.visibility_change_failed'), "error");
          renderList();
          return;
        }
        g.active = !!res.data.active;
        renderLayers();
      })
      .catch(function () {
        tpModal.toast(tpT('ui.network_error'), "error");
        renderList();
      });
  }

  /* ---------- Export / Import nativos (endpoints legacy) ----------
     Antes eran <a> a /geofences/export|import_modal, que devuelven FRAGMENTOS
     del modal legacy (sin layout ni su JS) → botones muertos. Ahora: modal v2
     + form-POST de descarga (export) y FormData (import). */
  function exportUrls() {
    return {
      create: (root.getAttribute("data-zones-export-url") || "/geofences/export_create"),
      import: (root.getAttribute("data-zones-import-url") || "/geofences/import"),
    };
  }
  function openExport() {
    var types = {
      export_single: "Exportar seleccionadas",
      export_active: "Exportar activas",
      export_inactive: "Exportar inactivas",
      export_groups: tpT('ui.export_by_groups'),
    };
    var opts = Object.keys(types)
      .map(function (k) { return '<option value="' + k + '">' + esc(types[k]) + "</option>"; })
      .join("");
    var checks = S.items
      .map(function (g) {
        return '<label class="tp-zexp-item"><input type="checkbox" data-exp-id value="' + g.id + '"><span class="tp-zone-row__swatch" style="background:' + (g.polygon_color || "#1e88e5") + '"></span><span>' + esc(g.name) + "</span></label>";
      })
      .join("");
    tpModal.open({
      title: "Exportar geocercas",
      size: "md",
      body:
        '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.export_type') + '</label>' +
        '<select class="tp-input" data-exp-type>' + opts + "</select></div>" +
        '<div class="tp-field" data-exp-wrap><label class="tp-field__label">Geocercas</label>' +
        '<div class="tp-zexp-list">' + (checks || '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_geofences') + '</div>') + "</div></div>" +
        '<div class="tp-field" data-exp-gwrap hidden><label class="tp-field__label">' + tpT('front.groups') + '</label>' +
        '<div class="tp-zexp-list">' +
        ((S.groups || []).map(function (g) {
          return '<label class="tp-zexp-item"><input type="checkbox" data-exp-group value="' + g.id + '"><span>' + esc(g.title) + "</span></label>";
        }).join("") || '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_groups') + '</div>') +
        "</div></div>" +
        '<div class="tp-field__hint">' + tpT('ui.gexp_format_note') + '</div>',
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost" },
        {
          label: tpT('front.export'),
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            var type = dlg.querySelector("[data-exp-type]").value;
            var ids = [];
            dlg.querySelectorAll("[data-exp-id]:checked").forEach(function (c) { ids.push(c.value); });
            submitExport(type, ids, dlg);
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
  function submitExport(type, ids, dlg) {
    // SinglesGeofenceFilter filtra por 'geofences' (ids) también en
    // activas/inactivas; GroupsGeofenceFilter espera 'groups'.
    if (type === "export_active" || type === "export_inactive") {
      var want = type === "export_active";
      ids = (S.items || [])
        .filter(function (g) { return !!g.active === want; })
        .map(function (g) { return g.id; });
    }
    var groupIds = [];
    if (type === "export_groups" && dlg) {
      dlg.querySelectorAll("[data-exp-group]:checked").forEach(function (c) { groupIds.push(c.value); });
    }
    var form = document.createElement("form");
    form.method = "POST";
    form.action = exportUrls().create;
    form.style.display = "none";
    var add = function (n, v) {
      var i = document.createElement("input");
      i.type = "hidden"; i.name = n; i.value = v;
      form.appendChild(i);
    };
    var tok = document.querySelector('meta[name="csrf-token"]');
    if (tok) add("_token", tok.getAttribute("content"));
    add("export_type", type);
    ids.forEach(function (id) { add("geofences[]", id); });
    groupIds.forEach(function (id) { add("groups[]", id); });
    document.body.appendChild(form);
    form.submit();
    setTimeout(function () { form.remove(); }, 1000);
  }
  function openImport() {
    tpModal.open({
      title: "Importar geocercas",
      size: "sm",
      body:
        '<div class="tp-field"><label class="tp-field__label">Archivo</label>' +
        '<input type="file" class="tp-input" data-imp-file accept=".csv,.gexp,.kml,.json,.geojson"></div>' +
        '<div class="tp-field__hint">' + tpT('ui.formats_kml_geojson') + '</div>',
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost" },
        {
          label: tpT('front.import'),
          variant: "primary",
          close: false,
          onClick: function (dlg, btn) {
            var f = dlg.querySelector("[data-imp-file]").files[0];
            if (!f) { tpModal.toast(tpT('ui.pick_a_file'), "warning"); return false; }
            var fd = new FormData();
            fd.append("file", f);
            btn.disabled = true;
            tpModal
              .fetchJSON(BASE + "/import", { method: "POST", body: fd })
              .then(function (r) {
                btn.disabled = false;
                if (r && r.ok && r.data && r.data.ok) {
                  tpModal.toast((r.data.message) || "Geocercas importadas.", "success");
                  tpModal.close(dlg);
                  load(true);
                } else {
                  tpModal.toast((r.data && (r.data.message || r.data.error)) || tpT('ui.import_failed'), "error");
                }
              })
              .catch(function () { btn.disabled = false; tpModal.toast(tpT('ui.network_error'), "error"); });
            return false;
          },
        },
      ],
    });
  }
  window.tpLiveZonesIO = { exportModal: openExport, importModal: openImport };

  var wired = false;
  function wire() {
    if (wired) return;
    wired = true;
    var list = document.getElementById("tp-zones-list");
    if (list)
      list.addEventListener("click", function (e) {
        var row = e.target.closest(".tp-poi-row");
        if (!row) return;
        var g = S.byId[parseInt(row.getAttribute("data-id"), 10)];
        if (!g) return;
        if (e.target.closest("[data-z-vis]")) return toggleActive(g, e.target.checked);
        if (e.target.closest("[data-z-edit]")) return openMetaModal(g);
        if (e.target.closest("[data-z-del]")) return del(g.id);
        S.activeId = g.id;
        renderLayers();
        flyTo(g);
        openZonePopup(g);
      });
    var search = document.getElementById("tp-zones-search");
    if (search)
      search.addEventListener("input", function () {
        S.q = search.value || "";
        renderList();
        renderLayers();
      });
    var grp = document.getElementById("tp-zones-group");
    if (grp)
      grp.addEventListener("change", function () {
        S.groupId = grp.value || "";
        renderList();
        renderLayers();
      });
    // Gestor de grupos (crear/editar/eliminar) — tp-groups.js
    var grpManage = document.getElementById("tp-zones-groups-manage");
    if (grpManage)
      grpManage.addEventListener("click", function () {
        if (!window.tpGroups) return;
        tpGroups.open({
          title: tpT('ui.geofence_groups'),
          base: root.getAttribute("data-zone-groups-url") || "/ui/v2/api/zone-groups",
          itemsKey: "geofences",
          itemsLabel: "Geocercas",
          groups: S.groups || [],
          items: (S.items || []).map(function (z) {
            return { id: z.id, name: z.name, color: z.polygon_color };
          }),
          groupOf: function (it) {
            var z = (S.items || []).filter(function (x) { return x.id === it.id; })[0];
            return z ? z.group_id || 0 : 0;
          },
          onSaved: function () { load(true); },
        });
      });
    var tf = document.getElementById("tp-zones-type-filter");
    if (tf)
      tf.addEventListener("click", function (e) {
        var b = e.target.closest("[data-ztype]");
        if (!b) return;
        tf.querySelectorAll("[data-ztype]").forEach(function (x) {
          x.setAttribute("aria-selected", "false");
        });
        b.setAttribute("aria-selected", "true");
        S.type = b.getAttribute("data-ztype");
        renderList();
        renderLayers();
      });
    var nw = document.getElementById("tp-zones-new");
    if (nw) nw.addEventListener("click", startCreateChooser);
  }

  // ----- API para live.js -----

  /* ============================================================
     Limite de velocidad vigente en un punto
     ------------------------------------------------------------
     Lo usa el coloreado del marcador en el mapa. Se resuelve AQUI, en el
     navegador, porque este modulo ya tiene las geocercas con su geometria y
     su limite: preguntarselo al servidor por cada vehiculo y cada refresco
     serian cientos de consultas espaciales por segundo.

     Con geocercas solapadas gana la de MENOR area, que es la misma regla que
     aplican las alertas y los reportes (Geofence::pickBySpeedLimitPriority).
     ============================================================ */

  var TIERRA_M = 111320; // metros por grado de latitud

  function metrosEntre(aLat, aLng, bLat, bLng) {
    var dLat = (bLat - aLat) * TIERRA_M;
    var dLng = (bLng - aLng) * TIERRA_M * Math.cos((aLat * Math.PI) / 180);
    return Math.sqrt(dLat * dLat + dLng * dLng);
  }

  // Distancia de un punto al segmento AB, en metros.
  function metrosASegmento(pLat, pLng, aLat, aLng, bLat, bLng) {
    var cos = Math.cos((aLat * Math.PI) / 180);
    var px = pLng * cos, py = pLat;
    var ax = aLng * cos, ay = aLat;
    var bx = bLng * cos, by = bLat;

    var dx = bx - ax, dy = by - ay;
    var largo = dx * dx + dy * dy;

    var t = largo ? ((px - ax) * dx + (py - ay) * dy) / largo : 0;
    t = Math.max(0, Math.min(1, t));

    var cx = ax + t * dx, cy = ay + t * dy;

    return Math.sqrt((px - cx) * (px - cx) + (py - cy) * (py - cy)) * TIERRA_M;
  }

  // Punto en poligono por lanzamiento de rayo.
  function dentroDelPoligono(pLat, pLng, pts) {
    var dentro = false;
    for (var i = 0, j = pts.length - 1; i < pts.length; j = i++) {
      var yi = pts[i].lat, xi = pts[i].lng;
      var yj = pts[j].lat, xj = pts[j].lng;

      if ((yi > pLat) !== (yj > pLat) &&
          pLng < ((xj - xi) * (pLat - yi)) / (yj - yi) + xi) {
        dentro = !dentro;
      }
    }
    return dentro;
  }

  // Area aproximada, solo para comparar cual geocerca es mas pequeña.
  function areaAprox(g) {
    if (g.type === "circle") {
      var r = Number(g.radius) || 0;
      return Math.PI * r * r;
    }

    var pts = normCoords(g.coordinates);

    if (g.type === "line") {
      var largo = 0;
      for (var i = 1; i < pts.length; i++) {
        largo += metrosEntre(pts[i - 1].lat, pts[i - 1].lng, pts[i].lat, pts[i].lng);
      }
      return largo * 2 * (Number(g.radius) || 0);
    }

    if (pts.length < 3) return Infinity;

    // Shoelace sobre grados, corregido por la latitud media
    var cos = Math.cos((pts[0].lat * Math.PI) / 180);
    var suma = 0;
    for (var k = 0, l = pts.length - 1; k < pts.length; l = k++) {
      suma += (pts[l].lng * cos) * pts[k].lat - (pts[k].lng * cos) * pts[l].lat;
    }
    return Math.abs(suma / 2) * TIERRA_M * TIERRA_M;
  }

  function contieneElPunto(g, lat, lng) {
    if (g.type === "circle") {
      var c = parseLatLng(g.center);
      if (!c || !g.radius) return false;
      return metrosEntre(lat, lng, c.lat, c.lng) <= Number(g.radius);
    }

    var pts = normCoords(g.coordinates);

    if (g.type === "line") {
      if (pts.length < 2 || !g.radius) return false;
      var r = Number(g.radius);
      for (var i = 1; i < pts.length; i++) {
        if (metrosASegmento(lat, lng, pts[i - 1].lat, pts[i - 1].lng,
                            pts[i].lat, pts[i].lng) <= r) {
          return true;
        }
      }
      return false;
    }

    return pts.length >= 3 && dentroDelPoligono(lat, lng, pts);
  }

  /**
   * Limite que aplica a ese vehiculo en ese punto, o null si no hay ninguno.
   *
   * Tres niveles, los mismos que Geofence::speedLimitFor: limite exacto del
   * tipo + estado, limite por defecto del tipo, y el limite general de la
   * geocerca.
   *
   * @return {{limit:number, zoneId:number, zoneName:string}|null}
   */
  function limiteEnPunto(lat, lng, tipoId, estadoId) {
    if (lat == null || lng == null || !S.items || !S.items.length) return null;

    lat = +lat;
    lng = +lng;
    if (isNaN(lat) || isNaN(lng)) return null;

    var mejor = null;

    S.items.forEach(function (g) {
      if (!contieneElPunto(g, lat, lng)) return;

      var limite = null;
      var vsl = g.vehicle_speed_limits || {};

      if (tipoId && vsl[tipoId]) {
        // 0 es la clave del limite por defecto del tipo (estado nulo)
        var porTipo = vsl[tipoId];
        limite = (estadoId && porTipo[estadoId] != null) ? porTipo[estadoId] : porTipo[0];
      }

      if (limite == null) limite = g.speed_limit;
      if (limite == null) return;

      var area = areaAprox(g);
      if (!mejor || area < mejor.area) {
        mejor = { limit: +limite, zoneId: g.id, zoneName: g.name, area: area };
      }
    });

    if (!mejor) return null;

    return { limit: mejor.limit, zoneId: mejor.zoneId, zoneName: mejor.zoneName };
  }

  window.tpLiveZones = {
    /** Limite de velocidad vigente en un punto (para el color del marcador). */
    limitFor: limiteEnPunto,
    activate: function () {
      wire();
      bindDrawCreated();
      load(false);
    },
    // Toggle de la capa de geocercas desde el control flotante del mapa.
    setVisible: function (on) {
      var m = map();
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

  // Si llegamos con el panel de Geocercas ya abierto (deep-link ?panel=zones),
  // live.js hizo switchPanel antes de cargar este script: auto-activar.
  var zp = document.getElementById("tp-panel-zones");
  if (zp && !zp.hidden) window.tpLiveZones.activate();
})();
