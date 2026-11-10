<?php
require_once("config/session.php");
require_once("config/commondb-config.php");

$rawDz = trim((string)($_REQUEST["dzongkhag"] ?? ""));
$dzCode = "";
$isAll = (strtolower($rawDz) === 'all');

$elemId = (!empty($_REQUEST['id']) && preg_match('/^[a-zA-Z0-9_\-]+$/', $_REQUEST['id']))
    ? (string)$_REQUEST['id']
    : 'facility';
$elemName = (!empty($_REQUEST['name']) && preg_match('/^[a-zA-Z0-9_\[\]\-]+$/', $_REQUEST['name']))
    ? (string)$_REQUEST['name']
    : 'facility';
$customClass = (!empty($_REQUEST['class']) && preg_match('/^[a-zA-Z0-9_\-\s]+$/', $_REQUEST['class']))
    ? (string)$_REQUEST['class']
    : 'form-control';
$placeholder = (!empty($_REQUEST['placeholder']))
    ? htmlspecialchars($_REQUEST['placeholder'], ENT_QUOTES, 'UTF-8')
    : 'Select Facility';
$selectedVal = isset($_REQUEST['selected']) ? (string)$_REQUEST['selected'] : '';
$optionsOnly = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'options') || !empty($_REQUEST['options_only']);
$isJson = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'json') ||
          (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
$loadAll = !empty($_REQUEST['all']);

if ($rawDz !== "" && !$isAll) {
    $safeDz = $commondb->real_escape_string($rawDz);
    $paddedDz = (is_numeric($rawDz) && strlen($rawDz) < 2) ? str_pad($rawDz, 2, '0', STR_PAD_LEFT) : $rawDz;
    $safePaddedDz = $commondb->real_escape_string($paddedDz);

    // Look up dzongkhag_code from ms_dzongkhag_table by code, padded code, name, or short code
    $dzLookup = $commondb->query(
        "SELECT dzongkhag_code FROM ms_dzongkhag_table 
         WHERE dzongkhag_code = '$safeDz' 
            OR dzongkhag_code = '$safePaddedDz' 
            OR LOWER(dzongkhag_name) = LOWER('$safeDz') 
            OR LOWER(dzongkhag_name_short) = LOWER('$safeDz') 
         LIMIT 1"
    );
    if ($dzLookup && ($dzRow = $dzLookup->fetch_row())) {
        $dzCode = $dzRow[0];
    } else {
        $dzCode = $safePaddedDz;
    }
}

$result = null;

$roleFilter = "";
if (!mn_is_rcdc()) {
    $facId = $commondb->real_escape_string((string)mn_facility());
    $roleFilter = " AND center_id = '$facId'";
}

if ($rawDz !== "" && !$isAll) {
    $whereDz = ($dzCode !== "") ? "dzongkhag_code = '$dzCode'" : "1=1";

    if ($loadAll) {
        $sql = "SELECT center_id, center_name FROM ms_health_center_table WHERE $whereDz $roleFilter ORDER BY center_name ASC";
        $result = $commondb->query($sql);
    } else {
        // Try with mneqas = 1 (staging/production standard)
        $sql = "SELECT center_id, center_name FROM ms_health_center_table WHERE $whereDz AND mneqas = 1 $roleFilter ORDER BY center_name ASC";
        $result = $commondb->query($sql);
        // Fallback 1: if WHERE dzongkhag_code = ? AND mneqas = 1 returns 0 rows, fallback to WHERE dzongkhag_code = ?
        if (!$result || $result->num_rows === 0) {
            $sqlFallback = "SELECT center_id, center_name FROM ms_health_center_table WHERE $whereDz $roleFilter ORDER BY center_name ASC";
            $result = $commondb->query($sqlFallback);
        }
        // Fallback 2: if still 0 rows (e.g. unknown dzongkhag code in local DB), return all facilities
        if (!$result || $result->num_rows === 0) {
            $sqlAll = "SELECT center_id, center_name FROM ms_health_center_table WHERE 1=1 $roleFilter ORDER BY center_name ASC";
            $result = $commondb->query($sqlAll);
        }
    }
} else {
    // No dzongkhag selected, or 'all' → return all facilities
    if ($loadAll) {
        $sql = "SELECT center_id, center_name FROM ms_health_center_table WHERE 1=1 $roleFilter ORDER BY center_name ASC";
        $result = $commondb->query($sql);
    } else {
        $sql = "SELECT center_id, center_name FROM ms_health_center_table WHERE mneqas = 1 $roleFilter ORDER BY center_name ASC";
        $result = $commondb->query($sql);
        if (!$result || $result->num_rows === 0) {
            $sql = "SELECT center_id, center_name FROM ms_health_center_table WHERE 1=1 $roleFilter ORDER BY center_name ASC";
            $result = $commondb->query($sql);
        }
    }
}

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    $facilities = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $facilities[] = [
                'id' => $row['center_id'],
                'text' => $row['center_name'],
                'center_id' => $row['center_id'],
                'center_name' => $row['center_name']
            ];
        }
    }
    echo json_encode(['success' => true, 'facilities' => $facilities]);
    exit;
}

if ($optionsOnly) {
    echo "<option value=''>$placeholder</option>";
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $sel = ($selectedVal !== '' && (string)$row['center_id'] === (string)$selectedVal) ? ' selected' : '';
            $centerId = htmlspecialchars($row['center_id'], ENT_QUOTES, 'UTF-8');
            $centerName = htmlspecialchars($row['center_name'], ENT_QUOTES, 'UTF-8');
            echo "<option value='$centerId'$sel>$centerName</option>";
        }
    }
    exit;
}

echo "<select name='$elemName' id='$elemId' class='$customClass'><option value=''>$placeholder</option>";
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sel = ($selectedVal !== '' && (string)$row['center_id'] === (string)$selectedVal) ? ' selected' : '';
        $centerId = htmlspecialchars($row['center_id'], ENT_QUOTES, 'UTF-8');
        $centerName = htmlspecialchars($row['center_name'], ENT_QUOTES, 'UTF-8');
        echo "<option value='$centerId'$sel>$centerName</option>";
    }
}
echo "</select>";
?>
<script>
    if (typeof $ !== 'undefined' && $('#sampleDiv').length) {
        $('#<?= $elemId ?>').off('change.sampleDiv').on('change.sampleDiv', function(){
            var fac = $(this).val();
            var sampleUrl = (window.location.pathname.indexOf('/molecular/') !== -1)
                ? 'load-shipment-sample.php?facility=' + encodeURIComponent(fac)
                : '../molecular/load-shipment-sample.php?facility=' + encodeURIComponent(fac);
            $('#sampleDiv').load(sampleUrl);
        });
    }
</script>
