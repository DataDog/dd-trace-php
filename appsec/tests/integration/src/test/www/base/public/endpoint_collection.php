<?php

echo "are_endpoints_collected before: " . (\DDTrace\are_endpoints_collected() ? 'true' : 'false') . "\n";

\DDTrace\add_endpoint('/test_random_endpoint', 'http.request', 'GET /test_random_endpoint', 'GET');

sleep(1);

echo "are_endpoints_collected after: " . (\DDTrace\are_endpoints_collected() ? 'true' : 'false') . "\n";