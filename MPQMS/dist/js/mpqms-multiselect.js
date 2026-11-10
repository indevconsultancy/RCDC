/*
 * MPQMS checkbox multi-select card.
 * A presentation-only enhancer that re-skins an existing <select multiple> into a
 * modern searchable checkbox card (search box, Select All / Clear All, scrollable
 * list, selected-count badge, sticky header) WITHOUT changing the form data:
 * the original <select> is kept in the DOM (same name/id/value/required) and the
 * checkboxes simply toggle its <option>.selected. If this script fails, the page
 * degrades to the native multi-select and still submits correctly.
 */
(function () {
	function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

	window.mpqmsMultiselectCard = function (selector, title) {
		var $sel = $(selector);
		if (!$sel.length || $sel.data('mpqmsEnhanced')) { return; }
		$sel.data('mpqmsEnhanced', true);

		// keep the real control for submit + validation, but hide it (as the dual-listbox did)
		$sel.addClass('mp-msel-source').hide();
		// the original inline <label> is replaced by the card's own title
		$sel.closest('.form-group').children('label').hide();

		var uid = ($sel.attr('id') || 'msel') + '_mp';

		var $card = $(
			'<div class="mp-msel card" role="group" aria-label="' + esc(title) + '">' +
				'<div class="mp-msel-head">' +
					'<div class="mp-msel-title"><span class="mp-msel-name">' + esc(title) + '</span>' +
						'<span class="mp-msel-count badge badge-primary">0 Selected</span></div>' +
					'<div class="mp-msel-search-wrap">' +
						'<i class="fas fa-search"></i>' +
						'<input type="text" class="form-control form-control-sm mp-msel-search" placeholder="Search ' + esc(title) + '…" aria-label="Search ' + esc(title) + '">' +
					'</div>' +
					'<div class="mp-msel-actions">' +
						'<button type="button" class="btn btn-xs mp-msel-all"><i class="fas fa-check-double"></i> Select All</button>' +
						'<button type="button" class="btn btn-xs mp-msel-none"><i class="far fa-square"></i> Clear All</button>' +
						'<span class="mp-msel-empty text-muted"></span>' +
					'</div>' +
				'</div>' +
				'<div class="mp-msel-list" role="group"></div>' +
			'</div>'
		);
		var $list = $card.find('.mp-msel-list');

		$sel.find('option').each(function (i) {
			var $o = $(this);
			var $item = $(
				'<label class="mp-msel-item">' +
					'<input type="checkbox" id="' + uid + '_' + i + '"' + ($o.prop('selected') ? ' checked' : '') + '>' +
					'<span>' + esc($o.text()) + '</span>' +
				'</label>'
			);
			$item.find('input').attr('data-val', $o.attr('value'));
			$list.append($item);
		});

		$sel.after($card);

		function updateCount() {
			var n = $list.find('input:checked').length;
			$card.find('.mp-msel-count').text(n + ' Selected');
		}
		function syncToSelect() {
			var checked = {};
			$list.find('input:checked').each(function () { checked[$(this).attr('data-val')] = true; });
			$sel.find('option').each(function () { $(this).prop('selected', !!checked[$(this).attr('value')]); });
			updateCount();
		}

		$list.on('change', 'input[type=checkbox]', syncToSelect);

		$card.find('.mp-msel-all').on('click', function () {
			$list.find('.mp-msel-item').filter(':visible').find('input').prop('checked', true);
			syncToSelect();
		});
		$card.find('.mp-msel-none').on('click', function () {
			$list.find('input').prop('checked', false);
			syncToSelect();
		});
		$card.find('.mp-msel-search').on('input', function () {
			var q = $.trim($(this).val()).toLowerCase();
			var shown = 0;
			$list.find('.mp-msel-item').each(function () {
				var match = $(this).find('span').text().toLowerCase().indexOf(q) !== -1;
				$(this).toggle(match);
				if (match) { shown++; }
			});
			$card.find('.mp-msel-empty').text(shown === 0 ? 'No matches' : '');
		});

		updateCount();
	};
})();
