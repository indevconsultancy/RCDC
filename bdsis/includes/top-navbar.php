 <!-- Check session -->
 <?php require_once("../config/session.php"); ?>
 <?php require_once(__DIR__ . "/rbac.php"); ?>
 <?php
	// Friendly identity for the navbar chip.
	// The role label comes from the DB-resolved BDSIS role (bdsis_role_label),
	// the same source the permissions use -- so the chip always matches what the
	// user may actually do. It used to be derived from $_SESSION['acl'] and
	// mapped only three role pairs, showing a generic "User" for BFDA, DHO, MoH,
	// RCDC FNL, clinicians and nurses even though their permissions were correct.
	$ntUser = $_SESSION['users'] ?? '';
	$ntInitial = strtoupper(substr($ntUser, 0, 1));
	$ntRole = bdsis_role_label();
 ?>
 <!-- Navbar -->
 <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
     <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <li class="nav-item d-none d-md-flex align-items-center">
        <div class="nt-user-chip">
          <span class="nt-avatar"><?=htmlspecialchars($ntInitial);?></span>
          <span><?=htmlspecialchars($ntUser);?></span>
          <span class="nt-role"><?=$ntRole;?></span>
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
