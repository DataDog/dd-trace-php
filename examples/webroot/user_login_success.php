<?php

\datadog\appsec\track_user_login_event('Admin', true, [
    'email' => 'jean.example@example.com',
    'session_id' => '987654321',
    'role' => 'admin'
]);

echo "User Login Success";
