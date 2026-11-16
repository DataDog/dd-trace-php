<?php

\datadog\appsec\track_user_signup_event("Admin", ['email' => 'jean.example@example.com',
    'session_id' => '987654321',
    'role' => 'admin'
 ]);

echo "User Signup";
