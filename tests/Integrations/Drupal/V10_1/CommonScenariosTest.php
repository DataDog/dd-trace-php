<?php

namespace DDTrace\Tests\Integrations\Drupal\V10_1;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Drupal\V8_9\CommonScenariosTest
{
    public static $database = "drupal101";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Drupal/Version_10_1/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_drupal_101']);
    }
}
