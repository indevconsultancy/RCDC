<?php
/*
 * MPQMS — dynamic BFDA / DRA recipient resolution for regulatory e-mails.
 *
 * Every MPQMS notification to the regulator (Final Result outcome, non-compliance alert,
 * official final-outcome report) used to go to the focal address held in the mail
 * configuration. This helper resolves the recipient(s) from EXISTING user-management data
 * instead, per sample, so nothing is hard-coded:
 *
 *   A BFDA/DRA user is a portal account with MPQMS access (commondb.user_system_access_table)
 *   system_id = 9, access_level = 7, user_category = 99, unit_access = 1, whose login is
 *   active (login_table.account_status = 1) and whose profile (user_profile_table) carries a
 *   valid e-mail address.
 *
 *   The sample -> BFDA/DRA relationship, in order of precision:
 *     1. ENTRY_USER         the BFDA/DRA user who registered (submitted) the sample
 *                           (registrationtable.entryUser).
 *     2. COLLECTING_CENTRE  the BFDA/DRA user(s) whose profile centre is the sample's
 *                           "Collected By" centre (registrationtable.collectID), then the
 *                           sampling site (siteID).
 *     3. DZONGKHAG          the BFDA/DRA user(s) whose centre lies in the same dzongkhag as the
 *                           collecting centre (regional office).
 *   When none of these yields an address the CALLER falls back to its existing configured
 *   focal address(es) so a sample is never left unreported - the fallback is logged.
 *
 * Read-only, prepared statements, never throws: any failure returns an empty list with a
 * reason, and the calling notifier continues exactly as before.
 *
 *   mpqms_bfda_recipients($conn, $regid)
 *     -> array('recipients'=>[e-mail...], 'users'=>[username...], 'basis'=>'ENTRY_USER'|
 *              'COLLECTING_CENTRE'|'SAMPLING_SITE'|'DZONGKHAG'|'NONE', 'reason'=>string)
 *
 * $conn is the ndtlisdb connection; commondb lives on the same server and is addressed with
 * a qualified name, so no second connection is needed inside library code.
 */

require_once(__DIR__.'/db_names.php');   // real database names (local or prefixed host)

if(!defined('MPQMS_BFDA_SYSTEM_ID'))     { define('MPQMS_BFDA_SYSTEM_ID', 9); }
if(!defined('MPQMS_BFDA_ACCESS_LEVEL'))  { define('MPQMS_BFDA_ACCESS_LEVEL', 7); }
if(!defined('MPQMS_BFDA_USER_CATEGORY')) { define('MPQMS_BFDA_USER_CATEGORY', 99); }
if(!defined('MPQMS_BFDA_UNIT_ACCESS'))   { define('MPQMS_BFDA_UNIT_ACCESS', 1); }

if(!function_exists('mpqms_bfda_user_sql')){
	/** Shared FROM/WHERE for an active BFDA/DRA user with an e-mail address (aliases a,p,l).
	 *  @param string $cdb real name of the shared user database, from mpq_db(). */
	function mpqms_bfda_user_sql($cdb = 'commondb'){
		return " FROM `".$cdb."`.user_system_access_table a
		         JOIN `".$cdb."`.user_profile_table p ON p.username = a.username
		         JOIN `".$cdb."`.login_table l ON l.username = a.username
		        WHERE a.system_id = ".(int)MPQMS_BFDA_SYSTEM_ID."
		          AND a.access_level = ".(int)MPQMS_BFDA_ACCESS_LEVEL."
		          AND a.user_category = ".(int)MPQMS_BFDA_USER_CATEGORY."
		          AND a.unit_access = ".(int)MPQMS_BFDA_UNIT_ACCESS."
		          AND l.account_status = 1
		          AND TRIM(COALESCE(p.email_id,'')) <> '' ";
	}
}

if(!function_exists('mpqms_bfda_fetch_users')){
	/**
	 * Runs one prepared lookup and returns array(username => email) for valid addresses.
	 * @param string $extraWhere  additional condition using aliases a/p/l (may reference ?)
	 * @param string $types       bind types for $params
	 */
	function mpqms_bfda_fetch_users($conn, $extraWhere, $types = '', $params = array()){
		$out = array();
		$sql = "SELECT TRIM(a.username), TRIM(p.email_id)".mpqms_bfda_user_sql(mpq_db($conn, 'commondb')).($extraWhere !== '' ? " AND (".$extraWhere.")" : "")." ORDER BY a.username";
		$st = mysqli_prepare($conn, $sql);
		if(!$st){ return $out; }
		if($types !== ''){ mysqli_stmt_bind_param($st, $types, ...$params); }
		mysqli_stmt_execute($st);
		mysqli_stmt_bind_result($st, $u, $e);
		while(mysqli_stmt_fetch($st)){
			$e = trim((string)$e);
			if($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)){ $out[(string)$u] = $e; }
		}
		mysqli_stmt_close($st);
		return $out;
	}
}

if(!function_exists('mpqms_bfda_recipients')){
	function mpqms_bfda_recipients($conn, $regid){
		$res = array('recipients'=>array(), 'users'=>array(), 'basis'=>'NONE', 'reason'=>'');
		$regid = (int)$regid;
		if($regid <= 0 || !$conn){ $res['reason'] = 'invalid registration / connection'; return $res; }
		try {
			// The sample's registering user and its collecting / sampling centres.
			$st = mysqli_prepare($conn, "SELECT TRIM(entryUser), TRIM(collectID), TRIM(siteID) FROM registrationtable WHERE registrationID = ?");
			if(!$st){ $res['reason'] = 'registration lookup failed'; return $res; }
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $entryUser, $collectID, $siteID);
			$found = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			if(!$found){ $res['reason'] = 'sample not found'; return $res; }
			$entryUser = (string)$entryUser; $collectID = (string)$collectID; $siteID = (string)$siteID;

			$users = array(); $basis = 'NONE';

			// 1. The BFDA/DRA user who registered the sample.
			if($entryUser !== ''){
				$users = mpqms_bfda_fetch_users($conn, "a.username = ?", 's', array($entryUser));
				if($users){ $basis = 'ENTRY_USER'; }
			}
			// 2. BFDA/DRA user(s) at the collecting centre, then at the sampling site.
			if(!$users && $collectID !== ''){
				$users = mpqms_bfda_fetch_users($conn, "p.center_id = ?", 's', array($collectID));
				if($users){ $basis = 'COLLECTING_CENTRE'; }
			}
			if(!$users && $siteID !== ''){
				$users = mpqms_bfda_fetch_users($conn, "p.center_id = ?", 's', array($siteID));
				if($users){ $basis = 'SAMPLING_SITE'; }
			}
			// 3. BFDA/DRA user(s) in the same dzongkhag as the collecting centre (regional office).
			if(!$users && $collectID !== ''){
				$cdb = mpq_db($conn, 'commondb');
				$users = mpqms_bfda_fetch_users($conn,
					"p.center_id IN (SELECT c2.center_id FROM `".$cdb."`.ms_health_center_table c2
					                  JOIN `".$cdb."`.ms_health_center_table c1 ON c1.dzongkhag_code = c2.dzongkhag_code
					                 WHERE c1.center_id = ? AND c1.dzongkhag_code <> '99')", 's', array($collectID));
				if($users){ $basis = 'DZONGKHAG'; }
			}

			// De-duplicate addresses case-insensitively (two accounts may share a mailbox).
			$seen = array();
			foreach($users as $u => $e){
				$k = strtolower($e);
				$res['users'][] = $u;
				if(isset($seen[$k])){ continue; }
				$seen[$k] = true; $res['recipients'][] = $e;
			}
			$res['basis'] = $basis;
			if(!$res['recipients']){
				$res['reason'] = 'no active BFDA/DRA user is linked to this sample (entryUser='.$entryUser.', collectID='.$collectID.', siteID='.$siteID.')';
			}
		} catch(\Throwable $e){
			$res = array('recipients'=>array(), 'users'=>array(), 'basis'=>'NONE', 'reason'=>'resolver error: '.$e->getMessage());
		}
		return $res;
	}
}

