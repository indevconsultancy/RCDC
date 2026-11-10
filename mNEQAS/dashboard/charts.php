<?php if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial ?>
<script>
	var chartTitle1 = <?php echo $chartTitle1; ?>;
    var yaxisTitle1 = <?php echo $yaxisTitle1; ?>;
    var category1 = <?php echo $category1; ?>;
    var series1 = <?php echo $series1; ?>;
    var msm1 = <?php echo $msm1; ?>;
    var tg1 = <?php echo $tg1; ?>;
    var fsw1 = <?php echo $fsw1; ?>;
    var oth1 = <?php echo $oth1; ?>;


    var chartTitle2 = <?php echo $chartTitle2; ?>;
    var yaxisTitle2 = <?php echo $yaxisTitle2; ?>;
    var category2 = <?php echo $category2; ?>;
    var msm2 = <?php echo $msm2; ?>;
    var tg2 = <?php echo $tg2; ?>;
    var fsw2 = <?php echo $fsw2; ?>;
    var oth2 = <?php echo $oth2; ?>;

    var chartTitle3 = <?php echo $chartTitle3; ?>;
    var series3 = <?php echo $series3; ?>;
    var data3 = <?php echo $data3; ?>;

    var chartTitle4 = <?php echo $chartTitle4; ?>;
    var yaxisTitle4 = <?php echo $yaxisTitle4; ?>;
    var category4 = <?php echo $category4; ?>;
    var msm4 = <?php echo $msm4; ?>;
    var tg4 = <?php echo $tg4; ?>;
    var fsw4 = <?php echo $fsw4; ?>;
    var oth4 = <?php echo $oth4; ?>;
     
    $(function () {   
        $('#member-category').highcharts({
            chart: {
                plotBackgroundColor: null,
                plotBorderWidth: null,
                plotShadow: false,
                type: 'pie'
            },
            title: {
                text: chartTitle3
            },
            tooltip: {
                pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
            },
            accessibility: {
                point: {
                    valueSuffix: '%'
                }
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true,
                        format: '<b>{point.name}</b>: {point.percentage:.1f} %'
                    }
                }
            },
            series: [{
                name: 'Member Category',
                colorByPoint: true,
                data: [{
                    name: series3[0],
                    y: data3[0]
                }, {
                    name: series3[1],
                    y: data3[1]
                }, {
                    name: series3[2],
                    y: data3[2]
                }, {
                    name: series3[3],
                    y: data3[3]
                }]
            }]
        });

        $('#category-age').highcharts({
            chart: {
                type: 'column'
            },
            title: {
                text: chartTitle1
            },
            xAxis: {
                categories: category1
            },
            yAxis: {
                min: 0,
                title: {
                    text: yaxisTitle1
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
                    stacking: 'normal',
                    dataLabels: {
                        enabled: true
                    }
                }
            },
            series: [{
                name: series1[0],
                data: msm1
            }, {
                name: series1[1],
                data: tg1
            }, {
                name: series1[2],
                data: fsw1
            }, {
                name: series1[3],
                data: oth1
            }]
        });
        
        $('#category-year').highcharts({
            chart: {
                type: 'column'
            },
            title: {
                text: chartTitle2
            },
            xAxis: {
                categories: category2
            },
            yAxis: {
                min: 0,
                title: {
                    text: yaxisTitle2
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
                    stacking: 'normal',
                    dataLabels: {
                        enabled: true
                    }
                }
            },
            series: [{
                name: series1[0],
                data: msm2
            }, {
                name: series1[1],
                data: tg2
            }, {
                name: series1[2],
                data: fsw2
            }, {
                name: series1[3],
                data: oth2
            }]
        });

        $('#category-occupation').highcharts({
            chart: {
                type: 'column'
            },
            title: {
                text: chartTitle4
            },
            xAxis: {
                categories: category4
            },
            yAxis: {
                min: 0,
                title: {
                    text: yaxisTitle4
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
                    stacking: 'normal',
                    dataLabels: {
                        enabled: true
                    }
                }
            },
            series: [{
                name: series1[0],
                data: msm4
            }, {
                name: series1[1],
                data: tg4
            }, {
                name: series1[2],
                data: fsw4
            }, {
                name: series1[3],
                data: oth4
            }]
        });
    })
</script>