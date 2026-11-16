--TEST--
FFE omits coerced targeting identity without changing evaluation or converting twice
--ENV--
DD_METRICS_OTEL_ENABLED=false
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/../../../Common/FeatureFlags/ffe_api_bootstrap.inc';
class FfeTargetingSubject {
    public $conversions = 0;
    public function __toString() {
        ++$this->conversions;
        return 'subject';
    }
}
$native = $submitted = array();
\DDTrace\install_hook('DDTrace\\ffe_evaluate', function ($hook) use (&$native) {
    $native[] = $hook->args[2];
});
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', function ($hook) use (&$submitted) {
    $submitted[] = $hook->args;
});
$client = new \DDTrace\FeatureFlags\Client(new \DDTrace\Log\NullLogger('debug'));
$subject = new FfeTargetingSubject();
$cases = array(
    array('string', 'subject', 'subject', 'subject', false),
    array('integer', 123, '123', null, true),
    array('boolean', false, '', null, true),
    array('object', $subject, 'subject', null, true),
    array('empty', '', '', '', false),
    array('null', null, null, null, false),
);
foreach ($cases as $i => $case) {
    list($label, $input, $evaluatedKey, $reportedKey, $invalid) = $case;
    $value = $client->getStringValue('missing', 'fallback', array('targetingKey' => $input));
    $args = $submitted[$i];
    echo $label, ': ';
    var_dump($value === 'fallback' && $native[$i] === $evaluatedKey
        && $args[3] === $reportedKey && isset($args[9]) && $args[9] === $invalid);
}
echo 'one conversion: '; var_dump($subject->conversions === 1);
?>
--EXPECT--
string: bool(true)
integer: bool(true)
boolean: bool(true)
object: bool(true)
empty: bool(true)
null: bool(true)
one conversion: bool(true)
