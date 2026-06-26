/* =====================================================
 * TalentusPro · History & Replay · Phase 7
 * - Device picker + datetime range + quick presets
 * - Fetches positions from /talentus/api/history
 * - Renders polyline + start/end + stop markers on MapLibre
 * - Scrubber with playback at 1×/4×/16×/64×
 * - Animated cursor marker via requestAnimationFrame
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-history");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
  var DEVICES_URL = root.getAttribute("data-devices-url");
  var LEGACY_BASE = "/reports";

  var MAP_CFG = (function () {
    try {
      return JSON.parse(
        document.getElementById("tp-history-map-config").textContent,
      );
    } catch (e) {
      return { mapId: null, keys: {}, openmaptilesUrl: "" };
    }
  })();

  var state = {
    deviceId: null,
    deviceName: "",
    positions: [],
    stops: [],
    kpis: null,
    from: "",
    to: "",
    cursorIdx: 0,
    playing: false,
    rate: 4,
    rafId: null,
    lastTs: 0,
    map: null,
    cursorMarker: null,
    stopMarkers: [],
    endMarkers: [],
  };

  function $(s, c) {
    return (c || document).querySelector(s);
  }
  function escapeHtml(s) {
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
  function fmtDur(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600),
      m = Math.floor((sec % 3600) / 60),
      s = sec % 60;
    if (h > 0) return h + "h " + (m < 10 ? "0" : "") + m + "m";
    if (m > 0) return m + "m " + (s < 10 ? "0" : "") + s + "s";
    return s + "s";
  }
  function fmtTime(t) {
    try {
      var d = new Date(t.replace(" ", "T"));
      return d.toLocaleString();
    } catch (e) {
      return t;
    }
  }
  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }
  function toLocalInput(d) {
    return (
      d.getFullYear() +
      "-" +
      pad2(d.getMonth() + 1) +
      "-" +
      pad2(d.getDate()) +
      "T" +
      pad2(d.getHours()) +
      ":" +
      pad2(d.getMinutes())
    );
  }
  function toApi(local) {
    if (!local) return "";
    // datetime-local: 2026-05-30T08:00
    return local.replace("T", " ") + ":00";
  }

  function fallbackStyle() {
    var dark = document.documentElement.getAttribute("data-theme") === "dark";
    if (dark)
      return "https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json";
    return {
      version: 8,
      sources: {
        osm: {
          type: "raster",
          tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
          tileSize: 256,
          attribution: "© OpenStreetMap",
        },
      },
      layers: [{ id: "osm", type: "raster", source: "osm" }],
    };
  }

  /* ---------- DEVICE PICKER ---------- */
  function loadDevices() {
    var sel = $("#tp-hist-device");
    return fetch(DEVICES_URL + "?limit=1000", {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        var items = j && j.data ? j.data : j.items || [];
        sel.innerHTML =
          '<option value="">— Selecciona vehículo —</option>' +
          items
            .map(function (d) {
              return (
                '<option value="' +
                d.id +
                '">' +
                escapeHtml(d.name || "#" + d.id) +
                "</option>"
              );
            })
            .join("");

        // Honor ?device=ID from query string
        var qs = new URLSearchParams(location.search);
        var preset = qs.get("device");
        if (preset) sel.value = preset;
      })
      .catch(function () {
        sel.innerHTML =
          '<option value="">No se pudieron cargar vehículos</option>';
      });
  }

  /* ---------- DATE PRESETS ---------- */
  function applyQuick(key) {
    var now = new Date();
    var from = new Date(now),
      to = new Date(now);
    if (key === "1h") {
      from.setHours(now.getHours() - 1);
    } else if (key === "today") {
      from.setHours(0, 0, 0, 0);
    } else if (key === "yesterday") {
      from.setDate(now.getDate() - 1);
      from.setHours(0, 0, 0, 0);
      to.setDate(now.getDate() - 1);
      to.setHours(23, 59, 0, 0);
    } else if (key === "7d") {
      from.setDate(now.getDate() - 7);
    }
    $("#tp-hist-from").value = toLocalInput(from);
    $("#tp-hist-to").value = toLocalInput(to);
  }

  /* ---------- MAP ---------- */
  function initMap() {
    if (state.map) return Promise.resolve(state.map);
    state.map = new maplibregl.Map({
      container: "tp-history-map",
      style: fallbackStyle(),
      center: [-77.0428, -12.0464], // Lima default
      zoom: 11,
      attributionControl: true,
    });
    state.map.addControl(
      new maplibregl.NavigationControl({ showCompass: false }),
      "top-right",
    );
    return new Promise(function (res) {
      state.map.on("load", function () {
        res(state.map);
      });
    });
  }

  function clearMap() {
    if (!state.map) return;
    ["hist-line", "hist-line-shadow"].forEach(function (id) {
      if (state.map.getLayer(id)) state.map.removeLayer(id);
    });
    if (state.map.getSource("hist-route")) state.map.removeSource("hist-route");
    state.stopMarkers.forEach(function (m) {
      m.remove();
    });
    state.stopMarkers = [];
    state.endMarkers.forEach(function (m) {
      m.remove();
    });
    state.endMarkers = [];
    if (state.cursorMarker) {
      state.cursorMarker.remove();
      state.cursorMarker = null;
    }
  }

  function makeMarkerEl(cls, label) {
    var el = document.createElement("div");
    el.className = "tp-hist-marker " + cls;
    if (label) el.textContent = label;
    return el;
  }

  function renderRoute() {
    clearMap();
    if (!state.positions.length) return;

    var coords = state.positions.map(function (p) {
      return [p.lng, p.lat];
    });

    state.map.addSource("hist-route", {
      type: "geojson",
      data: {
        type: "Feature",
        geometry: { type: "LineString", coordinates: coords },
      },
    });
    state.map.addLayer({
      id: "hist-line-shadow",
      type: "line",
      source: "hist-route",
      layout: { "line-join": "round", "line-cap": "round" },
      paint: {
        "line-color": "rgba(0,0,0,0.25)",
        "line-width": 6,
        "line-blur": 1,
      },
    });
    state.map.addLayer({
      id: "hist-line",
      type: "line",
      source: "hist-route",
      layout: { "line-join": "round", "line-cap": "round" },
      paint: { "line-color": "#122F71", "line-width": 3.5 },
    });

    // Start marker
    var start = state.positions[0];
    var end = state.positions[state.positions.length - 1];
    state.endMarkers.push(
      new maplibregl.Marker({
        element: makeMarkerEl("tp-hist-marker--start", "A"),
      })
        .setLngLat([start.lng, start.lat])
        .addTo(state.map),
    );
    state.endMarkers.push(
      new maplibregl.Marker({
        element: makeMarkerEl("tp-hist-marker--end", "B"),
      })
        .setLngLat([end.lng, end.lat])
        .addTo(state.map),
    );

    // Stop markers
    state.stops.forEach(function (s, i) {
      var el = makeMarkerEl("tp-hist-marker--stop", String(i + 1));
      var marker = new maplibregl.Marker({ element: el })
        .setLngLat([s.lng, s.lat])
        .setPopup(
          new maplibregl.Popup({ offset: 14 }).setHTML(
            "<strong>Parada " +
              (i + 1) +
              "</strong><br>" +
              "<small>" +
              escapeHtml(fmtDur(s.duration)) +
              "</small><br>" +
              "<small>" +
              escapeHtml(s.from) +
              "</small>",
          ),
        )
        .addTo(state.map);
      el.addEventListener("click", function () {
        goToStop(s.idx);
      });
      state.stopMarkers.push(marker);
    });

    // Cursor marker
    state.cursorMarker = new maplibregl.Marker({
      element: makeMarkerEl("tp-hist-marker--cursor", ""),
    })
      .setLngLat([start.lng, start.lat])
      .addTo(state.map);

    // Fit bounds
    var bounds = new maplibregl.LngLatBounds();
    coords.forEach(function (c) {
      bounds.extend(c);
    });
    state.map.fitBounds(bounds, { padding: 60, duration: 600 });
  }

  /* ---------- KPIs ---------- */
  function renderKpis() {
    var k = state.kpis || {};
    $("#tp-hist-kpis").hidden = false;
    $("#kpi-dist").textContent = k.distance_km != null ? k.distance_km : "—";
    $("#kpi-dur").textContent =
      k.duration_sec != null ? fmtDur(k.duration_sec) : "—";
    $("#kpi-max").textContent = k.max_speed != null ? k.max_speed : "—";
    $("#kpi-avg").textContent = k.avg_speed != null ? k.avg_speed : "—";
    $("#kpi-stops").textContent = k.stops != null ? k.stops : "—";
    $("#kpi-pts").textContent = k.points != null ? k.points : "—";
  }

  /* ---------- SCRUBBER ---------- */
  function renderScrubber() {
    if (!state.positions.length) {
      $("#tp-hist-scrub").hidden = true;
      return;
    }
    $("#tp-hist-scrub").hidden = false;

    var range = $("#tp-hist-range");
    range.max = String(state.positions.length - 1);
    range.value = "0";

    $("#tp-hist-time-start").textContent = fmtTime(state.positions[0].t);
    $("#tp-hist-time-end").textContent = fmtTime(
      state.positions[state.positions.length - 1].t,
    );

    // Stop marks on rail
    var marksEl = $("#tp-hist-marks");
    marksEl.innerHTML = "";
    var total = state.positions.length - 1;
    if (total > 0) {
      state.stops.forEach(function (s) {
        var pct = (s.idx / total) * 100;
        var dot = document.createElement("span");
        dot.className = "tp-history__scrub-mark";
        dot.style.left = pct + "%";
        dot.title = "Parada " + fmtDur(s.duration);
        marksEl.appendChild(dot);
      });
    }

    moveCursor(0);
  }

  function moveCursor(idx) {
    idx = Math.max(0, Math.min(state.positions.length - 1, idx | 0));
    state.cursorIdx = idx;
    var p = state.positions[idx];
    if (!p) return;
    if (state.cursorMarker) state.cursorMarker.setLngLat([p.lng, p.lat]);
    $("#tp-hist-range").value = String(idx);
    $("#tp-hist-cursor-time").textContent = fmtTime(p.t);
    $("#tp-hist-cursor-speed").textContent =
      (p.spd != null ? p.spd.toFixed(0) : "—") + " km/h";
  }

  function goToStop(positionIdx) {
    moveCursor(positionIdx);
    var p = state.positions[positionIdx];
    if (p && state.map)
      state.map.flyTo({
        center: [p.lng, p.lat],
        zoom: Math.max(state.map.getZoom(), 15),
      });
  }

  function nextStop(dir) {
    if (!state.stops.length) return;
    var cur = state.cursorIdx;
    var idxs = state.stops.map(function (s) {
      return s.idx;
    });
    if (dir > 0) {
      for (var i = 0; i < idxs.length; i++)
        if (idxs[i] > cur) return goToStop(idxs[i]);
      goToStop(idxs[idxs.length - 1]);
    } else {
      for (var j = idxs.length - 1; j >= 0; j--)
        if (idxs[j] < cur) return goToStop(idxs[j]);
      goToStop(idxs[0]);
    }
  }

  /* ---------- PLAYBACK ---------- */
  function tick(now) {
    if (!state.playing) return;
    var dtMs = now - state.lastTs;
    state.lastTs = now;
    // Advance: each real second moves rate * ~5 indices.
    // Better: advance based on position timestamps so playback is time-accurate.
    var sim = (dtMs * state.rate) / 1000; // simulated seconds elapsed
    var cur = state.positions[state.cursorIdx];
    var nextIdx = state.cursorIdx;
    var targetTs = cur.ts + sim;
    while (
      nextIdx + 1 < state.positions.length &&
      state.positions[nextIdx + 1].ts <= targetTs
    ) {
      nextIdx++;
    }
    if (nextIdx >= state.positions.length - 1) {
      moveCursor(state.positions.length - 1);
      pause();
      return;
    }
    moveCursor(nextIdx);
    state.rafId = requestAnimationFrame(tick);
  }

  function play() {
    if (!state.positions.length) return;
    if (state.cursorIdx >= state.positions.length - 1) moveCursor(0);
    state.playing = true;
    $("#tp-hist-play-icon").innerHTML =
      '<path d="M6 5h4v14H6zM14 5h4v14h-4z"/>';
    state.lastTs = performance.now();
    state.rafId = requestAnimationFrame(tick);
  }

  function pause() {
    state.playing = false;
    $("#tp-hist-play-icon").innerHTML = '<path d="M8 5v14l11-7z"/>';
    if (state.rafId) cancelAnimationFrame(state.rafId);
  }

  /* ---------- LOAD ---------- */
  function load() {
    var deviceId = $("#tp-hist-device").value;
    var from = $("#tp-hist-from").value;
    var to = $("#tp-hist-to").value;
    if (!deviceId) {
      alert("Selecciona un vehículo.");
      return;
    }
    if (!from || !to) {
      alert("Selecciona un rango de fechas.");
      return;
    }

    pause();
    state.deviceId = deviceId;
    state.deviceName =
      $("#tp-hist-device").options[$("#tp-hist-device").selectedIndex].text;
    state.from = toApi(from);
    state.to = toApi(to);

    $("#tp-hist-empty").hidden = true;
    $("#tp-hist-loading").hidden = false;

    var qs = new URLSearchParams({
      device: deviceId,
      from: state.from,
      to: state.to,
      max: "8000",
    });
    return initMap()
      .then(function () {
        return fetch(DATA_URL + "?" + qs.toString(), {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
      })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, body: j };
        });
      })
      .then(function (resp) {
        $("#tp-hist-loading").hidden = true;
        if (!resp.ok || !resp.body.ok) {
          var err = (resp.body && resp.body.error) || "error";
          if (err === "range_too_large")
            alert(
              "Rango demasiado grande. Máx " +
                (resp.body.max_days || 30) +
                " días.",
            );
          else if (err === "forbidden") alert("Sin permisos.");
          else alert("Error: " + err);
          $("#tp-hist-empty").hidden = false;
          return;
        }
        state.positions = resp.body.positions || [];
        state.stops = resp.body.stops || [];
        state.kpis = resp.body.kpis || {};

        if (!state.positions.length) {
          $("#tp-hist-empty").hidden = false;
          $("#tp-hist-empty").innerHTML =
            '<div class="tp-history__empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg></div><h2 class="tp-h6">Sin datos</h2><p class="tp-text-muted">No hay posiciones para ese rango.</p>';
          $("#tp-hist-kpis").hidden = true;
          $("#tp-hist-scrub").hidden = true;
          return;
        }
        renderKpis();
        renderRoute();
        renderScrubber();
      })
      .catch(function (e) {
        $("#tp-hist-loading").hidden = true;
        $("#tp-hist-empty").hidden = false;
        alert("Error al cargar: " + (e.message || e));
      });
  }

  /* ---------- EVENTS ---------- */
  function bind() {
    $("#tp-hist-quick").addEventListener("click", function (e) {
      var b = e.target.closest("[data-quick]");
      if (!b) return;
      $("#tp-hist-quick")
        .querySelectorAll(".tp-seg__btn")
        .forEach(function (x) {
          x.classList.remove("is-active");
        });
      b.classList.add("is-active");
      applyQuick(b.getAttribute("data-quick"));
    });
    $("#tp-hist-load").addEventListener("click", load);
    $("#tp-hist-legacy").addEventListener("click", function () {
      var devId = state.deviceId || $("#tp-hist-device").value;
      if (!devId) {
        alert("Selecciona un vehículo.");
        return;
      }
      window.open(
        "/reports?device_id=" + encodeURIComponent(devId),
        "_blank",
        "noopener",
      );
    });
    $("#tp-hist-play").addEventListener("click", function () {
      if (state.playing) pause();
      else play();
    });
    $("#tp-hist-prev").addEventListener("click", function () {
      nextStop(-1);
    });
    $("#tp-hist-next").addEventListener("click", function () {
      nextStop(1);
    });
    $("#tp-hist-speed").addEventListener("click", function (e) {
      var b = e.target.closest("[data-rate]");
      if (!b) return;
      $("#tp-hist-speed")
        .querySelectorAll(".tp-seg__btn")
        .forEach(function (x) {
          x.classList.remove("is-active");
        });
      b.classList.add("is-active");
      state.rate = parseInt(b.getAttribute("data-rate"), 10) || 1;
    });
    $("#tp-hist-range").addEventListener("input", function (e) {
      pause();
      moveCursor(parseInt(e.target.value, 10));
    });

    // Theme reactivity: rebuild style on theme change
    var obs = new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        if (m.attributeName === "data-theme" && state.map) {
          state.map.setStyle(fallbackStyle());
          state.map.once("styledata", function () {
            if (state.positions.length) renderRoute();
          });
        }
      });
    });
    obs.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["data-theme"],
    });
  }

  /* ---------- INIT ---------- */
  function init() {
    bind();
    applyQuick("today");
    loadDevices().then(function () {
      // Honor ?device=X&from=Y&to=Z
      var qs = new URLSearchParams(location.search);
      var from = qs.get("from"),
        to = qs.get("to");
      if (from)
        try {
          $("#tp-hist-from").value = toLocalInput(
            new Date(from.replace(" ", "T")),
          );
        } catch (e) {}
      if (to)
        try {
          $("#tp-hist-to").value = toLocalInput(new Date(to.replace(" ", "T")));
        } catch (e) {}
      if (qs.get("device") && $("#tp-hist-device").value) load();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
