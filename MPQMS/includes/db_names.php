<?php
/*
 * MPQMS — real database names for cross-database queries.
 *
 * MPQMS uses two databases: its own (ndtlisdb) and the shared user database (commondb). A few
 * queries join across them and so must name the other database explicitly. Those names used to
 * be written literally as "ndtlisdb" and "commondb", which only works where the databases carry
 * exactly those names. On hosted servers they are usually prefixed with the account name, e.g.
 * "rcdc_ndtlisdb" and "rcdc_commondb", and every literal cross-database query then fails.
 *
 * mpq_db($link, 'commondb') returns the real name of that database, using the same naming as the
 * database $link is already connected to:
 *
 *     connected to "ndtlisdb"       ->  "commondb"        (local XAMPP)
 *     connected to "rcdc_ndtlisdb"  ->  "rcdc_commondb"   (hosted server)
 *
 * Nothing needs configuring and db_config.php is not touched, so the same code runs unchanged in
 * both places. If the current name is not recognisable, the plain base name is returned — exactly
 * the previous behaviour — so this can never make a query worse than it was.
 */

if(!function_exists('mpq_db')){
	/**
	 * @param mysqli $link  an open connection to either MPQMS database
	 * @param string $base  'ndtlisdb' or 'commondb'
	 * @return string       a safe identifier (letters, digits, underscore) to use as `name`.table
	 */
	function mpq_db($link, $base){
		static $current = array();
		$base = ($base === 'commondb') ? 'commondb' : 'ndtlisdb';

		// The database this connection is on, looked up once per connection.
		$key = is_object($link) ? spl_object_hash($link) : '';
		if(!isset($current[$key])){
			$cur = '';
			try {
				$r = $link ? mysqli_query($link, 'SELECT DATABASE()') : false;
				if($r){ $row = mysqli_fetch_row($r); $cur = (string)($row[0] ?? ''); }
			} catch(\Throwable $e){ $cur = ''; }
			$current[$key] = $cur;
		}
		$cur = $current[$key];

		// Reuse whatever prefix the connected database carries.
		$name = $base;
		foreach(array('ndtlisdb', 'commondb') as $known){
			$len = strlen($known);
			if($cur !== '' && strlen($cur) >= $len && substr($cur, -$len) === $known){
				$name = substr($cur, 0, strlen($cur) - $len).$base;
				break;
			}
		}
		// Defence in depth: only ever return a plain identifier.
		return preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : $base;
	}
}
