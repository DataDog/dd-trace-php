<?php

namespace DDTrace\Tests\Integrations\Logs\MonologV3;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Integrations\Logs\MonologV1\MonologV1Test;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

use function DDTrace\close_span;
use function DDTrace\set_distributed_tracing_context;
use function DDTrace\start_span;

class MonologV3Test extends MonologV1Test
{
}
