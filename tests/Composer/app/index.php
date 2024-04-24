<?php

namespace User\App;

use AutoloaderThatFails;
use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Log\ErrorLogLogger;
use DDTrace\Log\Logger;
use DDTrace\Tag;
use DDTrace\Tracer;
use DDTrace\Type;

\error_reporting(E_ALL ^ E_WARNING);

date_default_timezone_set("UTC");

$scenario = getenv('DD_COMPOSER_TEST_CONTEXT');

$touchPreloadFile = __DIR__ . '/touch.preload';
$preload = \file_exists($touchPreloadFile) ? \trim(\file_get_contents($touchPreloadFile)) : '';

switch ($_SERVER['REQUEST_URI']) {
    case '/':
    case '/manual-tracing':
        // Do composer autoload here, not at the root level, so we can ALSO test for non-composer scenarios.
        require __DIR__ . "/vendor/autoload.php";
        /*
        * Manual instrumentation using DD api
        */
        $tracer = GlobalTracer::get();
        $scope = $tracer->startActiveSpan('my_operation');
        $span = $scope->getSpan();
        $span->setResource('my_resource');
        // A tag from composer namespace
        $span->setTag(Tag::HTTP_METHOD, 'GET');
        // A type from composer namespace
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $scope->close();

        /*
        * Using Logger class which is defined in both 'src' AND 'api'
        */
        Logger::set(new ErrorLogLogger('debug'));
        Logger::get()->debug('some-debug-message');

        // Accessing tracer version, which was loaded from a physical file until 0.48.3.
        if (\class_exists('\DDTrace\Tracer')) {
            Tracer::version();
        }

        break;
    case '/no-manual-tracing':
        require __DIR__ . "/vendor/autoload.php";
        require __DIR__ . '/custom_autoloaders.php';
        (new AutoloaderThatFails())->register();
        break;
    case '/no-composer':
        break;
    case '/no-composer-autoload-fails':
        require __DIR__ . '/custom_autoloaders.php';
        (new AutoloaderThatFails())->register();
        break;
    case '/composer-autoload-fails':
        require __DIR__ . "/vendor/autoload.php";
        require __DIR__ . '/custom_autoloaders.php';
        (new AutoloaderThatFails())->register();
        break;
    default:
        \header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
        exit();
}

echo "OK - preload:'$preload'";
