<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	// SECURITY (privilege-escalation fix): the login handoff arrives as ?par=base64(acl:cat:unit).
	// Trusting it let any signed-in user forge their own role (e.g. ?par of "1:1:1" => Administrator).
	// The client value is now ignored; the effective role is re-derived straight from the shared
	// portal access table for the authenticated user (system_id 9 = MPQMS) on every dashboard load,
	// so $_SESSION['acl'/'usercat'/'unitacc'] are always authoritative and cannot be tampered with.
	if(!empty($_SESSION['users'])){
		$mpUser = mysqli_real_escape_string($commondb, $_SESSION['users']);
		$mpAcc  = mysqli_query($commondb, "SELECT access_level, user_category, unit_access FROM user_system_access_table WHERE username='$mpUser' AND system_id IN (9) LIMIT 1");
		if($mpAcc && ($mpRow = mysqli_fetch_row($mpAcc))){
			$_SESSION['acl']     = $mpRow[0];
			$_SESSION['usercat'] = $mpRow[1];
			$_SESSION['unitacc'] = $mpRow[2];
		}
	}
?>
<?php
	// Dashboard reporting period (year + quarter), resolved and validated ONCE here and reused by
	// every card, the monthly chart and the analytics AJAX request, so no two components can end
	// up showing different periods. See dashboard/period_lib.php.
	require_once(__DIR__.'/period_lib.php');
	$mpPeriod = mpq_period_from_request($_GET);
	$mpWhere  = mpq_period_where($mpPeriod);          // rt.registrationDate >= start AND < end
	// Kept for the existing captions that already print the reporting year.
	$year     = $mpPeriod['year'];
?>
<?php include_once("../includes/header.php"); ?>
	<!-- CSS -->
	<?php include_once("../includes/css-jquery.php"); ?>
	<!-- Highcharts -->
	<?php include_once("../includes/highcharts.php"); ?>

	<body class="hold-transition sidebar-mini layout-fixed">
		<div class="wrapper">
			<!-- Top Navbar -->
			<?php include_once("../includes/top_navigation.php"); ?>
			<!-- Main Sidebar Container -->
			<?php include_once("../includes/main_navigation.php"); ?>
			 <div class="content-wrapper">
				<!-- Oct 2026 — scoped dashboard polish. Alignment-only CSS; adds no behaviour.
				     All rules below are scoped to .mp-dash-head / .mp-bfda-filter /
				     .mp-bfda-table / .mp-kpi-grid, so nothing bleeds to other pages. -->
				<style>
					/* 1. Header row — title + quarter selector share one baseline. */
					.mp-dash-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:4px 0; min-height:48px; }
					.mp-dash-head h1 { margin:0; font-size:1.6rem; line-height:40px; }
					.mp-dash-head .mp-dash-period { display:flex; align-items:center; gap:8px; }
					.mp-dash-head .mp-dash-period label { margin:0; font-weight:600; line-height:38px; }
					.mp-dash-head .mp-dash-period select { height:38px; min-width:170px; font-size:14px; }

					/* 2. BFDA filter bar — 4-col inline grid; Reset/Apply occupy column 4 of row 2
					      alongside Batch / Expiry / Registration Status. */
					.mp-bfda-filter { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px 12px; align-items:end; margin-bottom:4px; }
					.mp-bfda-filter .mp-bfda-field { display:flex; flex-direction:column; min-width:0; }
					.mp-bfda-filter .mp-bfda-field label { font-size:12px; font-weight:600; color:#64748b; margin:0 0 4px 2px; line-height:1.1; letter-spacing:.2px; text-transform:uppercase; }
					.mp-bfda-filter .form-control { height:38px; font-size:14px; border-radius:6px; }
					.mp-bfda-filter .mp-bfda-actions { display:flex; align-items:flex-end; justify-content:flex-end; gap:8px; }
					.mp-bfda-filter .mp-bfda-actions .btn { height:38px; min-width:92px; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
					@media (max-width: 992px){
						.mp-bfda-filter { grid-template-columns:repeat(2, minmax(0,1fr)); }
						.mp-bfda-filter .mp-bfda-actions { grid-column:1 / -1; margin-top:4px; }
					}
					@media (max-width: 576px){
						.mp-bfda-filter { grid-template-columns:1fr; }
					}

					/* 3. Recent Registrations table — alignment + consistent row rhythm. */
					.mp-bfda-table thead th { text-transform:uppercase; font-size:11px; letter-spacing:.3px; color:#64748b; border-bottom:2px solid #e2e8f0; padding:10px 12px; background:#f8fafc; }
					.mp-bfda-table tbody td { padding:10px 12px; vertical-align:middle; line-height:1.4; }
					.mp-bfda-table .t-left   { text-align:left;   }
					.mp-bfda-table .t-center { text-align:center; }
					.mp-bfda-table .t-right  { text-align:right;  }
					.mp-bfda-table tbody td.t-left   { word-break:break-word; }
					.mp-bfda-table .badge { font-size:11px; padding:5px 8px; border-radius:12px; font-weight:600; }
					.mp-bfda-table .btn-xs { line-height:1; padding:5px 7px; margin-left:2px; }

					/* 4. KPI small-boxes — equal height, equal padding, icons centred. */
					.mp-kpi-grid .small-box { min-height:112px; display:flex; align-items:center; justify-content:space-between; padding:16px 18px; margin-bottom:16px; border-radius:10px; box-shadow:0 1px 2px rgba(15,23,42,.06); }
					.mp-kpi-grid .small-box .inner { padding:0; display:flex; flex-direction:column; justify-content:center; gap:4px; }
					.mp-kpi-grid .small-box .inner > b { font-size:13px; font-weight:600; letter-spacing:.2px; opacity:.95; margin:0; }
					.mp-kpi-grid .small-box .inner > p { font-size:26px; font-weight:700; margin:0; line-height:1.1; }
					.mp-kpi-grid .small-box .icon { position:static; display:flex; align-items:center; justify-content:center; width:56px; height:56px; opacity:.9; }
					.mp-kpi-grid .small-box .icon i { font-size:40px; line-height:1; margin:0; }
				</style>
				<div class="content-header">
				  <div class="container-fluid">
					<div class="mp-dash-head">
					  <h1>Dashboard</h1>
					  <div class="mp-dash-period">
						<!-- Reporting period. Drives EVERY card, the monthly chart and the analytics charts. -->
						<label for="mpDashQuarter">Quarter</label>
						<select id="mpDashQuarter" class="form-control form-control-sm">
							<?php foreach(mpq_quarter_list() as $mpQv => $mpQl){
								echo "<option value='".$mpQv."' ".($mpQv === $mpPeriod['quarter'] ? 'selected' : '').">".htmlspecialchars($mpQl)."</option>";
							} ?>
						</select>
					  </div>
					</div>
				  </div>
				</div>
			<?php
				list($tottst) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND ft.result IN('Complies','Does not comply','Inconclusive') AND ".$mpWhere.""));
				list($samrej) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,samplestatustable st WHERE rt.registrationID=st.registrationID AND st.status='Rejected' AND ".$mpWhere.""));
				list($tstpnd) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.result NOT IN('Complies','Does not comply','Inconclusive') AND ".$mpWhere." AND rt.labID = 1"));
				list($apvpnd) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Pending' AND ".$mpWhere.""));
				// Req 9: total tests conducted + compliance breakdown (current year, by final result)
				list($totcond)  = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND ".$mpWhere.""));
				list($totcomp)  = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND ft.result='Complies' AND ".$mpWhere.""));
				list($totncomp) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND ft.result='Does not comply' AND ".$mpWhere.""));
				// Req 8: total pending individual test parameters (current year)
				list($pndparam) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.result NOT IN('Complies','Does not comply','Inconclusive') AND ".$mpWhere.""));
			?>

				<section class="content">
	      			<div class="container-fluid">
	       			 	<div class="row mp-kpi-grid">
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-success">
				              <div class="inner">
				                <b>Samples Tested</b>
				                <p><?=$tottst;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-vials"></i>
				              </div>
				            </div>
				          </div>
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-info">
				              <div class="inner">
				                <b>Test Pending</b>
				                <p><?=$tstpnd;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-hourglass-half"></i>
				              </div>
				            </div>
				          </div>
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-warning">
				              <div class="inner">
				                <b>Verification Pending</b>
				                <p><?=$apvpnd;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-user-shield"></i>
				              </div>
				            </div>
				          </div>
				           <div class="col-lg-3 col-6">
				            <div class="small-box bg-danger">
				              <div class="inner">
				                <b>Samples Rejected</b>
				                <p><?=$samrej;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-times-circle"></i>
				              </div>
				            </div>
				          </div>
			        	</div>
			      	</div>
			    </section>
			    <section class="content">
	      			<div class="container-fluid">
	       			 	<div class="row mp-kpi-grid">
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-secondary">
				              <div class="inner">
				                <b>Total Tests Conducted</b>
				                <p><?=$totcond;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-clipboard-check"></i>
				              </div>
				            </div>
				          </div>
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-success">
				              <div class="inner">
				                <b>Complies</b>
				                <p><?=$totcomp;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-check-circle"></i>
				              </div>
				            </div>
				          </div>
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-danger">
				              <div class="inner">
				                <b>Non-Complies</b>
				                <p><?=$totncomp;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-ban"></i>
				              </div>
				            </div>
				          </div>
				          <div class="col-lg-3 col-6">
				            <div class="small-box bg-warning">
				              <div class="inner">
				                <b>Pending Test Parameters</b>
				                <p><?=$pndparam;?></p>
				              </div>
				              <div class="icon">
				                <i class="fas fa-flask"></i>
				              </div>
				            </div>
				          </div>
			        	</div>
			      	</div>
			    </section>
			    <section class="content">
					<div class="container-fluid">
						<div class="card card-outline card-info">
							<div class="card-header">
								<h3 class="card-title"><i class="fas fa-chart-bar text-info"></i> &nbsp;<b>Monthly Testing Status</b> — approved tests by parameter, <?=$year;?></h3>
								<div class="card-tools">
								  <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
									<i class="fas fa-minus"></i>
								  </button>
								</div>
							</div>
							<div class="card-body">
							<?php
								// The three months of the selected quarter (was: January..last month).
								$month = $monthName = array();
								$ass = $wgt = $din = $dss = $fri = $idt = $stt = $ucn = $uwg = array();
								foreach($mpPeriod['months'] as $i){
									$month[] = $i;
									$monthName[] = date("F", mktime(0, 0, 0, $i, 1));
								}
								for($j=0;$j<count($month);$j++){
									$ass[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='1'"));
									$wgt[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='3'"));
									$din[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='5'"));
									$dss[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='6'"));
									$fri[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='8'"));
									$idt[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='9'"));
									$stt[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='15'"));
									$ucn[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='17'"));
									$uwg[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND ".mpq_month_where($mpPeriod, $month[$j])." AND lt.parameterID='19'"));
								}

								$datacat = json_encode($monthName,JSON_NUMERIC_CHECK);
								$dataass = json_encode($ass,JSON_NUMERIC_CHECK);
								$datawgt = json_encode($wgt,JSON_NUMERIC_CHECK);
								$datadin = json_encode($din,JSON_NUMERIC_CHECK);
								$datadss = json_encode($dss,JSON_NUMERIC_CHECK);
								$datafri = json_encode($fri,JSON_NUMERIC_CHECK);
								$dataidt = json_encode($idt,JSON_NUMERIC_CHECK);
								$datastt = json_encode($stt,JSON_NUMERIC_CHECK);
								$dataucn = json_encode($ucn,JSON_NUMERIC_CHECK);
								$datauwg = json_encode($uwg,JSON_NUMERIC_CHECK);

								echo("<div id='container' style='height:380px;'></div>");
								include("../reports/test_chart2.php");
							?>
							</div>
						</div>
					</div>
				</section>

			    <!-- ============================================================= -->
			    <!-- Analytics & Quality Insights  (ADDITIVE — independent section) -->
			    <!-- Everything above is untouched; this block only adds new charts. -->
			    <!-- ============================================================= -->
			    <section class="content">
					<div class="container-fluid">
						<!-- Section header: title + shared Year selector (drives all three charts, unchanged) -->
						<div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
							<h5 class="mb-2 mb-sm-0"><i class="fas fa-chart-pie text-primary"></i> &nbsp;<b>Analytics &amp; Quality Insights</b></h5>
							<div class="d-flex align-items-center">
								<label for="mpAnYear" class="mb-0 mr-2"><b>Year</b></label>
								<select id="mpAnYear" class="form-control form-control-sm" style="width:auto;min-width:110px;">
								<?php
									// Year list generated dynamically from the data; default = the selected period's year.
									$mpCur   = (int)$mpPeriod['year'];
									$mpYears = array();
									$mpYq = mysqli_query($connection,"SELECT DISTINCT YEAR(registrationDate) AS y FROM registrationtable ORDER BY y DESC");
									if($mpYq){ while($mpr = mysqli_fetch_row($mpYq)){ $mpYears[] = (int)$mpr[0]; } }
									if(!in_array($mpCur, $mpYears, true)){ array_unshift($mpYears, $mpCur); }
									foreach($mpYears as $mpY){
										$mpSel = ($mpY === $mpCur) ? 'selected' : '';
										echo "<option value='".$mpY."' ".$mpSel.">".$mpY."</option>";
									}
								?>
								</select>
							</div>
						</div>

						<!-- Each chart now lives in its own independent professional card -->
						<div class="row">
							<!-- Card 1: Quality Outcome (doughnut) -->
							<div class="col-lg-5 col-12 d-flex">
								<div class="card card-outline card-primary w-100">
									<div class="card-header">
										<h3 class="card-title"><i class="fas fa-chart-pie text-primary"></i> &nbsp;<b>Quality Outcome</b> <small class="text-muted">(<?=htmlspecialchars($mpPeriod['label'].' '.$mpPeriod['year']);?>)</small></h3>
									</div>
									<div class="card-body">
										<div class="mp-an-card">
											<div class="mp-an-loading text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
											<div class="mp-an-msg text-center text-muted py-5" style="display:none;"></div>
											<div id="mpChartDough" class="mp-an-chart" style="min-height:340px;display:none;"></div>
										</div>
									</div>
								</div>
							</div>
							<!-- Card 2: Samples Tested by Formulation (horizontal bar) -->
							<div class="col-lg-7 col-12 d-flex">
								<div class="card card-outline card-info w-100">
									<div class="card-header">
										<h3 class="card-title"><i class="fas fa-chart-bar text-info"></i> &nbsp;<b>Samples Tested by Formulation</b> <small class="text-muted">(<?=htmlspecialchars($mpPeriod['label'].' '.$mpPeriod['year']);?>)</small></h3>
									</div>
									<div class="card-body">
										<div class="mp-an-card">
											<div class="mp-an-loading text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
											<div class="mp-an-msg text-center text-muted py-5" style="display:none;"></div>
											<div id="mpChartForm" class="mp-an-chart" style="min-height:340px;display:none;"></div>
										</div>
									</div>
								</div>
							</div>
							<!-- Card 3: Quality Trend by Year (full-width stacked column) -->
							<div class="col-12">
								<div class="card card-outline card-success">
									<div class="card-header">
										<h3 class="card-title"><i class="fas fa-chart-line text-success"></i> &nbsp;<b>Quality Trend by Year</b> <small class="text-muted">(Complies vs Non-Complies)</small></h3>
									</div>
									<div class="card-body">
										<div class="mp-an-card">
											<div class="mp-an-loading text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
											<div class="mp-an-msg text-center text-muted py-5" style="display:none;"></div>
											<div id="mpChartTrend" class="mp-an-chart" style="min-height:360px;display:none;"></div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				    </section>

			    <script>
			    (function($){
			        // Self-contained Analytics loader — does not touch any existing dashboard JS.
			        var IDS = ['mpChartDough','mpChartForm','mpChartTrend'];

			        function card(id){ return $('#'+id).closest('.mp-an-card'); }
			        // states: 'loading' | 'chart' | 'msg'
			        function setState(id, state, text){
			            var c = card(id);
			            c.find('.mp-an-loading').toggle(state === 'loading');
			            c.find('.mp-an-msg').toggle(state === 'msg').text(text || '');
			            c.find('.mp-an-chart').css('display', state === 'chart' ? 'block' : 'none');
			        }
			        function allState(state, text){ IDS.forEach(function(id){ setState(id, state, text); }); }

			        function renderDoughnut(d){
			            if(!d || !d.total){ setState('mpChartDough','msg','No Data Available'); return; }
			            setState('mpChartDough','chart');
			            Highcharts.chart('mpChartDough', {
			                chart:{ type:'pie' },
			                title:{ text:null },
			                credits:{ enabled:false },
			                tooltip:{ pointFormat:'<b>{point.y}</b> ({point.percentage:.1f}%)' },
			                legend:{ enabled:true },
			                plotOptions:{ pie:{ innerSize:'65%', dataLabels:{ enabled:true, format:'{point.name}: {point.percentage:.1f}%' }, showInLegend:true } },
			                exporting:{ enabled:true },
			                series:[{ name:'Samples', colorByPoint:true, data:[
			                    { name:'Complies',     y:d.complies,    color:'#28a745' },
			                    { name:'Non-Complies', y:d.noncomplies, color:'#dc3545' }
			                ]}],
			                subtitle:{ useHTML:true, floating:true, verticalAlign:'middle', y:6,
			                    text:'<div style="text-align:center;line-height:1.1"><div style="font-size:26px;font-weight:700">'+d.total+'</div><div style="font-size:12px;color:#6c757d">Total Tested</div></div>' }
			            });
			        }

			        function renderFormulation(f){
			            if(!f || !f.names || !f.names.length){ setState('mpChartForm','msg','No Data Available'); return; }
			            setState('mpChartForm','chart');
			            // Server sends highest->lowest; reverse so the tallest bar sits at the TOP.
			            var names  = f.names.slice().reverse();
			            var counts = f.counts.slice().reverse();
			            Highcharts.chart('mpChartForm', {
			                chart:{ type:'bar', height: Math.max(340, names.length * 34 + 70) },
			                title:{ text:null },
			                credits:{ enabled:false },
			                xAxis:{ categories:names, title:{ text:null } },
			                yAxis:{ min:0, allowDecimals:false, title:{ text:'Samples Tested' } },
			                tooltip:{ pointFormat:'<b>{point.y}</b> samples tested' },
			                legend:{ enabled:false },
			                plotOptions:{ bar:{ dataLabels:{ enabled:true }, color:'#17a2b8' } },
			                exporting:{ enabled:true },
			                series:[{ name:'Samples Tested', data:counts }]
			            });
			        }

			        function renderTrend(t){
			            if(!t || !t.years || !t.years.length){ setState('mpChartTrend','msg','No Data Available'); return; }
			            setState('mpChartTrend','chart');
			            Highcharts.chart('mpChartTrend', {
			                chart:{ type:'column' },
			                title:{ text:null },
			                credits:{ enabled:false },
			                xAxis:{ categories:t.years },
			                yAxis:{ min:0, allowDecimals:false, title:{ text:'Samples' }, stackLabels:{ enabled:true } },
			                tooltip:{ shared:true },
			                legend:{ enabled:true },
			                plotOptions:{ column:{ stacking:'normal' } },
			                exporting:{ enabled:true },
			                series:[
			                    { name:'Complies',     data:t.complies,    color:'#28a745' },
			                    { name:'Non-Complies', data:t.noncomplies, color:'#dc3545' }
			                ]
			            });
			        }

			        // The Dashboard's period, rendered server-side and echoed here so every AJAX
			        // request uses exactly the same year and quarter as the cards.
			        var mpPeriod = { y: <?=(int)$mpPeriod['year'];?>, q: '<?=$mpPeriod['quarter'];?>' };

			        function load(year){
			            allState('loading');
			            $.ajax({ url:'analytics_data.php', data:{ year:year, q:mpPeriod.q, ajax:1 }, dataType:'json', timeout:20000 })
			                .done(function(res){
			                    if(!res || res.status !== 'success'){ allState('msg','Unable to load analytics.'); return; }
			                    renderDoughnut(res.doughnut);
			                    renderFormulation(res.formulation);
			                    renderTrend(res.trend);
			                })
			                .fail(function(){ allState('msg','Unable to load analytics.'); });
			        }

			        $(function(){
			            // Changing either control reloads the Dashboard with the new period, because the
			            // statistic cards and the monthly chart are server-rendered. The whole page then
			            // shows one consistent quarter — no component is left on the previous one.
			            function goTo(y, q){
			                var u = new URL(window.location.href);
			                u.searchParams.set('y', y);
			                u.searchParams.set('q', q);
			                window.location.assign(u.toString());
			            }
			            $('#mpDashQuarter').on('change', function(){ goTo(mpPeriod.y, $(this).val()); });
			            $('#mpAnYear').on('change', function(){ goTo($(this).val(), mpPeriod.q); });
			            load(mpPeriod.y);   // initial load, already scoped to the selected quarter
			        });
			    })(jQuery);
			    </script>

			<?php
				// =====================================================================
				// Oct 2026 — BFDA Dashboard "Recent Registrations" (bottom placement).
				//
				// Full filter + data table, now positioned at the bottom of the
				// dashboard page beneath every KPI card and chart (Oct-2026 directive).
				// The whole section is strictly conditional on the effective role being
				// BFDA — every other role sees the dashboard exactly as before
				// (byte-identical HTML path). Filter inputs are read from $_GET (GET so
				// URLs are shareable). Each filter clause is parameter-safe: dates are
				// preg-validated and bound, text filters are escaped before being
				// LIKE'd, and the Status filter is an allowlisted enum. The whole BFDA
				// section has its own try/catch so a filter error can never break the
				// KPI tiles and charts above.
				// =====================================================================
				if(mpq_effective_role() === 'BFDA'){
					// --- Parse + validate filter params --------------------------------
					$mpf = array(
						'from'    => trim((string)($_GET['rr_from']    ?? '')),
						'to'      => trim((string)($_GET['rr_to']      ?? '')),
						'product' => trim((string)($_GET['rr_product'] ?? '')),
						'mfg'     => trim((string)($_GET['rr_mfg']     ?? '')),
						'batch'   => trim((string)($_GET['rr_batch']   ?? '')),
						'expiry'  => trim((string)($_GET['rr_expiry']  ?? '')),
						'status'  => trim((string)($_GET['rr_status']  ?? '')),
					);
					$mpBfdaIsFilter = ($mpf['from']||$mpf['to']||$mpf['product']||$mpf['mfg']||$mpf['batch']||$mpf['expiry']||$mpf['status']);
					$mpOkDate = function($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) ? 1 : 0; };
					$mpFromSql   = $mpOkDate($mpf['from'])   ? $mpf['from']   : date('Y-m-d', strtotime('-7 days'));
					$mpToSql     = $mpOkDate($mpf['to'])     ? $mpf['to']     : date('Y-m-d');
					$mpExpirySql = $mpOkDate($mpf['expiry']) ? $mpf['expiry'] : '';
					$mpStatusChoices = array('Pending', 'Accepted', 'Rejected', 'Complies', 'Does not comply', 'Inconclusive');
					$mpStatus = in_array($mpf['status'], $mpStatusChoices, true) ? $mpf['status'] : '';
					$mpEsc = function($v) use($connection){ return mysqli_real_escape_string($connection, (string)$v); };

					// --- Build the SQL --------------------------------------------------
					// Oct-13 2026 — BFDA MUST NOT see samples collected by NMPTL
					// (collectID = 998 sentinel). Hard-coded exclusion for this card.
					$mpWhereParts = array("rt.registrationDate >= '".$mpEsc($mpFromSql)."'",
					                      "rt.registrationDate <= '".$mpEsc($mpToSql)."'",
					                      "rt.collectID <> 998");
					if($mpf['product'] !== ''){
						$p = $mpEsc($mpf['product']);
						$mpWhereParts[] = "(edl.edl LIKE '%$p%' OR rt.edlOther LIKE '%$p%')";
					}
					if($mpf['mfg'] !== ''){
						$p = $mpEsc($mpf['mfg']);
						$mpWhereParts[] = "(mfg.name LIKE '%$p%' OR rt.manufacturerOther LIKE '%$p%')";
					}
					if($mpf['batch'] !== ''){
						$mpWhereParts[] = "rt.batchNo LIKE '%".$mpEsc($mpf['batch'])."%'";
					}
					if($mpExpirySql !== ''){
						$mpWhereParts[] = "rt.expDate <= '".$mpEsc($mpExpirySql)."' AND rt.expDate <> '1900-01-01'";
					}
					switch($mpStatus){
						case 'Pending':
							$mpWhereParts[] = "(st.status IS NULL OR st.status='') AND (fr.result IS NULL OR fr.result='')"; break;
						case 'Accepted':   $mpWhereParts[] = "st.status='Accepted'"; break;
						case 'Rejected':   $mpWhereParts[] = "st.status='Rejected'"; break;
						case 'Complies':
						case 'Does not comply':
						case 'Inconclusive':
							$mpWhereParts[] = "fr.result='".$mpEsc($mpStatus)."'"; break;
					}
					// Oct-2026 directive — newest registered entries must appear at the
					// very top of the table. registrationDate DESC primary, registrationID
					// DESC secondary so same-date entries fall newest-first.
					$mpBfdaSql = "SELECT rt.registrationID, rt.sampleID, rt.registrationDate,
					              COALESCE(edl.edl, rt.edlOther, '') AS prod,
					              COALESCE(mfg.name, rt.manufacturerOther, '') AS mfg_name,
					              rt.batchNo, rt.expDate,
					              COALESCE(st.status, '') AS acp,
					              COALESCE(fr.result, '') AS fin,
					              rt.entrytimestamp
					       FROM registrationtable rt
					       LEFT JOIN msedltable edl ON rt.edlID = edl.id
					       LEFT JOIN msmanufacturertable mfg ON rt.manufacturer = mfg.id
					       LEFT JOIN samplestatustable st ON st.registrationID = rt.registrationID
					       LEFT JOIN finalresulttable fr ON fr.registrationID = rt.registrationID
					       WHERE ".implode(' AND ', $mpWhereParts)."
					       ORDER BY rt.registrationDate DESC, rt.registrationID DESC
					       LIMIT 100";
					$mpBfdaRows = array();
					try {
						$mpBfdaRs = mysqli_query($connection, $mpBfdaSql);
						if($mpBfdaRs){
							while($row = mysqli_fetch_assoc($mpBfdaRs)){ $mpBfdaRows[] = $row; }
						}
					} catch(\Throwable $e){ /* dashboard must never break */ }
			?>
			<section class="content">
				<div class="container-fluid">
					<div class="card card-outline card-info">
						<div class="card-header">
							<h3 class="card-title m-0">
								<i class="fas fa-bell text-info"></i>&nbsp;
								<b>Recent Registrations</b>
								<small class="text-muted">
									— <?= htmlspecialchars($mpFromSql); ?> &rarr; <?= htmlspecialchars($mpToSql); ?>
									(<?=count($mpBfdaRows);?><?= (count($mpBfdaRows) === 100) ? '+' : ''; ?>)
								</small>
							</h3>
							<div class="card-tools">
								<a href="../regtest/manager.php" class="btn btn-sm btn-outline-primary">
									<i class="fas fa-list"></i>&nbsp;Full list
								</a>
							</div>
						</div>
						<div class="card-body py-3">
							<!-- Filter form — GET to the same page, params prefixed rr_ so they
							     never collide with the dashboard's existing ?y=&q= params.
							     Grid layout (.mp-bfda-filter) gives all inputs a shared
							     38px height and consistent label typography. Row 2 lays
							     out BATCH | EXPIRY | STATUS | Reset+Apply across 4 cols. -->
							<form method="get" action="dashboard.php" id="mpBfdaFilterForm" autocomplete="off">
								<?php
								foreach(array('y','q') as $kp){
									if(isset($_GET[$kp])){
										echo '<input type="hidden" name="'.htmlspecialchars($kp).'" value="'.htmlspecialchars((string)$_GET[$kp]).'">';
									}
								}
								?>
								<div class="mp-bfda-filter">
									<div class="mp-bfda-field">
										<label for="rr_from">From Date</label>
										<input type="date" id="rr_from" name="rr_from" class="form-control" value="<?= htmlspecialchars($mpFromSql); ?>">
									</div>
									<div class="mp-bfda-field">
										<label for="rr_to">To Date</label>
										<input type="date" id="rr_to" name="rr_to" class="form-control" value="<?= htmlspecialchars($mpToSql); ?>">
									</div>
									<div class="mp-bfda-field">
										<label for="rr_product">Product Name</label>
										<input type="text" id="rr_product" name="rr_product" class="form-control" placeholder="e.g. Paracetamol" value="<?= htmlspecialchars($mpf['product']); ?>">
									</div>
									<div class="mp-bfda-field">
										<label for="rr_mfg">Manufacturer</label>
										<input type="text" id="rr_mfg" name="rr_mfg" class="form-control" placeholder="e.g. GlaxoSmithKline" value="<?= htmlspecialchars($mpf['mfg']); ?>">
									</div>
									<div class="mp-bfda-field">
										<label for="rr_batch">Batch Number</label>
										<input type="text" id="rr_batch" name="rr_batch" class="form-control" placeholder="Batch / Lot No." value="<?= htmlspecialchars($mpf['batch']); ?>">
									</div>
									<div class="mp-bfda-field">
										<label for="rr_expiry">Expiry Date (on or before)</label>
										<input type="date" id="rr_expiry" name="rr_expiry" class="form-control" value="<?= htmlspecialchars($mpExpirySql); ?>">
									</div>
									<div class="mp-bfda-field">
										<label for="rr_status">Registration Status</label>
										<select id="rr_status" name="rr_status" class="form-control">
											<option value="">All statuses</option>
											<?php foreach($mpStatusChoices as $s){
												$sel = ($s === $mpStatus) ? ' selected' : '';
												echo '<option value="'.htmlspecialchars($s).'"'.$sel.'>'.htmlspecialchars($s).'</option>';
											} ?>
										</select>
									</div>
									<div class="mp-bfda-actions">
										<a href="dashboard.php<?= (isset($_GET['y']) || isset($_GET['q'])) ? ('?' . http_build_query(array_intersect_key($_GET, array_flip(array('y','q'))))) : ''; ?>" class="btn btn-outline-secondary"><i class="fas fa-sync"></i>Reset</a>
										<button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i>Apply</button>
									</div>
								</div>
							</form>
						</div>
						<div class="card-body p-0">
							<?php if(empty($mpBfdaRows)){ ?>
								<div class="p-4 text-center text-muted"><i class="far fa-inbox"></i>&nbsp;<?= $mpBfdaIsFilter ? 'No registrations match the current filters.' : 'No registrations in the last 7 days.'; ?></div>
							<?php } else { ?>
							<div class="table-responsive">
								<table class="table table-hover mb-0 mp-bfda-table">
									<thead>
										<tr>
											<th class="t-left"   style="min-width:140px;">Sample ID</th>
											<th class="t-center" style="min-width:110px;">Reg. Date</th>
											<th class="t-left"   style="min-width:160px;">Product</th>
											<th class="t-left"   style="min-width:160px;">Manufacturer</th>
											<th class="t-left"   style="min-width:110px;">Batch</th>
											<th class="t-center" style="min-width:110px;">Expiry</th>
											<th class="t-center" style="min-width:120px;">Status</th>
											<th class="t-right"  style="min-width:120px;">Action</th>
										</tr>
									</thead>
									<tbody>
									<?php
										$mpYesterday  = (new DateTime('today'))->modify('-1 day');
										$mpCanEditReg = function_exists('mpq_can_do') ? mpq_can_do('registration','edit')   : false;
										$mpCanDelReg  = function_exists('mpq_can_do') ? mpq_can_do('registration','delete') : false;
										foreach($mpBfdaRows as $r){
											$rid  = (int)$r['registrationID'];
											$sid  = htmlspecialchars((string)$r['sampleID']);
											$dor  = (string)$r['registrationDate'];
											$prod = htmlspecialchars((string)$r['prod']);
											$mfgN = htmlspecialchars((string)$r['mfg_name']);
											$bno  = htmlspecialchars((string)$r['batchNo']);
											$exp  = (string)$r['expDate'];
											$expDisp = ($exp === '1900-01-01' || $exp === '0000-00-00' || $exp === '') ? '—' : htmlspecialchars($exp);
											$acp  = (string)$r['acp'];
											$fin  = (string)$r['fin'];
											if($fin !== ''){
												$statusTxt = htmlspecialchars($fin);
												$statusCls = ($fin === 'Complies') ? 'badge-success'
												           : (($fin === 'Does not comply') ? 'badge-danger' : 'badge-warning');
											} elseif($acp !== ''){
												$statusTxt = htmlspecialchars($acp);
												$statusCls = ($acp === 'Accepted') ? 'badge-info' : 'badge-secondary';
											} else {
												$statusTxt = 'Pending';
												$statusCls = 'badge-secondary';
											}
											$isNew = false;
											try {
												$ts = DateTime::createFromFormat('Y-m-d H:i:s', (string)$r['entrytimestamp']);
												if($ts && $ts >= $mpYesterday){ $isNew = true; }
											} catch(\Throwable $e){}
									?>
										<tr<?= $isNew ? ' style="background:#fff9db;"' : '';?>>
											<td class="t-left"><strong><?= $sid; ?></strong><?php if($isNew){ echo ' <span class="badge badge-pill badge-success ml-1" style="font-size:10px;">NEW</span>'; } ?></td>
											<td class="t-center"><?= htmlspecialchars($dor); ?></td>
											<td class="t-left"><?= $prod; ?></td>
											<td class="t-left"><?= $mfgN; ?></td>
											<td class="t-left"><?= $bno; ?></td>
											<td class="t-center"><?= $expDisp; ?></td>
											<td class="t-center"><span class="badge <?= $statusCls; ?>"><?= $statusTxt; ?></span></td>
											<td class="t-right">
												<a href="../regtest/view.php?rid=<?= $rid; ?>" class="btn btn-xs btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
												<?php if($mpCanEditReg){ ?>
													<a href="../regtest/registration.php?par=2&rid=<?= $rid; ?>" class="btn btn-xs btn-outline-primary" title="Edit"><i class="far fa-edit"></i></a>
												<?php } ?>
												<?php if($mpCanDelReg){ ?>
													<a href="javascript:void(0)" data-rid="<?= $rid; ?>" class="btn btn-xs btn-outline-danger mpd-bfda-del" title="Delete"><i class="fas fa-trash-alt"></i></a>
												<?php } ?>
											</td>
										</tr>
									<?php } // foreach ?>
									</tbody>
								</table>
							</div>
							<?php } ?>
						</div>
					</div>
				</div>
			</section>
			<?php if(!empty($mpBfdaRows) && (function_exists('mpq_can_do') && mpq_can_do('registration','delete'))){ ?>
			<script>
			$(function(){
				$(document).on('click', '.mpd-bfda-del', function(){
					var rid = $(this).data('rid');
					Swal.fire({ title:'Delete this registration?', text:'This cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete it' })
					.then(function(res){
						if(!res.isConfirmed) return;
						var tok = $('meta[name="csrf-token"]').attr('content') || '';
						$.ajax({ url:'../regtest/delete.php', type:'POST', data:{ rid:rid, rtyp:'R', csrf:tok }, dataType:'json',
							headers:{'X-CSRF-Token':tok} })
							.done(function(resp){
								Swal.fire({ icon:(resp&&resp.status==='success')?'success':'error', text:(resp&&resp.message)||'Done.', timer:1500, showConfirmButton:false });
								if(resp && resp.status==='success'){ setTimeout(function(){ location.reload(); }, 800); }
							})
							.fail(function(){ Swal.fire({ icon:'error', text:'Unable to delete. Please try again.' }); });
					});
				});
			});
			</script>
			<?php } ?>
			<?php } // end BFDA conditional ?>

			   </div>
			<?php include_once("../includes/footer.php"); ?>
		</div>
	</body>
</html>
