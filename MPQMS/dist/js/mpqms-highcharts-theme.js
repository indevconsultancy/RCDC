/* MPQMS global Highcharts theme — loaded right after highcharts.js */
(function () {
	if (typeof Highcharts === 'undefined') return;
	Highcharts.setOptions({
		colors: ['#007bff', '#17a2b8', '#fd7e14', '#dc3545', '#6f42c1',
				 '#28a745', '#6c757d', '#e83e8c', '#20c997', '#ffc107'],
		chart: {
			backgroundColor: 'transparent',
			style: { fontFamily: "'Source Sans Pro', -apple-system, 'Segoe UI', Roboto, sans-serif" },
			spacingTop: 20
		},
		title: {
			style: { color: '#1e293b', fontWeight: '700', fontSize: '15px' },
			align: 'left'
		},
		subtitle: {
			style: { color: '#64748b', fontSize: '12px' },
			align: 'left'
		},
		xAxis: {
			lineColor: '#e2e8f0',
			tickColor: '#e2e8f0',
			labels: { style: { color: '#64748b', fontSize: '11px' } },
			title: { style: { color: '#64748b' } }
		},
		yAxis: {
			gridLineColor: '#eef2f7',
			labels: { style: { color: '#64748b', fontSize: '11px' } },
			title: { style: { color: '#64748b', fontSize: '11px' } }
		},
		legend: {
			itemStyle: { color: '#475569', fontWeight: '600', fontSize: '12px' },
			itemHoverStyle: { color: '#007bff' }
		},
		tooltip: {
			backgroundColor: '#ffffff',
			borderColor: '#e2e8f0',
			borderRadius: 10,
			shadow: { color: 'rgba(15,23,42,0.15)', offsetY: 2, width: 6 },
			style: { color: '#1e293b', fontSize: '12px' }
		},
		plotOptions: {
			series: { animation: { duration: 500 } },
			column: { borderRadius: 4, borderWidth: 0, maxPointWidth: 42 },
			bar: { borderRadius: 4, borderWidth: 0, maxPointWidth: 26 },
			pie: {
				borderWidth: 0,
				dataLabels: { style: { color: '#475569', fontSize: '11px', fontWeight: '600', textOutline: 'none' } }
			}
		},
		credits: { enabled: false },
		exporting: {
			buttons: { contextButton: { symbolStroke: '#94a3b8', theme: { fill: 'transparent' } } }
		},
		lang: { thousandsSep: ',' }
	});
})();
