--TEST--
A line hook inside a conditionally declared function survives a double include of the declaring file
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// The guarded declaration is the interesting part twice over.
// Its body compiles into its own op_array, so the only opline the *file* scope carries for those lines is ZEND_DECLARE_FUNCTION -- and zai_hook's location map only gains the function once it resolves, so a resolution running before that must not settle for the declaration opline.
// The second include takes the function_exists() branch and declares nothing, which must not disturb the existing arm.
$log = [];
$id = DDTrace\install_line_hook(__DIR__ . '/line_hook_conditional_decl.inc', 5, function ($h) use (&$log) {
    $log[] = $h->line . ':' . var_export($h->var('inner'), true);
});

require __DIR__ . '/line_hook_conditional_decl.inc';
var_dump(conditional_target(1));

require __DIR__ . '/line_hook_conditional_decl.inc';
var_dump(conditional_target(2));

var_dump($log);
DDTrace\remove_hook($id);
var_dump(conditional_target(3));
var_dump(count($log));
echo "Done.\n";
?>
--EXPECT--
int(11)
int(12)
array(2) {
  [0]=>
  string(6) "5:NULL"
  [1]=>
  string(6) "5:NULL"
}
int(13)
int(2)
Done.
