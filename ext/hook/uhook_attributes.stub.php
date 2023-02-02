<?php

/** @generate-class-entries */

namespace DDTrace;

require "Zend/zend_attributes.stub.php";

// phpcs:disable Squiz.Functions.MultiLineFunctionDeclaration.Indent

/**
 * If specified, this attribute ensures that all calls to that function are traced.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final class Trace {
    /**
     * @param string $name The operation name to be assigned to the span. Defaults to the function name.
     * @param string $resource The resource to be assigned to the span.
     * @param string $type The type to be assigned to the span.
     * @param string $service The service to be assigned to the span. Defaults to default or inherited service name.
     * @param array $tags The tags to be assigned to the span.
     * @param bool|array $saveArgs Whether arguments shall be saved as tags on the span. True to save them all.
                                    False to save none. An array with parameter names to save only select ones.
     * @param bool $saveReturn Whether to save return values (including yielded values on generators) on the span.
     * @param bool $recurse Whether recursive calls shall be traced.
     * @param bool $run_if_limited Whether the function shall be traced in limited mode. (E.g. when span limit exceeded)
     */
    public function __construct(
        string $name = "",
        string $resource = "",
        string $type = "",
        string $service = "",
        array $tags = [],
/* disable for now, until final decision on the API is taken
        bool|array $saveArgs = false,
        bool $saveReturn = false,
*/
        bool $recurse = true,
        bool $run_if_limited = false
    ) {}
}
