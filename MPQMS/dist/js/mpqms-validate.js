/*
 * MPQMS — unified client-side validation defaults (presentation/behaviour only).
 *
 * Centralizes the jQuery Validate look-and-feel so EVERY form validates identically:
 *   - red inline message (span.invalid-feedback) placed beside the field, in its .form-group
 *   - red border (.is-invalid) on the control, cleared the moment the field becomes valid
 *     (jQuery Validate re-checks on keyup/blur/change by default)
 *   - on a blocked submit, focus + smooth-scroll to the FIRST invalid field
 *
 * This only standardizes HOW validation is shown/behaves. WHICH fields are required stays a
 * per-form decision (via each field's `required` attribute / rules), so no business rule,
 * workflow, or existing functionality changes. Forms that call $(...).validate() inherit these
 * defaults automatically; a form may still pass its own options to override them.
 */
/* CSRF: attach the per-session token (from the <meta name="csrf-token">) as an
 * X-CSRF-Token header on EVERY jQuery AJAX request. This covers all authenticated
 * writes (registration/result/settings/delete) with no change to their submit code,
 * and a cross-site forged POST cannot set this custom header — so it's rejected. */
(function () {
	if (!window.jQuery) { return; }
	jQuery(document).ajaxSend(function (e, xhr) {
		var m = document.querySelector('meta[name="csrf-token"]');
		if (m && m.content) { xhr.setRequestHeader('X-CSRF-Token', m.content); }
	});
})();

(function () {
	if (!(window.jQuery && jQuery.validator)) { return; }

	jQuery.validator.setDefaults({
		errorElement: 'span',
		errorPlacement: function (error, element) {
			error.addClass('invalid-feedback');
			var $fg = element.closest('.form-group');
			(($fg.length ? $fg : element.parent())).append(error);
		},
		highlight: function (element) { jQuery(element).addClass('is-invalid'); },
		unhighlight: function (element) { jQuery(element).removeClass('is-invalid'); },
		invalidHandler: function (event, validator) {
			if (validator.errorList && validator.errorList.length) {
				var el = validator.errorList[0].element;
				var $target = jQuery(el).is(':visible') ? jQuery(el) : jQuery(el).closest('.form-group');
				if ($target[0] && $target[0].scrollIntoView) {
					$target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			}
		}
	});
})();
