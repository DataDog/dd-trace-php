<?php
// $_POST/$_GET/$_COOKIE are populated EAGERLY during request startup: the SAPI
// treat_data parse runs inside php_hash_environment before any userland, and it
// does so regardless of auto_globals_jit (that INI only defers
// $_SERVER/$_ENV/$_REQUEST; $_POST is registered with jit=0, so it is always
// built eagerly). So when a field name overflows memory_limit the PHP2Shell
// mitigation has already fired during startup, before this script runs --
// reaching here means it did not. The count($_POST) below only reads the
// already-parsed array. We report the effective memory_limit, how many fields
// were parsed, and the peak real memory usage (used by the test to calibrate
// memory_limit so a given field lands in the soft-defer vs hard-exit band).
echo "memory_limit=", ini_get('memory_limit'), "\n";
$n = count($_POST);
echo "post_fields=$n\n";
echo "peak=", memory_get_peak_usage(true), "\n";
