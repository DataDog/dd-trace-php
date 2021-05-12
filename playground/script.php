<?php

const REPETITIONS = 100000000;

$results = [];

for ($i = 0; $i < REPETITIONS; $i++) {
    $result = (float)'0' === 0.0;
    if (!$result) {
     echo "NOPE\n";
    }
}
