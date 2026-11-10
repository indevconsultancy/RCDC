<?php
/* Cache busting for the rich-text editor assets.
   A browser that has an older summernote-bs4.min.js cached keeps using it after
   a deploy, which is one way the editor can look broken on a user's machine while
   it works everywhere else. Appending the file's own modification time makes the
   URL change whenever — and only whenever — the file actually changes, so a stale
   copy is fetched again exactly once and normal caching still applies afterwards.
   (time() would also force a reload, but on every single request, which would
   re-download the assets for every page view on a slow connection.) */
if (!function_exists('mn_asset')) {
    function mn_asset($relative) {
        $path = __DIR__ . '/../' . ltrim($relative, '/');
        $ver  = @filemtime($path);
        if ($ver === false) { $ver = '1'; }        // missing file: still emit a stable url
        return '../' . ltrim($relative, '/') . '?v=' . $ver;
    }
}
?>
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- daterange picker -->
    <link rel="stylesheet" href="../plugins/daterangepicker/daterangepicker.css">
    <!-- iCheck for checkboxes and radio inputs -->
    <link rel="stylesheet" href="../plugins/icheck-bootstrap/icheck-bootstrap.min.css">
    <!-- Bootstrap Color Picker -->
    <link rel="stylesheet" href="../plugins/bootstrap-colorpicker/css/bootstrap-colorpicker.min.css">
    <!-- Tempusdominus Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="../plugins/select2/css/select2.min.css">
    <link rel="stylesheet" href="../plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
    <!-- Bootstrap4 Duallistbox -->
    <link rel="stylesheet" href="../plugins/bootstrap4-duallistbox/bootstrap-duallistbox.min.css">
    <!-- BS Stepper -->
    <link rel="stylesheet" href="../plugins/bs-stepper/css/bs-stepper.min.css">
    <!-- dropzonejs -->
    <link rel="stylesheet" href="../plugins/dropzone/min/dropzone.min.css">
    <!-- SweetAlert2 -->
     <link rel="stylesheet" href="../plugins/sweetalert2-theme-bootstrap-4/bootstrap-4.min.css">
    <!-- Toastr -->
    <link rel="stylesheet" href="../plugins/toastr/toastr.min.css">
    <!-- summernote -->
    <link rel="stylesheet" href="<?= mn_asset('plugins/summernote/summernote-bs4.min.css') ?>">
     <!-- DataTables -->
    <link rel="stylesheet" href="../plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="../plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">

    <!-- ==========================================================================
         mNEQAS global design system (embedded so it applies on EVERY page/module).
         Loaded after AdminLTE so these rules win. Reusable components:
           .mneqas-grid (+ .cols-2/.cols-3/.cols-4) .form-field  -> FormRow / FormField
           .wiz-nav .wiz-step .wiz-pane                          -> WizardStepper
           .card-footer / .form-actions                          -> ActionButtons
           .filter-bar / .filter-row                             -> FilterBar
         ========================================================================== -->
    <style>
      :root{
        --mneqas-primary:#007bff; --mneqas-primary-dark:#0062cc;
        --mneqas-primary-soft:rgba(0,123,255,.12); --mneqas-ring:rgba(0,123,255,.25);
        --mneqas-success:#28a745; --mneqas-danger:#dc3545;
        --mneqas-ink:#2d3748; --mneqas-muted:#6c757d;
        --mneqas-border:#dfe3e8; --mneqas-border-strong:#ced4da;
        --mneqas-surface:#fff; --mneqas-surface-alt:#f8f9fb;
        /* radius raised 4px -> 8px to match the NTESIS geometry (see dist/css/mneqas-theme.css) */
        --mneqas-radius:8px; --mneqas-control-h:42px; --mneqas-gap:1rem;
        --mneqas-shadow:0 1px 3px rgba(16,24,40,.06),0 1px 2px rgba(16,24,40,.04);
        --mneqas-font:'Source Sans Pro',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
      }

      /* ---- Typography ---- */
      body,.content-wrapper{font-family:var(--mneqas-font);color:var(--mneqas-ink);}
      .content-header h1{font-weight:600;letter-spacing:-.01em;}
      .card-title{font-weight:600;}
      .text-primary{color:var(--mneqas-primary)!important;}

      /* ---- Cards ---- */
      .card{border:1px solid var(--mneqas-border);border-radius:var(--mneqas-radius);box-shadow:var(--mneqas-shadow);}
      .card.card-primary.card-outline{border-top:3px solid var(--mneqas-primary);}
      .card>.card-header{border-bottom:1px solid var(--mneqas-border);background:var(--mneqas-surface);}
      .card-header .card-title{font-size:1rem;margin-bottom:0;}

      /* ---- Labels ---- */
      label,.col-form-label,.col-form-label-sm{color:var(--mneqas-ink);font-weight:600;font-size:.8125rem;margin-bottom:.35rem;}
      .form-field>label{display:block;}

      /* ---- Form controls: ONE size everywhere (42px h, 4px radius, blue focus) ---- */
      .form-control,.custom-select,select.form-control,.input-group>.form-control{
        min-height:var(--mneqas-control-h);height:var(--mneqas-control-h);
        padding:.5rem .85rem;font-size:.875rem;color:var(--mneqas-ink);
        border:1px solid var(--mneqas-border-strong);border-radius:var(--mneqas-radius);
        box-shadow:none;transition:border-color .15s ease,box-shadow .15s ease;
      }
      textarea.form-control{height:auto;min-height:110px;padding:.6rem .85rem;line-height:1.5;}
      .form-control:focus,.custom-select:focus,select.form-control:focus{
        border-color:var(--mneqas-primary);box-shadow:0 0 0 .2rem var(--mneqas-ring);outline:none;
      }
      .form-control::placeholder{color:#9aa2ad;opacity:1;}
      .form-control[readonly],.form-control:disabled,.custom-select:disabled{
        background-color:var(--mneqas-surface-alt);color:var(--mneqas-muted);cursor:not-allowed;
      }
      /* Compact, self-consistent controls inside data-entry tables */
      .table .form-control,.table .form-control-sm,.form-control-sm{
        height:34px;min-height:34px;padding:.25rem .5rem;font-size:.8125rem;border-radius:var(--mneqas-radius);
      }
      .table td .form-control{margin-bottom:0;}

      /* ---- Date-picker input groups (Tempusdominus) ---- */
      .input-group.date .form-control{border-right:0;}
      .input-group.date .input-group-append .input-group-text{
        min-height:var(--mneqas-control-h);background:var(--mneqas-surface-alt);
        border:1px solid var(--mneqas-border-strong);border-left:0;color:var(--mneqas-muted);
        border-top-right-radius:var(--mneqas-radius);border-bottom-right-radius:var(--mneqas-radius);
      }
      .input-group.date:focus-within .form-control,.input-group.date:focus-within .input-group-text{border-color:var(--mneqas-primary);}

      /* ---- Select2: match control height + focus ---- */
      .select2-container--bootstrap4 .select2-selection,
      .select2-container--default .select2-selection--single{
        min-height:var(--mneqas-control-h)!important;height:var(--mneqas-control-h)!important;
        border:1px solid var(--mneqas-border-strong)!important;border-radius:var(--mneqas-radius)!important;
        display:flex;align-items:center;
      }
      .select2-container--default .select2-selection--single .select2-selection__rendered{
        line-height:calc(var(--mneqas-control-h) - 2px);padding-left:.85rem;color:var(--mneqas-ink);
      }
      .select2-container--default .select2-selection--single .select2-selection__arrow{height:calc(var(--mneqas-control-h) - 2px);}
      .select2-container--bootstrap4.select2-container--focus .select2-selection,
      .select2-container--default.select2-container--focus .select2-selection--single{
        border-color:var(--mneqas-primary)!important;box-shadow:0 0 0 .2rem var(--mneqas-ring);
      }

      /* ---- Reusable responsive form grid (max 4 per row) ---- */
      .mneqas-grid{display:grid;gap:var(--mneqas-gap) 1.25rem;grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:.5rem;}
      .mneqas-grid.cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
      .mneqas-grid.cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}
      .mneqas-grid .form-field{display:flex;flex-direction:column;min-width:0;margin-bottom:0;}
      .mneqas-grid .form-field.span-2{grid-column:span 2;}
      .mneqas-grid .form-field.span-3{grid-column:span 3;}
      .mneqas-grid .form-field.span-4{grid-column:1 / -1;}
      .mneqas-grid .form-field>.form-control,.mneqas-grid .form-field>.select2,
      .mneqas-grid .form-field>.input-group,.mneqas-grid .form-field>div{width:100%;}
      @media (max-width:991.98px){.mneqas-grid,.mneqas-grid.cols-3{grid-template-columns:repeat(2,minmax(0,1fr));}}
      @media (max-width:575.98px){
        .mneqas-grid,.mneqas-grid.cols-2,.mneqas-grid.cols-3{grid-template-columns:1fr;}
        .mneqas-grid .form-field.span-2,.mneqas-grid .form-field.span-3{grid-column:auto;}
      }
      /* Keep Bootstrap-grid form rows aligned along the control baseline */
      .form-row-aligned,.filter-row{align-items:flex-end;}

      /* ---- Buttons: consistent styling, blue primary (#007bff) ---- */
      .btn{border-radius:var(--mneqas-radius);font-weight:600;font-size:.875rem;padding:.5rem 1.1rem;letter-spacing:.01em;transition:background-color .15s ease,box-shadow .15s ease,transform .05s ease;}
      .btn:active{transform:translateY(1px);}
      .btn-sm{padding:.3rem .7rem;font-size:.8125rem;}
      .btn-primary,.bg-gradient-primary,.btn.bg-gradient-primary{background:var(--mneqas-primary)!important;border-color:var(--mneqas-primary)!important;color:#fff!important;}
      .btn-primary:hover,.bg-gradient-primary:hover,.btn.bg-gradient-primary:hover{background:var(--mneqas-primary-dark)!important;border-color:var(--mneqas-primary-dark)!important;}
      .btn-primary:focus,.bg-gradient-primary:focus{box-shadow:0 0 0 .2rem var(--mneqas-ring)!important;}
      .btn-outline-secondary,.btn-outline-primary{background:#fff;}

      /* ---- Action button bar (bottom-aligned) ---- */
      .card-footer{background:var(--mneqas-surface);border-top:1px solid var(--mneqas-border);}
      .card-footer .btn,.form-actions .btn{min-width:108px;}
      .form-actions{display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding-top:1rem;margin-top:.5rem;border-top:1px solid var(--mneqas-border);}
      .form-actions .btn-group-right{margin-left:auto;display:flex;gap:.5rem;}

      /* ---- Wizard / Stepper (clean blue active state) ---- */
      .wiz-nav{list-style:none;display:flex;padding:0;margin:0 0 .5rem;}
      .wiz-nav .wiz-step{flex:1;text-align:center;position:relative;cursor:pointer;padding:6px 4px;color:var(--mneqas-muted);font-size:12.5px;line-height:1.25;}
      .wiz-nav .wiz-step .wiz-num{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:#eef1f5;color:var(--mneqas-muted);font-weight:700;margin-bottom:6px;border:2px solid #eef1f5;transition:.2s;}
      .wiz-nav .wiz-step::before{content:"";position:absolute;top:23px;left:-50%;width:100%;height:2px;background:#e3e7ec;z-index:0;}
      .wiz-nav .wiz-step:first-child::before{display:none;}
      .wiz-nav .wiz-step .wiz-num,.wiz-nav .wiz-step .wiz-label{position:relative;z-index:1;display:block;margin:0 auto;}
      .wiz-nav .wiz-step .wiz-label{max-width:12ch;margin:0 auto;}
      .wiz-nav .wiz-step.active{color:var(--mneqas-primary);font-weight:700;}
      .wiz-nav .wiz-step.active .wiz-num{background:var(--mneqas-primary);border-color:var(--mneqas-primary);color:#fff;box-shadow:0 0 0 4px var(--mneqas-primary-soft);}
      .wiz-nav .wiz-step.done .wiz-num{background:var(--mneqas-success);border-color:var(--mneqas-success);color:#fff;}
      .wiz-nav .wiz-step.done::before,.wiz-nav .wiz-step.active::before{background:var(--mneqas-primary);}
      .wiz-pane{display:none;animation:mneqasFade .18s ease;}
      .wiz-pane.active{display:block;}
      @keyframes mneqasFade{from{opacity:0;transform:translateY(4px);}to{opacity:1;transform:none;}}
      .review-sec{border:1px solid var(--mneqas-border);border-radius:var(--mneqas-radius);margin-bottom:1rem;}
      .review-sec .review-head{background:var(--mneqas-surface-alt);padding:.5rem .75rem;border-bottom:1px solid var(--mneqas-border);display:flex;justify-content:space-between;align-items:center;}
      .review-sec .review-body{padding:.75rem;}
      .review-sec .rk{color:var(--mneqas-muted);font-size:12px;display:block;}
      @media (max-width:575.98px){
        .wiz-nav{overflow-x:auto;}
        .wiz-nav .wiz-step{min-width:84px;font-size:11.5px;}
        .wiz-nav .wiz-step .wiz-num{width:30px;height:30px;}
      }

      /* ---- Filter / search bar: single horizontal line ---- */
      .filter-bar{display:flex;flex-wrap:nowrap;align-items:flex-end;gap:.75rem;}
      .filter-bar .filter-field{flex:1 1 180px;min-width:150px;margin-bottom:0;}
      .filter-bar .filter-field.grow{flex:2 1 220px;}
      .filter-bar .filter-actions{flex:0 0 auto;display:flex;gap:.5rem;}
      .filter-bar .filter-field>label{display:block;}
      @media (max-width:767.98px){
        .filter-bar{flex-wrap:wrap;}
        .filter-bar .filter-field,.filter-bar .filter-actions{flex:1 1 100%;}
      }

      /* ---- DataTables polish ---- */
      .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{height:36px;border-radius:var(--mneqas-radius);border:1px solid var(--mneqas-border-strong);}
      table.dataTable thead th{font-weight:600;}

      /* ---- Misc ---- */
      .is-invalid,.form-control.is-invalid{border-color:var(--mneqas-danger)!important;}
      .invalid-feedback{font-size:.75rem;}
    </style>

    <!-- ==========================================================================
         mNEQAS theme (ported from the NTESIS design system).
         MUST stay last in the stylesheet chain so its palette + geometry win
         over AdminLTE and the legacy block above.
         ========================================================================== -->
    <link rel="stylesheet" href="../dist/css/mneqas-theme.css">

    <!-- jQuery -->
    <script src="../plugins/jquery/jquery.min.js"></script>
    <!-- Bootstrap 4 -->
    <script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 -->
    <script src="../plugins/select2/js/select2.full.min.js"></script>
    <!-- Bootstrap4 Duallistbox -->
    <script src="../plugins/bootstrap4-duallistbox/jquery.bootstrap-duallistbox.min.js"></script>
    <!-- InputMask -->
    <script src="../plugins/moment/moment.min.js"></script>
    <script src="../plugins/inputmask/jquery.inputmask.min.js"></script>
    <!-- date-range-picker -->
    <script src="../plugins/daterangepicker/daterangepicker.js"></script>
    <!-- bootstrap color picker -->
    <script src="../plugins/bootstrap-colorpicker/js/bootstrap-colorpicker.min.js"></script>
    <!-- Tempusdominus Bootstrap 4 -->
    <script src="../plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
    <!-- Shared calendar positioning: keeps every datepicker fully visible (see file header) -->
    <script src="../dist/js/mneqas-datepicker.js"></script>
    <!-- Bootstrap Switch -->
    <script src="../plugins/bootstrap-switch/js/bootstrap-switch.min.js"></script>
    <!-- BS-Stepper -->
    <script src="../plugins/bs-stepper/js/bs-stepper.min.js"></script>
    <!-- dropzonejs -->
    <script src="../plugins/dropzone/min/dropzone.min.js"></script>
    <!-- jquery-validation -->
    <script src="../plugins/jquery-validation/jquery.validate.min.js"></script>
    <script src="../plugins/jquery-validation/additional-methods.min.js"></script>
    <!-- ==========================================================================
         mNEQAS global validation framework (additive; no business logic changed).
         Standardises the look + behaviour of EVERY jQuery-validation form:
           - one house style (inline .invalid-feedback, red .is-invalid border)
           - auto-scroll + focus to the first invalid field on failed submit
           - a global submitHandler that disables the submit button on a VALID
             submit (duplicate-submission guard), self-healing after 6s
         setDefaults runs immediately so page-specific .validate() calls inherit
         it; the auto-init is deferred so it never overrides a form's own rules.
         ========================================================================== -->
    <script>
    (function(){
      if (typeof jQuery === 'undefined' || !jQuery.validator) { return; }
      var $ = jQuery;

      // form.submit() can be shadowed by an <input name="submit"> — call the prototype.
      function nativeSubmit(form){ HTMLFormElement.prototype.submit.call(form); }

      $.validator.setDefaults({
        // NB: no `ignore` override — keep jquery-validation's default (:hidden
        // skipped) so we never change WHICH fields a form validates. Wizards
        // that need hidden fields validated already pass ignore:[] themselves.
        errorElement: 'span',
        errorClass: 'is-invalid',
        validClass: 'is-valid-mn',        // avoid Bootstrap's green .is-valid noise
        errorPlacement: function (error, element) {
          error.addClass('invalid-feedback');
          var $wrap = element.closest('.form-field, .form-group, td, .input-group');
          if ($wrap.length) {
            // place after an input-group so the message isn't hidden inside it
            var $ig = element.closest('.input-group');
            ($ig.length ? $ig : element).parent().append(error);
            if ($wrap.is('td')) { $wrap.append(error); }
          } else {
            error.insertAfter(element);
          }
        },
        highlight:   function (el) { $(el).addClass('is-invalid'); },
        unhighlight: function (el) { $(el).removeClass('is-invalid'); },
        // Focus + scroll to the first invalid field, consistently, everywhere.
        invalidHandler: function (event, validator) {
          if (!validator.numberOfInvalids()) { return; }
          var el = validator.errorList[0].element;
          var $el = $(el);
          // reveal a collapsed/other wizard pane holding the field, if any
          var $pane = $el.closest('.tab-pane, .wiz-pane, .p-pane, .collapse');
          if ($pane.length && !$pane.hasClass('active') && !$pane.hasClass('show')) {
            try { $pane.addClass('show active'); } catch(e){}
          }
          try {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            $el.trigger('focus');
          } catch(e){ try { el.focus(); } catch(e2){} }
        },
        // Reached ONLY for a valid form that did not supply its own submitHandler.
        submitHandler: function (form) {
          var $btns = $(form).find('button[type="submit"], input[type="submit"]');
          $btns.prop('disabled', true);
          // self-heal: re-enable if the page is still here (AJAX-intercepted, etc.)
          setTimeout(function(){ $btns.prop('disabled', false); }, 6000);
          nativeSubmit(form);
        }
      });

      // Auto-init: give consistent validation to forms that declare required
      // fields but never call .validate() themselves. Deferred so it runs AFTER
      // page scripts, and skips any form that already has a validator or opts out.
      function autoInit(){
        $('form').each(function(){
          var $f = $(this);
          if ($f.data('validator')) { return; }                 // already validated
          if ($f.is('[data-no-validate]')) { return; }          // explicit opt-out
          var method = ($f.attr('method') || 'get').toLowerCase();
          var wantsIt = $f.is('[data-validate]') ||
                        (method === 'post' && $f.find('[required]').length > 0);
          if (!wantsIt) { return; }
          try { $f.validate(); } catch(e){}
        });
      }
      $(function(){ setTimeout(autoInit, 0); });
    })();
    </script>
    <!-- SweetAlert2 -->
    <script src="../plugins/sweetalert2/sweetalert2.min.js"></script>
    <!-- Toastr -->
    <script src="../plugins/toastr/toastr.min.js"></script>
    <!-- Summernote -->
    <script src="<?= mn_asset('plugins/summernote/summernote-bs4.min.js') ?>"></script>
    <!-- Summernote: shared initialiser.
         Every rich-text editor in mNEQAS goes through mnSummernote() instead of
         calling .summernote() directly, because three things have to be true for
         the list and paragraph buttons to work, and none of them is automatic:

           1. The element must be VISIBLE when the editor is built. Summernote
              measures its editable area at init; built inside a display:none
              modal or wizard pane it can come up with no usable range, and
              ul / ol / paragraph then fail silently on plain text nodes.
              Editors asked for while hidden are queued and built the moment
              their container is shown.
           2. An existing instance must be DESTROYED first. Re-opening a modal or
              re-rendering a pane over AJAX otherwise stacks a second toolbar on
              the same textarea; clicks land on the detached one and appear to do
              nothing.
           3. A toolbar button must not steal focus from the editable, or the
              selection the command needs is gone before the command runs.

         The toolbar always carries ['para', ['ul','ol','paragraph']] unless a
         page passes its own. Pages may still pass any Summernote option. -->
    <script>
    (function ($) {
        if (!$ || !$.fn) { return; }

        /**
         * Repair list markup before it reaches an editor.
         *
         * A lot of stored content holds bare <li> elements with no <ul>/<ol>
         * parent (the findings library alone has dozens of such rows). Loaded as
         * they are, those items render with no bullet, and the Unordered/Ordered
         * List buttons do nothing at all: the command looks for a block to convert
         * and finds a node that already claims to be a list item, so it toggles
         * nothing and the click appears to be ignored.
         *
         * Wrapping each run of orphan items in a <ul> makes the content valid, so
         * it renders with bullets and the toolbar commands work natively — no
         * interception of the editor's own handlers is needed.
         */
        window.mnNormalizeHtml = function (html) {
            var s = (html == null) ? '' : String(html);
            if (s.indexOf('<li') === -1 && s.indexOf('<LI') === -1) { return s; }

            var box = document.createElement('div');
            box.innerHTML = s;

            var child = box.firstChild, run = null, changed = false;
            while (child) {
                var next   = child.nextSibling;
                var isLi   = (child.nodeType === 1 && child.tagName === 'LI');
                var isGap  = (child.nodeType === 3 && !/\S/.test(child.nodeValue));
                if (isLi) {
                    if (!run) {
                        run = document.createElement('ul');
                        box.insertBefore(run, child);
                        changed = true;
                    }
                    run.appendChild(child);
                } else if (run && isGap) {
                    box.removeChild(child);        // whitespace between two items
                } else {
                    run = null;                    // the run ends at any other node
                }
                child = next;
            }
            return changed ? box.innerHTML : s;
        };

        window.MN_SN_TOOLBAR = [
            ['style', ['bold', 'italic', 'underline', 'clear']],
            ['para',  ['ul', 'ol', 'paragraph']]
        ];

        var pending  = [];     // editors whose container is not on screen yet
        var timer    = null;
        var attempts = 0;
        var MAX_ATTEMPTS = 200;               // ~30s at 150ms, then build anyway

        /* display:none leaves no offsetParent; the rect check covers position:fixed. */
        function isVisible(el) {
            if (!el || !el.ownerDocument) { return false; }
            if (el.offsetParent) { return true; }
            var r = el.getBoundingClientRect();
            return !!(r.width || r.height);
        }

        function build($el, cfg) {
            /* Summernote seeds itself from the source element, so repair the
               markup before it is read rather than after. */
            try {
                var seed = $el.val();
                if (seed) {
                    var fixed = window.mnNormalizeHtml(seed);
                    if (fixed !== seed) { $el.val(fixed); }
                }
            } catch (e) {}
            try {
                if ($el.data('summernote') || $el.next('.note-editor').length) {
                    $el.summernote('destroy');
                }
            } catch (e) {
                if (window.console) { console.warn('[mNEQAS] summernote destroy failed', e); }
            }
            try {
                $el.summernote(cfg);
            } catch (e) {
                /* Never swallow this: a failed init silently degrades the field to
                   a raw textarea, which is exactly the "buttons do nothing" report. */
                if (window.console) {
                    console.error('[mNEQAS] Summernote failed to initialise on',
                                  $el.attr('id') || $el.attr('name') || $el[0], e);
                }
            }
        }

        function drain() {
            attempts++;
            for (var i = pending.length - 1; i >= 0; i--) {
                var item = pending[i];
                if (!document.body.contains(item.el)) {   // DOM replaced under us
                    pending.splice(i, 1);
                    continue;
                }
                if (isVisible(item.el) || attempts >= MAX_ATTEMPTS) {
                    pending.splice(i, 1);
                    build($(item.el), item.cfg);
                }
            }
            if (!pending.length && timer) { clearInterval(timer); timer = null; attempts = 0; }
        }

        /**
         * Initialise one or more editors.
         * @param target  selector, element or jQuery set (the source textarea)
         * @param opts    optional Summernote options; toolbar defaults to MN_SN_TOOLBAR
         */
        window.mnSummernote = function (target, opts) {
            $(target).each(function () {
                var cfg = $.extend({
                    toolbar: window.MN_SN_TOOLBAR,
                    disableDragAndDrop: true
                }, opts || {});

                if (isVisible(this)) { build($(this), cfg); return; }

                pending.push({ el: this, cfg: cfg });
                if (!timer) { attempts = 0; timer = setInterval(drain, 150); }
            });
        };

        /* A modal or tab becoming visible is the common trigger; the interval
           above covers panes that are shown by plain .show() with no event. */
        $(document).on('shown.bs.modal shown.bs.tab shown.bs.collapse', function () { drain(); });

        /* Keep the caret/selection inside the editable while a toolbar button is
           pressed. mousedown would otherwise move focus to the button and the
           list/paragraph command would have nothing to act on. Bootstrap opens
           dropdowns on click, so suppressing the mousedown default is safe. */
        /* .note-btn is what Summernote puts on its own toolbar controls; the
           broader selectors cover dropdown items and any custom button. */
        $(document).on('mousedown', '.note-btn, .note-toolbar button, .note-toolbar a, .note-dropdown-menu a, .note-dropdown-menu button', function (e) {
            e.preventDefault();
        });
    })(window.jQuery);
    </script>
    <!-- DataTables  & Plugins -->
    <script src="../plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
    <script src="../plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
    <script src="../plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
    <script src="../plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
    <script src="../plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
    <script src="../plugins/jszip/jszip.min.js"></script>
    <script src="../plugins/pdfmake/pdfmake.min.js"></script>
    <script src="../plugins/pdfmake/vfs_fonts.js"></script>
    <script src="../plugins/datatables-buttons/js/buttons.html5.min.js"></script>
    <script src="../plugins/datatables-buttons/js/buttons.print.min.js"></script>
    <script src="../plugins/datatables-buttons/js/buttons.colVis.min.js"></script>
    <!-- AdminLTE App -->
    <script src="../dist/js/adminlte.min.js"></script>
    <!-- mNEQAS shared wizard/stepper state helper (state calculation only) -->
    <script src="../dist/js/mneqas-stepper-state.js"></script>
