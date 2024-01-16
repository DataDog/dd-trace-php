<?php

namespace DDTrace\Tests\Integrations\PHPRedis\V6;

use DDTrace\Tag;
use DDTrace\Integrations\PHPRedis\PHPRedisIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Exception;

// Note: PHPRedis 5 has many deprecated methodsd (compared to 4) that we still want to test
\error_reporting(E_ALL ^ \E_DEPRECATED);

class CustomRedisClass extends \Redis
{
}

class PHPRedisTest extends \DDTrace\Tests\Integrations\PHPRedis\V5\PHPRedisTest
{
}

