<?php

namespace DDTrace\Tests\Unit\Sampling;

use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tests\Common\BaseTestCase;

final class PrioritySamplingTest extends BaseTestCase
{
    public function testParseNull()
    {
        $this->assertSame(PrioritySampling::UNKNOWN, PrioritySampling::parse(null));
    }

    public function testParseUnknown()
    {
        $this->assertSame(PrioritySampling::UNKNOWN, PrioritySampling::parse('something'));
    }

    public function testParseKnownAsString()
    {
        $this->assertSame(PrioritySampling::AUTO_KEEP, PrioritySampling::parse('1'));
    }

    public function testParseKnownAsNumber()
    {
        $this->assertSame(PrioritySampling::AUTO_KEEP, PrioritySampling::parse(1));
    }
}
