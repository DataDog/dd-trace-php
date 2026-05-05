<?php

\datadog\appsec\internal\track_user_signup_event_automated('test', "Login", "Admin", ['email' => 'jean.example@example.com',
    'session_id' => '987654321',
    'role' => 'admin'
 ]);

echo "Automated User Signup";
