/*!
 * mNEQAS — shared wizard/stepper state helper.
 *
 * One place that decides what each step of a multi-step form shows, so every
 * module behaves the same way in Create and in Edit/View mode.
 *
 * Rules (agreed standard):
 *   Completed record  -> every step is Completed; the step being viewed is also Active.
 *   Draft record      -> saved/reached steps Completed, the step being viewed Active,
 *                        everything else Pending (Locked when its prerequisite is unmet).
 *   Create mode       -> unchanged: steps before the current one are Completed.
 *
 * Pure state calculation: it never touches form values, validation, payloads or
 * any backend call.
 *
 *   mnStepperState({ steps, current, completed, done, reachable })
 *     steps      [key, ...]  step keys in display order (number or string)
 *     current    key         the step on screen
 *     completed  bool        the whole record is submitted/finalised
 *     done       [key, ...]  steps already saved (ignored when completed = true)
 *     reachable  [key, ...]  optional: steps the user may open; others become 'locked'
 *   -> { key: 'done' | 'active done' | 'active' | 'pending' | 'locked', ... }
 *
 *   mnApplyStepperState($elements, stateMap, getKey)
 *     writes the classes done/active/locked onto the step elements.
 */
(function (window, $) {
    'use strict';

    function keyList(v) { return Array.isArray(v) ? v.slice() : []; }
    function has(list, k) { return list.indexOf(k) !== -1; }

    function mnStepperState(opts) {
        opts = opts || {};
        var steps     = keyList(opts.steps);
        var done      = keyList(opts.done);
        var reachable = Array.isArray(opts.reachable) ? opts.reachable : null;
        var current   = opts.current;
        var completed = !!opts.completed;
        var out = {};

        steps.forEach(function (key) {
            var isCurrent = (key === current);
            var isDone    = completed || has(done, key);
            var state;

            if (isDone && isCurrent)      { state = 'active done'; }   // completed stage being viewed
            else if (isDone)              { state = 'done'; }
            else if (isCurrent)           { state = 'active'; }
            else if (reachable && !has(reachable, key)) { state = 'locked'; }
            else                          { state = 'pending'; }

            out[key] = state;
        });
        return out;
    }

    function mnApplyStepperState($elements, stateMap, getKey) {
        if (!$ || !$elements || !$elements.each) { return; }
        $elements.each(function () {
            var el    = this;
            var key   = getKey ? getKey($(el), el) : $(el).data('step');
            var state = stateMap[key];
            $(el).removeClass('active done locked').removeAttr('aria-current');
            if (!state) { return; }
            if (state.indexOf('done')   !== -1) { $(el).addClass('done'); }
            if (state.indexOf('active') !== -1) { $(el).addClass('active').attr('aria-current', 'step'); }
            if (state === 'locked')            { $(el).addClass('locked'); }
        });
    }

    window.mnStepperState      = mnStepperState;
    window.mnApplyStepperState = mnApplyStepperState;
})(window, window.jQuery);
