<!-- Auth --> 
<?php include_once("../config/auth_ndtlis.php"); ?> 
<?php if(!isset($_SESSION['acl']) || (int)$_SESSION['acl'] !== 1){ header("Location: ../dashboard/dashboard.php"); exit; } ?> 
<?php include_once("../includes/header.php"); ?> 
    <!-- DB --><?php include_once("../config/db_config.php"); ?> 
    <!-- CSS --><?php include_once("../includes/css-jquery.php"); ?> 
    <body class="hold-transition sidebar-mini layout-fixed"> 
        <div class="wrapper"> 
            <?php include_once("../includes/top_navigation.php"); ?> 
            <?php include_once("../includes/main_navigation.php"); ?> 
            <div class="content-wrapper"> 
                <div class="content-header"> 
                  <div class="container-fluid"> 
                    <div class="row mb-2"> 
                      <div class="col-sm-6"><h1 class="m-0">User Management</h1></div> 
                      <div class="col-sm-6"><ol class="breadcrumb float-sm-right"> 
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li> 
                        <li class="breadcrumb-item active">Users</li> 
                      </ol></div> 
                    </div> 
                  </div> 
                </div> 
                <section class="content"> 
                  <div class="container-fluid"> 
                    <div class="card card-outline card-primary"> 
                        <div class="card-header"> 
                            <h3 class="card-title"><i class="fas fa-users text-muted"></i> &nbsp;<b>Users with MPQMS Access</b></h3> 
                            <div class="card-tools"> 
                                <button id="addUserBtn" class="btn btn-sm bg-gradient-success"><i class="fas fa-user-plus"></i>&nbsp;Add MPQMS Access</button> 
                            </div> 
                        </div> 
                        <div class="card-body"> 
                            <table id="usersTable" class="table table-hover table-striped" style="width:100%"> 
                                <thead><tr><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th class="text-right">Actions</th></tr></thead> 
                                <tbody> 
                                <?php 
                                    function mpu_role_label($acl,$uc){
                                        if($acl==1 && $uc==20) return 'MPD';
                                        if($acl==1) return 'Administrator';
                                        if($acl==2 && $uc==18) return 'NMPTL';
                                        if($acl==2 && $uc==19) return 'NMRL';
                                        if($acl==7) return 'BFDA';
                                        return 'ACL '.$acl;
                                    }
                                    $q = mysqli_query($commondb,"SELECT a.username,p.fullname,p.email_id,a.access_level,a.user_category FROM user_system_access_table a JOIN user_profile_table p ON a.username=p.username WHERE a.system_id=9 ORDER BY a.access_level,a.username"); 
                                    while($row = mysqli_fetch_assoc($q)){

    $u   = htmlspecialchars($row['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $fn  = htmlspecialchars($row['fullname'] ?? '', ENT_QUOTES, 'UTF-8');
    $em  = htmlspecialchars($row['email_id'] ?? '', ENT_QUOTES, 'UTF-8');

    $acl = (int)$row['access_level'];
    $uc  = (int)$row['user_category'];

    $label = htmlspecialchars(
        mpu_role_label($acl, $uc),
        ENT_QUOTES,
        'UTF-8'
    );

    echo "<tr>
        <td>$u</td>
        <td>$fn</td>
        <td>$em</td>
        <td><span class='badge badge-info'>$label</span></td>
        <td class='text-right'>

            <button
                class='btn btn-xs btn-outline-primary mpu-edit mp-uaction'
                data-user=\"$u\"
                data-fullname=\"$fn\"
                data-email=\"$em\"
                data-acl=\"$acl\"
                data-uc=\"$uc\"
                title='Edit User'>
                <i class='fas fa-user-edit'></i>
            </button>

            <button
                class='btn btn-xs btn-outline-secondary mpu-reset mp-uaction'
                data-user=\"$u\"
                title='Reset Password'>
                <i class='fas fa-key'></i>
            </button>

            <button
                class='btn btn-xs btn-outline-danger mpu-revoke mp-uaction'
                data-user=\"$u\"
                title='Revoke MPQMS Access'>
                <i class='fas fa-ban'></i>
            </button>

        </td>
    </tr>";
} 
                                ?> 
                                </tbody> 
                            </table> 
                        </div> 
                    </div> 
                  </div> 
                </section> 
            </div> 
            <?php include_once("../includes/footer.php"); ?> 
        </div> 
 
        <!-- Add / Edit Role modal --> 
        <div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-hidden="true"> 
          <div class="modal-dialog" role="document"><div class="modal-content"> 
            <form id="userForm"> 
              <div class="modal-header"><h5 class="modal-title" id="userModalTitle">Add MPQMS Access</h5> 
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div> 
              <div class="modal-body"> 
                <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>"> 
                <div class="form-group"> 
                    <label for="mpuFullname">Full Name</label> 
                    <input type="text" class="form-control" id="mpuFullname" name="fullname" placeholder="Enter Full Name" autocomplete="off"> 
                </div> 
                <div class="form-group"> 
                    <label for="mpuEmail">Email Address</label> 
                    <input type="email" class="form-control" id="mpuEmail" name="email" placeholder="Enter Email" autocomplete="off"> 
                </div> 
                <div class="form-group"> 
                    <label for="mpuUsername">Portal Username</label> 
                    <input type="text" class="form-control" id="mpuUsername" name="username" required autocomplete="off"> 
                </div> 
                <div class="form-group mpu-pass-field"> 
                    <label for="mpuPassword">Password</label> 
                    <input type="password" class="form-control" id="mpuPassword" name="password" placeholder="Enter Initial Password" autocomplete="new-password"> 
                </div> 
                <div class="form-group"> 
                    <label for="mpuRole">Role</label> 
                    <select class="form-control" id="mpuRole" name="role" required>
                        <option value="">Select role</option>
                        <option value="1:1">Administrator</option>
                        <option value="1:20">MPD (Medicinal Products Division)</option>
                        <option value="2:18">NMPTL</option>
                        <option value="2:19">NMRL</option>
                        <option value="7:99">BFDA</option>
                    </select>
                </div> 
              </div> 
              <div class="modal-footer"> 
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button> 
                <button type="submit" class="btn bg-gradient-success mpu-submit">Save</button> 
              </div> 
            </form> 
          </div></div> 
        </div> 
 
        <!-- Reset Password modal (manual admin-set password) -->
        <div class="modal fade" id="resetPwModal" tabindex="-1" role="dialog" aria-hidden="true">
          <div class="modal-dialog" role="document"><div class="modal-content">
            <form id="resetPwForm" autocomplete="off">
              <div class="modal-header"><h5 class="modal-title" id="resetPwTitle">Reset Password</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
              <div class="modal-body">
                <input type="hidden" id="rpUsername">
                <div class="form-group">
                    <label for="rpNew">New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="rpNew" autocomplete="new-password" placeholder="Enter new password">
                        <div class="input-group-append"><button type="button" class="btn btn-outline-secondary rp-toggle" data-target="#rpNew" tabindex="-1" title="Show / hide"><i class="fas fa-eye"></i></button></div>
                    </div>
                    <small class="form-text text-muted">Minimum 8 characters, including an uppercase letter and a number.</small>
                    <span class="text-danger small rp-err-new" style="display:none;"></span>
                </div>
                <div class="form-group">
                    <label for="rpConfirm">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="rpConfirm" autocomplete="new-password" placeholder="Re-enter new password">
                        <div class="input-group-append"><button type="button" class="btn btn-outline-secondary rp-toggle" data-target="#rpConfirm" tabindex="-1" title="Show / hide"><i class="fas fa-eye"></i></button></div>
                    </div>
                    <span class="text-danger small rp-err-confirm" style="display:none;"></span>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn bg-gradient-primary rp-submit" disabled><i class="fas fa-key"></i> Reset Password</button>
              </div>
            </form>
          </div></div>
        </div>

        <script> 
$(function(){ 
    var dt = $('#usersTable').DataTable({ order:[[3,'asc'],[0,'asc']], pageLength:25 }); 
    var mode = 'grant'; 
 
    function mpuReloadTable(){ 
        var search = dt.search(); 
        var pageIdx = dt.page(); 
        $.get(window.location.href, function(html){ 
            var fresh; 
            try { fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('#usersTable tbody'); } catch(e){ fresh = null; } 
            if(!fresh){ return; } 
            dt.destroy(); 
            document.querySelector('#usersTable tbody').innerHTML = fresh.innerHTML; 
            dt = $('#usersTable').DataTable({ order:[[3,'asc'],[0,'asc']], pageLength:25 }); 
            dt.search(search).draw(false); 
            if(pageIdx < dt.page.info().pages){ dt.page(pageIdx).draw(false); } 
        }); 
    } 
 
    $('#addUserBtn').on('click', function(){ 
        mode = 'grant'; 
        $('#userModalTitle').text('Add MPQMS Access / Create User'); 
        $('#mpuUsername').val('').prop('readonly', false); 
        $('#mpuFullname').val(''); 
        $('#mpuEmail').val(''); 
        $('#mpuPassword').val(''); 
        $('.mpu-pass-field').show(); 
        $('#mpuRole option[value="2:19"]').prop('disabled', true).prop('hidden', true);   // NMRL: not assignable to NEW users
        $('#mpuRole').val(''); 
        $('#userModal').modal('show'); 
    }); 
 
    $('#usersTable').on('click', '.mpu-edit', function(){  
    mode = 'role';

    var $btn = $(this);

    var u  = $btn.data('user') || '';
    var fn = $btn.data('fullname') || '';
    var em = $btn.data('email') || '';
    var acl = $btn.data('acl');
    var uc  = $btn.data('uc');

    $('#userModalTitle').text('Edit User — ' + u);

    // Populate existing user information
    $('#mpuUsername')
        .val(u)
        .prop('readonly', true);

    $('#mpuFullname')
        .val(fn);

    $('#mpuEmail')
        .val(em);

    // Password is not editable from this form
    $('.mpu-pass-field').hide();

    // Populate existing role (Edit keeps every role, including NMRL, for existing users)
    $('#mpuRole option[value="2:19"]').prop('disabled', false).prop('hidden', false);
    $('#mpuRole')
        .val(acl + ':' + uc);

    $('#userModal').modal('show');
}); 
 
    // Inline field validation (single source of truth — red border + helper text, no popup).
    function uFieldErr($f, msg){ $f.addClass('is-invalid'); var $g=$f.closest('.form-group'); $g.find('.invalid-feedback').remove(); $g.append('<span class="invalid-feedback">'+msg+'</span>'); }
    function uFieldOk($f){ $f.removeClass('is-invalid'); $f.closest('.form-group').find('.invalid-feedback').remove(); }
    $('#mpuUsername').on('input', function(){ if($.trim(this.value)!==''){ uFieldOk($(this)); } });
    $('#mpuRole').on('change', function(){ if((($(this).val()||'').split(':')).length===2){ uFieldOk($(this)); } });
    $('#userModal').on('show.bs.modal', function(){ $('#userForm .is-invalid').removeClass('is-invalid'); $('#userForm .invalid-feedback').remove(); });

    $('#userForm').on('submit', function(e){
        e.preventDefault();
        var username = $.trim($('#mpuUsername').val());
        var role = ($('#mpuRole').val() || '').split(':');
        var ok = true;
        if(!username){ uFieldErr($('#mpuUsername'), 'Please enter a username.'); ok = false; } else { uFieldOk($('#mpuUsername')); }
        if(role.length !== 2){ uFieldErr($('#mpuRole'), 'Please select a role.'); ok = false; }
        else if(mode === 'grant' && $('#mpuRole').val() === '2:19'){ uFieldErr($('#mpuRole'), 'NMRL cannot be assigned to a new user. Please choose another role.'); ok = false; }
        else { uFieldOk($('#mpuRole')); }
        if(!ok){ return; }

        $('.mpu-submit').prop('disabled', true); 
 
        var postData = { 
            action: mode, 
            username: username, 
            fullname: $.trim($('#mpuFullname').val()), 
            email: $.trim($('#mpuEmail').val()), 
            password: $('#mpuPassword').val(), 
            access_level: role[0], 
            user_category: role[1], 
            csrf_token: $('#csrf_token').val() 
        }; 
 
        $.ajax({ 
            url: 'user_action.php', 
            type: 'POST', 
            data: postData, 
            dataType: 'json', 
            success: function(res){ 
                $('.mpu-submit').prop('disabled', false); 
                if(res && res.status === 'success'){ 
                    $('#userModal').modal('hide'); 
                    mpuReloadTable(); 
                    Swal.fire({icon:'success', text:res.message, timer:1600, showConfirmButton:false}); 
                } else { 
                    Swal.fire({icon:'error', text:(res && res.message) || 'Action failed.'}); 
                } 
            }, 
            error: function(xhr, status, error){ 
                $('.mpu-submit').prop('disabled', false); 
                var responseMsg = 'Invalid response format from server.'; 
                 
                if (xhr.responseJSON && xhr.responseJSON.message) { 
                    responseMsg = xhr.responseJSON.message; 
                } else if (xhr.responseText) { 
                    console.error('Server Raw Response:', xhr.responseText); 
                    try { 
                        var parsed = JSON.parse(xhr.responseText); 
                        if(parsed.message) responseMsg = parsed.message; 
                    } catch(e) { 
                        responseMsg = 'Server Error (500). Please check web server logs.'; 
                    } 
                } 
                Swal.fire({icon:'error', text: responseMsg}); 
            } 
        }); 
    }); 
 
    $('#usersTable').on('click', '.mpu-reset', function(){
        var u = $(this).data('user');
        $('#rpUsername').val(u);
        $('#resetPwTitle').text('Reset Password for ' + u);
        $('#rpNew, #rpConfirm').val('').removeClass('is-invalid').attr('type','password');
        $('.rp-err-new, .rp-err-confirm').text('').hide();
        $('.rp-toggle i').removeClass('fa-eye-slash').addClass('fa-eye');
        $('.rp-submit').prop('disabled', true).html('<i class="fas fa-key"></i> Reset Password');
        $('#resetPwModal').modal('show');
        setTimeout(function(){ $('#rpNew').trigger('focus'); }, 450);
    });

    // Real-time validation for the manual reset-password form (both fields filled, match, complexity).
    function rpValidate(){
        var np = $('#rpNew').val() || '', cp = $('#rpConfirm').val() || '';
        var eN = '', eC = '';
        if(np === '') eN = 'New password is required.';
        else if(np.length < 8) eN = 'At least 8 characters.';
        else if(!/[A-Z]/.test(np)) eN = 'Include at least one uppercase letter.';
        else if(!/[0-9]/.test(np)) eN = 'Include at least one number.';
        if(cp === '') eC = 'Please confirm the password.';
        else if(np !== cp) eC = 'Passwords do not match.';
        $('#rpNew').toggleClass('is-invalid', !!eN);
        $('.rp-err-new').text(eN).toggle(!!eN);
        $('#rpConfirm').toggleClass('is-invalid', !!eC);
        $('.rp-err-confirm').text(eC).toggle(!!eC);
        var ok = !eN && !eC;
        $('.rp-submit').prop('disabled', !ok);
        return ok;
    }
    $('#resetPwForm').on('input', '#rpNew, #rpConfirm', rpValidate);

    // Eye icon — toggle password visibility.
    $('#resetPwModal').on('click', '.rp-toggle', function(){
        var $inp = $($(this).data('target'));
        var masked = $inp.attr('type') === 'password';
        $inp.attr('type', masked ? 'text' : 'password');
        $(this).find('i').toggleClass('fa-eye', !masked).toggleClass('fa-eye-slash', masked);
    });

    // Submit the admin-entered manual password.
    $('#resetPwForm').on('submit', function(e){
        e.preventDefault();
        if(!rpValidate()) return;
        var u = $('#rpUsername').val();
        var $btn = $('.rp-submit');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Resetting\u2026');
        $.post('user_action.php', { action:'resetpw', username:u, newPassword:$('#rpNew').val(), confirmPassword:$('#rpConfirm').val(), csrf_token:$('#csrf_token').val() }, function(res){
            if(res && res.status === 'success'){
                $('#resetPwModal').modal('hide');
                $('#rpNew, #rpConfirm').val('');
                Swal.fire({icon:'success', text: res.message || ('Password successfully reset for ' + u), timer:2200, showConfirmButton:false});
            } else {
                $btn.prop('disabled', false).html('<i class="fas fa-key"></i> Reset Password');
                Swal.fire({icon:'error', text:(res && res.message) || 'Failed to reset password.'});
            }
        }, 'json').fail(function(xhr){
            $btn.prop('disabled', false).html('<i class="fas fa-key"></i> Reset Password');
            var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Request failed.';
            Swal.fire({icon:'error', text:msg});
        });
    });

    $('#usersTable').on('click', '.mpu-revoke', function(){ 
        var u=$(this).data('user'); 
        Swal.fire({ title:'Revoke MPQMS access for '+u+'?', html:'The portal account is <b>not</b> deleted — only MPQMS access is removed.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Revoke' }) 
        .then(function(r){ if(!r.isConfirmed) return; 
            $.post('user_action.php', { action:'revoke', username:u, csrf_token:$('#csrf_token').val() }, function(res){ 
                if(res && res.status==='success'){ mpuReloadTable(); Swal.fire({icon:'success',text:res.message,timer:1800,showConfirmButton:false}); } 
                else { Swal.fire({icon:'error',text:(res&&res.message)||'Failed.'}); } 
            },'json').fail(function(xhr){  
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Request failed.'; 
                Swal.fire({icon:'error',text:msg});  
            }); 
        }); 
    }); 
}); 
</script> 
    </body> 
</html>