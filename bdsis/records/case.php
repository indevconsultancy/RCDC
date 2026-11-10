<?php /* Buffer the page. lab.php and case.php cannot authorise the record
         until the database connection and the requested subject id are known,
         which is after the layout has started rendering. Buffering lets
         bdsis_deny() discard the part-built page and return a true 403
         instead of a 200 carrying a denial message. */
   ob_start(); ?>
<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php");
    include_once("../check-record.php");
    require_once("../includes/rbac.php");
    // bdsis_processed_by_label(): renders a stored personnel value, resolving a
    // historical username to the staff member's full name and passing free text
    // through unchanged. Used by the four free-text sample personnel fields.
    require_once("../includes/lab-helpers.php");
    bdsis_require_module();   // refuse roles with no BDSIS record access
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <!-- Top Navbar -->
  <?php require_once("../includes/top-navbar.php"); ?>
  <!-- Main Sidebar Container -->
  <?php require_once("../includes/sidebar-menu.php"); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Case management</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="record-management.php">Records management</a></li>
                        <li class="breadcrumb-item active">Case Management</li>
                    </ol>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>

    <!-- Data and page settings -->
<?php
    $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
    /* Health Facility list for New Case. The label carries the dzongkhag because
       several facilities are not named after their district -- Bumthang's is
       "Wangdicholing Hospital", Thimphu's is "JDWNRH" -- so a user looking for a
       district could not find it by name alone. Ordered by dzongkhag for the same
       reason. Only the label changes; the option value is still center_id, so
       what gets saved is unaffected. */
    $bdsis_Site= "SELECT h.center_id, CONCAT(h.center_name,' (',d.dzongkhag_name,')')
                    FROM ms_health_center_table h
                    JOIN ms_dzongkhag_table d ON d.dzongkhag_code = h.dzongkhag_code
                   WHERE h.dsis=1
                   ORDER BY d.dzongkhag_name ASC";

    $y=date("y");
    if($acl==3 && $usercat==3){
        $ctid=$_SESSION["ctid"];
        $dzdqry = ($commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$ctid' )"));
        list($dzongkhag_code, $dzongkhag_name) = $dzdqry->fetch_row();

        $facilityqry=$commondb->query("SELECT center_code,center_name FROM ms_health_center_table WHERE center_id='".$ctid."'");
        list($facilityId,$center_name) = $facilityqry->fetch_row();
    }

    $action = isset($_GET["action"]) ? $_GET["action"] : 1;
    $caseError = '';                 // set when the requested case cannot be loaded

    /* Registering a new case is the 'add' capability. Guarded on page load, not
       only at the save endpoint, so a read-only role (DHO, MoH, BFDA) cannot even
       open the blank wizard -- the form is not offered, matching the hidden
       "New Case" button on the listing. The save endpoint still re-checks. */
    if($action != 2){ bdsis_require_can('add', null, 'records'); }

    /* CID No. auto-migration (2026-10-08): run the ALTER on first page load
       for BOTH new-case (action==1) and edit (action==2) paths so the field
       renders in Step 1 regardless of whether phpMyAdmin was ever touched.
       bdsis_subject_cid_no_column_ensure() is idempotent (no-op when the
       column is already at VARCHAR(50)) and silently falls back to false
       on an ALTER failure, so the UI stays hidden rather than throwing. */
    if (function_exists('bdsis_subject_cid_no_column_ensure')) {
        bdsis_subject_cid_no_column_ensure($bdsis);
    }

    if($action == 2){
        $id = isset($_GET["sid"]) ? $_GET["sid"] : '';

        /* Authorise the RECORD, not just the session. Checked against the subject's
           own centre, which is what stops a facility user opening another hospital's
           patient by editing the subject id in the URL. Runs before the record is
           read so nothing leaks on refusal. */
        if($id !== ''){ bdsis_require_subject($bdsis, $id); bdsis_require_can('edit', null, 'records'); }

        $subjects = null;
        /* CID No. (2026-10-07): optional alphanumeric field read in its
           own small query so the main $subjects positional indexes are
           untouched. The auto-migration already ran at the top of this
           file; the probe below gates the UI so a database that doesn't
           have the column (ensure() failed) still renders cleanly. */
        $subjectCidNo = '';
        if($id !== ''){
            /* Columns 13-18 are the Case Investigation Form additions (reg_number, dob,
               residence_type, guardian_name, notified_by, notification_date); appended
               so every existing $subjects[n] index keeps its meaning. */
            $subjectqry=$bdsis->query("SELECT center_id, subject_type, hospital_visit_date, subject_name, gender, age, residental_address, dzongkhag_code, occupation_code, contact_no, subject_category, recorded_by, recorded_on, reg_number, dob, residence_type, guardian_name, notified_by, notification_date FROM subject_information_table WHERE subject_id='".$bdsis->real_escape_string($id)."'");
            if($subjectqry && $subjectqry->num_rows > 0){ $subjects = $subjectqry->fetch_row(); }
            if(function_exists('bdsis_subject_cid_no_column_exists') && bdsis_subject_cid_no_column_exists($bdsis)){
                $cidQ = $bdsis->query("SELECT cid_no FROM subject_information_table WHERE subject_id='".$bdsis->real_escape_string($id)."'");
                if($cidQ && $cidQ->num_rows > 0){
                    $cidRow = $cidQ->fetch_row();
                    $subjectCidNo = isset($cidRow[0]) ? (string)$cidRow[0] : '';
                }
                if($cidQ){ $cidQ->free(); }
            }
        }

        /* No such case. Every block below indexes $subjects, so without this the
           page emitted a screenful of "array offset on value of type null"
           warnings and then rendered a blank, unusable form. The usual way to get
           here is a save that did not happen: iu-case.php used to redirect to this
           edit URL even when the INSERT failed, so the browser was asked to edit a
           record that was never created. */
        if($subjects === null){
            $caseError = ($id === '')
                ? 'No case was specified.'
                : 'Case "'.htmlspecialchars($id, ENT_QUOTES).'" could not be found. It may not have been saved, or it may since have been deleted.';
        }

        if($caseError === ''){
            $districtqry = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code='".$commondb->real_escape_string($subjects[7])."'");
            $dzongkhag = ($districtqry && $districtqry->num_rows) ? $districtqry->fetch_row() : array('','');

            /* Labelled the same way as $bdsis_Site above, so the pre-selected option
               on an existing case matches its entry in the list rather than looking
               like a separate facility. */
            $facilityqry=$commondb->query("SELECT CONCAT(h.center_name,' (',d.dzongkhag_name,')')
                                             FROM ms_health_center_table h
                                             JOIN ms_dzongkhag_table d ON d.dzongkhag_code = h.dzongkhag_code
                                            WHERE h.center_id='".$commondb->real_escape_string($subjects[0])."'");
            $facility = ($facilityqry && $facilityqry->num_rows) ? $facilityqry->fetch_row() : array('');

            $occupationqry = $commondb->query("SELECT value FROM ms_occupation_table WHERE id='".$commondb->real_escape_string($subjects[8])."'");
            $occupation = ($occupationqry && $occupationqry->num_rows) ? $occupationqry->fetch_row() : array('');

            /* A subject can exist with no clinical row: Step 1 wrote the two tables
               in one && condition, which short-circuits, so a failed clinical INSERT
               still left the subject committed. Fall back to blanks so the form
               renders empty and can be completed rather than warning on every
               field. (iu-case.php now writes both inside a transaction.) */
            /* Columns 9-28 are the Case Investigation Form additions, appended so the
               existing $epiinfo[0..8] indexes are untouched:
                 9 admission_date 10 hospital_name 11 discharge_death_date
                12 max_diarrhea_episodes 13 dehydration_degree 14 sym_watery_loose
                15 sym_bloody 16 sym_fever 17 sym_abdominal_pain 18 diarrhea_acute
                19 diarrhea_persistent 20 diarrhea_chronic 21 vomiting
                22 max_vomiting_episodes 23 rehydration 24 rota_vaccine 25 vaccine_type
                26 vaccine_last_date 27 vaccine_info_source 28 outcome */
            $epiqry = $bdsis->query("SELECT clinic_visit_status, date_illness_onset, stool_charateristices, diarrhea_duration, food_suspect_status, food_suspect_name, travel_history_status, travel_place, travel_date,
                                            admission_date, hospital_name, discharge_death_date, max_diarrhea_episodes, dehydration_degree, sym_watery_loose, sym_bloody, sym_fever, sym_abdominal_pain, diarrhea_acute, diarrhea_persistent, diarrhea_chronic, vomiting, max_vomiting_episodes, rehydration, rota_vaccine, vaccine_type, vaccine_last_date, vaccine_info_source, outcome
                                     FROM case_clinical_information_table WHERE subject_id='".$bdsis->real_escape_string($id)."'");
            $epiinfo = ($epiqry && $epiqry->num_rows) ? $epiqry->fetch_row() : array_fill(0, 29, '');

            if($subjects[10]=="1"){$chk_sur="Checked";$chk_outb="";} else{$chk_sur="";$chk_outb="Checked";}
            if($epiinfo[0]=="OPD"){$chkopd="Checked";$chkipd="";} else{$chkopd="";$chkipd="Checked";}
            if($epiinfo[6]=="Yes"){$thsyes="Checked";$thsno="";$thsunk="";$tpsts="";$dotsts="";} else if($epiinfo[6]=="No"){$thsyes="";$thsno="Checked"; $thsunk="";$tpsts="disabled";$dotsts="disabled";} else{$thsyes="";$thsno=""; $thsunk="Checked";$tpsts="disabled";$dotsts="disabled";}
            /* Food consumed. The "No" branch used to test $epiinfo[5] -- food_suspect_NAME --
               instead of $epiinfo[4], the status. A name never equals "No", so every stored
               "No" fell through to the else, which checked BOTH the No and Unknown radios;
               the browser honours the last one, so 3,416 cases displayed as "Unknown" and
               silently re-saved as Unk. Index corrected and the else now checks Unknown only. */
            if($epiinfo[4]=="Yes"){$fssyes="Checked";$fssno="";$fssunk="";$fsnsts="";}
            else if($epiinfo[4]=="No"){$fssyes="";$fssno="Checked";$fssunk="";$fsnsts="disabled";}
            else{$fssyes="";$fssno="";$fssunk="Checked";$fsnsts="disabled";}
        switch ($epiinfo[2]) { 
            case "Bloody": 
                $chkbloody = "Checked";
                $chkloose = "";
                $chkwatery = "";
            break;
            case "Loose": 
                $chkloose = "Checked";
                $chkbloody = "";
                $chkwatery = "";
            break;
            case "Watery": 
                $chkwatery = "Checked";
                $chkloose = "";
                $chkbloody = "";
            break;
            default:
                $chkwatery = "";
                $chkloose = "";
                $chkbloody = "";
        }

        $readonly='readonly';
        $disabled='disabled';
        $gender = array($subjects[4],$subjects[4]);
        $occupationOption=array($subjects[8],$occupation[0]);
        $occupationOption=array($subjects[8],$occupation[0]);
        $dzongkhagOption=array($subjects[7],$dzongkhag[1]);
        // $getSID ='';

        $cancel = "case.php?action=2&sid=$id";
        //Enable Delete Button if there is no associated lab record, bacteriology result, Elisa Result, shipment, microscopy result)
        if (bdsis_can('delete', 'records') && chkMicroscopy($id, $bdsis)==0 AND chkShipment($id, $bdsis)==0 AND  chkBacteriology($id, $bdsis)==0 AND chkElisa($id, $bdsis)==0) {
            $deletepi = "<a href='delete.php?type=caseInfo&id=$id&siid=$id' class='btn bg-gradient-danger mx-5' onclick='return confirmDelete()'>Delete</a>"; 
        }else{$deletepi = "";}
        $savepi = "Update";
        } else {
            /* Case not found. Still define everything the markup touches, so the
               page can render its error panel without a second wave of
               undefined-variable notices on the way down. */
            $readonly = $disabled = '';
            $chkopd=$chkipd=$chk_sur=$chk_outb=$chkwatery=$chkbloody=$chkloose=$fssyes=$fssno=$fssunk=$thsyes=$thsno=$thsunk='';
            $dotsts=$tpsts=$fsnsts="disabled";
            $subjects   = array_fill(0, 19, '');
            $epiinfo    = array_fill(0, 29, '');
            $dzongkhag  = array('', '');
            $facility   = array('');
            $occupation = array('');
            $gender = $occupationOption = $dzongkhagOption = array('', '');
            $deletepi = ''; $savepi = 'Update';
            $cancel = "record-management.php";
        }
    }
    else{
        $id = '';
        // $getSID = 'onchange="getSID(this.value)"';
        $cancel = "case.php?action=1";
        $chkopd=$chkipd=$chk_sur=$chk_outb=$chkwatery=$chkbloody=$chkloose=$fssyes=$fssno=$fssunk=$thsyes=$thsno=$thsunk= $readonly=$disabled='';
        $dotsts=$tpsts=$fsnsts="disabled";
        $deletepi = "";
        $subjects = array_fill(0, 19, '');   // 13 original columns + 6 Case Investigation Form fields
        $epiinfo = array_fill(0, 29, '');    // 9 original columns + 20 Case Investigation Form fields
        $gender = array('','Select Gender');
        $occupationOption = array('','Select Occupation');
        $dzongkhagOption = array('','Select Dzongkhag');
        $facility = array('Select Facility');
        /* Preview of the id the server will allocate. Shown whenever the centre is
           already settled -- i.e. the user has exactly one permitted centre -- which
           is true for a facility user and for a district officer whose dzongkhag has
           a single participating hospital. Anyone choosing from a list gets it from
           the select's onchange handler instead. Previously this was keyed to
           acl==3&&usercat==3, so every other role saw a blank Subject ID even once a
           facility had been picked. */
        $subjectid = "";
        $previewCentres = bdsis_case_centres($commondb);
        if (count($previewCentres) === 1) {
            $previewId = bdsis_next_subject_id($bdsis, $commondb, (string)key($previewCentres));
            if ($previewId !== null) { $subjectid = $previewId; }
        }
        $savepi = "Save";
    }

    /* ---------- Wizard step model (server-side; one form per step) ----------
       Order (client request): Case Details -> Microscopy Result -> Sample
       Shipment -> Preview. The Sample Shipment and Microscopy Result step
       blocks lower in this file still sit in their original file positions --
       only their $step == N guards were swapped -- so a diff-hostile physical
       move is avoided. iu-case.php's nextStep routing was updated to match. */
    $isEdit  = ($action == 2);
    $wsteps  = array(1 => 'Case Details', 2 => 'Microscopy Result', 3 => 'Sample Shipment', 4 => 'Preview');
    $wtotal  = count($wsteps);
    $step    = isset($_GET['step']) ? (int)$_GET['step'] : 1;
    if($step < 1 || $step > $wtotal) $step = 1;
    if(!$isEdit) $step = 1;   // a new case starts at Case Details; later steps need a saved record

    /* ---------- Staff dropdown sources ----------
       NOTE: the four sample personnel fields ("Sample Collected by", "Sample
       Shipped to RCDC by", "Sample Received By" and "Sample Processed by") are
       now free-text inputs, so none of them reads these lists any more. The
       queries and bdsis_staff_options() below are kept because they are still
       the definition of who is eligible, and dropping them is outside the scope
       of that change.

       Historically: "Sample Collected by" and "Sample Processed by" listed the
       BDSIS users at the case's own facility; "Sample Shipped to RCDC by" listed
       the RCDC lab staff.

       Defined once here, at page level, because they are needed by BOTH step 2
       and step 3. They used to be declared inside the step-2 block only, so on
       step 3 $facilityUser was undefined and $commondb->query(null) raised a
       fatal ValueError -- which is why the Microscopy step's "Sample Processed
       by" dropdown came up broken.

       $ctid falls back to the session centre so the lists are never built from an
       empty centre id (which silently returned zero rows). */
    $ctid = (isset($subjects[0]) && $subjects[0] !== '')
              ? $subjects[0]
              : (isset($_SESSION["ctid"]) ? $_SESSION["ctid"] : '');
    $ctidEsc = $commondb->real_escape_string($ctid);

    $facilityUser = "SELECT up.username, up.fullname
                       FROM user_profile_table up
                       JOIN user_system_access_table usa ON usa.username = up.username
                      WHERE up.center_id = '".$ctidEsc."'
                        AND usa.system_id = 6
                      ORDER BY up.fullname ASC";

    $eidlqry = "SELECT up.username, up.fullname
                  FROM user_profile_table up
                 WHERE up.center_id = '997'
                   AND up.username IN (SELECT usa.username FROM user_system_access_table usa
                                        WHERE usa.access_level = 2 AND usa.user_category = 13)
                 ORDER BY up.fullname ASC";
    if(!function_exists('bdsis_staff_options')){
        /**
         * Options for a staff picker, from one of the two page-level staff
         * queries above. WHO is eligible, and what is stored, are unchanged --
         * this only builds the <option> list correctly.
         *
         * It replaces the old "saved value as a raw first option, then the whole
         * list" pattern, which had three faults:
         *   - the saved person appeared TWICE when they were also in the list;
         *   - the first option rendered with empty text when the saved username
         *     no longer resolved to a profile, so the field looked blank;
         *   - there was no placeholder once a value was saved, and no way to
         *     tell an empty list from a working one.
         *
         * The saved value always round-trips: someone who has since moved
         * facility or lost their account is still offered (marked as such), so
         * re-saving the form cannot silently change who is on record.
         *
         * @param  int $count  set to the number of eligible staff found
         * @return string
         */
        function bdsis_staff_options($commondb, $sql, $current, $currentName, &$count = 0)
        {
            $current = trim((string)$current);
            $count   = 0;
            $found   = false;
            $html    = '<option value="">Select Name</option>';
            $res     = ($commondb instanceof mysqli) ? $commondb->query($sql) : false;
            if($res){
                while(list($uname, $fname) = $res->fetch_row()){
                    $uname = (string)$uname;
                    $count++;
                    $sel = ($current !== '' && $uname === $current);
                    if($sel){ $found = true; }
                    $html .= '<option value="'.htmlspecialchars($uname, ENT_QUOTES).'"'.($sel ? ' selected' : '').'>'
                           . htmlspecialchars((string)$fname).'</option>';
                }
                $res->free();
            }
            if($current !== '' && !$found){
                $label = trim((string)$currentName);
                if($label === ''){ $label = $current; }
                $html .= '<option value="'.htmlspecialchars($current, ENT_QUOTES).'" selected>'
                       . htmlspecialchars($label).' (not in this facility\'s list)</option>';
            }
            return $html;
        }
    }
    /* Shown under a picker that has no one to offer, so an empty list is
       explained instead of looking like a control that failed to load. */
    $staffEmptyNote = '<small class="form-text text-danger">No BDSIS user is registered at this facility yet, so there is no name to choose. Contact RCDC to have the facility\'s staff accounts set up.</small>';

    // Section completion, drives the progress graph. Mapping now matches the
    // wizard order: Step 2 = Microscopy, Step 3 = Sample Shipment.
    $wdone = array(
        1 => $isEdit,
        2 => ($isEdit && chkMicroscopy($id, $bdsis) == 1),
        3 => ($isEdit && chkShipment($id, $bdsis) == 1),
        4 => false,
    );
    $wdoneCount = (int)$wdone[1] + (int)$wdone[2] + (int)$wdone[3];
    $wpercent   = round($wdoneCount / ($wtotal - 1) * 100);
    function wlink($id, $n){ return "case.php?action=2&sid=".urlencode($id)."&step=".$n; }
?>

<?php if($caseError !== ''): ?>
    <!-- The requested case could not be loaded. Show this instead of a blank
         form, which is what the page used to render after the wave of
         array-offset warnings. -->
    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-folder-open fa-3x mb-3" style="color:var(--nt-slate-300)"></i>
                    <h4 style="font-weight:700;color:var(--nt-slate-800)">Case not available</h4>
                    <p class="text-muted mb-4"><?=htmlspecialchars($caseError, ENT_QUOTES);?></p>
                    <a href="record-management.php" class="btn bg-gradient-primary">
                        <i class="fa fa-list"></i>&nbsp Back to Records
                    </a>
                    <a href="case.php?action=1" class="btn bg-gradient-secondary ml-2">
                        <i class="fa fa-plus"></i>&nbsp New Case
                    </a>
                </div>
            </div>
        </div>
    </section>
</div><!-- /.content-wrapper -->
<?php require_once("../includes/footer.php"); ?>
<?php exit; endif; ?>
<?php
/* ---------------------------------------------------------------------------
   Conditional ("If yes, ...") field groups are HIDDEN, not merely greyed out.
   This turns the per-field 'disabled' flag the input already carries into the
   matching inline display style, so the first paint arrives with the right
   groups collapsed instead of flashing them until caseInitConditionals() runs.
   It takes the same flag as the input, so markup and behaviour cannot
   disagree, and callers pass `?? ''` for the brand-new-case path where those
   vars are not set.

   Declared HERE, unconditionally at the top level of the file, and as a named
   function rather than a closure. The first version was a closure assigned
   beside $cifVacc INSIDE the `if($step == 1)` block, so on the Microscopy step
   -- where step 1 never executes -- the variable did not exist and the
   othersWrap div died with "Value of type null is not callable".

   An unconditional top-level `function` declaration is hoisted for the entire
   file, so it is defined no matter which $step this request renders and no
   matter where it is called from. That is why there is deliberately NO
   if(!function_exists(...)) wrapper: that wrapper would make the declaration
   conditional again (defined only when the guard runs), which is the shape of
   the bug this replaces. The guard is the right pattern for includes/*.php,
   which can be required more than once; case.php is only ever reached as a
   page and is never included, so a bare declaration is both safe and stronger.
   No isset()/is_callable() check is needed at any call site as a result.
   --------------------------------------------------------------------------- */
function bdsis_case_cond_hide($disabledFlag)
{
    return (trim((string)$disabledFlag) !== '') ? ' style="display:none;"' : '';
}
?>

    <!-- Wizard progress graph -->
    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body py-3">
                    <div class="wizard-steps mb-3">
                        <?php foreach($wsteps as $n => $t){
                            $cls = "wizard-step";
                            if($n == $step) $cls .= " active"; else if($wdone[$n]) $cls .= " done";
                            if(!$isEdit && $n > 1) $cls .= " locked";
                            $ic   = ($wdone[$n] && $n != $step) ? "<i class='fa fa-check'></i>" : $n;
                            $href = ($isEdit && !($n > $wtotal)) ? wlink($id, $n) : "#";
                            if(!$isEdit && $n > 1) $href = "#";
                            echo "<a class='$cls' href='$href'><span class='step-no'>$ic</span><span class='step-label'>".$t."</span></a>";
                        } ?>
                    </div>
                    <small class="text-muted d-block text-center mt-1"><?=$wdoneCount;?> of <?=($wtotal - 1);?> sections completed<?php if(!$isEdit) echo " &mdash; save Case Details to unlock the remaining steps"; ?></small>
                </div>
            </div>
        </div>
    </section>

<?php if($step == 1):
    /* Case Investigation Form additions -- helpers for the OPTIONAL fields below.
       None of them carries `required`; blank stays NULL. */
    /* Under-5 Case Investigation Form gate. The CIF applies to children aged
       0-59 months; at 60 months and above the form stays exactly as it was
       before the CIF fields were added. Age is already captured in MONTHS
       (subject_information_table.age), so no new field is needed -- an empty
       age (a brand-new case) hides the CIF until an age is typed, and
       cifSyncUnder5() re-evaluates it live as the user types. */
    $cifAgeRaw = isset($subjects[5]) ? trim((string)$subjects[5]) : '';
    $cifIsU5   = ($cifAgeRaw !== '' && (int)$cifAgeRaw < 60);
    $cifHide   = $cifIsU5 ? '' : 'style="display:none;"';
    $cifVal = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES); };
    $cifChk = function($cur, $val){ return ((string)$cur !== '' && (string)$cur === (string)$val) ? 'checked' : ''; };
    $cifSel = function($cur, $val){ return ((string)$cur !== '' && (string)$cur === (string)$val) ? 'selected' : ''; };
    $cifRh  = array_filter(explode(',', (string)($epiinfo[23] ?? '')));     // rehydration: '1' ORS, '2' IV fluids
    $cifVacc = (($epiinfo[24] ?? '') === '1') ? '' : 'disabled';             // vaccine details only when received
?>
    <!-- General Information -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">                                            
                    <form class="form-horizontal" id="caseDemography" autocomplete="off" method="Post" action="iu-case.php">
                        <div class="card card-primary">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fa fa-user-plus"></i> Step 1 of 4 &mdash; Case Demography &amp; Clinical Information</h3>
                            </div>
                            <div class="card-body">
                                <input type='hidden' name='action' id='action' value='<?=$action;?>'/>
                                <input type='hidden' name='submit' id='submit' value='caseDemography' />
                                <input type="hidden" name="cid" id="cid" value="<?=$id ?? '';?>" />

                                <div class="row">
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Health Facility:</label>
                                        <div id="facilityDiv">
                                            <?php
                                                /* The centres this user may register a case at, from one helper that
                                                   iu-case.php also validates against.

                                                   Three render paths:
                                                     (edit view)  action==2 -- the case already carries a centre; render
                                                                  it read-only from $facility[0]. No picker.
                                                     (one centre) $onlyCentre !== null (scoped user, or a national user
                                                                  whose profile pins them to one hospital) -- render the
                                                                  centre name as read-only text, no dropdown widget, and
                                                                  post the value via a hidden input. This replaces the
                                                                  previous disabled-<select>: the client asked to remove
                                                                  the picker for scoped users (there is nothing to choose
                                                                  anyway), and this keeps the UI honest about that.
                                                     (chooser)    multiple permitted centres (Admin / RCDC / MoH picking
                                                                  which site to register the case at) -- render the
                                                                  <select> exactly as before.
                                                */
                                                $caseCentres = ($action != 2) ? bdsis_case_centres($commondb) : array();
                                                $onlyCentre  = (count($caseCentres) === 1) ? key($caseCentres) : null;
                                                $onlyLabel   = ($onlyCentre !== null) ? (string)$caseCentres[$onlyCentre] : '';
                                            ?>
                                            <?php if ($action == 2): /* Edit view: no picker, no chooser. */ ?>
                                                <input type="text" class="form-control" value="<?=htmlspecialchars($facility[0], ENT_QUOTES);?>" readonly>
                                                <input type="hidden" name="facility" value="<?=htmlspecialchars($subjects[0], ENT_QUOTES);?>">
                                            <?php elseif ($onlyCentre !== null): /* Single permitted centre: read-only display + hidden value. */ ?>
                                                <input type="text" class="form-control" value="<?=htmlspecialchars($onlyLabel, ENT_QUOTES);?>" readonly>
                                                <input type="hidden" name="facility" id="facil" value="<?=htmlspecialchars($onlyCentre, ENT_QUOTES);?>">
                                                <script>
                                                    /* getSID() runs on the national chooser's onchange; here the value
                                                       never changes, so trigger it once on load so the Subject ID
                                                       preview populates the same way it does after a manual pick. */
                                                    document.addEventListener('DOMContentLoaded', function () {
                                                        if (typeof getSID === 'function') { getSID(<?=json_encode((string)$onlyCentre);?>); }
                                                    });
                                                </script>
                                            <?php elseif (count($caseCentres) > 0): /* National-scope chooser -- unchanged behaviour. */ ?>
                                                <select class="form-control sid" name="facility" id="facil" onchange="getSID(this.value)" <?=($disabled ?? '');?>>
                                                    <option value=""><?=$facility[0];?></option>
                                                    <?php foreach ($caseCentres as $ccId => $ccLabel) {
                                                        echo '<option value="' . htmlspecialchars($ccId, ENT_QUOTES) . '">'
                                                           . htmlspecialchars($ccLabel) . '</option>';
                                                    } ?>
                                                </select>
                                            <?php else: /* No permitted centres at all. */ ?>
                                                <small class="text-danger">No participating health facility is assigned to your
                                                  account's district, so a case cannot be registered here. Contact RCDC.</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for="sid">Subject ID:</label>
                                        <?php
                                            /* Generated, never typed. On a new case this shows the id the server
                                               WILL allocate; iu-case.php regenerates it at save time and retries on
                                               a duplicate key, so what is stored is always unique even if two users
                                               are creating a case at the same moment. Manual entry is gone -- it was
                                               the source of duplicate-ID collisions. */
                                            /* The centre is settled only when the user has exactly one permitted
                                               centre. Read from the same list as the dropdown rather than from
                                               bdsis_user_centre(): a district officer's own centre is their health
                                               office, which has no center_code and so no id prefix -- the case is
                                               registered at the participating hospital in their dzongkhag. */
                                            $sidCentre = '';
                                            if($action == 2){
                                                $sidCentre = $subjects[0] ?? '';
                                            }elseif(isset($onlyCentre) && $onlyCentre !== null){
                                                $sidCentre = (string)$onlyCentre;
                                            }
                                            $sidPreview = ($id ?? '');
                                            if($sidPreview === '' && $sidCentre !== ''){
                                                $sidPreview = (string)bdsis_next_subject_id($bdsis, $commondb, $sidCentre);
                                            }
                                        ?>
                                        <input type="text" name="sid" id="sid" class="form-control text-center"
                                               value="<?=htmlspecialchars($sidPreview, ENT_QUOTES);?>" readonly
                                               title="Generated automatically from your health facility" />
                                        <small class="text-muted" id="sid_result">
                                            <?= ($action == 2)
                                                  ? 'Assigned when the case was created.'
                                                  : ($sidCentre !== ''
                                                        ? 'Allocated automatically for your health facility.'
                                                        : 'Choose a health facility to allocate the Subject ID.'); ?>
                                        </small>
                                    </div>
                                    <!-- CID No. (2026-10-07, widened 2026-10-08): optional alphanumeric
                                         field positioned directly after Subject ID. Letters and digits
                                         only (pattern enforced client-side; iu-case.php re-normalises
                                         server-side via bdsis_normalize_cid_no); non-mandatory, capped
                                         at 50 characters to match the VARCHAR(50) backing column.
                                         Hidden by default when the backing column is not yet present
                                         in the database -- the probe keeps the feature off until
                                         db-migrations/2026-10-07-cid-no.sql (plus the widening ALTER
                                         in db-migrations/2026-10-08-cid-no-widen.sql) has been
                                         applied, so a pre-migration staging environment is
                                         unaffected. -->
                                    <?php $cidColumnReady = (function_exists('bdsis_subject_cid_no_column_exists') && bdsis_subject_cid_no_column_exists($bdsis)); ?>
                                    <?php if ($cidColumnReady): ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for="cid_no">CID No.:</label>
                                        <input type="text" name="cid_no" id="cid_no" class="form-control text-center"
                                               value="<?=htmlspecialchars($subjectCidNo ?? '', ENT_QUOTES);?>"
                                               maxlength="50"
                                               pattern="[A-Za-z0-9]*"
                                               autocomplete="off"
                                               aria-describedby="cid_no_hint"
                                               title="Alphanumeric only (letters and numbers). Optional." />
                                        <small class="text-muted" id="cid_no_hint">Optional. Letters and numbers only.</small>
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "dohv">Date of Hospital Visit:</label>
                                        <input type="date" name="dohv" id="dohv" class="form-control text-center datepicker" value="<?=$subjects[2];?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="reg_number">Reg. Number:</label>
                                        <input class="form-control" type="text" name="reg_number" id="reg_number" maxlength="30" value="<?=$cifVal($subjects[13]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="dob">DOB (DD/MM/YYYY):</label>
                                        <input type="date" name="dob" id="dob" class="form-control text-center datepicker" value="<?=$cifVal($subjects[14]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Category:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="p_category" id="p_categorys" value="1" required <?=$chk_sur;?>>
                                            <label class="form-check-label" for="p_categorys">Surveillance </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="p_category" id="p_categoryo" value="2" required <?=$chk_outb;?>>
                                            <label class="form-check-label" for="p_categoryo">Outbreak </label>
                                        </div>
                                    </div>								
							    
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="cname">Patient Name:</label> 
                                        <input class="form-control" type="text" name="cname" id="cname" placeholder="" aria-label="Patient Name" value="<?=$subjects[3];?>" required /> 
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="cgen">Gender:</label>
                                        <select class="form-control sid" name="cgen" id="cgen" required >
                                            <option value="<?=$gender[0];?>"><?=$gender[1];?></option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "cage">Age (Months):</label> 
                                        <input type="number" name="cage" id="cage" class="form-control text-center" min=0 value="<?=$subjects[5];?>" required oninput="cifSyncUnder5();" />
                                        <small class="text-muted" id="cifU5Note" <?=$cifHide;?>>Under-5 Case Investigation Form fields are shown (0&ndash;59 months).</small>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "cno">Contact Number:</label> 
                                        <input type="number" name="cno" id="cno" class="form-control text-center" min="0" maxlength="10" minlength="8" value="<?=$subjects[9];?>" required />
                                    </div>								
							    
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="occupation">Occupation:</label>
                                        <select class="form-control sid" name="occupation" id="occupation" required >
                                            <option value="<?=$occupationOption[0];?>"><?=$occupationOption[1];?></option>
                                            <?php
                                            $occupation="SELECT id,value FROM ms_occupation_table ORDER BY id ASC";
                                            $result = $commondb->query($occupation);
                                            while($row = $result->fetch_row()) {
                                                echo("<option value='$row[0]'>$row[1]</option>");
                                            }
                                            ?>
                                        </select>
                                    </div>                                    
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="district">District:</label>
                                        <select class="form-control sid" name="district" id="district" required >
                                            <option value="<?=$dzongkhagOption[0];?>"><?=$dzongkhagOption[1];?></option>
                                            <?php
                                            $result = $commondb->query($dzsql);
                                            while($row = $result->fetch_row()) {
                                                echo("<option value='$row[0]'>$row[1]</option>");
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm">Rural / Urban:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="residence_type" id="residence_rural" value="Rural" <?=$cifChk($subjects[15], 'Rural');?>>
                                            <label class="form-check-label" for="residence_rural">Rural</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="residence_type" id="residence_urban" value="Urban" <?=$cifChk($subjects[15], 'Urban');?>>
                                            <label class="form-check-label" for="residence_urban">Urban</label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="guardian_name">Parent Name (Father/Mother)/Guardian:</label>
                                        <input class="form-control" type="text" name="guardian_name" id="guardian_name" maxlength="60" value="<?=$cifVal($subjects[16]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="notified_by">Notified/Investigated by:</label>
                                        <input class="form-control" type="text" name="notified_by" id="notified_by" maxlength="60" value="<?=$cifVal($subjects[17]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="notification_date">Date of notification:</label>
                                        <input type="date" name="notification_date" id="notification_date" class="form-control text-center datepicker" value="<?=$cifVal($subjects[18]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="raddress">Residental Address:</label>
                                        <textarea class="form-control" name="raddress" id="raddress" aria-label="Residental Address" required><?=$subjects[6];?></textarea>
                                    </div>                                    								
							    </div>
                                </div>
                            </div>
                            <!-- /.card-body (Demography) -->

                            <!-- Case Investigation Form: Hospitalization (all optional) -->
                            <label class="col-sm-12 text-primary col-form-label px-4 cif-u5" <?=$cifHide;?>><b>Hospitalization</b></label>
                            <div class="card-body pt-0 cif-u5" <?=$cifHide;?>>
                                <div class="row">
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for="admission_date">Date of admission, if applicable:</label>
                                        <input type="date" name="admission_date" id="admission_date" class="form-control text-center datepicker" value="<?=$cifVal($epiinfo[9]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for="hospital_name">Name/Address of hospital (Sentinel Site):</label>
                                        <input class="form-control" type="text" name="hospital_name" id="hospital_name" maxlength="100" value="<?=$cifVal($epiinfo[10]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for="discharge_death_date">Date of discharge or death, if applicable:</label>
                                        <input type="date" name="discharge_death_date" id="discharge_death_date" class="form-control text-center datepicker" value="<?=$cifVal($epiinfo[11]);?>" />
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body (Hospitalization) -->

                            <label class="col-sm-12 text-primary col-form-label px-4"><b>Clinical Information</b></label>
                            <div class="card-body pt-0">
                                <div class="row">
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Patient Category:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="ccli_status" id="ccli_status1" value="OPD" required <?=$chkopd;?>>
                                            <label class="form-check-label" for="ccli_status1">Out-Patient  </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="ccli_status" id="ccli_status2" value="IPD" required <?=$chkipd;?>>
                                            <label class="form-check-label" for="ccli_status2">In-Patient </label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="doio">Date of Illness Onset:</label> 
                                        <input type="date" name="doio" id="doio" class="form-control text-center datepicker" required value="<?=$epiinfo[1];?>" /> 
                                    </div>

                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="max_diarrhea_episodes">Maximum number of diarrhoeal episodes in 24H at peak of illness:</label>
                                        <input type="number" name="max_diarrhea_episodes" id="max_diarrhea_episodes" min="0" class="form-control text-center" value="<?=$cifVal($epiinfo[12]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="dehydration_degree">Degree of dehydration presented by case:</label>
                                        <select class="form-control" name="dehydration_degree" id="dehydration_degree">
                                            <option value="">Select</option>
                                            <option value="0" <?=$cifSel($epiinfo[13], '0');?>>None</option>
                                            <option value="1" <?=$cifSel($epiinfo[13], '1');?>>Some</option>
                                            <option value="2" <?=$cifSel($epiinfo[13], '2');?>>Severe</option>
                                            <option value="3" <?=$cifSel($epiinfo[13], '3');?>>Shock</option>
                                            <option value="99" <?=$cifSel($epiinfo[13], '99');?>>Unknown</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Diarrhea stool characteristics?:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="cstool_char" id="cstool_char1" value="Watery" required <?=$chkwatery;?>>
                                            <label class="form-check-label" for="cstool_char1">Watery  </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="cstool_char" id="cstool_char2" value="Bloody" required <?=$chkbloody;?>>
                                            <label class="form-check-label" for="cstool_char2">Bloody </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="cstool_char" id="cstool_char3" value="Loose" required <?=$chkloose;?>>
                                            <label class="form-check-label" for="cstool_char3">Loose </label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for ="doio">Duration of Diarrhea (Hours):</label> 
                                        <input type="number" name="dduration" id="dduration" min="0" class="form-control text-center" required value="<?=$epiinfo[3];?>"/> 
                                    </div>

                                    <!-- Case Investigation Form: Syndrome (Yes/No per item, all optional) -->
                                    <div class="form-group col-12 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm"><b>Syndrome</b></label>
                                        <div class="row">
                                        <?php foreach(array(
                                            'sym_watery_loose'    => array(14, 'Watery/Loose diarrhea'),
                                            'sym_bloody'          => array(15, 'Bloody diarrhea'),
                                            'sym_fever'           => array(16, 'Fever'),
                                            'sym_abdominal_pain'  => array(17, 'Abdominal pain'),
                                            'diarrhea_acute'      => array(18, 'Acute diarrhea (&lt;14 days)'),
                                            'diarrhea_persistent' => array(19, 'Persistent diarrhea (last 14-28 days)'),
                                            'diarrhea_chronic'    => array(20, 'Chronic diarrhea (last more than 28 days)'),
                                            'vomiting'            => array(21, 'Vomiting present?'),
                                        ) as $symName => $sym){ ?>
                                            <div class="form-group col-12 col-sm-6 col-md-4">
                                                <label class="col-form-label-sm"><?=$sym[1];?>:</label>
                                                <div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="<?=$symName;?>" id="<?=$symName;?>_y" value="Yes" <?=$cifChk($epiinfo[$sym[0]], 'Yes');?>>
                                                        <label class="form-check-label" for="<?=$symName;?>_y">Yes</label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="<?=$symName;?>" id="<?=$symName;?>_n" value="No" <?=$cifChk($epiinfo[$sym[0]], 'No');?>>
                                                        <label class="form-check-label" for="<?=$symName;?>_n">No</label>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php } ?>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4 cif-u5" <?=$cifHide;?>>
                                        <label class="col-form-label-sm" for="max_vomiting_episodes">Maximum number of vomiting episodes in 24-hour period, at peak of illness:</label>
                                        <input type="number" name="max_vomiting_episodes" id="max_vomiting_episodes" min="0" class="form-control text-center" value="<?=$cifVal($epiinfo[22]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Suspected foods/drinks consumed in the past 72 hours:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="food_consumed" id="food_consumedy" value="Yes"  onchange="enable_foodDesc(this.value)" required <?=$fssyes;?>>
                                            <label class="form-check-label" for="food_consumedy">Yes  </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="food_consumed" id="food_consumedn" value="No"  onchange="enable_foodDesc(this.value)" required <?=$fssno;?>>
                                            <label class="form-check-label" for="food_consumedn">No  </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="food_consumed" id="food_consumedu" value="Unk"  onchange="enable_foodDesc(this.value)" required <?=$fssunk;?>>
                                            <label class="form-check-label" for="food_consumedu">Unknown  </label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="foodNameWrap"<?=bdsis_case_cond_hide($fsnsts ?? '');?>>
                                        <label class="col-form-label-sm" for ="food_name">If yes, Specify <i>(name the food/drinks)</i>:</label>
                                        <textarea class="form-control" name="food_name" id="food_name" <?=$fsnsts;?>><?=$epiinfo[5];?></textarea>
                                    </div>
                                    
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Travel History:</label> 
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="travel_his" id="travel_hisy" value="Yes"  onchange="enable_THistory(this.value)" required <?=$thsyes;?>>
                                            <label class="form-check-label" for="travel_hisy">Yes  </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="travel_his" id="travel_hisn" value="No"  onchange="enable_THistory(this.value)" required <?=$thsno;?>>
                                            <label class="form-check-label" for="travel_hisn">No  </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="travel_his" id="travel_hisu" value="Unk"  onchange="enable_THistory(this.value)" required <?=$thsunk;?>>
                                            <label class="form-check-label" for="travel_hisu">Unknown  </label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="travelPlaceWrap"<?=bdsis_case_cond_hide($tpsts ?? '');?>>
                                        <label class="col-form-label-sm" for ="travel_place">If yes, Place Travelled:</label>
                                        <textarea class="form-control" name="travel_place" id="travel_place" <?=$tpsts;?>><?=$epiinfo[7];?></textarea>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="travelDateWrap"<?=bdsis_case_cond_hide($dotsts ?? '');?>>
                                        <label class="col-form-label-sm" for ="travel_date">Date of Travel:</label> 
                                        <input type="date" name="travel_date" id="travel_date" class="form-control text-center datepicker" required <?=$dotsts;?> value="<?=$epiinfo[8];?>" /> 
                                    </div>	
							    </div>
                                </div>
                            <!-- /.card-body (Clinical) -->

                            <!-- Case Investigation Form: Treatment, Immunization History, Outcome (all optional) -->
                            <label class="col-sm-12 text-primary col-form-label px-4 cif-u5" <?=$cifHide;?>><b>Treatment</b></label>
                            <div class="card-body pt-0 cif-u5" <?=$cifHide;?>>
                                <div class="row">
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Rehydration done:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="rehydration[]" id="rehydration_ors" value="1" <?=in_array('1', $cifRh, true) ? 'checked' : '';?>>
                                            <label class="form-check-label" for="rehydration_ors">ORS</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="rehydration[]" id="rehydration_iv" value="2" <?=in_array('2', $cifRh, true) ? 'checked' : '';?>>
                                            <label class="form-check-label" for="rehydration_iv">IV fluids</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body (Treatment) -->

                            <label class="col-sm-12 text-primary col-form-label px-4 cif-u5" <?=$cifHide;?>><b>Immunization History</b></label>
                            <div class="card-body pt-0 cif-u5" <?=$cifHide;?>>
                                <div class="row">
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Rota vaccine received:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="rota_vaccine" id="rota_vaccine_y" value="1" onchange="enable_vaccine(this.value)" <?=$cifChk($epiinfo[24], '1');?>>
                                            <label class="form-check-label" for="rota_vaccine_y">Yes</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="rota_vaccine" id="rota_vaccine_n" value="2" onchange="enable_vaccine(this.value)" <?=$cifChk($epiinfo[24], '2');?>>
                                            <label class="form-check-label" for="rota_vaccine_n">No</label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="vaccineTypeWrap"<?=bdsis_case_cond_hide($cifVacc ?? '');?>>
                                        <label class="col-form-label-sm" for="vaccine_type">Vaccine type:</label>
                                        <select class="form-control" name="vaccine_type" id="vaccine_type" <?=$cifVacc;?>>
                                            <option value="">Select</option>
                                            <option value="1" <?=$cifSel($epiinfo[25], '1');?>>Rotarix</option>
                                            <option value="2" <?=$cifSel($epiinfo[25], '2');?>>Rotavac</option>
                                            <option value="3" <?=$cifSel($epiinfo[25], '3');?>>Rotasil</option>
                                            <option value="Others" <?=$cifSel($epiinfo[25], 'Others');?>>Others</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="vaccineDateWrap"<?=bdsis_case_cond_hide($cifVacc ?? '');?>>
                                        <label class="col-form-label-sm" for="vaccine_last_date">Date of vaccine last received:</label>
                                        <input type="date" name="vaccine_last_date" id="vaccine_last_date" class="form-control text-center datepicker" <?=$cifVacc;?> value="<?=$cifVal($epiinfo[26]);?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="vaccineSourceWrap"<?=bdsis_case_cond_hide($cifVacc ?? '');?>>
                                        <label class="col-form-label-sm" for="vaccine_info_source">Source of vaccination information:</label>
                                        <select class="form-control" name="vaccine_info_source" id="vaccine_info_source" <?=$cifVacc;?>>
                                            <option value="">Select</option>
                                            <option value="1" <?=$cifSel($epiinfo[27], '1');?>>Card</option>
                                            <option value="2" <?=$cifSel($epiinfo[27], '2');?>>HF records (Health facility/Health book records)</option>
                                            <option value="3" <?=$cifSel($epiinfo[27], '3');?>>Recall</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body (Immunization History) -->

                            <label class="col-sm-12 text-primary col-form-label px-4 cif-u5" <?=$cifHide;?>><b>Outcome</b></label>
                            <div class="card-body pt-0 cif-u5" <?=$cifHide;?>>
                                <div class="row">
                                    <div class="form-group col-12">
                                        <label class="col-form-label-sm">Outcome:</label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="outcome" id="outcome_alive" value="Alive" <?=$cifChk($epiinfo[28], 'Alive');?>>
                                                <label class="form-check-label" for="outcome_alive">Alive</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="outcome" id="outcome_death" value="Death" <?=$cifChk($epiinfo[28], 'Death');?>>
                                                <label class="form-check-label" for="outcome_death">Death</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="outcome" id="outcome_referred" value="Referred" <?=$cifChk($epiinfo[28], 'Referred');?>>
                                                <label class="form-check-label" for="outcome_referred">Referred</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="outcome" id="outcome_unknown" value="99" <?=$cifChk($epiinfo[28], '99');?>>
                                                <label class="form-check-label" for="outcome_unknown">unknown</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body (Outcome) -->
                            <div class="card-footer d-flex align-items-center">
                                <!-- Left: Cancel.  Right: the primary action. -->
                                <a href="<?=$cancel;?>" class="btn bg-gradient-secondary">Cancel</a>
                                <?=$deletepi;?>
                                <button type="submit" class="btn bg-gradient-success ml-auto"><i class="fa fa-save"></i> <?=$savepi;?> &amp; Continue</button>
                            </div>
                        </div>
                        <!-- /.card -->                        
                    </form>  
                </div>
                <!-- /.col -->
            </div>
            <!-- /.row -->

        </div>
    </section>
    <!-- /.section -->
<?php endif; /* step 1: Case Details */ ?>

<?php
/* Wizard order was reordered so Sample Shipment now sits at Step 3 (after
   Microscopy Result). The block below is unmoved; only its guard changed
   from `$step == 2` to `$step == 3`. */
if($isEdit && $step == 3):
    $sid=$id;
    // $ctid, $facilityUser and $eidlqry are set at page level (see above).

    $shipmentqry = $bdsis->query("SELECT collected_by, collection_date, shipment_by, shipment_date, receive_by, receive_date, sample_status, status_details FROM sample_information_table WHERE subject_id='".$sid."'");
    if($shipmentqry->num_rows > 0){
        list($cby,$cdate,$shby,$shdate,$rcvby,$rcvdate,$samsts,$stsdetail)=$shipmentqry->fetch_row();

        /* The three "resolve this username to a full name" lookups that used to
           sit here are gone. Nothing reads their result any more -- the fields
           are free-text inputs that resolve their own value through
           bdsis_processed_by_label() -- and they interpolated the stored value
           into SQL unescaped, so a typed name containing an apostrophe threw a
           syntax error and took the whole Sample Shipment step down with it. */

        if($samsts=="1"){$chksamsts1="Checked";$chksamsts2=""; $disdetails="disabled";} else if($samsts=="2"){$chksamsts1="";$chksamsts2="Checked"; $disdetails="";} else{$chksamsts1="";$chksamsts2=""; $disdetails="disabled";}

        if (chkShipment($id, $bdsis)==1 AND bdsis_can('delete', 'records')) {
            $deletesi = "<a href='delete.php?type=sampleInfo&id=$sid&siid=$sid' class='btn bg-gradient-danger mx-5' onclick='return confirmDelete()'>Delete</a>"; 
        }else{$deletesi = "";}
        $savesi = "Update";

    }else{
        $action = 3;
        $cby = $cdate = $shby = $shdate = $rcvby = $rcvdate = $samsts = $stsdetail = $chksamsts1 = $chksamsts2 =  $disdetails = '';
        /* No sample status chosen yet, so the details box starts disabled -- the
           same state an existing record with no status gets above. Without this
           the RCDC block behaved differently on create than on edit. */
        $disdetails = 'disabled';
        $cbyname = $rcvbyname = $shbyname ='Select Name';
        $deletesi = "";
        $savesi = "Save";
    }
	
?>
    <!-- Sample Shipment -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">                                            
                    <form class="form-horizontal" id="sampleShipment" autocomplete="off" method="Post" action="iu-case.php">
                        <div class="card card-primary">
                            <div class="card-header">
                                <h3 class="card-title">Sample Shipment</h3>
                            </div>
                            <div class="card-body">
                                <input type='hidden' name='action' id='action' value='<?=$action;?>'/>
                                <input type='hidden' name='submit' id='submit' value='sampleShipment' />
                                <input type="hidden" name="sid" id="sid" value="<?=$id ?? '';?>" />

                                <div class="row">
                                    <label class="col-sm-12 col-form-label"><span class="text-primary">Sample Information:</span> <?=$id;?></label>                                    
                                    <!-- Four related fields (two collected/shipped pairs). At lg they sit on
                                         one row, below that they break into those pairs, so none is orphaned
                                         onto a row of its own. -->
                                    <div class="form-group col-12 col-sm-6 col-lg-3">
                                        <label class="col-form-label-sm" for="ccby">Sample Collected by:</label>
                                        <!-- Free text: the name is typed in rather than picked from the
                                             facility's BDSIS users. maxlength matches collected_by
                                             varchar(50), so a long name is refused at the field instead
                                             of being silently truncated by the insert. -->
                                        <input type="text" class="form-control" name="ccby" id="ccby" maxlength="50" value="<?=htmlspecialchars(bdsis_processed_by_label($cby, $commondb), ENT_QUOTES);?>" required />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-lg-3">
                                        <label class="col-form-label-sm" for="doc">Date of collection:</label>
                                        <!-- required: sample_information_table.collection_date is NOT NULL, so a
                                             blank date could never be stored. Without this the browser allowed the
                                             submit and the insert died server side, which is why a new Sample
                                             Shipment silently failed to save. -->
                                        <input type="date" name="doc" id="doc" class="form-control text-center datepicker" value="<?=$cdate;?>" required />
                                    </div>

                                    <div class="form-group col-12 col-sm-6 col-lg-3">
                                        <label class="col-form-label-sm" for="shipby">Sample Shipped to RCDC by:</label>
                                        <!-- Free text; see "Sample Collected by" above. -->
                                        <input type="text" class="form-control" name="shipby" id="shipby" maxlength="50" value="<?=htmlspecialchars(bdsis_processed_by_label($shby, $commondb), ENT_QUOTES);?>" required />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-lg-3">
                                        <label class="col-form-label-sm" for="dos">Date of Shipment to RCDC:</label>
                                        <input type="date" name="dos" id="dos" class="form-control text-center datepicker" value="<?=$shdate;?>" />
                                    </div>
                                </div>
                                <?php
                                /* Sample Information (RCDC) card -- WITHDRAWN.
                                   The "Sample Received By / Date of Sample Receive /
                                   Sample Status / If Unsatisfactory, Give Details"
                                   inputs previously rendered here (behind $mayRecordReceipt)
                                   were removed at the client's request. The underlying
                                   sample_information_table columns (receive_by, receive_date,
                                   sample_status, status_details) are UNTOUCHED so any
                                   historical values still resolve on reports and the
                                   preview view further down still displays them. The
                                   companion save handler (iu-case.php) no longer reads
                                   these POST fields nor writes those columns, so a new
                                   save cannot overwrite existing receipt data with NULL.
                                   See iu-case.php for the paired change. */
                                ?>
                            </div>
                            <!-- /.card-body -->
                            <div class="card-footer d-flex align-items-center">
                                <!-- Left: Cancel, Previous.  Right: the primary action. -->
                                <a href="<?=$cancel;?>" class="btn bg-gradient-secondary">Cancel</a>
                                <a href="<?=wlink($id,2);?>" class="btn bg-gradient-secondary ml-2"><i class="fa fa-arrow-left"></i> Previous</a>
                                <?=$deletesi;?>
                                <button type="submit" class="btn bg-gradient-success ml-auto"><i class="fa fa-save"></i> <?=$savesi;?> &amp; Continue</button>
                            </div>
                        </div>
                        <!-- /.card -->
                    </form>  
                </div>
                <!-- /.col -->
            </div>
            <!-- /.row -->
        </div>
    </section>
    <!-- /.section -->
<?php endif; /* step 3: Sample Shipment (was step 2 before the reorder) */ ?>

<?php /* Wizard order was reordered so Microscopy Result now sits at Step 2
         (immediately after Case Details). The block below is unmoved; only
         its guard changed from `$step == 3` to `$step == 2`. */
if($isEdit && $step == 2):
    $sid=$id;

    /* Under-5 gate for the CIF laboratory fields on this step. The age lives on
       Step 1 and cannot change here, so it is read once from the saved record. */
    $micAgeRaw = isset($subjects[5]) ? trim((string)$subjects[5]) : '';
    $micIsU5   = ($micAgeRaw !== '' && (int)$micAgeRaw < 60);

	$microscopyqry = $bdsis->query("SELECT color,mucus,blood,wbc,rbc,ova_cyst,others,aliquots,processed_by,processed_on,quantity_adequate,consistency,aliquot_mcb FROM microscopy_result_table WHERE subject_id='".$sid."'");
    if($microscopyqry->num_rows > 0){
        $action = 2;
        list($color,$muc,$bld,$wbc,$rbc,$ovac,$othrs,$aliquots,$mpby,$pon,$qtyAdq,$consist,$aliqMcb)=$microscopyqry->fetch_row();

        /* Removed for the same reason as the three in the Sample Shipment step
           above: unread, and unescaped against a value that is now free text. */
        $oth1="";$oth2="";$othdisable="";
        if($othrs!=null){$oth1="Checked";} else{$oth2="Checked"; $othdisable="disabled";}
        $aliquot1=$aliquot2="";
        if($aliquots==1){$aliquot1="Checked";} elseif($aliquots==0){$aliquot2="Checked";}
        if (chkMicroscopy($id, $bdsis)==1) {
            $deletemr = "<a href='delete.php?type=microscopy&id=$sid&siid=$sid' class='btn bg-gradient-danger mx-5' onclick='return confirmDelete()'>Delete</a>"; 
        }else{$deletemr = "";}
        $savemr = "Update";
    }else {
        $action = 4;
        $color = $muc = $bld = $wbc = $rbc = $ovac = $othrs = $aliquots = $mpby = $pon = $aliquot1 = $aliquot2 = $oth1 = '';
        $qtyAdq = $consist = $aliqMcb = '';
        $oth2 = 'Checked';
        $othdisable="disabled";
        $mpbyname ='Select Name';
        $deletemr = "";
        $savemr = "Save";
    }
	
    ?>

    <!-- Microscopy Result -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">                                            
                    <form class="form-horizontal" id="microscopyResult" autocomplete="off" method="Post" action="iu-case.php">
                        <div class="card card-primary">
                            <div class="card-header">
                                <h3 class="card-title">Microscopy Result</h3>
                            </div>
                            <div class="card-body">
                                <input type='hidden' name='action' id='action' value='<?=$action;?>'/>
                                <input type='hidden' name='submit' id='submit' value='microscopyResult' />
                                <input type="hidden" name="sid" id="sid" value="<?=$id ?? '';?>" />

                                <div class="row">
                                    <label class="col-sm-12 col-form-label"><span class="text-primary">Microscopy Result:</span> <?=$id;?></label>                           
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Colour:</label>
                                        <select class="form-control" name="color" id="color" required >
                                            <option value="<?= $color; ?>"><?= $color; ?></option>	
                                            <option value="Brown">Brown</option>
                                            <option value="Yellow">Yellow</option>
                                            <option value="Green">Green</option>
                                            <option value="Black">Black</option>
                                            <option value="Red">Red</option>
                                            <option value="Clay">Clay</option>								
                                            <option value="Other">Other</option>
                                        </select>                    
                                    </div>
<?php if($micIsU5): /* Under-5 CIF laboratory detail only */ ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Consistency:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="consistency" id="consistency_wl" value="Watery/Loose" <?=((string)$consist === 'Watery/Loose') ? 'checked' : '';?>>
                                            <label class="form-check-label" for="consistency_wl">Watery/Loose </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="consistency" id="consistency_f" value="Formed" <?=((string)$consist === 'Formed') ? 'checked' : '';?>>
                                            <label class="form-check-label" for="consistency_f">Formed </label>
                                        </div>
                                    </div>
<?php endif; ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Mucus:</label>
                                        <select class="form-control" name="mucus" id="mucus" required >
                                            <option value="<?= $muc; ?>"><?= $muc; ?></option>	
                                            <option value="Yes">Yes</option>
                                            <option value="No">No</option>
                                        </select>                    
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Blood:</label>
                                        <select class="form-control" name="blood" id="blood" required >
                                            <option value="<?= $bld; ?>"><?= $bld; ?></option>	
                                            <option value="Yes">Yes</option>
                                            <option value="No">No</option>
                                        </select>                    
                                    </div>
                                
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "wbc">White Blood Cells(WBC):</label> 
                                        <input type="text" name="wbc" id="wbc" class="form-control text-center" required value="<?=$wbc;?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "rbc">Red Blood Cells(RBC):</label> 
                                        <input type="text" name="rbc" id="rbc" class="form-control text-center" required value="<?=$rbc;?>" />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "ovc">Ova/cyst:</label> 
                                        <input type="text" name="ovc" id="ovc" class="form-control text-center" required value="<?=$ovac;?>" />
                                    </div>
                                
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Others:</label> 
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" value="1" name="oth" id="oth1" onchange="enableOthers(this.value);" required <?=$oth1;?>>
                                            <label class="form-check-label" for="oth1">Yes </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" value="0" name="oth" id="oth2" onchange="enableOthers(this.value);" required <?=$oth2;?>>
                                            <label class="form-check-label" for="oth2">No </label>
                                        </div>
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4" id="othersWrap"<?=bdsis_case_cond_hide($othdisable ?? '');?>>
                                        <label class="col-form-label-sm" for ="others">Others:</label>
                                        <textarea class="form-control" name="others" id="others" <?=$othdisable;?>><?=$othrs;?></textarea>
                                    </div>
<?php if($micIsU5): /* Under-5 CIF laboratory detail only */ ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Quantity adequate?:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="quantity_adequate" id="quantity_adequate_y" value="Yes" <?=((string)$qtyAdq === 'Yes') ? 'checked' : '';?>>
                                            <label class="form-check-label" for="quantity_adequate_y">Yes </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="quantity_adequate" id="quantity_adequate_n" value="No" <?=((string)$qtyAdq === 'No') ? 'checked' : '';?>>
                                            <label class="form-check-label" for="quantity_adequate_n">No </label>
                                        </div>
                                    </div>
<?php endif; ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Sample Aliquots in Cryo tube?:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" value="1" name="aliquot" id="aliquot1" required <?=$aliquot1;?>>
                                            <label class="form-check-label" for="aliquot1">Yes </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" value="0" name="aliquot" id="aliquot2" required <?=$aliquot2;?>>
                                            <label class="form-check-label" for="aliquot2">No </label>
                                        </div>
                                    </div>
<?php if($micIsU5): /* Under-5 CIF laboratory detail only */ ?>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm">Sample aliquot in mCB media?:</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" value="1" name="aliquot_mcb" id="aliquot_mcb1" <?=((string)$aliqMcb === '1') ? 'checked' : '';?>>
                                            <label class="form-check-label" for="aliquot_mcb1">Yes </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" value="0" name="aliquot_mcb" id="aliquot_mcb2" <?=((string)$aliqMcb === '0') ? 'checked' : '';?>>
                                            <label class="form-check-label" for="aliquot_mcb2">No </label>
                                        </div>
                                    </div>
<?php endif; ?>
							                                     
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for="proby">Sample Processed by:</label>
                                        <!-- Free text; see "Sample Collected by" on the Sample Shipment step. -->
                                        <input type="text" class="form-control" name="proby" id="proby" maxlength="50" value="<?=htmlspecialchars(bdsis_processed_by_label($mpby, $commondb), ENT_QUOTES);?>" required />
                                    </div>
                                    <div class="form-group col-12 col-sm-6 col-md-4">
                                        <label class="col-form-label-sm" for = "prodate">Date of processing:</label> 
                                        <input type="date" name="prodate" id="prodate" class="form-control text-center datepicker" value="<?=$pon;?>" />
                                    </div>
                                </div>
                            </div> 
                            <!-- /.card-body -->
                            <div class="card-footer d-flex align-items-center">
                                <!-- Left: Cancel, Previous.  Right: the primary action. -->
                                <a href="<?=$cancel;?>" class="btn bg-gradient-secondary">Cancel</a>
                                <a href="<?=wlink($id,1);?>" class="btn bg-gradient-secondary ml-2"><i class="fa fa-arrow-left"></i> Previous</a>
                                <?=$deletemr;?>
                                <button type="submit" class="btn bg-gradient-success ml-auto"><i class="fa fa-save"></i> <?=$savemr;?> &amp; Continue</button>
                            </div>
                        </div>
                        <!-- /.card -->
                    </form>  
                </div>
                <!-- /.col -->
            </div>
            <!-- /.row -->
        </div>
    </section>
    <!-- /.section -->
<?php endif; /* step 2: Microscopy (was step 3 before the reorder) */ ?>

<?php if($isEdit && $step == 4):
    // Read-back for the preview step.
    $pvSample = $bdsis->query("SELECT collected_by, collection_date, shipment_by, shipment_date, receive_by, receive_date, sample_status, status_details FROM sample_information_table WHERE subject_id='".$id."'");
    $pvSample = ($pvSample && $pvSample->num_rows) ? $pvSample->fetch_assoc() : null;
    $pvMicro = $bdsis->query("SELECT color,mucus,blood,wbc,rbc,ova_cyst,others,aliquots,processed_by,processed_on,quantity_adequate,consistency,aliquot_mcb FROM microscopy_result_table WHERE subject_id='".$id."'");
    $pvMicro = ($pvMicro && $pvMicro->num_rows) ? $pvMicro->fetch_assoc() : null;
    $nm = function($u) use ($commondb){ if(!$u) return ''; $r=$commondb->query("SELECT fullname FROM user_profile_table WHERE username='".$commondb->real_escape_string($u)."'"); if($r && $r->num_rows){ $x=$r->fetch_row(); return $x[0]; } return $u; };
    $dash = "<span class='text-muted'>&mdash;</span>";
    $show = function($v) use ($dash){ $v = trim((string)$v); return ($v==='' || $v=='0000-00-00') ? $dash : htmlspecialchars($v); };
    /* Labels for the coded Case Investigation Form answers. */
    $pvCode = function($v, $map) use ($dash){ $v = trim((string)$v); if($v === '') return $dash; return isset($map[$v]) ? htmlspecialchars($map[$v]) : htmlspecialchars($v); };
    $pvDehyd = array('0'=>'0 = None', '1'=>'1 = Some', '2'=>'2 = Severe', '3'=>'3 = Shock', '99'=>'99 = Unknown');
    $pvVacc  = array('1'=>'Rotarix', '2'=>'Rotavac', '3'=>'Rotasil', 'Others'=>'Others');
    $pvSrc   = array('1'=>'Card', '2'=>'HF records', '3'=>'Recall');
    $pvOut   = array('Alive'=>'Alive', 'Death'=>'Death', 'Referred'=>'Referred', '99'=>'Unknown');
    $pvRota  = array('1'=>'Yes', '2'=>'No');
    $pvYesNo = array('1'=>'Yes', '0'=>'No');
    $pvRh    = implode(', ', array_map(function($c){ return $c === '1' ? 'ORS' : ($c === '2' ? 'IV fluids' : $c); }, array_filter(explode(',', (string)$epiinfo[23]))));
    /* Under-5 gate for the preview, from the same age in months. */
    $pvAgeRaw = isset($subjects[5]) ? trim((string)$subjects[5]) : '';
    $pvIsU5   = ($pvAgeRaw !== '' && (int)$pvAgeRaw < 60);
?>
    <section class="content">
      <div class="container-fluid">
        <div class="card card-primary card-outline">
          <div class="card-header"><h3 class="card-title"><i class="fa fa-clipboard-check"></i> Step 4 of 4 &mdash; Preview &amp; Finish</h3></div>
          <div class="card-body">

            <div class="preview-section mb-4">
              <h5>Case Demography</h5>
              <dl class="row mb-0">
                <dt class="col-sm-3">Subject ID</dt><dd class="col-sm-3"><?=$show($id);?></dd>
<?php if (function_exists('bdsis_subject_cid_no_column_exists') && bdsis_subject_cid_no_column_exists($bdsis)): ?>
                <dt class="col-sm-3">CID No.</dt><dd class="col-sm-3"><?=$show($subjectCidNo ?? '');?></dd>
<?php endif; ?>
                <dt class="col-sm-3">Health Facility</dt><dd class="col-sm-3"><?=$show($facility[0]);?></dd>
                <dt class="col-sm-3">Date of Hospital Visit</dt><dd class="col-sm-3"><?=$show($subjects[2]);?></dd>
                <dt class="col-sm-3">Category</dt><dd class="col-sm-3"><?=($subjects[10]=='1'?'Surveillance':($subjects[10]=='2'?'Outbreak':$dash));?></dd>
                <dt class="col-sm-3">Patient Name</dt><dd class="col-sm-3"><?=$show($subjects[3]);?></dd>
                <dt class="col-sm-3">Gender</dt><dd class="col-sm-3"><?=$show($subjects[4]);?></dd>
                <dt class="col-sm-3">Age (Months)</dt><dd class="col-sm-3"><?=$show($subjects[5]);?></dd>
                <dt class="col-sm-3">Contact Number</dt><dd class="col-sm-3"><?=$show($subjects[9]);?></dd>
                <dt class="col-sm-3">Occupation</dt><dd class="col-sm-3"><?=$show($occupation[0]);?></dd>
                <dt class="col-sm-3">District</dt><dd class="col-sm-3"><?=$show($dzongkhag[1] ?? '');?></dd>
                <dt class="col-sm-3">Residential Address</dt><dd class="col-sm-9"><?=$show($subjects[6]);?></dd>
<?php if($pvIsU5): /* Under-5 CIF details only */ ?>
                <dt class="col-sm-3">Reg. Number</dt><dd class="col-sm-3"><?=$show($subjects[13]);?></dd>
                <dt class="col-sm-3">DOB</dt><dd class="col-sm-3"><?=$show($subjects[14]);?></dd>
                <dt class="col-sm-3">Rural / Urban</dt><dd class="col-sm-3"><?=$show($subjects[15]);?></dd>
                <dt class="col-sm-3">Parent/Guardian</dt><dd class="col-sm-3"><?=$show($subjects[16]);?></dd>
                <dt class="col-sm-3">Notified/Investigated by</dt><dd class="col-sm-3"><?=$show($subjects[17]);?></dd>
                <dt class="col-sm-3">Date of notification</dt><dd class="col-sm-3"><?=$show($subjects[18]);?></dd>
<?php endif; ?>
              </dl>
            </div>

<?php if($pvIsU5): ?>
            <div class="preview-section mb-4">
              <h5>Hospitalization</h5>
              <dl class="row mb-0">
                <dt class="col-sm-3">Date of admission</dt><dd class="col-sm-3"><?=$show($epiinfo[9]);?></dd>
                <dt class="col-sm-3">Hospital (Sentinel Site)</dt><dd class="col-sm-3"><?=$show($epiinfo[10]);?></dd>
                <dt class="col-sm-3">Date of discharge or death</dt><dd class="col-sm-3"><?=$show($epiinfo[11]);?></dd>
              </dl>
            </div>
<?php endif; /* Hospitalization: under-5 only */ ?>

            <div class="preview-section mb-4">
              <h5>Clinical Information</h5>
              <dl class="row mb-0">
                <dt class="col-sm-3">Patient Category</dt><dd class="col-sm-3"><?=($epiinfo[0]=='OPD'?'Out-Patient':($epiinfo[0]=='IPD'?'In-Patient':$dash));?></dd>
                <dt class="col-sm-3">Date of Illness Onset</dt><dd class="col-sm-3"><?=$show($epiinfo[1]);?></dd>
                <dt class="col-sm-3">Stool Characteristics</dt><dd class="col-sm-3"><?=$show($epiinfo[2]);?></dd>
                <dt class="col-sm-3">Duration of Diarrhea (Hrs)</dt><dd class="col-sm-3"><?=$show($epiinfo[3]);?></dd>
                <dt class="col-sm-3">Suspected food/drinks</dt><dd class="col-sm-3"><?=$show($epiinfo[4]);?></dd>
                <dt class="col-sm-3">Food name</dt><dd class="col-sm-3"><?=$show($epiinfo[5]);?></dd>
                <dt class="col-sm-3">Travel History</dt><dd class="col-sm-3"><?=$show($epiinfo[6]);?></dd>
                <dt class="col-sm-3">Place Travelled</dt><dd class="col-sm-3"><?=$show($epiinfo[7]);?></dd>
                <dt class="col-sm-3">Date of Travel</dt><dd class="col-sm-3"><?=$show($epiinfo[8]);?></dd>
<?php if($pvIsU5): /* Under-5 CIF clinical detail */ ?>
                <dt class="col-sm-3">Max diarrhoeal episodes / 24H</dt><dd class="col-sm-3"><?=$show($epiinfo[12]);?></dd>
                <dt class="col-sm-3">Degree of dehydration</dt><dd class="col-sm-3"><?=$pvCode($epiinfo[13], $pvDehyd);?></dd>
                <dt class="col-sm-3">Watery/Loose diarrhea</dt><dd class="col-sm-3"><?=$show($epiinfo[14]);?></dd>
                <dt class="col-sm-3">Bloody diarrhea</dt><dd class="col-sm-3"><?=$show($epiinfo[15]);?></dd>
                <dt class="col-sm-3">Fever</dt><dd class="col-sm-3"><?=$show($epiinfo[16]);?></dd>
                <dt class="col-sm-3">Abdominal pain</dt><dd class="col-sm-3"><?=$show($epiinfo[17]);?></dd>
                <dt class="col-sm-3">Acute diarrhea (&lt;14 days)</dt><dd class="col-sm-3"><?=$show($epiinfo[18]);?></dd>
                <dt class="col-sm-3">Persistent diarrhea (14-28 days)</dt><dd class="col-sm-3"><?=$show($epiinfo[19]);?></dd>
                <dt class="col-sm-3">Chronic diarrhea (&gt;28 days)</dt><dd class="col-sm-3"><?=$show($epiinfo[20]);?></dd>
                <dt class="col-sm-3">Vomiting present</dt><dd class="col-sm-3"><?=$show($epiinfo[21]);?></dd>
                <dt class="col-sm-3">Max vomiting episodes / 24H</dt><dd class="col-sm-3"><?=$show($epiinfo[22]);?></dd>
<?php endif; ?>
              </dl>
            </div>

<?php if($pvIsU5): ?>
            <div class="preview-section mb-4">
              <h5>Treatment, Immunization History &amp; Outcome</h5>
              <dl class="row mb-0">
                <dt class="col-sm-3">Rehydration done</dt><dd class="col-sm-3"><?=$show($pvRh);?></dd>
                <dt class="col-sm-3">Rota vaccine received</dt><dd class="col-sm-3"><?=$pvCode($epiinfo[24], $pvRota);?></dd>
                <dt class="col-sm-3">Vaccine type</dt><dd class="col-sm-3"><?=$pvCode($epiinfo[25], $pvVacc);?></dd>
                <dt class="col-sm-3">Date of vaccine last received</dt><dd class="col-sm-3"><?=$show($epiinfo[26]);?></dd>
                <dt class="col-sm-3">Source of vaccination information</dt><dd class="col-sm-3"><?=$pvCode($epiinfo[27], $pvSrc);?></dd>
                <dt class="col-sm-3">Outcome</dt><dd class="col-sm-3"><?=$pvCode($epiinfo[28], $pvOut);?></dd>
              </dl>
            </div>
<?php endif; /* Treatment / Immunization / Outcome: under-5 only */ ?>

            <div class="preview-section mb-4">
              <h5>Sample Shipment <?php if(!$pvSample) echo "<small class='text-warning'>(not entered)</small>"; ?></h5>
              <?php if($pvSample): ?>
              <dl class="row mb-0">
                <dt class="col-sm-3">Collected By</dt><dd class="col-sm-3"><?=$show($nm($pvSample['collected_by']));?></dd>
                <dt class="col-sm-3">Collection Date</dt><dd class="col-sm-3"><?=$show($pvSample['collection_date']);?></dd>
                <dt class="col-sm-3">Shipped By</dt><dd class="col-sm-3"><?=$show($nm($pvSample['shipment_by']));?></dd>
                <dt class="col-sm-3">Shipment Date</dt><dd class="col-sm-3"><?=$show($pvSample['shipment_date']);?></dd>
                <dt class="col-sm-3">Received By</dt><dd class="col-sm-3"><?=$show($nm($pvSample['receive_by']));?></dd>
                <dt class="col-sm-3">Receive Date</dt><dd class="col-sm-3"><?=$show($pvSample['receive_date']);?></dd>
                <dt class="col-sm-3">Sample Status</dt><dd class="col-sm-3"><?=($pvSample['sample_status']=='1'?'Satisfactory':($pvSample['sample_status']=='2'?'Unsatisfactory':$dash));?></dd>
                <dt class="col-sm-3">Status Details</dt><dd class="col-sm-3"><?=$show($pvSample['status_details']);?></dd>
              </dl>
              <?php endif; ?>
            </div>

            <div class="preview-section mb-4">
              <h5>Microscopy Result <?php if(!$pvMicro) echo "<small class='text-warning'>(not entered)</small>"; ?></h5>
              <?php if($pvMicro): ?>
              <dl class="row mb-0">
                <dt class="col-sm-3">Colour</dt><dd class="col-sm-3"><?=$show($pvMicro['color']);?></dd>
                <dt class="col-sm-3">Mucus</dt><dd class="col-sm-3"><?=$show($pvMicro['mucus']);?></dd>
                <dt class="col-sm-3">Blood</dt><dd class="col-sm-3"><?=$show($pvMicro['blood']);?></dd>
                <dt class="col-sm-3">WBC</dt><dd class="col-sm-3"><?=$show($pvMicro['wbc']);?></dd>
                <dt class="col-sm-3">RBC</dt><dd class="col-sm-3"><?=$show($pvMicro['rbc']);?></dd>
                <dt class="col-sm-3">Ova/Cyst</dt><dd class="col-sm-3"><?=$show($pvMicro['ova_cyst']);?></dd>
                <dt class="col-sm-3">Others</dt><dd class="col-sm-3"><?=$show($pvMicro['others']);?></dd>
                <dt class="col-sm-3">Processed By</dt><dd class="col-sm-3"><?=$show($nm($pvMicro['processed_by']));?></dd>
                <dt class="col-sm-3">Consistency</dt><dd class="col-sm-3"><?=$show($pvMicro['consistency']);?></dd>
                <dt class="col-sm-3">Quantity adequate</dt><dd class="col-sm-3"><?=$show($pvMicro['quantity_adequate']);?></dd>
                <dt class="col-sm-3">Aliquot in cryo tube</dt><dd class="col-sm-3"><?=$pvCode($pvMicro['aliquots'], $pvYesNo);?></dd>
                <dt class="col-sm-3">Aliquot in mCB media</dt><dd class="col-sm-3"><?=$pvCode($pvMicro['aliquot_mcb'], $pvYesNo);?></dd>
              </dl>
              <?php endif; ?>
            </div>

          </div>
          <div class="card-footer d-flex align-items-center">
            <!-- Final step. Left: Cancel, Review.  Right: Submit.
                 "Review" is the full read-only report, which is what there is to
                 review at this point; it replaces the old "View Full Report". -->
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary">Cancel</a>
            <a href="record-management.php" class="btn bg-gradient-success ml-auto"><i class="fa fa-check"></i> Submit</a>
          </div>
        </div>
      </div>
    </section>
<?php endif; /* step 4: Preview */ ?>
</div>

<!-- /.content-wrapper -->    
<script>
    $(document).ready(function(){
        cifSyncUnder5();   // match the CIF fields to the age already on the form
        caseInitConditionals();   // collapse/reveal every "If yes, ..." group
        /* rcdcSyncRequired() call removed with the Sample Information (RCDC)
           card. Its DOM ids (receiveby, dosr, ssts1, ssts2, rcdcIntake) no
           longer exist. See iu-case.php for the paired backend change. */

        $('#caseDemography').validate({
            submitHandler: function (form) { return bdsisCrudSubmit(form); },
            rules: {
                dzongkhag: "required",
                facility: "required",
            },
            
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });

        $('#sampleShipment').validate({
            submitHandler: function (form) { return bdsisCrudSubmit(form); },
            rules: {
            },
            
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });

        $('#microscopyResult').validate({
            submitHandler: function (form) { return bdsisCrudSubmit(form); },
            rules: {
            },
            
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });


        /* $("#facility").change(function(){
            var facility = $('#facility').val();
            $.get("load-sid.php?ctid="+facility, function(data) {
                    $('#chksid').val(data);
            });
        }); */
        
    });

    /* checkSID() removed: the Subject ID is generated server-side and read-only,
       so there is nothing to type and nothing to pre-check. Uniqueness is
       guaranteed by the primary key and the retry loop in iu-case.php. */

    /* Fills the read-only Subject ID with the id the server would allocate for the
       chosen facility. Only national roles can change facility; for everyone else
       the field is already populated server-side. The value is a preview --
       iu-case.php re-allocates and retries on collision at save time. */
    function getSID(facility){
        if(!facility){ $('#sid').val(''); return; }
        $.get("load-sid.php?ctid="+encodeURIComponent(facility), function(data) {
                $('#sid').val($.trim(data));
        });
    }

    /* Show the Under-5 CIF fields only for 0-59 months. Presentation only:
       iu-case.php re-derives the same rule from the posted age and is what
       actually decides whether CIF values are stored. */
    function cifSyncUnder5(){
        var ageEl = document.getElementById('cage');
        var raw   = ageEl ? (ageEl.value || '').trim() : '';
        var months = (raw === '') ? -1 : parseInt(raw, 10);
        var show  = (months >= 0 && months < 60);
        var els = document.querySelectorAll('.cif-u5');
        for(var i=0;i<els.length;i++){ els[i].style.display = show ? '' : 'none'; }
        var note = document.getElementById('cifU5Note');
        if(note){ note.style.display = show ? '' : 'none'; }
    }

    /* ----- Conditional ("If yes, ...") field groups ---------------------
       2026-10-14: these four used to only grey the dependent inputs out with
       .disabled, leaving them on screen -- and two of them did not clear what
       had been typed, so switching Yes -> No on an edit left the old answer
       sitting in a greyed box looking like it would still be saved (it would
       not: iu-case.php writes NULL for those columns whenever the status is
       not Yes). They now collapse the whole field group instead.

       caseSetCondGroup does four things, and the ORDER of the last two
       matters:

         1. shows or hides each wrapper div;
         2. enables or disables each input. Disabling is what actually keeps a
            hidden field out of the POST -- display:none alone does NOT stop a
            field being submitted, so hiding without disabling would have left
            exactly the stale-data path this change is meant to close;
         3. drops `required` BEFORE the group is hidden. A hidden input that is
            still required makes the browser refuse to submit while reporting
            the error against an invisible control -- the form just appears
            dead. travel_date is rendered with a hard-coded `required`, so this
            is a live case, not a hypothetical;
         4. clears the value, since the server is going to store NULL anyway.
            The original required state is remembered on data-was-required so
            re-answering Yes restores it (same attribute lab.php uses for its
            view/edit mode, kept deliberately consistent).

       Both validators in play agree with this: jQuery Validate skips
       :hidden by default (no `ignore` override anywhere on this page) and
       native constraint validation skips disabled fields. */
    function caseSetCondGroup(show, wrapIds, fieldIds){
        var i, el;
        for(i = 0; i < wrapIds.length; i++){
            el = document.getElementById(wrapIds[i]);
            if(el){ el.style.display = show ? '' : 'none'; }
        }
        for(i = 0; i < fieldIds.length; i++){
            el = document.getElementById(fieldIds[i]);
            if(!el){ continue; }
            if(show){
                el.disabled = false;
                if(el.getAttribute('data-was-required') === '1'){ el.required = true; }
            } else {
                if(el.required){ el.setAttribute('data-was-required', '1'); }
                el.required = false;
                el.disabled = true;
                if(el.tagName === 'SELECT'){ el.selectedIndex = 0; } else { el.value = ''; }
                /* Clear a stale jQuery Validate error badge so a hidden field
                   cannot leave a red outline behind on the collapsed group. */
                if(window.jQuery){ jQuery(el).removeClass('is-invalid'); }
            }
        }
    }

    /* The value of whichever radio in the group is checked, or '' when none is
       -- a brand-new case, where every dependent group must start collapsed. */
    function caseCheckedVal(name){
        var els = document.getElementsByName(name);
        for(var i = 0; i < els.length; i++){
            if(els[i].checked){ return els[i].value; }
        }
        return '';
    }

    /* Bring every conditional group into line with the answer already on the
       form. Runs on load, so it covers a fresh entry, an edit that arrives
       with stored answers, and stepping back to an earlier step. The server
       also emits the matching display style via bdsis_case_cond_hide(), so this
       with the first paint rather than correcting it. */
    function caseInitConditionals(){
        enable_foodDesc(caseCheckedVal('food_consumed'));
        enable_THistory(caseCheckedVal('travel_his'));
        enable_vaccine(caseCheckedVal('rota_vaccine'));
        enableOthers(caseCheckedVal('oth'));
    }

    /* The four trigger handlers. Names are unchanged -- every onchange in the
       markup calls them, and those attributes were not touched. */
    function enable_THistory(val){
        caseSetCondGroup(val === 'Yes',
            ['travelPlaceWrap', 'travelDateWrap'], ['travel_place', 'travel_date']);
    }

    function enable_foodDesc(val){
        caseSetCondGroup(val === 'Yes', ['foodNameWrap'], ['food_name']);
    }

    /* Immunization History: vaccine type / date / source only apply when the
       Rota vaccine was received ('1' = Yes). */
    function enable_vaccine(val){
        caseSetCondGroup(val === '1',
            ['vaccineTypeWrap', 'vaccineDateWrap', 'vaccineSourceWrap'],
            ['vaccine_type', 'vaccine_last_date', 'vaccine_info_source']);
    }

    /* rcdcSyncRequired() and enableSDtls() were the RCDC-only helpers wired to
       the removed Sample Information (RCDC) card. They referenced ids
       (rcdcIntake, receiveby, dosr, ssts1, ssts2, dtls) that no longer exist,
       so both are gone. Everything else in this block is untouched. */

    function enableOthers(val){
        caseSetCondGroup(val === '1', ['othersWrap'], ['others']);
    }

    function confirmDelete() {
        if (confirm("Do you really want to DELETE this record?") == true) { return true; }
        else { return false; }
    }
</script>
<!-- Page footer -->

<?php require_once("../includes/footer.php"); ?>
