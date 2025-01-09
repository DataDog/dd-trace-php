<?php

namespace DDTrace\Tests\Integrations\Drupal\V10;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Drupal\V8\CommonScenariosTest
{
    public static $database = "drupal10";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Drupal/Version_10/web/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_drupal_10']);
    }
}
