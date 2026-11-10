/* mpqms-select2multi v2 — Oct-2026 chip renderer (no "+N" truncation). */
/*
 * MPQMS multi-select dropdown (presentation only).
 *
 * Skins an existing <select multiple name=.. id=.. required> as a professional,
 * COLLAPSED enterprise dropdown: a fixed-height toggle that shows only a summary
 * ("Select …" when empty, "Capsules, Creams +22" / "24 Selected" when filled) and,
 * when opened, a floating panel with Search, Select All, Clear All, a scrollable
 * checkbox list, and a live count. It never spills selected chips into the page, so
 * the control (and its row) stays a fixed height no matter how many items are chosen.
 *
 * The native <select> is kept in the DOM (hidden) as the submit source — the checkboxes
 * just toggle its <option>.selected — so posted data, POST names/ids and server-side
 * validation are 100% identical. Degrades to the native multi-select if this script fails.
 *
 * Exposed as window.mpqmsMultiselectDropdown; window.mpqmsSelect2Multi is a
 * backward-compatible alias (existing pages call that name).
 */
(function () {
	function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

	function build(selector, title) {
		var $sel = $(selector);
		if (!$sel.length || $sel.data('mpqmsS2')) { return; }
		$sel.data('mpqmsS2', true);

		// Keep the native <select multiple> as the submit source; hide it only.
		$sel.addClass('mp-msd-source').hide();

		// Ensure a field label sits above the control (AJAX-reloaded selects arrive without one).
		var $fg = $sel.closest('.form-group');
		var $label = $fg.children('label').first();
		if (!$label.length && title) { $label = $('<label></label>').text(title); $sel.before($label); }
		var labelText = title || $.trim($label.text()) || 'Select';
		var uid = ($sel.attr('id') || 'msd');

		var $wrap = $('<div class="mp-msd"></div>');
		var $toggle = $(
			'<button type="button" class="form-control mp-msd-toggle" aria-haspopup="listbox" aria-expanded="false">' +
				'<span class="mp-msd-summary mp-msd-ph"></span>' +
				'<i class="fas fa-chevron-down mp-msd-caret"></i>' +
			'</button>'
		);
		var $panel = $(
			'<div class="mp-msd-panel">' +
				'<div class="mp-msd-search-wrap"><i class="fas fa-search"></i>' +
					'<input type="text" class="form-control form-control-sm mp-msd-search" placeholder="Search…" aria-label="Search ' + esc(labelText) + '"></div>' +
				'<div class="mp-msd-actions">' +
					'<button type="button" class="btn btn-xs mp-msd-all"><i class="fas fa-check-double"></i> Select All</button>' +
					'<button type="button" class="btn btn-xs mp-msd-none"><i class="far fa-square"></i> Clear All</button>' +
					'<span class="mp-msd-count badge badge-primary">0 Selected</span>' +
				'</div>' +
				'<div class="mp-msd-list" role="listbox" aria-multiselectable="true"></div>' +
				'<div class="mp-msd-empty text-muted">No matches</div>' +
			'</div>'
		);
		var $list = $panel.find('.mp-msd-list');

		$sel.find('option').each(function () {
			var $o = $(this);
			var $item = $('<label class="mp-msd-item"><input type="checkbox"' + ($o.prop('selected') ? ' checked' : '') + '><span>' + esc($o.text()) + '</span></label>');
			$item.children('input').attr('data-val', $o.attr('value'));
			$list.append($item);
		});

		$wrap.append($toggle).append($panel);
		$sel.after($wrap);

		var $summary = $toggle.children('.mp-msd-summary');
		var placeholder = 'Select ' + labelText + '…';

		// Oct 2026 — render EVERY selected option as its own visible chip inside
		// the summary (auto-growing, flex-wrapping) instead of truncating the
		// list to "First, Second +N". A chip's × button unselects just that
		// option without opening the panel.
		function updateSummary() {
			var items = [];
			$list.find('input:checked').each(function () {
				items.push({ val: $(this).attr('data-val'), name: $(this).next('span').text() });
			});
			var n = items.length;
			$summary.empty();
			if (n === 0) {
				$summary.text(placeholder).addClass('mp-msd-ph');
				$toggle.removeAttr('title');
			} else {
				$summary.removeClass('mp-msd-ph');
				for (var i = 0; i < items.length; i++) {
					var $chip = $('<span class="mp-msd-chip"></span>').attr('data-val', items[i].val)
						.append($('<span class="mp-msd-chip-text"></span>').text(items[i].name))
						.append($('<button type="button" class="mp-msd-chip-x" aria-label="Remove">&times;</button>'));
					$summary.append($chip);
				}
				$toggle.attr('title', items.map(function(it){ return it.name; }).join(', '));
			}
			$panel.find('.mp-msd-count').text(n + ' Selected');
		}
		function syncToSelect() {
			var checked = {};
			$list.find('input:checked').each(function () { checked[$(this).attr('data-val')] = true; });
			$sel.find('option').each(function () { $(this).prop('selected', !!checked[$(this).attr('value')]); });
			updateSummary();
			$sel.trigger('change');   // keep jQuery-validate / listeners in sync
		}

		// Chip × removes just that selection; stop the click from toggling the panel.
		// Attribute lookup is done by iterating, not by selector, so a value with
		// quotes / backslashes can never break the match on older jQuery (<3.0,
		// no $.escapeSelector) and no injection into the selector string is possible.
		$summary.on('click', '.mp-msd-chip-x', function (e) {
			e.preventDefault(); e.stopPropagation();
			var val = $(this).closest('.mp-msd-chip').attr('data-val');
			$list.find('input[type=checkbox]').each(function () {
				if ($(this).attr('data-val') === val) { $(this).prop('checked', false); return false; }
			});
			syncToSelect();
		});
		// Clicking a chip body stays on the toggle button -> the panel opens as before.

		$list.on('change', 'input[type=checkbox]', syncToSelect);
		$panel.find('.mp-msd-all').on('click', function () { $list.find('.mp-msd-item').filter(':visible').children('input').prop('checked', true); syncToSelect(); });
		$panel.find('.mp-msd-none').on('click', function () { $list.find('input').prop('checked', false); syncToSelect(); });
		$panel.find('.mp-msd-search').on('input', function () {
			var q = $.trim($(this).val()).toLowerCase(), shown = 0;
			$list.find('.mp-msd-item').each(function () {
				var m = $(this).children('span').text().toLowerCase().indexOf(q) !== -1;
				$(this).toggle(m); if (m) { shown++; }
			});
			$panel.find('.mp-msd-empty').toggle(shown === 0);
		});

		function openPanel() { $wrap.addClass('open'); $toggle.attr('aria-expanded', 'true'); setTimeout(function () { $panel.find('.mp-msd-search').trigger('focus'); }, 0); }
		function closePanel() { $wrap.removeClass('open'); $toggle.attr('aria-expanded', 'false'); }
		$toggle.on('click', function (e) { e.preventDefault(); e.stopPropagation(); if ($wrap.hasClass('open')) { closePanel(); } else { openPanel(); } });
		$panel.on('click', function (e) { e.stopPropagation(); });
		$(document).off('mousedown.mpmsd_' + uid).on('mousedown.mpmsd_' + uid, function (e) { if (!$.contains($wrap[0], e.target)) { closePanel(); } });
		$wrap.on('keydown', function (e) { if (e.key === 'Escape' || e.keyCode === 27) { closePanel(); $toggle.trigger('focus'); } });

		updateSummary();
	}

	window.mpqmsMultiselectDropdown = build;
	window.mpqmsSelect2Multi = build;   // backward-compatible alias
})();
