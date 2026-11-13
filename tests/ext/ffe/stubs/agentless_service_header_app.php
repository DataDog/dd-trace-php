<?php

// The parent records the CDN requests of this process until it sends finish.
echo "fixture_child_ready\n";
if (trim((string) fgets(STDIN)) !== 'finish') throw new RuntimeException('finish command missing');
