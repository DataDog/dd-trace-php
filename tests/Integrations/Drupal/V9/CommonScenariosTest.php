<?php

namespace DDTrace\Tests\Integrations\Drupal\V9;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Drupal\V8\CommonScenariosTest
{
    public static $database = "drupal9";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Drupal/Version_9/web/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_drupal_9']);
    }
}
