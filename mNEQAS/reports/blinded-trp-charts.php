<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle = <?= $chartTitle; ?>;
    var xAxisTitle = <?= $xAxisTitle; ?>;
    var yaxisTitle = <?= $yaxisTitle; ?>;
    var y2axisTitle = <?= $y2axisTitle; ?>;
    var category = <?= $category; ?>;
    var series = <?= $series; ?>;
    var tpl = <?= $tpl; ?>;
    var tbsr = <?= $tbsr; ?>;
  
    $(function () {
		Highcharts.chart('container', {
            chart: {
            marginRight: 80 // like left
            },
            title: {
                text: chartTitle
            },
            xAxis: [{
                title: {
                    text: xAxisTitle
            },
                categories: category,
            }],            
            yAxis: [{
                lineWidth: 1,
                title: {
                    text: yaxisTitle
                },
                stackLabels: {
                    enabled: true,
                    style: {
                        fontWeight: 'bold',
                        color: ( // theme
                            Highcharts.defaultOptions.title.style &&
                            Highcharts.defaultOptions.title.style.color
                        ) || 'gray'
                    }
                }
            }, {
                lineWidth: 1,
                opposite: true,
                title: {
                    text: y2axisTitle
                }
            }],
            legend: {
                align: 'center',
                x: -30,
                verticalAlign: 'bottom',
                y: 21,
                floating: true,
                backgroundColor:
                    Highcharts.defaultOptions.legend.backgroundColor || 'white',
                shadow: false
            },
            tooltip: {
                headerFormat: '<b>{point.x}</b><br/>',
                pointFormat: '{series.name}: {point.y}<br/>Total: {point.stackTotal}',
                shared: true,
            },
            series: [{
                type: 'column',
                name: series[0],
                color: '#00B050',
                data: tbsr
            }, {
                type: 'spline',
                name: series[1],
                color: '#FF0000',
                data: tpl,
                yAxis: 1
            }]
        });
    })
</script>