<?php
	// ---------------------------------------------------------------------------
	// MPQMS Dashboard — Analytics & Quality Insights (AJAX data endpoint)
	//
	// Pure additive endpoint for the dashboard Analytics section. Returns JSON for
	// three charts (doughnut / horizontal bar / stacked column) for a selected year.
	//
	// Data scope matches the EXISTING dashboard summary cards exactly: compliance is
	// read from finalresulttable joined to registrationtable on registrationID and
	// filtered by YEAR(registrationDate). No new permission surface — auth_ndtlis.php
	// enforces an authenticated session (same gate as dashboard.php); the figures are
	// the same global figures every role already sees on the dashboard.
	//
	// All aggregation is done in SQL with GROUP BY; the joins hit indexed columns
	// (registrationID PKs, registrationDate / formulationID indexes).
	// ---------------------------------------------------------------------------
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");

	header('Content-Type: application/json');

	try {
		// Selected reporting period. Resolved and validated by the SAME helper the Dashboard page
		// uses, so the charts can never interpret the quarter differently from the cards. An absent
		// or invalid quarter falls back to the current one; it never widens the query.
		require_once(__DIR__.'/period_lib.php');
		$period = mpq_period_from_request($_GET);
		$year   = $period['year'];
		$pWhere = mpq_period_where($period);

		// 1) Quality Outcome (doughnut) — Complies vs Non-Complies for the selected year.
		//    Same definition as the dashboard "Complies" / "Non-Complies" cards.
		$complies = 0; $noncomplies = 0;
		$q1 = mysqli_query($connection,
			"SELECT ft.result, COUNT(*) AS c
			   FROM registrationtable rt
			   JOIN finalresulttable ft ON rt.registrationID = ft.registrationID
			  WHERE ft.result IN ('Complies','Does not comply')
			    AND $pWhere
			  GROUP BY ft.result");
		if($q1){
			while($r = mysqli_fetch_assoc($q1)){
				if($r['result'] === 'Complies'){ $complies = (int)$r['c']; }
				else { $noncomplies = (int)$r['c']; }
			}
		}

		// 2) Samples Tested by Formulation (horizontal bar) — selected year.
		//    Formulation names come dynamically from the master formulation table
		//    (msformulationtable) via the join; sorted highest -> lowest.
		$formNames = array(); $formCounts = array();
		$q2 = mysqli_query($connection,
			"SELECT f.formulation AS formulation, COUNT(*) AS c
			   FROM registrationtable rt
			   JOIN finalresulttable ft ON rt.registrationID = ft.registrationID
			   JOIN msformulationtable f ON rt.formulationID = f.id
			  WHERE ft.result IN ('Complies','Does not comply','Inconclusive')
			    AND $pWhere
			  GROUP BY f.id, f.formulation
			  ORDER BY c DESC, f.formulation ASC");
		if($q2){
			while($r = mysqli_fetch_assoc($q2)){
				$formNames[]  = $r['formulation'];
				$formCounts[] = (int)$r['c'];
			}
		}

		// 3) Quality Trend by Year (stacked column) — all years present in the data.
		//    Years are generated dynamically from the database (no hardcoded range).
		$trend = array();
		$q3 = mysqli_query($connection,
			"SELECT YEAR(rt.registrationDate) AS yr, ft.result AS result, COUNT(*) AS c
			   FROM registrationtable rt
			   JOIN finalresulttable ft ON rt.registrationID = ft.registrationID
			  WHERE ft.result IN ('Complies','Does not comply')
			    AND MONTH(rt.registrationDate) BETWEEN ".(int)$period['months'][0]." AND ".(int)$period['months'][2]."
			  GROUP BY yr, ft.result
			  ORDER BY yr ASC");
		if($q3){
			while($r = mysqli_fetch_assoc($q3)){
				$y = (string)(int)$r['yr'];
				if(!isset($trend[$y])){ $trend[$y] = array('c' => 0, 'n' => 0); }
				if($r['result'] === 'Complies'){ $trend[$y]['c'] = (int)$r['c']; }
				else { $trend[$y]['n'] = (int)$r['c']; }
			}
		}
		$tYears = array(); $tComp = array(); $tNon = array();
		foreach($trend as $y => $v){ $tYears[] = $y; $tComp[] = $v['c']; $tNon[] = $v['n']; }

		echo json_encode(array(
			'status'      => 'success',
			'year'        => $year,
			'quarter'     => $period['quarter'],
			'period'      => $period['label'].' '.$year,
			'doughnut'    => array(
				'complies'    => $complies,
				'noncomplies' => $noncomplies,
				'total'       => $complies + $noncomplies
			),
			'formulation' => array('names' => $formNames, 'counts' => $formCounts),
			'trend'       => array('years' => $tYears, 'complies' => $tComp, 'noncomplies' => $tNon)
		));
	} catch(\Throwable $e){
		// Never leak internals; the dashboard shows a friendly "Unable to load analytics."
		echo json_encode(array('status' => 'error', 'message' => 'Unable to load analytics.'));
	}
?>
