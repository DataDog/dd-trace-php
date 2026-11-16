<?php

\datadog\appsec\internal\track_user_login_failure_event_automated('test', 'Login', 'Admin', false, [
    'email' => 'jean.example@example.com',
    'session_id' => '987654321',
    'role' => 'admin'
]);

echo "Automated User Login Failure";
