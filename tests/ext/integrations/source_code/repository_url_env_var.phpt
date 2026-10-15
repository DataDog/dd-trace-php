--TEST--
When DD_GIT_REPOSITORY_URL is specified, _dd.git.repository_url is injected
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_GIT_REPOSITORY_URL=github.com/user/env_repo
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
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
  ["attributes"]=>
  array(3) {
    ["_dd.sdk.otlp_export"]=>
    string(5) "false"
    ["_dd.tags.process"]=>
    string(%d) "%s"
    ["_dd.git.repository_url"]=>
    string(24) "github.com/user/env_repo"
  }
  ["chunks"]=>
  array(1) {
    [0]=>
    array(4) {
      ["trace_id"]=>
      string(%d) "%s"
      ["priority"]=>
      int(1)
      ["sampling_mechanism"]=>
      int(0)
      ["spans"]=>
      array(2) {
        [0]=>
        array(8) {
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
          ["attributes"]=>
          array(6) {
            ["runtime-id"]=>
            string(%d) "%s"
            ["process_id"]=>
            int(%d)
            ["_dd.agent_psr"]=>
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
        array(8) {
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
    }
  }
}
