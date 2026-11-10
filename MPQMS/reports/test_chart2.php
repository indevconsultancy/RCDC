<script>
	$(function () {
		var datacat = <?php echo $datacat; ?>;
		var dataass = <?php echo $dataass; ?>;
		var datawgt = <?php echo $datawgt; ?>;
		var datadin = <?php echo $datadin; ?>;
		var datadss = <?php echo $datadss; ?>;
		var datafri = <?php echo $datafri; ?>;
		var dataidt = <?php echo $dataidt; ?>;
		var datastt = <?php echo $datastt; ?>;
		var dataucn = <?php echo $dataucn; ?>;
		var datauwg = <?php echo $datauwg; ?>;

		Highcharts.chart("container", {
		    chart: {
		        type: 'column'
		    },
		    title: {
		        text: null
		    },
		    xAxis: {
		        categories: datacat
		    },
		    yAxis: {
		        min: 0,
		        title: {
		            text: 'Total Test'
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
		        verticalAlign: 'bottom',
		        borderWidth: 0,
		        shadow: false
		    },
		    tooltip: {
		        shared: true,
		    },
		    plotOptions: {
		        column: {
		            stacking: 'normal',
		            dataLabels: {
		                enabled: false
		            }
		        }
		    },
		    series: [{
		        name: 'Assay',
		        data: dataass
		    }, {
		        name: 'Average Weight',
		        data: datawgt
		    }, {
		        name: 'Disintegration',
		        data: datadin
		    }, {
		        name: 'Dissolution',
		        data: datadss
		    }, {
		        name: 'Friability',
		        data: datafri
		    }, {
		        name: 'Identification Test',
		        data: dataidt
		    }, {
		        name: 'Sterility Test',
		        data: datastt
		    }, {
		        name: 'Uniformity of Content',
		        data: dataucn
		    }, {
		        name: 'Uniformity of Weight',
		        data: datauwg
		    }]
		});
	})	
</script>