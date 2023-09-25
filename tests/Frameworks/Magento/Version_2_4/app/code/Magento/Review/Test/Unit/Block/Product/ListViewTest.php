<?php declare(strict_types=1);
/**
 * Test class for \Magento\Review\Block\Product\View\ListView
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Review\Test\Unit\Block\Product;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Review\Block\Product\View\ListView;
use PHPUnit\Framework\TestCase;

class ListViewTest extends TestCase
{
    /**
     * @var ListView
     */
    private $listView;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->listView = $this->objectManager->getObject(
            ListView::class
        );
    }

    /**
     * Validate that ListView->toHtml() would not crush if provided product is null
     */
    public function testBlockShouldNotFailWithNullProduct()
    {
        $output = $this->listView->toHtml();
        $this->assertEquals('', $output);
    }
}
