<?php

namespace DDTrace\Tests\Integrations\Laravel\Latest;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Integrations\Laravel\APIGWTestSuite;


class APIGWTest extends APIGWTestSuite
{
    public static $database = "laravel11";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Latest/public/index.php';
    }
}