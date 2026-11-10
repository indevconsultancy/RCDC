<?php
	session_start();
	require_once("../config/db_config.php");
	require_once("reset_lib.php");
	date_default_timezone_set('Asia/Thimphu');

	$msg = ""; $msgType = "";

	if($_SERVER['REQUEST_METHOD'] === 'POST'){
		$identifier = trim($_POST['identifier'] ?? '');
		if($identifier === ''){
			$msg = "Please enter your username or registered email."; $msgType = "danger";
		} else {
			// Look up the account (username OR email). Prepared statement — no enumeration in the response.
			$stmt = $commondb->prepare(
				"SELECT lt.username, pt.email_id, lt.account_status
				 FROM login_table lt JOIN user_profile_table pt ON lt.username = pt.username
				 WHERE lt.username = ? OR pt.email_id = ? LIMIT 1");
			$stmt->bind_param('ss', $identifier, $identifier);
			$stmt->execute();
			$user = $stmt->get_result()->fetch_assoc();
			$stmt->close();

			if($user && (int)$user['account_status'] === 1 && !empty($user['email_id'])){
				$username = $user['username'];

				// Simple rate limit: don't issue a new token if one was created < 60s ago.
				$recent = false;
				$rs = $connection->prepare("SELECT created_at FROM password_reset_table WHERE username = ? ORDER BY id DESC LIMIT 1");
				$rs->bind_param('s', $username); $rs->execute();
				$last = $rs->get_result()->fetch_assoc(); $rs->close();
				if($last && strtotime($last['created_at']) > (time() - 60)){ $recent = true; }

				if(!$recent){
					// Invalidate any outstanding tokens, then issue a fresh single-use token.
					$inv = $connection->prepare("UPDATE password_reset_table SET used = 1 WHERE username = ? AND used = 0");
					$inv->bind_param('s', $username); $inv->execute(); $inv->close();

					$token   = bin2hex(random_bytes(32));            // 64 hex chars, sent to the user
					$hash    = hash('sha256', $token);               // only the hash is stored
					$expires = date('Y-m-d H:i:s', time() + 1800);   // 30-minute expiry
					$created = date('Y-m-d H:i:s');

					$ins = $connection->prepare(
						"INSERT INTO password_reset_table (username, token_hash, expires_at, used, created_at) VALUES (?,?,?,0,?)");
					$ins->bind_param('ssss', $username, $hash, $expires, $created);
					$ins->execute(); $ins->close();

					$link = mpqms_base_url()."/MPQMS/login/reset_password.php?token=".$token;
					mpqms_send_reset_email($user['email_id'], $username, $link);
				}
			}

			// Always the same generic response — never reveals whether an account exists.
			$msg = "If an account matches, a password reset link has been sent to the registered email address. "
				 . "The link expires in 30 minutes. Please also check your spam folder.";
			$msgType = "success";
		}
	}
?>
<?php require_once("../includes/header.php"); ?>
<?php require_once("../includes/css-jquery.php"); ?>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="card">
    <div class="nt-login-brand">
      <img src="../dist/img/NDTLIS_LOGO.png" alt="MPQMS Logo">
      <span class="nt-login-title">MPQMS</span>
      <span class="nt-login-sub">Reset your password</span>
    </div>
    <div class="card-body">
      <?php if($msg !== ''){ ?>
        <div class="alert alert-<?=$msgType==='success'?'success':'danger';?>"><?=htmlspecialchars($msg);?></div>
      <?php } ?>
      <?php if($msgType !== 'success'){ ?>
      <p class="login-box-msg text-muted">Enter your username or registered email and we'll send you a secure link to reset your password.</p>
      <form id="form" autocomplete="off" method="Post" action="forgot_password.php">
        <div class="input-group mb-3">
          <input type="text" name="identifier" class="form-control" placeholder="Username or Email" required>
          <div class="input-group-append">
            <div class="input-group-text"><span class="fas fa-user"></span></div>
          </div>
        </div>
        <div class="row align-items-center">
          <div class="col-6">
            <p class="mb-0"><a href="login.php"><small><i class="fas fa-arrow-left"></i> Back to Sign In</small></a></p>
          </div>
          <div class="col-6">
            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
          </div>
        </div>
      </form>
      <?php } else { ?>
        <p class="text-center mb-0"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to Sign In</a></p>
      <?php } ?>
    </div>
  </div>
</div>
<script>
  $(function () {
    $('#form').validate({
      errorElement: 'span',
      errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.form-group').append(error); },
      highlight: function (element) { $(element).addClass('is-invalid'); },
      unhighlight: function (element) { $(element).removeClass('is-invalid'); }
    });
  });
</script>
</body>
</html>
