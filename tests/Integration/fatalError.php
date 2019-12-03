<?php

if ($_SERVER['REQUEST_URI'] === '/user-fatal') {
    trigger_error("Manually triggered user fatal error", E_USER_ERROR);
} elseif ($_SERVER['REQUEST_URI'] === '/core-fatal') {
    spl_autoload_register('doesnt_exist');
} elseif ($_SERVER['REQUEST_URI'] === '/unhandled-exception') {
    throw new \Exception('Exception not hanlded by the framework!');
} elseif ($_SERVER['REQUEST_URI'] === '/caught-exception') {
    try {
        throw new \Exception('Exception hanlded by the framework!');
    } catch (\Exception $e) {
        // handling somehow
    }
}
