<?php
/*
 * MPQMS — Analyst / Approver master save endpoint.
 *
 * POST /MPQMS/settings/save_analyst_approver.php
 *   csrf    = <session csrf token>
 *   action  = create | update | toggle | delete
 *   kind    = analyst | approver
 *   id      = <row id>              (update/toggle/delete)
 *   name    = <display name>        (create/update)
 *   status  = 1 | 0                 (create/update/toggle)
 *
 * Returns {status:'success'|'error', message:string}.
 */
declare(strict_types=1);
include_once("../config/auth_ndtlis.php");
include_once("../config/db_config.php");
include_once("../config/mpq_validate.php");

header('Content-Type: application/json; charset=utf-8');

$fail = function($msg, $code=400){
    http_response_code($code);
    echo json_encode(['status'=>'error','message'=>$msg]);
    exit;
};

if(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'){ $fail('POST only.', 405); }

// Oct-13 2026 — ADMIN-only. Blocks any crafted POST from BFDA / NDTL / NMRL
// even if a stale mpqms_role_permissions row carries can_enter / can_edit for
// their role. Per-action matrix bit is still re-checked below (defense in depth).
if(!(function_exists('mpq_effective_role') && mpq_effective_role() === 'ADMIN')){
    $fail('You do not have permission for this action.', 403);
}

// CSRF
$posted = (string)($_POST['csrf'] ?? '');
$session = (string)($_SESSION['csrf'] ?? '');
if($session === '' || !hash_equals($session, $posted)){ $fail('Security token mismatch. Please refresh and try again.', 403); }

$action = (string)($_POST['action'] ?? '');
$kind   = (string)($_POST['kind']   ?? '');
if(!in_array($action, ['create','update','toggle','delete'], true)){ $fail('Unknown action.'); }
if(!in_array($kind,   ['analyst','approver'], true)){ $fail('Unknown master.'); }

$table    = ($kind === 'approver') ? 'approver_master' : 'analyst_master';
$module   = ($kind === 'approver') ? 'approver_master' : 'analyst_master';

// Per-action permission re-check (server-side source of truth).
$needed = ($action === 'create') ? 'add'
        : (($action === 'update' || $action === 'toggle') ? 'edit' : 'delete');
if(!mpq_can_do($module, $needed)){ $fail('You do not have permission for this action.', 403); }

$id   = (int)($_POST['id']   ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
if(strlen($name) > 120){ $name = substr($name, 0, 120); }
$status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
$status = $status === 0 ? 0 : 1;

$esc = function($v) use($connection){ return mysqli_real_escape_string($connection, (string)$v); };

try {
    switch($action){
        case 'create':
            if($name === ''){ $fail('Name is required.'); }
            $nameEsc = $esc($name);
            $ok = @mysqli_query($connection,
                "INSERT INTO $table (name, status) VALUES ('$nameEsc', $status)");
            if(!$ok){
                if(mysqli_errno($connection) === 1062){ $fail('"'.$name.'" already exists in this master.'); }
                $fail('Could not save: '.mysqli_error($connection));
            }
            echo json_encode(['status'=>'success','message'=>'Added.']);
            exit;

        case 'update':
            if($id <= 0 || $name === ''){ $fail('Invalid payload.'); }
            $nameEsc = $esc($name);
            $ok = @mysqli_query($connection,
                "UPDATE $table SET name='$nameEsc', status=$status WHERE id=$id");
            if(!$ok){
                if(mysqli_errno($connection) === 1062){ $fail('"'.$name.'" already exists in this master.'); }
                $fail('Could not update: '.mysqli_error($connection));
            }
            echo json_encode(['status'=>'success','message'=>'Updated.']);
            exit;

        case 'toggle':
            if($id <= 0){ $fail('Invalid id.'); }
            $ok = @mysqli_query($connection,
                "UPDATE $table SET status=$status WHERE id=$id");
            if(!$ok){ $fail('Could not update status.'); }
            echo json_encode(['status'=>'success','message'=>($status ? 'Activated.' : 'Deactivated.')]);
            exit;

        case 'delete':
            if($id <= 0){ $fail('Invalid id.'); }
            $ok = @mysqli_query($connection, "DELETE FROM $table WHERE id=$id");
            if(!$ok){ $fail('Could not delete.'); }
            echo json_encode(['status'=>'success','message'=>'Deleted.']);
            exit;
    }
} catch(\Throwable $e){
    $fail('Unexpected error: '.$e->getMessage(), 500);
}
?>
