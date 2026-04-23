<?php
session_start();
$_SESSION['user_id'] = 1;
header('Location: dashboard.php?id=1');
exit;
