<?php
// Required unique identifier of the user.
// All other fields are optional.
\DDTrace\set_user($_GET['id'] ?? '123456789',
[
    'name' => 'Jean Example',
    'email' => 'jean.example@example.com',
    'session_id' => '987654321',
    'role' => 'admin',
    'scope' => 'read:message, write:files'
]);
echo "User Tracking";
