--TEST--
Backtrace do not contains datadog frames
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
--FILE--
<?php
namespace DDTrace {
    use function datadog\appsec\testing\generate_backtrace;

    class SomeIntegration {
        public function init()
        {
            install_hook("ltrim", self::hooked_function(), null);
        }

        private static function hooked_function()
        {
            return static function (HookData $hook) {
                  var_dump(generate_backtrace("some id"));
            };
        }
    }
}
namespace {
    include __DIR__ . '/inc/ddtrace_version.php';

    ddtrace_version_at_least('0.79.0');

    function two($param01, $param02)
    {
        var_dump(ltrim("     Verify the wrapped function works"));
    }

    function one($param01)
    {
        two($param01, "other");
    }

    $integration = new DDTrace\SomeIntegration();
    $integration->init();

    DDTrace\start_span();
    $root = DDTrace\active_span();
    one("foo01");
}

?>
--EXPECTF--
array(3) {
  ["language"]=>
  string(3) "php"
  ["id"]=>
  string(7) "some id"
  ["frames"]=>
  array(3) {
    [0]=>
    array(4) {
      ["line"]=>
      int(26)
      ["function"]=>
      string(5) "ltrim"
      ["file"]=>
      string(25) "generate_backtrace_06.php"
      ["id"]=>
      int(1)
    }
    [1]=>
    array(4) {
      ["line"]=>
      int(31)
      ["function"]=>
      string(3) "two"
      ["file"]=>
      string(25) "generate_backtrace_06.php"
      ["id"]=>
      int(2)
    }
    [2]=>
    array(4) {
      ["line"]=>
      int(39)
      ["function"]=>
      string(3) "one"
      ["file"]=>
      string(25) "generate_backtrace_06.php"
      ["id"]=>
      int(3)
    }
  }
}
string(33) "Verify the wrapped function works"
