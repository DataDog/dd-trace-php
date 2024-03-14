<?php

for ($i = 0; $i < 10; $i++) {
    $runtime[$i] = new \parallel\Runtime();
    $future[$i] = $runtime[$i]->run(
        function (int $worker) {
            for ($i = 0; $i < 10; $i++) {
                try {
                    throw new Exception("Exception from worker " . $worker);
                } catch (Exception $e) {
                    // I do not care ;-)
                }
            }
            return $worker;
        },
        [$i]
    );
}

for ($i = 0; $i < 10; $i++) {
    try {
        throw new Exception("Exception from main thread");
    } catch (Exception $e) {
        // I do not care ;-)
    }
}

for ($i = 0; $i < 10; $i++) {
    printf("\nWorker %s exited\n", $future[$i]->value());
}
