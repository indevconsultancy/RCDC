<!-- /.page header -->
<?php require_once("../includes/header.php"); ?>
<?php require_once("../includes/css-jquery.php"); ?>
<?php if(isset($_GET["msgLogin"])) $msgLogin = "<p class='login-box-msg text-danger'><i class='fas fa-exclamation-circle'></i> ".htmlspecialchars($_GET['msgLogin'])."</p>"; else $msgLogin = " <p class='login-box-msg text-muted'>Sign in to start your session</p>";?>

<body class="hold-transition login-page">
<div class="login-box">
  <div class="card">
    <div class="nt-login-brand">
      <img src="../dist/img/bdsis_logo.png" alt="BDSIS Logo">
      <span class="nt-login-title">BDSIS</span>
      <span class="nt-login-sub">Bhutan Diarrheal Study Information System</span>
    </div>
    <div class="card-body">
     <?=$msgLogin;?>
      <form id="form" autocomplete="off" method="Post" action="authentications.php">
        <div class="input-group mb-3">
          <input type="text" name="username" class="form-control" placeholder="Username" required>
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-user"></span>
            </div>
          </div>
        </div>
        <div class="input-group mb-3">
          <input type="password" name="password" class="form-control" placeholder="Password" required>
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-lock"></span>
            </div>
          </div>
        </div>
        <div class="row align-items-center">
          <div class="col-7">
            <p class="mb-0">
              <a href="forgot-password.php"><small>I forgot my password</small></a>
            </p>
          </div>
          <!-- /.col -->
          <div class="col-5">
            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-sign-in-alt"></i> Sign In</button>
          </div>
          <!-- /.col -->
        </div>
      </form>
    </div>
    <!-- /.login-card-body -->
  </div>
  <p class="text-center mt-3 mb-0" style="color:rgba(255,255,255,.65); font-size:.8rem;">
    Royal Centre for Disease Control &middot; Ministry of Health
  </p>
</div>
<!-- /.login-box -->

<script>
    $(function () {
			$('#form').validate({
				errorElement: 'span',
				errorPlacement: function (error, element) {
				  error.addClass('invalid-feedback');
				  element.closest('.form-group').append(error);
				},
				highlight: function (element, errorClass, validClass) {
				  $(element).addClass('is-invalid');
				},
				unhighlight: function (element, errorClass, validClass) {
				  $(element).removeClass('is-invalid');
				}
			 });
		});
</script>  
</body>
</html

