<?php
// Mock Session and GET request
session_start();
$_SESSION['user_id'] = 3; // Correct User ID for test@test.com
$_GET['ajax_data'] = 1;
$_GET['id'] = 5; // Target OID

// Mock Environment
putenv("DB_HOST=kylo-main-db");
putenv("DB_USER=sylo_app");
putenv("DB_PASS=sylo_app_pass");
putenv("DB_NAME=sylo_admin_db");

// Include the script
require 'data.php';
?>
