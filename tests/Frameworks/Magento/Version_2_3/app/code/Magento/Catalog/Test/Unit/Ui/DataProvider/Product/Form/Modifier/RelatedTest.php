<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related;

/**
 * Class RelatedTest
 */
class RelatedTest extends AbstractModifierTest
{
    /**
     * @return \Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related
     */
    protected function createModel()
    {
        return $this->objectManager->getObject(\Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related::class, [
            'locator' => $this->locatorMock,
        ]);
    }

    /**
     * @return void
     */
    public function testModifyMeta()
    {
        $this->assertArrayHasKey(Related::DATA_SCOPE_RELATED, $this->getModel()->modifyMeta([]));
    }

    /**
     * @return void
     */
    public function testModifyData()
    {
        $data = $this->getSampleData();

        $this->assertSame($data, $this->getModel()->modifyData($data));
    }
}
