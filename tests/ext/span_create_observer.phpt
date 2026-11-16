--TEST--
Test observing span creation
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

DDTrace\active_stack()->spanCreationObservers[] = function($span) {
    $span->name = "hey";
    echo "Observed\n";
};

DDTrace\start_span();
$span = DDTrace\start_span();
print "Got assigned a name: $span->name\n";
DDTrace\close_span();
DDTrace\close_span();

DDTrace\create_stack();
DDTrace\start_span();
DDTrace\close_span();

DDTrace\active_stack()->spanCreationObservers = [];
DDTrace\start_span();
DDTrace\close_span();

DDTrace\switch_stack();

print "Back to root\n";

DDTrace\active_stack()->spanCreationObservers[] = function($span) {
    echo "Observed and removed: 1\n";
    return false;
};
DDTrace\start_span();
DDTrace\start_span();
DDTrace\close_span();
DDTrace\close_span();

// inherited and propagated back (removing original "observed" function too)
DDTrace\active_stack()->spanCreationObservers = [function($span) {
    echo "Observed and removed: 2\n";
    return false;
}];
DDTrace\create_stack();
DDTrace\start_span();
DDTrace\close_span();
DDTrace\start_span();
DDTrace\close_span();
DDTrace\switch_stack();
DDTrace\start_span();
DDTrace\close_span();

DDTrace\start_span(); // on top of a root span
DDTrace\active_stack()->spanCreationObservers = [function($span) {
    echo "Observed, added and removed\n";
    DDTrace\active_stack()->spanCreationObservers[] = function($span) {
        echo "Inner-observed\n";
    };
    return false;
}, 2];
DDTrace\start_span();
DDTrace\close_span();
DDTrace\start_span();
DDTrace\close_span();
DDTrace\close_span();

echo "Observed is count: ", count(array_filter(DDTrace\active_stack()->spanCreationObservers)), "\n";

DDTrace\active_stack()->spanCreationObservers = [function($span) {
    echo "Observed and cleared\n";
    // root spans create a stack, hence parent
    DDTrace\active_stack()->spanCreationObservers = [];
    DDTrace\active_stack()->parent->spanCreationObservers = [];
}, function($span) {
    echo "Not visited\n";
}];
DDTrace\start_span();
DDTrace\close_span();
DDTrace\start_span();
DDTrace\close_span();


?>
--EXPECT--
Observed
Observed
Got assigned a name: hey
Observed
Back to root
Observed
Observed and removed: 1
Observed
Observed and removed: 2
Observed, added and removed
Inner-observed
Inner-observed
Observed is count: 0
Observed and cleared
