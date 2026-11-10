      <!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
  </aside>
  <!-- /.control-sidebar -->
  
    <!-- Main Footer -->
    <footer class="main-footer">
        <strong>Bhutan Diarrheal Study Information System(BDSIS) | 2012-<?php echo date('Y');?> | Developed by ICT Unit, RCDC</a></strong>
        <div class="float-right d-none d-sm-inline-block">
        <b>Version</b> 2.0.0
        </div>
    </footer>
    </div>
    <!-- ./wrapper -->

    <!-- Date fields use the native HTML date input (type="date").
         The jQuery UI picker that used to be initialised here rendered dd-mm-yyyy,
         but the save handlers passed that string straight into MySQL DATE columns,
         which stored 0000-00-00. A native date input always submits YYYY-MM-DD, so
         the displayed value and the stored value can no longer disagree. It also
         brings the browser/OS calendar, keyboard entry and built-in validation for
         free, and works on mobile. Nothing to initialise. -->

    <!-- Seamless CRUD submit.
         The record and lab forms post to processing pages (iu-case.php, iu-lab.php,
         verify.php). Historically the browser navigated to that page, which drew a
         blank screen with a SweetAlert the user had to Close before being sent back.
         bdsisCrudSubmit posts the same form in the background instead: the server
         returns the SAME success/error text as JSON (a non-AJAX request still gets
         the old popup-and-redirect page, so nothing about the save, its validation
         or its permissions changes), the text is shown as a toast, and on success
         the browser goes exactly where the server asked -- the next wizard step, or
         a refresh of the lab screen so the saved rows and their record ids reappear.
         On error it stays put so the entered data is not lost. It is the
         submitHandler of every validated CRUD form (so validation still gates the
         save) and is bound directly to the verify/return forms, which have none. -->
    <script>
    (function () {
        function crudToast(icon, text) {
            if (window.Swal) {
                Swal.fire({ toast: true, position: 'top-end', icon: icon,
                            title: (text || '').trim(), showConfirmButton: false,
                            timer: 2200, timerProgressBar: true });
            }
        }
        window.bdsisCrudSubmit = function (form) {
            var $f = window.jQuery ? jQuery(form) : null;
            if (!$f) { form.submit(); return true; }   /* no jQuery: fall back to the old flow */
            jQuery.ajax({
                url: form.getAttribute('action'),
                type: (form.getAttribute('method') || 'post'),
                data: $f.serialize(),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).done(function (r) {
                crudToast(r.ok ? 'success' : 'error', r.msg);
                var message = (r && r.msg) ? r.msg : (r && r.ok ? 'Submitted Successfully!!' : 'Submission Failed!!');
                crudToast(r.ok ? 'success' : 'error', message);
                if (r.ok && r.lnk) { setTimeout(function () { window.location = r.lnk; }, 650); }
                /* on error: stay on the form, keeping every entered value */
            }).fail(function () {
                crudToast('error', 'Submission Failed!! Please try again.');
            }).fail(function (jqXHR, textStatus, errorThrown) {
                var errorDetail = '';
                if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.msg) {
                    errorDetail = jqXHR.responseJSON.msg;
                } else if (jqXHR && jqXHR.responseText) {
                    try {
                        var parsed = JSON.parse(jqXHR.responseText);
                        if (parsed && parsed.msg) { errorDetail = parsed.msg; }
                    } catch (e) {
                        if (jqXHR.status === 500) {
                            errorDetail = 'Database error: unable to update sample records (' + (errorThrown || 'Server 500') + ')';
                        } else if (jqXHR.status === 400 || jqXHR.status === 422) {
                            errorDetail = 'Validation error: required field missing or invalid entry';
                        } else if (jqXHR.status === 401 || jqXHR.status === 403) {
                            errorDetail = 'Permission denied: unauthorized operation';
                        } else if (jqXHR.status === 0) {
                            errorDetail = 'Network error: could not connect to server';
                        } else {
                            errorDetail = 'HTTP ' + jqXHR.status + ' (' + (errorThrown || 'Request error') + ')';
                        }
                    }
                }
                var finalMsg = errorDetail ? ('Submission Failed!! ' + errorDetail) : 'Submission Failed!! Please try again.';
                crudToast('error', finalMsg);
            });
            return false;   /* handled in place; never open the processing page */
        };
        if (window.jQuery) {
            jQuery(function () {
                /* verify / return forms carry no jquery-validation, so bind them here */
                jQuery(document).on('submit', 'form[action$="verify.php"]', function (e) {
                    e.preventDefault();
                    return bdsisCrudSubmit(this);
                });
            });
        }
    })();
    </script>
    </body>
</html>