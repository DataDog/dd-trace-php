<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Ui\DataProvider\Product\Form;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Ui\DataProvider\Product\Form\NewCategoryDataProvider;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\TestCase;

class NewCategoryDataProviderTest extends TestCase
{
    /**
     * @var NewCategoryDataProvider
     */
    protected $newCategoryDataProvider;

    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactoryMock;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->collectionFactoryMock = $this->createPartialMock(CollectionFactory::class, ['create']);
        $this->newCategoryDataProvider = $this->objectManagerHelper->getObject(
            NewCategoryDataProvider::class,
            ['collectionFactory' => $this->collectionFactoryMock]
        );
    }

    public function testGetData()
    {
        $this->assertArrayHasKey('config', $this->newCategoryDataProvider->getData());
    }

    public function testGetMeta()
    {
        $this->assertArrayHasKey('data', $this->newCategoryDataProvider->getMeta());
    }
}
