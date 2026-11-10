<?php
	ob_start();
	error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
	ini_set('display_errors', '0');

	require_once(__DIR__ . "/../config/session.php");
	require_once(__DIR__ . "/../config/auth.php");
	require_once(__DIR__ . "/../config/db-config.php"); 
	date_default_timezone_set('Asia/Thimphu');

	$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
		|| (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
		|| (isset($_POST['format']) && $_POST['format'] === 'json')
		|| isset($_POST['ajax']);

	if ($isAjax) {
		mn_require_rcdc('You are not authorised to modify slide mappings.', 'json');
	} else {
		mn_require_rcdc();
	}

	function send_slides_response(string $status, string $message, string $redirectUrl = '', int $httpCode = 200, bool $isAjax = true): void
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		$redirectUrl = !empty($redirectUrl) ? $redirectUrl : 'mappings.php';
		if ($isAjax) {
			http_response_code($httpCode);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'status'   => $status,
				'success'  => ($status === 'success'),
				'message'  => $message,
				'redirect' => $redirectUrl
			]);
			exit;
		} else {
			$sep = (strpos($redirectUrl, '?') !== false) ? '&' : '?';
			$target = $redirectUrl . $sep . 'status=' . urlencode($status) . '&msg=' . urlencode($message);
			header("Location: " . $target);
			exit;
		}
	}

	$action  = isset($_POST['action']) ? $conn->real_escape_string($_POST['action']) : '';
	$cid     = isset($_POST['cid']) ? $conn->real_escape_string($_POST['cid']) : '';
	$rid     = isset($_POST['rid']) ? $conn->real_escape_string($_POST['rid']) : '';
	$iid     = isset($_POST['iid']) ? $conn->real_escape_string($_POST['iid']) : '';
	$slideno = isset($_POST['slideno']) && is_array($_POST['slideno']) ? $_POST['slideno'] : [];
	$check   = false;
	$null    = NULL;

	$lnk = "mappings.php?id=$cid&rid=$rid&iid=$iid";

	if ($action == 1) {
		$row    = isset($_POST['row']) && is_array($_POST['row']) ? $_POST['row'] : [];
		$column = isset($_POST['column']) && is_array($_POST['column']) ? $_POST['column'] : [];

		$stmt = $conn->prepare("INSERT INTO banking_slide_informations VALUES (?, ?, ?, ?)");

		for ($i = 0; $i < count($slideno); $i++) {
			$slotResult = $conn->query("SELECT slot_id FROM banking_slot_informations WHERE rack_id='$rid' AND column_no IS NULL AND row_no IS NULL AND status='Empty' ORDER BY slot_id ASC LIMIT 1");
			$slotRow = $slotResult ? mysqli_fetch_assoc($slotResult) : null;
			$slot_id = $slotRow['slot_id'] ?? null;

			// If no unassigned slot found, check if a slot already exists for this position in the rack
			if (empty($slot_id) && isset($column[$i]) && isset($row[$i])) {
				$existingSlot = $conn->query("SELECT slot_id FROM banking_slot_informations WHERE rack_id='$rid' AND column_no='$column[$i]' AND row_no='$row[$i]' LIMIT 1");
				if ($existingSlot && ($esRow = mysqli_fetch_assoc($existingSlot))) {
					$slot_id = $esRow['slot_id'] ?? null;
				}
			}

			// Fallback to POST slot_id if provided
			if (empty($slot_id)) {
				$slot_id = $_POST['slot_id'][$i] ?? ($_POST['slotid'][$i] ?? ($_POST['slot_id'] ?? null));
			}

			// Guard check: reject empty/null slot_id before DB execution
			if (empty($slot_id)) {
				if ($stmt) { $stmt->close(); }
				$conn->close();
				send_slides_response('error', 'Invalid slot position: Selected slot does not exist in the bank layout.', $lnk, 400, $isAjax);
			}

			$curSlideNo = isset($slideno[$i]) ? trim((string)$slideno[$i]) : '';

			if ($curSlideNo === "") {
				$status1 = $status2 = "Empty";
			} else {
				$status1 = "Current";
				$status2 = "Filled";
			}

			$conn->query("UPDATE banking_slot_informations SET column_no='$column[$i]', row_no='$row[$i]', status='$status2' WHERE slot_id='$slot_id'");

			$stmt->bind_param("iiss", $null, $slot_id, $curSlideNo, $status1);
			if ($stmt->execute()) {
				$check = true;
			} else {
				$check = false;
			}
		}
		if ($stmt) {
			$stmt->close();
		}
	} else {
		$slideid = isset($_POST['slideid']) && is_array($_POST['slideid']) ? $_POST['slideid'] : [];
		$slotid  = isset($_POST['slotid']) && is_array($_POST['slotid']) ? $_POST['slotid'] : [];

		for ($i = 0; $i < count($slideno); $i++) {
			$curSlotId  = $slotid[$i] ?? null;
			$curSlideId = $slideid[$i] ?? null;
			$curSlideNo = isset($slideno[$i]) ? trim((string)$slideno[$i]) : '';

			if ($curSlideNo === "") {
				$status1 = $status2 = "Empty";
			} else {
				$rdnameid = "status$i";
				$status1 = isset($_POST[$rdnameid]) ? $_POST[$rdnameid] : "Current";
				$status2 = "Filled";
			}

			if (!empty($curSlotId)) {
				$conn->query("UPDATE banking_slot_informations SET status='$status2' WHERE slot_id='$curSlotId'");
			}

			if (!empty($curSlideId)) {
				$conn->query("UPDATE banking_slide_informations SET slide_no='$curSlideNo', status='$status1' WHERE slide_id='$curSlideId'");
			} elseif (!empty($curSlotId) && $curSlideNo !== "") {
				$insSlide = $conn->prepare("INSERT INTO banking_slide_informations (slide_id, slot_id, slide_no, status) VALUES (?, ?, ?, ?)");
				if ($insSlide) {
					$insSlide->bind_param("iiss", $null, $curSlotId, $curSlideNo, $status1);
					$insSlide->execute();
					$insSlide->close();
				}
			}
		}
		$check = true;
	}

	$connError = $conn->error;
	$conn->close();

	if ($check) {
		send_slides_response('success', 'Mapped Successfully!!', $lnk, 200, $isAjax);
	} else {
		send_slides_response('error', 'Mapping Failed!! ' . ($connError ?: ''), $lnk, 500, $isAjax);
	}
