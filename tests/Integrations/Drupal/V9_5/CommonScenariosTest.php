<?php

namespace DDTrace\Tests\Integrations\Drupal\V9_5;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Drupal\V8_9\CommonScenariosTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Drupal/Version_9_5/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_drupal_95']);
    }
}
