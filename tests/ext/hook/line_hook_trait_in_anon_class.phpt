--TEST--
A trait range flattened into an anonymous class fires begin and end, alongside a named user
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait AT {
    public function tm($t) {
        $v = $t;                    // 5  begin
        return $v . '!';            // 6  end
    }
}

class NamedUser { use AT; }

function named_user() { return new NamedUser(); }
function anon_user() { return new class { use AT; }; }
function anon_plus() { return new class { use AT; public function extra() { return 1; } }; }

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 5, $b, 6, $e);

// An anonymous class using a trait only gains the flattened method at *link* time, and it is linked lazily by ZEND_DECLARE_ANON_CLASS the first time the expression runs -- after this hook was installed.
// Up to PHP 7.3 the separate ZEND_BIND_TRAITS opline announced that; 7.4 folded it into the link, and PHP 8 never announces a non-toplevel class at all.
// Whichever way the resolver reaches it, every user of the trait must pair begin with end.
$log = []; var_dump(named_user()->tm('n')); echo 'named: ', implode(',', $log), "\n";
$log = []; var_dump(anon_user()->tm('a'));  echo 'anon:  ', implode(',', $log), "\n";
$log = []; var_dump(anon_plus()->tm('p'));  echo 'anon+: ', implode(',', $log), "\n";

// A second instantiation re-executes the declare opline against an already-linked class.
$log = []; var_dump(anon_user()->tm('a2')); echo 'again: ', implode(',', $log), "\n";

DDTrace\remove_hook($id);
$log = [];
anon_user()->tm('gone');
echo 'after remove: ', ($log ? implode(',', $log) : '(nothing)'), "\n";

echo "Done.\n";
?>
--EXPECT--
string(2) "n!"
named: B5,E6
string(2) "a!"
anon:  B5,E6
string(2) "p!"
anon+: B5,E6
string(3) "a2!"
again: B5,E6
after remove: (nothing)
Done.
