/**
 * Herramienta de mapa "Unidades sin transmitir".
 *
 * El usuario dibuja una zona —polígono o círculo— y se listan los vehículos
 * cuya ÚLTIMA posición cae dentro y que llevan sin transmitir más del tiempo
 * elegido. Reutiliza L.Draw, el mismo dibujo con el que se crean geocercas,
 * para que el gesto sea el que ya conoce.
 */
function SilentDevices() {
  var _this = this;

  var draw = null;
  var layer = null;

  // La zona tal como viaja al backend: {polygon: [...]} o {circle: {...}}.
  var zone = null;

  _this.map = function () {
    return app.map;
  };

  _this.shape = function () {
    return $('#silent_devices_shape').val() || 'polygon';
  };

  /** Empieza a dibujar. El modal se aparta para no tapar el mapa. */
  _this.draw = function () {
    _this.clear();

    $('#silentDevices').modal('hide');

    if (_this.shape() === 'circle') {
      // Mismas unidades que el dibujo de geocercas circulares.
      draw = new L.Draw.Circle(_this.map(), {
        metric: app.settings.units.speed.radio == 1,
        feet: app.settings.units.speed.radio != 1,
      });
    } else {
      draw = new L.Draw.Polygon(_this.map());
    }

    draw.enable();
  };

  /** Quita del mapa la zona anterior y cancela un dibujo a medias. */
  _this.clear = function () {
    if (draw) {
      draw.disable();
      draw = null;
    }

    if (layer) {
      _this.map().removeLayer(layer);
      layer = null;
    }

    zone = null;
  };

  _this.events = function () {
    // El dibujo termina con el mismo evento que usan las geocercas, asi que
    // solo se atiende cuando esta herramienta lo pidio (draw != null).
    _this.map().on(L.Draw.Event.CREATED, function (e) {
      if (!draw) return;

      draw = null;

      layer = e.layer;
      _this.map().addLayer(layer);

      if (typeof layer.getRadius === 'function') {
        var c = layer.getLatLng();
        // El radio va en metros, que es lo que devuelve Leaflet y lo que
        // espera el backend; no se convierte a la unidad del usuario.
        zone = { circle: { lat: c.lat, lng: c.lng, radius: layer.getRadius() } };
      } else {
        var pts = [];
        layer.getLatLngs()[0].forEach(function (p) {
          pts.push({ lat: p.lat, lng: p.lng });
        });
        zone = { polygon: pts };
      }

      $('#silentDevices').modal('show');
      _this.search();
    });

    $(document).on('click', '#silent_devices_draw', function () {
      _this.draw();
    });

    // Cambiar de forma obliga a volver a dibujar: la zona anterior ya no
    // corresponde a lo que el selector dice.
    $(document).on('change', '#silent_devices_shape', function () {
      _this.clear();
      $('#silent_devices_result').empty();
      $('#silent_devices_export, #silent_devices_export_xlsx').hide();
    });

    $(document).on('change', '#silent_devices_minutes', function () {
      if (zone) _this.search();
    });

    $(document).on('click', '#silent_devices_export', function () {
      _this.export(app.urls.silentDevicesInZoneExport);
    });

    $(document).on('click', '#silent_devices_export_xlsx', function () {
      _this.export(app.urls.silentDevicesInZoneExportXlsx);
    });

    // Cerrar el modal deja el mapa limpio: la zona era de la consulta.
    $(document).on('hidden.bs.modal', '#silentDevices', function () {
      if (draw) return; // se esta dibujando: el modal vuelve solo
      _this.clear();
    });
  };

  _this.minutes = function () {
    return $('#silent_devices_minutes').val() || 120;
  };

  _this.search = function () {
    var result = $('#silent_devices_result');

    loader.add(result);

    $.ajax({
      type: 'POST',
      url: app.urls.silentDevicesInZone,
      data: { zone: JSON.stringify(zone), minutes: _this.minutes() },
      dataType: 'json',
      success: function (res) {
        _this.render(res);
      },
      error: function () {
        result.html('<div class="alert alert-danger">' + lang.unexpected_error + '</div>');
      },
      complete: function () {
        loader.remove(result);
      },
    });
  };

  _this.render = function (res) {
    var result = $('#silent_devices_result');
    var items = res.items || [];

    $('#silent_devices_export, #silent_devices_export_xlsx').toggle(items.length > 0);

    if (!items.length) {
      result.html('<div class="alert alert-info">' + lang.silent_devices_none + '</div>');
      return;
    }

    var html =
      '<p><strong>' + res.total + '</strong> ' + lang.silent_devices_found +
      ' <small class="text-muted">(' + res.corte + ')</small></p>';

    if (res.acotado) {
      html += '<p class="text-muted">' + lang.silent_devices_capped + '</p>';
    }

    html +=
      '<div class="table-responsive"><table class="table table-list table-hover"><thead><tr>' +
      '<th>' + lang.name + '</th>' +
      '<th>' + lang.plate_number + '</th>' +
      '<th>' + lang.imei + '</th>' +
      '<th>' + lang.latitude + ' / ' + lang.longitude + '</th>' +
      '<th>' + lang.users + '</th>' +
      '<th>' + lang.last_update + '</th>' +
      '</tr></thead><tbody>';

    items.forEach(function (i) {
      html +=
        '<tr>' +
        '<td>' + escapeHtml(i.name) + '</td>' +
        '<td>' + escapeHtml(i.plate) + '</td>' +
        '<td>' + escapeHtml(i.imei) + '</td>' +
        // Las coordenadas llevan enlace al mapa: el siguiente paso siempre
        // es "y donde quedo exactamente".
        '<td><a href="javascript:" onclick="app.silentDevices.locate(' + i.lat + ',' + i.lng + ')">' +
        i.lat + ', ' + i.lng + '</a></td>' +
        '<td>' + escapeHtml(i.users) + '</td>' +
        '<td>' + (i.last_at
          ? escapeHtml(i.last_at) + ' <small class="text-muted">(' + escapeHtml(i.silent_for) + ')</small>'
          : '<span class="text-muted">' + lang.never_transmitted + '</span>') +
        '</td>' +
        '</tr>';
    });

    result.html(html + '</tbody></table></div>');
  };

  /** Centra el mapa en la última posición de una unidad de la lista. */
  _this.locate = function (lat, lng) {
    $('#silentDevices').modal('hide');
    _this.map().setView([lat, lng], 16);
  };

  /** Descarga: se manda por form para que lo gestione el navegador. */
  _this.export = function (url) {
    var form = $('<form>', { method: 'POST', action: url, target: '_blank' });

    form.append($('<input>', { type: 'hidden', name: '_token', value: getCsrfToken() }));
    form.append($('<input>', { type: 'hidden', name: 'minutes', value: _this.minutes() }));
    form.append($('<input>', { type: 'hidden', name: 'zone', value: JSON.stringify(zone) }));

    form.appendTo('body').submit().remove();
  };

  function escapeHtml(s) {
    return $('<div>').text(s == null ? '' : s).html();
  }

  function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
  }
}
