--TEST--
Test class scoped file inclusion hooking
--INI--
datadog.trace.generate_root_span=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

include __DIR__ . '/../dd_dumper.inc';

DDTrace\install_hook("testinclude.inc", function($hook) {
    $hook->span();
});
DDTrace\install_hook("A::include", function($hook) {
    $hook->span();
});

class A {
    public static function include() {
        echo include "testinclude.inc", "\n";
    }
}

A::include();

dd_dump_spans();

?>
--EXPECTF--
test
spans(\DDTrace\SpanData) (1) {
  A.include (hook_scoped_file.php, A.include, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
    %stestinclude.inc (hook_scoped_file.php, %stestinclude.inc, cli)
}
