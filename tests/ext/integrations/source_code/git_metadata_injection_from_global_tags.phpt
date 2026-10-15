--TEST--
Basic Git Metadata Injection from global tags (Repository URL & Commit Sha)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TAGS=git.commit.sha:123456,git.repository_url:github.com/user/env_repo
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
  array(4) {
    ["_dd.sdk.otlp_export"]=>
    string(5) "false"
    ["_dd.tags.process"]=>
    string(%d) "%s"
    ["_dd.git.commit.sha"]=>
    string(6) "123456"
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
          string(43) "git_metadata_injection_from_global_tags.php"
          ["resource"]=>
          string(43) "git_metadata_injection_from_global_tags.php"
          ["service"]=>
          string(43) "git_metadata_injection_from_global_tags.php"
          ["type"]=>
          string(3) "cli"
          ["attributes"]=>
          array(8) {
            ["runtime-id"]=>
            string(%d) "%s"
            ["process_id"]=>
            int(%d)
            ["git.commit.sha"]=>
            string(6) "123456"
            ["git.repository_url"]=>
            string(24) "github.com/user/env_repo"
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
        array(9) {
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
          string(43) "git_metadata_injection_from_global_tags.php"
          ["type"]=>
          string(3) "cli"
          ["attributes"]=>
          array(2) {
            ["git.commit.sha"]=>
            string(6) "123456"
            ["git.repository_url"]=>
            string(24) "github.com/user/env_repo"
          }
        }
      }
    }
  }
}
