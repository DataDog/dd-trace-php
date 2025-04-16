<?php

\datadog\appsec\track_user_login_success_event_automated(
    $_GET['login'] ?? 'Login',
    $_GET['id'] ?? 'Admin',
    [
        'email' => 'jean.example@example.com',
        'session_id' => '987654321',
        'role' => 'admin'
    ]
);

echo "Automated User Login Success";
