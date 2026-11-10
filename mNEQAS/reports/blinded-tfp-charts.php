<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle = <?= $chartTitle; ?>;
    var xAxisTitle = <?= $xAxisTitle; ?>;
    var yaxisTitle = <?= $yaxisTitle; ?>;
    var y2axisTitle = <?= $y2axisTitle; ?>;
    var category = <?= $category; ?>;
    var series = <?= $series; ?>;
    var tps = <?= $tps; ?>;
    var tpf = <?= $tpf; ?>;
    var tpv = <?= $tpv; ?>;
    var mix = <?= $mix; ?>;
  
    $(function () {
		Highcharts.chart('container', {
            chart: {
                defaultSeriesType: 'spline',
                zoomType: 'xy',
            },
            title: {
                text: chartTitle,
                align: 'center'
            },
            xAxis: [{
                title: {
                    text: xAxisTitle
                },
                crosshair: true,
                categories: category,
            }],            
            yAxis: [{
                lineWidth: 2,
                title: {
                    text: yaxisTitle
                },
                labels: {
                    // format: '{value}°C',
                    style: {
                        color: Highcharts.getOptions().colors[1]
                    }
                },
            }, {
                lineWidth: 1,
                opposite: true,
                title: {
                    text: y2axisTitle
                }
            }],
            tooltip: {
                shared: true,
                crosshairs: true
            },
            legend: {
                layout: 'horizontal',
                align: 'center',
                x: -30,
                verticalAlign: 'bottom',
                y: 21,
                floating: true,
                backgroundColor:
                    Highcharts.defaultOptions.legend.backgroundColor || 'white',
                shadow: false
            },
            series: [{
                type: 'column',
                name: series[1],
                color: '#FF0000',
                data: tpf
            }, {
                type: 'column',
                name: series[2],
                color: '#00B050',
                data: tpv,
            }, {
                name: series[3],
                color: '#7030A0',
                data: mix,
            }, {
                name: series[0],
                color: '#0000FF',
                data: tps,
                yAxis: 1
            }, ]
        });
    })
</script>