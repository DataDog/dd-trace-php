<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Internal\SplitFeatures;
use DDTrace\Log\Logger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Tests\Common\BaseTestCase;

final class SplitFeaturesTest extends BaseTestCase
{
    private $logger;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        SplitFeatures::resetWarnedDestinations();
        $this->logger = new SplitFeaturesRecordingLogger();
        Logger::set($this->logger);
    }

    protected function ddTearDown()
    {
        SplitFeatures::resetWarnedDestinations();
        parent::ddTearDown();
    }

    public function testRoutesEachFeatureToTheMetadataPrefixOfEachDestination()
    {
        $this->assertSame(array(
            '__dd_feature.holdout.key' => 'h1',
            '__dd_exposure_feature.holdout.key' => 'h1',
            '__dd_evaluation_feature.weight' => 0.5,
            '__dd_feature.included' => false,
            '__dd_exposure_feature.count' => 3.0,
        ), SplitFeatures::toMetadata(array(
            array('key' => 'holdout.key', 'value' => 'h1', 'destinations' => array('HOOK', 'EXPOSURE')),
            array('key' => 'weight', 'value' => 0.5, 'destinations' => array('EVALUATION')),
            array('key' => 'included', 'value' => false, 'destinations' => array('HOOK')),
            array('key' => 'count', 'value' => 3, 'destinations' => array('EXPOSURE')),
        )));
    }

    public function testSkipsMalformedFeatures()
    {
        $this->assertSame(array(), SplitFeatures::toMetadata(null));
        $this->assertSame(array(), SplitFeatures::toMetadata('features'));
        $this->assertSame(array('__dd_feature.ok' => 'yes'), SplitFeatures::toMetadata(array(
            'not-an-entry',
            array('value' => 'v', 'destinations' => array('HOOK')),
            array('key' => '', 'value' => 'v', 'destinations' => array('HOOK')),
            array('key' => 'array.value', 'value' => array(1), 'destinations' => array('HOOK')),
            array('key' => 'null.value', 'value' => null, 'destinations' => array('HOOK')),
            array('key' => 'no.value', 'destinations' => array('HOOK')),
            array('key' => 'scalar.destinations', 'value' => 'v', 'destinations' => 'HOOK'),
            array('key' => 'ok', 'value' => 'yes', 'destinations' => array('HOOK', 7)),
        )));
    }

    public function testFirstFeatureWinsForEveryDestination()
    {
        $this->assertSame(array('__dd_feature.key' => 'first'), SplitFeatures::toMetadata(array(
            array('key' => 'key', 'value' => array(), 'destinations' => array('EXPOSURE')),
            array('key' => 'key', 'value' => 'first', 'destinations' => array('HOOK')),
            array('key' => 'key', 'value' => 'second', 'destinations' => array('HOOK', 'EXPOSURE')),
        )));
    }

    public function testUnknownDestinationsAreIgnoredAndWarnedOnce()
    {
        $metadata = SplitFeatures::toMetadata(array(
            array('key' => 'a', 'value' => 'v', 'destinations' => array('FUTURE', 'hook')),
            array('key' => 'b', 'value' => 'v', 'destinations' => array('FUTURE', 'HOOK')),
        ));
        SplitFeatures::toMetadata(array(array('key' => 'c', 'value' => 'v', 'destinations' => array('FUTURE'))));

        $this->assertSame(array('__dd_feature.b' => 'v'), $metadata);
        $this->assertCount(2, $this->logger->warnings);
        $this->assertSame('FUTURE', $this->logger->warnings[0]['destination']);
        $this->assertSame('hook', $this->logger->warnings[1]['destination']);
    }

    public function testWarnedDestinationsAreCapped()
    {
        for ($i = 0; $i < SplitFeatures::WARNED_DESTINATIONS_LIMIT + 5; $i++) {
            SplitFeatures::toMetadata(array(array('key' => 'k', 'value' => 'v', 'destinations' => array('D' . $i))));
        }

        $this->assertCount(SplitFeatures::WARNED_DESTINATIONS_LIMIT, $this->logger->warnings);
    }

    public function testReadsFeaturesBackByPrefix()
    {
        $this->assertSame(array('holdout.key' => 'h1', 'n' => 1.0), SplitFeatures::withPrefix(array(
            '__dd_feature.holdout.key' => 'h1',
            '__dd_exposure_feature.other' => 'x',
            '__dd_feature.' => 'empty key',
            '__dd_feature.n' => 1.0,
            '__dd_do_log' => true,
        ), SplitFeatures::HOOK_PREFIX));
    }
}

final class SplitFeaturesRecordingLogger implements LoggerInterface
{
    public $warnings = array();

    public function debug($message, array $context = array())
    {
    }

    public function warning($message, array $context = array())
    {
        $this->warnings[] = $context;
    }

    public function error($message, array $context = array())
    {
    }

    public function isLevelActive($level)
    {
        return true;
    }
}
