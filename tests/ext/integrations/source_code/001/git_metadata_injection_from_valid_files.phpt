--TEST--
Basic Git Metadata Injection from valid .git files (Repository URL & Commit Sha)
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_GIT_METADATA_ENABLED=1
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: The pecl run-tests path is not in a git repository");
?>
--FILE--
<?php

require __DIR__ . '/../../../includes/git_functions.inc';

ini_set('datadog.trace.git_metadata_enabled', 1);

generateFullFakeGitFolder(__DIR__);

function makeRequest() {
    /** @var \DDTrace\RootSpanData $rootSpan */
    $rootSpan = \DDTrace\start_span();
    \DDTrace\start_span();

    \DDTrace\close_span();
    \DDTrace\close_span();

    $closedSpans = dd_trace_serialize_closed_spans();

    $rootMeta = $closedSpans[0]['meta'];
    echo $rootMeta['_dd.git.repository_url'] . PHP_EOL;
    echo $rootMeta['_dd.git.commit.sha'] . PHP_EOL;

    \DDTrace\start_span();
    \DDTrace\close_span();

    $closedRoot = dd_trace_serialize_closed_spans();
    $rootMeta2 = $closedRoot[0]['meta'];

    echo $rootMeta2['_dd.git.repository_url'] . PHP_EOL;
    echo $rootMeta2['_dd.git.commit.sha'] . PHP_EOL;
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
https://github.com/user/repo_new
123456
https://github.com/user/repo_new
123456
https://github.com/user/repo_new
123456
https://github.com/user/repo_new
123456
