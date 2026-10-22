<?php
// Child script for php2shell_mitigation_input_filter_parse_str.phpt.
// parse_str() goes through sapi_module.input_filter with PARSE_STRING: with
// filter.default set, ext/filter replaces *val and *new_val_len and returns 1.
// getenv() goes through sapi_getenv(), which calls the filter with a NULL
// new_val_len and returns the (possibly replaced) value.
parse_str('a=<b>&b[]=1&c=x+y', $v);
$env = getenv('P2S_ENV');
echo "RESULT ", json_encode([$v, $env]), "\n";
