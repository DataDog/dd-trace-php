<?php

$success = $_GET['success'] ?? 0;
$failure = $_GET['failure'] ?? 0;

for ($i = 0; $i < $success; $i++) {
    \DDTrace\ATO\V2\track_user_login_success('login');
}
for ($i = 0; $i < $failure; $i++) {
    \DDTrace\ATO\V2\track_user_login_failure('login', true);
}

echo 'Done!';
