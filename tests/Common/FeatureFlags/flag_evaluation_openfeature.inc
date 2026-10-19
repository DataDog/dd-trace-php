<?php
require __DIR__ . '/ffe_api_bootstrap.inc';
require_once $root . '/tests/OpenFeature/vendor/autoload.php';

function ffeOutcomeConfig($consent = true) {
    $config = array('createdAt' => '2026-05-22T00:00:00Z',
        'environment' => array('name' => 'test'),
        'observeFullEvaluationData' => $consent, 'flags' => array());
    foreach (array('flag', 'inner') as $key) {
        $config['flags'][$key] = array('key' => $key, 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('blue' => array('key' => 'blue', 'value' => 'blue')),
            'allocations' => array(array('key' => 'allocation', 'rules' => array(), 'doLog' => false,
                'splits' => array(array('variationKey' => 'blue', 'serialId' => 1, 'shards' => array())))));
    }
    return json_encode($config);
}

class FfeOutcomeHook extends \OpenFeature\implementation\hooks\StringHook {
    public $onBefore;
    public $onAfter;
    public $onError;
    public $onFinally;
    public function before(\OpenFeature\interfaces\hooks\HookContext $context,
        \OpenFeature\interfaces\hooks\HookHints $hints): ?\OpenFeature\interfaces\flags\EvaluationContext {
        return $this->onBefore ? ($this->onBefore)($context) : null;
    }
    public function after(\OpenFeature\interfaces\hooks\HookContext $context,
        \OpenFeature\interfaces\provider\ResolutionDetails $details,
        \OpenFeature\interfaces\hooks\HookHints $hints): void {
        if ($this->onAfter) ($this->onAfter)($context, $details);
    }
    public function error(\OpenFeature\interfaces\hooks\HookContext $context, \Throwable $error,
        \OpenFeature\interfaces\hooks\HookHints $hints): void {
        if ($this->onError) ($this->onError)();
    }
    public function finally(\OpenFeature\interfaces\hooks\HookContext $context,
        \OpenFeature\interfaces\hooks\HookHints $hints): void {
        if ($this->onFinally) ($this->onFinally)();
    }
}

$ffeRecords = array();
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', function ($hook) use (&$ffeRecords) {
    $ffeRecords[] = $hook->args;
});
function ffeOutcomeClient($provider, $hook = null) {
    $api = \OpenFeature\OpenFeatureAPI::getInstance();
    $api->setProvider($provider);
    $client = $api->getClient('ffe-terminal-outcome');
    if ($hook) $client->addHooks($hook);
    return $client;
}
