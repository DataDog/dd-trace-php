<?php

/* Verify that samples collected inside eval()'d closures still resolve their
 * frames even after each closure and its op_array are destroyed before the
 * profile is exported at shutdown.
 */

function main() {
    for ($i = 0; $i < 5; $i++) {
        $closure = eval('return static function () {
            usleep(50_000);
        };');
        $closure();
        unset($closure);
    }
}

main();
