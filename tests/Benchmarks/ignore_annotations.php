<?php

\DDTrace\hook_method(
    'PhpBench\Benchmark\Metadata\AnnotationReader',
    '__construct',
    function ($This) {
        $reflection = new \ReflectionClass($This);
        $property = $reflection->getProperty('globalIgnoredNames');
        $property->setAccessible(true);
        $globalIgnoredNames = $property->getValue($This);
        $globalIgnoredNames['retryAttempts'] = true;
        $globalIgnoredNames['retryDelayMethod'] = true;
        $property->setValue($This, $globalIgnoredNames);
    }
);