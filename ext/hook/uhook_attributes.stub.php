<?php

/** @generate-class-entries */

namespace DDTrace;

require "Zend/zend_attributes.stub.php";

#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final class Traced {
    public function __construct(
        string $name = "",
        string $resource = "",
        string $type = "",
        string $service = "",
        array $tags = [],
        bool|array $args = false,
        bool $return = false,
        bool $recurse = true,
        bool $run_if_limited = false
    ) {}
}
