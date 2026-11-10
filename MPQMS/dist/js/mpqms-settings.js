/*
 * MPQMS settings CRUD — professional AJAX workflow (no full page reload).
 * Submits the add/edit form asynchronously, shows a SweetAlert2 message, and
 * updates only the affected DataTable row in place (preserving search, paging,
 * sort and scroll). The server still performs all validation & business logic;
 * the record is persisted regardless of client rendering.
 */
(function () {
	window.initMpqmsSettings = function (cfg) {
		var dt = $(cfg.table).DataTable();          // already initialised inline
		var $form = $(cfg.form);

		// Rows added through AJAX get the same cell alignment the server-rendered rows have
		// (ID / Ranking / Action centred), so in-place additions look identical to a reload.
		window.mpqmsSettingsAlignRow = window.mpqmsSettingsAlignRow || function (node, data) {
			var $td = $(node).children('td');
			$td.first().addClass('text-center'); $td.last().addClass('text-center');
			if (data && data.length === 6) { $td.eq(3).addClass('text-center'); }   // Parameter: Ranking column
		};
		function addRow(row) { var r = dt.row.add(row); dt.one('draw', function () { window.mpqmsSettingsAlignRow(r.node(), row); }); return r; }

		// ---- Delete a master record (Admin only): confirm -> POST -> remove the row in place ----
		// The delete icon (.mp-del-master) is rendered server-side only for Administrators, and the
		// backend re-verifies admin + CSRF and refuses if the record is linked to existing data.
		$(cfg.table).on('click', '.mp-del-master', function () {
			var $row = $(this).closest('tr');
			var id   = $(this).data('id');
			var type = $(this).data('type');
			var name = String($(this).data('name') || '');
			var item = cfg.label || 'item';
			Swal.fire({
				title: 'Delete ' + item + '?',
				html: 'Are you sure you want to delete this ' + item +
				      (name ? ' (<b>' + $('<div>').text(name).html() + '</b>)' : '') +
				      '? This action cannot be undone.',
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#d33',
				cancelButtonText: 'Cancel',
				confirmButtonText: 'Yes, Delete'
			}).then(function (r) {
				if (!r.isConfirmed) return;
				$.post('delete_master.php', { type: type, id: id }, function (res) {
					if (res && res.status === 'success') {
						dt.row($row).remove().draw(false);       // refresh the DataTable in place (keeps sort/paging)
						Swal.fire({ icon: 'success', text: res.message || 'Item successfully deleted.', timer: 1800, showConfirmButton: false });
					} else {
						Swal.fire({ icon: (res && res.status === 'blocked') ? 'warning' : 'error', text: (res && res.message) || 'Failed to delete.' });
					}
				}, 'json').fail(function (xhr) {
					var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Request failed.';
					Swal.fire({ icon: 'error', text: msg });
				});
			});
		});

		if (!$form.length) { return; }                // read-only role: list only, no Add/Edit form on the page

		function resetToAddMode() {
			$form.find("input[type=text], input[type=number]").val('');
			$form.find(".mp-cat").prop('checked', false);
			$form.find("[name=sts]").prop('checked', false);
			$form.find("[name=id]").remove();
			$form.find("[name=type]").val('N' + cfg.type);
			$form.find(".mp-submit").val('Save ' + cfg.label);
			$form.find(".mp-addnew, .mp-cancel").hide();     // no Cancel/Add-New in Add mode
			$form.closest('.card').find('.mp-form-title').text('Add ' + cfg.label);   // restore Add title
			$form.find('.is-invalid').removeClass('is-invalid');
			$form.find('.invalid-feedback').remove();
			var v = $form.data('validator'); if (v) { v.resetForm(); }
			// Leave the "?id=" of the finished edit behind so a refresh (or the browser Back
			// button) shows the clean Add form instead of re-opening the record just saved.
			if (window.history && history.replaceState && /[?&]id=/.test(window.location.search)) {
				history.replaceState(null, '', window.location.pathname);
			}
		}

		// "Cancel" (edit mode) clears the form and returns to Add mode without navigating
		// away. (.mp-addnew kept as a backward-compatible alias.)
		$form.on('click', '.mp-cancel, .mp-addnew', function (e) { e.preventDefault(); resetToAddMode(); });

		$form.validate({
			errorElement: 'span',
			errorPlacement: function (error, element) {
				error.addClass('invalid-feedback');
				element.closest('.form-group').append(error);
			},
			highlight: function (el) { $(el).addClass('is-invalid'); },
			unhighlight: function (el) { $(el).removeClass('is-invalid'); },
			submitHandler: function (form) {
				var $btn = $(form).find('.mp-submit');
				$btn.prop('disabled', true);
				$.post('save_update.php', $(form).serialize() + '&ajax=1', function (res) {
					$btn.prop('disabled', false);
					if (res && res.status === 'success') {
						if (res.mode === 'update') {
							var updated = false;
							dt.rows().every(function () {
								var d = this.data();
								if (String($('<div>').html(d[0]).text()).trim() === String(res.id)) {
									this.data(res.row); updated = true;
								}
							});
							if (!updated) { addRow(res.row); }
						} else {
							addRow(res.row);
						}
						dt.draw(false);                     // keep current page / scroll
						Swal.fire({ icon: 'success', text: res.message || 'Saved successfully!', timer: 1400, showConfirmButton: false });
						resetToAddMode();
					} else {
						Swal.fire({ icon: 'error', text: (res && res.message) || 'Failed to save.' });
					}
				}, 'json').fail(function (xhr) {
					$btn.prop('disabled', false);
					var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message
						        : (xhr && xhr.status === 0 ? 'The server could not be reached. Please check your connection and try again.'
						        : 'Request failed (HTTP ' + (xhr ? xhr.status : '?') + '). Nothing was saved. Please try again.');
						Swal.fire({ icon: 'error', text: msg });
				});
				return false;                                // never do a native submit
			}
		});
	};
})();
