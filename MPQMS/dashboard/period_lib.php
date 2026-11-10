<?php
/*
 * MPQMS Dashboard — reporting-period (year + quarter) resolution.
 *
 * ONE place decides which period the Dashboard is showing, so the statistic cards, the monthly
 * chart and the AJAX analytics charts can never disagree about it. Both dashboard.php and
 * analytics_data.php resolve the period through mpq_period_from_request().
 *
 * Reporting convention: the CALENDAR year already used everywhere on the Dashboard
 * (YEAR(registrationtable.registrationDate)) and the four calendar quarters within it:
 *
 *     Q1  1 Jan  - 31 Mar        Q3  1 Jul  - 30 Sep
 *     Q2  1 Apr  - 30 Jun        Q4  1 Oct  - 31 Dec
 *
 * Every Dashboard metric is keyed off registrationtable.registrationDate — the date the sample
 * entered the system. That is the existing business date for all of them, so the quarter is
 * applied to that column and to nothing else; no metric changes its meaning.
 *
 * Ranges are half-open, [start, end), so a record dated exactly on the first day of a quarter is
 * counted in that quarter and a record on the first day of the next quarter is not counted twice.
 */

if(!function_exists('mpq_quarter_list')){
	/** The only four quarters the Dashboard accepts, with their display labels. */
	function mpq_quarter_list(){
		return array(
			'Q1' => 'Q1 (Jan - Mar)',
			'Q2' => 'Q2 (Apr - Jun)',
			'Q3' => 'Q3 (Jul - Sep)',
			'Q4' => 'Q4 (Oct - Dec)',
		);
	}
}

if(!function_exists('mpq_quarter_valid')){
	/** Whitelist check. Anything that is not exactly Q1..Q4 is rejected. */
	function mpq_quarter_valid($q){
		return is_string($q) && isset(mpq_quarter_list()[strtoupper(trim($q))]);
	}
}

if(!function_exists('mpq_quarter_current')){
	/** The quarter today falls in — the Dashboard's default. */
	function mpq_quarter_current($ts = null){
		$m = (int)date('n', $ts === null ? time() : $ts);
		return 'Q'.(int)(floor(($m - 1) / 3) + 1);
	}
}

if(!function_exists('mpq_quarter_months')){
	/** The three calendar month numbers of a quarter, in order. */
	function mpq_quarter_months($q){
		$n = (int)substr(strtoupper($q), 1, 1);
		$first = ($n - 1) * 3 + 1;
		return array($first, $first + 1, $first + 2);
	}
}

if(!function_exists('mpq_period_from_request')){
	/**
	 * Resolve the Dashboard reporting period from the request, validated server-side.
	 * The client is never trusted: an absent, malformed or out-of-range value falls back to the
	 * current period rather than widening the query.
	 *
	 * @return array year, quarter, label, months[], start 'Y-m-d', end 'Y-m-d' (EXCLUSIVE),
	 *               month_ranges[month => array(start, end)]
	 */
	function mpq_period_from_request($req = null){
		if($req === null){ $req = $_REQUEST; }

		$year = isset($req['y']) ? (int)$req['y'] : (isset($req['year']) ? (int)$req['year'] : (int)date('Y'));
		if($year < 1900 || $year > 2100){ $year = (int)date('Y'); }

		$q = isset($req['q']) ? strtoupper(trim((string)$req['q'])) : '';
		if(!mpq_quarter_valid($q)){ $q = mpq_quarter_current(); }

		$months = mpq_quarter_months($q);
		$start  = sprintf('%04d-%02d-01', $year, $months[0]);
		// Exclusive upper bound = the first day of the month after the quarter.
		$endM   = $months[2] + 1; $endY = $year;
		if($endM > 12){ $endM = 1; $endY++; }
		$end    = sprintf('%04d-%02d-01', $endY, $endM);

		$monthRanges = array();
		foreach($months as $m){
			$nm = $m + 1; $ny = $year;
			if($nm > 12){ $nm = 1; $ny++; }
			$monthRanges[$m] = array(
				'start' => sprintf('%04d-%02d-01', $year, $m),
				'end'   => sprintf('%04d-%02d-01', $ny, $nm),
			);
		}

		$labels = mpq_quarter_list();
		return array(
			'year'         => $year,
			'quarter'      => $q,
			'label'        => $labels[$q],
			'months'       => $months,
			'start'        => $start,
			'end'          => $end,
			'month_ranges' => $monthRanges,
		);
	}
}

if(!function_exists('mpq_period_where')){
	/**
	 * The SQL date condition for a resolved period. Both bounds are literals this library built
	 * from validated integers, so nothing from the request reaches the query.
	 *
	 * @param string $col fully-qualified date column, e.g. "rt.registrationDate"
	 */
	function mpq_period_where($period, $col = 'rt.registrationDate'){
		return $col." >= '".$period['start']."' AND ".$col." < '".$period['end']."'";
	}
}

if(!function_exists('mpq_month_where')){
	/** The SQL date condition for one month of the resolved period. */
	function mpq_month_where($period, $month, $col = 'rt.registrationDate'){
		if(!isset($period['month_ranges'][$month])){ return '1=0'; }
		$r = $period['month_ranges'][$month];
		return $col." >= '".$r['start']."' AND ".$col." < '".$r['end']."'";
	}
}
