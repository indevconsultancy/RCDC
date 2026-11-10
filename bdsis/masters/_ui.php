<?php
/**
 * Shared Master Data visual layer.
 *
 * Included by every page under BDSIS/masters/ that opts into the refreshed
 * look. It emits ONE scoped <style> block plus a small DataTables placeholder
 * tweak. Everything is scoped by `body.md-page` so nothing here can leak into
 * the rest of BDSIS (Records / Lab / Reports keep their existing AdminLTE
 * defaults).
 *
 * How to opt a page in:
 *   1. add `md-page` to the <body class="hold-transition sidebar-mini">
 *   2. require this file after css-jquery.php
 *
 * No PHP logic, no auth, no DB access -- pure presentation.
 */
if (defined('BDSIS_MASTERS_UI')) { return; }
define('BDSIS_MASTERS_UI', 1);
?>
<style>
/* =========================================================================
   BDSIS Master Data -- refreshed visual layer (scope: body.md-page only).
   Design tokens first, then components. AdminLTE classes are OVERRIDDEN, not
   removed, so the underlying markup keeps working with the shared theme.
   ========================================================================= */
.md-page{
  --md-primary:#0d9488;
  --md-primary-strong:#0f766e;
  --md-primary-ring:rgba(13,148,136,.18);
  --md-warn:#f59e0b;
  --md-warn-strong:#d97706;
  --md-warn-ring:rgba(245,158,11,.18);
  --md-text:#0f172a;
  --md-text-2:#334155;
  --md-muted:#64748b;
  --md-border:#e5e7eb;
  --md-border-soft:#f1f5f9;
  --md-ground:#f5f7fa;
  --md-surface:#ffffff;
  --md-thead:#f8fafc;
  --md-row-alt:#fafbfc;
  --md-hover:#eef4f5;
  --md-readonly:#f8fafc;
  --md-code-bg:#eef2f6;
  --md-code:#0f766e;
  --md-radius:10px;
  --md-radius-sm:8px;
  --md-shadow-sm:0 1px 2px rgba(15,23,42,.04),0 1px 3px rgba(15,23,42,.04);
  --md-shadow-md:0 4px 12px rgba(15,23,42,.06),0 2px 4px rgba(15,23,42,.04);
}

/* Page shell */
.md-page .content-wrapper{background:var(--md-ground);}
.md-page .content-header{padding:1.25rem .75rem .75rem;}
.md-page .content-header h1{font-size:1.35rem;font-weight:600;color:var(--md-text);letter-spacing:-.01em;line-height:1.25;}
.md-page .content-header .text-muted{color:var(--md-muted)!important;}
.md-page .breadcrumb{background:transparent;padding:.35rem 0;font-size:.78rem;margin:0;}
.md-page .breadcrumb a{color:var(--md-muted);text-decoration:none;}
.md-page .breadcrumb a:hover{color:var(--md-primary-strong);}
.md-page .breadcrumb-item+.breadcrumb-item::before{color:#cbd5e1;}
.md-page .breadcrumb .active{color:var(--md-text);font-weight:500;}
.md-page section.content{padding-top:.25rem;}

/* Cards */
.md-page .card{border:1px solid var(--md-border);border-radius:var(--md-radius);box-shadow:var(--md-shadow-sm);background:var(--md-surface);margin-bottom:1.25rem;overflow:hidden;}
.md-page .card.card-outline{border-top:3px solid var(--md-primary);}
.md-page .card.card-warning.card-outline{border-top-color:var(--md-warn);}
.md-page .card.card-secondary.card-outline{border-top-color:#94a3b8;}
.md-page .card.card-primary.card-outline{border-top-color:var(--md-primary);}
.md-page .card-header{background:transparent;border-bottom:1px solid var(--md-border-soft);padding:.875rem 1.15rem;min-height:auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;}
.md-page .card-header .card-title{font-weight:600;font-size:.92rem;color:var(--md-text);letter-spacing:0;margin:0;line-height:1.4;float:none;}
.md-page .card-header .card-title .fas,
.md-page .card-header .card-title .far{color:var(--md-primary);margin-right:.35rem;}
.md-page .card-header .card-title small{display:block;font-weight:400;font-size:.72rem;color:var(--md-muted);letter-spacing:0;margin-top:.15rem;}
.md-page .card-tools{float:none;margin:0;display:flex;align-items:center;gap:.5rem;}
.md-page .card-body{padding:1rem 1.15rem;}
.md-page .card-body.p-0{padding:0!important;}
.md-page .card-footer{background:var(--md-thead);border-top:1px solid var(--md-border-soft);padding:.75rem 1.15rem;display:flex;gap:.5rem;flex-wrap:wrap;}

/* Tables */
.md-page .table{margin:0;color:var(--md-text-2);border-collapse:separate;border-spacing:0;}
.md-page .table thead th{background:var(--md-thead);color:var(--md-muted);font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;padding:.7rem 1.15rem;border-top:0;border-bottom:1px solid var(--md-border);white-space:nowrap;vertical-align:middle;}
.md-page .table tbody td{padding:.7rem 1.15rem;vertical-align:middle;border-top:1px solid var(--md-border-soft);font-size:.86rem;color:var(--md-text-2);}
.md-page .table tbody tr:first-child td{border-top:0;}
.md-page .table tbody tr:nth-child(even){background:var(--md-row-alt);}
.md-page .table.table-hover tbody tr:hover{background:var(--md-hover);}
.md-page .table tbody tr.text-muted td{color:#94a3b8;}
.md-page .table code{background:var(--md-code-bg);color:var(--md-code);padding:2px 7px;border-radius:5px;font-size:.78rem;font-weight:500;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;}

/* Bordered variant -- visible grid lines on every data cell for pages that
   want a dense, scannable record list (e.g. pathogens, antimicrobials). The
   borders use the soft palette so the table still reads as modern rather
   than heavy; `table-hover` still works on top. */
.md-page .table.table-bordered{border:1px solid var(--md-border);}
.md-page .table.table-bordered thead th,
.md-page .table.table-bordered tbody td{border:1px solid var(--md-border-soft);}
.md-page .table.table-bordered thead th{border-bottom-color:var(--md-border);}
.md-page .table.table-bordered tbody tr:first-child td{border-top:1px solid var(--md-border-soft);}

/* Badges */
.md-page .badge{padding:.32em .7em;font-weight:500;font-size:.7rem;border-radius:999px;letter-spacing:.01em;line-height:1.35;vertical-align:middle;}
.md-page .badge-success{background:#dcfce7;color:#166534;}
.md-page .badge-warning{background:#fef3c7;color:#854d0e;}
.md-page .badge-secondary{background:#f1f5f9;color:#475569;}
.md-page .badge-info{background:#dbeafe;color:#1e40af;}
.md-page .badge-danger{background:#fee2e2;color:#991b1b;}

/* Action controls */
.md-page .btn{border-radius:var(--md-radius-sm);font-weight:500;letter-spacing:0;transition:transform .05s,box-shadow .15s,background .15s,border-color .15s,color .15s;}
.md-page .btn:focus,.md-page .btn.focus{box-shadow:0 0 0 3px var(--md-primary-ring);outline:0;}
.md-page .btn-xs{padding:.28rem .6rem;font-size:.72rem;line-height:1.2;border-radius:6px;}
.md-page td .btn+.btn,.md-page td .btn+form,.md-page td form+.btn,.md-page td form+form{margin-left:.35rem;}

.md-page .btn-outline-primary{color:var(--md-primary-strong);border:1px solid #cbd5e1;background:#fff;}
.md-page .btn-outline-primary:hover{background:var(--md-primary);border-color:var(--md-primary);color:#fff;}
.md-page .btn-outline-warning{color:#b45309;border:1px solid #cbd5e1;background:#fff;}
.md-page .btn-outline-warning:hover{background:var(--md-warn);border-color:var(--md-warn);color:#fff;}
.md-page .btn-outline-success{color:#15803d;border:1px solid #cbd5e1;background:#fff;}
.md-page .btn-outline-success:hover{background:#16a34a;border-color:#16a34a;color:#fff;}
.md-page .btn-outline-danger{color:#b91c1c;border:1px solid #cbd5e1;background:#fff;}
.md-page .btn-outline-danger:hover{background:#dc2626;border-color:#dc2626;color:#fff;}

/* Icon-only action buttons (edit pen / delete trash) for data tables. Sized
   to match .btn-xs, square, and horizontally centred in the Action column.
   The .md-action-cell wrapper flex-aligns multiple icon buttons / forms so
   they sit in a tidy row regardless of RBAC gating. */
.md-page .md-action-cell{display:flex;align-items:center;justify-content:center;gap:.4rem;flex-wrap:nowrap;}
.md-page .md-action-cell form{display:inline-flex;margin:0;}
.md-page .btn.md-btn-icon{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;line-height:1;border-radius:6px;}
.md-page .btn.md-btn-icon .fa,
.md-page .btn.md-btn-icon .fas,
.md-page .btn.md-btn-icon .far{font-size:.78rem;margin:0;}
.md-page .btn.md-btn-icon.md-btn-edit{color:#1d4ed8;border:1px solid #bfdbfe;background:#eff6ff;}
.md-page .btn.md-btn-icon.md-btn-edit:hover{color:#fff;background:#2563eb;border-color:#2563eb;}
.md-page .btn.md-btn-icon.md-btn-delete{color:#b91c1c;border:1px solid #fecaca;background:#fef2f2;}
.md-page .btn.md-btn-icon.md-btn-delete:hover{color:#fff;background:#dc2626;border-color:#dc2626;}
.md-page .btn.md-btn-icon.md-btn-warning{color:#b45309;border:1px solid #fed7aa;background:#fff7ed;}
.md-page .btn.md-btn-icon.md-btn-warning:hover{color:#fff;background:var(--md-warn);border-color:var(--md-warn);}
.md-page .btn.md-btn-icon.md-btn-success{color:#15803d;border:1px solid #bbf7d0;background:#f0fdf4;}
.md-page .btn.md-btn-icon.md-btn-success:hover{color:#fff;background:#16a34a;border-color:#16a34a;}
.md-page .btn.md-btn-icon[disabled],
.md-page .btn.md-btn-icon.md-btn-icon--disabled{opacity:.4;cursor:not-allowed;pointer-events:none;}
.md-page .md-action-cell .md-action-dash{color:#cbd5e1;font-size:.9rem;line-height:1;}

.md-page .btn.bg-gradient-primary{background:var(--md-primary)!important;border-color:var(--md-primary)!important;color:#fff!important;box-shadow:none;}
.md-page .btn.bg-gradient-primary:hover{background:var(--md-primary-strong)!important;border-color:var(--md-primary-strong)!important;}
.md-page .btn.bg-gradient-warning{background:var(--md-warn)!important;border-color:var(--md-warn)!important;color:#fff!important;box-shadow:none;}
.md-page .btn.bg-gradient-warning:hover{background:var(--md-warn-strong)!important;border-color:var(--md-warn-strong)!important;}
.md-page .btn-secondary{background:#f8fafc;color:#334155;border:1px solid #cbd5e1;}
.md-page .btn-secondary:hover{background:#e2e8f0;border-color:#94a3b8;color:#0f172a;}

/* Form card primary-action placement. .md-form-actions arranges the footer
   so the primary button sits on the right and Cancel on the left; when the
   primary action is also marked --block, it takes the full width of the
   footer (used for the stacked single-action "Add" state on narrow cards). */
.md-page .card-footer.md-form-actions{align-items:center;justify-content:space-between;}
.md-page .md-primary-action{font-weight:500;padding:.5rem 1.1rem;display:inline-flex;align-items:center;gap:.4rem;}
.md-page .md-primary-action .fas,
.md-page .md-primary-action .far{font-size:.78rem;}
.md-page .md-primary-action--block{width:100%;justify-content:center;}

/* Method filter (btn-group) */
.md-page .card-header .btn-group-sm > .btn{border-radius:6px;font-size:.75rem;padding:.32rem .75rem;}
.md-page .card-header .btn-group-sm > .btn+.btn{margin-left:.3rem;}
.md-page .card-header .btn-group-sm > .btn-primary{background:var(--md-primary);border-color:var(--md-primary);color:#fff;box-shadow:none;}
.md-page .card-header .btn-group-sm > .btn-outline-primary{color:var(--md-muted);border-color:#cbd5e1;background:#fff;}
.md-page .card-header .btn-group-sm > .btn-outline-primary:hover{color:var(--md-primary-strong);border-color:var(--md-primary);background:#fff;}

/* Forms */
.md-page .form-group{margin-bottom:1rem;}
.md-page .form-group > label,
.md-page .col-form-label-sm{display:block;font-weight:600;font-size:.78rem;color:var(--md-text);margin-bottom:.35rem;padding:0;letter-spacing:.01em;text-transform:none;}
.md-page .form-control{border:1px solid var(--md-border);border-radius:var(--md-radius-sm);padding:.5rem .75rem;height:calc(2.15rem + 2px);font-size:.87rem;color:var(--md-text);background:#fff;transition:border-color .15s,box-shadow .15s;box-shadow:none;}
.md-page .form-control::placeholder{color:#94a3b8;}
.md-page .form-control:focus{border-color:var(--md-primary);box-shadow:0 0 0 3px var(--md-primary-ring);outline:0;}
.md-page .form-control[readonly]{background:var(--md-readonly);color:var(--md-muted);border-color:var(--md-border-soft);cursor:not-allowed;}
.md-page select.form-control{-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml;utf8,<svg fill='%2364748b' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");background-repeat:no-repeat;background-position:right .55rem center;background-size:16px;padding-right:2rem;}
.md-page .form-text{color:var(--md-muted);font-size:.74rem;margin-top:.3rem;line-height:1.4;}
.md-page .form-text.text-muted{color:var(--md-muted)!important;}
.md-page input.text-center{text-align:center;}

/* Fieldset (breakpoints) */
.md-page fieldset.border{border:1px solid var(--md-border)!important;border-radius:var(--md-radius)!important;padding:.35rem 1rem 1rem!important;margin-bottom:1rem;background:var(--md-thead);}
.md-page fieldset.border legend{width:auto;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:600;color:var(--md-primary-strong);padding:0 .5rem!important;margin-bottom:0;background:transparent;border:0;float:none;}

/* Alerts */
.md-page .alert{border-radius:var(--md-radius);border:1px solid transparent;padding:.75rem 1rem;font-size:.85rem;box-shadow:var(--md-shadow-sm);}
.md-page .alert-info{background:#e0f2fe;color:#075985;border-color:#bae6fd;}
.md-page .alert-secondary{background:#f8fafc;color:#334155;border-color:#e2e8f0;}
.md-page .alert-success{background:#dcfce7;color:#166534;border-color:#86efac;}
.md-page .alert-warning{background:#fef3c7;color:#854d0e;border-color:#fcd34d;}
.md-page .alert-danger{background:#fee2e2;color:#7f1d1d;border-color:#fca5a5;}
.md-page .alert .close{padding:.55rem 1rem;color:inherit;opacity:.6;text-shadow:none;}
.md-page .alert .close:hover{opacity:1;}

/* Listing toolbar -- the band above a data table that carries the primary
   action on the right and any list filters on the left. Sits directly above
   the DataTables search row, whose horizontal padding (1.15rem, below) it
   matches so the controls line up in one column. Added for users.php; any
   other master listing can adopt the same markup. */
/* Search left, primary action right, one row. space-between does the split;
   margin-left:auto on the actions keeps the button right even when the search
   side is absent. Top padding is deliberately small -- on a listing card with
   no .card-header this toolbar IS the top of the card. */
.md-page .md-toolbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding:.7rem 1.15rem .55rem;}
.md-page .md-toolbar__search{flex:0 1 auto;}
.md-page .md-toolbar__actions{display:flex;align-items:center;gap:.5rem;margin-left:auto;}
/* Matches what .dataTables_filter input used to look like, so swapping the
   built-in box for our own changed the layout and not the styling. */
.md-page .md-toolbar__search input{border:1px solid var(--md-border);border-radius:var(--md-radius-sm);padding:.4rem .75rem;font-size:.82rem;min-width:240px;background:#fff;color:var(--md-text-2);height:auto;transition:border-color .15s,box-shadow .15s;}
.md-page .md-toolbar__search input:focus{border-color:var(--md-primary);box-shadow:0 0 0 3px var(--md-primary-ring);outline:0;}
.md-page .md-toolbar__search input::placeholder{color:#94a3b8;}
/* With the length/filter row switched off in users.php, the wrapper's first row
   is the TABLE. The old rule here targeted .row:first-child to tame the search
   row's divider and padding; pointed at the table row it would just add stray
   space, so it is replaced by trimming the wrapper itself. */
.md-page .md-has-toolbar .dataTables_wrapper{padding-top:0;}
@media(max-width:575.98px){
  .md-page .md-toolbar{padding:.6rem 1rem .5rem;}
  .md-page .md-toolbar__search,
  .md-page .md-toolbar__actions{width:100%;margin-left:0;}
  .md-page .md-toolbar__search input{min-width:0;width:100%;}
  .md-page .md-toolbar__actions .md-primary-action{width:100%;justify-content:center;}
}

/* DataTables integration */
.md-page .dataTables_wrapper{padding:0 1.15rem 1rem;}
.md-page .dataTables_wrapper .row:first-child{padding:.85rem 0 .5rem;border-bottom:1px solid var(--md-border-soft);margin:0 -1.15rem .5rem;padding-left:1.15rem;padding-right:1.15rem;}
.md-page .dataTables_filter{text-align:right;}
.md-page .dataTables_filter label{color:var(--md-muted);font-size:.78rem;font-weight:500;margin-bottom:0;}
.md-page .dataTables_filter input{border:1px solid var(--md-border);border-radius:var(--md-radius-sm);padding:.4rem .75rem;font-size:.82rem;margin-left:.5rem;min-width:220px;background:#fff;transition:border-color .15s,box-shadow .15s;}
.md-page .dataTables_filter input:focus{border-color:var(--md-primary);box-shadow:0 0 0 3px var(--md-primary-ring);outline:0;}
.md-page .dataTables_info{color:var(--md-muted);font-size:.78rem;padding:.85rem 0 0!important;}
.md-page .dataTables_paginate{padding:.65rem 0 0!important;}
.md-page .paginate_button.page-item .page-link{border:1px solid transparent;color:var(--md-muted);border-radius:6px;margin:0 2px;padding:.32rem .65rem;font-size:.78rem;background:transparent;line-height:1.2;}
.md-page .paginate_button.page-item .page-link:hover{background:var(--md-hover);color:var(--md-primary-strong);border-color:transparent;}
.md-page .paginate_button.page-item.active .page-link{background:var(--md-primary);border-color:var(--md-primary);color:#fff;}
.md-page .paginate_button.page-item.disabled .page-link{color:#cbd5e1;}
.md-page .table thead th.sorting,
.md-page .table thead th.sorting_asc,
.md-page .table thead th.sorting_desc{cursor:pointer;background-position:right .8rem center;background-size:12px;padding-right:1.75rem;}

/* Empty state */
.md-page .table .text-muted.p-4{color:var(--md-muted)!important;font-size:.9rem;font-style:italic;}

/* Compact context panel (used on the staff form to replace 3 readonly inputs) */
.md-page .md-context{background:var(--md-thead);border:1px solid var(--md-border-soft);border-radius:var(--md-radius-sm);padding:.75rem .9rem;margin-bottom:1rem;}
.md-page .md-context dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:.35rem .75rem;}
.md-page .md-context dt{color:var(--md-muted);font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;align-self:center;}
.md-page .md-context dd{margin:0;color:var(--md-text);font-weight:500;font-size:.84rem;}

/* Small helpers */
.md-page hr{border-color:var(--md-border-soft);margin:1rem 0;}
.md-page .text-muted{color:var(--md-muted)!important;}
.md-page .md-nowrap{white-space:nowrap;}

/* Horizontal scroll container for wide data tables. Wrap the <table> so the
   whole thing scrolls as one unit on narrow viewports and the Action /
   In-use columns are never truncated. DataTables also respects this
   wrapper when scrollX is NOT set. */
.md-page .md-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.md-page .md-table-scroll > .table{min-width:960px;}

/* Responsive */
@media(max-width:991.98px){
  .md-page .content-header{padding:1rem .75rem .5rem;}
  .md-page .card-header{padding:.75rem 1rem;}
  .md-page .card-body{padding:.85rem 1rem;}
  .md-page .table thead th,
  .md-page .table tbody td{padding:.6rem .85rem;}
  .md-page .dataTables_filter{text-align:left;}
  .md-page .dataTables_filter input{margin-left:0;min-width:0;width:100%;}
  .md-page .dataTables_wrapper .row:first-child{margin:0 -1rem .5rem;padding-left:1rem;padding-right:1rem;}
}
</style>
<script>
/* Tighten the DataTables search bar: swap "Search:" label for a placeholder.
   Runs on window.load so it fires AFTER each page's $(function(){ DataTable(); })
   which inits the .dataTables_filter DOM. Idempotent (safe to double-run). */
window.addEventListener('load', function () {
  document.querySelectorAll('.md-page .dataTables_filter').forEach(function (wrap) {
    var input = wrap.querySelector('input');
    if (input && !input.placeholder) { input.placeholder = 'Search…'; }
    var lbl = wrap.querySelector('label');
    if (lbl) {
      lbl.childNodes.forEach(function (n) {
        if (n.nodeType === 3) { n.textContent = ''; }
      });
    }
  });
});
</script>
