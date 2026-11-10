<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php");

	header('Content-Type: application/json');

	/* Findings are multi-select: `ids` is a comma-separated list and the reply holds
	   one row per finding, in the order asked for, so the caller can compose the
	   Finding and Recommendation text without duplication.
	   The single `id` form is still supported and still answers with the flat
	   {finding, recommendation} shape, so any older caller keeps working. */
	$idsRaw = isset($_GET["ids"]) ? (string)$_GET["ids"] : '';
	$single = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

	$ids = array();
	if ($idsRaw !== '') {
		foreach (explode(',', $idsRaw) as $v) {
			$v = intval(trim($v));
			if ($v > 0 && !in_array($v, $ids, true)) { $ids[] = $v; }   // de-duplicate
		}
	} elseif ($single > 0) {
		$ids[] = $single;
	}

	$rows = array();
	if (count($ids)) {
		// Bound placeholders only - the ids are already cast to int above.
		$place = implode(',', array_fill(0, count($ids), '?'));
		$types = str_repeat('i', count($ids));
		$stmt  = $conn->prepare("SELECT finding_id, title, category, finding_content, recommendation_content
		                         FROM blinded_finding_master
		                         WHERE finding_id IN ($place) AND status = 1");
		$stmt->bind_param($types, ...$ids);
		$stmt->execute();
		$res = $stmt->get_result();

		$byId = array();
		while ($r = $res->fetch_assoc()) { $byId[(int)$r['finding_id']] = $r; }
		$stmt->close();

		// Preserve the order the caller listed them in.
		foreach ($ids as $fid) {
			if (!isset($byId[$fid])) { continue; }
			$rows[] = array(
				"finding_id"     => $fid,
				"title"          => $byId[$fid]['title'],
				// the editors head each block with the CATEGORY, not the finding title
				"category"       => $byId[$fid]['category'],
				"finding"        => ($byId[$fid]['finding_content'] !== null ? $byId[$fid]['finding_content'] : ''),
				"recommendation" => ($byId[$fid]['recommendation_content'] !== null ? $byId[$fid]['recommendation_content'] : ''),
			);
		}
	}

	$data = array(
		"rows" => $rows,
		// Legacy flat keys: the first (or only) match.
		"finding"        => count($rows) ? $rows[0]["finding"] : "",
		"recommendation" => count($rows) ? $rows[0]["recommendation"] : "",
	);

	echo json_encode($data);
	$conn->close();
?>
