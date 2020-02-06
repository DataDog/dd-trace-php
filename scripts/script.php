<?php

error_log('This is printed to error log');
posix_kill(posix_getpid(), 11);
