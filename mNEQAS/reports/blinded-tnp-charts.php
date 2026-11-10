<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle = <?= $chartTitle; ?>;
    var xAxisTitle = <?= $xAxisTitle; ?>;
    var yaxisTitle = <?= $yaxisTitle; ?>;
    var y2axisTitle = <?= $y2axisTitle; ?>;
    var category = <?= $category; ?>;
    var series = <?= $series; ?>;
    var tns = <?= $tns; ?>;
    var tps = <?= $tps; ?>;
    var tbsr = <?= $tbsr; ?>;
  
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
                layout: 'vertical',
                align: 'left',
                x: 80,
                verticalAlign: 'top',
                y: 55,
                floating: true,
                backgroundColor:
                    Highcharts.defaultOptions.legend.backgroundColor || // theme
                    'rgba(255,255,255,0.25)'
            },
            plotOptions: {
                series: {
                    cursor: 'pointer',
                    className: 'popup-on-click',
                    marker: {
                        lineWidth: 1
                    },
                    label: {
                        connectorAllowed: false
                    },
                    pointStart: 0,
                }
            },
            series: [{
                type: 'column',
                name: series[0],
                color: '#00B050',
                data: tbsr
            }, {
                name: series[1],
                color: '#0000FF',
                data: tns,
            }, {
                name: series[2],
                color: '#FF0000',
                data: tps,
                yAxis: 1
            }]
        });
    })
</script>