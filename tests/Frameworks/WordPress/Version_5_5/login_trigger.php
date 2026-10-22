<?php
ob_start();
require __DIR__ . '/wp-load.php';
ob_end_clean();

$user = $_GET['user'] ?? '';
$pass = $_GET['pass'] ?? 'test';

wp_authenticate($user, $pass);

echo 'Done';
