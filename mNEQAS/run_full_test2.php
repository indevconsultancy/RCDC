<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "config/commondb-config.php";

function runTest($sessionMock, $getParams, $file = "load-facility.php") {
    foreach ($sessionMock as $k => $v) {
        $_SESSION[$k] = $v;
    }
    $_GET = $getParams;
    $_POST = [];
    $_REQUEST = $getParams;

    ob_start();
    include __DIR__ . '/' . $file;
    $output = ob_get_clean();

    return $output;
}

// Case 1: RCDC user, Dzongkhag code '08' (Paro)
$out1 = runTest(
    ['userID' => '1', 'roleID' => '1'],
    ['dzongkhag' => '08']
);
$pass1 = (strpos($out1, "Paro Hospital") !== false && strpos($out1, "id='facility'") !== false);
echo "Test 1 (RCDC '08'): " . ($pass1 ? "PASS" : "FAIL") . "\n";

// Case 2: RCDC user, Dzongkhag name 'Paro'
$out2 = runTest(
    ['userID' => '1', 'roleID' => '1'],
    ['dzongkhag' => 'Paro']
);
$pass2 = (strpos($out2, "Paro Hospital") !== false);
echo "Test 2 (RCDC 'Paro'): " . ($pass2 ? "PASS" : "FAIL") . "\n";

// Case 3: RCDC user, Dzongkhag code '99' (mneqas=0 fallback)
$out3 = runTest(
    ['userID' => '1', 'roleID' => '1'],
    ['dzongkhag' => '99']
);
$pass3 = (strpos($out3, "Others") !== false);
echo "Test 3 (mneqas=0 fallback '99'): " . ($pass3 ? "PASS" : "FAIL") . "\n";

// Case 4: RCDC user, load-facility-all.php
$out4 = runTest(
    ['userID' => '1', 'roleID' => '1'],
    ['dzongkhag' => '08'],
    'load-facility-all.php'
);
$pass4 = (strpos($out4, "Drugyel PHC") !== false && strpos($out4, "Paro Hospital") !== false);
echo "Test 4 (load-facility-all.php): " . ($pass4 ? "PASS" : "FAIL") . "\n";

// Case 5: Custom element id 'facility2'
$out5 = runTest(
    ['userID' => '1', 'roleID' => '1'],
    ['dzongkhag' => '08', 'id' => 'facility2']
);
$pass5 = (strpos($out5, "id='facility2'") !== false);
echo "Test 5 (id='facility2'): " . ($pass5 ? "PASS" : "FAIL") . "\n";

// Case 6: format='options'
$out6 = runTest(
    ['userID' => '1', 'roleID' => '1'],
    ['dzongkhag' => '08', 'format' => 'options']
);
$pass6 = (strpos($out6, "<select") === false && strpos($out6, "<option value='079'>") !== false);
echo "Test 6 (format='options'): " . ($pass6 ? "PASS" : "FAIL") . "\n";

unlink(__FILE__);
