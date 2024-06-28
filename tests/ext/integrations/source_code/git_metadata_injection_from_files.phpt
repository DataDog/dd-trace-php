--TEST--
Basic Git Metadata Injection from .git files (Repository URL & Commit Sha)
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_GIT_METADATA_ENABLED=1
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: The pecl run-tests path is not in a git repository");
?>
--FILE--
<?php

ini_set('datadog.trace.git_metadata_enabled', 1);

$rootSpan = \DDTrace\start_span();
$internalSpan = \DDTrace\start_span();

\DDTrace\close_span();
\DDTrace\close_span();

$closedSpans = dd_trace_serialize_closed_spans();

$gitCommitSha = trim(`git rev-parse HEAD`);
$gitRepositoryURL = trim(`git config --get remote.origin.url`);

$rootMeta = $closedSpans[0]['meta'];
$childMeta = $closedSpans[1]['meta'];
echo 'Root Meta Repo URL: ' . (!isset($rootMeta['git.repository_url']) ? 'OK' : 'NOK') . PHP_EOL;
echo 'Child Meta Repo URL: ' . ($childMeta['git.repository_url'] === $gitRepositoryURL ? 'OK' : 'NOK') . PHP_EOL;
echo 'Root Meta Commit Sha: ' . (!isset($rootMeta['git.commit.sha']) ? 'OK' : 'NOK') . PHP_EOL;
echo 'Child Meta Commit Sha: ' . ($childMeta['git.commit.sha'] == $gitCommitSha ? 'OK' : 'NOK') . PHP_EOL;
echo '_dd Root Meta Repo URL: ' . ($rootMeta['_dd.git.repository_url'] === $gitRepositoryURL ? 'OK' : 'NOK') . PHP_EOL;
echo '_dd Root Meta Commit Sha: ' . ($rootMeta['_dd.git.commit.sha'] == $gitCommitSha ? 'OK' : 'NOK') . PHP_EOL;

?>
--EXPECTF--
Root Meta Repo URL: OK
Child Meta Repo URL: OK
Root Meta Commit Sha: OK
Child Meta Commit Sha: OK
_dd Root Meta Repo URL: OK
_dd Root Meta Commit Sha: OK
