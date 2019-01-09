<?php

namespace DDTrace\Tests;

use DDTrace\Bootstrap;
use DDTrace\Configuration;
use DDTrace\Integrations\IntegrationsLoader;
use PHPUnit\Framework\BaseTestListener;
use PHPUnit_Framework_Test;

class DDTestListener extends BaseTestListener
{
    public function startTest(PHPUnit_Framework_Test $test)
    {
        Bootstrap::resetTracer();
        Configuration::clear();
        IntegrationsLoader::get()->reset();
        IntegrationsLoader::load();
    }

    public function endTest(\PHPUnit_Framework_Test $test, $time)
    {
        Configuration::clear();
    }
}
