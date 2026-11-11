<?php

/** @generate-function-entries */

namespace datadog\appsec {
    function is_enabled(): bool {}
    function is_fully_disabled(): bool {}
    /** @param array|string $rasp_rule rule name, or options array (keys: rasp_rule, rule_variant, subctx_id, subctx_last_call) */
    function push_addresses(array $addresses, array|string $rasp_rule = '', string $rule_variant = ''): bool {}
    function should_send_downstream_bodies(): bool {}
    /** @param resource $stream */
    function fflush_stdiocast($stream): bool {}
}
