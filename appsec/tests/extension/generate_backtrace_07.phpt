--TEST--
Functions are fully qualified names
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
--FILE--
<?php
namespace Some\NameSpace {
    use function datadog\appsec\testing\generate_backtrace;

    class Foo {
        static function three () {
            var_dump(generate_backtrace("some id"));
        }

        function two($param01, $param02)
        {
            self::three();
        }

        function one($param01)
        {
            $this->two($param01, "other");
        }
    }
}

namespace {
    include __DIR__ . '/inc/ddtrace_version.php';

    DDTrace\start_span();
    $root = DDTrace\active_span();

    $class = new Some\NameSpace\Foo();
    $class->one("foo01");
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
      int(12)
      ["function"]=>
      string(25) "Some\NameSpace\Foo::three"
      ["file"]=>
      string(25) "generate_backtrace_07.php"
      ["id"]=>
      int(0)
    }
    [1]=>
    array(4) {
      ["line"]=>
      int(17)
      ["function"]=>
      string(23) "Some\NameSpace\Foo::two"
      ["file"]=>
      string(25) "generate_backtrace_07.php"
      ["id"]=>
      int(1)
    }
    [2]=>
    array(4) {
      ["line"]=>
      int(29)
      ["function"]=>
      string(23) "Some\NameSpace\Foo::one"
      ["file"]=>
      string(25) "generate_backtrace_07.php"
      ["id"]=>
      int(2)
    }
  }
}
