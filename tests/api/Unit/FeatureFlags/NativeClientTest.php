<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Client;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\Log\LogLevel;
use DDTrace\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class NativeClientTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testObjectEvaluationsReturnDefaultsForScalarJson()
    {
        $values = array(
            'json-int' => 42,
            'json-string' => 'text',
            'json-null' => null,
            'json-object' => array('a' => 1),
            'json-list' => array(1, 2),
        );
        $flags = array();
        foreach ($values as $key => $value) {
            $flags[$key] = array(
                'key' => $key, 'enabled' => true, 'variationType' => 'JSON',
                'variations' => array('on' => array('key' => 'on', 'value' => $value)),
                'allocations' => array(array(
                    'key' => 'allocation', 'rules' => array(),
                    'splits' => array(array('variationKey' => 'on', 'shards' => array())),
                    'doLog' => true,
                )),
            );
        }
        $this->assertTrue(\DDTrace\Testing\ffe_load_config(json_encode(array(
            'createdAt' => '2024-01-01T00:00:00Z',
            'environment' => array('name' => 'test'),
            'flags' => $flags,
        ))));

        $client = new Client(new NullLogger(LogLevel::EMERGENCY));
        $default = array('default' => true);
        foreach ($values as $key => $value) {
            $details = $client->getObjectDetails($key, $default);
            if (is_array($value)) {
                $this->assertSame($value, $details->getValue(), $key);
                $this->assertSame(EvaluationReason::STATIC_REASON, $details->getReason(), $key);
                $this->assertNull($details->getErrorCode(), $key);
                $this->assertSame('on', $details->getVariant(), $key);
            } else {
                $this->assertSame($default, $details->getValue(), $key);
                $this->assertSame(EvaluationReason::ERROR, $details->getReason(), $key);
                $this->assertSame(EvaluationErrorCode::TYPE_MISMATCH, $details->getErrorCode(), $key);
                $this->assertNull($details->getVariant(), $key);
            }
        }
    }
}
