<?php
/**
 * panel-number-functions.php
 * ---------------------------------------------------------------------------
 * Shared Panel No. formatting for Settings -> Microscopy / RDT Panel Assignments.
 *
 * Format:  YY-ROUND-CODE-SERIAL        e.g. 26-1-MM-01
 *   YY      2-digit year (Asia/Thimphu), as before
 *   ROUND   panel_round number of the selected round
 *   CODE    MM = Microscopy slide, SL = RDT slide, PN = RDT panel header
 *   SERIAL  sequential within YY-ROUND-CODE across ALL facilities, 2-digit minimum
 *
 * Dzongkhag / facility codes are not part of the number, so the serial is
 * counted across every panel of that year + round instead of per facility.
 * Existing numbers in the old formats are never rewritten; they simply do not
 * match the new prefix and therefore do not affect the new sequence.
 * ---------------------------------------------------------------------------
 */

if (!function_exists('mnPanelNumberPrefix')) {

/** "26-1-MM-" */
function mnPanelNumberPrefix($roundNo, $code){
    return date('y') . '-' . (int)$roundNo . '-' . $code . '-';
}

/** "26-1-MM-" + 7 -> "26-1-MM-07" */
function mnPanelNumberFormat($prefix, $serial){
    return $prefix . str_pad((string)(int)$serial, 2, '0', STR_PAD_LEFT);
}

/**
 * Highest serial already used under $prefix.
 * $target: 'micro_slide' | 'rdt_slide' | 'rdt_panel' (whitelisted table/column).
 * $excludePanelId: panel being edited, so its own numbers don't inflate the serial.
 */
function mnPanelMaxSerial($conn, $target, $prefix, $excludePanelId = ''){
    $targets = array(
        'micro_slide' => array('panel_slide_informations',     'slide_number'),
        'rdt_slide'   => array('rdt_panel_slide_informations', 'slide_number'),
        'rdt_panel'   => array('rdt_panel_informations',       'panel_number'),
    );
    if (!isset($targets[$target])) { throw new InvalidArgumentException('Unknown panel number target'); }
    list($table, $column) = $targets[$target];

    $sql = "SELECT $column FROM $table WHERE $column LIKE ?";
    $like = addcslashes($prefix, '\\%_') . '%';
    $types = 's';
    $params = array($like);
    if ((string)$excludePanelId !== '' && ctype_digit((string)$excludePanelId)) {
        $sql .= " AND panel_id <> ?";
        $types .= 'i';
        $params[] = (int)$excludePanelId;
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) { throw new RuntimeException('Could not prepare serial query'); }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $max = 0;
    $re  = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
    while ($row = $res->fetch_row()) {
        if (preg_match($re, (string)$row[0], $m) && (int)$m[1] > $max) { $max = (int)$m[1]; }
    }
    $stmt->close();
    return $max;
}

/** Next $count consecutive numbers under $prefix. */
function mnPanelNextNumbers($conn, $target, $prefix, $count, $excludePanelId = ''){
    $max = mnPanelMaxSerial($conn, $target, $prefix, $excludePanelId);
    $out = array();
    for ($i = 1; $i <= $count; $i++) { $out[] = mnPanelNumberFormat($prefix, $max + $i); }
    return $out;
}

}
