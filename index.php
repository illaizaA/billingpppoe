<?php
session_start();
require_once __DIR__ . '/helpers_salam.php';

if (empty($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

salamRequireLogin();
header('Location: dashboard_salam.php');
exit;
?>
