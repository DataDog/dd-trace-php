<?php

namespace DDTrace\Tests\Integrations\Yii\V2_0_49;

class ParameterizedRouteTest extends \DDTrace\Tests\Integrations\Yii\Latest\ParameterizedRouteTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Yii/Version_2_0_49/web/index.php';
    }
}
