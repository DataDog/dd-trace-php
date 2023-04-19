--TEST--
Test file inclusion hooking
--FILE--
<?php

function test_hook($path) {
    DDTrace\install_hook($path, null, function($hook) use ($path) {
        echo "$path: {$hook->args[0]}, ret: {$hook->returned}\n";
    });
}

chdir(__DIR__);

test_hook(DDTrace\HOOK_ALL_FILES);
test_hook("estinclude.inc");
test_hook("testinclude.inc");
test_hook("./testinclude.inc");
test_hook("../testinclude.inc");
test_hook(__DIR__ . "/testinclude.inc");

echo include "testinclude.inc", "\n";

?>
--EXPECTF--
Could not add hook to file path ../testinclude.inc, could not resolve path This message is only displayed once. Use DD_TRACE_DEBUG=1 to show all messages.
%s/install_hook/testinclude.inc: %s/install_hook/testinclude.inc, ret: test
./testinclude.inc: %s/install_hook/testinclude.inc, ret: test
testinclude.inc: %s/install_hook/testinclude.inc, ret: test
: %s/install_hook/testinclude.inc, ret: test
test
