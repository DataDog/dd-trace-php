<?php

\datadog\appsec\track_user_login_failure_event_automated('Login', 'Admin', false, [
    'email' => 'jean.example@example.com',
    'session_id' => '987654321',
    'role' => 'admin'
]);

echo "Automated User Login Failure";
