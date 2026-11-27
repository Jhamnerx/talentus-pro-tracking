function HistoryGraph() {
    var
        _this = this;

    // Hasta cuatro series a la vez: mas de cuatro ejes no caben en el alto del
    // panel y el grafico deja de leerse, que es justo lo contrario de lo que se
    // busca al superponer.
    var MAX_SERIES = 4;

    // Una por serie, en el orden en que se van seleccionando.
    var COLORS = ["#4c5454", "#2e7d32", "#c62828", "#1565c0"];

    _this.init = function() {
        _this.plot =  null;
        _this.unit = null;
        _this.graph_data = [];

        if ( ! _this.selected)
            _this.selected = [];
    };

    _this.options = function() {
        return {
            series: {
                shadowSize: 0
            },
            crosshair: {
                mode: "x"
            },
            lines: {
                show: true,
                lineWidth: 1.5
            },
            zoom: {
                interactive: false
            },
            pan: {
                interactive: true
            },
            xaxis: {
                minTickSize: [30, "minute"],
                mode: 'time',
                twelveHourClock: app.settings.timeFormat.includes(' A'),
            },

            legend: {
                noColumns: 0,
                labelFormatter: function (label, series) {
                    return "<font color=\"white\">" + label + "</font>";
                },
                backgroundColor: "#000",
                backgroundOpacity: 1.0,
                labelBoxBorderColor: "#000000",
                position: "nw"
            },
            grid: {
                color: "#999999",
                margin: {
                    top: 10,
                    left: 10,
                    bottom: 10,
                    right: 10
                },
                hoverable: true,
                clickable: true,
                borderWidth: 1,
                borderColor: '#DDDDDD'
            }
        };
    };

    _this.events = function() {
        // Las pestañas dejan de ser excluyentes: cada clic añade o quita esa
        // serie. Superponer nivel de combustible con encendido o velocidad es
        // lo que distingue "bajó andando" de "bajó parado", y con una sola
        // serie a la vez eso no se puede ver.
        $(document).on('click', '#graph_sensors [data-id]', function(e) {
            e.preventDefault();

            _this.toggle($(this).attr('data-id'));
        });
    };

    _this.events();

    _this.clear = function() {
        $('#bottom-history').hide();

        if (_this.plot && typeof _this.plot.shutdown !== 'undefined') {
            _this.plot.shutdown();
        }
        $("#placeholder").unbind("plotclick");
        $("#placeholder").unbind("plothover");

        _this.graph_data = [];
        _this.plot = {};
        _this.selected = [];

        $('#hoverdata').html('');
        $('#hoverdata-date').html('');

        app.map.invalidateSize();
    };

    _this.findSensor = function(key) {
        var found = null;

        $.each(window.history_sensors || [], function(index, item) {
            if (item.key == key)
                found = item;
        });

        return found;
    };

    _this.toggle = function(key) {
        _this.init();

        var at = $.inArray(key, _this.selected);

        if (at !== -1) {
            // Siempre tiene que quedar una: un grafico vacio no dice nada
            if (_this.selected.length > 1)
                _this.selected.splice(at, 1);
        } else {
            if (_this.selected.length >= MAX_SERIES)
                _this.selected.shift();

            _this.selected.push(key);
        }

        _this.render();
    };

    /** Compatibilidad: selección única, como antes. */
    _this.loadData = function(key) {
        _this.init();
        _this.selected = [key];
        _this.render();
    };

    _this.positionTime = function(position) {
        // La hora viene ya en la zona del usuario; flot dibuja el eje en UTC,
        // asi que se construye como UTC para que el eje y el dato coincidan.
        var t = position.t;

        return new Date(t.substr(0, 10) + 'T' + t.substr(11, 8) + 'Z').getTime();
    };

    /**
     * Rellenos y robos como lineas verticales sobre la curva. El pipeline ya
     * los calcula (AppendFuelFilling / AppendFuelTheft); aqui solo se pintan.
     */
    _this.buildMarkings = function() {
        var markings = [];

        _this.events_fuel = [];

        $.each(app.history.positions || [], function(index, position) {
            $.each([['fill', '#2e7d32'], ['theft', '#c62828']], function(i, pair) {
                var kind = pair[0];

                if ( ! position[kind])
                    return;

                var at = _this.positionTime(position);

                markings.push({
                    xaxis: { from: at, to: at },
                    color: pair[1],
                    lineWidth: 2
                });

                _this.events_fuel.push($.extend({ kind: kind, at: at }, position[kind]));
            });
        });

        return markings;
    };

    /** El relleno o robo mas cercano al punto sobre el que se esta pasando. */
    _this.nearestFuelEvent = function(at) {
        var found = null;

        $.each(_this.events_fuel || [], function(index, event) {
            // Dos minutos: lo justo para que la etiqueta salga al apuntar a la
            // linea sin aparecer cuando se pasa lejos de ella.
            if (Math.abs(event.at - at) <= 120000)
                found = event;
        });

        return found;
    };

    _this.render = function() {
        _this.init();

        if ( ! _this.selected.length)
            return;

        var options = _this.options();
        var series = [];
        var axes = [];

        $.each(_this.selected, function(index, key) {
            var sensor = _this.findSensor(key);

            if ( ! sensor)
                return;

            var data = [];

            $.each(app.history.positions, function(i, position) {
                data.push([_this.positionTime(position), position[key], 0, position.id]);
            });

            axes.push({
                position: index % 2 ? 'right' : 'left',
                tickFormatter: (function (unit) {
                    return function (value, axis) {
                        return value.toFixed(axis.tickDecimals) + unit;
                    };
                })(sensor.unit || ''),
                minTickSize: 1,
                tickDecimals: 0,
                zoomRange: false
            });

            series.push({
                label: sensor.name,
                data: data,
                color: COLORS[index % COLORS.length],
                yaxis: axes.length,
                // Solo la primera se rellena: con varias superpuestas, los
                // rellenos se tapan entre si y no se ve ninguna.
                lines: {
                    fill: index === 0 ? 0.25 : false,
                    lineWidth: 1.5
                },
                sensorUnit: sensor.unit || ''
            });
        });

        if ( ! series.length)
            return;

        options.yaxes = axes;
        options.grid.markings = _this.buildMarkings();

        // La escala apretada solo tiene sentido con una serie: con varias, cada
        // eje ya se ajusta a lo suyo.
        if (series.length === 1) {
            var values = [];

            $.each(series[0].data, function(i, point) {
                if (point[1] !== null && isFinite(point[1]))
                    values.push(point[1]);
            });

            if (values.length) {
                // Nada de Math.min(...array): con un mes de posiciones el
                // spread se pasa del limite de argumentos y revienta.
                var min = values[0], max = values[0];

                for (var i = 1; i < values.length; i++) {
                    if (values[i] < min) min = values[i];
                    if (values[i] > max) max = values[i];
                }

                if (max && (1 - min / max) * 100 < 1) {
                    axes[0].min = min;
                    axes[0].max = Math.ceil(max + (max - min) * 0.2);
                }
            }
        }

        _this.unit = series[0].sensorUnit;
        _this.graph_data = series[0].data;
        _this.plot = $.plot("#placeholder", series, options);

        _this.markSelected();

        $("#placeholder").unbind("plothover").bind("plothover", function (event, pos, item) {
            if (item != null) {
                var unit = item.series.sensorUnit || '';

                $("#hoverdata").text(item.series.label + ': ' + item.datapoint[1] + ' ' + unit);
                $("#hoverdata-date").text( moment.utc(item.datapoint[0]).format(app.settings.dateFormat + ' ' + app.settings.timeFormat) );

                return;
            }

            // Sin punto debajo, pero puede estar sobre la linea de un relleno
            var fuelEvent = pos ? _this.nearestFuelEvent(pos.x) : null;

            if (fuelEvent) {
                $("#hoverdata").html(
                    '<strong style="color:' + (fuelEvent.kind === 'fill' ? '#2e7d32' : '#c62828') + '">'
                    + (fuelEvent.kind === 'fill' ? app.lang.fuel_fillings : app.lang.fuel_thefts)
                    + '</strong> ' + fuelEvent.diff + ' ' + fuelEvent.unit
                    + ' (' + fuelEvent.from + ' → ' + fuelEvent.to + ')'
                );
                $("#hoverdata-date").text(moment.utc(fuelEvent.at).format(app.settings.dateFormat + ' ' + app.settings.timeFormat));
            }
        });

        $("#placeholder").unbind("plotclick").bind("plotclick", function (event, pos, pitem) {
            if (pitem != null) {
                var
                    position_id = pitem.series.data[pitem.dataIndex][3],
                    position = app.history.findPosition(position_id);

                app.history.historyPointPopup(position, true);
            }
        });
    };

    _this.markSelected = function() {
        $('#graph_sensors li').removeClass('active');

        $.each(_this.selected, function(index, key) {
            $('#graph_sensors [data-id="' + key + '"]').closest('li').addClass('active');
        });
    };

    _this.graphLeft = function(e) {
        if (e)
            e.preventDefault();

        _this.plot.pan({
            left: -100
        });
    };

    _this.graphRight = function(e) {
        if (e)
            e.preventDefault();

        _this.plot.pan({
            left: +100
        });
    };

    _this.zoomIn = function(e) {
        if (e)
            e.preventDefault();

        _this.plot.zoom({ center: { left: 10, top: 0 } });
    };

    _this.zoomOut = function(e) {
        if (e)
            e.preventDefault();

        _this.plot.zoomOut({ center: { left: 10, top: 0 } });
    };
}
