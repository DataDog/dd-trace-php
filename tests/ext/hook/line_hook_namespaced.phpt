--TEST--
Namespaced functions, classes and strict_types do not disturb line resolution
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
declare(strict_types=1);

namespace Deep\Space;

function nsFn(int $x): int {
    return $x + 1;                  // 7
}

class NsClass {
    public function m(string $t): string {
        return 'ns:' . $t;          // 12
    }
}

$log = [];
$p = function (\DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$a = \DDTrace\install_line_hook(__FILE__, 7, $p);
$b = \DDTrace\install_line_hook(__FILE__, 12, $p);

\var_dump(nsFn(1));
\var_dump((new NsClass)->m('t'));
echo \implode(',', $log), "\n";
\DDTrace\remove_hook($a);
\DDTrace\remove_hook($b);
echo "Done.\n";
?>
--EXPECT--
int(2)
string(4) "ns:t"
7,12
Done.
