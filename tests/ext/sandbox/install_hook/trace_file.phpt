--TEST--
Test file inclusion hooking
--INI--
datadog.trace.generate_root_span=0
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
  %s/testinclude.inc (trace_file.php, %s/install_hook/testinclude.inc, cli)
    _dd.p.dm => -1
  %s/testinclude.inc (trace_file.php, %s/install_hook/testinclude.inc, cli)
    _dd.p.dm => -1
}
