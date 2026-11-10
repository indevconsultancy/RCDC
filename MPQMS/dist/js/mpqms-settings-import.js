/*
 * MPQMS Settings — "Import from Excel" (bulk create) for the six master-data sub-modules.
 *
 * Flow:  Import Excel -> Download template -> choose file -> Validate rows (preview on the
 *        right) -> Import valid new records -> toast -> modal closes -> listing page reloads.
 *
 * Purely additive: it injects one button into the list card header and one modal into the
 * page; the manual Add/Edit/Delete workflow (mpqms-settings.js) is untouched. The server
 * (settings/import_master.php) re-validates everything before saving — the preview shown
 * here is informational, never authoritative.
 *
 *   initMpqmsImport({ type:'F', label:'Formulation', table:'#table', canImport:true|false })
 */
(function () {
	function esc(v){ return $('<div>').text(v == null ? '' : String(v)).html(); }
	function csrfToken(){ return $('meta[name="csrf-token"]').attr('content') || ''; }

	var IMPORT_LABEL = '<i class="fas fa-file-import"></i>&nbsp;Import valid new records';
	var FILE_LABEL   = 'Choose .xlsx or .csv file…';
	var EMPTY_PREVIEW = '<div class="text-muted small text-center py-4">Preview appears here after <b>Validate rows</b>.</div>';

	window.initMpqmsImport = function (cfg) {
		if (!cfg || !cfg.canImport) { return; }                 // read-only roles get no import control
		var type = cfg.type, label = cfg.label || 'Record';
		var modalId = 'mpImportModal_' + type, file = null, validation = null;

		/* ---------- button in the list card header ---------- */
		var $card = $(cfg.table || '#table').closest('.card');
		var $hdr = $card.find('.card-header').first();
		var $tools = $hdr.find('.card-tools'); if (!$tools.length) { $tools = $('<div class="card-tools"></div>').appendTo($hdr); }
		$tools.append('<button type="button" class="btn btn-sm bg-gradient-info mp-import-open" title="Bulk upload new ' + esc(label) + ' records from Excel"><i class="fas fa-file-excel"></i>&nbsp;Import Excel</button>');

		/* ---------- modal: compact two-column layout (controls left, preview right) ---------- */
		$('body').append(
			'<div class="modal fade mp-imp-modal" id="' + modalId + '" tabindex="-1" role="dialog" aria-hidden="true">' +
			'<div class="modal-dialog modal-xl modal-dialog-scrollable" role="document"><div class="modal-content">' +
			'<div class="modal-header bg-info py-2 px-3 align-items-center"><h6 class="modal-title mb-0"><i class="fas fa-file-excel"></i>&nbsp; Import ' + esc(label) + ' from Excel</h6>' +
			'<button type="button" class="close m-0 p-0 pl-3" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>' +
			'<div class="modal-body p-3">' +
			'  <div class="mp-imp-grid" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;">' +
			'    <div class="mp-imp-controls" style="flex:1 1 230px;max-width:290px;display:flex;flex-direction:column;gap:10px;">' +
			'      <a class="btn btn-outline-primary btn-block m-0 text-left mp-imp-template" href="import_master.php?action=template&type=' + type + '"><i class="fas fa-download fa-fw"></i>&nbsp;Download template</a>' +
			// EDL only: the current records in the same layout, Drug Categories comma-separated in one cell.
			(type === 'E'
				? '      <a class="btn btn-outline-secondary btn-block m-0 text-left mp-imp-export" href="import_master.php?action=export&type=' + type + '"><i class="fas fa-file-export fa-fw"></i>&nbsp;Export current records</a>'
				: '') +
			'      <div class="custom-file m-0"><input type="file" class="custom-file-input mp-imp-file" id="' + modalId + '_file" accept=".xlsx,.csv"><label class="custom-file-label text-truncate mp-imp-file-label" for="' + modalId + '_file">' + FILE_LABEL + '</label></div>' +
			'      <button type="button" class="btn bg-gradient-primary btn-block m-0 text-left mp-imp-validate" disabled><i class="fas fa-check-double fa-fw"></i>&nbsp;Validate rows</button>' +
			'    </div>' +
			'    <div class="mp-imp-result" style="flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:10px;">' +
			'      <div class="mp-imp-summary" style="display:none;"></div>' +
			'      <div class="mp-imp-preview border rounded" style="max-height:58vh;overflow:auto;">' + EMPTY_PREVIEW + '</div>' +
			'    </div>' +
			'  </div>' +
			'</div>' +
			'<div class="modal-footer py-2 px-3">' +
			'  <button type="button" class="btn bg-gradient-success btn-sm m-0 mp-imp-import" disabled>' + IMPORT_LABEL + '</button>' +
			'</div></div></div></div>');
		var $m = $('#' + modalId);
		var $file = $m.find('.mp-imp-file'), $fileLabel = $m.find('.mp-imp-file-label'), $validate = $m.find('.mp-imp-validate'),
		    $import = $m.find('.mp-imp-import'), $preview = $m.find('.mp-imp-preview'), $summary = $m.find('.mp-imp-summary');

		function reset(){ file = null; validation = null; $file.val(''); $fileLabel.text(FILE_LABEL); $validate.prop('disabled', true); $import.prop('disabled', true).html(IMPORT_LABEL); $summary.hide().empty(); $preview.html(EMPTY_PREVIEW); }

		$card.on('click', '.mp-import-open', function () { reset(); $m.modal('show'); });
		$m.on('hidden.bs.modal', reset);

		$file.on('change', function () {
			file = this.files && this.files[0] ? this.files[0] : null; validation = null;
			$fileLabel.text(file ? file.name : FILE_LABEL);
			$validate.prop('disabled', !file); $import.prop('disabled', true).html(IMPORT_LABEL); $summary.hide().empty(); $preview.html(EMPTY_PREVIEW);
		});

		function renderPreview(res, afterImport){
			var fields = res.fields || [], rows = res.rows || [];
			var h = '<table class="table table-sm table-bordered table-striped mb-0" style="font-size:.8rem;"><thead class="thead-light"><tr><th style="width:48px;">Row</th>';
			fields.forEach(function(f){ h += '<th>' + esc(f.header) + '</th>'; });
			h += '<th style="width:84px;">Status</th><th>Message</th></tr></thead><tbody>';
			rows.forEach(function(r){
				var ok = !!r.valid, imported = afterImport && ok;
				h += '<tr class="' + (ok ? '' : 'table-danger') + '"><td class="text-center">' + esc(r.row) + '</td>';
				fields.forEach(function(f){ h += '<td>' + esc(r.values ? r.values[f.key] : '') + '</td>'; });
				h += '<td class="text-center">' + (imported ? '<span class="badge badge-success">Imported</span>' : (ok ? '<span class="badge badge-primary">Valid</span>' : '<span class="badge badge-danger">Error</span>')) + '</td>';
				h += '<td>' + (ok ? (imported ? '<span class="text-success">Saved</span>' : '<span class="text-muted">Will be created</span>') : '<ul class="mb-0 pl-3">' + (r.messages || []).map(function(x){ return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>') + '</td></tr>';
			});
			h += '</tbody></table>';
			$preview.html(h);
		}
		function renderSummary(s){
			$summary.show().html('<div class="alert ' + (s.invalid ? 'alert-warning' : 'alert-success') + ' mb-0 py-1 px-2 small">' +
				'<b>' + esc(s.total) + '</b> row' + (s.total === 1 ? '' : 's') + ' read &middot; <b class="text-success">' + esc(s.valid) + '</b> valid &middot; <b class="text-danger">' + esc(s.invalid) + '</b> with errors' +
				(s.invalid ? ' (rows with errors are skipped)' : '') + '</div>');
		}
		function post(action, done){
			var fd = new FormData(); fd.append('action', action); fd.append('type', type); fd.append('file', file); fd.append('csrf', csrfToken()); fd.append('ajax', '1');
			return $.ajax({ url: 'import_master.php', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', headers: { 'X-CSRF-Token': csrfToken() } })
				.done(done)
				.fail(function (xhr) { var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'The request failed (HTTP ' + xhr.status + '). Nothing was saved.'; Swal.fire({ icon: 'error', title: 'Import', text: msg }); });
		}

		$validate.on('click', function () {
			if (!file) { return; }
			var $b = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin fa-fw"></i>&nbsp;Validating…');
			post('validate', function (res) {
				if (!res || res.status !== 'success') { Swal.fire({ icon: 'error', title: 'Validation', text: (res && res.message) || 'The file could not be validated.' }); validation = null; $import.prop('disabled', true); return; }
				validation = res; renderPreview(res, false); renderSummary(res.summary);
				$import.prop('disabled', !(res.summary && res.summary.valid > 0)).html('<i class="fas fa-file-import"></i>&nbsp;Import ' + (res.summary ? res.summary.valid : 0) + ' valid new record' + (res.summary && res.summary.valid === 1 ? '' : 's'));
			}).always(function(){ $b.prop('disabled', !file).html('<i class="fas fa-check-double fa-fw"></i>&nbsp;Validate rows'); });
		});

		$import.on('click', function () {
			if (!file || !validation || !validation.summary || validation.summary.valid < 1) { return; }
			var n = validation.summary.valid, skip = validation.summary.invalid;
			Swal.fire({ icon: 'question', title: 'Import ' + n + ' new ' + label + ' record' + (n === 1 ? '' : 's') + '?', html: (skip ? '<b>' + skip + '</b> row' + (skip === 1 ? '' : 's') + ' with errors will be skipped.<br>' : '') + 'The file is re-validated on the server before anything is saved.', showCancelButton: true, confirmButtonText: 'Yes, import', cancelButtonText: 'Cancel' })
			.then(function (r) {
				if (!r.isConfirmed) { return; }
				var $b = $import.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>&nbsp;Importing…'); $validate.prop('disabled', true);
				post('import', function (res) {
					if (!res || res.status !== 'success') {
						if (res && res.rows) { renderPreview(res, false); }
						Swal.fire({ icon: 'error', title: 'Not imported', text: (res && res.message) || 'The import failed. Nothing was saved.' });
						$b.prop('disabled', false).html(IMPORT_LABEL); $validate.prop('disabled', !file); return;
					}
					// Success: brief toast, close the modal and reload the listing page so the new
					// records appear in the list (the page URL is kept, minus any ?id= edit state).
					file = null; validation = null;
					$m.modal('hide');
					Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message || 'Records imported successfully.', showConfirmButton: false, timer: 1600, timerProgressBar: true })
						.then(function () { window.location.href = window.location.pathname; });
				}).fail(function(){ $b.prop('disabled', false).html(IMPORT_LABEL); $validate.prop('disabled', !file); });
			});
		});
	};
})();
