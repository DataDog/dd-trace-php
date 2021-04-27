<?php

echo "This script will intentionally segfault.\n";

// We don't require the pcntl extension, so can't use constant SIGSEGV
$sigsegv = 11;
posix_kill(posix_getpid(), 11);
