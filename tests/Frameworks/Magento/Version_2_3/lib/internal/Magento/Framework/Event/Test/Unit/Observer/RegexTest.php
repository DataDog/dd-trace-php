<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Event\Test\Unit\Observer;

use \Magento\Framework\Event\Observer\Regex;

/**
 * Class RegexTest
 */
class RegexTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Regex
     */
    protected $regex;

    protected function setUp(): void
    {
        $this->regex = new Regex();
    }

    protected function tearDown(): void
    {
        $this->regex = null;
    }

    /**
     * @dataProvider isValidForProvider
     * @param string $pattern
     * @param string $name
     * @param bool $expectedResult
     */
    public function testIsValidFor($pattern, $name, $expectedResult)
    {
        $this->regex->setEventRegex($pattern);
        $eventMock = $this->createMock(\Magento\Framework\Event::class);
        $eventMock->expects($this->any())
            ->method('getName')
            ->willReturn($name);

        $this->assertEquals($expectedResult, $this->regex->isValidFor($eventMock));
    }

    /**
     * @return array
     */
    public function isValidForProvider()
    {
        return [
            ['~_name$~', 'event_name', true],
            ['~_names$~', 'event_name', false]
        ];
    }
}
