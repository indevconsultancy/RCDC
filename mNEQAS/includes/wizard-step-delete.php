<?php
/**
 * Wizard step Delete — shared across every stepper in mNEQAS.
 * ============================================================================
 * Renders a red Delete button into a wizard's action bar, beside Cancel and
 * Previous, and gives it the behaviour that is safe to apply uniformly:
 *
 *   DRAFT (nothing saved for this step yet)
 *     Clears every field, dropdown, editor and dynamic table row inside the
 *     step that is on screen. Touches no database row at all, so there is
 *     nothing to confirm and nothing to undo on the server.
 *
 *   SAVED (this step already has stored data)
 *     Asks for confirmation with the agreed wording, then hands off to the
 *     module's own delete endpoint for the row the user is clearing.
 *
 *   WHOLE RECORD  ('action' => 'record' on the step)
 *     For a read-only summary step - a Review & Print at the end of a wizard -
 *     there are no fields to clear and no single row the step owns, so the
 *     draft and saved paths above both have nothing to act on. Such a step
 *     instead confirms with its own wording and hands off to the module's
 *     whole-record delete. The step declares the endpoint in 'url' and the
 *     question in 'confirm'; an empty 'url' means the signed-in user may not
 *     delete records, and the button says so - in that step's own words if it
 *     supplies 'denied' - rather than posting to be refused.
 *
 * WHY THE SAVED PATH HANDS OFF RATHER THAN DELETING HERE
 * Each module's delete.php already owns this: it carries the write-role check,
 * the record-ownership check, the stage-owner role rule, and the dependency
 * rules that refuse to clear a stage while a later stage still holds data
 * ("Shipment receipt cannot be undone while Controller 1 results exist").
 * Those guards are the reason clearing a step cannot quietly destroy the lab
 * results that depend on it, so this file routes through them instead of
 * issuing its own SQL.
 *
 * Their granularity is per ROW, not per step: an RDT request has many products,
 * and `rac` clears one product's assessment. A single button that cleared every
 * product's assessment at once would be a new bulk delete, with its own guards
 * to design, so this file does not invent one. Where a step owns exactly one row
 * the hand-off is exact; where it owns many, the button clears the draft and
 * points at the per-row controls that already exist on the step.
 *
 * THE STEPPER TICK
 * Nothing here updates it. Every wizard derives completion from the data itself
 * ($doneSteps in rdt-registration.php, and the equivalent elsewhere), so once a
 * row is gone the tick reverts on the next render and dependent steps fall back
 * to Pending on their own.
 *
 * USAGE
 *   require_once(__DIR__ . '/../includes/wizard-step-delete.php');
 *   render_wizard_step_delete(array(
 *       'module'    => 'rdt',                        // for the audit entry
 *       'record_id' => (int)$id,                     // 0 while unsaved
 *       'can_write' => mn_is_rcdc(),                 // hides the button outright
 *       'steps'     => array(                        // step number => metadata
 *           2 => array('name' => 'Product Details', 'rows' => 'many'),
 *           5 => array('name' => 'Accessory Assessment', 'rows' => 'many'),
 *           7 => array('name' => 'Review & Print', 'rows' => 'none',
 *                      'action'  => 'record',
 *                      'url'     => mn_is_admin() ? 'delete.php?type=tr&id=9&trid=9' : '',
 *                      'confirm' => 'Are you sure you want to delete this record?',
 *                      'denied'  => 'Deleting a record is restricted to an Administrator.'),
 *       ),
 *   ));
 */

if (!function_exists('render_wizard_step_delete')) {

/**
 * Records a step-clearing event. Silent when the table is absent, so this ships
 * before the migration and starts logging the moment the table exists.
 */
function mn_log_step_delete($conn, $module, $stepName, $recordId, $detail = '') {
    $chk = @$conn->query("SHOW TABLES LIKE 'system_activity_log'");
    if (!$chk || $chk->num_rows === 0) { return false; }

    $userId = isset($_SESSION['userID']) ? (string)$_SESSION['userID'] : '';
    $roleId = isset($_SESSION['roleID']) ? (string)$_SESSION['roleID'] : '';
    $ip     = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $now    = date('Y-m-d H:i:s');
    $action = 'STEP_DELETE';

    $st = $conn->prepare("INSERT INTO system_activity_log
                          (user_id, role_id, module, action, step_name, record_id, detail, ip_address, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?)");
    if (!$st) { return false; }
    $rid = (int)$recordId;
    $st->bind_param('sssssisss', $userId, $roleId, $module, $action, $stepName, $rid, $detail, $ip, $now);
    $ok = $st->execute();
    $st->close();
    return (bool)$ok;
}

function render_wizard_step_delete(array $cfg) {
    $module   = isset($cfg['module'])    ? (string)$cfg['module']    : '';
    $recordId = isset($cfg['record_id']) ? (int)$cfg['record_id']    : 0;
    $canWrite = !empty($cfg['can_write']);
    $steps    = isset($cfg['steps']) && is_array($cfg['steps']) ? $cfg['steps'] : array();

    /* Without write rights the control is not rendered at all, so there is no
       button to probe and nothing to disable in the DOM. */
    if (!$canWrite) { return; }
    ?>
    <!-- Clears the step on screen. Red, and sits with Cancel / Previous. -->
    <button type="button" class="btn bg-gradient-danger mr-auto" id="wizStepDelete">
      <i class="fa fa-trash"></i> Delete
    </button>

    <div class="modal fade" id="wizStepDelModal" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title mb-0"><i class="fa fa-exclamation-triangle text-danger"></i>&nbsp; Confirm Deletion</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <p class="mb-2" id="wizStepDelAsk">Are you sure you want to delete data for this step? This will reset downstream dependent steps.</p>
            <p class="mb-0 small text-muted" id="wizStepDelNote"></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn bg-gradient-secondary" data-dismiss="modal">Cancel</button>
            <button type="button" class="btn bg-gradient-danger" id="wizStepDelGo">Delete</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    /* Scoped so it cannot collide with a wizard's own script block. */
    (function(){
      /* The modal is emitted next to the button, which sits inside the action
         bar - a flex row, sometimes inside a positioned card. Bootstrap wants a
         modal at body level, so it is moved there once, which keeps the backdrop
         and stacking order correct whatever the host page does with the footer. */
      $(function(){ $('#wizStepDelModal').appendTo('body'); });

      var WSD_STEPS     = <?= json_encode($steps) ?>;
      var WSD_RECORD_ID = <?= (int)$recordId ?>;

      /* The step on screen, whichever markup the wizard uses: the panels are
         .wiz-pane in most modules, .wizard-step in Blinded Rechecking and
         .workflow-pane in Molecular Testing, and all three carry data-step.
         Molecular has to be named explicitly because its Review step holds no
         inputs at all, so the generic fallback below - which looks for a
         visible [data-step] that contains a field - would never find it. */
      function wsdPane(){
        var $p = $('.wiz-pane[data-step]:visible, .wizard-step[data-step]:visible, .workflow-pane[data-step]:visible').first();
        if(!$p.length){ $p = $('[data-step]:visible').filter(function(){ return $(this).find(':input').length > 0; }).first(); }
        return $p;
      }
      function wsdStepNo($pane){ return parseInt(($pane.attr('data-step') || '0'), 10) || 0; }

      /* Does the step on screen hold anything the user has typed? Used only to
         choose between clearing a draft and confirming a stored delete. */
      function wsdPaneHasInput($pane){
        var dirty = false;
        $pane.find('input, select, textarea').each(function(){
          var $f = $(this), t = (this.type || '').toLowerCase();
          if($f.is(':disabled') || t === 'hidden' || t === 'submit' || t === 'button'){ return; }
          if(t === 'checkbox' || t === 'radio'){ if(this.checked){ dirty = true; } return; }
          if($.trim($f.val() || '') !== ''){ dirty = true; }
        });
        return dirty;
      }

      /* Clear everything the user can type into, inside this step only. */
      function wsdClearPane($pane){
        $pane.find('input, select, textarea').each(function(){
          var $f = $(this), t = (this.type || '').toLowerCase();
          if($f.is(':disabled') || t === 'submit' || t === 'button'){ return; }
          /* Hidden ids keep a saved row addressable; clearing them would detach
             the row from its record on the next save. */
          if(t === 'hidden'){ return; }
          if(t === 'checkbox' || t === 'radio'){ this.checked = false; $f.trigger('change'); return; }
          if($f.is('select')){
            $f.val('');
            if($f.hasClass('select2') || $f.data('select2')){ try { $f.trigger('change.select2'); } catch(e){} }
            $f.trigger('change');
            return;
          }
          /* A rich-text field is emptied through its editor, or the textarea
             keeps showing the old HTML. */
          if($f.hasClass('summernote') || $f.next('.note-editor').length || $f.parent().find('.note-editor').length){
            try { $f.summernote('code', ''); return; } catch(e){}
          }
          $f.val('').trigger('change');
        });

        /* Dynamic table rows: keep the first as the empty template the wizard
           expects and drop the rest, which is what every Add-row script assumes. */
        $pane.find('table tbody').each(function(){
          var $rows = $(this).children('tr');
          if($rows.length > 1){ $rows.slice(1).remove(); }
        });

        /* Any validation state left over from the cleared values. */
        $pane.find('.is-invalid').removeClass('is-invalid');
        $pane.find('.invalid-feedback, .error').remove();
      }

      function wsdToast(kind, msg){
        if(window.Swal){
          Swal.fire({ icon: kind, text: msg, confirmButtonText: 'OK' });
        } else {
          alert(msg);
        }
      }

      var WSD_ASK_DEFAULT = $('#wizStepDelAsk').text();

      $(document).on('click', '#wizStepDelete', function(){
        var $pane = wsdPane();
        if(!$pane.length){ return; }
        var no   = wsdStepNo($pane);
        var meta = WSD_STEPS[String(no)] || WSD_STEPS[no] || null;
        var name = (meta && meta.name) ? meta.name : ('Step ' + no);

        /* A step that deletes the whole record is decided before anything else:
           it is a read-only summary, so there is nothing on screen to inspect
           and the field check below would always call it empty. */
        if(meta && meta.action === 'record'){
          if(!WSD_RECORD_ID){
            wsdToast('info', 'Save the record before deleting it.');
            return;
          }
          if(!meta.url){
            /* No url means the signed-in user may not do this one. The reason
               differs by step - a whole record is Administrator-only, an RCDC
               stage is RCDC-only - so the step supplies its own wording and
               the Administrator sentence is only the fallback. */
            wsdToast('info', meta.denied || 'Deleting an entire record is restricted to an Administrator.');
            return;
          }
          $('#wizStepDelAsk').text(meta.confirm || WSD_ASK_DEFAULT);
          $('#wizStepDelNote').text('This removes the request and everything recorded against it. It cannot be undone.');
          $('#wizStepDelModal').data('target-url', meta.url).modal('show');
          return;
        }

        /* Anything else keeps the wording it had. */
        $('#wizStepDelAsk').text(WSD_ASK_DEFAULT);

        /* An unsaved record has nothing stored for any step, so this is always
           the draft path - no confirmation, no server call. */
        var saved = WSD_RECORD_ID > 0 && meta && meta.saved;

        if(!saved){
          if(!wsdPaneHasInput($pane)){
            wsdToast('info', 'There is nothing entered on this step to clear.');
            return;
          }
          wsdClearPane($pane);
          wsdToast('success', name + ': the entries on this step were cleared. Nothing was deleted from the database.');
          return;
        }

        /* Stored data: confirm, then hand off to the module's own delete, which
           carries the role, ownership and dependency rules. */
        $('#wizStepDelNote').text(
          meta.rows === 'many'
            ? 'This step holds one entry per row. Use the Delete control on the row you want to remove - each row is checked against the stages that depend on it.'
            : ('Step: ' + name)
        );
        $('#wizStepDelModal').data('target-url', meta.url || '').modal('show');
      });

      $(document).on('click', '#wizStepDelGo', function(){
        var url = $('#wizStepDelModal').data('target-url') || '';
        $('#wizStepDelModal').modal('hide');
        if(url){
          /* delete.php applies its guards and then returns here, so the stepper
             re-derives every tick from the data that is left. */
          window.location = url;
          return;
        }
        wsdToast('info', 'Use the Delete control beside the row you want to remove on this step.');
      });
    })();
    </script>
    <?php
}

}
