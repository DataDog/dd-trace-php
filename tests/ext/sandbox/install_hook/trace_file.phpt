--TEST--
Test file inclusion hooking
--INI--
datadog.trace.generate_root_span=0
datadog.code_origin_for_spans_enabled=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

include __DIR__ . '/../dd_dumper.inc';

DDTrace\install_hook(DDTrace\HOOK_ALL_FILES, function($hook) {
    $hook->span();
});

echo include "testinclude.inc", "\n";
echo include "testinclude.inc", "\n";

dd_dump_spans();

?>
--EXPECTF--
test
test
spans(\DDTrace\SpanData) (2) {
  %stestinclude.inc (trace_file.php, %sinstall_hook%ctestinclude.inc, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  %stestinclude.inc (trace_file.php, %sinstall_hook%ctestinclude.inc, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
}
