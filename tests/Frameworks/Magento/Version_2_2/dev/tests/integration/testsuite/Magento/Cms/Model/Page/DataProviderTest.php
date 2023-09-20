<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Cms\Model\Page;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Cms\Model\CustomLayoutManager;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Magento\Cms\Model\Page as PageModel;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Test pages data provider.
 *
 * @magentoAppArea adminhtml
 */
class DataProviderTest extends TestCase
{
    /**
     * @var DataProvider
     */
    private $provider;

    /**
     * @var PageRepositoryInterface
     */
    private $repo;

    /**
     * @var CustomLayoutManager
     */
    private $filesFaker;

    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * Find page by identifier.
     *
     * @param string $identifier
     * @return PageInterface
     * @throws NoSuchEntityException
     */
    private function findPage(string $identifier): PageInterface
    {
        /** @var SearchCriteriaBuilder $criteria */
        $criteria = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class);
        $criteria->addFilter(PageInterface::IDENTIFIER, $identifier);
        $criteria->setPageSize(1);

        $results = $this->repo->getList($criteria->create())->getItems();
        if (!$results) {
            throw NoSuchEntityException::singleField(PageInterface::IDENTIFIER, $identifier);
        }

        return array_pop($results);
    }

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->repo = $objectManager->get(PageRepositoryInterface::class);
        $this->filesFaker = $objectManager->get(CustomLayoutManager::class);
        $this->request = $objectManager->get(HttpRequest::class);
        $this->provider = $objectManager->create(
            DataProvider::class,
            [
                'name' => 'test',
                'primaryFieldName' => 'page_id',
                'requestFieldName' => 'page_id',
                'customLayoutManager' => $this->filesFaker
            ]
        );
    }

    /**
     * Check that custom layout date is handled properly.
     *
     * @magentoDataFixture Magento/Cms/_files/pages_with_layout_xml.php
     * @throws \Throwable
     * @return void
     */
    public function testCustomLayoutData()
    {
        $data = $this->provider->getData();
        $page1Data = null;
        $page2Data = null;
        $page3Data = null;
        foreach ($data as $pageData) {
            if ($pageData[PageModel::IDENTIFIER] === 'test_custom_layout_page_1') {
                $page1Data = $pageData;
            } elseif ($pageData[PageModel::IDENTIFIER] === 'test_custom_layout_page_2') {
                $page2Data = $pageData;
            } elseif ($pageData[PageModel::IDENTIFIER] === 'test_custom_layout_page_3') {
                $page3Data = $pageData;
            }
        }
        $this->assertNotEmpty($page1Data);
        $this->assertNotEmpty($page2Data);
        $this->assertEquals('_existing_', $page1Data['layout_update_selected']);
        $this->assertEquals(null, $page2Data['layout_update_selected']);
        $this->assertEquals('test_selected', $page3Data['layout_update_selected']);
    }

    /**
     * Check that proper meta for custom layout field is returned.
     *
     * @return void
     * @throws \Throwable
     * @magentoDataFixture Magento/Cms/_files/pages_with_layout_xml.php
     */
    public function testCustomLayoutMeta()
    {
        //Testing a page without layout xml
        $page = $this->findPage('test_custom_layout_page_3');
        $this->filesFaker->fakeAvailableFiles((int)$page->getId(), ['test1', 'test2']);
        $this->request->setParam('page_id', $page->getId());

        $meta = $this->provider->getMeta();
        $this->assertArrayHasKey('design', $meta);
        $this->assertArrayHasKey('children', $meta['design']);
        $this->assertArrayHasKey('custom_layout_update_select', $meta['design']['children']);
        $this->assertArrayHasKey('arguments', $meta['design']['children']['custom_layout_update_select']);
        $this->assertArrayHasKey('data', $meta['design']['children']['custom_layout_update_select']['arguments']);
        $this->assertArrayHasKey(
            'options',
            $meta['design']['children']['custom_layout_update_select']['arguments']['data']
        );
        $expectedList = [
            ['label' => 'No update', 'value' => '_no_update_'],
            ['label' => 'test1', 'value' => 'test1'],
            ['label' => 'test2', 'value' => 'test2']
        ];
        $metaList = $meta['design']['children']['custom_layout_update_select']['arguments']['data']['options'];
        sort($expectedList);
        sort($metaList);
        $this->assertEquals($expectedList, $metaList);

        //Page with old layout xml
        $page = $this->findPage('test_custom_layout_page_1');
        $this->filesFaker->fakeAvailableFiles((int)$page->getId(), ['test3']);
        $this->request->setParam('page_id', $page->getId());

        $meta = $this->provider->getMeta();
        $this->assertArrayHasKey('design', $meta);
        $this->assertArrayHasKey('children', $meta['design']);
        $this->assertArrayHasKey('custom_layout_update_select', $meta['design']['children']);
        $this->assertArrayHasKey('arguments', $meta['design']['children']['custom_layout_update_select']);
        $this->assertArrayHasKey('data', $meta['design']['children']['custom_layout_update_select']['arguments']);
        $this->assertArrayHasKey(
            'options',
            $meta['design']['children']['custom_layout_update_select']['arguments']['data']
        );
        $expectedList = [
            ['label' => 'No update', 'value' => '_no_update_'],
            ['label' => 'Use existing layout update XML', 'value' => '_existing_'],
            ['label' => 'test3', 'value' => 'test3'],
        ];
        $metaList = $meta['design']['children']['custom_layout_update_select']['arguments']['data']['options'];
        sort($expectedList);
        sort($metaList);
        $this->assertEquals($expectedList, $metaList);
    }
}
