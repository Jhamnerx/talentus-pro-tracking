/* =====================================================
   TalentusPro · Unidades sin transmitir en una zona
   - Se dibuja un polígono o un círculo sobre el mapa de Live y se listan
     los vehículos cuya ÚLTIMA posición cae dentro y que llevan sin
     transmitir más del tiempo elegido.
   - Mismo backend que la herramienta del tema clásico
     (Frontend\SilentDevicesController): un solo cálculo para las dos UI.
   ===================================================== */
(function () {
  "use strict";

  var URLS = {
    search: "/devices/silent_in_zone",
    csv: "/devices/silent_in_zone/export",
    xlsx: "/devices/silent_in_zone/export_xlsx",
  };

  var draw = null;
  var layer = null;
  var zone = null;
  var dialog = null;

  function map() {
    return window.tpLive && tpLive.map();
  }

  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s == null ? "" : s;
    return d.innerHTML;
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : "";
  }

  /* ----- Zona ----- */
  function clear() {
    var m = map();

    if (draw) {
      draw.disable();
      draw = null;
    }

    if (layer && m) {
      m.removeLayer(layer);
    }

    layer = null;
    zone = null;
  }

  function startDraw(shape) {
    var m = map();
    if (!m) return;

    clear();

    // Se cierra el modal mientras se dibuja: si no, tapa el mapa.
    if (dialog && window.tpModal) tpModal.close(dialog);

    var opciones = { shapeOptions: { color: "#ef4444", weight: 2, fillOpacity: 0.08 } };

    draw = shape === "circle"
      ? new L.Draw.Circle(m, Object.assign({ metric: true }, opciones))
      : new L.Draw.Polygon(m, Object.assign({ allowIntersection: false, showArea: false }, opciones));

    draw.enable();

    // El evento de leaflet-draw es global y lo comparten zonas y rutas, así
    // que solo se atiende mientras esta herramienta tiene un dibujo activo.
    m.once(L.Draw.Event.CREATED, function (e) {
      if (!draw) return;
      draw = null;

      layer = e.layer;
      m.addLayer(layer);

      if (typeof layer.getRadius === "function") {
        var c = layer.getLatLng();
        // Radio en metros, que es lo que da Leaflet y lo que espera el
        // backend; no se convierte a la unidad del usuario.
        zone = { circle: { lat: c.lat, lng: c.lng, radius: layer.getRadius() } };
      } else {
        zone = {
          polygon: layer.getLatLngs()[0].map(function (p) {
            return { lat: p.lat, lng: p.lng };
          }),
        };
      }

      open();
    });
  }

  /* ----- Modal ----- */
  function bodyHtml() {
    return (
      '<div class="tp-form">' +
      '<div class="tp-row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">' +
      '<label class="tp-form__field" style="min-width:150px;"><span class="tp-form__label">Forma</span>' +
      '<select class="tp-input" data-sd-shape>' +
      '<option value="polygon">' + tpT('ui.polygon') + '</option>' +
      '<option value="circle">' + tpT('ui.circle') + '</option>' +
      "</select></label>" +
      '<label class="tp-form__field" style="min-width:180px;"><span class="tp-form__label">' + tpT('ui.not_transmitting_over') + '</span>' +
      '<select class="tp-input" data-sd-minutes>' +
      '<option value="30">30 min</option><option value="60">1 h</option>' +
      '<option value="120" selected>2 h</option><option value="360">6 h</option>' +
      '<option value="720">12 h</option><option value="1440">24 h</option>' +
      '<option value="4320">' + tpT('ui.days_3') + '</option><option value="10080">' + tpT('ui.days_7') + '</option>' +
      "</select></label>" +
      '<button type="button" class="tp-btn tp-btn--primary tp-btn--sm" data-sd-draw>Dibujar zona</button>' +
      '<span style="margin-left:auto; display:flex; gap:6px;">' +
      '<button type="button" class="tp-btn tp-btn--outline tp-btn--sm" data-sd-csv hidden>CSV</button>' +
      '<button type="button" class="tp-btn tp-btn--outline tp-btn--sm" data-sd-xlsx hidden>XLSX</button>' +
      "</span></div>" +
      '<div data-sd-result style="margin-top:14px;">' +
      '<p class="tp-text-muted tp-text-sm">' + tpT('ui.draw_zone_silent_help') + '</p>' +
      "</div></div>"
    );
  }

  function open() {
    if (!window.tpModal) return;

    dialog = tpModal.open({
      title: "Unidades sin transmitir",
      size: "lg",
      body: bodyHtml(),
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      onMount: function (d) {
        dialog = d;

        d.querySelector("[data-sd-draw]").addEventListener("click", function () {
          startDraw(d.querySelector("[data-sd-shape]").value);
        });

        // Cambiar de forma invalida la zona dibujada: ya no es la que dice
        // el selector.
        d.querySelector("[data-sd-shape]").addEventListener("change", function () {
          clear();
          pintar(null);
        });

        d.querySelector("[data-sd-minutes]").addEventListener("change", function () {
          if (zone) search();
        });

        d.querySelector("[data-sd-csv]").addEventListener("click", function () {
          descargar(URLS.csv);
        });
        d.querySelector("[data-sd-xlsx]").addEventListener("click", function () {
          descargar(URLS.xlsx);
        });

        if (zone) search();
      },
      onClose: function () {
        // Si se cerró para dibujar, la zona se conserva; si lo cerró el
        // usuario, el mapa queda limpio.
        if (!draw) clear();
        dialog = null;
      },
    });
  }

  function minutos() {
    var s = dialog && dialog.querySelector("[data-sd-minutes]");
    return s ? s.value : 120;
  }

  function search() {
    if (!dialog || !zone) return;

    var caja = dialog.querySelector("[data-sd-result]");
    caja.innerHTML = '<p class="tp-text-muted tp-text-sm">Buscando…</p>';

    var fd = new FormData();
    fd.append("_token", csrf());
    fd.append("zone", JSON.stringify(zone));
    fd.append("minutes", minutos());

    fetch(URLS.search, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(pintar)
      .catch(function () {
        caja.innerHTML = '<p class="tp-text-sm" style="color:var(--danger-500);">No se pudo consultar.</p>';
      });
  }

  function pintar(res) {
    if (!dialog) return;

    var caja = dialog.querySelector("[data-sd-result]");
    var items = (res && res.items) || [];

    dialog.querySelector("[data-sd-csv]").hidden = !items.length;
    dialog.querySelector("[data-sd-xlsx]").hidden = !items.length;

    if (!res) {
      caja.innerHTML = '<p class="tp-text-muted tp-text-sm">' + tpT('ui.draw_zone_silent_help') + '</p>';
      return;
    }

    if (!items.length) {
      caja.innerHTML = '<p class="tp-text-sm">No hay unidades sin transmitir en esa zona.</p>';
      return;
    }

    var html =
      '<p class="tp-text-sm"><strong>' + res.total + "</strong> unidades sin transmitir " +
      '<span class="tp-text-muted">(desde ' + esc(res.corte) + ")</span></p>";

    if (res.acotado) {
      html += '<p class="tp-text-xs tp-text-muted">Se muestran las primeras ' + items.length + " de " + res.total + ".</p>";
    }

    html +=
      '<div style="overflow:auto; max-height:52vh;"><table class="tp-table"><thead><tr>' +
      "<th>" + tpT('ui.name') + "</th><th>" + tpT('ui.plate') + "</th><th>IMEI</th><th>Coordenadas</th><th>Usuarios</th><th>" + tpT('ui.last_transmission') + "</th>" +
      "</tr></thead><tbody>";

    items.forEach(function (i) {
      html +=
        "<tr>" +
        "<td>" + esc(i.name) + "</td>" +
        "<td>" + esc(i.plate) + "</td>" +
        "<td>" + esc(i.imei) + "</td>" +
        '<td><a href="#" data-sd-go="' + i.lat + "," + i.lng + '">' + i.lat + ", " + i.lng + "</a></td>" +
        "<td>" + esc(i.users) + "</td>" +
        "<td>" + (i.last_at
          ? esc(i.last_at) + ' <span class="tp-text-muted">(' + esc(i.silent_for) + ")</span>"
          : '<span class="tp-text-muted">' + tpT('ui.never_transmitted') + '</span>') +
        "</td></tr>";
    });

    caja.innerHTML = html + "</tbody></table></div>";

    // Ir al punto: el siguiente paso siempre es "¿y dónde quedó?".
    caja.addEventListener("click", function (e) {
      var a = e.target.closest("[data-sd-go]");
      if (!a) return;
      e.preventDefault();

      var p = a.getAttribute("data-sd-go").split(",");
      var m = map();
      if (!m) return;

      tpModal.close(dialog);
      m.setView([parseFloat(p[0]), parseFloat(p[1])], 16);
    });
  }

  /** Descarga por form, para que la gestione el navegador. */
  function descargar(url) {
    var f = document.createElement("form");
    f.method = "POST";
    f.action = url;
    f.target = "_blank";

    [["_token", csrf()], ["zone", JSON.stringify(zone)], ["minutes", minutos()]].forEach(function (par) {
      var i = document.createElement("input");
      i.type = "hidden";
      i.name = par[0];
      i.value = par[1];
      f.appendChild(i);
    });

    document.body.appendChild(f);
    f.submit();
    f.remove();
  }

  window.tpSilentDevices = { open: open, clear: clear };
})();
