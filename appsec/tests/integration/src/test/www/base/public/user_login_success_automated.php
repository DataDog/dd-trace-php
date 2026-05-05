<?php

\datadog\appsec\internal\track_user_login_success_event_automated(
    'test',
    $_GET['login'] ?? 'Login',
    $_GET['id'] ?? 'Admin',
    [
        'email' => 'jean.example@example.com',
        'session_id' => '987654321',
        'role' => 'admin'
    ]
);

echo "Automated User Login Success";
