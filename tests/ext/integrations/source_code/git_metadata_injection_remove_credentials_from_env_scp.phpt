--TEST--
SCP-like repository URL sanitization from env var
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_GIT_REPOSITORY_URL=git@github.com:DataDog/dd-trace-php.git
DD_GIT_COMMIT_SHA=123456
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
    string(58) "git_metadata_injection_remove_credentials_from_env_scp.php"
    ["resource"]=>
    string(58) "git_metadata_injection_remove_credentials_from_env_scp.php"
    ["service"]=>
    string(58) "git_metadata_injection_remove_credentials_from_env_scp.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(5) {
      ["_dd.git.commit.sha"]=>
      string(6) "123456"
      ["_dd.git.repository_url"]=>
      string(35) "github.com:DataDog/dd-trace-php.git"
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
      ["runtime-id"]=>
      string(36) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["process_id"]=>
      float(%d)
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
    string(58) "git_metadata_injection_remove_credentials_from_env_scp.php"
    ["type"]=>
    string(3) "cli"
  }
}


