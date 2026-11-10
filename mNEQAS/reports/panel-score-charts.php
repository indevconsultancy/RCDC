<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle = <?php echo $chartTitle; ?>;
    var yaxisTitle = <?php echo $yaxisTitle; ?>;
    var category = <?php echo $category; ?>;
    var series = <?php echo $series; ?>;
    var sen = <?php echo $sen; ?>;
    var spc = <?php echo $spc; ?>;
    var inf = <?php echo $inf; ?>;
    var spe = <?php echo $spe; ?>;
    var sta = <?php echo $sta; ?>;
    var den = <?php echo $den; ?>;
    var avg = <?php echo $avg; ?>;
  
    $(function () {
		Highcharts.chart('container', {
        title: {
            text: chartTitle
        },
        xAxis: {
            categories: category
        },
        yAxis: {
            min: 0,
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
        },
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
            pointFormat: '{series.name}: {point.y}<br/>Total: {point.stackTotal}'
        },
        plotOptions: {
            column: {
                stacking: 'percent'
            }
        },
        series: [{
            type: 'column',
            name: series[0],
            data: sen
        }, {
            type: 'column',
            name: series[1],
            data: spc
        }, {
            type: 'column',
            name: series[2],
            data: inf
        }, {
            type: 'column',
            name: series[3],
            data: spe
        }, {
            type: 'column',
            name: series[4],
            data: sta
        }, {
            type: 'column',
            name: series[5],
            data: den
        }, {
            type: 'spline',
            name: series[6],
            data: avg
        }]
    });
})
</script>