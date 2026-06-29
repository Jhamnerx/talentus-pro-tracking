/* ============================================================
 * UI v2 · Live workspace — LEAFLET engine (migración desde MapLibre)
 * ------------------------------------------------------------
 * Un solo mapa Leaflet (mismo motor que el legacy) para el workspace:
 * Objetos (devices) + paneles Geocercas/POIs sobre el MISMO mapa.
 * Paso 1: mapa + capas base + marcadores de device + lista + controles.
 * Zonas (leaflet-draw) y POIs se conectan en los commits siguientes.
 * ============================================================ */
(function () {
  "use strict";
  var root = document.getElementById("tp-live");
  if (!root || !window.L) return;

  var CFG = {
    devicesUrl: root.getAttribute("data-devices-url"),
    updatesUrl:
      root.getAttribute("data-updates-url") ||
      (root.getAttribute("data-devices-url") || "") + "/updates",
    zonesUrl: root.getAttribute("data-zones-url"),
    poisUrl: root.getAttribute("data-pois-url"),
    geoUrl: root.getAttribute("data-geo-url") || "",
    pollInterval: parseInt(root.getAttribute("data-poll-interval"), 10) || 15000,
  };

  var MAP_CFG = (function () {
    try {
      return JSON.parse(
        (document.getElementById("tp-live-map-config") || {}).textContent ||
          "{}",
      );
    } catch (e) {
      return {};
    }
  })();
  var KEYS = MAP_CFG.keys || {};

  // -----------------------------------------------------------
  // Capas base (Leaflet). OSM + Google (tiles ≠) + proveedores con key.
  // -----------------------------------------------------------
  function buildBaseLayers() {
    var L_ = window.L;
    var layers = {};
    layers["osm"] = {
      name: "OpenStreetMap",
      layer: L_.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap",
      }),
    };
    var g = function (lyrs, name) {
      return {
        name: name,
        layer: L_.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=" + lyrs + "&x={x}&y={y}&z={z}",
          { subdomains: ["0", "1", "2", "3"], maxZoom: 21, attribution: "Google" },
        ),
      };
    };
    layers["g_roadmap"] = g("m", "Google Normal");
    layers["g_hybrid"] = g("y", "Google Híbrido");
    layers["g_satellite"] = g("s", "Google Satélite");
    layers["g_terrain"] = g("p", "Google Terreno");
    if (KEYS.mapbox_key) {
      layers["mapbox"] = {
        name: "Mapbox",
        layer: L_.tileLayer(
          "https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token=" +
            KEYS.mapbox_key,
          { maxZoom: 22, tileSize: 512, zoomOffset: -1, attribution: "Mapbox" },
        ),
      };
    }
    return layers;
  }

  var state = {
    map: null,
    baseLayers: {},
    currentBase: null,
    devices: [],
    total: 0,
    index: {},
    markers: {}, // id → L.marker
    deviceLayer: null, // L.layerGroup
    filtered: [],
    visual: [],
    groups: [],
    grouped: true,
    collapsedGroups: {},
    statusFilter: "all",
    searchQuery: "",
    selectedId: null,
    checkTime: null,
    pollHandle: null,
    bootstrapped: false,
    popup: null,
    toggles: {},
    autoFit: false,
    geoMarker: null,
    trafficLayer: null,
    clusterGroup: null,
    routesLayer: null,
  };
  try {
    var sg = localStorage.getItem("tp_live_grouped");
    if (sg !== null) state.grouped = sg === "1";
  } catch (e) {}

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  // -----------------------------------------------------------
  // Estado operativo del device (autoritativo: d.online)
  // -----------------------------------------------------------
  function classify(d) {
    switch (d && d.online) {
      case "online":
      case "excessive_speed":
      case "move":
        return "moving";
      case "engine":
      case "ack":
      case "stop":
      case "idle":
      case "park":
      case "blocked":
        return "idle";
      default:
        return "offline";
    }
  }
  function fmtTimeAgo(ts) {
    if (!ts) return "—";
    var sec = parseInt(ts, 10);
    if (sec > 1e12) sec = Math.floor(sec / 1000);
    var diff = Math.floor(Date.now() / 1000) - sec;
    if (diff < 0) diff = 0;
    if (diff < 60) return diff + "s";
    if (diff < 3600) return Math.floor(diff / 60) + "m";
    if (diff < 86400) return Math.floor(diff / 3600) + "h";
    return Math.floor(diff / 86400) + "d";
  }

  // -----------------------------------------------------------
  // Mapa Leaflet + capas base
  // -----------------------------------------------------------
  function initMap() {
    state.map = L.map("tp-live-map", {
      center: [-12.0464, -77.0428],
      zoom: 5,
      zoomControl: false,
      attributionControl: true,
    });
    state.baseLayers = buildBaseLayers();
    var firstId =
      MAP_CFG.mapId && state.baseLayers[MAP_CFG.mapId]
        ? MAP_CFG.mapId
        : state.baseLayers["g_roadmap"]
          ? "g_roadmap"
          : "osm";
    setBaseLayer(firstId);
    state.deviceLayer = L.layerGroup().addTo(state.map);
    buildBaseMenu();
  }
  function setBaseLayer(id) {
    var entry = state.baseLayers[id];
    if (!entry) return;
    if (state.currentBase && state.baseLayers[state.currentBase])
      state.map.removeLayer(state.baseLayers[state.currentBase].layer);
    entry.layer.addTo(state.map);
    if (entry.layer.bringToBack) entry.layer.bringToBack();
    state.currentBase = id;
    var menu = document.getElementById("tp-live-providers-menu");
    if (menu)
      menu.querySelectorAll("[data-map-id]").forEach(function (b) {
        b.setAttribute(
          "aria-selected",
          b.getAttribute("data-map-id") === id ? "true" : "false",
        );
      });
  }
  function buildBaseMenu() {
    var menu = document.getElementById("tp-live-providers-menu");
    if (!menu) return;
    menu.innerHTML = Object.keys(state.baseLayers)
      .map(function (id) {
        return (
          '<button type="button" class="tp-menu__item" data-map-id="' +
          id +
          '">' +
          esc(state.baseLayers[id].name) +
          "</button>"
        );
      })
      .join("");
    menu.addEventListener("click", function (e) {
      var b = e.target.closest("[data-map-id]");
      if (!b) return;
      setBaseLayer(b.getAttribute("data-map-id"));
      menu.hidden = true;
    });
  }

  // -----------------------------------------------------------
  // Marcador de device (ícono real, como el legacy device.js)
  // -----------------------------------------------------------
  function assetPath(p) {
    if (!p) return "";
    if (/^https?:\/\//.test(p) || p.charAt(0) === "/") return p;
    return "/" + p;
  }
  function deviceIcon(d) {
    var icon = d.icon || {};
    var type = icon.type || "arrow";
    var src,
      w,
      h,
      rotate = false,
      anchorBottom = false;
    if (type === "arrow") {
      src = "/images/device_icons/estados/" + (d.icon_color || "gray") + ".png";
      w = 25;
      h = 33;
      rotate = true;
    } else if (type === "rotating") {
      src = assetPath(icon.path);
      w = icon.width || 32;
      h = icon.height || 32;
      rotate = true;
    } else {
      src = assetPath(icon.path);
      w = icon.width || 32;
      h = icon.height || 32;
      anchorBottom = true;
    }
    var max = Math.max(w, h),
      scale = max > 44 ? 44 / max : 1;
    w = Math.round(w * scale);
    h = Math.round(h * scale);
    var rot = rotate ? "transform:rotate(" + (parseFloat(d.course) || 0) + "deg);" : "";
    var html =
      '<img src="' +
      src +
      '" width="' +
      w +
      '" height="' +
      h +
      '" style="display:block;' +
      rot +
      '" draggable="false">';
    return L.divIcon({
      html: html,
      className: "tp-lmarker",
      iconSize: [w, h],
      iconAnchor: anchorBottom ? [w / 2, h] : [w / 2, h / 2],
    });
  }

  function renderMarkers() {
    if (!state.map) return;
    var seen = {};
    state.filtered.forEach(function (d) {
      if (d.lat == null || d.lng == null) return;
      if (d.active === false) return;
      seen[d.id] = true;
      var ll = [parseFloat(d.lat), parseFloat(d.lng)];
      var cont = markerContainer();
      var m = state.markers[d.id];
      if (m) {
        m.setLatLng(ll);
        m.setIcon(deviceIcon(d));
      } else {
        m = L.marker(ll, { icon: deviceIcon(d) });
        m.on("click", function () {
          openDevicePopup(d.id);
        });
        cont.addLayer(m);
        state.markers[d.id] = m;
      }
    });
    var cont2 = markerContainer();
    Object.keys(state.markers).forEach(function (id) {
      if (!seen[id]) {
        cont2.removeLayer(state.markers[id]);
        delete state.markers[id];
      }
    });
  }

  // Contenedor activo de marcadores: cluster o layerGroup normal.
  function markerContainer() {
    return state.toggles.cluster && state.clusterGroup ? state.clusterGroup : state.deviceLayer;
  }
  function ensureCluster() {
    if (!window.L.markerClusterGroup) return null;
    if (!state.clusterGroup)
      state.clusterGroup = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 50, showCoverageOnHover: false });
    return state.clusterGroup;
  }
  function setClusterMode(on) {
    var m = state.map;
    // sacar todos los marcadores de ambos contenedores
    Object.keys(state.markers).forEach(function (id) {
      try {
        state.deviceLayer.removeLayer(state.markers[id]);
      } catch (e) {}
      if (state.clusterGroup) {
        try {
          state.clusterGroup.removeLayer(state.markers[id]);
        } catch (e) {}
      }
    });
    state.markers = {};
    if (on) {
      var cg = ensureCluster();
      if (!cg) {
        state.toggles.cluster = false;
        window.tpModal && tpModal.toast && tpModal.toast("Agrupación no disponible.", "warning");
        return;
      }
      m.removeLayer(state.deviceLayer);
      cg.addTo(m);
    } else {
      if (state.clusterGroup) m.removeLayer(state.clusterGroup);
      state.deviceLayer.addTo(m);
    }
    renderMarkers();
  }

  function fitToFleet() {
    var pts = state.filtered
      .filter(function (d) {
        return d.lat != null && d.lng != null && d.active !== false;
      })
      .map(function (d) {
        return [parseFloat(d.lat), parseFloat(d.lng)];
      });
    if (pts.length) state.map.fitBounds(L.latLngBounds(pts), { padding: [50, 50], maxZoom: 16 });
  }

  // Aplica un toggle de capa del control flotante.
  function applyToggle(key) {
    var on = !!state.toggles[key];
    var m = state.map;
    if (key === "devices") {
      var cont = markerContainer();
      if (on) cont.addTo(m);
      else m.removeLayer(cont);
    } else if (key === "cluster") {
      setClusterMode(on);
    } else if (key === "geofences") {
      if (window.tpLiveZones && tpLiveZones.setVisible) tpLiveZones.setVisible(on);
    } else if (key === "poi") {
      if (window.tpLivePois && tpLivePois.setVisible) tpLivePois.setVisible(on);
    } else if (key === "routes") {
      setRoutesVisible(on);
    } else if (key === "traffic") {
      if (on) {
        if (!state.trafficLayer)
          state.trafficLayer = L.tileLayer(
            "https://mt{s}.google.com/vt/lyrs=h,traffic&x={x}&y={y}&z={z}",
            { subdomains: ["0", "1", "2", "3"], opacity: 0.7 },
          );
        state.trafficLayer.addTo(m);
      } else if (state.trafficLayer) {
        m.removeLayer(state.trafficLayer);
      }
    } else if (key === "names") {
      m.getContainer().classList.toggle("tp-show-names", on);
    } else if (key === "tails") {
      window.tpModal && tpModal.toast && tpModal.toast("Estela: disponible próximamente.", "info");
    }
  }

  // Rutas: capa de polilíneas desde el endpoint de rutas (lectura).
  function setRoutesVisible(on) {
    var m = state.map;
    if (!on) {
      if (state.routesLayer) m.removeLayer(state.routesLayer);
      return;
    }
    if (state.routesLayer) {
      state.routesLayer.addTo(m);
      return;
    }
    fetch(CFG.routesUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        state.routesLayer = L.layerGroup();
        ((j && j.items) || []).forEach(function (rt) {
          var pts = (rt.coordinates || []).map(function (c) {
            return [c[1], c[0]]; // [lng,lat] → [lat,lng]
          });
          if (pts.length < 2) return;
          L.polyline(pts, { color: rt.color || "#7c3aed", weight: 3, opacity: 0.85 })
            .bindTooltip(rt.name || "Ruta")
            .addTo(state.routesLayer);
        });
        if (state.toggles.routes) state.routesLayer.addTo(m);
      })
      .catch(function () {});
  }

  // Modal de configuración de widgets (reusa el endpoint nativo v2).
  function openWidgetsConfig(configUrl, saveUrl) {
    if (!configUrl || !window.tpModal) return;
    tpModal.fetchJSON(configUrl, { method: "GET" }).then(function (res) {
      if (!res || !res.ok || !res.data || !res.data.ok) {
        tpModal.toast && tpModal.toast("No se pudo cargar la configuración.", "error");
        return;
      }
      var dlg = tpModal.open({
        title: "Widgets",
        size: "lg",
        body: res.data.html,
        buttons: [
          { label: "Cancelar", variant: "ghost" },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog, btn) {
              var form = dialog.querySelector("#tp-widgets-config-form");
              if (!form) return false;
              var checked = [];
              form.querySelectorAll('input[name="widgets[]"]:checked').forEach(function (i) {
                checked.push(i.value);
              });
              var payload = {
                default: form.querySelector('[name="default"]').checked ? 1 : 0,
                status: form.querySelector('[name="status"]').checked ? 1 : 0,
                list: checked,
              };
              btn.disabled = true;
              tpModal
                .fetchJSON(saveUrl, { method: "POST", json: payload })
                .then(function (r) {
                  btn.disabled = false;
                  if (r && r.ok && r.data && r.data.ok) {
                    tpModal.toast && tpModal.toast("Widgets actualizados.", "success");
                    tpModal.close(dialog);
                    if (state.selectedId) loadDrawerWidgets(state.selectedId);
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
      var def = dlg.querySelector('[name="default"]');
      var fields = dlg.querySelector("#tp-widgets-config-fields");
      function syncDef() {
        if (!fields) return;
        fields.style.opacity = def && def.checked ? "0.45" : "";
        fields.querySelectorAll("input").forEach(function (i) {
          i.disabled = def && def.checked;
        });
      }
      if (def) {
        def.addEventListener("change", syncDef);
        syncDef();
      }
    });
  }

  // -----------------------------------------------------------
  // Popup de device (conciso, con dirección perezosa)
  // -----------------------------------------------------------
  var STATUS_LABELS = {
    online: "En movimiento",
    excessive_speed: "Exceso de velocidad",
    engine: "Motor encendido",
    ack: "Detenido",
    stop: "Detenido",
    idle: "Ralentí",
    blocked: "Bloqueado",
    offline: "Desconectado",
    expired: "Expirado",
  };
  var addrCache = {};
  function fetchAddr(d, cb) {
    if (!CFG.geoUrl || d.lat == null) return cb("");
    if (addrCache[d.id]) return cb(addrCache[d.id]);
    fetch(
      CFG.geoUrl +
        (CFG.geoUrl.indexOf("?") === -1 ? "?" : "&") +
        "lat=" +
        d.lat +
        "&lng=" +
        d.lng,
      { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } },
    )
      .then(function (r) {
        return r.text();
      })
      .then(function (t) {
        var i = t.indexOf("<");
        if (i !== -1) t = t.slice(0, i);
        t = t.trim();
        try {
          var j = JSON.parse(t);
          t = j.address || j.name || t;
        } catch (e) {}
        addrCache[d.id] = t;
        cb(t);
      })
      .catch(function () {
        cb("");
      });
  }
  function openDevicePopup(id) {
    var d = state.index[id];
    if (!d || d.lat == null) return;
    var status = classify(d);
    var speed = d.speed_text || (Math.round(parseFloat(d.speed) || 0) + " km/h");
    var html =
      '<div class="tp-dev-popup" data-status="' +
      status +
      '"><div class="tp-dev-popup__head"><span class="tp-dev-popup__dot"></span>' +
      '<span class="tp-dev-popup__name">' +
      esc(d.name || "#" + d.id) +
      "</span></div>" +
      '<div class="tp-dev-popup__status">' +
      esc(STATUS_LABELS[d.online] || d.online || "—") +
      "</div><dl class=\"tp-dev-popup__defs\">" +
      (d.plate_number ? "<dt>Placa</dt><dd>" + esc(d.plate_number) + "</dd>" : "") +
      "<dt>Velocidad</dt><dd>" +
      esc(speed) +
      "</dd><dt>Hora</dt><dd>" +
      esc(d.time || "—") +
      '</dd><dt>Ubicación</dt><dd data-pop-addr>Cargando…</dd></dl>' +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm tp-full" data-pop-details>Ver detalles</button></div>';
    if (!state.popup) state.popup = L.popup({ maxWidth: 280, className: "tp-dev-popup-wrap" });
    state.popup.setLatLng([parseFloat(d.lat), parseFloat(d.lng)]).setContent(html).openOn(state.map);
    var el = state.popup.getElement();
    fetchAddr(d, function (a) {
      if (!state.popup || !state.popup.isOpen()) return;
      var slot = el && el.querySelector("[data-pop-addr]");
      if (slot) slot.textContent = a || "—";
    });
    var btn = el && el.querySelector("[data-pop-details]");
    if (btn)
      btn.addEventListener("click", function () {
        selectDevice(id);
      });
  }
  function selectDevice(id) {
    state.selectedId = id;
    var d = state.index[id];
    if (d && d.lat != null)
      state.map.setView([parseFloat(d.lat), parseFloat(d.lng)], Math.max(state.map.getZoom(), 14));
    root.setAttribute("data-drawer-open", "true");
    var drawer = document.getElementById("tp-live-drawer");
    if (drawer) drawer.setAttribute("aria-hidden", "false");
    if (d) fillDrawer(d);
    loadDrawerWidgets(id);
  }
  function setText(elId, val) {
    var el = document.getElementById(elId);
    if (el) el.textContent = val == null || val === "" ? "—" : val;
  }
  function fillDrawer(d) {
    setText("tp-drawer-name", d.name || "#" + d.id);
    setText("tp-drawer-plate", d.plate_number || d.imei || "—");
    var dot = document.getElementById("tp-drawer-dot");
    if (dot) {
      var st = classify(d);
      dot.className = "tp-dot tp-dot--" + (st === "moving" ? "moving" : st === "idle" ? "idle" : "offline");
    }
    setText("tp-drawer-speed", d.speed_text ? d.speed_text.replace(/[^\d.,]/g, "") : Math.round(parseFloat(d.speed) || 0));
    setText("tp-drawer-course", d.course != null ? Math.round(d.course) : "—");
    setText("tp-drawer-stop", d.stop_duration_text || "—");
    setText("tp-drawer-distance", d.total_distance != null ? d.total_distance : "—");
    setText("tp-drawer-lastseen", d.time || "—");
    setText("tp-drawer-coords", d.lat != null ? parseFloat(d.lat).toFixed(5) + ", " + parseFloat(d.lng).toFixed(5) : "—");
    setText("tp-drawer-altitude", d.altitude != null ? d.altitude + " m" : "—");
    setText(
      "tp-drawer-engine",
      d.engine_status === true || d.engine_status === "on" ? "Encendido" : d.engine_status != null ? "Apagado" : "—",
    );
    setText("tp-drawer-imei", d.imei || "—");
    setText("tp-drawer-model", d.device_model || "—");
    setText("tp-drawer-sim", d.sim_number || "—");
    var sr = document.getElementById("tp-drawer-status-row");
    if (sr) {
      var lbl = STATUS_LABELS[d.online] || d.online || "—";
      sr.innerHTML = '<span class="tp-chip" data-status="' + classify(d) + '">' + esc(lbl) + "</span>";
    }
  }
  // Reparte los widgets del device: .tp-dw--side → drawer, .tp-dw--bottom → bottombar.
  function loadDrawerWidgets(id) {
    var box = document.getElementById("tp-drawer-widgets");
    var bottom = document.getElementById("tp-live-bottombar-cards");
    if (box) box.innerHTML = '<div class="tp-text-xs tp-text-muted" style="padding:8px;">Cargando…</div>';
    var base = CFG.devicesUrl || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/widgets", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (state.selectedId != id) return;
        var sideHtml = "",
          bottomHtml = "";
        if (res && res.ok && res.html) {
          var tmp = document.createElement("div");
          tmp.innerHTML = res.html;
          Array.prototype.forEach.call(tmp.querySelectorAll(".tp-dw--side"), function (n) {
            sideHtml += n.outerHTML;
          });
          // La barra inferior usa cards horizontales: tomamos las .tp-card de
          // dentro de .tp-dw--bottom como hijos directos (no el wrapper).
          Array.prototype.forEach.call(tmp.querySelectorAll(".tp-dw--bottom > .tp-card"), function (n) {
            bottomHtml += n.outerHTML;
          });
          // Si la blade no agrupa, todo va al drawer.
          if (!sideHtml && !bottomHtml) sideHtml = res.html;
        }
        if (box) box.innerHTML = sideHtml || "";
        if (bottom) bottom.innerHTML = bottomHtml || "";
        if (bottomHtml.trim()) {
          root.setAttribute("data-bottombar-open", "true");
          root.removeAttribute("data-bottombar-collapsed");
        } else {
          root.removeAttribute("data-bottombar-open");
        }
      })
      .catch(function () {});
  }

  // -----------------------------------------------------------
  // Lista (búsqueda + estado + grupos, con cabeceras colapsables)
  // -----------------------------------------------------------
  function recompute() {
    var q = state.searchQuery.trim().toLowerCase();
    var sf = state.statusFilter;
    var counts = { all: state.devices.length, moving: 0, idle: 0, offline: 0 };
    state.filtered = state.devices.filter(function (d) {
      var c = classify(d);
      if (c === "moving") counts.moving++;
      else if (c === "idle") counts.idle++;
      else counts.offline++;
      if (sf !== "all" && c !== sf) return false;
      if (q) {
        var hay = (d.name || "") + " " + (d.plate_number || "") + " " + (d.imei || "");
        if (hay.toLowerCase().indexOf(q) === -1) return false;
      }
      return true;
    });
    state.filtered.sort(function (a, b) {
      var r = { moving: 0, idle: 1, offline: 2 };
      var ra = r[classify(a)],
        rb = r[classify(b)];
      if (ra !== rb) return ra - rb;
      return (a.name || "").localeCompare(b.name || "");
    });
    ["all", "moving", "idle", "offline"].forEach(function (k) {
      var n = root.querySelector('[data-count="' + k + '"]');
      if (n) n.textContent = counts[k];
    });
    var sM = document.getElementById("tp-live-stat-moving");
    if (sM) sM.textContent = counts.moving;
    var sI = document.getElementById("tp-live-stat-idle");
    if (sI) sI.textContent = counts.idle;
    var sO = document.getElementById("tp-live-stat-offline");
    if (sO) sO.textContent = counts.offline;
    if (window.tpUpdateLiveStatus)
      window.tpUpdateLiveStatus({ moving: counts.moving, idle: counts.idle, offline: counts.offline });
  }

  function groupTitle(gid) {
    if (!gid) return "Sin grupo";
    for (var i = 0; i < state.groups.length; i++)
      if (state.groups[i].id === gid) return state.groups[i].title;
    return "Grupo " + gid;
  }
  function rowTemplate(d) {
    var status = classify(d);
    var speedNum = Math.round(parseFloat(d.speed) || 0);
    var speedTxt = d.speed_text || speedNum + " km/h";
    var meta = d.plate_number || d.imei || "";
    var active = d.active !== false;
    return (
      '<div class="tp-live__row" data-id="' +
      d.id +
      '" data-status="' +
      status +
      '" role="option">' +
      '<label class="tp-live__row-active" title="Mostrar en el mapa"><input type="checkbox" data-id="' +
      d.id +
      '"' +
      (active ? " checked" : "") +
      "></label>" +
      '<span class="tp-live__row-dot"></span>' +
      '<div class="tp-live__row-main"><div class="tp-live__row-name">' +
      esc(d.name || "#" + d.id) +
      '</div><div class="tp-live__row-meta">' +
      esc(meta) +
      "</div></div>" +
      '<div class="tp-live__row-end"><span class="tp-live__row-speed' +
      (speedNum === 0 ? " tp-live__row-speed--zero" : "") +
      '">' +
      esc(speedTxt) +
      '</span><span class="tp-live__row-time">' +
      fmtTimeAgo(d.acktimestamp || d.timestamp) +
      "</span></div>" +
      '<button type="button" class="tp-live__row-menu" data-id="' +
      d.id +
      '" title="Acciones"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg></button>' +
      "</div>"
    );
  }
  var listEl = document.getElementById("tp-live-list");
  function renderList() {
    if (!listEl) return;
    var sk = listEl.querySelector(".tp-live__skeleton");
    if (sk) sk.remove();
    var empty = root.querySelector(".tp-live__empty");
    if (!state.filtered.length) {
      listEl.innerHTML = "";
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    var html = "";
    if (state.grouped) {
      var byG = {};
      state.filtered.forEach(function (d) {
        var g = d.group_id || 0;
        (byG[g] = byG[g] || []).push(d);
      });
      var order = [];
      state.groups.forEach(function (g) {
        if (byG[g.id]) order.push(g.id);
      });
      Object.keys(byG).forEach(function (k) {
        var g = parseInt(k, 10);
        if (order.indexOf(g) === -1) order.push(g);
      });
      order.forEach(function (g) {
        var collapsed = !!state.collapsedGroups[g];
        html +=
          '<div class="tp-live__group" data-gid="' +
          g +
          '"' +
          (collapsed ? ' data-collapsed="1"' : "") +
          '><svg class="tp-live__group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg><span class="tp-live__group-title">' +
          esc(groupTitle(g)) +
          '</span><span class="tp-live__group-count">' +
          byG[g].length +
          "</span></div>";
        if (!collapsed) html += byG[g].map(rowTemplate).join("");
      });
    } else {
      html = state.filtered.map(rowTemplate).join("");
    }
    listEl.innerHTML = html;
  }

  // -----------------------------------------------------------
  // Kebab de acciones (server-rendered, /actions)
  // -----------------------------------------------------------
  var rowMenu = null;
  function closeRowMenu() {
    if (rowMenu) {
      rowMenu.remove();
      rowMenu = null;
      document.removeEventListener("click", closeRowMenu);
    }
  }
  function openRowMenu(btn, id) {
    closeRowMenu();
    rowMenu = document.createElement("div");
    rowMenu.className = "tp-row-menu";
    rowMenu.innerHTML = '<div class="tp-row-menu__loading">Cargando…</div>';
    document.body.appendChild(rowMenu);
    var r = btn.getBoundingClientRect();
    rowMenu.style.position = "fixed";
    rowMenu.style.left = Math.min(r.right + 6, window.innerWidth - 250) + "px";
    rowMenu.style.top = Math.max(8, r.top) + "px";
    rowMenu.addEventListener("click", function (e) {
      var it = e.target.closest(".tp-row-menu__item");
      if (!it) return;
      var act = it.getAttribute("data-act");
      closeRowMenu();
      if (act === "history")
        location.href = "/ui/v2/history?device=" + id + "&range=" + (it.getAttribute("data-range") || "today");
      else if (act === "follow") selectDevice(id);
      else if (act === "edit") {
        if (window.tpDeviceModal) window.tpDeviceModal.openEdit(id, function () { fetchDevices(true); });
      } else if (act === "pending" && window.tpModal && tpModal.toast)
        tpModal.toast("Función disponible próximamente.", "info");
    });
    var base = CFG.devicesUrl || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/actions", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (rowMenu) rowMenu.innerHTML = res && res.ok && res.html ? res.html : '<div class="tp-row-menu__loading">Sin acciones.</div>';
      })
      .catch(function () {});
    setTimeout(function () {
      document.addEventListener("click", closeRowMenu);
    }, 0);
  }

  function csrf() {
    return (
      (window.Talentus && window.Talentus.csrf) ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ""
    );
  }
  function persistActive(id, active) {
    var fd = new FormData();
    fd.append("id", id);
    fd.append("active", active ? "1" : "0");
    fetch("/devices/change_active", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-CSRF-TOKEN": csrf(), "X-Requested-With": "XMLHttpRequest" },
    }).catch(function () {});
  }

  if (listEl) {
    listEl.addEventListener("click", function (e) {
      var gh = e.target.closest(".tp-live__group");
      if (gh) {
        var g = parseInt(gh.getAttribute("data-gid"), 10);
        state.collapsedGroups[g] = !state.collapsedGroups[g];
        renderList();
        return;
      }
      if (e.target.closest(".tp-live__row-active")) return;
      var mb = e.target.closest(".tp-live__row-menu");
      if (mb) {
        e.stopPropagation();
        openRowMenu(mb, parseInt(mb.getAttribute("data-id"), 10));
        return;
      }
      var row = e.target.closest(".tp-live__row");
      if (row) selectDevice(parseInt(row.getAttribute("data-id"), 10));
    });
    listEl.addEventListener("change", function (e) {
      var cb = e.target;
      if (!cb.matches || !cb.matches('.tp-live__row-active input[type="checkbox"]')) return;
      var id = parseInt(cb.getAttribute("data-id"), 10);
      var d = state.index[id];
      if (!d) return;
      d.active = cb.checked;
      renderMarkers();
      persistActive(id, cb.checked);
    });
  }

  // -----------------------------------------------------------
  // Feed de devices (todas las páginas) + polling de deltas
  // -----------------------------------------------------------
  var statusText = document.getElementById("tp-live-status-text");
  var inFlight = null;
  function commit(rows, total) {
    state.devices = rows;
    state.total = total || rows.length;
    state.index = {};
    rows.forEach(function (d) {
      state.index[d.id] = d;
    });
    recompute();
    renderList();
    renderMarkers();
    if (!state.bootstrapped && state.filtered.length) {
      fitToFleet();
      state.bootstrapped = true;
    }
  }
  function fetchDevices(manual) {
    if (inFlight) inFlight.abort();
    var ctrl = new AbortController();
    inFlight = ctrl;
    if (statusText) statusText.textContent = manual ? "Refrescando…" : "Actualizando…";
    var acc = [],
      total = 0;
    function page(p) {
      var sep = CFG.devicesUrl.indexOf("?") === -1 ? "?" : "&";
      return fetch(CFG.devicesUrl + sep + "page=" + p, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        signal: ctrl.signal,
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (j) {
          acc = acc.concat((j && j.data) || []);
          if (j && Array.isArray(j.groups)) state.groups = j.groups;
          var pg = (j && j.pagination) || null;
          if (pg && pg.total != null) total = pg.total;
          commit(acc, total);
          if (pg && pg.current_page < pg.last_page) {
            if (statusText) statusText.textContent = "Cargando… " + acc.length + "/" + (total || "?");
            return page(pg.current_page + 1);
          }
          if (statusText)
            statusText.textContent = (total || acc.length) + " vehículos · " + new Date().toLocaleTimeString();
        });
    }
    page(1)
      .catch(function (e) {
        if (e.name !== "AbortError" && statusText) statusText.textContent = "Error al cargar";
      })
      .finally(function () {
        inFlight = null;
      });
  }
  function pollUpdates() {
    if (document.visibilityState !== "visible") return;
    fetch(CFG.updatesUrl + "?time=" + encodeURIComponent(state.checkTime || "") + "&_=" + Date.now(), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (res && res.time) state.checkTime = res.time;
        var items = (res && res.items) || [];
        if (!items.length) return;
        items.forEach(function (d) {
          if (state.index[d.id]) {
            for (var k in d) if (Object.prototype.hasOwnProperty.call(d, k)) state.index[d.id][k] = d[k];
          } else {
            state.index[d.id] = d;
            state.devices.push(d);
          }
        });
        recompute();
        renderList();
        renderMarkers();
      })
      .catch(function () {});
  }

  // -----------------------------------------------------------
  // Controles + filtros + paneles
  // -----------------------------------------------------------
  function wire() {
    var sf = document.getElementById("tp-live-status-filter");
    if (sf)
      sf.addEventListener("click", function (e) {
        var b = e.target.closest("[data-filter]");
        if (!b) return;
        sf.querySelectorAll("[data-filter]").forEach(function (x) {
          x.setAttribute("aria-selected", "false");
        });
        b.setAttribute("aria-selected", "true");
        state.statusFilter = b.getAttribute("data-filter");
        recompute();
        renderList();
        renderMarkers();
      });
    var search = document.getElementById("tp-live-search");
    var st;
    if (search)
      search.addEventListener("input", function () {
        clearTimeout(st);
        st = setTimeout(function () {
          state.searchQuery = search.value || "";
          recompute();
          renderList();
        }, 120);
      });
    var gt = document.getElementById("tp-live-group-toggle");
    if (gt) {
      var sync = function () {
        gt.setAttribute("aria-pressed", state.grouped ? "true" : "false");
        gt.classList.toggle("is-active", state.grouped);
      };
      sync();
      gt.addEventListener("click", function () {
        state.grouped = !state.grouped;
        try {
          localStorage.setItem("tp_live_grouped", state.grouped ? "1" : "0");
        } catch (e) {}
        sync();
        renderList();
      });
    }
    var refresh = document.getElementById("tp-live-refresh");
    if (refresh) refresh.addEventListener("click", function () { fetchDevices(true); });
    var fit = document.getElementById("tp-live-fit");
    if (fit) fit.addEventListener("click", fitToFleet);
    var zi = document.getElementById("tp-live-zoom-in");
    if (zi) zi.addEventListener("click", function () { state.map.zoomIn(); });
    var zo = document.getElementById("tp-live-zoom-out");
    if (zo) zo.addEventListener("click", function () { state.map.zoomOut(); });
    var fs = document.getElementById("tp-live-fullscreen");
    if (fs)
      fs.addEventListener("click", function () {
        var el = document.getElementById("tp-live-map");
        if (!document.fullscreenElement) (el.requestFullscreen || function () {}).call(el);
        else document.exitFullscreen();
        setTimeout(function () { state.map.invalidateSize(); }, 250);
      });
    var styleBtn = document.getElementById("tp-live-style-toggle");
    var menu = document.getElementById("tp-live-providers-menu");
    if (styleBtn && menu)
      styleBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        menu.hidden = !menu.hidden;
      });
    document.addEventListener("click", function () {
      if (menu) menu.hidden = true;
    });

    // Geolocalización
    var geo = document.getElementById("tp-live-geolocate");
    if (geo)
      geo.addEventListener("click", function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (p) {
          var ll = [p.coords.latitude, p.coords.longitude];
          if (state.geoMarker) state.geoMarker.setLatLng(ll);
          else state.geoMarker = L.circleMarker(ll, { radius: 7, color: "#2e63d9", fillColor: "#2e63d9", fillOpacity: 0.6 }).addTo(state.map);
          state.map.setView(ll, 15);
        });
      });
    // Auto-seguir flota
    var af = document.getElementById("tp-live-autofollow");
    if (af)
      af.addEventListener("click", function () {
        state.autoFit = !state.autoFit;
        af.setAttribute("aria-pressed", state.autoFit ? "true" : "false");
        if (state.autoFit) fitToFleet();
      });

    // Toggles de capa
    Array.prototype.forEach.call(root.querySelectorAll(".tp-live__toggle[data-toggle]"), function (btn) {
      var key = btn.getAttribute("data-toggle");
      state.toggles[key] = btn.getAttribute("aria-pressed") === "true";
      btn.addEventListener("click", function () {
        state.toggles[key] = !state.toggles[key];
        btn.setAttribute("aria-pressed", state.toggles[key] ? "true" : "false");
        applyToggle(key);
      });
    });
    // Estado inicial
    applyToggle("devices");

    // Bottombar (widgets inferiores)
    var bbCollapse = document.getElementById("tp-bottombar-collapse");
    if (bbCollapse)
      bbCollapse.addEventListener("click", function () {
        root.toggleAttribute("data-bottombar-collapsed");
      });
    var bbExpand = document.getElementById("tp-bottombar-expand");
    if (bbExpand)
      bbExpand.addEventListener("click", function () {
        root.setAttribute("data-bottombar-open", "true");
        root.removeAttribute("data-bottombar-collapsed");
      });
    var bbConfig = document.getElementById("tp-bottombar-config");
    if (bbConfig)
      bbConfig.addEventListener("click", function () {
        openWidgetsConfig(bbConfig.getAttribute("data-config-url"), bbConfig.getAttribute("data-save-url"));
      });

    // "Centrar en mapa" del drawer
    var center = document.getElementById("tp-drawer-center");
    if (center)
      center.addEventListener("click", function () {
        if (state.selectedId) selectDevice(state.selectedId);
      });

    // Drawer close
    var dc = document.getElementById("tp-drawer-close");
    if (dc)
      dc.addEventListener("click", function () {
        root.removeAttribute("data-drawer-open");
        root.removeAttribute("data-bottombar-open");
        state.selectedId = null;
      });

    // Conmutador de paneles
    Array.prototype.forEach.call(document.querySelectorAll(".tp-live__paneltab"), function (t) {
      t.addEventListener("click", function () {
        switchPanel(t.getAttribute("data-panel-tab"));
      });
    });
    // Rail intercept (Live/Geocercas/POIs) → cambiar panel sin recargar
    Array.prototype.forEach.call(document.querySelectorAll(".tp-rail a[href]"), function (a) {
      var href = a.getAttribute("href") || "";
      var toPois = href.indexOf("panel=pois") !== -1;
      var toZones = href.indexOf("panel=zones") !== -1;
      var toLive = !toPois && !toZones && /\/ui\/v2\/live(\?|$)/.test(href);
      if (!toPois && !toZones && !toLive) return;
      a.addEventListener("click", function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
        e.preventDefault();
        var panel = toPois ? "pois" : toZones ? "zones" : "objects";
        switchPanel(panel);
        try {
          history.replaceState(null, "", panel === "objects" ? "/ui/v2/live" : "/ui/v2/live?panel=" + panel);
        } catch (err) {}
      });
    });
    try {
      var ip = new URLSearchParams(location.search).get("panel");
      if (ip === "pois" || ip === "zones") switchPanel(ip);
    } catch (e) {}
  }

  function switchPanel(name) {
    Array.prototype.forEach.call(document.querySelectorAll(".tp-live__panel"), function (p) {
      p.hidden = p.getAttribute("data-panel") !== name;
    });
    Array.prototype.forEach.call(document.querySelectorAll(".tp-live__paneltab"), function (t) {
      t.classList.toggle("is-active", t.getAttribute("data-panel-tab") === name);
    });
    // Zonas/POIs: render en el mapa Leaflet (se conectan en el commit siguiente).
    if (window.tpLiveZones && name === "zones") window.tpLiveZones.activate();
    if (window.tpLivePois && name === "pois") window.tpLivePois.activate();
  }

  // -----------------------------------------------------------
  // API pública para los módulos de paneles (zones/pois en Leaflet)
  // -----------------------------------------------------------
  window.tpLive = {
    map: function () {
      return state.map;
    },
    refresh: function () {
      fetchDevices(true);
    },
    selectDevice: selectDevice,
  };

  // -----------------------------------------------------------
  // Init
  // -----------------------------------------------------------
  initMap();
  wire();
  fetchDevices(false);
  state.pollHandle = setInterval(pollUpdates, CFG.pollInterval);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") pollUpdates();
  });
})();
