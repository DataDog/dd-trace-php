--TEST--
Data Streams Monitoring: each fiber has a pathway of its own
--DESCRIPTION--
A fiber starts on a copy of the pathway of the code that starts it, and keeps its own while other
fibers run, so a produce in one fiber does not continue a message another fiber consumed.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip: fibers need PHP 8.1'); ?>
--ENV--
DD_DATA_STREAMS_ENABLED=1
DD_SERVICE=weblog
DD_ENV=system-tests
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function context_hash(string $context): string {
    $bytes = base64_decode($context, true);
    return sprintf('%u', unpack('P', substr($bytes, 0, 8))[1]);
}

$out = ['direction:out', 'topic:billing', 'type:rabbitmq'];

// The produce hash a message consumed from $queue links to.
function linked(string $queue, string $message): string {
    global $out;
    \DDTrace\Internal\dsm_push_pathway();
    \DDTrace\Internal\dsm_set_checkpoint(['direction:in', "topic:$queue", 'type:rabbitmq'], $message);
    $hash = context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));
    \DDTrace\Internal\dsm_pop_pathway();
    return $hash;
}

$message = \DDTrace\Internal\dsm_set_checkpoint($out);
$linkedMain = linked('main', $message);
$linkedA = linked('fiber-a', $message);

\DDTrace\Internal\dsm_set_checkpoint(['direction:in', 'topic:main', 'type:rabbitmq'], $message);

$a = new Fiber(function () use ($message, $out) {
    \DDTrace\Internal\dsm_set_checkpoint(['direction:in', 'topic:fiber-a', 'type:rabbitmq'], $message);
    Fiber::suspend();
    return context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));
});
$b = new Fiber(function () use ($out) {
    return context_hash(\DDTrace\Internal\dsm_set_checkpoint($out));
});

$a->start();
$b->start();
echo "a fiber starts on the pathway of the code that starts it, not another fiber's: ",
    var_export($b->getReturn() === $linkedMain, true), "\n";
$a->resume();
echo "a fiber keeps its pathway while others run: ", var_export($a->getReturn() === $linkedA, true), "\n";
echo "the code that started the fibers keeps its pathway: ",
    var_export(context_hash(\DDTrace\Internal\dsm_set_checkpoint($out)) === $linkedMain, true), "\n";

?>
--EXPECT--
a fiber starts on the pathway of the code that starts it, not another fiber's: true
a fiber keeps its pathway while others run: true
the code that started the fibers keeps its pathway: true
