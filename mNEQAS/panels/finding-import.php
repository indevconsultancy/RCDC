<?php
/**
 * Findings library — one-time Excel import for Microscopy / RDT Panel Testing.
 * ---------------------------------------------------------------------------
 * Seeds panel_finding_master from a supplied .xlsx. The sheet is read as two
 * columns: A = Finding, B = Recommendation (optional). A sheet title row, the
 * column header row and blank rows are skipped; wording, punctuation,
 * percentages and XXX/XX placeholders are kept exactly as typed, with only
 * surrounding whitespace trimmed.
 *
 * Nothing is written until the import is confirmed: the first pass is always a
 * dry run that reports what would happen. Rows are matched on module + finding
 * text, so importing the same sheet twice adds nothing the second time — the
 * import is safe to repeat and safe to abandon.
 *
 * RCDC/Administrator only, the same rule that governs managing the library in
 * panels/finding-master.php. This is a seeding tool, not part of the feedback
 * workflow, and can be removed once the master data is in place.
 */
require_once("../config/session.php");
require_once("../config/auth.php");
require_once("../config/db-config.php");

if (!mn_is_rcdc()) { mn_deny('Only RCDC/Administrator may import the findings library.'); }

$module  = isset($_REQUEST['module']) ? $_REQUEST['module'] : '';
$module  = ($module === 'rdt') ? 'rdt' : (($module === 'micro') ? 'micro' : '');
$confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';
$moduleName = ($module === 'rdt') ? 'RDT Panel Testing' : 'Microscopy Panel Testing';

/* ---------------------------------------------------------------------------
   Read an .xlsx with nothing but ZipArchive + DOM: the first sheet's XML plus
   the shared-string table. No package is pulled in for a one-off import.
--------------------------------------------------------------------------- */
function pfiReadSheet($path) {
    $z = new ZipArchive();
    if ($z->open($path) !== true) { return array('err' => 'That file could not be opened as an Excel (.xlsx) workbook.'); }

    $shared = array();
    $sx = $z->getFromName('xl/sharedStrings.xml');
    if ($sx !== false) {
        $d = new DOMDocument();
        if (@$d->loadXML($sx)) {
            foreach ($d->getElementsByTagName('si') as $si) {
                $t = '';
                foreach ($si->getElementsByTagName('t') as $node) { $t .= $node->nodeValue; }
                $shared[] = $t;
            }
        }
    }

    $xml = $z->getFromName('xl/worksheets/sheet1.xml');
    if ($xml === false) { $z->close(); return array('err' => 'The workbook has no first worksheet.'); }
    $d = new DOMDocument();
    if (!@$d->loadXML($xml)) { $z->close(); return array('err' => 'The worksheet could not be read.'); }

    $rows = array();
    foreach ($d->getElementsByTagName('row') as $row) {
        $cells = array();
        foreach ($row->getElementsByTagName('c') as $c) {
            $col = preg_replace('/\d/', '', $c->getAttribute('r'));
            $t   = $c->getAttribute('t');
            $v   = '';
            $is  = $c->getElementsByTagName('is')->item(0);
            $vn  = $c->getElementsByTagName('v')->item(0);
            if ($t === 'inlineStr' && $is) { $v = $is->textContent; }
            elseif ($vn) { $v = ($t === 's') ? (isset($shared[(int)$vn->nodeValue]) ? $shared[(int)$vn->nodeValue] : '') : $vn->nodeValue; }
            $cells[$col] = $v;
        }
        $rows[(int)$row->getAttribute('r')] = $cells;
    }
    $z->close();
    return array('rows' => $rows);
}

/* Collapse the runs of spaces spreadsheet cells tend to carry, without
   touching the wording itself. */
function pfiTidy($v) {
    $v = str_replace("\xC2\xA0", ' ', (string)$v);          // non-breaking space
    $v = preg_replace('/[ \t]+/', ' ', $v);
    return trim($v);
}

/* ---------------------------------------------------------------------------
   Categories. Present only once the 2026-09-30 panel-finding-categories
   migration has run; without it the importer still works and every finding
   lands in the default category, exactly as it did before.
--------------------------------------------------------------------------- */
function pfiHasCategoryColumn($conn) {
    $q = @$conn->query("SHOW COLUMNS FROM panel_finding_master LIKE 'category'");
    return ($q && $q->num_rows > 0);
}
function pfiLoadCategories($conn, $module) {
    $out = array();
    $chk = @$conn->query("SHOW TABLES LIKE 'panel_finding_categories'");
    if (!$chk || $chk->num_rows === 0) { return $out; }
    $st = $conn->prepare("SELECT cat_key, name FROM panel_finding_categories
                          WHERE module = ? AND status = 1 ORDER BY sort_order, name");
    if (!$st) { return $out; }
    $st->bind_param('s', $module);
    $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) { $out[$r['cat_key']] = $r['name']; }
    $st->close();
    return $out;
}
/* Reduce a phrase to letters+digits so "Malaria Species Identification" and
   "malaria species identification is %" compare equal. */
function pfiNorm($v) {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$v));
}
/* A section header looks like "Performance score in <category> is <score> %".
   The captured phrase is matched against the module's category NAMES; an
   unmatched phrase simply leaves the current category unchanged. */
function pfiSectionCategory($finding, array $cats) {
    if (!preg_match('/^\s*performance\s+score\s+in\s+(.+?)\s+is\b/i', $finding, $m)) { return null; }
    $want = pfiNorm($m[1]);
    if ($want === '') { return null; }
    foreach ($cats as $key => $name) {
        $n = pfiNorm($name);
        if ($n === $want || strpos($want, $n) !== false || strpos($n, $want) !== false) { return $key; }
    }
    return null;
}

$report = null;
$fatal  = '';

if ($module !== '' && isset($_FILES['workbook']) && $_FILES['workbook']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['workbook']['error'] !== UPLOAD_ERR_OK) {
        $fatal = 'The upload did not complete. Please try again.';
    } elseif (!is_uploaded_file($_FILES['workbook']['tmp_name'])) {
        $fatal = 'Invalid upload.';
    } else {
        $read = pfiReadSheet($_FILES['workbook']['tmp_name']);
        if (isset($read['err'])) {
            $fatal = $read['err'];
        } else {
            /* ---- interpret the sheet ---- */
            $parsed = array();                 // finding => recommendation, first occurrence wins
            $stats  = array('scanned' => 0, 'blank' => 0, 'headers' => 0, 'dupes' => 0, 'noRec' => 0);
            $catMap   = pfiLoadCategories($conn, $module);
            $hasCatCol = pfiHasCategoryColumn($conn);
            $curCat   = 'general';          // until the first section header
            foreach ($read['rows'] as $rNo => $cells) {
                $stats['scanned']++;
                $finding = pfiTidy(isset($cells['A']) ? $cells['A'] : '');
                $rec     = pfiTidy(isset($cells['B']) ? $cells['B'] : '');

                if ($finding === '' && $rec === '') { $stats['blank']++; continue; }

                // The sheet's own title row and column headings are not findings.
                $low = strtolower($finding);
                if ($low === 'findings' || $low === 'finding' || $low === 'recommendations'
                    || strtolower($rec) === 'recommendations'
                    || ($rec === '' && preg_match('/^(rdt|micro(scopy)?)\s*panel$/i', $finding))) {
                    $stats['headers']++;
                    continue;
                }
                if ($finding === '') { $stats['blank']++; continue; }   // a recommendation with no finding

                /* A "Performance score in X is …" row both opens a section AND is
                   itself a finding, so the category is set before the row is kept. */
                $sect = pfiSectionCategory($finding, $catMap);
                if ($sect !== null) { $curCat = $sect; }

                if (isset($parsed[$finding])) {
                    $stats['dupes']++;
                    // Keep a recommendation the first occurrence happened to lack.
                    if ($parsed[$finding]['rec'] === '' && $rec !== '') { $parsed[$finding]['rec'] = $rec; }
                    continue;
                }
                $parsed[$finding] = array('rec' => $rec, 'cat' => $curCat);
            }
            foreach ($parsed as $p) { if ($p['rec'] === '') { $stats['noRec']++; } }

            /* ---- what the library already holds ---- */
            $have = $conn->prepare("SELECT finding_id, title FROM panel_finding_master WHERE module = ?");
            if (!$have) {
                $fatal = 'The findings library table (panel_finding_master) was not found in this database.';
            } else {
                $existing = array();
                $have->bind_param('s', $module);
                $have->execute();
                $rs = $have->get_result();
                while ($row = $rs->fetch_assoc()) { $existing[pfiTidy($row['title'])] = (int)$row['finding_id']; }
                $have->close();

                $toInsert = array();
                $already  = array();
                foreach ($parsed as $finding => $p) {
                    if (isset($existing[$finding])) { $already[] = $finding; }
                    else { $toInsert[$finding] = $p; }
                }

                $inserted = 0;
                if ($confirm && count($toInsert)) {
                    /* All or nothing: a half-seeded library is worse than none. */
                    $conn->begin_transaction();
                    try {
                        $sort = 0;
                        $max = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM panel_finding_master WHERE module = ?");
                        $max->bind_param('s', $module);
                        $max->execute();
                        $max->bind_result($sort);
                        $max->fetch();
                        $max->close();
                        $sort = (int)$sort;

                        $now = date('Y-m-d H:i:s');
                        /* The category column only exists after the 2026-09-30 migration. */
                        $st = $hasCatCol
                            ? $conn->prepare("INSERT INTO panel_finding_master
                                    (module, title, category, finding_content, recommendation_content, status, sort_order, created_at, updated_at)
                                    VALUES (?,?,?,?,?,1,?,?,?)")
                            : $conn->prepare("INSERT INTO panel_finding_master
                                    (module, title, finding_content, recommendation_content, status, sort_order, created_at, updated_at)
                                    VALUES (?,?,?,?,1,?,?,?)");
                        foreach ($toInsert as $finding => $p) {
                            $rec = $p['rec'];
                            $sort += 10;
                            // Stored as the sheet worded it; the editors expect HTML.
                            $fc = '<p>' . htmlspecialchars($finding, ENT_QUOTES, 'UTF-8') . '</p>';
                            $rc = ($rec === '') ? '' : '<p>' . htmlspecialchars($rec, ENT_QUOTES, 'UTF-8') . '</p>';
                            $title = (mb_strlen($finding) > 255) ? mb_substr($finding, 0, 255) : $finding;
                            if ($hasCatCol) {
                                $cat = $p['cat'];
                                $st->bind_param('sssssiss', $module, $title, $cat, $fc, $rc, $sort, $now, $now);
                            } else {
                                $st->bind_param('ssssiss', $module, $title, $fc, $rc, $sort, $now, $now);
                            }
                            $st->execute();
                            $inserted++;
                        }
                        $st->close();
                        $conn->commit();
                    } catch (\Throwable $e) {
                        $conn->rollback();
                        $inserted = 0;
                        $fatal = 'The import was rolled back and nothing was saved: ' . $e->getMessage();
                    }
                }

                $report = array(
                    'catMap'  => $catMap,  'hasCatCol' => $hasCatCol,
                    'stats'   => $stats,   'parsed'  => $parsed,
                    'toInsert'=> $toInsert,'already' => $already,
                    'inserted'=> $inserted,'confirmed' => $confirm,
                    'file'    => $_FILES['workbook']['name'],
                );
            }
        }
    }
}

require_once("../includes/header.php");
require_once("../includes/css-jquery.php");
?>
<div class="wrapper">
  <?php require_once("../includes/top-navbar.php"); ?>
  <?php require_once("../includes/sidebar-menu.php"); ?>
  <div class="content-wrapper">
    <section class="content pt-3">
      <div class="container-fluid">
        <div class="card card-outline card-primary">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-file-excel"></i>&nbsp; Import Findings Library from Excel</h3>
          </div>
          <div class="card-body">

            <?php if ($fatal !== '') { ?>
              <div class="alert alert-danger"><?= htmlspecialchars($fatal, ENT_QUOTES) ?></div>
            <?php } ?>

            <form method="post" enctype="multipart/form-data" class="form-horizontal">
              <div class="form-group row">
                <label class="col-sm-3 col-form-label-sm">Module</label>
                <div class="col-sm-9">
                  <select name="module" class="form-control" required>
                    <option value="">-- Select module --</option>
                    <option value="rdt"   <?= $module === 'rdt'   ? 'selected' : '' ?>>RDT Panel Testing</option>
                    <option value="micro" <?= $module === 'micro' ? 'selected' : '' ?>>Microscopy Panel Testing</option>
                  </select>
                  <small class="text-muted">Findings are held separately per module and are never offered in the other one.</small>
                </div>
              </div>
              <div class="form-group row">
                <label class="col-sm-3 col-form-label-sm">Excel file (.xlsx)</label>
                <div class="col-sm-9">
                  <input type="file" name="workbook" class="form-control-file" accept=".xlsx" required>
                  <small class="text-muted">First sheet, column A = Finding, column B = Recommendation (may be empty).</small>
                </div>
              </div>
              <button type="submit" class="btn bg-gradient-primary">
                <i class="fas fa-search"></i> Check the file
              </button>
              <a href="panel-list.php" class="btn bg-gradient-secondary">Back</a>
            </form>

            <?php if ($report) { ?>
              <hr>
              <h5 class="mb-2"><?= $report['confirmed'] ? 'Import result' : 'Dry run &mdash; nothing has been saved yet' ?></h5>
              <p class="text-muted mb-2">
                <?= htmlspecialchars($report['file'], ENT_QUOTES) ?> &rarr; <?= htmlspecialchars($moduleName, ENT_QUOTES) ?>
              </p>
              <table class="table table-sm table-bordered w-auto">
                <tr><th>Rows scanned</th><td><?= (int)$report['stats']['scanned'] ?></td></tr>
                <tr><th>Title / header rows skipped</th><td><?= (int)$report['stats']['headers'] ?></td></tr>
                <tr><th>Blank rows skipped</th><td><?= (int)$report['stats']['blank'] ?></td></tr>
                <tr><th>Duplicate findings within the sheet</th><td><?= (int)$report['stats']['dupes'] ?></td></tr>
                <tr><th>Distinct findings in the sheet</th><td><?= count($report['parsed']) ?></td></tr>
                <tr><th>Findings without a recommendation</th><td><?= (int)$report['stats']['noRec'] ?></td></tr>
                <tr><th>Already in the library (skipped)</th><td><?= count($report['already']) ?></td></tr>
                <?php if ($report['hasCatCol'] && count($report['toInsert'])) {
                      $byCat = array();
                      foreach ($report['toInsert'] as $p) {
                          $k = $p['cat'];
                          $byCat[$k] = isset($byCat[$k]) ? $byCat[$k] + 1 : 1;
                      } ?>
                  <tr>
                    <th>By category</th>
                    <td>
                      <?php foreach ($byCat as $k => $c) {
                            $lbl = isset($report['catMap'][$k]) ? $report['catMap'][$k] : $k; ?>
                        <span class="badge badge-light border mr-1"><?= htmlspecialchars($lbl, ENT_QUOTES) ?>: <?= (int)$c ?></span>
                      <?php } ?>
                    </td>
                  </tr>
                <?php } ?>
                <tr class="table-primary">
                  <th><?= $report['confirmed'] ? 'Imported' : 'Would be imported' ?></th>
                  <td><strong><?= $report['confirmed'] ? (int)$report['inserted'] : count($report['toInsert']) ?></strong></td>
                </tr>
              </table>

              <?php if (count($report['toInsert'])) { ?>
                <table class="table table-sm table-bordered">
                  <thead><tr><th style="width:44px;">#</th><th style="width:170px;">Category</th><th>Finding</th><th>Recommendation</th></tr></thead>
                  <tbody>
                  <?php $i = 0; foreach ($report['toInsert'] as $fTxt => $pRow) { $i++;
                        $rTxt = $pRow['rec'];
                        $cLbl = isset($report['catMap'][$pRow['cat']]) ? $report['catMap'][$pRow['cat']] : $pRow['cat']; ?>
                    <tr>
                      <td><?= $i ?></td>
                      <td><span class="badge badge-light border"><?= htmlspecialchars($cLbl, ENT_QUOTES) ?></span></td>
                      <td><?= htmlspecialchars($fTxt, ENT_QUOTES) ?></td>
                      <td><?= $rTxt === '' ? '<span class="text-muted">&mdash;</span>' : htmlspecialchars($rTxt, ENT_QUOTES) ?></td>
                    </tr>
                  <?php } ?>
                  </tbody>
                </table>
              <?php } elseif (!$report['confirmed']) { ?>
                <div class="alert alert-info">Every finding in this sheet is already in the library &mdash; there is nothing to import.</div>
              <?php } ?>

              <?php if ($report['confirmed']) { ?>
                <div class="alert alert-success">
                  <?= (int)$report['inserted'] ?> finding(s) added to <?= htmlspecialchars($moduleName, ENT_QUOTES) ?>.
                  They are selectable in the Feedback form now, and editable under Manage Findings.
                </div>
              <?php } elseif (count($report['toInsert'])) { ?>
                <form method="post" enctype="multipart/form-data" class="mt-2">
                  <input type="hidden" name="module" value="<?= htmlspecialchars($module, ENT_QUOTES) ?>">
                  <input type="hidden" name="confirm" value="yes">
                  <div class="form-group">
                    <label class="col-form-label-sm">Attach the same file again to confirm</label>
                    <input type="file" name="workbook" class="form-control-file" accept=".xlsx" required>
                  </div>
                  <button type="submit" class="btn bg-gradient-success">
                    <i class="fas fa-database"></i> Import these <?= count($report['toInsert']) ?> finding(s)
                  </button>
                </form>
              <?php } ?>
            <?php } ?>

          </div>
        </div>
      </div>
    </section>
  </div>
</div>
<?php require_once("../includes/footer.php"); ?>
