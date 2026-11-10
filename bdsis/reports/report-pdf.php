<?php
	require_once("../config/session.php");
    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php");
    require_once("../includes/lab-helpers.php");
    require_once("../includes/rbac.php");
    /* A report exposes the full clinical and laboratory record, so it is authorised
       exactly like the record itself. Without this a facility user could read any
       hospital's patient simply by changing ?sid= -- subject ids are sequential. */
    bdsis_require_can('print', null, 'reports');
    if(!isset($_GET["sid"]) OR $_GET["sid"] === ""){ bdsis_deny('No report record was specified.'); }
    bdsis_require_subject($bdsis, $_GET["sid"]);
    require_once("report-html-builder.php");

    $sid=$bdsis->real_escape_string($_GET["sid"]);
    $rpt=isset($_GET["rpt"]) ? $_GET["rpt"] : 'All';

    /* The whole per-subject body (logo, patient header, the requested result
       sections and the signature block) is built by the shared builder, which
       reports/report-group-pdf.php also uses so the two stay in step. */
    /* Optional Analyst / Verified By chosen on the report screen. Both are
       validated against the configured roster inside the builder, so an
       unrecognised name simply falls back to the automatic signature block.
       Permissions and the report format are unaffected. */
    /* `verifier` is accepted alongside `verified_by` so either spelling of the
       parameter works; the page sends verified_by. */
    $signatories = array(
        'analyst'  => isset($_GET["analyst"]) ? (string)$_GET["analyst"] : '',
        'verifier' => isset($_GET["verified_by"]) ? (string)$_GET["verified_by"]
                    : (isset($_GET["verifier"]) ? (string)$_GET["verifier"] : ''),
    );

    $html = bdsis_build_report_html($sid, $rpt, $bdsis, $commondb, bdsis_report_logo_html(), true, $signatories);

    require_once($_SERVER["DOCUMENT_ROOT"]."/BDSIS/mpdf/vendor/autoload.php");
	$mpdf=new \Mpdf\Mpdf([
		'mode' => 'utf-8',
		'format' => 'A4',
		'default_font_size' => 11,
		'default_font' => '',
		'margin_left' => 15,
		'margin_right' => 15,
		'margin_top' => 20,
		'margin_bottom' => 20,
		'margin_header' => 10,
		'margin_footer' => 5,
		'orientation' => 'P',
	]);
	$mpdf->mirrorMargins = 1;
	$header = array (
		'C' => array (
		  'content' => 'AMR Summary',
		  'font-size' => 12,
		  'font-style' => 'B',
		  'font-family' => 'arial',
		  'color'=>'#333333'
		)
	);
	$footer = array (
		'C' => array (
		  'content' => '- {PAGENO} - | Bhutan Diarrheal Study Information System(BDSIS) | localhost',
		  'font-size' => 8,
		  'font-style' => '',
		  'font-family' => 'arial',
		  'color'=>'#333333'
		),
		'line' => 1,
	);

	$mpdf->SetTitle('BDSIS AMR Summary');
	// $mpdf->SetHTMLHeader('<img src="'.$_SERVER["DOCUMENT_ROOT"].'/FluCovid/images/header.jpg"/>','E');
	// $mpdf->SetHTMLHeader('<img src="'.$_SERVER["DOCUMENT_ROOT"].'/FluCovid/images/header.jpg"/>','O');
	$mpdf->SetHeader($header,'O');
	$mpdf->SetHeader($header,'E');
	$mpdf->SetFooter($footer,'O');
	$mpdf->SetFooter($footer,'E');
	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	$mpdf->Output($sid."_Lab_Report.pdf", "I");
	exit;
?>
