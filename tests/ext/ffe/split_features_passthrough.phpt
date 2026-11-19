--TEST--
FFE native bridge hands the selected split's features to PHP
--FILE--
<?php
$config = json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => array(
        'with.features' => array(
            'key' => 'with.features', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
            'allocations' => array(array(
                'key' => 'allocation', 'rules' => array(), 'doLog' => true,
                'splits' => array(array('variationKey' => 'on', 'shards' => array(), 'features' => array(
                    array('key' => 'holdout.key', 'value' => 'h1', 'destinations' => array('HOOK', 'EXPOSURE')),
                    array('key' => 'weight', 'value' => 2, 'destinations' => array('EVALUATION', 'FUTURE')),
                    array('key' => 'included', 'value' => false, 'destinations' => array('HOOK')),
                    array('key' => 'holdout.key', 'value' => 'second', 'destinations' => array('HOOK')),
                    array('key' => 'object', 'value' => array('a' => 1), 'destinations' => array('HOOK')),
                ))),
            )),
        ),
        'malformed.features' => array(
            'key' => 'malformed.features', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
            'allocations' => array(array(
                'key' => 'allocation', 'rules' => array(), 'doLog' => true,
                'splits' => array(array('variationKey' => 'on', 'shards' => array(), 'features' => 'not-a-list')),
            )),
        ),
        'without.features' => array(
            'key' => 'without.features', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
            'allocations' => array(array(
                'key' => 'allocation', 'rules' => array(), 'doLog' => true,
                'splits' => array(array('variationKey' => 'on', 'shards' => array())),
            )),
        ),
    ),
));
var_dump(DDTrace\Testing\ffe_load_config($config));
$result = DDTrace\ffe_evaluate('with.features', DDTrace\FFE_STRING, 'user', array());
echo json_encode($result->features, JSON_PRESERVE_ZERO_FRACTION), "\n";
var_dump(is_float($result->features[1]['value']));
echo json_encode(DDTrace\ffe_evaluate('malformed.features', DDTrace\FFE_STRING, 'user', array())->features), "\n";
echo json_encode(DDTrace\ffe_evaluate('without.features', DDTrace\FFE_STRING, 'user', array())->features), "\n";
echo json_encode(DDTrace\ffe_evaluate('with.features', DDTrace\FFE_BOOL, 'user', array())->features), "\n";
?>
--EXPECT--
bool(true)
[{"key":"holdout.key","value":"h1","destinations":["HOOK","EXPOSURE"]},{"key":"weight","value":2.0,"destinations":["EVALUATION","FUTURE"]},{"key":"included","value":false,"destinations":["HOOK"]}]
bool(true)
[]
[]
[]
