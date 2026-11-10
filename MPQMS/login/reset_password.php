<?php
	session_start();
	require_once("../config/db_config.php");
	date_default_timezone_set('Asia/Thimphu');

	$token   = $_REQUEST['token'] ?? '';
	$valid   = false; $username = '';
	$stage   = 'form';          // form | invalid | done
	$msg     = ""; $msgType = "";

	// A valid token is 64 hex chars; look up its hash and check single-use + expiry.
	if($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)){
		$hash = hash('sha256', $token);
		$stmt = $connection->prepare("SELECT username, expires_at, used FROM password_reset_table WHERE token_hash = ? ORDER BY id DESC LIMIT 1");
		$stmt->bind_param('s', $hash); $stmt->execute();
		$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
		if($row && (int)$row['used'] === 0 && strtotime($row['expires_at']) > time()){
			$valid = true; $username = $row['username'];
		}
	}

	if(!$valid){
		$stage = 'invalid';
	} else if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newpass'])){
		// CSRF check
		if(empty($_POST['csrf']) || empty($_SESSION['reset_csrf']) || !hash_equals($_SESSION['reset_csrf'], $_POST['csrf'])){
			$msg = "Your session expired. Please submit the form again."; $msgType = "danger";
		} else {
			$p1 = (string)($_POST['newpass'] ?? '');
			$p2 = (string)($_POST['confirmpass'] ?? '');
			if(strlen($p1) < 8){
				$msg = "Password must be at least 8 characters long."; $msgType = "danger";
			} else if(!preg_match('/[A-Za-z]/', $p1) || !preg_match('/[0-9]/', $p1)){
				$msg = "Password must contain at least one letter and one number."; $msgType = "danger";
			} else if($p1 !== $p2){
				$msg = "The two passwords do not match."; $msgType = "danger";
			} else {
				// Update the (portal-shared) credential using the existing MD5 scheme for login compatibility.
				$md5 = md5($p1);
				$upd = $commondb->prepare("UPDATE login_table SET password = ? WHERE username = ?");
				$upd->bind_param('ss', $md5, $username); $upd->execute(); $upd->close();
				// Burn every reset token for this user.
				$b = $connection->prepare("UPDATE password_reset_table SET used = 1 WHERE username = ?");
				$b->bind_param('s', $username); $b->execute(); $b->close();
				unset($_SESSION['reset_csrf']);
				$stage = 'done';
			}
		}
	}

	if($stage === 'form'){ $_SESSION['reset_csrf'] = bin2hex(random_bytes(16)); }
?>
<?php require_once("../includes/header.php"); ?>
<?php require_once("../includes/css-jquery.php"); ?>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="card">
    <div class="nt-login-brand">
      <img src="../dist/img/NDTLIS_LOGO.png" alt="MPQMS Logo">
      <span class="nt-login-title">MPQMS</span>
      <span class="nt-login-sub">Set a new password</span>
    </div>
    <div class="card-body">
    <?php if($stage === 'invalid'){ ?>
      <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> This password reset link is invalid or has expired.</div>
      <p class="text-center mb-0"><a href="forgot_password.php"><i class="fas fa-redo"></i> Request a new reset link</a></p>
    <?php } else if($stage === 'done'){ ?>
      <div class="alert alert-success"><i class="fas fa-check-circle"></i> Your password has been reset successfully. You can now sign in with your new password.</div>
      <p class="text-center mb-0"><a href="login.php" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Go to Sign In</a></p>
    <?php } else { ?>
      <?php if($msg !== ''){ ?><div class="alert alert-<?=$msgType==='success'?'success':'danger';?>"><?=htmlspecialchars($msg);?></div><?php } ?>
      <p class="login-box-msg text-muted">Choose a new password for your account. Use at least 8 characters including a letter and a number.</p>
      <form id="form" autocomplete="off" method="Post" action="reset_password.php">
        <input type="hidden" name="token" value="<?=htmlspecialchars($token);?>">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['reset_csrf']);?>">
        <div class="input-group mb-3">
          <input type="password" name="newpass" id="newpass" class="form-control" placeholder="New Password" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
        </div>
        <div class="input-group mb-3">
          <input type="password" name="confirmpass" id="confirmpass" class="form-control" placeholder="Confirm New Password" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
        </div>
        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-key"></i> Reset Password</button>
      </form>
    <?php } ?>
    </div>
  </div>
</div>
<script>
  $(function () {
    $('#form').validate({
      rules: {
        newpass: { required: true, minlength: 8 },
        confirmpass: { required: true, equalTo: '#newpass' }
      },
      messages: {
        confirmpass: { equalTo: 'Passwords do not match.' }
      },
      errorElement: 'span',
      errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.input-group').after(error); },
      highlight: function (element) { $(element).addClass('is-invalid'); },
      unhighlight: function (element) { $(element).removeClass('is-invalid'); }
    });
  });
</script>
</body>
</html>
