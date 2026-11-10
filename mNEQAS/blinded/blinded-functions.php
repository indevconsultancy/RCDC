<?php
function showMonth($month){
    switch ($month) {
        case 1:
            $mnt = 'January';
            break;
        case 2:
            $mnt = 'February';
            break;
        case 3:
            $mnt = 'March';
            break;
        case 4:
            $mnt = 'April';
            break;
        case 5:
            $mnt = 'May';
            break;
        case 6:
            $mnt = 'June';
            break;
        case 7:
            $mnt = 'July';
            break;
        case 8:
            $mnt = 'August';
            break;
        case 9:
            $mnt = 'September';
            break;
        case 10:
            $mnt = 'October';
            break;
        case 11:
            $mnt = 'November';
            break;
        case 12:
            $mnt = 'December';
            break;
        default:
            $mnt = 'Select Month';
            break;
    }
    return $mnt;
}

function microscopyResults(){
    return "
    <option value ='Nmps'>Nmps</option>
    <option value ='Pf'>Pf</option>
    <option value ='Pv'>Pv</option>
    <option value ='F+V'>F+V</option>
    <option value ='Po'>Po</option>
    <option value ='Pm'>Pm</option>
    <option value ='Pk'>Pk</option>
    ";
}

function parasiteStages(){
    return "
    <option value ='T'>T</option>
    <option value ='S'>S</option>
    <option value ='G'>G</option>
    <option value ='TS'>TS</option>
    <option value ='TG'>TG</option>
    <option value ='TSG'>TSG</option>
    ";
}

function slideStatus(){
    return "
    <option value='Intact'>Intact</option>
    <option value='Broken'>Broken</option>
    <option value='Missing'>Missing</option>";
}

function acceptableResults(){
    return "
    <option value='Acceptable'>Acceptable</option>
    <option value='NotAcceptable'>NotAcceptable</option>";
}

/**
 * First <option> for a "saved value OR placeholder" select.
 * When a value has already been saved it is rendered as the pre-selected first
 * option, preserving the EXACT stored value so an existing selection is never
 * overwritten. When nothing is saved yet it renders a meaningful placeholder
 * label instead of a blank option. Purely presentational — the value that gets
 * submitted is identical to before; only the label shown for the empty state
 * changes (blank -> descriptive placeholder).
 */
if (!function_exists('blSavedOption')) {
    function blSavedOption($val, $placeholder = 'Select'){
        $raw = ($val === null) ? '' : (string)$val;
        if (trim($raw) !== '') {
            $safe = htmlspecialchars($raw, ENT_QUOTES);
            return "<option value='$safe'>$safe</option>";
        }
        return "<option value=''>".htmlspecialchars($placeholder, ENT_QUOTES)."</option>";
    }
}


function lastday($month = '', $year = '') {
    if (empty($month)) {
       $month = date('m');
    }
    if (empty($year)) {
       $year = date('Y');
    }
    $result = strtotime("{$year}-{$month}-01");
    $result = strtotime('-1 second', strtotime('+1 month', $result));
    return date('Y-m-d', $result);
 }

 function fifteeenthOfNext($month = '', $year = '')
 {
    if (empty($month)) {
       $month = date('m');
    }else $month=$month+1;

    if (empty($year)) {
       $year = date('Y');
    }

    // Roll into the next year only AFTER December (month 12 -> next year's Jan),
    // using the RECORD's year. The previous test (`$month == 12`, i.e. a November
    // report after the +1, and `date('Y')+1` = the current calendar year) mis-set
    // the deadline for November records so they were almost always scored ONTIME.
    if($month > 12) { $month = 1; $year = $year + 1; }

    $result = strtotime("{$year}-{$month}-15");
    return date('Y-m-d', $result);
 }

/* =====================================================================
   Shared numeric/density helpers.
   Moved here verbatim from iu-blinded.php so the Controller 1 entry form
   and the Excel importer compute density identically.
   ===================================================================== */

// Coerce a posted numeric field to a safe SQL literal for an UNQUOTED numeric
// column: a plain number, or the literal NULL when blank / non-numeric. This
// prevents (a) PHP 8 "Unsupported operand types" fatals in the density math
// and (b) SQL syntax errors from an empty value landing in a numeric column.
if (!function_exists('sqlNum')) {
    function sqlNum($v){
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null || !is_numeric($v)) ? 'NULL' : (string)(0 + $v);
    }
}
// Parasite density = parasite_count / wbc_count * 8000, guarded so an empty or
// zero WBC never throws — returns the numeric density, or 'NULL' when it cannot
// be computed. Business rule (the formula) is unchanged for valid inputs.
if (!function_exists('calcDensity')) {
    function calcDensity($pcount, $wbcount){
        $wb = (float)$wbcount;
        if ($wb == 0 || !is_numeric(trim((string)$wbcount)) || !is_numeric(trim((string)$pcount))) {
            return 'NULL';
        }
        return (string)round((float)$pcount / $wb * 8000);
    }
}


/**
 * Calculates parasite density and acceptable WHO density range (+/- 25%)
 * given parasite count, WBC count, or stored density and ranges.
 */
if (!function_exists("blCalculateDensityAndRange")) {
    function blCalculateDensityAndRange($pCount, $wCount, $storedDensity = null, $storedMin = null, $storedMax = null) {
        $density = null;
        if ($storedDensity !== null && $storedDensity !== "" && (int)$storedDensity > 0) {
            $density = (int)$storedDensity;
        } elseif ($pCount !== null && $pCount !== "" && $wCount !== null && $wCount !== "" && (float)$wCount > 0) {
            $density = (int)round(((float)$pCount / (float)$wCount) * 8000);
        }

        $minRange = ($storedMin !== null && $storedMin !== "") ? (int)$storedMin : null;
        $maxRange = ($storedMax !== null && $storedMax !== "") ? (int)$storedMax : null;

        if (($minRange === null || $maxRange === null) && $density !== null && $density > 0) {
            $minRange = (int)round($density * 0.75);
            $maxRange = (int)round($density * 1.25);
        }

        $rangeText = ($minRange !== null && $maxRange !== null) ? ($minRange . " - " . $maxRange) : "-";
        $densityText = ($density !== null && $density > 0) ? (string)$density : "-";

        return array(
            "density"     => $density,
            "min_range"   => $minRange,
            "max_range"   => $maxRange,
            "density_txt" => $densityText,
            "range_txt"   => $rangeText
        );
    }
}

/**
 * Calculates Controller 2 stepper badge state and completion status.
 * Strictly gates Controller 2 by Controller 1's full completion.
 *
 * @param int|mysqli $arg1 Total intact slides OR mysqli connection
 * @param int        $arg2 Count of C1 completed slides OR blinded_id
 * @param int        $arg3 Count of C2 completed slides
 * @return array
 */
if (!function_exists('blDeriveC2StepperStatus')) {
    function blDeriveC2StepperStatus($arg1, $arg2 = 0, $arg3 = 0) {
        if ($arg1 instanceof mysqli) {
            $conn = $arg1;
            $id = (int)$arg2;
            $intactQuery = $conn->query("SELECT result_id, c1_microscopy, c2_microscopy FROM blinded_results WHERE slide_status = 'Intact' AND blinded_id = $id");
            $intactCnt = 0;
            $c1Cnt = 0;
            $c2Cnt = 0;
            if ($intactQuery) {
                while ($r = $intactQuery->fetch_assoc()) {
                    $intactCnt++;
                    if ($r['c1_microscopy'] !== null && trim((string)$r['c1_microscopy']) !== '') {
                        $c1Cnt++;
                    }
                    if ($r['c2_microscopy'] !== null && trim((string)$r['c2_microscopy']) !== '') {
                        $c2Cnt++;
                    }
                }
            }
        } else {
            $intactCnt = (int)$arg1;
            $c1Cnt     = (int)$arg2;
            $c2Cnt     = (int)$arg3;
        }

        $c1Done = ($intactCnt > 0 && $c1Cnt === $intactCnt);
        $c2Done = ($c1Done && $intactCnt > 0 && $c2Cnt === $intactCnt);

        if ($c2Done) {
            $status     = 'Completed';
            $badgeClass = 'done';
            $badgeIcon  = 'fa-check';
        } elseif ($c1Done && $c2Cnt > 0) {
            $status     = 'In Progress';
            $badgeClass = 'active';
            $badgeIcon  = 'fa-hourglass-half';
        } else {
            $status     = 'Pending';
            $badgeClass = 'pending';
            $badgeIcon  = '5';
        }

        return array(
            'intactCnt'  => $intactCnt,
            'c1Cnt'      => $c1Cnt,
            'c2Cnt'      => $c2Cnt,
            'c1Done'     => $c1Done,
            'c2Done'     => $c2Done,
            'status'     => $status,
            'badgeClass' => $badgeClass,
            'badgeIcon'  => $badgeIcon
        );
    }
}


?>