<?php
    require_once("../config/session.php");
	require_once("../config/db-config.php");
    if(isset($_GET["facility"]) AND $_GET["facility"]<>""){
		$facility=(string)$_GET["facility"];
		// Patient data: facility users may list only their own facility's unshipped samples.
		if (!mn_owns_facility($facility)) { mn_deny_plain('You are not authorised to view this facility\'s samples.'); }
		$sst = $conn->prepare("SELECT dbs_id, patient_name, age, bse_no, collection_date FROM dbs_results WHERE dbs_shipment_id IS NULL AND facility_id = ?");
		$sst->bind_param('s', $facility);
		$sst->execute();
        $samples = $sst->get_result();
        if($samples->num_rows >= 1){
?>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th scope="col">Sample #</th>
                        <th>Patient Name</th>
                        <th>Age</th>
                        <th>BSE No.</th>
                        <th>Collection Date</th>
                    </tr>
                </thead>
                <tbody class="checkbox-group required">
                    <?php
                    while ($row = $samples->fetch_row()) {
                    ?>
                    <tr>
                        <td scope="row">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="sample_id" name="sample_id[]" value="<?=$row[0];?>" checked>
                                <label class="form-check-label" for="sample_id"><?=$row[0];?></label>
                            </div>
                        </td>
                        <td><?=$row[1];?></td>
                        <td><?=$row[2];?></td>
                        <td><?=$row[3];?></td>
                        <td><?=$row[4];?></td>
                    </tr>
                    <?php
                    }
                    ?>
                </tbody>
            </table>
<?php
        }else{
?>
            <div class="form-group required col-sm-12">
                <label for="sample_id">No Samples found for Shipment</label>
                <select name="sample_id" id="sample_id" class="form-control" required>
                    <option value="" disabled selected>No samples available</option>
                </select>
            </div>

           
<?php
        }
    }else{

?>
        <div class="form-group required col-sm-12">
            <label for="sample_id">Select Samples</label>
            <select name="sample_id" id="sample_id" class="form-control" required>
                <option value="" selected>Select Samples</option>
            </select>
        </div>
<?php
    }
?>