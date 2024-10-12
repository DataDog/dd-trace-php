--TEST--
When DD_GIT_REPOSITORY_URL is specified, _dd.git.repository_url is injected
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_GIT_REPOSITORY_URL=github.com/user/env_repo
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

ini_set('datadog.trace.git_metadata_enabled', 1);

$rootSpan = \DDTrace\start_span();
$internalSpan = \DDTrace\start_span();

\DDTrace\close_span();
\DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(2) {
  [0]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(26) "repository_url_env_var.php"
    ["resource"]=>
    string(26) "repository_url_env_var.php"
    ["service"]=>
    string(26) "repository_url_env_var.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(4) {
      ["runtime-id"]=>
      string(%d) "%s"
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.git.repository_url"]=>
      string(24) "github.com/user/env_repo"
      ["_dd.p.tid"]=>
      string(16) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["process_id"]=>
      float(%d)
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
    }
  }
  [1]=>
  array(9) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(0) ""
    ["resource"]=>
    string(0) ""
    ["service"]=>
    string(26) "repository_url_env_var.php"
    ["type"]=>
    string(3) "cli"
  }
}
