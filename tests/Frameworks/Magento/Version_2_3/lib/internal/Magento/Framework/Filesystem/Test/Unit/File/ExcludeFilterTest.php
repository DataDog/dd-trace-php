<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Filesystem\Test\Unit\File;

use Magento\Framework\Filesystem\Filter\ExcludeFilter;

/**
 * Class ExcludeFilterTest
 */
class ExcludeFilterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Iterator
     */
    protected $iterator;

    protected function setUp(): void
    {
        $this->iterator = $this->getFilesIterator();
    }

    public function testExclusion()
    {
        $iterator = new ExcludeFilter(
            $this->iterator,
            [
                BP . '/var/session/'
            ]
        );

        foreach ($iterator as $i) {
            $result[] = $i;
        }

        $this->assertTrue(!in_array(BP . '/var/session/', $result), 'Filtered path should not be in array');
    }

    /**
     * @return \Generator
     */
    private function getFilesIterator()
    {
        $files = [
            BP . '/var/',
            BP . '/var/session/',
            BP . '/var/cache/'
        ];

        foreach ($files as $file) {
            $item = $this->getMockBuilder(
                \SplFileInfoClass::class
            )->setMethods(['__toString', 'getFilename'])->getMock();
            $item->expects($this->any())->method('__toString')->willReturn($file);
            $item->expects($this->any())->method('getFilename')->willReturn('notDots');
            yield $item;
        }
    }
}
