--TEST--
Basic Git Metadata Injection from invalid .git files (Repository URL & Commit Sha)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_GIT_METADATA_ENABLED=0
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: The pecl run-tests path is not in a git repository");
?>
--FILE--
<?php
include __DIR__ . '/../../../sandbox/dd_dumper.inc';

require __DIR__ . '/../../../includes/git_functions.inc';

ini_set('datadog.trace.git_metadata_enabled', 1);

generateInvalidFakeGitFolder(__DIR__);

function makeRequest() {
    /** @var \DDTrace\RootSpanData $rootSpan */
    $rootSpan = \DDTrace\start_span();
    \DDTrace\start_span();

    \DDTrace\close_span();
    \DDTrace\close_span();

    $closedSpans = dd_clean_spans();
    $rootMeta = $closedSpans[0]['attributes'];
    var_dump($rootMeta);

    \DDTrace\start_span();
    \DDTrace\close_span();

    $closedRoot = dd_clean_spans();
    $rootMeta2 = $closedRoot[0]['attributes'];
    var_dump($rootMeta2);
}

makeRequest();
makeRequest();

?>
--CLEAN--
<?php
function rm_rf($dir) {
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? rm_rf("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
rm_rf(__DIR__ . '/.git');
?>
--EXPECTF--
array(7) {
  ["runtime-id"]=>
  string(%d) "%s"
  ["_dd.git.repository_url"]=>
  string(32) "https://github.com/user/repo_new"
  ["process_id"]=>
  float(%f)
  ["_dd.agent_psr"]=>
  float(1)
  ["php.compilation.total_time_ms"]=>
  float(%f)
  ["php.memory.peak_usage_bytes"]=>
  float(%f)
  ["php.memory.peak_real_usage_bytes"]=>
  float(%f)
}
array(7) {
  ["runtime-id"]=>
  string(%d) "%s"
  ["_dd.git.repository_url"]=>
  string(32) "https://github.com/user/repo_new"
  ["process_id"]=>
  float(%f)
  ["_dd.agent_psr"]=>
  float(1)
  ["php.compilation.total_time_ms"]=>
  float(%f)
  ["php.memory.peak_usage_bytes"]=>
  float(%f)
  ["php.memory.peak_real_usage_bytes"]=>
  float(%f)
}
array(7) {
  ["runtime-id"]=>
  string(%d) "%s"
  ["_dd.git.repository_url"]=>
  string(32) "https://github.com/user/repo_new"
  ["process_id"]=>
  float(%f)
  ["_dd.agent_psr"]=>
  float(1)
  ["php.compilation.total_time_ms"]=>
  float(%f)
  ["php.memory.peak_usage_bytes"]=>
  float(%f)
  ["php.memory.peak_real_usage_bytes"]=>
  float(%f)
}
array(7) {
  ["runtime-id"]=>
  string(%d) "%s"
  ["_dd.git.repository_url"]=>
  string(32) "https://github.com/user/repo_new"
  ["process_id"]=>
  float(%f)
  ["_dd.agent_psr"]=>
  float(1)
  ["php.compilation.total_time_ms"]=>
  float(%f)
  ["php.memory.peak_usage_bytes"]=>
  float(%f)
  ["php.memory.peak_real_usage_bytes"]=>
  float(%f)
}
