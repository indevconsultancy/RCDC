<?php
session_start();
$_SESSION["userID"] = "administrator";
$_SESSION["roleID"] = "1";

chdir(__DIR__ . "/blinded");
require_once "../config/db-config.php";

$_GET = ["id" => "2"];
$_REQUEST = $_GET;

ob_start();
include "pdf-blinded-feedbacks.php";
$pdfOutput = ob_get_clean();

echo "PDF Script executed successfully without errors!\n";
unlink(__FILE__);
