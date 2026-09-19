<div class="widget widget-sensor-graph" id="widget-fuel-graph" >
    <div class="widget-heading">
        <div class="widget-title">
            <i class="icon fuel_tank"></i> {{ trans('front.fuel') }}
        </div>
    </div>

    {{-- Este bloque va ANTES de los graficos: los <script> se ejecutan en el
         orden del documento, y los de abajo usan `plots` y `options`. Estando
         al final, la primera vez que se abria el widget salia en blanco (las
         variables aun no existian) y solo funcionaba a partir de la segunda,
         reutilizando las que habian quedado globales. --}}
    <script>
        var plots = {};
        var options = {
            colors: ["rgba(76, 84, 84, 1)"],
            lines: {
                show: true,
                lineWidth: 1.5,
                fill: true,
                fillColor: "rgba(76, 84, 84, 0.4)"
            },
            xaxis: {
                minTickSize: [30, "minute"],
                mode: 'time',
                twelveHourClock: app.settings.timeFormat.includes(' A')
            },
            yaxis: {
                // La unidad real la pone cada sensor mas abajo; esto es solo
                // el valor por defecto si el sensor no trae ninguna.
                tickFormatter: function(value, axis) { return value.toFixed(axis.tickDecimals); },
                minTickSize: 1,
                tickDecimals: 0,
                zoomRange: false
            },
            grid: {
                color: "#999999",
                margin: {
                    top: 0,
                    left: 0,
                    bottom: 0,
                    right: 5
                },
                hoverable: true,
                borderWidth: 1,
                borderColor: '#DDDDDD'
            }
        };
    </script>

    <div class="widget-body">
        @if (empty($data))
            <div class="widget-empty" style="display: block">
                <p>{{ $error ?? '' }}</p>
            </div>
        @else
            @foreach($data as $sensor)
            <div class="sensor-graph-head">
                <span class="sensor-graph-name">{{ $sensor['name'] }}</span>
                <span class="sensor-graph-hover" id="sensor-hover-{{ $sensor['id'] }}"></span>
            </div>
            <div id="sensor-graph-{{ $sensor['id'] }}" class="sensor-graph"></div>
            <script>
                (function () {
                    var unit = '{!! $sensor['unit'] !!}';

                    // Cada grafico necesita su propia copia: `options` es
                    // compartido y el formateador depende de la unidad del
                    // sensor, que cambia de uno a otro.
                    var sensorOptions = $.extend(true, {}, options);

                    sensorOptions.yaxis.tickFormatter = function (value, axis) {
                        return value.toFixed(axis.tickDecimals) + unit;
                    };

                    var graph_items = [];

                    $.each({!! json_encode($sensor['values']) !!}, function (index, value) {
                        var date = moment.unix(value.t).toDate().getTime();
                        graph_items.push([date, value.v, 0, index]);
                    });

                    plots['{{ $sensor['id'] }}'] = $.plot("#sensor-graph-{{ $sensor['id'] }}", [graph_items], sensorOptions);

                    // Sin esto el grafico es solo una silueta: no se puede leer
                    // el valor de ningun punto.
                    $("#sensor-graph-{{ $sensor['id'] }}").bind("plothover", function (event, pos, item) {
                        var $label = $("#sensor-hover-{{ $sensor['id'] }}");

                        if (!item) {
                            $label.text('');
                            return;
                        }

                        // moment.utc, no moment: flot dibuja el eje de tiempo en
                        // UTC, asi que la etiqueta tiene que leerse igual que
                        // el eje. Es el mismo criterio que el grafico de los
                        // reportes (partials/item_sensor_data).
                        $label.text(
                            item.datapoint[1] + unit
                            + ' · ' + moment.utc(item.datapoint[0]).format('DD/MM HH:mm')
                        );
                    });
                })();
            </script>
            @endforeach
        @endif
    </div>
</div>
