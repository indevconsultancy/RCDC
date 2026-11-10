<?php
/**
 * mNEQAS — shared mPDF bootstrap used by every PDF report.
 *
 * - Loads the Composer-managed mPDF (vendor/, mpdf/mpdf ^8.3). The older copy in
 *   mpdf/vendor (8.1.4) is not PHP 8.3+ compatible: its _getObjAttr() passes the
 *   trailing object marker to unserialize(), which raises
 *   "unserialize(): Extra data starting at offset ..." on PHP >= 8.3.
 * - Gives mPDF a writable temp/font-cache directory outside the web root.
 * - Builds the whole document in memory before any header is sent, so the
 *   download is never aborted with "Data has already been sent to output".
 */
if (!function_exists('mn_pdf_create')) {

    require_once __DIR__ . '/../vendor/autoload.php';

    /** Writable directory for mPDF temp files and the font cache. */
    function mn_pdf_temp_dir()
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mneqas-mpdf';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (is_dir($dir) && is_writable($dir)) { return $dir; }
        // Fallback: mPDF's own tmp folder inside the package.
        return __DIR__ . '/../vendor/mpdf/mpdf/tmp';
    }

    /** new \Mpdf\Mpdf with the report's own settings plus the shared temp dir. */
    function mn_pdf_create(array $config)
    {
        if (!isset($config['tempDir'])) { $config['tempDir'] = mn_pdf_temp_dir(); }
        return new \Mpdf\Mpdf($config);
    }

    /**
     * Stored rich-text fragments (e.g. blinded findings built from
     * blinded_finding_master items) can hold bare <li> items with no <ul>/<ol>.
     * Browsers tolerate that; mPDF's list handling does not. Wrap each run of
     * bare items in a <ul>; fragments that already contain a list are unchanged.
     */
    function mn_pdf_list_fragment($html)
    {
        $html = (string)$html;
        if (stripos($html, '<li') === false || preg_match('/<(ul|ol)\b/i', $html)) { return $html; }
        $out = preg_replace('#(?:\s*<li\b.*?</li>)+#is', '<ul>$0</ul>', $html);
        return $out === null ? $html : $out;
    }

    /**
     * Finish the document, discard any page output buffered while building the
     * report, then send the PDF as a download.
     */
    function mn_pdf_download(\Mpdf\Mpdf $mpdf, $fileName)
    {
        $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);   // renders the document
        while (ob_get_level() > 0) { ob_end_clean(); }
        $mpdf->Output($fileName, \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    }
}
