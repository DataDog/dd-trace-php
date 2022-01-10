--TEST--
Parsing of datadog.appsec.helper_extra_flags
--INI--
--FILE--
<?php
use function datadog\appsec\testing\{set_helper_extra_args,get_helper_argv};

function t($cmdline) {
    echo $cmdline, ":\n";
    set_helper_extra_args($cmdline);
    var_dump(get_helper_argv());
    echo "\n";
}

t('');
t('""');
t('  ');
t('foo bar');
t('\foo \bar');
t('  foo   bar   ');
t('foo\ bar\ ');
t('foo""\'\'');
t('foo"\'"\'"\'');
t('foo"bar\""\'bar\\\'\'');
t('"foo bar"');
t("'foo bar'");

echo "\nWith errors\n\n";
t("bar'");
t('bar"');
t("'bar");
t('"bar');
t('bar\\');

?>
--EXPECTF--
:
array(1) {
  [0]=>
  string(%d) "%s"
}

"":
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(0) ""
}

  :
array(1) {
  [0]=>
  string(%d) "%s"
}

foo bar:
array(3) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(3) "foo"
  [2]=>
  string(3) "bar"
}

\foo \bar:
array(3) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(3) "foo"
  [2]=>
  string(3) "bar"
}

  foo   bar   :
array(3) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(3) "foo"
  [2]=>
  string(3) "bar"
}

foo\ bar\ :
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(8) "foo bar "
}

foo""'':
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(3) "foo"
}

foo"'"'"':
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(5) "foo'""
}

foo"bar\""'bar\'':
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(11) "foobar"bar'"
}

"foo bar":
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(7) "foo bar"
}

'foo bar':
array(2) {
  [0]=>
  string(%d) "%s"
  [1]=>
  string(7) "foo bar"
}


With errors

bar':

Warning: datadog\appsec\testing\get_helper_argv(): [ddappsec] datadog.appsec.helper_extra_args has unmatched quotes: bar' in %s on line %d
array(0) {
}

bar":

Warning: datadog\appsec\testing\get_helper_argv(): [ddappsec] datadog.appsec.helper_extra_args has unmatched quotes: bar" in %s on line %d
array(0) {
}

'bar:

Warning: datadog\appsec\testing\get_helper_argv(): [ddappsec] datadog.appsec.helper_extra_args has unmatched quotes: 'bar in %s on line %d
array(0) {
}

"bar:

Warning: datadog\appsec\testing\get_helper_argv(): [ddappsec] datadog.appsec.helper_extra_args has unmatched quotes: "bar in %s on line %d
array(0) {
}

bar\:

Warning: datadog\appsec\testing\get_helper_argv(): [ddappsec] datadog.appsec.helper_extra_args has an unpaired \ at the end: bar\ in %s on line %d
array(0) {
}
