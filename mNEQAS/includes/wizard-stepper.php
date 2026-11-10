<?php
/**
 * ============================================================================
 *  Reusable Wizard Progress Stepper  (shared across every module's wizard)
 * ----------------------------------------------------------------------------
 *  A modern horizontal stepper: numbered circles joined by a progress line.
 *  Completed + active steps are blue (#007bff); pending steps are grey.
 *  Fully role-driven and dynamic — pass any number of steps and it adapts.
 *
 *  USAGE
 *  -----
 *      require_once __DIR__ . "/../includes/wizard-stepper.php";
 *
 *      render_wizard_stepper([
 *          ['id' => 1, 'title' => 'MP Slide Info',    'status' => 'completed'],
 *          ['id' => 2, 'title' => 'District Results', 'status' => 'in-progress'],
 *          ['id' => 3, 'title' => 'Shipment Receive', 'status' => 'pending'],
 *          ['id' => 4, 'title' => 'Controller 1',     'status' => 'pending'],
 *          ['id' => 5, 'title' => 'Controller 2',     'status' => 'pending'],
 *          ['id' => 6, 'title' => 'Review',           'status' => 'pending'],
 *      ]);
 *
 *  PROPS (each step)
 *  -----------------
 *      id      : number/label shown inside the circle (ignored once completed)
 *      title   : step caption
 *      status  : 'completed' | 'in-progress' | 'pending'
 *
 *  The CSS is emitted only once per request, so the partial is safe to call
 *  from any page without linking an extra stylesheet.
 * ============================================================================
 */

if (!function_exists('render_wizard_stepper')) {

    /**
     * Emit the shared stepper CSS a single time per request.
     */
    function wizard_stepper_styles()
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        return <<<CSS
<style>
    /* ---- Reusable wizard progress stepper ---- */
    .wzp{display:flex;flex-wrap:nowrap;align-items:flex-start;margin:0 0 1.75rem;padding:0;font-family:inherit;}
    .wzp *{box-sizing:border-box;}
    .wzp-step{flex:1 1 0;min-width:0;position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;padding:0 .25rem;}
    /* step circle */
    .wzp-ico{position:relative;z-index:1;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#E5E7EB;color:#6B7280;font-weight:700;font-size:1rem;line-height:1;border:3px solid #fff;transition:.2s;}
    .wzp-ico .wzp-check{width:20px;height:20px;fill:none;stroke:#fff;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;}
    /* progress line (right of each circle, centred behind the circles) */
    .wzp-step:not(:last-child)::before{content:"";position:absolute;top:22px;left:50%;width:100%;height:4px;background:#E5E7EB;z-index:0;}
    /* labels */
    .wzp-body{margin-top:.55rem;}
    .wzp-title{font-size:.85rem;font-weight:600;color:#6B7280;line-height:1.25;}
    .wzp-status{margin-top:.1rem;font-size:.75rem;font-weight:600;color:#9CA3AF;}
    /* completed */
    .wzp-step.wzp-completed .wzp-ico{background:#007bff;color:#fff;border-color:#fff;}
    .wzp-step.wzp-completed .wzp-title{color:#111827;}
    .wzp-step.wzp-completed .wzp-status{color:#007bff;}
    .wzp-step.wzp-completed:not(:last-child)::before{background:#007bff;}
    /* in-progress / active */
    .wzp-step.wzp-active .wzp-ico{background:#007bff;color:#fff;border-color:#fff;box-shadow:0 0 0 4px rgba(0,123,255,.18);}
    .wzp-step.wzp-active .wzp-title{color:#111827;font-weight:700;}
    .wzp-step.wzp-active .wzp-status{color:#007bff;}
    /* responsive: stack vertically on small screens */
    @media (max-width:640px){
        .wzp{flex-direction:column;align-items:stretch;}
        .wzp-step{flex:0 0 auto;flex-direction:row;align-items:flex-start;text-align:left;padding:.4rem 0;}
        .wzp-ico{flex:0 0 auto;}
        .wzp-body{margin-top:0;margin-left:.85rem;padding-top:.2rem;}
        .wzp-step:not(:last-child)::before{top:44px;left:22px;transform:translateX(-50%);width:4px;height:100%;}
    }
</style>
CSS;
    }

    /**
     * Render the stepper.
     *
     * @param array  $steps  list of ['id','title','status']
     * @param string $attrs  optional extra attributes for the wrapper (e.g. id="...")
     */
    function render_wizard_stepper(array $steps, $attrs = '')
    {
        $html  = wizard_stepper_styles();
        $html .= '<div class="wzp"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';

        foreach ($steps as $step) {
            $status = isset($step['status']) ? $step['status'] : 'pending';

            switch ($status) {
                case 'completed':
                    $cls   = 'wzp-completed';
                    $label = 'Completed';
                    break;
                case 'in-progress':
                    $cls   = 'wzp-active';
                    $label = 'In Progress';
                    break;
                default:
                    $cls   = 'wzp-pending';
                    $label = 'Pending';
                    break;
            }

            $num   = isset($step['id'])    ? htmlspecialchars($step['id'], ENT_QUOTES)    : '';
            $title = isset($step['title']) ? htmlspecialchars($step['title'], ENT_QUOTES) : '';

            // Completed steps show a white check; all others show their number.
            if ($status === 'completed') {
                $icon = '<svg class="wzp-check" viewBox="0 0 24 24" aria-hidden="true">'
                      . '<polyline points="20 6 9 17 4 12"></polyline></svg>';
            } else {
                $icon = '<span class="wzp-num">' . $num . '</span>';
            }

            $html .= '<div class="wzp-step ' . $cls . '">'
                   .     '<div class="wzp-ico">' . $icon . '</div>'
                   .     '<div class="wzp-body">'
                   .         '<div class="wzp-title">' . $title . '</div>'
                   .         '<div class="wzp-status">' . $label . '</div>'
                   .     '</div>'
                   . '</div>';
        }

        $html .= '</div>';
        echo $html;
    }
}
