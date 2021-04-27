<?php

header('Content-Type: text/plain');

echo 'Agent configured as HOST environment variable: ' . getenv('DD_AGENT_HOST') . "\n";
echo 'Port configured in Virtual-Host via SetEnv: ' . getenv('DD_TRACE_AGENT_PORT') . "\n";

echo "Hi!\n";
