<?php
	require_once($_SERVER["DOCUMENT_ROOT"]."/COMMON/config/auth_users.php");
	require_once($_SERVER["DOCUMENT_ROOT"]."/COMMON/config/db_config.php");
	require_once($_SERVER["DOCUMENT_ROOT"]."/BDSIS/config/db_config.php");
	$user=$_SESSION["users"];$ctid=$_SESSION["ctid"];$acl=$_SESSION["acl"];$usercat=$_SESSION["usercat"];$unitacc=$_SESSION["unitacc"];
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0" />
	<title>Bhutan Diarrheal Study Information System(BDSIS)</title>
	<link href="/COMMON/css/styles.css" rel="stylesheet" type="text/css" />
	<script type="text/javascript" src="/COMMON/js/jquery-1.7.2.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/ui.spinner.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.mousewheel.js"></script>
	<script type="text/javascript" src="/COMMON/js/jquery-1.8.2.min.js"></script>
	<script>
		$(document).ready(function(){
			// Date fields are native <input type="date"> (always YYYY-MM-DD); no picker to init.
			$("#form").validationEngine({binded:false});
			$("#form1").validationEngine({binded:false});
			$("#form2").validationEngine({binded:false});
			$('#chksid').change(function(){checkSID();});
		});
		function checkSID(){  
			var sid = $('#chksid').val();  
			$.post("check_sid.php", { sid: sid },  
			function(result){  
				if(result == 1){  
					$('#sid_result').html('Available');
					 $('#sid').val(sid);	
				}else if(result == 2){  
					$('#sid_result').html('ID Length Mismatch');
					 $('#sid').val('');	
				}else{  
					$('#sid_result').html('Duplicate Case Subject ID'); 
					 $('#sid').val('');		
				}  
			});  
		}
	</script>
	<script>
		// $(document).ready(function(){
			// $(".datepicker").datepicker({changeMonth: true,changeYear: true,dateFormat: 'dd-mm-yy'});
			// $("#form").validationEngine({binded:false});
			// $("#form1").validationEngine({binded:false});
			// $("#form2").validationEngine({binded:false});
			// $("#form3").validationEngine({binded:false});
			// $("#form4").validationEngine({binded:false});
			// $("#form5").validationEngine({binded:false});
			// $("#form6").validationEngine({binded:false});
			// $("#form7").validationEngine({binded:false});
			// $("#form8").validationEngine({binded:false});
			// $("#form9").validationEngine({binded:false});
			// $("#form10").validationEngine({binded:false});
			// $("#form11").validationEngine({binded:false});
			// $("#form12").validationEngine({binded:false});
			// $("#form13").validationEngine({binded:false});
			// $("#form14").validationEngine({binded:false});
			// $("#form15").validationEngine({binded:false});
		// });
	</script>

	<script type="text/javascript" src="/COMMON/js/jquery-1.8.0.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.validationEngine-en.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.validationEngine.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/charts/excanvas.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/charts/jquery.flot.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/charts/jquery.flot.orderBars.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/charts/jquery.flot.pie.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/charts/jquery.flot.resize.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/charts/jquery.sparkline.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/tables/jquery.dataTables.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/tables/jquery.sortable.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/tables/jquery.resizable.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/forms/autogrowtextarea.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.uniform.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.inputlimiter.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.tagsinput.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.maskedinput.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.autotab.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.chosen.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.dualListBox.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.cleditor.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/forms/jquery.ibutton.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/uploader/plupload.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/uploader/plupload.html4.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/uploader/plupload.html5.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/uploader/jquery.plupload.queue.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/wizards/jquery.form.wizard.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/wizards/jquery.validate.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/wizards/jquery.form.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.collapsible.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.breadcrumbs.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.tipsy.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.progress.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.timeentry.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.colorpicker.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.jgrowl.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.fancybox.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.fileTree.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.sourcerer.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/others/jquery.fullcalendar.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/others/jquery.elfinder.js"></script>

	<script type="text/javascript" src="/COMMON/js/plugins/ui/jquery.easytabs.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/files/bootstrap.js"></script>
	<script type="text/javascript" src="/COMMON/js/files/functions.js"></script>

	<script type="text/javascript" src="/COMMON/js/charts/hBar_side.js"></script>
	<link rel="stylesheet" href="/COMMON/js/plugins/colorbox/colorbox.css" />
	<script type="text/javascript" src="/COMMON/js/plugins/colorbox/jquery-1.10.2.min.js"></script>
	<script type="text/javascript" src="/COMMON/js/plugins/colorbox/jquery.colorbox.js"></script>
	<script type="text/javascript">var cbox = $.noConflict(true);</script>
	<script>
		cbox(document).ready(function(){
			cbox(".viewprofile").colorbox({iframe:true, width:"600px", height:"470px" });
			cbox(".editprofile").colorbox({iframe:true, width:"600px", height:"550px" });
			cbox(".changepassword").colorbox({iframe:true, width:"600px", height:"350px" });
			cbox(".adduser").colorbox({iframe:true, width:"600px", height:"580px",onClosed:function(){ location.reload(true);} });
			cbox(".addcenter").colorbox({iframe:true, width:"600px", height:"500px",onClosed:function(){ location.reload(true);} });
			cbox(".confirmdelete").colorbox({iframe:true, width:"400px", height:"270px",onClosed:function(){ location.reload(true);} });
			
			$("#rcdtab").dataTable().fnDestroy();
			$("#rcdtab").dataTable( {
				"fnDrawCallback": function() {
				 cbox(".confirmdelete").colorbox({iframe:true, width:"400px", height:"270px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[3, "desc" ],[0,'desc']]
			});
			var rcdtab = $('#rcdtab').DataTable();
			$("#rcdtab tfoot th").each( function ( i ) {
				var select = $('<select><option value=""></option></select>')
					.appendTo( $(this).empty() )
					.on( 'change', function () {
						var val = $(this).val();

						rcdtab.column( i )
							.search( val ? '^'+$(this).val()+'$' : val, true, false )
							.draw();
					} );

					rcdtab.column( i ).data().unique().sort().each( function ( d, j ) {
					select.append( '<option value="'+d+'">'+d+'</option>' )
					} );
			} );
			
			$("#rcdtab1").dataTable().fnDestroy();
			$("#rcdtab1").dataTable( {
				"fnDrawCallback": function() {
				 cbox(".confirmdelete").colorbox({iframe:true, width:"400px", height:"270px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[1, "desc" ],[0,'desc']]
			});
			
			$("#shptab").dataTable().fnDestroy();
			$("#shptab").dataTable( {
				"fnDrawCallback": function() {
				 cbox(".confirmdelete").colorbox({iframe:true, width:"400px", height:"270px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[0, "desc" ]]
			});
			var shptab = $('#shptab').DataTable();
			$("#shptab tfoot th").each( function ( i ) {
				var select = $('<select><option value=""></option></select>')
					.appendTo( $(this).empty() )
					.on( 'change', function () {
						var val = $(this).val();

						shptab.column( i )
							.search( val ? '^'+$(this).val()+'$' : val, true, false )
							.draw();
					} );

					shptab.column( i ).data().unique().sort().each( function ( d, j ) {
					select.append( '<option value="'+d+'">'+d+'</option>' )
					} );
			} );

			$("#usertab").dataTable().fnDestroy();
			$("#usertab").dataTable( {
				"fnDrawCallback": function( oSettings ) {
					cbox(".editprofile").colorbox({iframe:true, width:"600px", height:"580px",onClosed:function(){ location.reload(true);} });
					cbox(".changestatus").colorbox({iframe:true, width:"550px", height:"370px",onClosed:function(){ location.reload(true);} });
					cbox(".resetpassword").colorbox({iframe:true, width:"550px", height:"270px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[0, "desc" ],[1,'desc']]
			});
			var usertab = $('#usertab').DataTable();
			$("#usertab tfoot th").each( function ( i ) {
				var select = $('<select><option value=""></option></select>')
					.appendTo( $(this).empty() )
					.on( 'change', function () {
						var val = $(this).val();

						usertab.column( i )
							.search( val ? '^'+$(this).val()+'$' : val, true, false )
							.draw();
					} );

					usertab.column( i ).data().unique().sort().each( function ( d, j ) {
					select.append( '<option value="'+d+'">'+d+'</option>' )
					} );
			} );
			
			$("#site").dataTable().fnDestroy();
			$("#site").dataTable( {
				"fnDrawCallback": function() {
				 cbox(".editcenter").colorbox({iframe:true, width:"550px", height:"450px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[0, "desc" ],[1,'desc']]
			});
			var site = $('#site').DataTable();
			$("#site tfoot th").each( function ( i ) {
				var select = $('<select><option value=""></option></select>')
					.appendTo( $(this).empty() )
					.on( 'change', function () {
						var val = $(this).val();

						site.column( i )
							.search( val ? '^'+$(this).val()+'$' : val, true, false )
							.draw();
					} );

					site.column( i ).data().unique().sort().each( function ( d, j ) {
					select.append( '<option value="'+d+'">'+d+'</option>' )
					} );
			} );
			
			$("#pathogen").dataTable().fnDestroy();
			$("#pathogen").dataTable( {
				"fnDrawCallback": function() {
				cbox(".edit").colorbox({iframe:true, width:"500px", height:"300px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[0, "asc" ]]
			});
			
			$("#parasite").dataTable().fnDestroy();
			$("#parasite").dataTable( {
				"fnDrawCallback": function() {
				 cbox(".edit").colorbox({iframe:true, width:"500px", height:"300px",onClosed:function(){ location.reload(true);} });
				},
				"sPaginationType": "full_numbers",
				"order": [[0, "asc" ]]
			});
		});
	</script>
</head>

<body>

<!-- Top line begins -->
<div id="top">
    <div class="wrapper">
        <span class="logo"><img height="33" src="/COMMON/images/bdsis_logo.png" alt="" /></span>
        <div class="clear"></div>
    </div>
</div>
<!-- Top line ends -->


<!-- Sidebar begins -->
<div id="sidebar">
    <div class="mainNav">
        <!-- Main nav -->
        <ul class="nav">
            <li><a href="/COMMONNEW/dashboard/dashboard.php" title="Dashboard"><img src="/COMMON/images/icons/mainnav/center.png" alt="" /><span>RCDC Home</span></a></li>
			<li><a href="/BDSIS/dashboard/dashboard.php" title="Dashboard"><img src="/COMMON/images/icons/mainnav/dashboard.png" alt="" /><span>Dashboard</span></a></li>
            <li><a href="/BDSIS/records/record_management.php" title="Patient Records"><img src="/COMMON/images/icons/mainnav/ui.png" alt="" /><span>Records</span></a></li>	
			<li><a href="/BDSIS/lab/record_management.php" title="Lab Records"><img src="/COMMON/images/icons/mainnav/lab.png" alt="" /><span>Lab</span></a></li>
			<li><a href="/BDSIS/reports/report_management.php" title="Reports"><img src="/COMMON/images/icons/mainnav/statistics.png" alt="" /><span>Reports</span></a></li>
			<li><a href="#" title="About System"><img src="/COMMON/images/icons/mainnav/info.png" alt="" /><span>About System</span></a></li>			
        </ul>
    </div>
</div>
<!-- Sidebar ends -->