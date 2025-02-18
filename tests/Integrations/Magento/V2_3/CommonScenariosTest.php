<?php

namespace DDTrace\Tests\Integrations\Magento\V2_3;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Magento\V2_4\CommonScenariosTest
{
    public static $database = "magento23";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Magento/Version_2_3/pub/index.php';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '2.3.7';
    }
}
