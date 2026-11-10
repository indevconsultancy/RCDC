<?php
/**
 * ============================================================================
 *  Findings library picker + "Manage Findings" modal
 *  Shared by Microscopy Panel Testing and RDT Panel Testing feedback.
 * ----------------------------------------------------------------------------
 *  Both modules keep their existing automatically generated Findings text. This
 *  adds a multi-select of managed presets on top: whatever is selected is
 *  appended to the generated content inside a marked block, so re-selecting
 *  never duplicates anything and never disturbs text the evaluator typed.
 *
 *  Findings can be grouped into categories (panel_finding_categories, scoped by
 *  module). When the module has categories, the library list gains a Category
 *  column with a search box, a category filter and pagination, the Add/Edit form
 *  gains a Category field, and the text written into the Findings and
 *  Recommendations editors is grouped under a bold category heading. A module
 *  with no categories keeps exactly its current list and output.
 *
 *  The manage screen is a listing table (S.No. / Finding Name / Order / Status /
 *  Action) that switches to a full-width form for Add and Edit, and to a
 *  read-only preview for View. The JSON endpoint (finding-master.php) and its
 *  list | content | save | delete actions are unchanged.
 *
 *  USAGE (inside the feedback form, just above the Findings editor):
 *      require_once __DIR__ . "/../includes/panel-findings-ui.php";
 *      render_panel_findings_ui('micro', 'finding-master.php', $selectedIds, $canManage);
 *
 *  $module     'micro' | 'rdt'   — scopes the library
 *  $endpoint   path to finding-master.php as seen from the including page
 *  $selected   array of finding_id already linked to this feedback
 *  $canManage  true to show the Manage Findings button (RCDC/Administrator)
 * ============================================================================
 */
if (!function_exists('render_panel_findings_ui')) {

function render_panel_findings_ui($module, $endpoint, array $selected = array(), $canManage = false)
{
    $module   = ($module === 'rdt') ? 'rdt' : 'micro';
    $selJson  = json_encode(array_values(array_map('intval', $selected)));
    $endpoint = htmlspecialchars($endpoint, ENT_QUOTES);
    ?>
    <div class="form-group row">
      <label class="col-sm-3 col-form-label-sm">Select Findings</label>
      <div class="col-sm-9">
        <div class="d-flex align-items-start" style="gap:8px;">
          <select id="pfmSelect" name="pfm_finding_ids[]" class="form-control" multiple style="flex:1; min-width:0;"
                  data-placeholder="-- Select Finding(s) --"></select>
          <?php if ($canManage) { ?>
          <button type="button" class="btn btn-sm bg-gradient-primary text-nowrap" id="pfmManageBtn"
                  data-toggle="modal" data-target="#pfmModal">
            <i class="fa fa-cog"></i> Manage Findings
          </button>
          <?php } ?>
        </div>
        <small class="form-text text-muted">
          Selected findings are added below the automatically generated content, with their recommendations.
        </small>
      </div>
    </div>

    <!-- Manage Findings. No nested &lt;form&gt; and no name attributes: the fields are
         read by id and posted over AJAX, so they never submit with the feedback form. -->
    <div class="modal fade" id="pfmModal" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">

          <div class="modal-header align-items-center">
            <h5 class="modal-title mb-0" id="pfmModalTitle"><i class="fa fa-cog"></i>&nbsp; Manage Findings</h5>
            <div class="pfm-head-tools">
              <button type="button" class="btn btn-sm bg-gradient-primary" id="pfmAddBtn">
                <i class="fa fa-plus"></i> Add New Finding
              </button>
              <!-- Categories group the findings and head each block in the editors,
                   so they are managed from the same screen rather than a settings page. -->
              <button type="button" class="btn btn-sm bg-gradient-secondary ml-2" id="pfmCatBtn">
                <i class="fa fa-tags"></i> Manage Categories
              </button>
              <button type="button" class="close m-0 p-0 ml-2" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          </div>

          <div class="modal-body">
            <div id="pfmAlert"></div>

            <!-- ---------- listing ---------- -->
            <div id="pfmListView">

              <!-- Search / category filter / page size. The whole bar is hidden
                   for a module without categories except the search box, which
                   is useful either way. -->
              <!-- Shown instead of the Manage Categories button on a database
                   without the categories table, so the feature is discoverable
                   and the one step that enables it is stated. -->
              <div id="pfmCatUnavailable" class="alert alert-light border py-1 px-2 small mb-2 d-none">
                <i class="fa fa-info-circle text-muted"></i>
                Finding categories are not set up on this database yet, so
                <strong>Manage Categories</strong> is unavailable and findings are
                listed without grouping. Running the panel finding categories
                migration on this database enables it.
              </div>
              <div class="form-row align-items-end mb-2" id="pfmFilterBar">
                <div class="col-md-5 mb-1">
                  <label class="col-form-label-sm mb-0">Search</label>
                  <input type="text" id="pfmSearch" class="form-control form-control-sm"
                         placeholder="Finding name or content" autocomplete="off">
                </div>
                <div class="col-md-4 mb-1 d-none" id="pfmCatFilterWrap">
                  <label class="col-form-label-sm mb-0">Category</label>
                  <select id="pfmCatFilter" class="form-control form-control-sm">
                    <option value="">All categories</option>
                  </select>
                </div>
                <div class="col-md-3 mb-1">
                  <label class="col-form-label-sm mb-0">Show</label>
                  <select id="pfmPageSize" class="form-control form-control-sm">
                    <option value="10" selected>10 per page</option>
                    <option value="25">25 per page</option>
                    <option value="50">50 per page</option>
                    <option value="0">All</option>
                  </select>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered table-hover pfm-table mb-0">
                  <thead class="thead-light">
                    <tr>
                      <th class="text-center" style="width:80px;">S.No.</th>
                      <th>Finding Name</th>
                      <th class="text-center" style="width:200px;">Category</th>
                      <th class="text-center" style="width:120px;">Status</th>
                      <th class="text-center" style="width:160px;">Action</th>
                    </tr>
                  </thead>
                  <tbody id="pfmTableBody"></tbody>
                </table>
              </div>

              <div class="d-flex justify-content-between align-items-center mt-2" id="pfmPager">
                <small class="text-muted" id="pfmCount"></small>
                <ul class="pagination pagination-sm mb-0" id="pfmPages"></ul>
              </div>
            </div>

            <!-- ---------- add / edit ---------- -->
            <div id="pfmFormView" class="d-none">
              <input type="hidden" id="pfmId" value="0">

              <div class="form-group">
                <label class="col-form-label-sm">Finding Name <span class="text-danger">*</span></label>
                <input type="text" id="pfmTitle" class="form-control" maxlength="255"
                       placeholder="Short name shown in the list">
              </div>

              <!-- Only rendered as visible for a module that has categories. -->
              <div class="form-group d-none" id="pfmCatWrap">
                <label class="col-form-label-sm">Category <span class="text-danger">*</span></label>
                <select id="pfmCat" class="form-control"></select>
                <small class="form-text text-muted">
                  Findings are grouped under this heading in the Findings and Recommendations text.
                </small>
              </div>

              <div class="form-group">
                <label class="col-form-label-sm">Finding Content <span class="text-danger">*</span></label>
                <textarea id="pfmContent" class="form-control"></textarea>
              </div>

              <div class="form-group">
                <label class="col-form-label-sm">Recommendation Content</label>
                <textarea id="pfmRec" class="form-control"></textarea>
              </div>

              <!-- Order was removed: a new finding is appended automatically and an
                   edit keeps the position it already had (see finding-master.php). -->
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="col-form-label-sm">Status</label>
                  <select id="pfmStatus" class="form-control">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- ---------- categories ---------- -->
            <div id="pfmCatView" class="d-none">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">Categories head each block of findings and recommendations in the editors.</small>
                <button type="button" class="btn btn-sm bg-gradient-primary" id="pfmCatAddBtn"><i class="fa fa-plus"></i> Add Category</button>
              </div>
              <div class="table-responsive">
                <table class="table table-bordered table-hover pfm-table mb-0">
                  <thead class="thead-light">
                    <tr>
                      <th class="text-center" style="width:70px;">S.No.</th>
                      <th>Category Name</th>
                      <th>Description</th>
                      <th class="text-center" style="width:90px;">In Use</th>
                      <th class="text-center" style="width:120px;">Status</th>
                      <th class="text-center" style="width:170px;">Action</th>
                    </tr>
                  </thead>
                  <tbody id="pfmCatBody"></tbody>
                </table>
              </div>

              <!-- add / edit a category, in place under the table -->
              <div id="pfmCatForm" class="border rounded p-3 mt-3 d-none">
                <input type="hidden" id="pfmCatId" value="0">
                <div class="form-row">
                  <div class="form-group col-md-5">
                    <label class="col-form-label-sm">Category Name <span class="text-danger">*</span></label>
                    <input type="text" id="pfmCatName" class="form-control" maxlength="100" placeholder="e.g. Sensitivity">
                  </div>
                  <div class="form-group col-md-5">
                    <label class="col-form-label-sm">Description</label>
                    <input type="text" id="pfmCatDesc" class="form-control" maxlength="255" placeholder="Optional">
                  </div>
                  <div class="form-group col-md-2">
                    <label class="col-form-label-sm">Status</label>
                    <select id="pfmCatStatus" class="form-control">
                      <option value="1">Active</option>
                      <option value="0">Inactive</option>
                    </select>
                  </div>
                </div>
                <div class="text-right">
                  <button type="button" class="btn btn-sm bg-gradient-secondary" id="pfmCatCancel">Cancel</button>
                  <button type="button" class="btn btn-sm bg-gradient-success" id="pfmCatSave"><i class="fa fa-save"></i> Save Category</button>
                </div>
              </div>
            </div>

            <!-- ---------- read-only preview ---------- -->
            <div id="pfmDetailView" class="d-none">
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="col-form-label-sm text-muted mb-0">Finding Name</label>
                  <div class="pfm-detail-value" id="pfmvTitle"></div>
                </div>
                <div class="form-group col-md-12 d-none" id="pfmvCatWrap">
                  <label class="col-form-label-sm text-muted mb-0">Category</label>
                  <div class="pfm-detail-value" id="pfmvCat"></div>
                </div>
                <div class="form-group col-md-6">
                  <label class="col-form-label-sm text-muted mb-0">Status</label>
                  <div class="pfm-detail-value" id="pfmvStatus"></div>
                </div>
              </div>
              <div class="form-group">
                <label class="col-form-label-sm text-muted mb-0">Finding Content</label>
                <div class="pfm-detail-box" id="pfmvContent"></div>
              </div>
              <div class="form-group mb-0">
                <label class="col-form-label-sm text-muted mb-0">Recommendation Content</label>
                <div class="pfm-detail-box" id="pfmvRec"></div>
              </div>
            </div>

          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-sm bg-gradient-secondary d-none" id="pfmBackBtn">
              <i class="fa fa-arrow-left"></i> Back
            </button>
            <button type="button" class="btn btn-sm bg-gradient-secondary" id="pfmCloseBtn" data-dismiss="modal">Close</button>
            <button type="button" class="btn btn-sm bg-gradient-success d-none" id="pfmSaveBtn">
              <i class="fa fa-save"></i> Save
            </button>
          </div>

        </div>
      </div>
    </div>

    <style>
      #pfmModal .modal-header .pfm-head-tools{ display:flex; align-items:center; margin-left:auto; }
      #pfmModal .modal-body{ padding:16px 18px; }
      #pfmModal .pfm-table th,
      #pfmModal .pfm-table td{ vertical-align:middle; }
      #pfmModal .pfm-table td.pfm-name{ word-break:break-word; }
      #pfmModal .pfm-actions{ display:flex; gap:6px; justify-content:center; }
      #pfmModal .pfm-empty{ padding:18px; text-align:center; color:#6c757d; }
      /* Form: even spacing for inputs and both rich-text boxes */
      #pfmFormView .form-group{ margin-bottom:16px; }
      #pfmFormView label{ font-weight:600; margin-bottom:4px; }
      #pfmFormView .note-editor.note-frame{ margin-bottom:0; border:1px solid #ced4da; border-radius:.25rem; }
      #pfmFormView .note-editor .note-editing-area .note-editable{ padding:10px 12px; }
      /* Read-only preview */
      #pfmDetailView label{ font-weight:600; }
      #pfmDetailView .pfm-detail-value{ padding:6px 0 0 0; }
      #pfmDetailView .pfm-detail-box{
        border:1px solid #dee2e6; border-radius:.25rem; background:#fff;
        padding:10px 12px; min-height:64px; max-height:240px; overflow:auto;
      }
      #pfmModal #pfmFilterBar label{ font-weight:600; }
      #pfmModal #pfmPager{ min-height:31px; }
      #pfmModal .pfm-table td .badge{ font-weight:600; }
      .btn-xs{ padding:.1rem .4rem; font-size:.75rem; line-height:1.4; }
    </style>

    <script>
    /* ================= FINDINGS LIBRARY (<?= $module ?>) =================
       The picker appends the selected presets to the generated Findings and
       Recommendations inside a marked block, so the generated text and anything
       the evaluator typed are preserved and re-selecting never duplicates. */
    (function(){
      var PFM_MODULE   = <?= json_encode($module) ?>;
      var PFM_URL      = <?= json_encode($endpoint) ?>;
      var PFM_SELECTED = <?= $selJson ?>;
      var PFM_START = '<!--PFM_SEL_START-->', PFM_END = '<!--PFM_SEL_END-->';
      var rows = [], canManage = false, editorsReady = false;
      /* Categories for this module. Empty array = the module has none, and every
         category-aware branch below falls back to the pre-category behaviour. */
      var cats = [], pfmPage = 1;
      /* cat_key => name for EVERY category, deactivated ones included: a finding
         keeps its category when that category is switched off, and it must still
         be named properly in the list and in the editor headings. */
      var catNames = {};
      /* Whether the categories table exists at all. Blinded calls this
         catsDynamic; the Manage Categories button follows it, so the control is
         absent rather than broken on a database without the migration. */
      var catsAvailable = false;

      /* "Has categories" still means "has one a finding can be FILED under". */
      function catsOn(){ return cats.length > 0; }
      function catName(key){
        var k = String(key == null ? '' : key);
        var name = null;
        $.each(cats, function(_, c){ if(String(c.key) === k){ name = c.name; } });
        if(name === null && Object.prototype.hasOwnProperty.call(catNames, k)){ name = catNames[k]; }
        if(name !== null){ return name; }
        /* No category record carries this key - the finding was filed under one
           that the module does not have (most often the default 'general' before
           the categories are seeded). The key is still shown so the grouping is
           never silently blank, but it is presented as a heading rather than as
           a raw identifier: underscores become spaces and each word is
           capitalised. Once the real category exists its own name wins above. */
        return k.replace(/_+/g, ' ')
                .replace(/\s+/g, ' ')
                .replace(/(^|\s)([a-z])/g, function(_m, lead, ch){ return lead + ch.toUpperCase(); });
      }

      function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
      function alertBox(kind, msg){
        $('#pfmAlert').html('<div class="alert alert-'+kind+' py-1 px-2 small mb-2">'+esc(msg)+'</div>');
        if(kind === 'success'){ setTimeout(function(){ $('#pfmAlert').empty(); }, 2500); }
      }
      function rowById(id){
        var found = null;
        $.each(rows, function(_, x){ if(String(x.finding_id) === String(id)){ found = x; } });
        return found;
      }

      /* ---------- picker ---------- */
      function renderSelect(){
        var keep = $('#pfmSelect').val() || [];
        /* On the first paint the control holds no options yet, so what counts as
           "already selected" is the record's own saved list. Without that fallback
           a finding deactivated after the record was saved is filtered out of the
           options and silently dropped from the selection — the next save would
           lose it. An inactive finding is offered only to the records that already
           carry it, never for a new selection, and is labelled as inactive. */
        var chosen = $.map(keep.length ? keep : PFM_SELECTED, String);
        var opts = '';
        $.each(rows, function(_, r){
          if(String(r.status) !== '1' && $.inArray(String(r.finding_id), chosen) === -1){ return; }
          opts += '<option value="'+r.finding_id+'">'+esc(r.title)+
                  (String(r.status) === '1' ? '' : ' (inactive)')+'</option>';
        });
        var $s = $('#pfmSelect');
        $s.html(opts).val(chosen);
        if($s.hasClass('select2-hidden-accessible')){ $s.trigger('change.select2'); }
      }

      function stripBlock(html){
        html = String(html || '');
        var a = html.indexOf(PFM_START), b = html.indexOf(PFM_END);
        if(a !== -1 && b !== -1 && b > a){ html = html.substring(0, a) + html.substring(b + PFM_END.length); }
        return html;
      }
      function setCode(sel, html){
        var $el = $(sel);
        if(!$el.length) return;
        /* Repair orphan <li> markup on the way in — see mnNormalizeHtml. */
        var code = (typeof window.mnNormalizeHtml === 'function')
                 ? window.mnNormalizeHtml(html) : html;
        if($el.data('summernote')){ $el.summernote('code', code); } else { $el.val(code); }
      }
      function getCode(sel){
        var $el = $(sel);
        if(!$el.length) return '';
        return $el.data('summernote') ? ($el.summernote('code') || '') : ($el.val() || '');
      }

      /* ---------- category presets in the finding form ----------
         Changing the Category in Add/Edit Finding drops a headed block into both
         editors: the category name, then one line per active finding already
         filed under it. The block is delimited by markers so a later category
         change replaces the heading and its lines wholesale and never touches
         whatever the author typed around it. Same model as Blinded Rechecking's
         FMCAT block, built from the rows already loaded, so it reflects every
         add and edit without another request. */
      var PFM_CAT_START = '<!--PFMCAT_START-->', PFM_CAT_END = '<!--PFMCAT_END-->';

      function catBlockStrip(html){
        var s = String(html || '');
        var a = s.indexOf(PFM_CAT_START), b = s.indexOf(PFM_CAT_END);
        if(a === -1 || b === -1 || b < a){ return s; }
        return s.slice(0, a) + s.slice(b + PFM_CAT_END.length);
      }

      /* One entry contributes one line. Stored content may be a bare <li>, a <p>
         or a whole block, so the line is its plain text - that is what keeps the
         two sides index-aligned however an entry was authored. */
      function catBlockLine(html){
        return $.trim($('<div>').html(String(html || '')).text()).replace(/\s+/g, ' ');
      }

      /* The paired presets for one category: active entries of this module only,
         excluding the finding being edited so it cannot quote itself. */
      function catBlockPairs(catKey){
        var editingId = String($('#pfmId').val() || '0');
        var out = [];
        $.each(rows, function(_, r){
          if(String(r.category) !== String(catKey)){ return; }
          if(String(r.status) !== '1'){ return; }          // inactive entries are not presets
          if(String(r.finding_id) === editingId){ return; }
          out.push({
            finding: catBlockLine(r.finding_content) || catBlockLine(r.title),
            rec:     catBlockLine(r.recommendation_content)
          });
        });
        return out;
      }

      /* Only a user-driven change rewrites the block. blankForm() and the Edit
         handler set the value with .val(), which does not fire change, so
         opening Add or Edit never clobbers content that was loaded. */
      function applyCatPresets(){
        var key   = $('#pfmCat').val();
        var pairs = catBlockPairs(key);
        var name  = catName(key);

        var fBase = catBlockStrip(getCode('#pfmContent'));
        var rBase = catBlockStrip(getCode('#pfmRec'));

        if(!pairs.length){
          /* Nothing to insert: still drop any block from the previous category so
             a stale heading cannot be left behind. */
          setCode('#pfmContent', fBase);
          setCode('#pfmRec', rBase);
          return;
        }

        var head = '<p><strong>' + esc(name) + ':</strong></p>';
        var fL = '', rL = '';
        $.each(pairs, function(_, p){
          fL += '<li>' + esc(p.finding) + '</li>';
          /* an entry without a recommendation still emits a line, so the indexes
             on the two sides stay in step */
          rL += '<li>' + (p.rec ? esc(p.rec) : '<br>') + '</li>';
        });

        setCode('#pfmContent', fBase + PFM_CAT_START + head + '<ul>' + fL + '</ul>' + PFM_CAT_END);
        setCode('#pfmRec',     rBase + PFM_CAT_START + head + '<ul>' + rL + '</ul>' + PFM_CAT_END);
      }

      function applySelection(){
        var ids = $('#pfmSelect').val() || [];
        var findBase = stripBlock(getCode('#finding'));
        var recBase  = stripBlock(getCode('#recommendation'));
        if(!ids.length){ setCode('#finding', findBase); setCode('#recommendation', recBase); return; }

        $.getJSON(PFM_URL, { action:'content', module:PFM_MODULE, ids: ids.join(',') })
         .done(function(resp){
            var list = (resp && resp.rows) ? resp.rows : [];
            /* The content response carries the category list too, so a category
               added since the page loaded still gets its proper heading. */
            if(resp && resp.cats){ cats = resp.cats; }
            if(resp && resp.catNames){ catNames = resp.catNames; }

            if(catsOn()){
              /* Grouped output: one bold category heading, then that category's
                 findings, in the library's own category order. Placeholders such
                 as "Panel no xxx" are copied through untouched for the evaluator
                 to fill in. */
              /* The library order, deactivated categories included, so switching
                 one off does not move its block to the end. catNames is already in
                 sort_order; the active list only tops it up if the two disagree. */
              var order = [];
              $.each(catNames, function(k){ order.push(String(k)); });
              $.each(cats, function(_, c){ if($.inArray(String(c.key), order) === -1){ order.push(String(c.key)); } });
              var bucket = {};
              $.each(list, function(_, it){
                var k = String(it.category || 'general');
                if(!bucket[k]){
                  bucket[k] = { f: [], r: [] };
                  if($.inArray(k, order) === -1){ order.push(k); }   // category since removed
                }
                if($.trim(it.finding || '') !== ''){ bucket[k].f.push(it.finding); }
                var rc = $.trim(it.recommendation || '');
                if(rc !== '' && $.inArray(rc, bucket[k].r) === -1){ bucket[k].r.push(rc); }
              });
              var gf = '', gr = '';
              $.each(order, function(_, k){
                var b = bucket[k];
                if(!b) return;
                /* A real heading element, so the grouping is structure and not just
                   bold text. h4 rather than h3 because both feedback PDFs already use
                   <h3> for the section labels ("Findings :", "Recommendations :") and
                   the content is injected into that same cell - an h3 here would render
                   at the same weight as the label it sits under. */
                var head = '<h4>' + esc(catName(k)) + '</h4>';
                if(b.f.length){ gf += head + b.f.join(''); }
                if(b.r.length === 1){ gr += head + b.r[0]; }
                else if(b.r.length > 1){
                  gr += head + '<ol>' + $.map(b.r, function(one){ return '<li>' + one + '</li>'; }).join('') + '</ol>';
                }
              });
              setCode('#finding',        gf ? (findBase + PFM_START + gf + PFM_END) : findBase);
              setCode('#recommendation', gr ? (recBase  + PFM_START + gr + PFM_END) : recBase);
              return;
            }

            var f = '', recs = [];

            /* The library holds two shapes of entry: some carry a short name
               plus separate detail, others (the imported master data) are a
               single sentence that is both. Repeating that sentence as its own
               heading reads as a duplicate, so the heading is emitted only when
               it actually says something the content does not. */
            function plain(html){ return $.trim($('<div>').html(html || '').text()); }

            $.each(list, function(_, it){
              var body = it.finding || '';
              if(plain(body) !== $.trim(it.title || '')){
                f += '<p><b>' + esc(it.title) + '</b></p>';
              }
              f += body;
              /* Findings often share one recommendation — several of the RDT
                 master entries carry the same paragraph — so an identical
                 recommendation is kept once however many findings ask for it. */
              var rec = $.trim(it.recommendation || '');
              if(rec !== '' && $.inArray(rec, recs) === -1){ recs.push(rec); }
            });

            /* One recommendation keeps exactly the output it had before; several
               are numbered so it is clear where one ends and the next begins. */
            var r = '';
            if(recs.length === 1){ r = recs[0]; }
            else if(recs.length > 1){
              r = '<ol>' + $.map(recs, function(one){ return '<li>' + one + '</li>'; }).join('') + '</ol>';
            }
            setCode('#finding', f ? (findBase + PFM_START + f + PFM_END) : findBase);
            setCode('#recommendation', r ? (recBase + PFM_START + r + PFM_END) : recBase);
         });
      }

      /* ---------- library CRUD ---------- */
      function load(cb){
        $.getJSON(PFM_URL, { action:'list', module:PFM_MODULE })
         .done(function(resp){
            if(resp && resp.ok){
              rows = resp.rows || [];
              cats = resp.cats || [];
              catNames = resp.catNames || {};
              catsAvailable = !!resp.catsDynamic;
              canManage = !!resp.canManage;
              applyCatVisibility();
              renderSelect();
              renderList();
            }
            if(cb) cb();
         });
      }

      /* Show or hide every category-only control in one place. */
      function applyCatVisibility(){
        var on = catsOn();
        /* Manage Categories is offered only where it can work. The note takes its
           place so the reader knows the feature exists and what enables it. */
        $('#pfmCatBtn').toggleClass('d-none', !catsAvailable);
        $('#pfmCatUnavailable').toggleClass('d-none', catsAvailable);
        /* The Category column replaced Order, so it is always shown. Only the
           filter and the form field depend on categories existing. */
        $('#pfmCatFilterWrap').toggleClass('d-none', !on);
        $('#pfmCatWrap').toggleClass('d-none', !on);
        var opts = '';
        $.each(cats, function(_, c){ opts += '<option value="'+esc(c.key)+'">'+esc(c.name)+'</option>'; });
        $('#pfmCat').html(opts);
        var keep = $('#pfmCatFilter').val() || '';
        $('#pfmCatFilter').html('<option value="">All categories</option>' + opts).val(keep);
      }

      /* The rows the list is currently showing: search text, then category. */
      function filteredRows(){
        var q   = $.trim(($('#pfmSearch').val() || '')).toLowerCase();
        var cat = catsOn() ? ($('#pfmCatFilter').val() || '') : '';
        return $.grep(rows, function(r){
          if(cat !== '' && String(r.category || 'general') !== String(cat)){ return false; }
          if(q === ''){ return true; }
          var hay = (String(r.title || '') + ' ' +
                     $('<div>').html(String(r.finding_content || '')).text() + ' ' +
                     $('<div>').html(String(r.recommendation_content || '')).text()).toLowerCase();
          return hay.indexOf(q) !== -1;
        });
      }

      function pageSize(){ return parseInt($('#pfmPageSize').val() || '10', 10) || 0; }

      /* Listing table: S.No. / Finding Name / [Category] / Order / Status / Action */
      function renderList(){
        var $body = $('#pfmTableBody');
        if(!$body.length) return;
        var cols = 5;   // S.No. / Finding Name / Category / Status / Action

        if(!rows.length){
          $body.html('<tr><td colspan="'+cols+'" class="pfm-empty">'+
                     'No findings yet. Use <strong>Add New Finding</strong> to create one.</td></tr>');
          $('#pfmCount').text(''); $('#pfmPages').empty();
          return;
        }

        var list  = filteredRows();
        var size  = pageSize();
        var total = list.length;
        var pages = (size > 0) ? Math.max(1, Math.ceil(total / size)) : 1;
        if(pfmPage > pages){ pfmPage = pages; }
        if(pfmPage < 1){ pfmPage = 1; }
        var from  = (size > 0) ? (pfmPage - 1) * size : 0;
        var slice = (size > 0) ? list.slice(from, from + size) : list;

        if(!total){
          $body.html('<tr><td colspan="'+cols+'" class="pfm-empty">No finding matches this search.</td></tr>');
          $('#pfmCount').text('0 of ' + rows.length + ' finding(s)');
          $('#pfmPages').empty();
          return;
        }

        var h = '';
        $.each(slice, function(i, r){
          var active = String(r.status) === '1';
          h += '<tr>'+
                 '<td class="text-center">'+(from + i + 1)+'</td>'+
                 '<td class="pfm-name">'+esc(r.title)+'</td>'+
                 '<td class="text-center">'+
                   (r.category ? '<span class="badge badge-light border">'+esc(catName(r.category))+'</span>'
                               : '<span class="text-muted">&mdash;</span>')+
                 '</td>'+
                 '<td class="text-center"><span class="badge badge-'+(active ? 'success' : 'secondary')+'">'+
                   (active ? 'Active' : 'Inactive')+'</span></td>'+
                 '<td>'+
                   '<div class="pfm-actions">'+
                     '<button type="button" class="btn btn-sm btn-outline-info pfmView" data-id="'+r.finding_id+'" title="View"><i class="fa fa-eye"></i></button>'+
                     '<button type="button" class="btn btn-sm btn-outline-primary pfmEdit" data-id="'+r.finding_id+'" title="Edit"><i class="fa fa-edit"></i></button>'+
                     '<button type="button" class="btn btn-sm btn-outline-danger pfmDel" data-id="'+r.finding_id+'" title="Delete"><i class="fa fa-trash"></i></button>'+
                   '</div>'+
                 '</td>'+
               '</tr>';
        });
        $body.html(h);

        var shown = 'Showing ' + (from + 1) + '\u2013' + (from + slice.length) + ' of ' + total;
        $('#pfmCount').text(total === rows.length ? (shown + ' finding(s)')
                                                  : (shown + ' matching finding(s) of ' + rows.length));

        var p = '';
        if(pages > 1){
          p += '<li class="page-item'+(pfmPage === 1 ? ' disabled' : '')+'">'+
               '<a class="page-link pfmGo" href="#" data-p="'+(pfmPage - 1)+'">&laquo;</a></li>';
          for(var n = 1; n <= pages; n++){
            /* Long libraries: first, last and a window around the current page. */
            if(pages > 7 && n !== 1 && n !== pages && Math.abs(n - pfmPage) > 2){
              if(Math.abs(n - pfmPage) === 3){ p += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'; }
              continue;
            }
            p += '<li class="page-item'+(n === pfmPage ? ' active' : '')+'">'+
                 '<a class="page-link pfmGo" href="#" data-p="'+n+'">'+n+'</a></li>';
          }
          p += '<li class="page-item'+(pfmPage === pages ? ' disabled' : '')+'">'+
               '<a class="page-link pfmGo" href="#" data-p="'+(pfmPage + 1)+'">&raquo;</a></li>';
        }
        $('#pfmPages').html(p);
      }

      /* ---------- screens: list / form / preview ---------- */
      /* ---------- categories ---------- */
      var pfmCats = [];

      function renderCatList(){
        var $b = $('#pfmCatBody');
        if(!$b.length) return;
        if(!pfmCats.length){
          $b.html('<tr><td colspan="6" class="pfm-empty">No categories yet. Use <strong>Add Category</strong> to create one.</td></tr>');
          return;
        }
        var h = '';
        $.each(pfmCats, function(i, c){
          var active = String(c.status) === '1';
          /* A category that findings point at cannot be deleted - the button says
             so rather than failing after the click. Inactive is the way out. */
          var used   = parseInt(c.in_use, 10) || 0;
          var delAttr = used
            ? ' disabled title="In use by '+used+' finding(s) - set the status to Inactive instead"'
            : ' title="Delete"';
          h += '<tr>'+
                 '<td class="text-center">'+(i+1)+'</td>'+
                 '<td class="pfm-name">'+esc(c.name)+'</td>'+
                 '<td class="pfm-name">'+(c.description ? esc(c.description) : '<span class="text-muted">&mdash;</span>')+'</td>'+
                 '<td class="text-center">'+(parseInt(c.in_use,10) || 0)+'</td>'+
                 '<td class="text-center"><span class="badge badge-'+(active ? 'success' : 'secondary')+'">'+
                   (active ? 'Active' : 'Inactive')+'</span></td>'+
                 '<td>'+
                   '<div class="pfm-actions">'+
                     '<button type="button" class="btn btn-sm btn-outline-primary pfmCatEdit" data-id="'+c.category_id+'" title="Edit"><i class="fa fa-edit"></i></button>'+
                     '<button type="button" class="btn btn-sm btn-outline-secondary pfmCatToggle" data-id="'+c.category_id+'" title="'+(active ? 'Deactivate' : 'Activate')+'">'+
                       '<i class="fa fa-'+(active ? 'toggle-on' : 'toggle-off')+'"></i></button>'+
                     '<button type="button" class="btn btn-sm btn-outline-danger pfmCatDel" data-id="'+c.category_id+'"'+delAttr+'><i class="fa fa-trash"></i></button>'+
                   '</div>'+
                 '</td>'+
               '</tr>';
        });
        $b.html(h);
      }

      function catById(id){
        var hit = null;
        $.each(pfmCats, function(_, c){ if(String(c.category_id) === String(id)){ hit = c; } });
        return hit;
      }

      /* Reloads the categories AND the findings, because a rename changes the
         names shown in the findings list and in the Category dropdown. */
      function loadCats(cb){
        $.getJSON(PFM_URL, { action:'cat_list', module:PFM_MODULE })
         .done(function(resp){
            if(resp && resp.ok){ pfmCats = resp.rows || []; renderCatList(); }
            else { alertBox('danger', (resp && resp.msg) || 'Could not load categories.'); }
            if(cb) cb();
         })
         .fail(function(){ alertBox('danger', 'Could not load categories.'); if(cb) cb(); });
      }

      function blankCatForm(){
        $('#pfmCatId').val(0); $('#pfmCatName').val(''); $('#pfmCatDesc').val(''); $('#pfmCatStatus').val('1');
      }

      function showCats(){
        $('#pfmListView, #pfmFormView, #pfmDetailView').addClass('d-none');
        $('#pfmCatView').removeClass('d-none');
        $('#pfmAddBtn, #pfmCatBtn, #pfmSaveBtn').addClass('d-none');
        $('#pfmBackBtn, #pfmCloseBtn').removeClass('d-none');
        $('#pfmCatForm').addClass('d-none');
        $('#pfmModalTitle').html('<i class="fa fa-tags"></i>&nbsp; Manage Categories');
      }

      function showList(){
        $('#pfmListView').removeClass('d-none');
        $('#pfmFormView, #pfmDetailView, #pfmCatView').addClass('d-none');
        $('#pfmAddBtn').removeClass('d-none');
        /* Only back to visible if the feature is available at all. */
        $('#pfmCatBtn').toggleClass('d-none', !catsAvailable);
        $('#pfmBackBtn, #pfmSaveBtn').addClass('d-none');
        $('#pfmCloseBtn').removeClass('d-none');
        // #pfmModalTitle, never ".modal-title": Summernote renders its own dialogs
        // (Insert Link / Image / Video) inside this modal and they carry that class.
        $('#pfmModalTitle').html('<i class="fa fa-cog"></i>&nbsp; Manage Findings');
      }

      function showForm(mode){
        $('#pfmListView, #pfmDetailView, #pfmCatView').addClass('d-none');
        $('#pfmFormView').removeClass('d-none');
        $('#pfmAddBtn, #pfmCatBtn, #pfmCloseBtn').addClass('d-none');
        $('#pfmBackBtn, #pfmSaveBtn').removeClass('d-none');
        $('#pfmSaveBtn').html('<i class="fa fa-save"></i> ' + (mode === 'edit' ? 'Update' : 'Save'));
        $('#pfmModalTitle').html('<i class="fa fa-'+(mode === 'edit' ? 'edit' : 'plus')+'"></i>&nbsp; '+
                                 (mode === 'edit' ? 'Edit Finding' : 'Add New Finding'));
        // Summernote measures its container, so it is created once the form is visible.
        initEditors();
      }

      function showDetail(){
        $('#pfmListView, #pfmFormView, #pfmCatView').addClass('d-none');
        $('#pfmDetailView').removeClass('d-none');
        $('#pfmAddBtn, #pfmCatBtn, #pfmCloseBtn, #pfmSaveBtn').addClass('d-none');
        $('#pfmBackBtn').removeClass('d-none');
        $('#pfmModalTitle').html('<i class="fa fa-eye"></i>&nbsp; View Finding');
      }

      function blankForm(){
        $('#pfmId').val(0); $('#pfmTitle').val(''); $('#pfmStatus').val('1');
        /* A new finding opens on whatever category the list is filtered to, which
           is almost always the one being worked on. */
        if(catsOn()){
          var pre = $('#pfmCatFilter').val() || '';
          $('#pfmCat').val(pre !== '' ? pre : $('#pfmCat option:first').val());
        }
        setCode('#pfmContent', ''); setCode('#pfmRec', '');
      }

      function initEditors(){
        if(editorsReady) return;
        if(typeof $.fn.summernote !== 'function') return;
        /* 'paragraph' carries the alignment/indent controls, which were missing
           here while every other editor in the app had them. */
        var cfg = { height: 150, toolbar: [['style',['bold','italic','underline','clear']],['para',['ul','ol','paragraph']]] };
        mnSummernote('#pfmContent', cfg);
        mnSummernote('#pfmRec', cfg);
        editorsReady = true;
      }

      $(function(){
        if(!$('#pfmSelect').length) return;
        load();

        // Select2 when available, so the multi-select matches the rest of the app.
        if(typeof $.fn.select2 === 'function'){
          $('#pfmSelect').select2({ theme:'bootstrap4', placeholder:'-- Select Finding(s) --', width:'100%' });
        }
        $(document).on('change', '#pfmSelect', applySelection);
        /* Category presets: a user-driven category change rewrites the headed
           block in both editors, leaving the author's own text alone. */
        $(document).on('change', '#pfmCat', applyCatPresets);

        // The manage screen always opens on the listing.
        $(document).on('shown.bs.modal', '#pfmModal', function(){ renderList(); showList(); });

        // Search / filter / page size all reset to the first page.
        $(document).on('input', '#pfmSearch', function(){ pfmPage = 1; renderList(); });
        $(document).on('change', '#pfmCatFilter, #pfmPageSize', function(){ pfmPage = 1; renderList(); });
        $(document).on('click', '#pfmPages .pfmGo', function(e){
          e.preventDefault();
          if($(this).closest('li').hasClass('disabled')) return;
          var p = parseInt($(this).data('p'), 10);
          if(p > 0){ pfmPage = p; renderList(); }
        });

        $(document).on('click', '#pfmAddBtn', function(){ blankForm(); showForm('add'); });

        /* ---------- categories ---------- */
        $(document).on('click', '#pfmCatBtn', function(){
          $('#pfmAlert').empty();
          showCats();
          loadCats();
        });
        $(document).on('click', '#pfmCatAddBtn', function(){
          blankCatForm();
          $('#pfmCatForm').removeClass('d-none');
          $('#pfmCatName').trigger('focus');
        });
        $(document).on('click', '#pfmCatCancel', function(){ $('#pfmCatForm').addClass('d-none'); });
        $(document).on('click', '.pfmCatEdit', function(){
          var c = catById($(this).data('id'));
          if(!c) return;
          $('#pfmCatId').val(c.category_id);
          $('#pfmCatName').val(c.name);
          $('#pfmCatDesc').val(c.description || '');
          $('#pfmCatStatus').val(String(c.status) === '1' ? '1' : '0');
          $('#pfmCatForm').removeClass('d-none');
          $('#pfmCatName').trigger('focus');
        });

        /* Status toggle reuses cat_save, so the name/description round-trip
           unchanged and only the flag moves. */
        $(document).on('click', '.pfmCatToggle', function(){
          var c = catById($(this).data('id'));
          if(!c) return;
          var $b = $(this).prop('disabled', true);
          $.post(PFM_URL, {
            action:'cat_save', module:PFM_MODULE,
            category_id: c.category_id, name: c.name, description: c.description || '',
            status: (String(c.status) === '1' ? 0 : 1)
          }, null, 'json')
          .done(function(resp){
            $b.prop('disabled', false);
            if(resp && resp.ok){ loadCats(); load(); }
            else { alertBox('danger', (resp && resp.msg) || 'Could not change the status.'); }
          })
          .fail(function(){ $b.prop('disabled', false); alertBox('danger', 'Could not change the status.'); });
        });

        $(document).on('click', '#pfmCatSave', function(){
          var name = $.trim($('#pfmCatName').val());
          if(name === ''){ alertBox('warning', 'Category name is required.'); return; }
          var $b = $(this).prop('disabled', true);
          $.post(PFM_URL, {
            action:'cat_save', module:PFM_MODULE,
            category_id: $('#pfmCatId').val(), name: name,
            description: $('#pfmCatDesc').val(), status: $('#pfmCatStatus').val()
          }, null, 'json')
          .done(function(resp){
            $b.prop('disabled', false);
            if(resp && resp.ok){
              alertBox('success', 'Category saved.');
              $('#pfmCatForm').addClass('d-none');
              blankCatForm();
              loadCats();
              load();          // refresh the findings list and the Category dropdown
            } else { alertBox('danger', (resp && resp.msg) || 'Could not save the category.'); }
          })
          .fail(function(){ $b.prop('disabled', false); alertBox('danger', 'Could not save the category.'); });
        });

        $(document).on('click', '.pfmCatDel', function(){
          var c = catById($(this).data('id'));
          if(!c) return;
          var used = parseInt(c.in_use, 10) || 0;
          if(used > 0){
            alertBox('warning', '"' + c.name + '" is used by ' + used + ' finding(s), so it cannot be deleted. '+
              'Set its status to Inactive instead - it will no longer be offered for new findings, '+
              'and the existing ones keep their heading.');
            return;
          }
          if(!confirm('Delete the category "' + c.name + '"?')) return;
          $.post(PFM_URL, { action:'cat_delete', module:PFM_MODULE, category_id: c.category_id }, null, 'json')
           .done(function(resp){
              if(resp && resp.ok){
                alertBox('success', resp.msg || 'Category deleted.');
                loadCats(); load();
              } else { alertBox('danger', (resp && resp.msg) || 'Could not delete the category.'); }
           })
           .fail(function(){ alertBox('danger', 'Could not delete the category.'); });
        });

        $(document).on('click', '#pfmBackBtn', function(){ $('#pfmAlert').empty(); showList(); });

        $(document).on('click', '.pfmView', function(){
          var r = rowById($(this).data('id'));
          if(!r) return;
          var active = String(r.status) === '1';
          $('#pfmvTitle').text(r.title || '');
          $('#pfmvStatus').html('<span class="badge badge-'+(active ? 'success' : 'secondary')+'">'+
                                (active ? 'Active' : 'Inactive')+'</span>');
          $('#pfmvCatWrap').toggleClass('d-none', !catsOn());
          if(catsOn()){ $('#pfmvCat').text(catName(r.category || 'general')); }
          $('#pfmvContent').html(r.finding_content || '<span class="text-muted">—</span>');
          $('#pfmvRec').html(r.recommendation_content || '<span class="text-muted">—</span>');
          showDetail();
        });

        $(document).on('click', '.pfmEdit', function(){
          var r = rowById($(this).data('id'));
          if(!r) return;
          showForm('edit');                      // editors exist before the content is written
          $('#pfmId').val(r.finding_id);
          $('#pfmTitle').val(r.title);
          /* A finding saved under a category that has since been removed keeps
             its value: it is added to the list so editing does not silently
             move it somewhere else. */
          if(catsOn()){
            var cur = String(r.category || 'general');
            if(!$('#pfmCat option[value="' + cur.replace(/"/g, '\\"') + '"]').length){
              $('#pfmCat').append($('<option>').val(cur).text(cur + ' (unlisted)'));
            }
            $('#pfmCat').val(cur);
          }
          $('#pfmStatus').val(String(r.status) === '1' ? '1' : '0');
          setCode('#pfmContent', r.finding_content || '');
          setCode('#pfmRec', r.recommendation_content || '');
        });

        $(document).on('click', '#pfmSaveBtn', function(){
          var title = $.trim($('#pfmTitle').val());
          if(title === ''){ alertBox('warning', 'Finding name is required.'); return; }
          if($.trim($('<div>').html(getCode('#pfmContent')).text()) === ''){
            alertBox('warning', 'Finding content is required.'); return;
          }
          /* Category drives the heading each finding gets in the editors, so a
             finding cannot be saved without one once categories exist. */
          if(catsOn() && !$('#pfmCat').val()){
            alertBox('warning', 'Category is required.'); return;
          }
          var $b = $(this).prop('disabled', true);
          $.post(PFM_URL, {
            action:'save', module:PFM_MODULE,
            finding_id: $('#pfmId').val(), title: title,
            finding_content: getCode('#pfmContent'),
            recommendation_content: getCode('#pfmRec'),
            status: $('#pfmStatus').val(),
            category: catsOn() ? ($('#pfmCat').val() || '') : ''
          }, null, 'json')
          .done(function(resp){
            $b.prop('disabled', false);
            if(resp && resp.ok){ alertBox('success', 'Saved.'); blankForm(); load(showList); }
            else { alertBox('danger', (resp && resp.msg) || 'Save failed.'); }
          })
          .fail(function(){ $b.prop('disabled', false); alertBox('danger', 'Save failed.'); });
        });

        $(document).on('click', '.pfmDel', function(){
          if(!confirm('Delete this finding? Feedback that already uses it will keep working.')) return;
          $.post(PFM_URL, { action:'delete', module:PFM_MODULE, finding_id: $(this).data('id') }, null, 'json')
           .done(function(resp){
              if(resp && resp.ok){ alertBox(resp.deactivated ? 'info' : 'success', resp.msg || 'Deleted.'); blankForm(); load(showList); }
              else { alertBox('danger', (resp && resp.msg) || 'Delete failed.'); }
           })
           .fail(function(){ alertBox('danger', 'Delete failed.'); });
        });
      });
    })();
    </script>
    <?php
}

}
