<?php

$collected = function_exists('\DDTrace\are_endpoints_collected') && \DDTrace\are_endpoints_collected();
echo "are_endpoints_collected: " . ($collected ? 'true' : 'false') . "\n";
