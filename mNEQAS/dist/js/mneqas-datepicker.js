/* mNEQAS — shared Tempus Dominus (datetimepicker) positioning.
 *
 * Tempus Dominus 5 inserts its calendar INSIDE the field's .input-group and
 * absolutely positions it. The calendar is therefore cut off by any scrolling
 * or overflow:hidden ancestor (.table-responsive, cards) and trapped in the
 * stacking context of its wizard pane, so later elements such as the wizard
 * footer paint over it. Its up/down choice also ignores the viewport.
 *
 * While a calendar is open this helper moves it to <body> and positions it with
 * position:fixed against the field:
 *   - below the field when it fits, above when it fits there instead,
 *     otherwise on the side with more room, always kept inside the viewport;
 *   - clamped horizontally so it never leaves the left/right edge;
 *   - re-placed on page/container scroll, window resize and calendar view
 *     changes (days <-> months <-> years), and closed if the field scrolls away.
 * The library removes the calendar itself on hide, so nothing is left behind.
 *
 * It does not initialise pickers itself. It only (a) supplies the application's
 * single date format as the library default, so a calendar the library creates
 * on its own can never produce a date+time value, and (b) keeps a field the
 * server rendered as readonly read-only when a page initialises its picker.
 * Loaded once from includes/css-jquery.php, right after the library.
 */
(function ($) {
    'use strict';
    if (!$ || !$.fn || !$.fn.datetimepicker || $.fn.datetimepicker.mnPortal) { return; }
    $.fn.datetimepicker.mnPortal = true;

    var Ctor = $.fn.datetimepicker.Constructor;
    if (Ctor && Ctor.Default && !Ctor.Default.format) {
        Ctor.Default.format = 'YYYY-MM-DD';          // every page already passes this format
    }

    /* Keep server-rendered read-only date fields read-only.
       The library applies its default option `readonly: false` while initialising,
       which REMOVES a `readonly` attribute the page rendered on a locked field
       (received shipment, locked header, read-only role), so the field looked
       editable and its calendar still opened. When a picker is created on an
       input that is already readonly — and the page did not choose a readonly
       value itself — it is created with `readonly: true`, which keeps the field
       locked and keeps its calendar closed. */
    var tdInterface = $.fn.datetimepicker;
    $.fn.datetimepicker = function (option) {
        if (option !== undefined && !$.isPlainObject(option)) {
            return tdInterface.apply(this, arguments);      // method calls: 'date', 'hide', ...
        }
        return this.each(function () {
            var $el = $(this), opts = option;
            if (!$el.data('datetimepicker') && !(option && 'readonly' in option)) {
                var $in = $el.is('input') ? $el : $el.find('input.datetimepicker-input').first();
                if ($in.length && $in.prop('readonly')) { opts = $.extend({}, option, { readonly: true }); }
            }
            tdInterface.call($el, opts);
        });
    };
    $.extend($.fn.datetimepicker, tdInterface);             // Constructor, noConflict, ...

    var GAP = 4, PAD = 6, Z = 1060;                   // above modals (1050), same layer as popovers
    var open = null;                                  // { el, widget, anchor, onScroll, ro }

    function anchorOf(el) {
        // Position against the whole input group (field + icon) when there is one.
        var $el = $(el);
        var $grp = $el.is('.input-group') ? $el : $el.closest('.input-group');
        return ($grp.length ? $grp : $el)[0];
    }

    function place(initial) {
        if (!open) { return; }
        var w = open.widget;
        if (!w || !document.body.contains(w)) { close(); return; }
        if (w.parentNode !== document.body) { document.body.appendChild(w); }   // library re-inserts it on resize

        var r  = open.anchor.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;

        // The field has been scrolled off the screen while open: close like a native
        // dropdown. Not on the opening placement itself — a calendar opened for a field
        // that is not (fully) on screen is simply clamped into view.
        if (initial !== true && (r.bottom < 0 || r.top > vh || r.right < 0 || r.left > vw)) {
            $(open.el).datetimepicker('hide');
            return;
        }

        var h = w.offsetHeight, wd = w.offsetWidth;
        var below = vh - r.bottom - GAP - PAD;
        var above = r.top - GAP - PAD;
        var up = (h > below) && (h <= above || above > below);
        var top = up ? (r.top - GAP - h) : (r.bottom + GAP);
        top = Math.max(PAD, Math.min(top, vh - h - PAD));

        var left = r.left;
        if (left + wd > vw - PAD) { left = Math.max(PAD, Math.min(r.right - wd, vw - wd - PAD)); }
        left = Math.max(PAD, left);

        var $w = $(w);
        $w.toggleClass('top', up).toggleClass('bottom', !up).removeClass('float-right');
        var s = w.style;
        s.setProperty('position', 'fixed', 'important');
        s.setProperty('z-index', String(Z), 'important');
        s.setProperty('top', Math.round(top) + 'px', 'important');
        s.setProperty('left', Math.round(left) + 'px', 'important');
        s.setProperty('bottom', 'auto', 'important');
        s.setProperty('right', 'auto', 'important');
        s.setProperty('margin', '0', 'important');
    }

    function close() {
        if (!open) { return; }
        window.removeEventListener('scroll', open.onScroll, true);
        $(window).off('resize.mnDatepicker');
        if (open.ro) { open.ro.disconnect(); }
        $(open.widget).off('click.mnDatepicker');
        open = null;
    }

    $(document).on('show.datetimepicker', function (e) {
        var el = e.target;
        var data = $(el).data('datetimepicker');
        var w = data && data.widget && data.widget[0];
        if (!w) { return; }
        close();                                      // only one calendar is ever open

        open = { el: el, widget: w, anchor: anchorOf(el) };
        w.classList.add('mn-dp-portal');
        document.body.appendChild(w);
        place(true);

        open.onScroll = function (ev) {
            // ignore scrolling that happens inside the calendar itself
            if (open && ev.target !== open.widget && !(ev.target.nodeType === 1 && open.widget.contains(ev.target))) { place(); }
        };
        window.addEventListener('scroll', open.onScroll, true);          // page + every scrolling container
        // Runs after the library's own resize handler, which puts the widget back in the field.
        $(window).on('resize.mnDatepicker', function () { setTimeout(function () { place(true); }, 0); });
        // Day / month / year views differ in height: re-place after every click inside
        // the calendar, and whenever its size changes for any other reason.
        $(w).on('click.mnDatepicker', function () { setTimeout(function () { place(true); }, 0); });
        if (window.ResizeObserver) {
            open.ro = new ResizeObserver(function () { place(true); });
            open.ro.observe(w);
        }
    });

    $(document).on('hide.datetimepicker', function (e) {
        if (open && open.el === e.target) { close(); }
    });
})(window.jQuery);
