<?php

// Test script to verify process tags with multiple child spans and partial flush
echo "Testing DD Process Tags with Partial Flush\n";
echo "PHP Version: " . PHP_VERSION . "\n";

// Check if ddtrace extension is loaded
if (extension_loaded('ddtrace')) {
    echo "✓ ddtrace extension loaded\n";
} else {
    echo "✗ ddtrace extension NOT loaded\n";
    exit(1);
}

for ($j = 1; $j <= ; $j++) {
    // Create a root span
    $rootSpan = \DDTrace\start_span();
    $rootSpan->name = 'test.root_span';
    $rootSpan->service = 'test-service';
    $rootSpan->resource = 'root-resource';
    $rootSpan->meta['root.tag'] = 'root-value';

    echo "✓ Root span created\n";

    // // Create 5 child spans
    // $childSpans = [];
    // for ($i = 1; $i <= 5; $i++) {
    //     usleep(1000); // 1ms between spans
        
    //     $childSpan = \DDTrace\start_span();
    //     $childSpan->name = "test.child_span_$i";
    //     $childSpan->service = 'test-service';
    //     $childSpan->resource = "child-resource-$i";
    //     $childSpan->meta['child.number'] = (string)$i;
        
    //     echo "✓ Child span $i created\n";
        
    //     // Simulate work in child span
    //     usleep(500);
        
    //     // Close the child span
    //     \DDTrace\close_span();
    //     echo "✓ Child span $i closed\n";
    // }

    // Simulate more work in root span
    usleep(1000);

    // Close the root span - this will trigger serialization and flush
    \DDTrace\close_span();
}
echo "✓ Root span closed\n";
echo "Script completed successfully\n";
