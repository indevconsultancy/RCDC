<script>
/*
 * MPQMS — Test Compliance chart.
 *
 * Single, fixed visualisation: a standard grouped bar (column) chart with three
 * series — Complies (green), Does not comply (red), Inconclusive (orange) — and a
 * "Total: N" text annotation above each category group. No KPI cards, no chart-
 * type toggle, no stacked / line variants.
 *
 * Data arrives from compliance_chart1.php as flat parallel arrays aligned to
 * datacat. Empty months/categories render as zero-height bars without breaking
 * the layout.
 */
$(function () {
    var datacat   = <?php echo $datacat; ?>;
    var datacomp  = <?php echo $datacomp; ?>;
    var datadcomp = <?php echo $datadcomp; ?>;
    var dataincon = <?php echo $dataincon; ?>;
    var datatotal = <?php echo $datatotal; ?>;

    var COLORS = {
        complies:    '#28a745',
        dcomplies:   '#dc3545',
        inconclusive:'#fd7e14',
        total:       '#1f6feb'
    };

    function esc(s){
        return String(s == null ? '' : s)
                 .replace(/&/g, '&amp;')
                 .replace(/</g, '&lt;')
                 .replace(/>/g, '&gt;')
                 .replace(/"/g, '&quot;');
    }

    Highcharts.chart('container', {
        chart: { type: 'column' },
        title: { text: null },
        xAxis: {
            categories: datacat,
            title: { text: null },
            crosshair: true
        },
        yAxis: {
            min: 0,
            title: { text: 'Number of samples' },
            allowDecimals: false
        },
        legend: { align: 'center', verticalAlign: 'bottom', layout: 'horizontal' },
        tooltip: {
            shared: true,
            useHTML: true,
            formatter: function(){
                var ix = this.points && this.points[0] ? this.points[0].point.index : -1;
                var c  = ix >= 0 ? (datacomp[ix]  || 0) : 0;
                var d  = ix >= 0 ? (datadcomp[ix] || 0) : 0;
                var i  = ix >= 0 ? (dataincon[ix] || 0) : 0;
                var t  = ix >= 0 ? (datatotal[ix] || 0) : 0;
                return '<b>' + esc(this.x) + '</b><br/>'
                     + '<span style="color:' + COLORS.complies    + '">&#9679;</span> Complies: <b>' + c + '</b><br/>'
                     + '<span style="color:' + COLORS.dcomplies   + '">&#9679;</span> Does not comply: <b>' + d + '</b><br/>'
                     + '<span style="color:' + COLORS.inconclusive+ '">&#9679;</span> Inconclusive: <b>' + i + '</b><br/>'
                     + '<span style="color:' + COLORS.total       + '">&#9679;</span> <b>Total tested: ' + t + '</b>';
            }
        },
        plotOptions: {
            column: {
                borderRadius: 2,
                pointPadding: 0.02,
                groupPadding: 0.12
            }
        },
        series: [{
            name: 'Complies',
            data: datacomp,
            color: COLORS.complies
        },{
            name: 'Does not comply',
            data: datadcomp,
            color: COLORS.dcomplies
        },{
            name: 'Inconclusive',
            data: dataincon,
            color: COLORS.inconclusive
        },{
            // Invisible overlay series pinned to the per-category Total — purely so
            // the dataLabels formatter can print "Total: N" above each category group.
            // enableMouseTracking:false + showInLegend:false keep it out of the
            // legend and tooltip; grouping:false floats it over the whole group
            // instead of adding a fourth visible bar.
            name: 'Total',
            type: 'column',
            data: datatotal,
            color: 'rgba(0,0,0,0)',
            enableMouseTracking: false,
            showInLegend: false,
            borderWidth: 0,
            grouping: false,
            dataLabels: {
                enabled: true,
                inside: false,
                verticalAlign: 'bottom',
                y: -4,
                formatter: function(){ return (this.y > 0) ? ('Total: ' + this.y) : ''; },
                style: { color: COLORS.total, textOutline: 'none', fontWeight: '700', fontSize: '11px' }
            }
        }],
        responsive: {
            rules: [{
                condition: { maxWidth: 500 },
                chartOptions: {
                    legend: { align: 'center', verticalAlign: 'bottom', layout: 'horizontal' },
                    yAxis:  { title: { text: null } }
                }
            }]
        }
    });
});
</script>
