--TEST--
insteadof/as trait aliasing keeps one line hook per flattened body
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait One {
    public function shared($t) {
        return 'one:' . $t;         // 5
    }
}
trait Two {
    public function shared($t) {
        return 'two:' . $t;         // 10
    }
}

class Picker {
    use One, Two {
        One::shared insteadof Two;
        Two::shared as sharedTwo;
        One::shared as aliasOne;
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line . ':' . $h->var('t'); };
$a = DDTrace\install_line_hook(__FILE__, 5, $p);
$b = DDTrace\install_line_hook(__FILE__, 10, $p);

$o = new Picker();
var_dump($o->shared('x'));
var_dump($o->aliasOne('y'));
var_dump($o->sharedTwo('z'));
echo implode(',', $log), "\n";
DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
echo "Done.\n";
?>
--EXPECT--
string(5) "one:x"
string(5) "one:y"
string(5) "two:z"
5:x,5:y,10:z
Done.
