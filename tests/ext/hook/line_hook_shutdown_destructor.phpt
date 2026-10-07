--TEST--
A hook on a destructor body still fires when the object dies at request shutdown
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Late {
    public function __destruct() {
        echo "dtor body\n";         // 5
    }
}

$p = function (DDTrace\LineHookData $h) { echo 'hook at ', $h->line, "\n"; };
$id = DDTrace\install_line_hook(__FILE__, 5, $p);

$GLOBALS['keep'] = new Late();
register_shutdown_function(function () { echo "shutdown fn\n"; });
echo "end of script\n";
?>
--EXPECT--
end of script
shutdown fn
hook at 5
dtor body
