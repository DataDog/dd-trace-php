--TEST--
phpinfo() shows each INI directive exactly once, under the section of its component
--SKIPIF--
<?php
if (!extension_loaded('ddtrace')) die('skip: test requires the ddtrace extension');
?>
--INI--
datadog.service=master-service
datadog.tags="k:<v>&"
--FILE--
<?php

// Runtime changes must only show up as "Local Value"
ini_set('datadog.service', 'runtime-service');

ob_start();
(new ReflectionExtension('ddtrace'))->info();
$output = ob_get_clean();

$headings = [
    'Datadog Tracer' => 'tracer',
    'Datadog Application Security' => 'appsec',
    'Datadog Profiler' => 'profiler',
];

$section = null;
$sectionsSeen = [];
$rows = []; // directive => list of [section, local, master]
foreach (preg_split("/\R/", $output) as $line) {
    if (isset($headings[$line])) {
        $section = $headings[$line];
        $sectionsSeen[] = $section;
        continue;
    }
    if (preg_match('/^((?:datadog|ddtrace)\.[a-z0-9_.]+) => (.*) => (.*)$/', $line, $m)) {
        $rows[$m[1]][] = [$section, $m[2], $m[3]];
    }
}

function check($condition, $message) {
    if (!$condition) {
        echo "FAIL: $message\n";
    }
}

// The tracer section always comes first
check($sectionsSeen[0] === 'tracer', 'The tracer section must be the first one');
check($sectionsSeen === array_unique($sectionsSeen), 'Sections must not be repeated');
check(count($rows) > 0, 'No INI directives were found');

// Directives are listed exactly once, and in the section of their component. If a component is not built into the
// extension it has no section, and its directives fall back to the tracer one.
$has = array_flip($sectionsSeen);
foreach ($rows as $name => $occurrences) {
    check(count($occurrences) === 1, "$name is shown " . count($occurrences) . ' times');

    if (strpos($name, 'datadog.appsec.') === 0 || strpos($name, 'datadog.api_security_') === 0) {
        $expected = isset($has['appsec']) ? 'appsec' : 'tracer';
    } elseif (strpos($name, 'datadog.profiling.') === 0) {
        $expected = isset($has['profiler']) ? 'profiler' : 'tracer';
    } else {
        $expected = 'tracer';
    }
    check($occurrences[0][0] === $expected, "$name is under '{$occurrences[0][0]}' instead of '$expected'");
}

// Settings shared by all the products live in the tracer section
foreach (['datadog.service', 'datadog.env', 'datadog.version', 'datadog.agent_host', 'datadog.trace.enabled'] as $shared) {
    check(isset($rows[$shared]), "$shared is missing");
    check(isset($rows[$shared]) && $rows[$shared][0][0] === 'tracer', "$shared must be in the tracer section");
}

// Local vs master values
check(isset($rows['datadog.service']), 'datadog.service is missing');
if (isset($rows['datadog.service'])) {
    check($rows['datadog.service'][0][1] === 'runtime-service', 'Local value must be the runtime one');
    check($rows['datadog.service'][0][2] === 'master-service', 'Master value must be the ini one');
}

// Text mode (CLI) prints values as they are, they are only escaped when rendering HTML
check(isset($rows['datadog.tags']) && $rows['datadog.tags'][0][1] === 'k:<v>&', 'Values must not be escaped in text mode');

// Directives without a value are shown as such
check(strpos($output, 'datadog.api_key => no value => no value') !== false, 'Empty directives must say "no value"');

echo "Done.\n";
?>
--EXPECT--
Done.
