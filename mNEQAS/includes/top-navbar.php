 <!-- Check session -->
 <?php require_once("../config/session.php"); ?>
 <?php
    /* -----------------------------------------------------------------
       Logged-in user context for the top navbar. Purely native to mNEQAS
       — no external/FoodSIMS helpers (e.g. bdsis_role_label) are used.

       Sources (set at login in login/authentications.php):
         $_SESSION['userID']  -> login username (string)
         $_SESSION['roleID']  -> numeric access_level

       Everything is guarded with isset()/casts so a missing session
       variable can never raise a fatal error or call an undefined function.
       ----------------------------------------------------------------- */
    $navUser = (isset($_SESSION['userID']) && $_SESSION['userID'] !== '')
             ? (string)$_SESSION['userID']
             : 'Guest';

    // Local numeric-role -> label map (no DB call, no external helper).
    // Unknown or missing roles simply show no label (username only).
    $navRoleMap = array(
        1 => 'Administrator',
        2 => 'RCDC Lab',
        3 => 'Health Worker',
        4 => 'District Health Office',
        5 => 'Ministry of Health',
        6 => 'BAFRA',
        7 => 'Drug Regulatory Authority',
        99 => 'Others',
    );
    $navRoleId = isset($_SESSION['roleID']) ? (int)$_SESSION['roleID'] : 0;
    $navRole   = isset($navRoleMap[$navRoleId]) ? $navRoleMap[$navRoleId] : '';

    // Default avatar (used when the user has no profile image). Absolute path
    // so it resolves from any module subdirectory that includes this navbar.
    $navAvatar = '/MNEQAS/dist/img/User.png';
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
      <li class="nav-item">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>
      <!-- Logged-in user (avatar + username + optional role) -->
      <li class="nav-item d-flex align-items-center px-2">
        <img src="<?= htmlspecialchars($navAvatar, ENT_QUOTES); ?>" alt="User avatar"
             class="img-circle elevation-1"
             style="width:34px; height:34px; object-fit:cover;">
        <span class="d-none d-sm-flex flex-column ml-2" style="line-height:1.15;">
          <span class="font-weight-bold text-dark"><?= htmlspecialchars($navUser, ENT_QUOTES); ?></span>
          <?php if ($navRole !== '') { ?>
          <small class="text-muted"><?= htmlspecialchars($navRole, ENT_QUOTES); ?></small>
          <?php } ?>
        </span>
      </li>
    </ul>
    </nav>
  <!-- /.navbar -->