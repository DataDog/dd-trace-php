<?php

namespace DDTrace\Tests;

use DDTrace\Configuration;
use PHPUnit\Framework\BaseTestListener;
use PHPUnit_Framework_Test;

class DDTestListener extends BaseTestListener
{
    public function startTest(PHPUnit_Framework_Test $test)
    {
        Configuration::clear();
    }

    public function endTest(\PHPUnit_Framework_Test $test, $time)
    {
        Configuration::clear();
    }
}
