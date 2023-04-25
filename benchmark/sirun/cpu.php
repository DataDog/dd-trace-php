<?php
// Check if the number of iterations is provided as a CLI argument
if ($argc < 2) {
    echo "Usage: php benchmark.php [number_of_iterations]\n";
    exit(1);
}

// Get the number of iterations from the CLI argument
$iterations = (int) $argv[1];

// A simple function that performs a basic mathematical operation
function doNothing() {
    usleep(10000);
    allocateMemory();
}

function allocateMemory() {
    str_repeat('a', 512 * 1024);
}

// Run the loop for the specified number of iterations
for ($i = 0; $i < $iterations; $i++) {
    doNothing($i);
}
