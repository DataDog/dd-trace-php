<?php
require __DIR__ . '/flag_evaluation_openfeature.inc';
$api = \OpenFeature\OpenFeatureAPI::getInstance();
$api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
$parent = getmypid();
$api->addHooks(new \DDTrace\OpenFeature\ExposureHook(function ($event) use ($parent) {
    echo json_encode([getmypid() === $parent ? 'parent' : 'child', $event->isExposure(), $event->getFlagKey(),
        $event->getTargetingKey(), $event->getAllocationKey(), $event->getVariant(), $event->getSplitSerialId(), $event->getFeatures()]), "\n";
}));
$openFeatureClient = $api->getClient();
require dirname(__DIR__, 2) . '/ext/ffe/stubs/exposure_cross_process_app.php';
