<!-- /.page header -->
<?php require_once("../includes/header.php"); ?>
<?php require_once("../includes/css-jquery.php"); ?>

<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <a href="#"><b>Lhak-sam</b></a>
  </div>
  <!-- /.login-logo -->
  <div class="card">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Your new password will be sent to the registered email.</p>

      <form id="form" action="" autocomplete="off" method="post">
        <div class="input-group mb-3">
          <input type="email" name="email" class="form-control" placeholder="Email" required>
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-envelope"></span>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-block">Request new password</button>
          </div>
          <!-- /.col -->
        </div>
      </form>

      <p class="mt-3 mb-1">
        <a href="login.php">Login</a>
      </p>
    </div>
    <!-- /.login-card-body -->
  </div>
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

<!-- /.page footer -->
<!-- Close with the login-page's own tags, exactly as login.php does. The shared
     includes/footer.php closes an AdminLTE .wrapper and prints the application
     footer, neither of which the login screen has -- pulling it here (with the
     wrong relative path) is what crashed the page. -->
</body>
</html>
