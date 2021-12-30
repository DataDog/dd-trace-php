<?php
if (key_exists('USE_ZEND_ALLOC', $_ENV) && $_ENV['USE_ZEND_ALLOC'] == '0' &&
    !key_exists('NO_VALGRIND_SKIP', $_ENV)) {
    die('skip not to run with valgrind');
}

