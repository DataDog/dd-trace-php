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
[{"flagKey":"disabled","variant":null,"allocationKey":null,"errorCode":null,"observeFullEvaluationData":false}]
parent: true 0
[]
