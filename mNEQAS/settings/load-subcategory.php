<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php"); 
    require_once("../includes/css-jquery.php");
	if(isset($_GET["category_id"]) AND $_GET["category_id"]<>""){
		$category_id=(int)$_GET["category_id"];   // integer id only (was concatenated raw into SQL)

        echo '<select class="form-control selectsub" name="subcategory" id="subcategory"><option value="">Select Subcategory</option>';

        $subcatquery = "SELECT subcategory_id, subcategory_value FROM ms_onsite_subcategories WHERE subcategory_status=1 AND category_id=".$category_id." ORDER BY subcategory_order ASC";                   
        $result2 = $conn->query($subcatquery);
        while($sub = $result2->fetch_row()) {
         echo '<option value="'.$sub[0].'">'.$sub[1].'</option>';
        }
		echo("</select>");
	}
    else{
        echo '<select class="form-control selectsub" name="subcategory" id="subcategory"><option value="">Select Subcategory</option>';
    }
?>
<script>
    $(function () {
        //Initialize Select2 Elements
        $('.selectsub').select2({
        theme: 'bootstrap4'
        });
    });
</script>