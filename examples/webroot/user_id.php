<?php
$rootSpan = \DDTrace\root_span();

 // Required unique identifier of the user.
$rootSpan->meta['usr.id'] = '123456789';

// All other fields are optional.
$rootSpan->meta['usr.name'] = 'Jean Example';
$rootSpan->meta['usr.email'] = 'jean.example@example.com';
$rootSpan->meta['usr.session_id'] = '987654321';
$rootSpan->meta['usr.role'] = 'admin';
$rootSpan->meta['usr.scope'] = 'read:message, write:files';

echo "User Tracking";
