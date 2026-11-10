<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle = <?= $chartTitle; ?>;
    var yaxisTitle = <?= $yaxisTitle; ?>;
    var category = <?= $category; ?>;
    var series = <?= $series; ?>;
    var ont = <?= $ont; ?>;
    var lte = <?= $lte; ?>;
    var zer = <?= $zer; ?>;
    var nrc = <?= $nrc; ?>;
  
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
            shared: true,
        },
        plotOptions: {
            column: {
                stacking: 'percent'
            }
        },
        series: [{
            type: 'column',
            name: series[0],
            color: '#00B050',
            data: ont
        }, {
            type: 'column',
            name: series[1],
            color: '#FFFF00',
            data: lte
        }, {
            type: 'column',
            name: series[2],
            color: '#FF0000',
            data: nrc
        }, {
            type: 'column',
            name: series[3],
            color: '#0000FF',
            data: zer
        }]
    });
})
</script>