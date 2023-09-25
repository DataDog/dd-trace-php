<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\AdvancedSearch\Block;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Search\Model\QueryResult;
use Magento\AdvancedSearch\Model\SuggestedQueriesInterface;
use Magento\Framework\View\LayoutInterface;

/**
 * @magentoAppArea frontend
 */
class SuggestionsTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\AdvancedSearch\Block\Suggestions */
    protected $block;

    protected function setUp(): void
    {
        $suggestedQueries = $this->createMock(SuggestedQueriesInterface::CLASS);
        $suggestedQueries->expects($this->any())->method('getItems')->willReturn([
            new QueryResult('test item', 1),
            new QueryResult("<script>alert('Test');</script>", 1)
        ]);

        $this->block = Bootstrap::getObjectManager()->create(\Magento\AdvancedSearch\Block\Suggestions::class, [
            'searchDataProvider' => $suggestedQueries,
            'title' => 'title',
        ]);
    }

    public function testRenderEscaping()
    {
        $html = $this->block->toHtml();

        $this->assertStringContainsString('test+item', $html);
        $this->assertStringContainsString('test item', $html);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('%3Cscript%3Ealert%28%27Test%27%29%3B%3C%2Fscript%3E', $html);
        $this->assertStringContainsString("&lt;script&gt;alert(&#039;Test&#039;);&lt;/script&gt;", $html);
    }
}
