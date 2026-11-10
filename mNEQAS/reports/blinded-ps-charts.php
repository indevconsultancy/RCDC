<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle = <?= $chartTitle; ?>;
    var xAxisTitle = <?= $xAxisTitle; ?>;
    var yaxisTitle = <?= $yaxisTitle; ?>;
    var category = <?= $category; ?>;
    var series = <?= $series; ?>;
    var sen = <?= $sen; ?>;
    var spe = <?= $spe; ?>;
    var mpdetect = <?= $mpdetect; ?>;
    var mpspecies = <?= $mpspecies; ?>;
    var mpstage = <?= $mpstage; ?>;
    var pden = <?= $pden; ?>;
    var film = <?= $film; ?>;
    var stain = <?= $stain; ?>;
  
    $(function () {
		Highcharts.chart('container', {
            chart: {
                zoomType: 'xy',
                defaultSeriesType: 'column',
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
                    enabled: true,
                    format: '{value}%',
                    style: {
                        color: Highcharts.getOptions().colors[1]
                    }
                },
            }],
            tooltip: {
                shared: true,
                crosshairs: true,
                headerFormat: '<b>{point.x}</b><br/>',
                pointFormat: '{series.name}: {point.y}<br/>{point.stackTotal}'
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
            plotOptions: {
                column: {
                    dataLabels: {
                        enabled: true,
                    },
                    enableMouseTracking: false
                }
            },
            series: [{
                name: series[0],
                // color: '#4472C4',
                data: sen,
            }, {
                name: series[1],
                // color: '#ED7D31',
                data: spe,
            }, {
                name: series[2],
                // color: '#ED7D31',
                data: mpdetect,
            }, {
                name: series[3],
                // color: '#ED7D31',
                data: mpspecies,
            }, {
                name: series[4],
                // color: '#ED7D31',
                data: mpstage,
            }, {
                name: series[5],
                // color: '#ED7D31',
                data: pden,
            }, {
                name: series[6],
                // color: '#ED7D31',
                data: film,
            }, {
                name: series[7],
                // color: '#ED7D31',
                data: stain,
            }]
        });
    })
</script>