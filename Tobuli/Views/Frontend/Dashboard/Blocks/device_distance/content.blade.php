<style>
    .dd-wrap {
        display: flex;
        gap: 0;
    }

    .dd-chart {
        flex: 1;
        min-width: 0;
    }

    .dd-legend {
        width: 170px;
        flex-shrink: 0;
        padding-left: 12px;
        overflow-y: auto;
        max-height: 260px;
    }

    .dd-legend table {
        width: 100%;
        border-collapse: collapse;
    }

    .dd-legend td {
        padding: 3px 4px;
        font-size: 11px;
        color: #4a5568;
        vertical-align: middle;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 140px;
    }

    .dd-legend .dd-swatch {
        width: 10px;
        height: 10px;
        border-radius: 2px;
        display: inline-block;
        flex-shrink: 0;
    }

    #distance_travelled .flot-y-axis .flot-tick-label,
    #distance_travelled .flot-x-axis .flot-tick-label {
        font-size: 11px !important;
        color: #8a9bb0 !important;
    }
</style>

<div class="dd-wrap">
    <div class="dd-chart">
        <div id="distance_travelled" style="width:100%; height:260px;"></div>
    </div>
    <div class="dd-legend">
        <div id="distance_travelled_legends"></div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        var keys = [];
        var dates = {!! $keys !!};
        for (var i in dates) {
            keys.push([parseInt(i), dates[i]]);
        }

        var palette = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#F97316', '#14B8A6',
            '#EC4899', '#6366F1'
        ];
        var dataset = [];
        var data = {!! $data !!};
        var ci = 0;
        for (var device in data) {
            dataset.push({
                label: device,
                data: data[device],
                color: palette[ci % palette.length]
            });
            ci++;
        }

        var unit = '{{ Formatter::distance()->getUnit() }}';

        $.plot($("#distance_travelled"), dataset, {
            yaxis: {
                tickFormatter: function(x) {
                    return x > 0 ? x.toFixed(0) + unit : '0';
                },
                font: {
                    size: 11,
                    color: '#8a9bb0'
                },
                tickColor: '#eef0f2',
                min: 0
            },
            xaxis: {
                ticks: keys,
                autoscaleMargin: 0.08,
                font: {
                    size: 11,
                    color: '#8a9bb0'
                }
            },
            series: {
                shadowSize: 0,
                bars: {
                    show: true,
                    barWidth: 0.5 / (dataset.length || 1),
                    order: 1,
                    lineWidth: 0,
                    fill: 1,
                    align: 'center'
                }
            },
            legend: {
                show: true,
                noColumns: 1,
                labelFormatter: function(label) {
                    return '<span style="font-size:11px;color:#4a5568;">' + label + '</span>';
                },
                container: $('#distance_travelled_legends'),
                labelBoxBorderColor: 'transparent'
            },
            grid: {
                show: true,
                borderWidth: 0,
                hoverable: true,
                color: '#eef0f2',
                backgroundColor: '#fff'
            }
        });
    });
</script>
