<?php
/*
 * MPQMS Settings — Excel bulk-import library (Formulation / Parameter / Category / EDL /
 * Manufacturer / MAH).
 *
 * Each sub-module is described by ONE map entry that mirrors its existing Add form exactly:
 * the same fields, the same mandatory/optional rules, the same table/columns, the same
 * sentinel id that save_update.php skips when it computes MAX(id)+1, and the same audit
 * row. Import creates NEW records only; a row that fails any rule is reported by row
 * number and never inserted.
 *
 *   mpq_import_modules()                          -> module map
 *   mpq_import_module($type)                      -> one entry (or null)
 *   mpq_import_template_xlsx($mod, $conn)         -> binary .xlsx template
 *   mpq_import_read_file($path, $origName)        -> array(header=>..., rows=>[[col=>value]], error=>?)
 *   mpq_import_validate($mod, $conn, $rows)       -> per-row verdicts (valid / error) + summary
 *   mpq_import_execute($mod, $conn, $rows, $user) -> inserts the valid rows in ONE transaction
 *
 * No spreadsheet library is available in this deployment, so .xlsx files are produced and
 * parsed directly (they are zip archives of XML) with the ZipArchive + DOM extensions that
 * are already loaded. CSV is accepted as a fallback format.
 */

if(!function_exists('mpq_import_modules')){
	function mpq_import_modules(){
		return array(
			'F' => array(
				'label'=>'Formulation', 'table'=>'msformulationtable', 'nameCol'=>'formulation', 'sentinel'=>99,
				'audit'=>'formulation', 'page'=>'formulation.php', 'restricted'=>true, 'adminOnly'=>false,
				'fields'=>array(
					array('key'=>'name',   'header'=>'Formulation', 'required'=>true,  'max'=>150, 'help'=>'Formulation name (text, max 150 characters). Must not already exist.'),
					array('key'=>'status', 'header'=>'Status',      'required'=>true,  'kind'=>'status', 'help'=>'Active or Inactive'),
				),
			),
			'P' => array(
				'label'=>'Parameter', 'table'=>'mstestparametertable', 'nameCol'=>'parameter', 'sentinel'=>99,
				'audit'=>'parameter', 'page'=>'parameter.php', 'restricted'=>true, 'adminOnly'=>false,
				'fields'=>array(
					array('key'=>'name',    'header'=>'Parameter', 'required'=>true,  'max'=>150, 'help'=>'Test parameter name (text, max 150 characters). Must not already exist.'),
					array('key'=>'unit',    'header'=>'Unit',      'required'=>false, 'max'=>30,  'help'=>'Optional unit, e.g. mg, %, ppm (max 30 characters)'),
					array('key'=>'ranking', 'header'=>'Ranking',   'required'=>true,  'kind'=>'int', 'help'=>'Display order — a whole number'),
					array('key'=>'status',  'header'=>'Status',    'required'=>true,  'kind'=>'status', 'help'=>'Active or Inactive'),
				),
			),
			'C' => array(
				'label'=>'Category', 'table'=>'msdrugcategorytable', 'nameCol'=>'category', 'sentinel'=>99,
				'audit'=>'category', 'page'=>'category.php', 'restricted'=>true, 'adminOnly'=>false,
				'fields'=>array(
					array('key'=>'name',   'header'=>'Category', 'required'=>true,  'max'=>150, 'help'=>'Drug category name (text, max 150 characters). Must not already exist.'),
					array('key'=>'status', 'header'=>'Status',   'required'=>true,  'kind'=>'status', 'help'=>'Active or Inactive'),
				),
			),
			'E' => array(
				'label'=>'EDL', 'table'=>'msedltable', 'nameCol'=>'edl', 'sentinel'=>999,
				'audit'=>'edl', 'page'=>'edl.php', 'restricted'=>true, 'adminOnly'=>true,
				'fields'=>array(
					array('key'=>'name',       'header'=>'EDL',             'required'=>true,  'max'=>150, 'help'=>'EDL / product name (text, max 150 characters). Must not already exist.'),
					array('key'=>'status',     'header'=>'Status',          'required'=>true,  'kind'=>'status', 'help'=>'Active or Inactive'),
					array('key'=>'categories', 'header'=>'Drug Categories', 'required'=>false, 'kind'=>'categories', 'help'=>'Optional. Leave blank for none. One or more ACTIVE Drug Category names in a single cell, separated by commas (";" is also accepted). A few category names contain a comma themselves — copy those exactly from the Categories sheet and they are still recognised.'),
				),
			),
			'M' => array(
				'label'=>'Manufacturer', 'table'=>'msmanufacturertable', 'nameCol'=>'name', 'sentinel'=>999,
				'audit'=>'manufacturer', 'page'=>'manufacturer.php', 'restricted'=>false, 'adminOnly'=>false,
				'fields'=>array(
					array('key'=>'name',   'header'=>'Manufacturer', 'required'=>true,  'max'=>200, 'help'=>'Manufacturer name (text, max 200 characters). Must not already exist.'),
					array('key'=>'status', 'header'=>'Status',       'required'=>true,  'kind'=>'status', 'help'=>'Active or Inactive'),
				),
			),
			'H' => array(
				'label'=>'MAH', 'table'=>'msmahtable', 'nameCol'=>'name', 'sentinel'=>99,
				'audit'=>'mah', 'page'=>'mah.php', 'restricted'=>false, 'adminOnly'=>false,
				'fields'=>array(
					array('key'=>'name',   'header'=>'MAH',    'required'=>true,  'max'=>200, 'help'=>'Marketing Authorisation Holder name (text, max 200 characters). Must not already exist.'),
					array('key'=>'status', 'header'=>'Status', 'required'=>true,  'kind'=>'status', 'help'=>'Active or Inactive'),
				),
			),
		);
	}
}

if(!function_exists('mpq_import_module')){
	function mpq_import_module($type){
		$m = mpq_import_modules();
		$type = strtoupper(trim((string)$type));
		return isset($m[$type]) ? $m[$type] + array('type'=>$type) : null;
	}
}

/* ---------------------------------------------------------------------------------------
 * Permission: identical to save_update.php / the page guards.
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_allowed')){
	function mpq_import_allowed($mod){
		$acl = (int)($_SESSION['acl'] ?? 0); $ucat = (int)($_SESSION['usercat'] ?? 0);
		if(!in_array($acl, array(1,2), true)){ return false; }                  // BFDA/DRA is read-only
		if(!empty($mod['adminOnly']) && $acl !== 1){ return false; }            // EDL: Administrator only
		if(!empty($mod['restricted']) && !($acl === 1 || ($acl === 2 && $ucat === 18))){ return false; }
		return true;
	}
}

/* ---------------------------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_norm')){
	/** Key used for duplicate detection: trimmed, single-spaced, case-insensitive. */
	function mpq_import_norm($s){
		$s = preg_replace('/\s+/u', ' ', trim((string)$s));
		return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
	}
}
if(!function_exists('mpq_import_len')){
	function mpq_import_len($s){ return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s); }
}
if(!function_exists('mpq_import_parse_status')){
	/** Accepts the values a person would type; returns 1 / 0 / null (invalid). */
	function mpq_import_parse_status($v){
		$k = strtolower(trim((string)$v));
		if(in_array($k, array('1','active','yes','y','true','enabled'), true)){ return 1; }
		if(in_array($k, array('0','inactive','no','n','false','disabled'), true)){ return 0; }
		return null;
	}
}
if(!function_exists('mpq_import_header_key')){
	function mpq_import_header_key($h){ return strtolower(trim(preg_replace('/[\s\*:_\-]+/', ' ', (string)$h))); }
}
if(!function_exists('mpq_import_active_categories')){
	/** Active drug categories exactly as the EDL form offers them: normalised name => id. */
	function mpq_import_active_categories($conn){
		$out = array();
		$r = mysqli_query($conn, "SELECT id, category FROM msdrugcategorytable WHERE status=1 ORDER BY id ASC");
		while($x = mysqli_fetch_row($r)){ $out[mpq_import_norm($x[1])] = array('id'=>(int)$x[0], 'name'=>$x[1]); }
		return $out;
	}
}

/* ---------------------------------------------------------------------------------------
 * EDL <-> Drug Category (multi-select) cell handling.
 *
 * The EDL form stores one msedlcategorymaptable row per ticked checkbox. In Excel the whole
 * selection travels in ONE cell: category names separated by commas, and an empty cell when
 * nothing is ticked.
 *
 * Commas are not a clean delimiter here, because several real category names contain one
 * ("Analgesics,Antipyretics & Non-Steroidal Anti-Inflammatory Medicines"). Splitting naively
 * would break every EDL mapped to those. mpq_import_split_categories() therefore matches
 * GREEDILY: at each position it tries the longest run of comma-separated tokens that is
 * itself a known category name, and only falls back to a single token. ";" and "|" are still
 * honoured first, so Excel files written for the previous format keep importing unchanged.
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_cat_key')){
	/** Match key that also ignores spacing around an internal comma, so "A, B" == "A,B". */
	function mpq_import_cat_key($s){ return preg_replace('/\s*,\s*/', ',', mpq_import_norm($s)); }
}
if(!function_exists('mpq_import_cat_index')){
	/** Lookup for the resolver: comma-tolerant key => category, on top of the plain key. */
	function mpq_import_cat_index($cats){
		$ix = array();
		foreach($cats as $k => $c){ $ix[$k] = $c; $ix[mpq_import_cat_key($c['name'])] = $c; }
		return $ix;
	}
}
if(!function_exists('mpq_import_split_categories')){
	/**
	 * Resolve one Drug Categories cell into the ticked categories.
	 * @param  string $v    raw cell value ('' = nothing selected)
	 * @param  array  $cats mpq_import_active_categories() result
	 * @return array {ids:[int], names:[string], bad:[string]} — ids/names de-duplicated, in cell order
	 */
	function mpq_import_split_categories($v, $cats){
		$out = array('ids'=>array(), 'names'=>array(), 'bad'=>array());
		$v = trim((string)$v);
		if($v === ''){ return $out; }                       // blank cell -> no categories, never an error
		$ix = mpq_import_cat_index($cats);
		$take = function($c) use (&$out){
			if(!in_array($c['id'], $out['ids'], true)){ $out['ids'][] = $c['id']; $out['names'][] = $c['name']; }
		};
		// ";" and "|" are unambiguous, so honour them first (backward compatibility).
		foreach(preg_split('/\s*[;|]\s*/', $v) as $chunk){
			$chunk = trim($chunk);
			if($chunk === ''){ continue; }
			// A chunk that is a category name in its own right needs no comma splitting at all.
			$k = mpq_import_cat_key($chunk);
			if(isset($ix[$k])){ $take($ix[$k]); continue; }
			// Otherwise walk the comma tokens, longest matching run first.
			$tok = array_map('trim', explode(',', $chunk));
			$n = count($tok); $i = 0;
			while($i < $n){
				$hit = null; $end = $i;
				for($j = $n - 1; $j >= $i; $j--){
					$cand = implode(',', array_slice($tok, $i, $j - $i + 1));
					$ck = mpq_import_cat_key($cand);
					if(isset($ix[$ck])){ $hit = $ix[$ck]; $end = $j; break; }
				}
				if($hit !== null){ $take($hit); }
				else { $t = $tok[$i]; if($t !== ''){ $out['bad'][] = $t; } }
				$i = $end + 1;
			}
		}
		return $out;
	}
}
if(!function_exists('mpq_import_join_categories')){
	/** The Excel cell for a selection: comma-separated, blank when nothing is selected. */
	function mpq_import_join_categories($names){ return implode(', ', (array)$names); }
}

/* ---------------------------------------------------------------------------------------
 * .xlsx TEMPLATE writer (Data sheet with bold headers + Instructions sheet
 * [+ Categories sheet for EDL]).
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_xml_esc')){
	function mpq_import_xml_esc($s){ return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
}
if(!function_exists('mpq_import_col_letter')){
	function mpq_import_col_letter($i){ $s=''; $i=(int)$i; while($i>=0){ $s=chr($i%26+65).$s; $i=intdiv($i,26)-1; } return $s; }
}
if(!function_exists('mpq_import_sheet_xml')){
	/** $rows: array of arrays of strings; row 1 is bold when $boldHeader. */
	function mpq_import_sheet_xml($rows, $widths, $boldHeader = true){
		$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		if($widths){ $x .= '<cols>'; foreach($widths as $i=>$w){ $x .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.(int)$w.'" customWidth="1"/>'; } $x .= '</cols>'; }
		$x .= '<sheetData>';
		foreach($rows as $ri => $row){
			$x .= '<row r="'.($ri+1).'">';
			foreach($row as $ci => $val){
				$style = ($boldHeader && $ri === 0) ? ' s="1"' : '';
				$x .= '<c r="'.mpq_import_col_letter($ci).($ri+1).'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.mpq_import_xml_esc($val).'</t></is></c>';
			}
			$x .= '</row>';
		}
		$x .= '</sheetData></worksheet>';
		return $x;
	}
}
if(!function_exists('mpq_import_template_xlsx')){
	function mpq_import_template_xlsx($mod, $conn){
		$headers = array(); $widths = array(); $instr = array(array('Column', 'Required', 'Rule'));
		foreach($mod['fields'] as $f){
			$headers[] = $f['header']; $widths[] = max(18, min(60, mpq_import_len($f['header']) + 30));
			$instr[] = array($f['header'], $f['required'] ? 'Yes' : 'No', $f['help']);
		}
		$instr[] = array('', '', '');
		$instr[] = array('General', '', 'Fill the "Data" sheet only. Keep the header row exactly as provided. One record per row; empty rows are ignored.');
		$instr[] = array('', '', 'Only NEW '.$mod['label'].' records are created. Rows whose name already exists (case-insensitive) or is repeated in the file are reported and skipped.');
		$instr[] = array('', '', 'Every row is validated before anything is saved. Rows with errors are listed by row number and never imported.');
		$sheets = array(array('name'=>'Data', 'xml'=>mpq_import_sheet_xml(array($headers), $widths)),
		                array('name'=>'Instructions', 'xml'=>mpq_import_sheet_xml($instr, array(22, 10, 110))));
		if($mod['type'] === 'E'){
			$cats = array(array('Active Drug Categories — copy a name exactly; put several in one cell separated by commas; leave the cell blank for none'));
			foreach(mpq_import_active_categories($conn) as $c){ $cats[] = array($c['name']); }
			$sheets[] = array('name'=>'Categories', 'xml'=>mpq_import_sheet_xml($cats, array(70)));
		}

		return mpq_import_write_xlsx($sheets);
	}
}

if(!function_exists('mpq_import_write_xlsx')){
	/** Package the given sheets (name + worksheet XML) into an .xlsx binary. */
	function mpq_import_write_xlsx($sheets){
		$tmp = tempnam(sys_get_temp_dir(), 'mpqx'); @unlink($tmp); $tmp .= '.xlsx';
		$z = new ZipArchive();
		if($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true){ return false; }
		$ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		     . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
		     . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		     . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		$rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach($sheets as $i => $s){
			$n = $i + 1;
			$ct  .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb  .= '<sheet name="'.mpq_import_xml_esc($s['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
			$rel .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
			$z->addFromString('xl/worksheets/sheet'.$n.'.xml', $s['xml']);
		}
		$ct  .= '</Types>';
		$wb  .= '</sheets></workbook>';
		$rel .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$z->addFromString('[Content_Types].xml', $ct);
		$z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
		$z->addFromString('xl/workbook.xml', $wb);
		$z->addFromString('xl/_rels/workbook.xml.rels', $rel);
		$z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
		$z->close();
		$bin = file_get_contents($tmp); @unlink($tmp);
		return $bin;
	}
}

/* ---------------------------------------------------------------------------------------
 * EXPORT: current records as .xlsx, in the SAME column layout as the import template, so an
 * exported file can be edited and fed straight back in. For EDL the whole Drug Category
 * selection travels in one cell, comma-separated, blank when the record has none.
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_export_rows')){
	/** Current rows for $mod as arrays aligned to $mod['fields']. Read-only. */
	function mpq_import_export_rows($mod, $conn){
		$rows = array();
		$table = $mod['table']; $nameCol = $mod['nameCol'];
		$extra = ($mod['type'] === 'P') ? ', unit, ranking' : '';
		$r = mysqli_query($conn, "SELECT id, $nameCol, status$extra FROM $table ORDER BY id ASC");
		if(!$r){ return $rows; }

		// EDL: one pass over the map table, so the export is not N+1 queries.
		$catsFor = array();
		if($mod['type'] === 'E'){
			$q = mysqli_query($conn, "SELECT m.edlID, c.category FROM msedlcategorymaptable m
			                          JOIN msdrugcategorytable c ON c.id = m.categoryID
			                          WHERE c.status = 1 ORDER BY m.edlID ASC, c.id ASC");
			if($q){ while($x = mysqli_fetch_row($q)){ $catsFor[(string)$x[0]][] = $x[1]; } }
		}

		while($x = mysqli_fetch_assoc($r)){
			$line = array();
			foreach($mod['fields'] as $f){
				switch($f['key']){
					case 'name':       $line[] = (string)$x[$nameCol]; break;
					case 'status':     $line[] = ((int)$x['status'] === 1) ? 'Active' : 'Inactive'; break;
					case 'unit':       $line[] = (string)($x['unit'] ?? ''); break;
					case 'ranking':    $line[] = (string)($x['ranking'] ?? ''); break;
					case 'categories': $line[] = mpq_import_join_categories($catsFor[(string)$x['id']] ?? array()); break;
					default:           $line[] = '';
				}
			}
			$rows[] = $line;
		}
		return $rows;
	}
}
if(!function_exists('mpq_import_export_xlsx')){
	function mpq_import_export_xlsx($mod, $conn){
		$headers = array(); $widths = array();
		foreach($mod['fields'] as $f){ $headers[] = $f['header']; $widths[] = max(18, min(60, mpq_import_len($f['header']) + 30)); }
		$data = array_merge(array($headers), mpq_import_export_rows($mod, $conn));
		$sheets = array(array('name'=>'Data', 'xml'=>mpq_import_sheet_xml($data, $widths)));
		if($mod['type'] === 'E'){
			$cats = array(array('Active Drug Categories — copy a name exactly; put several in one cell separated by commas; leave the cell blank for none'));
			foreach(mpq_import_active_categories($conn) as $c){ $cats[] = array($c['name']); }
			$sheets[] = array('name'=>'Categories', 'xml'=>mpq_import_sheet_xml($cats, array(70)));
		}
		return mpq_import_write_xlsx($sheets);
	}
}

/* ---------------------------------------------------------------------------------------
 * READER: .xlsx (first sheet; shared strings, inline strings, numbers, booleans) or .csv.
 * Returns array('rows'=>[[colLetter=>value]...], 'error'=>string|null). Rows keep their
 * 1-based spreadsheet row number in key '__row'.
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_read_file')){
	function mpq_import_read_file($path, $origName){
		$ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
		if($ext === 'csv' || $ext === 'txt'){ return mpq_import_read_csv($path); }
		if($ext !== 'xlsx'){ return array('rows'=>array(), 'error'=>'Unsupported file type ".'.$ext.'". Please upload the .xlsx template (or a .csv with the same columns).'); }
		return mpq_import_read_xlsx($path);
	}
}
if(!function_exists('mpq_import_read_csv')){
	function mpq_import_read_csv($path){
		$rows = array(); $fh = @fopen($path, 'r');
		if(!$fh){ return array('rows'=>array(), 'error'=>'The uploaded file could not be read.'); }
		$first = fread($fh, 3); if($first !== "\xEF\xBB\xBF"){ rewind($fh); }   // skip UTF-8 BOM
		$n = 0;
		while(($rec = fgetcsv($fh, 0, ',')) !== false){
			$n++; if($n > 5000){ break; }
			$row = array('__row'=>$n);
			foreach($rec as $i => $v){ $row[mpq_import_col_letter($i)] = (string)$v; }
			$rows[] = $row;
		}
		fclose($fh);
		return array('rows'=>$rows, 'error'=>null);
	}
}
if(!function_exists('mpq_import_read_xlsx')){
	function mpq_import_read_xlsx($path){
		$z = new ZipArchive();
		if($z->open($path) !== true){ return array('rows'=>array(), 'error'=>'The file is not a valid .xlsx workbook.'); }
		// Guard against decompression bombs.
		for($i = 0; $i < $z->numFiles; $i++){ $st = $z->statIndex($i); if($st && $st['size'] > 40 * 1024 * 1024){ $z->close(); return array('rows'=>array(), 'error'=>'The workbook is too large to process.'); } }
		$load = function($name) use ($z){ $s = $z->getFromName($name); if($s === false){ return null; } $d = new DOMDocument(); if(!@$d->loadXML($s, LIBXML_NONET)){ return null; } return $d; };

		// Locate the first worksheet through the workbook relationships.
		$sheetPath = 'xl/worksheets/sheet1.xml';
		$wb = $load('xl/workbook.xml'); $rels = $load('xl/_rels/workbook.xml.rels');
		if($wb && $rels){
			$first = $wb->getElementsByTagNameNS('*', 'sheet')->item(0);
			if($first){
				$rid = $first->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
				if($rid === ''){ $rid = $first->getAttribute('r:id'); }
				foreach($rels->getElementsByTagNameNS('*', 'Relationship') as $r){
					if($r->getAttribute('Id') === $rid){ $t = $r->getAttribute('Target'); $sheetPath = (strpos($t, '/') === 0) ? ltrim($t, '/') : 'xl/'.$t; }
				}
			}
		}
		// Shared strings (rich text runs are concatenated).
		$shared = array();
		if(($ss = $load('xl/sharedStrings.xml')) !== null){
			foreach($ss->getElementsByTagNameNS('*', 'si') as $si){ $txt = ''; foreach($si->getElementsByTagNameNS('*', 't') as $t){ $txt .= $t->textContent; } $shared[] = $txt; }
		}
		$sheet = $load($sheetPath); $z->close();
		if(!$sheet){ return array('rows'=>array(), 'error'=>'The workbook has no readable worksheet.'); }

		$rows = array(); $count = 0;
		foreach($sheet->getElementsByTagNameNS('*', 'row') as $rowEl){
			$rn = (int)$rowEl->getAttribute('r'); if($rn <= 0){ $rn = $count + 1; }
			$row = array('__row'=>$rn); $has = false;
			foreach($rowEl->getElementsByTagNameNS('*', 'c') as $c){
				$ref = $c->getAttribute('r'); $col = preg_replace('/[^A-Z]/', '', strtoupper($ref)); if($col === ''){ continue; }
				$type = $c->getAttribute('t'); $val = '';
				if($type === 'inlineStr'){ foreach($c->getElementsByTagNameNS('*', 't') as $t){ $val .= $t->textContent; } }
				else {
					$v = $c->getElementsByTagNameNS('*', 'v')->item(0); $raw = $v ? $v->textContent : '';
					if($type === 's'){ $val = isset($shared[(int)$raw]) ? $shared[(int)$raw] : ''; }
					else if($type === 'b'){ $val = ($raw === '1') ? 'TRUE' : 'FALSE'; }
					else { $val = $raw; if($val !== '' && is_numeric($val) && strpos($val, 'E') !== false){ $val = rtrim(rtrim(sprintf('%.10F', (float)$val), '0'), '.'); } }
				}
				if($val !== ''){ $has = true; }
				$row[$col] = $val;
			}
			if($has){ $rows[] = $row; $count++; if($count > 5000){ break; } }
		}
		return array('rows'=>$rows, 'error'=>null);
	}
}

/* ---------------------------------------------------------------------------------------
 * HEADER MAPPING + VALIDATION
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_map_headers')){
	/** Finds the header row and maps each field key to a column letter. */
	function mpq_import_map_headers($mod, $rows){
		if(empty($rows)){ return array('error'=>'The file is empty.'); }
		$hdr = $rows[0]; $map = array();
		foreach($mod['fields'] as $f){
			$want = mpq_import_header_key($f['header']);
			foreach($hdr as $col => $val){ if($col === '__row'){ continue; } if(mpq_import_header_key($val) === $want){ $map[$f['key']] = $col; break; } }
		}
		$missing = array();
		foreach($mod['fields'] as $f){ if($f['required'] && !isset($map[$f['key']])){ $missing[] = $f['header']; } }
		if($missing){ return array('error'=>'The header row does not match the '.$mod['label'].' template. Missing column(s): '.implode(', ', $missing).'. Please download the template and keep its first row unchanged.'); }
		return array('map'=>$map, 'dataRows'=>array_slice($rows, 1));
	}
}

if(!function_exists('mpq_import_validate')){
	/**
	 * Validates every data row against the module's form rules, the file itself (repeats)
	 * and the database (already existing names). Nothing is written.
	 * @return array {error?, rows:[{row, values:{key=>display}, valid:bool, messages:[]}], summary:{total,valid,invalid}, map}
	 */
	function mpq_import_validate($mod, $conn, $rows){
		$hm = mpq_import_map_headers($mod, $rows);
		if(isset($hm['error'])){ return array('error'=>$hm['error']); }
		$map = $hm['map'];

		// Existing names (case-insensitive) — a bulk import creates NEW records only.
		$existing = array();
		$r = mysqli_query($conn, "SELECT id, ".$mod['nameCol']." FROM ".$mod['table']);
		while($x = mysqli_fetch_row($r)){ $k = mpq_import_norm($x[1]); if(!isset($existing[$k])){ $existing[$k] = (int)$x[0]; } }
		$cats = ($mod['type'] === 'E') ? mpq_import_active_categories($conn) : array();

		$out = array(); $seen = array(); $valid = 0;
		foreach($hm['dataRows'] as $row){
			$vals = array(); $msgs = array(); $rowNo = (int)$row['__row'];
			$allBlank = true;
			foreach($mod['fields'] as $f){ $v = isset($map[$f['key']]) && isset($row[$map[$f['key']]]) ? trim((string)$row[$map[$f['key']]]) : ''; $vals[$f['key']] = $v; if($v !== ''){ $allBlank = false; } }
			if($allBlank){ continue; }                                            // ignore empty lines

			$rec = array();
			foreach($mod['fields'] as $f){
				$v = $vals[$f['key']]; $kind = isset($f['kind']) ? $f['kind'] : 'text';
				if($v === ''){ if($f['required']){ $msgs[] = $f['header'].' is required.'; } $rec[$f['key']] = ($kind === 'categories') ? array() : ''; continue; }
				switch($kind){
					case 'status':
						$s = mpq_import_parse_status($v);
						if($s === null){ $msgs[] = 'Status must be Active or Inactive (got "'.$v.'").'; } else { $rec['status'] = $s; $vals['status'] = $s ? 'Active' : 'Inactive'; }
						break;
					case 'int':
						if(!preg_match('/^-?\d+(\.0+)?$/', $v)){ $msgs[] = $f['header'].' must be a whole number (got "'.$v.'").'; } else { $rec[$f['key']] = (int)$v; $vals[$f['key']] = (string)(int)$v; }
						break;
					case 'categories':
						// Multi-select cell: comma-separated names (";"/"|" still accepted), blank = none.
						$sp = mpq_import_split_categories($v, $cats);
						if($sp['bad']){ $msgs[] = 'Unknown or inactive Drug Category: "'.implode('", "', $sp['bad']).'". Use the names on the Categories sheet, separated by commas.'; }
						$rec['categories'] = $sp['ids']; $vals['categories'] = mpq_import_join_categories($sp['names']);
						break;
					default:
						if(isset($f['max']) && mpq_import_len($v) > $f['max']){ $msgs[] = $f['header'].' is longer than '.$f['max'].' characters.'; }
						$rec[$f['key']] = $v;
				}
			}
			// Duplicate detection: in the database, then within the file.
			if($vals['name'] !== ''){
				$k = mpq_import_norm($vals['name']);
				if(isset($existing[$k])){ $msgs[] = 'This '.$mod['label'].' already exists (ID '.$existing[$k].'). Only new records are imported.'; }
				else if(isset($seen[$k])){ $msgs[] = 'Repeated in this file (same as row '.$seen[$k].').'; }
				else { $seen[$k] = $rowNo; }
			}
			$ok = empty($msgs); if($ok){ $valid++; }
			$out[] = array('row'=>$rowNo, 'values'=>$vals, 'record'=>$rec, 'valid'=>$ok, 'messages'=>$msgs);
		}
		if(empty($out)){ return array('error'=>'No data rows were found below the header row.'); }
		return array('rows'=>$out, 'summary'=>array('total'=>count($out), 'valid'=>$valid, 'invalid'=>count($out) - $valid), 'map'=>$map);
	}
}

/* ---------------------------------------------------------------------------------------
 * EXECUTE — inserts the valid rows with the SAME id / lock / audit logic as save_update.php,
 * in ONE transaction (all valid rows are saved, or none). Invalid rows are never touched.
 * ------------------------------------------------------------------------------------ */
if(!function_exists('mpq_import_execute')){
	function mpq_import_execute($mod, $conn, $rows, $user){
		$val = mpq_import_validate($mod, $conn, $rows);          // never trust a client-side verdict
		if(isset($val['error'])){ return array('status'=>'error', 'message'=>$val['error']); }
		$toInsert = array_values(array_filter($val['rows'], function($r){ return $r['valid']; }));
		if(empty($toInsert)){ return array('status'=>'error', 'message'=>'There are no valid rows to import.', 'rows'=>$val['rows'], 'summary'=>$val['summary']); }

		$table = $mod['table']; $nameCol = $mod['nameCol']; $sentinel = (int)$mod['sentinel'];
		$now = date('Y-m-d H:i:s'); $user = (string)$user; $inserted = array(); $ok = false; $err = '';
		@mysqli_query($conn, "SELECT GET_LOCK('mpq_settings_newid',5)");        // same serialisation as save_update.php
		mysqli_begin_transaction($conn);
		try {
			list($next) = mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(MAX(id),0)+1 FROM $table WHERE id!=$sentinel"));
			$next = (int)$next;
			if($mod['type'] === 'P'){
				$ins = mysqli_prepare($conn, "INSERT INTO $table (id, $nameCol, unit, ranking, status) VALUES (?,?,?,?,?)");
			} else {
				$ins = mysqli_prepare($conn, "INSERT INTO $table (id, $nameCol, status) VALUES (?,?,?)");
			}
			$aud = mysqli_prepare($conn, "INSERT INTO masterdata_audit(entity,record_id,action,details,changed_by,changed_at) VALUES (?,?,'INSERT',?,?,?)");
			$cmap = ($mod['type'] === 'E') ? mysqli_prepare($conn, "INSERT INTO msedlcategorymaptable (edlID, categoryID) VALUES (?,?)") : null;
			foreach($toInsert as $r){
				$rec = $r['record']; $id = $next++;
				if($mod['type'] === 'P'){ $u = (string)($rec['unit'] ?? ''); $rk = (int)$rec['ranking']; mysqli_stmt_bind_param($ins, 'issii', $id, $rec['name'], $u, $rk, $rec['status']); }
				else { mysqli_stmt_bind_param($ins, 'isi', $id, $rec['name'], $rec['status']); }
				mysqli_stmt_execute($ins);
				if($cmap){ foreach($rec['categories'] as $cid){ mysqli_stmt_bind_param($cmap, 'ii', $id, $cid); mysqli_stmt_execute($cmap); } }
				$details = json_encode(array('name'=>$rec['name'], 'status'=>(string)$rec['status'], 'source'=>'excel-import'));
				mysqli_stmt_bind_param($aud, 'sisss', $mod['audit'], $id, $details, $user, $now);
				mysqli_stmt_execute($aud);
				$inserted[] = array('row'=>$r['row'], 'id'=>$id, 'values'=>$r['values'], 'record'=>$rec);
			}
			mysqli_commit($conn); $ok = true;
		} catch(\Throwable $e){ mysqli_rollback($conn); $err = $e->getMessage(); $inserted = array(); }
		@mysqli_query($conn, "SELECT RELEASE_LOCK('mpq_settings_newid')");

		if(!$ok){ return array('status'=>'error', 'message'=>'The import was rolled back — nothing was saved. '.($err !== '' ? '[Details: '.$err.']' : '')); }

		// Rows in the exact shape save_update.php returns, so the list updates in place.
		$tableRows = array();
		foreach($inserted as $i){
			$statusSpan = ($i['record']['status'] == 1) ? "<span class='text-success font-weight-bold'>Active</span>" : "<span class='text-muted'>Inactive</span>";
			$edit = "<a href='".$mod['page']."?id=".$i['id']."' title='Edit'><i class='far fa-edit'></i></a>";
			$name = htmlspecialchars($i['record']['name'], ENT_QUOTES, 'UTF-8');
			if((int)($_SESSION['acl'] ?? 0) === 1){   // same Action cell as the list page (Delete is Admin-only)
				$edit .= " &nbsp;<a href='javascript:void(0)' class='mp-del-master' data-id='".(int)$i['id']."' data-type='D".$mod['type']."' data-name=\"".$name."\" title='Delete'><i class='fas fa-trash-alt text-danger'></i></a>";
			}
			$tableRows[] = ($mod['type'] === 'P')
				? array((string)$i['id'], $name, htmlspecialchars((string)$i['record']['unit'], ENT_QUOTES, 'UTF-8'), (string)$i['record']['ranking'], $statusSpan, $edit)
				: array((string)$i['id'], $name, $statusSpan, $edit);
		}
		return array('status'=>'success', 'message'=>count($inserted).' new '.$mod['label'].' record'.(count($inserted) === 1 ? '' : 's').' imported successfully.',
		             'inserted'=>count($inserted), 'skipped'=>$val['summary']['invalid'], 'rows'=>$val['rows'], 'tableRows'=>$tableRows,
		             'ids'=>array_map(function($i){ return $i['id']; }, $inserted));
	}
}
?>
