<?php

switch ($_SERVER['REQUEST_URI']) {
    case "/success":
        http_response_code(200);
        break;
    case "/error":
        http_response_code(500);
}

echo "Done.\n";
