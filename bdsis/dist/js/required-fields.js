/*
 * Marks the label of every required field with a red asterisk.
 *
 * Done here rather than in the markup because required fields are spread across
 * thousands of lines of PHP, and several are injected after page load -- the
 * "+ Add Pathogen" row clones a template, and the E.coli AST and facility
 * selects arrive by AJAX. Editing markup would miss those and would drift the
 * moment a new field is added; this stays correct on its own.
 *
 * Label resolution: take the first label inside the field's .form-group that is
 * not a .form-check-label. That deliberately skips the per-option labels of a
 * radio group ("Surveillance", "Outbreak") and marks the group label
 * ("Category:") once, rather than starring every option.
 */
(function () {
    'use strict';

    var STAR_CLASS = 'required-star';
    var FIELDS = 'input[required], select[required], textarea[required]';

    function findLabel(el) {
        var group = el.closest ? el.closest('.form-group') : null;
        if (group) {
            var labels = group.querySelectorAll('label');
            for (var i = 0; i < labels.length; i++) {
                if (!labels[i].classList.contains('form-check-label')) {
                    return labels[i];
                }
            }
        }

        // No .form-group wrapper: fall back to an explicit for= association.
        // Attribute values in this codebase are inconsistently spaced
        // (for="x", for ="x", for = "x"), so compare trimmed rather than
        // relying on an attribute selector.
        var id = el.getAttribute('id');
        if (id) {
            var withFor = document.querySelectorAll('label[for]');
            for (var j = 0; j < withFor.length; j++) {
                if (withFor[j].getAttribute('for').trim() === id &&
                    !withFor[j].classList.contains('form-check-label')) {
                    return withFor[j];
                }
            }
        }
        return null;
    }

    function mark(el) {
        if (el.type === 'hidden') { return; }

        var label = findLabel(el);
        // Idempotent: a radio group resolves to one shared label, and the
        // observer re-scans on every DOM change, so both would otherwise
        // append duplicate stars.
        if (!label || label.querySelector('.' + STAR_CLASS)) { return; }

        var star = document.createElement('span');
        star.className = STAR_CLASS;
        star.setAttribute('aria-hidden', 'true');
        star.textContent = '*';

        // Most labels read "Field name:" -- put the star before the colon so it
        // renders as "Field name *:" rather than "Field name: *".
        var last = label.lastChild;
        if (last && last.nodeType === 3 && /:\s*$/.test(last.nodeValue)) {
            last.nodeValue = last.nodeValue.replace(/:\s*$/, '');
            label.appendChild(star);
            label.appendChild(document.createTextNode(':'));
        } else {
            label.appendChild(star);
        }
    }

    function scan(root) {
        var scope = (root && root.querySelectorAll) ? root : document;
        var fields = scope.querySelectorAll(FIELDS);
        for (var i = 0; i < fields.length; i++) {
            mark(fields[i]);
        }
        // The changed node may itself be a required field.
        if (root && root.matches && root.matches(FIELDS)) { mark(root); }
    }

    function init() {
        scan(document);

        if (!window.MutationObserver) { return; }
        var pending = null;
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    // Coalesce bursts (a cloned row fires many mutations) into
                    // one rescan; mark() is idempotent so a full pass is safe.
                    if (pending) { clearTimeout(pending); }
                    pending = setTimeout(function () { pending = null; scan(document); }, 50);
                    return;
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
