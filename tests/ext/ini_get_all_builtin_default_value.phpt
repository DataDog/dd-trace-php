--TEST--
ini_get_all() details report the registered default of our INI entries
--SKIPIF--
<?php if (PHP_VERSION_ID < 80600) die("skip: builtin_default_value requires PHP 8.6+"); ?>
--FILE--
<?php

$inis = array_filter(ini_get_all(null, true), function ($name) {
    return strpos($name, "datadog.") === 0;
}, ARRAY_FILTER_USE_KEY);

$bad = [];
foreach ($inis as $name => $details) {
    $default = $details["builtin_default_value"];
    if (!is_string($default) || strlen($default) > 4096 || !preg_match("//u", $default)) {
        $bad[] = $name;
    }
}
var_dump(count($inis) > 100, $bad);
var_dump($inis["datadog.trace.agent_port"]["builtin_default_value"]);
var_dump($inis["datadog.trace.enabled"]["builtin_default_value"]);

$ddtrace = ini_get_all("ddtrace", true);
var_dump($ddtrace["datadog.trace.debug_prng_seed"]["builtin_default_value"]);

?>
--EXPECT--
bool(true)
array(0) {
}
string(4) "8126"
string(4) "true"
string(2) "-1"
