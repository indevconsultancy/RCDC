<?php 
/* 
 * MPQMS Admin — User Management backend (Administrator / acl==1 only). 
 */ 

// 1. Buffer output immediately to prevent any included script from emitting whitespace or unexpected HTML 
ob_start(); 

// Suppress direct HTML error rendering 
ini_set('display_errors', 0); 
error_reporting(E_ALL); 

// Output helper function to output pure JSON and terminate cleanly 
function jout($status, $msg, $extra = array()){  
    if (ob_get_length()) ob_clean(); 
    header('HTTP/1.1 200 OK'); 
    header('Content-Type: application/json; charset=utf-8'); 
    echo json_encode(array_merge(array('status' => $status, 'message' => $msg), $extra));  
    exit;  
} 

// Custom error handler to trap PHP notices/warnings as clean JSON 
set_error_handler(function($errno, $errstr, $errfile, $errline) { 
    jout('error', "PHP Error: $errstr in " . basename($errfile) . " on line $errline"); 
}); 

try { 
    include_once("../config/auth_ndtlis.php"); 
    include_once("../config/db_config.php"); 
    include_once("../config/mpq_validate.php"); 

    // Clean buffer after file inclusions 
    if (ob_get_length()) ob_clean(); 

    // 2. Strict Administrator-only enforcement 
    if (!isset($_SESSION['acl']) || (int)$_SESSION['acl'] !== 1) { 
        jout('error', 'Unauthorized access. Only Administrators are permitted to perform this action.'); 
    } 

    if (function_exists('mpq_require_role')) { 
        mpq_require_role(array(1), true);   // Administrator only 
    } 
    if (function_exists('mpq_csrf_check')) { 
        mpq_csrf_check(true);               // Validates CSRF token 
    } 

    $action = $_POST['action']   ?? ''; 
    $uname  = trim($_POST['username'] ?? ''); 
    $me     = $_SESSION['users'] ?? ($_SESSION['username'] ?? ''); 
    $now    = date('Y-m-d H:i:s'); 

    // Connection selection 
    $auditConn = isset($ndtlisdb) ? $ndtlisdb : $commondb; 

    function mpu_audit($conn, $target, $action, $details, $by, $now){ 
        if (!$conn) return; 
        
        // Auto-create audit table if missing to prevent fatal SQL exceptions
        $createTableQuery = "CREATE TABLE IF NOT EXISTS mpqms_user_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_username VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT,
            changed_by VARCHAR(100) NOT NULL,
            changed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        @mysqli_query($conn, $createTableQuery);

        $t = mysqli_real_escape_string($conn, $target);  
        $a = mysqli_real_escape_string($conn, $action); 
        $d = mysqli_real_escape_string($conn, json_encode($details));  
        $b = mysqli_real_escape_string($conn, $by); 
        @mysqli_query($conn, "INSERT INTO mpqms_user_audit(target_username, action, details, changed_by, changed_at) VALUES('$t', '$a', '$d', '$b', '$now')"); 
    } 

    function mpu_admin_count($commondb, $exceptUser=null){ 
        $w = "system_id=9 AND access_level=1"; 
        if($exceptUser !== null){  
            $w .= " AND username <> '" . mysqli_real_escape_string($commondb, $exceptUser) . "'";  
        } 
        $res = mysqli_query($commondb, "SELECT COUNT(*) FROM user_system_access_table WHERE $w"); 
        return $res ? (int)mysqli_fetch_row($res)[0] : 0; 
    } 

    if($uname === ''){  
        jout('error', 'Username is required.');  
    } 

    $unameE = mysqli_real_escape_string($commondb, $uname); 
    $isSelf = (strcasecmp($uname, $me) === 0); 
    $roleValid = function($acl){ return in_array((int)$acl, array(1, 2, 7), true); }; 

    if($action === 'grant'){ 
        $acl      = (int)($_POST['access_level'] ?? 0); 
        $ucat     = (int)($_POST['user_category'] ?? 0); 
        $fullname = trim($_POST['fullname'] ?? ''); 
        $email    = trim($_POST['email'] ?? ''); 
        $password = $_POST['password'] ?? ''; 
        $centerId = isset($_POST['center_id']) ? (int)$_POST['center_id'] : 0; 
        $designationId = isset($_POST['designation']) ? (int)$_POST['designation'] : 0; 

        if(!$roleValid($acl)){  
            jout('error', 'Please choose a valid role.');  
        } 
        // NMRL (access_level 2 / user_category 19) is no longer assignable to a NEW user. The role,
        // its permissions and every existing NMRL user are untouched; 'role' (Edit) is not affected.
        if($acl === 2 && $ucat === 19){ 
            jout('error', 'The NMRL role can no longer be assigned to a new user. Please choose another role.'); 
        } 

        // 1. Check if login record exists 
        $chkUser = mysqli_query($commondb, "SELECT 1 FROM login_table WHERE username='$unameE'"); 
        if(!$chkUser){ 
            jout('error', 'Database query error on login check: '.mysqli_error($commondb)); 
        } 

        if(mysqli_num_rows($chkUser) == 0){ 
            if(empty($fullname) || empty($email) || empty($password)){ 
                jout('error', 'New user creation requires Full Name, Email, and Password.'); 
            } 

            // Create Login Entry 
            $passHash = md5($password); 
            $insLogin = mysqli_query($commondb, "INSERT INTO login_table (username, password) VALUES ('$unameE', '$passHash')"); 
             
            if(!$insLogin){  
                jout('error', 'Failed to create login account: '.mysqli_error($commondb));  
            } 

            // Fetch fallback center_id if not explicitly sent 
            if ($centerId <= 0) { 
                $getCenter = mysqli_query($commondb, "SELECT center_id FROM ms_health_center_table LIMIT 1"); 
                if ($getCenter && mysqli_num_rows($getCenter) > 0) { 
                    $rowCenter = mysqli_fetch_assoc($getCenter); 
                    $centerId = (int)$rowCenter['center_id']; 
                } 
            } 
            if ($centerId <= 0) { 
                jout('error', 'No valid Health Center found in ms_health_center_table.'); 
            } 

            // Fetch fallback designation ID if not explicitly sent
            if ($designationId <= 0) { 
                $getDesig = mysqli_query($commondb, "SELECT id FROM ms_designation_table LIMIT 1"); 
                if ($getDesig && mysqli_num_rows($getDesig) > 0) { 
                    $rowDesig = mysqli_fetch_assoc($getDesig); 
                    $designationId = (int)$rowDesig['id']; 
                } 
            } 
            if ($designationId <= 0) { 
                jout('error', 'No valid Designation found in ms_designation_table.'); 
            } 

            // Create Profile Entry 
            $fnE = mysqli_real_escape_string($commondb, $fullname); 
            $emE = mysqli_real_escape_string($commondb, $email); 
             
            $chkProf = mysqli_query($commondb, "SELECT 1 FROM user_profile_table WHERE username='$unameE'"); 
            if(mysqli_num_rows($chkProf) == 0){ 
                $insProfile = mysqli_query($commondb, 
                    "INSERT INTO user_profile_table (username, fullname, email_id, center_id, designation) 
                     VALUES ('$unameE', '$fnE', '$emE', $centerId, $designationId)"
                ); 
                if(!$insProfile){ 
                    jout('error', 'Failed to create user profile: '.mysqli_error($commondb)); 
                } 
            } 
        } 

        // 2. Check if MPQMS access is assigned 
        $chkAccess = mysqli_query($commondb, "SELECT 1 FROM user_system_access_table WHERE username='$unameE' AND system_id=9"); 
        if($chkAccess && mysqli_num_rows($chkAccess) > 0){ 
            jout('error', 'This user already has MPQMS access — use "Edit Role" instead.'); 
        } 

        // 3. Grant System Access (system_id = 9) 
        $insAccess = mysqli_query($commondb, "INSERT INTO user_system_access_table (username, system_id, access_level, user_category, unit_access) VALUES ('$unameE', 9, $acl, $ucat, 1)"); 
        if(!$insAccess){ 
            jout('error', 'Failed to grant system access: '.mysqli_error($commondb)); 
        } 

        mpu_audit($auditConn, $uname, 'GRANT_ACCESS', array('access_level'=>$acl, 'user_category'=>$ucat), $me, $now); 
        jout('success', 'User '.$uname.' created and granted MPQMS access successfully.'); 
    } 
    else if($action === 'role'){

    $acl      = (int)($_POST['access_level'] ?? 0);
    $ucat     = (int)($_POST['user_category'] ?? 0);
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if(!$roleValid($acl)){
        jout('error', 'Please choose a valid role.');
    }

    if($fullname === ''){
        jout('error', 'Full Name is required.');
    }

    if($email === ''){
        jout('error', 'Email Address is required.');
    }

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        jout('error', 'Please enter a valid Email Address.');
    }

    $chkAcc = mysqli_query(
        $commondb,
        "SELECT 1
         FROM user_system_access_table
         WHERE username='$unameE'
         AND system_id=9"
    );

    if(!$chkAcc || mysqli_num_rows($chkAcc) == 0){
        jout('error', 'This user has no MPQMS access to edit.');
    }

    if($isSelf && $acl != 1){
        jout('error', 'You cannot remove your own Administrator role.');
    }

    if($acl != 1 && mpu_admin_count($commondb, $uname) == 0){
        jout('error', 'This is the last Administrator — assign another admin first.');
    }

    /*
     * Escape editable profile values.
     */
    $fullnameE = mysqli_real_escape_string($commondb, $fullname);
    $emailE    = mysqli_real_escape_string($commondb, $email);

    /*
     * Update MPQMS role/access information.
     */
    $updRole = mysqli_query(
        $commondb,
        "UPDATE user_system_access_table
         SET access_level=$acl,
             user_category=$ucat
         WHERE username='$unameE'
         AND system_id=9"
    );

    if(!$updRole){
        jout(
            'error',
            'Failed to update user role: ' . mysqli_error($commondb)
        );
    }

    /*
     * Update Full Name and Email in the user profile table.
     */
    $updProfile = mysqli_query(
        $commondb,
        "UPDATE user_profile_table
         SET fullname='$fullnameE',
             email_id='$emailE'
         WHERE username='$unameE'"
    );

    if(!$updProfile){

        /*
         * Role was already updated, so report the actual
         * profile update failure instead of showing success.
         */
        jout(
            'error',
            'Role was updated, but Full Name and Email could not be updated: ' .
            mysqli_error($commondb)
        );
    }

    /*
     * Audit the complete user update.
     */
    mpu_audit(
        $auditConn,
        $uname,
        'UPDATE_USER',
        array(
            'fullname'      => $fullname,
            'email'         => $email,
            'access_level'  => $acl,
            'user_category' => $ucat
        ),
        $me,
        $now
    );

    jout(
        'success',
        'User details and role updated successfully for ' . $uname . '.'
    );
} 
    else if($action === 'revoke'){ 
        if($isSelf){  
            jout('error', 'You cannot revoke your own MPQMS access.');  
        } 
         
        $chkAcc = mysqli_query($commondb, "SELECT 1 FROM user_system_access_table WHERE username='$unameE' AND system_id=9"); 
        if(!$chkAcc || mysqli_num_rows($chkAcc) == 0){ 
            jout('error', 'This user has no MPQMS access.'); 
        } 
        if(mpu_admin_count($commondb, $uname) == 0){  
            jout('error', 'This is the last Administrator — assign another admin first.');  
        } 
         
        $del = mysqli_query($commondb, "DELETE FROM user_system_access_table WHERE username='$unameE' AND system_id=9"); 
        if(!$del){  
            jout('error', 'Failed to revoke access: '.mysqli_error($commondb));  
        } 

        mpu_audit($auditConn, $uname, 'REVOKE_ACCESS', array(), $me, $now); 
        jout('success', 'MPQMS access revoked for '.$uname.'. The portal account itself is not deleted.'); 
    } 
    else if($action === 'resetpw'){ 
        $chkAcc = mysqli_query($commondb, "SELECT 1 FROM user_system_access_table WHERE username='$unameE' AND system_id=9"); 
        if(!$chkAcc || mysqli_num_rows($chkAcc) == 0){ 
            jout('error', 'Password reset is only allowed for users with MPQMS access.'); 
        } 
         
        // Administrator-entered manual password (server-side mirror of the client-side rules).
        $newPass = (string)($_POST['newPassword'] ?? $_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirmPassword'] ?? $_POST['confirm_password'] ?? '');
        if($newPass === ''){ jout('error', 'New password is required.'); }
        if($confirm !== '' && $newPass !== $confirm){ jout('error', 'New Password and Confirm Password do not match.'); }
        if(strlen($newPass) < 8){ jout('error', 'Password must be at least 8 characters long.'); }
        if(!preg_match('/[A-Z]/', $newPass)){ jout('error', 'Password must contain at least one uppercase letter.'); }
        if(!preg_match('/[0-9]/', $newPass)){ jout('error', 'Password must contain at least one number.'); }

        // Portal login uses md5 hashing (see login/authentications.php) — hash before storage.
        $hash = md5($newPass);
        $updP = mysqli_query($commondb, "UPDATE login_table SET password='$hash' WHERE username='$unameE'");
        if(!$updP){
            jout('error', 'Failed to reset password: '.mysqli_error($commondb));
        }

        mpu_audit($auditConn, $uname, 'RESET_PASSWORD', array('scope'=>'portal-wide','method'=>'manual'), $me, $now);
        jout('success', 'Password successfully reset for '.$uname.'.'); 
    } 

    jout('error', 'Unknown action requested.'); 

} catch (Throwable $e) { 
    jout('error', 'Server Exception: ' . $e->getMessage()); 
}