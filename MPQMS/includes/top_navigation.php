<?php
	// Friendly identity for the navbar chip (display only).
	$mpUser = $_SESSION['users'] ?? '';
	$mpInitial = strtoupper(substr($mpUser, 0, 1));
	$mpAcl = (int)($_SESSION['acl'] ?? 0);
	if($mpAcl == 1){ $mpRole = 'Administrator'; }
	elseif($mpAcl == 2){ $mpRole = 'Laboratory'; }
	else{ $mpRole = 'User'; }
?>
  <!-- Top Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="../dashboard/dashboard.php" class="nav-link">Dashboard</a>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <li class="nav-item d-none d-md-flex align-items-center">
        <div class="nt-user-chip">
          <span class="nt-avatar"><?=$mpInitial;?></span>
          <span><?=htmlspecialchars($mpUser);?></span>
          <span class="nt-role"><?=$mpRole;?></span>
        </div>
      </li>
      <li class="nav-item">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button" title="Fullscreen">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->
