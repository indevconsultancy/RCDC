<?php
/*
 * MPQMS — Sample Type dropdown loader (READ-ONLY).
 *
 *   GET /MPQMS/regtest/load_sample_type.php?sel=<optional-currently-stored-value>
 *
 * Returns a <select id="sample_type"> ready to drop into #stdiv. Options are
 * read from mssampletypetable (status=1 AND is_legacy=0) so the admin can edit
 * them without touching PHP. If the table does not exist yet (migration not
 * run), the loader falls back to the hard-coded trio aligned with Test Category
 * (Complain | Surveillance | Registration) so the form keeps working either way.
 *
 * The caller passes `sel=<stored_value>` in edit mode so a value saved under a
 * now-retired/legacy row (e.g. "Routine Compliance") still appears pre-selected,
 * preventing silent data loss when the record is saved back.
 */
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");

	header('Content-Type: text/html; charset=utf-8');

	$sel = isset($_GET['sel']) ? trim((string)$_GET['sel']) : '';

	$options = array();
	try {
		$rs = @mysqli_query($connection,
			"SELECT name FROM mssampletypetable WHERE status=1 AND is_legacy=0 ORDER BY sort_order ASC, name ASC");
		if($rs){ while($row = mysqli_fetch_row($rs)){ $options[] = (string)$row[0]; } }
	} catch(\Throwable $e){ /* fall through to defaults */ }

	// Fallback — table not created yet. Mirrors the required Test-Category alignment.
	if(empty($options)){ $options = array('Complain','Surveillance','Registration'); }

	// Pre-selected value may be a now-legacy string ("Routine Compliance"); surface it as an
	// extra option on top so edit mode never loses the stored value.
	$needsLegacy = ($sel !== '' && !in_array($sel, $options, true));

	echo "<select name='sample_type' id='sample_type' class='form-control' required>";
	echo "<option value=''>Select Sample Type</option>";
	if($needsLegacy){
		echo "<option value='".htmlspecialchars($sel, ENT_QUOTES, 'UTF-8')."' selected>".
			 htmlspecialchars($sel, ENT_QUOTES, 'UTF-8')." (legacy)</option>";
	}
	foreach($options as $opt){
		$selAttr = ($opt === $sel) ? ' selected' : '';
		echo "<option value='".htmlspecialchars($opt, ENT_QUOTES, 'UTF-8')."'".$selAttr.">".
			 htmlspecialchars($opt, ENT_QUOTES, 'UTF-8')."</option>";
	}
	echo "</select>";
?>
