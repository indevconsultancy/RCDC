<?php
/*
 * MPQMS — Appellate Laboratory dropdown loader (READ-ONLY).
 *
 *   GET /MPQMS/regtest/load_lab.php?par1=In-country|Ex-country&sel=<optional-stored-id>
 *
 * Returns a <select name="lab" id="lab"> ready to drop into #labdiv. The
 * callers in registration.php use this only for the In-country branch (the
 * Ex-country branch is rendered as a free-text input instead), but the loader
 * still honours `par1` as an allowlisted filter so the behaviour is self-
 * contained. When no `sel` is passed and the inex-country branch is
 * "In-country", NMPTL (National Medical Product Testing Laboratory) is picked
 * as the default selection per the Oct-2026 directive.
 */
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");

	header('Content-Type: text/html; charset=utf-8');

	if(!isset($_GET["par1"])){ echo '<select name="lab" id="lab" class="form-control" required></select>'; return; }

	$par1 = (string)$_GET["par1"];
	if(!in_array($par1, array('In-country','Ex-country'), true)){ $par1 = 'In-country'; }
	$sel  = isset($_GET['sel']) ? (int)$_GET['sel'] : 0;

	$esc = mysqli_real_escape_string($connection, $par1);
	$qry = mysqli_query($connection,
		"SELECT id, name FROM msappellatelabtable WHERE status=1 AND inexcountry='$esc' ORDER BY name ASC");

	// First pass — gather rows + pick an NMPTL fallback when caller passed no `sel`.
	$rows = array();
	$nmptlId = 0;
	while($qry && ($row = mysqli_fetch_row($qry))){
		$rows[] = $row;
		if($par1 === 'In-country' && $nmptlId === 0 && stripos((string)$row[1], 'National Medical Product Testing') === 0){
			$nmptlId = (int)$row[0];
		}
	}
	// If no stored selection, prefer NMPTL for In-country.
	if($sel <= 0 && $par1 === 'In-country' && $nmptlId > 0){ $sel = $nmptlId; }

	echo "<select name='lab' id='lab' class='form-control' required>";
	echo "<option value=''>Select Laboratory</option>";
	foreach($rows as $row){
		$id  = (int)$row[0];
		$lbl = htmlspecialchars((string)$row[1], ENT_QUOTES, 'UTF-8');
		$selAttr = ($id === $sel) ? ' selected' : '';
		echo "<option value='$id'$selAttr>$lbl</option>";
	}
	echo "</select>";
?>
