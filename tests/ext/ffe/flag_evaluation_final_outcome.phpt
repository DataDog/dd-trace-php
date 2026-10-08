--TEST--
FFE counts capture the final mapped outcome once, independently of OTLP metrics
--ENV--
DD_METRICS_OTEL_ENABLED=false
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
$root = getenv('TEST_PHP_SRCDIR') ?: dirname(dirname(dirname(__DIR__)));
spl_autoload_register(function ($class) use ($root) {
    if (strpos($class, 'DDTrace\\') === 0) {
        $path = $root . '/src/api/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

$calls = array();
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', function ($hook) use (&$calls) {
    $calls[] = $hook->args;
});
$config = array(
    'createdAt' => '2026-05-22T00:00:00Z',
    'environment' => array('name' => 'test'),
    'observeFullEvaluationData' => true,
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('blue' => array('key' => 'blue', 'value' => 'blue')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => false,
            'splits' => array(array('variationKey' => 'blue', 'serialId' => 0, 'shards' => array())),
        )),
    )),
);
var_dump(\DDTrace\Testing\ffe_load_config(json_encode($config)));
$client = new \DDTrace\FeatureFlags\Client();
$context = array('targetingKey' => 'subject', 'attributes' => array('plan' => 'pro'));
var_dump($client->getStringValue('flag', 'fallback', $context));
var_dump($client->getBooleanValue('flag', false, $context));
var_dump($client->getStringValue('missing', 'fallback', $context));

// Simulate a config reload after assignment and a PHP mapping failure.
// The record must keep the assignment's consent and the mapper's final error.
$config['observeFullEvaluationData'] = false;
$reload = \DDTrace\install_hook('DDTrace\\ffe_evaluate', null, function ($hook) use ($config) {
    $hook->returned->valueJson = '{invalid-json';
    \DDTrace\Testing\ffe_load_config(json_encode($config));
});
var_dump($client->getStringValue('flag', 'fallback', $context));
\DDTrace\remove_hook($reload);

// This is the evaluator mode used by OpenFeature: its own metric hook owns OTLP.
$evaluator = \DDTrace\FeatureFlags\Internal\NativeEvaluator::create(false);
var_dump($evaluator->evaluate('flag', \DDTrace\FeatureFlags\EvaluationType::STRING,
    'fallback', 'subject', array('plan' => 'pro'))->getValue());
echo json_encode($calls, JSON_UNESCAPED_SLASHES), "\n";
?>
--EXPECT--
bool(true)
string(4) "blue"
bool(false)
string(8) "fallback"
string(8) "fallback"
string(4) "blue"
[["flag","blue","allocation","subject",{"plan":"pro"},null,false,true],["flag",null,null,"subject",{"plan":"pro"},"TYPE_MISMATCH",true,true],["missing",null,null,"subject",{"plan":"pro"},"FLAG_NOT_FOUND",true,true],["flag",null,null,"subject",{"plan":"pro"},"PARSE_ERROR",true,true],["flag","blue","allocation","subject",{"plan":"pro"},null,false,false]]
