--TEST--
A retroactive join applies the same scope filtering as an ordinary hook entry
--DESCRIPTION--
Hooks are keyed by the opcodes array, which several distinct functions can share -- a trait method flattened into two classes is one entry behind two zend_functions. zai_hook_continue() filters those by called scope at entry; a join that matched only on hook id delivered A::run's callback to a running B::run.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait SharedMethod {
    public function run($install) {
        if ($install) {
            DDTrace\install_hook('A::run', null, function (DDTrace\HookData $h) {
                echo '  A hook received ', get_class($h->instance), "\n";
            });
        }
        echo '  ', static::class, " body\n";
    }
}
class A { use SharedMethod; }
class B { use SharedMethod; }

// A hook on B makes B's frame carry a record, which is what the join then grows.
DDTrace\install_hook('B::run', function () {});

(new B)->run(true);     // installs the A hook mid-call; B must not receive it
(new A)->run(false);    // a real A call does

echo "Done.\n";
?>
--EXPECT--
  B body
  A body
  A hook received A
Done.
