<?php

require_once("../config/session.php");
require_once("../config/auth.php");
mn_require_rcdc();

require_once("../config/db-config.php");

$round = isset($_GET['round'])
    ? trim($_GET['round'])
    : '';

if ($round === '') {
    exit;
}

/*
 * Get number of panels from the selected round.
 */
$stmt = $conn->prepare("
    SELECT total_panels
    FROM rdt_panel_round_informations
    WHERE round_id = ?
    LIMIT 1
");

if (!$stmt) {
    exit;
}

$stmt->bind_param("s", $round);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_row();

$stmt->close();

if (!$row) {
    exit;
}

// Derive totalPanels dynamically from the round's configuration
$totalPanels = (int)$row[0];
if ($totalPanels <= 0) {
    $totalPanels = 10;
}

/*
 * Create empty panel number fields.
 *
 * The actual panel numbers will be generated
 * later after Dzongkhag + Facility are selected.
 */
for ($i = 1; $i <= $totalPanels; $i++) {

?>

<div class="form-group row panel-row" data-panel-index="<?= $i ?>">

    <label class="col-sm-3 col-form-label-sm">
        Panel No. <?= $i ?> (*)
    </label>

    <div class="col-sm-3">

        <input
            type="text"
            name="slideno[]"
            class="form-control"
            value=""
            readonly
            required
            autocomplete="off"
        >

    </div>

</div>

<?php

}

?>
