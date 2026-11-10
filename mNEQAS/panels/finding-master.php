<?php
/**
 * Findings library — shared JSON endpoint for Microscopy Panel Testing and
 * RDT Panel Testing feedback.
 * ---------------------------------------------------------------------------
 * One managed library (panel_finding_master) scoped by `module`:
 *      micro -> Microscopy Panel Testing
 *      rdt   -> RDT Panel Testing
 * The two modules score and generate their findings differently, but a finding
 * preset is the same shape in both (title + finding content + recommendation),
 * so they share this table and this endpoint instead of duplicating both.
 * Modelled on the Findings master already used by Blinded Rechecking.
 *
 * Actions (GET or POST): list | content | save | delete | cat_list | cat_save | cat_delete
 *
 * Categories (panel_finding_categories, or mneqas_finding_categories if that is
 * the feedback editors can head each block with the category name. They are
 * optional everywhere: on a database without the 2026-09-30 migration, and for
 * a module with no categories seeded, every response falls back to the single
 * implicit category "general" and the output is unchanged.
 * Reading is open to anyone who may open the feedback page; managing the
 * library is RCDC/Administrator only, exactly like entering Findings.
 */
require_once("../config/session.php");
require_once("../config/auth.php");
require_once("../config/db-config.php");

function pfmJson(array $out) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

/* Does panel_finding_master carry the category column yet? */
function pfmHasCat($conn) {
    static $has = null;
    if ($has === null) {
        /* The findings table of the module in play - its own after the split,
           the shared one before it. */
        $q   = @$conn->query("SHOW COLUMNS FROM " . pfmFindTable($conn) . " LIKE 'category'");
        $has = ($q && $q->num_rows > 0);
    }
    return $has;
}
/* The database the application is actually connected to, for the message above:
   the usual cause of a missing table is a migration run on the wrong one. */
function pfmDbName($conn) {
    $q = @$conn->query("SELECT DATABASE()");
    $r = $q ? $q->fetch_row() : null;
    return ($r && $r[0] !== null && $r[0] !== '') ? (string)$r[0] : 'this database';
}

/* The categories table, or '' when this database has neither name.

   panel_finding_categories is the name this module has always used and the one
   blinded_finding_categories is modelled on. mneqas_finding_categories is
   accepted as well because the specification named it, so a table created
   under either name is picked up without a rename or a code change.

   Both are fixed literals, so interpolating the result into SQL cannot carry
   anything from a request. Resolved once per request. */
/* The module of the request in progress, for helpers called before the
   local $module is in scope. */
function pfmModule() {
    if (defined('PFM_FIXED_MODULE')) { return PFM_FIXED_MODULE; }
    $m = isset($_POST['module']) ? $_POST['module'] : (isset($_GET['module']) ? $_GET['module'] : '');
    return ($m === 'rdt') ? 'rdt' : 'micro';
}

function pfmHasTable($conn, $name) {
    static $seen = array();
    if (array_key_exists($name, $seen)) { return $seen[$name]; }
    $q = @$conn->query("SHOW TABLES LIKE '" . $name . "'");
    $seen[$name] = ($q && $q->num_rows > 0);
    return $seen[$name];
}

/* Both tables for one module, as array(findings, categories).

   RDT and Microscopy each own their pair - rdt_panel_finding_* and
   micro_panel_finding_* - so neither module can read or write the other's
   library at all. Until the split migration has run those tables do not
   exist, and each falls back to the shared pair it used before, which is why
   this is safe to deploy ahead of the migration.

   Every name below is a fixed literal chosen from $module, which is itself
   whitelisted to micro|rdt, so nothing from a request reaches SQL. */
function pfmTables($conn, $module) {
    static $cache = array();
    if (isset($cache[$module])) { return $cache[$module]; }

    $own = ($module === 'rdt') ? 'rdt_panel_finding' : 'micro_panel_finding';

    $find = pfmHasTable($conn, $own . '_master') ? $own . '_master' : 'panel_finding_master';

    $cats = '';
    if (pfmHasTable($conn, $own . '_categories')) {
        $cats = $own . '_categories';
    } else {
        /* The shared pair, including the name the specification once used. */
        foreach (array('panel_finding_categories', 'mneqas_finding_categories') as $cand) {
            if (pfmHasTable($conn, $cand)) { $cats = $cand; break; }
        }
    }

    $cache[$module] = array('findings' => $find, 'categories' => $cats);
    return $cache[$module];
}

/* Kept so existing call sites read unchanged. */
function pfmCatTable($conn, $module = null) {
    if ($module === null) { $module = pfmModule(); }
    $t = pfmTables($conn, $module);
    return $t['categories'];
}
function pfmFindTable($conn, $module = null) {
    if ($module === null) { $module = pfmModule(); }
    $t = pfmTables($conn, $module);
    return $t['findings'];
}

/* cat_key => name for one module, in display order. Empty when the module has
   no categories, which is what keeps Microscopy on its existing behaviour. */
function pfmCats($conn, $module) {
    static $cache = array();
    if (isset($cache[$module])) { return $cache[$module]; }
    $out = array();
    $catTable = pfmCatTable($conn, $module);
    if ($catTable !== '') {
        $st = $conn->prepare("SELECT cat_key, name FROM $catTable
                              WHERE module = ? AND status = 1 ORDER BY sort_order, name");
        if ($st) {
            $st->bind_param('s', $module);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) { $out[$r['cat_key']] = $r['name']; }
            $st->close();
        }
    }
    $cache[$module] = $out;
    return $out;
}
/* Every category of the module, active or not, as cat_key => name. Findings
   keep the category they were filed under when it is deactivated, so this is
   what names a heading and what decides whether a stored value is still valid.
   pfmCats() stays active-only: that is the list a finding may be FILED under. */
function pfmAllCats($conn, $module) {
    static $cache = array();
    if (isset($cache[$module])) { return $cache[$module]; }
    $out = array();
    $catTable = pfmCatTable($conn, $module);
    if ($catTable !== '') {
        $st = $conn->prepare("SELECT cat_key, name FROM $catTable
                              WHERE module = ? ORDER BY sort_order, name");
        if ($st) {
            $st->bind_param('s', $module);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) { $out[$r['cat_key']] = $r['name']; }
            $st->close();
        }
    }
    $cache[$module] = $out;
    return $out;
}
/* The list of categories as the browser wants it, ordered. */
function pfmCatList($conn, $module) {
    $rows = array();
    foreach (pfmCats($conn, $module) as $k => $n) { $rows[] = array('key' => $k, 'name' => $n); }
    return $rows;
}
/* Keep only a category this module actually has; anything else is general. A
   deactivated one is accepted so editing an older finding does not silently
   move it out of its group. */
function pfmCleanCat($conn, $module, $cat) {
    $cat  = trim((string)$cat);
    $cats = pfmAllCats($conn, $module);
    if ($cat === '' || !isset($cats[$cat])) { return 'general'; }
    return $cat;
}

$act    = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
/* The module this request belongs to.

   A module-owned endpoint (rdt-panels/rdt-finding-master.php,
   panels/micro-finding-master.php) defines PFM_FIXED_MODULE before including
   this engine, so the module is a property of the code path and cannot be
   switched by a request parameter. Reached directly, it still accepts the
   parameter as before so nothing that already calls it breaks. */
$module = defined('PFM_FIXED_MODULE') ? PFM_FIXED_MODULE
        : (isset($_POST['module']) ? $_POST['module'] : (isset($_GET['module']) ? $_GET['module'] : ''));
$module = ($module === 'rdt') ? 'rdt' : (($module === 'micro') ? 'micro' : '');
if ($module === '') { pfmJson(array('ok' => false, 'msg' => 'Unknown module.')); }

/* Resolved once; every statement below uses these two names. */
$pfmTbl   = pfmTables($conn, $module);
$tblFind  = $pfmTbl['findings'];
$catTable = $pfmTbl['categories'];

try {
    /* ---- list (read-only; any signed-in user of the module) ---- */
    if ($act === 'list') {
        $rows = array();
        /* Without the migration the column is synthesised, so every consumer can
           read row.category unconditionally. */
        $catCol = pfmHasCat($conn) ? 'category' : "'general' AS category";
        $st = $conn->prepare("SELECT finding_id, title, $catCol, finding_content, recommendation_content, status, sort_order
                              FROM $tblFind WHERE module = ?
                              ORDER BY sort_order, finding_id");
        $st->bind_param('s', $module);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) { $rows[] = $r; }
        $st->close();
        pfmJson(array(
            'ok'        => true,
            'rows'      => $rows,
            'canManage' => mn_is_rcdc(),
            'cats'      => pfmCatList($conn, $module),   // [] = this module has none
            'catNames'  => pfmAllCats($conn, $module),   // includes deactivated ones, for headings
            /* Is category management available at all on this database? The client
               hides the Manage Categories button when it is not, the way Blinded
               Rechecking does, rather than offering a control whose every action
               would fail. Independent of whether this MODULE has any categories
               yet: the table can exist with none seeded for this module. */
            'catsDynamic' => ($catTable !== ''),
        ));
    }

    /* ---- content for the selected ids, in the order asked for ---- */
    if ($act === 'content') {
        $raw = isset($_GET['ids']) ? (string)$_GET['ids'] : (isset($_POST['ids']) ? (string)$_POST['ids'] : '');
        $ids = array();
        foreach (explode(',', $raw) as $v) {
            $v = (int)trim($v);
            if ($v > 0 && !in_array($v, $ids, true)) { $ids[] = $v; }   // de-duplicate
        }
        $rows = array();
        if (count($ids)) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $types = 's' . str_repeat('i', count($ids));
            $args  = array_merge(array($module), $ids);
            $catCol = pfmHasCat($conn) ? 'category' : "'general' AS category";
            $st = $conn->prepare("SELECT finding_id, title, $catCol, finding_content, recommendation_content
                                  FROM $tblFind
                                  WHERE module = ? AND status = 1 AND finding_id IN ($place)");
            $st->bind_param($types, ...$args);
            $st->execute();
            $rs = $st->get_result();
            $byId = array();
            while ($r = $rs->fetch_assoc()) { $byId[(int)$r['finding_id']] = $r; }
            $st->close();
            foreach ($ids as $fid) {
                if (!isset($byId[$fid])) { continue; }          // inactive or deleted: skipped
                $rows[] = array(
                    'finding_id'     => $fid,
                    'title'          => $byId[$fid]['title'],
                    'category'       => (string)$byId[$fid]['category'],
                    'finding'        => (string)$byId[$fid]['finding_content'],
                    'recommendation' => (string)$byId[$fid]['recommendation_content'],
                );
            }
        }
        pfmJson(array('ok' => true, 'rows' => $rows,
                      'cats' => pfmCatList($conn, $module),
                      'catNames' => pfmAllCats($conn, $module)));
    }

    /* ================= CATEGORY MANAGEMENT =================
       Scoped by module throughout: panel_finding_categories is shared by
       Microscopy and RDT, so a category belongs to exactly one of them. */
    if ($act === 'cat_list' || $act === 'cat_save' || $act === 'cat_delete') {
        /* $catTable was resolved once for this module at the top of the
           request. Say what to do about it when there is none: the reader of
           this message is the person who can run the migration. */
        if ($catTable === '') {
            pfmJson(array('ok' => false, 'msg' =>
                'Categories are not available on this database yet. Run '
              . '2026-10-05-panel-finding-categories.sql on ' . pfmDbName($conn)
              . ' to create the panel_finding_categories table, then reopen this screen.'));
        }

        /* Reading the list is open to anyone who may read the library. */
        if ($act === 'cat_list') {
            $rows = array();
            $st = $conn->prepare("SELECT category_id, cat_key, name, description, status, sort_order
                                  FROM $catTable WHERE module = ?
                                  ORDER BY sort_order, name");
            $st->bind_param('s', $module);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) {
                /* How many findings of this module currently use it — the UI warns
                   before a delete that would orphan them. */
                $r['in_use'] = 0;
                $us = $conn->prepare("SELECT COUNT(*) FROM $tblFind WHERE module = ? AND category = ?");
                if ($us) {
                    $us->bind_param('ss', $module, $r['cat_key']);
                    $us->execute();
                    $ur = $us->get_result()->fetch_row();
                    $us->close();
                    $r['in_use'] = $ur ? (int)$ur[0] : 0;
                }
                $rows[] = $r;
            }
            $st->close();
            pfmJson(array('ok' => true, 'rows' => $rows, 'canManage' => mn_is_rcdc()));
        }

        /* Changing them is RCDC/Administrator only, like the findings themselves. */
        if (!mn_is_rcdc()) { pfmJson(array('ok' => false, 'msg' => 'Only RCDC can manage finding categories.')); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pfmJson(array('ok' => false, 'msg' => 'Invalid request.')); }

        $catId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

        if ($act === 'cat_delete') {
            if ($catId <= 0) { pfmJson(array('ok' => false, 'msg' => 'Invalid category.')); }
            /* Must belong to this module. */
            $own = $conn->prepare("SELECT cat_key FROM $catTable WHERE category_id = ? AND module = ?");
            $own->bind_param('is', $catId, $module);
            $own->execute();
            $ownRow = $own->get_result()->fetch_row();
            $own->close();
            if (!$ownRow) { pfmJson(array('ok' => false, 'msg' => 'Category not found for this module.')); }
            $catKey = (string)$ownRow[0];

            /* Deletion is allowed only while nothing uses the category: a finding
               left pointing at a category that no longer exists would lose its
               heading. When it is in use the request is refused and the caller is
               told to set the status to Inactive instead, which takes it out of
               the Add/Edit dropdown while every existing finding keeps resolving. */
            $us = $conn->prepare("SELECT COUNT(*) FROM $tblFind WHERE module = ? AND category = ?");
            $us->bind_param('ss', $module, $catKey);
            $us->execute();
            $usRow = $us->get_result()->fetch_row();
            $us->close();
            $inUse = $usRow ? (int)$usRow[0] : 0;

            if ($inUse > 0) {
                pfmJson(array('ok' => false, 'in_use' => $inUse,
                              'msg' => 'This category is used by ' . $inUse . ' finding(s), so it cannot be deleted. '
                                     . 'Set its status to Inactive instead - it will no longer be offered for new '
                                     . 'findings, and the existing ones keep their heading.'));
            }
            $dl = $conn->prepare("DELETE FROM $catTable WHERE category_id = ? AND module = ?");
            $dl->bind_param('is', $catId, $module);
            $dl->execute();
            $dl->close();
            pfmJson(array('ok' => true, 'deactivated' => false));
        }

        /* ---- cat_save: add or update ---- */
        $cName = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $cDesc = trim(isset($_POST['description']) ? $_POST['description'] : '');
        $cStat = (isset($_POST['status']) && (int)$_POST['status'] === 0) ? 0 : 1;
        if ($cName === '') { pfmJson(array('ok' => false, 'msg' => 'Category name is required.')); }
        if (mb_strlen($cName) > 100) { pfmJson(array('ok' => false, 'msg' => 'Category name is too long (max 100 characters).')); }
        if (mb_strlen($cDesc) > 255) { pfmJson(array('ok' => false, 'msg' => 'Description is too long (max 255 characters).')); }

        /* Unique name WITHIN the module — Microscopy and RDT may each have their
           own "General" without colliding. */
        $dup = $conn->prepare("SELECT category_id FROM $catTable WHERE module = ? AND name = ? AND category_id <> ?");
        $dup->bind_param('ssi', $module, $cName, $catId);
        $dup->execute();
        $dup->store_result();
        $isDup = $dup->num_rows > 0;
        $dup->close();
        if ($isDup) { pfmJson(array('ok' => false, 'msg' => 'A category with that name already exists.')); }

        $now = date('Y-m-d H:i:s');
        if ($catId > 0) {
            /* The row has to belong to THIS module. The UPDATE below is scoped by
               module as well, so another module's category was never altered, but
               without this check the no-op came back as a success and the caller
               was told a save had happened. cat_delete already checks the same way. */
            $own = $conn->prepare("SELECT category_id FROM $catTable WHERE category_id = ? AND module = ?");
            $own->bind_param('is', $catId, $module);
            $own->execute();
            $own->store_result();
            $ownOk = $own->num_rows > 0;
            $own->close();
            if (!$ownOk) { pfmJson(array('ok' => false, 'msg' => 'Category not found for this module.')); }

            /* cat_key is never rewritten: panel_finding_master.category stores it,
               so changing it would orphan every finding that carries it. */
            $up = $conn->prepare("UPDATE $catTable
                                  SET name = ?, description = ?, status = ?, updated_at = ?
                                  WHERE category_id = ? AND module = ?");
            $up->bind_param('ssisis', $cName, $cDesc, $cStat, $now, $catId, $module);
            $up->execute();
            $up->close();
            pfmJson(array('ok' => true, 'category_id' => $catId));
        }

        /* A new key derived from the name, made unique within the module. */
        $base = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($cName));
        $base = trim($base, '_');
        if ($base === '') { $base = 'category'; }
        $base = mb_substr($base, 0, 56);
        $key  = $base; $n = 2;
        while (true) {
            $kq = $conn->prepare("SELECT category_id FROM $catTable WHERE module = ? AND cat_key = ?");
            $kq->bind_param('ss', $module, $key);
            $kq->execute();
            $kq->store_result();
            $taken = $kq->num_rows > 0;
            $kq->close();
            if (!$taken) { break; }
            $key = $base . '_' . $n; $n++;
        }

        $sq = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM $catTable WHERE module = ?");
        $sq->bind_param('s', $module);
        $sq->execute();
        $sRow = $sq->get_result()->fetch_row();
        $sq->close();
        $cSort = $sRow ? (int)$sRow[0] : 10;

        $ins = $conn->prepare("INSERT INTO $catTable
                               (module, cat_key, name, description, status, sort_order, created_at, updated_at)
                               VALUES (?,?,?,?,?,?,?,?)");
        $ins->bind_param('ssssiiss', $module, $key, $cName, $cDesc, $cStat, $cSort, $now, $now);
        $ins->execute();
        $newCat = $conn->insert_id;
        $ins->close();
        pfmJson(array('ok' => true, 'category_id' => $newCat, 'cat_key' => $key));
    }

    /* ---- everything below manages the library: RCDC/Administrator only ---- */
    if (!mn_is_rcdc()) { pfmJson(array('ok' => false, 'msg' => 'Only RCDC can manage the findings library.')); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pfmJson(array('ok' => false, 'msg' => 'Invalid request.')); }

    /* ---- add / update ---- */
    if ($act === 'save') {
        $fid   = isset($_POST['finding_id']) ? (int)$_POST['finding_id'] : 0;
        $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
        $fc    = isset($_POST['finding_content']) ? $_POST['finding_content'] : '';
        $rc    = isset($_POST['recommendation_content']) ? $_POST['recommendation_content'] : '';
        $stat  = (isset($_POST['status']) && (int)$_POST['status'] === 0) ? 0 : 1;
        /* The Order field was removed from the form. An existing finding keeps the
           order it already has; a new one is appended to the end of its module. */
        if (isset($_POST['sort_order']) && $_POST['sort_order'] !== '') {
            $sort = (int)$_POST['sort_order'];
        } elseif ($fid > 0) {
            $sq = $conn->prepare("SELECT sort_order FROM $tblFind WHERE finding_id = ? AND module = ?");
            $sq->bind_param('is', $fid, $module);
            $sq->execute();
            $sRow = $sq->get_result()->fetch_row();
            $sq->close();
            $sort = $sRow ? (int)$sRow[0] : 0;
        } else {
            $sq = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM $tblFind WHERE module = ?");
            $sq->bind_param('s', $module);
            $sq->execute();
            $sRow = $sq->get_result()->fetch_row();
            $sq->close();
            $sort = $sRow ? (int)$sRow[0] : 10;
        }
        $cat   = pfmCleanCat($conn, $module, isset($_POST['category']) ? $_POST['category'] : '');

        if ($title === '') { pfmJson(array('ok' => false, 'msg' => 'Finding name is required.')); }
        if (mb_strlen($title) > 255) { pfmJson(array('ok' => false, 'msg' => 'Finding name is too long (max 255 characters).')); }
        if (trim(strip_tags($fc)) === '') { pfmJson(array('ok' => false, 'msg' => 'Finding content is required.')); }

        // A title must stay unique inside its own module.
        $dup = $conn->prepare("SELECT finding_id FROM $tblFind WHERE module = ? AND title = ? AND finding_id <> ?");
        $dup->bind_param('ssi', $module, $title, $fid);
        $dup->execute();
        $dup->store_result();
        $isDup = $dup->num_rows > 0;
        $dup->close();
        if ($isDup) { pfmJson(array('ok' => false, 'msg' => 'A finding with that name already exists.')); }

        $now    = date('Y-m-d H:i:s');
        $hasCat = pfmHasCat($conn);
        if ($fid > 0) {
            if ($hasCat) {
                $st = $conn->prepare("UPDATE $tblFind
                                      SET title=?, category=?, finding_content=?, recommendation_content=?, status=?, sort_order=?, updated_at=?
                                      WHERE finding_id=? AND module=?");
                $st->bind_param('ssssiisis', $title, $cat, $fc, $rc, $stat, $sort, $now, $fid, $module);
            } else {
                $st = $conn->prepare("UPDATE $tblFind
                                      SET title=?, finding_content=?, recommendation_content=?, status=?, sort_order=?, updated_at=?
                                      WHERE finding_id=? AND module=?");
                $st->bind_param('sssiisis', $title, $fc, $rc, $stat, $sort, $now, $fid, $module);
            }
        } else {
            if ($hasCat) {
                $st = $conn->prepare("INSERT INTO $tblFind
                                      (module, title, category, finding_content, recommendation_content, status, sort_order, created_at, updated_at)
                                      VALUES (?,?,?,?,?,?,?,?,?)");
                $st->bind_param('sssssiiss', $module, $title, $cat, $fc, $rc, $stat, $sort, $now, $now);
            } else {
                $st = $conn->prepare("INSERT INTO $tblFind
                                      (module, title, finding_content, recommendation_content, status, sort_order, created_at, updated_at)
                                      VALUES (?,?,?,?,?,?,?,?)");
                $st->bind_param('ssssiiss', $module, $title, $fc, $rc, $stat, $sort, $now, $now);
            }
        }
        $st->execute();
        $newId = ($fid > 0) ? $fid : $conn->insert_id;
        $st->close();
        pfmJson(array('ok' => true, 'finding_id' => $newId, 'category' => $cat));
    }

    /* ---- delete (deactivates instead when the finding is already in use) ---- */
    if ($act === 'delete') {
        $fid = isset($_POST['finding_id']) ? (int)$_POST['finding_id'] : 0;
        if ($fid <= 0) { pfmJson(array('ok' => false, 'msg' => 'Invalid finding.')); }

        // Must belong to this module.
        $own = $conn->prepare("SELECT finding_id FROM $tblFind WHERE finding_id = ? AND module = ?");
        $own->bind_param('is', $fid, $module);
        $own->execute();
        $own->store_result();
        $mine = $own->num_rows > 0;
        $own->close();
        if (!$mine) { pfmJson(array('ok' => false, 'msg' => 'Finding not found for this module.')); }

        // Feedback already referencing it must keep resolving, so deactivate instead.
        $useTable = ($module === 'rdt') ? 'rdt_panel_feedback_findings' : 'panel_feedback_findings';
        $used = $conn->prepare("SELECT finding_id FROM $useTable WHERE finding_id = ? LIMIT 1");
        $used->bind_param('i', $fid);
        $used->execute();
        $used->store_result();
        $inUse = $used->num_rows > 0;
        $used->close();

        if ($inUse) {
            $st = $conn->prepare("UPDATE $tblFind SET status = 0 WHERE finding_id = ? AND module = ?");
            $st->bind_param('is', $fid, $module);
            $st->execute();
            $st->close();
            pfmJson(array('ok' => true, 'deactivated' => true,
                          'msg' => 'This finding is used by saved feedback, so it was deactivated instead of deleted. It no longer appears in the list for new feedback.'));
        }

        $st = $conn->prepare("DELETE FROM $tblFind WHERE finding_id = ? AND module = ?");
        $st->bind_param('is', $fid, $module);
        $st->execute();
        $st->close();
        pfmJson(array('ok' => true, 'deactivated' => false));
    }

    pfmJson(array('ok' => false, 'msg' => 'Unknown action.'));
} catch (\Throwable $e) {
    error_log('[mNEQAS] finding-master: ' . $e->getMessage());
    pfmJson(array('ok' => false, 'msg' => 'Server error — the change was not saved.'));
}
