--TEST--
FFE dependent flags preserve unique prerequisite observations through Rust and Zend
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php
var_dump(DDTrace\Testing\ffe_load_config(file_get_contents(__DIR__ . '/dependent_flags.json')));
foreach (array('root', 'rejected', 'missing', 'requires-disabled', 'parent') as $key) {
    $result = DDTrace\ffe_evaluate($key, DDTrace\FFE_BOOL, 'subject', array(), false);
    echo $key . ': ' . $result->valueJson . ' ' . $result->errorCode . "\n";
    echo json_encode($result->dependencyEvaluations) . "\n";
}
$config = json_decode(file_get_contents(__DIR__ . '/dependent_flags.json'), true);
$config['flags']['parent']['allocations'] = array();
DDTrace\Testing\ffe_load_config(json_encode($config));
$result = DDTrace\ffe_evaluate('root', DDTrace\FFE_BOOL, 'subject', array(), false);
echo 'requires-unassigned: ' . $result->valueJson . ' ' . $result->errorCode . "\n";
echo json_encode($result->dependencyEvaluations) . "\n";
foreach (array('disabled', 'parent') as $key) {
    $result = DDTrace\ffe_evaluate($key, DDTrace\FFE_BOOL, 'subject', array(), false);
    echo 'direct-' . $key . ': ' . $result->errorCode . ' ' . $result->reason . "\n";
}
?>
--EXPECT--
bool(true)
root: true 0
[{"flagKey":"parent","variant":"on","allocationKey":"parent-allocation","errorCode":null,"observeFullEvaluationData":false}]
rejected: false 0
[{"flagKey":"parent","variant":"on","allocationKey":"parent-allocation","errorCode":null,"observeFullEvaluationData":false}]
missing: null 7
[{"flagKey":"absent","variant":null,"allocationKey":null,"errorCode":"FLAG_NOT_FOUND","observeFullEvaluationData":false}]
requires-disabled: null 7
[{"flagKey":"disabled","variant":null,"allocationKey":null,"errorCode":"GENERAL","observeFullEvaluationData":false}]
parent: true 0
[]
requires-unassigned: null 7
[{"flagKey":"parent","variant":null,"allocationKey":null,"errorCode":"GENERAL","observeFullEvaluationData":false}]
direct-disabled: 0 4
direct-parent: 0 1
