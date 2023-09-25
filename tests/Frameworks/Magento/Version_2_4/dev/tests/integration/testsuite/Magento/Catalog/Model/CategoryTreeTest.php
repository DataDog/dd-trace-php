<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model;

/**
 * Test class for \Magento\Catalog\Model\Category.
 * - tree knowledge is tested
 *
 * @see \Magento\Catalog\Model\CategoryTest
 * @magentoDataFixture Magento/Catalog/_files/categories.php
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CategoryTreeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Category
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Category::class
        );
    }

    /**
     * Load category
     *
     * @param $categoryId
     * @return Category
     */
    protected function loadCategory($categoryId)
    {
        $this->_model->setData([]);
        $this->_model->load($categoryId);
        return $this->_model;
    }

    public function testMovePosition()
    {
        //move category 9 to new parent 6 with afterCategoryId = null
        $category = $this->loadCategory(9);
        $category->move(6, null);
        $category = $this->loadCategory(9);
        $this->assertEquals(1, $category->getPosition(), 'Position must be 1, if $afterCategoryId was null|false|0');
        $category = $this->loadCategory(10);
        $this->assertEquals(5, $category->getPosition(), 'Category 10 position must decrease after Category 9 moved');
        $category = $this->loadCategory(11);
        $this->assertEquals(6, $category->getPosition(), 'Category 11 position must decrease after Category 9 moved');
        $category = $this->loadCategory(6);
        $this->assertEquals(2, $category->getPosition(), 'Category 6 position must be the same');

        //move category 11 to new parent 6 with afterCategoryId = 9
        $category = $this->loadCategory(11);
        $category->move(6, 9);
        $category = $this->loadCategory(11);
        $this->assertEquals(2, $category->getPosition(), 'Category 11 position must be after category 9');
        $category = $this->loadCategory(10);
        $this->assertEquals(5, $category->getPosition(), 'Category 10 position must be the same');
        $category = $this->loadCategory(9);
        $this->assertEquals(1, $category->getPosition(), 'Category 9 position must be 1');
    }

    public function testMove()
    {
        $this->_model->load(7);
        $this->assertEquals(2, $this->_model->getParentId());
        $this->_model->move(6, 0);
        /* load is not enough to reset category data */
        $this->_model->setData([]);
        $this->_model->load(7);
        $this->assertEquals(6, $this->_model->getParentId());
    }

    /**
     */
    public function testMoveWrongParent()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->_model->load(7);
        $this->_model->move(100, 0);
    }

    /**
     */
    public function testMoveWrongId()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->_model->move(100, 0);
    }

    /**
     * @magentoDataFixture Magento/CatalogUrlRewrite/_files/categories.php
     * @magentoAppIsolation enabled
     */
    public function testGetUrlPath()
    {
        $this->assertNull($this->_model->getUrlPath());
        $this->_model->load(4);
        $this->assertEquals('category-1/category-1-1', $this->_model->getUrlPath());
    }

    public function testGetParentId()
    {
        $this->assertEquals(0, $this->_model->getParentId());
        $this->_model->unsetData();
        $this->_model->load(4);
        $this->assertEquals(3, $this->_model->getParentId());
    }

    public function testGetParentIds()
    {
        $this->assertEmpty($this->_model->getParentIds());
        $this->_model->unsetData();
        $this->_model->load(4);
        $this->assertContainsEquals(3, $this->_model->getParentIds());
        $this->assertNotContainsEquals(4, $this->_model->getParentIds());
    }

    public function testGetChildren()
    {
        $this->_model->load(3);
        $this->assertEquals(array_diff([4, 13], explode(',', $this->_model->getChildren())), []);
    }

    public function testGetChildrenSorted()
    {
        $this->_model->load(2);
        $unsorted = explode(',', $this->_model->getChildren());
        sort($unsorted);
        $this->assertEquals(array_diff($unsorted, explode(',', $this->_model->getChildren(true, true, true))), []);
    }

    public function testGetPathInStore()
    {
        $this->_model->load(5);
        $this->assertEquals('5,4,3', $this->_model->getPathInStore());
    }

    public function testGetAllChildren()
    {
        $this->_model->load(4);
        $this->assertEquals('4,5', $this->_model->getAllChildren());
        $this->_model->load(5);
        $this->assertEquals('5', $this->_model->getAllChildren());
    }

    public function testGetPathIds()
    {
        $this->assertEquals([''], $this->_model->getPathIds());
        $this->_model->setPathIds([1]);
        $this->assertEquals([1], $this->_model->getPathIds());

        $this->_model->unsetData();
        $this->_model->setPath('1/2/3');
        $this->assertEquals([1, 2, 3], $this->_model->getPathIds());
    }

    public function testGetLevel()
    {
        $this->assertEquals(0, $this->_model->getLevel());
        $this->_model->setData('level', 1);
        $this->assertEquals(1, $this->_model->getLevel());
    }

    public function testGetAnchorsAbove()
    {
        $this->_model->load(4);
        $this->assertContainsEquals(3, $this->_model->getAnchorsAbove());
        $this->_model->load(5);
        $this->assertContainsEquals(4, $this->_model->getAnchorsAbove());
    }

    public function testGetParentCategories()
    {
        $this->_model->load(5);
        $parents = $this->_model->getParentCategories();
        $this->assertCount(3, $parents);
    }

    public function testGetParentCategoriesEmpty()
    {
        $this->_model->load(1);
        $parents = $this->_model->getParentCategories();
        $this->assertCount(0, $parents);
    }

    public function testGetChildrenCategories()
    {
        $this->_model->load(3);
        $children = $this->_model->getChildrenCategories();
        $this->assertCount(2, $children);
    }

    public function testGetChildrenCategoriesEmpty()
    {
        $this->_model->load(5);
        $children = $this->_model->getChildrenCategories();
        $this->assertCount(0, $children);
    }

    public function testGetParentDesignCategory()
    {
        $this->_model->load(5);
        $parent = $this->_model->getParentDesignCategory();
        $this->assertEquals(5, $parent->getId());
    }

    public function testIsInRootCategoryList()
    {
        $this->assertFalse($this->_model->isInRootCategoryList());
        $this->_model->unsetData();
        $this->_model->load(3);
        $this->assertTrue($this->_model->isInRootCategoryList());
    }
}
