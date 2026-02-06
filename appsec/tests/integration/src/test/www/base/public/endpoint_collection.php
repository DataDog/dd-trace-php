<?php

echo "are_endpoints_collected before: " . (\DDTrace\are_endpoints_collected() ? 'true' : 'false') . "\n";

\DDTrace\add_endpoint('/test', 'http.request', 'GET /test', 'GET');

sleep(1);

echo "are_endpoints_collected after: " . (\DDTrace\are_endpoints_collected() ? 'true' : 'false') . "\n";