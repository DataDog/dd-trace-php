<?php

var_dump("Are endpoints collected?", \DDTrace\are_endpoints_collected());

\DDTrace\add_endpoint("/api/v1/traces", 'http.request', "resource_name", "GET");

// \DDTrace\add_endpoint("type", "/api/v1/traces", "operation_name", "resource_name","body_typeaaaa", "response_type", 1, 2, '{"some":"json"}');
// \DDTrace\add_endpoint("type", "/api/v1/traces", "operation_name", "resource_name","body_typeaaaa", "response_type", 1, 2, '');