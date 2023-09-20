<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Test\Legacy\Magento\Framework\ObjectManager;

class DiConfigTest extends \PHPUnit\Framework\TestCase
{
    public function testObsoleteDiFormat()
    {
        $invoker = new \Magento\Framework\App\Utility\AggregateInvoker($this);
        $invoker(
            [$this, 'assertObsoleteFormat'],
            \Magento\Framework\App\Utility\Files::init()->getDiConfigs(true)
        );
    }

    /**
     * Scan the specified di.xml file and assert that it has no obsolete nodes
     *
     * @param string $file
     */
    public function assertObsoleteFormat($file)
    {
        $xml = simplexml_load_file($file);
        $this->assertSame(
            [],
            $xml->xpath('//param'),
            'The <param> node is obsolete. Instead, use the <argument name="..." xsi:type="...">'
        );
        $this->assertSame(
            [],
            $xml->xpath('//instance'),
            'The <instance> node is obsolete. Instead, use the <argument name="..." xsi:type="object">'
        );
        $this->assertSame(
            [],
            $xml->xpath('//array'),
            'The <array> node is obsolete. Instead, use the <argument name="..." xsi:type="array">'
        );
        $this->assertSame(
            [],
            $xml->xpath('//item[@key]'),
            'The <item key="..."> node is obsolete. Instead, use the <item name="..." xsi:type="...">'
        );
        $this->assertSame(
            [],
            $xml->xpath('//value'),
            'The <value> node is obsolete. Instead, provide the actual value as a text literal.'
        );
    }

    public function testCommandListClassIsNotDirectlyConfigured()
    {
        $invoker = new \Magento\Framework\App\Utility\AggregateInvoker($this);
        $invoker(
            [$this, 'assertCommandListClassIsNotDirectlyConfigured'],
            \Magento\Framework\App\Utility\Files::init()->getDiConfigs(true)
        );
    }

    /**
     * Scan the specified di.xml file and assert that it has no directly configured CommandList class
     *
     * @param string $file
     */
    public function assertCommandListClassIsNotDirectlyConfigured($file)
    {
        $xml = simplexml_load_file($file);
        foreach ($xml->xpath('//type') as $type) {
            $this->assertNotContains(
                \Magento\Framework\Console\CommandList::class,
                $type->attributes(),
                'Use \Magento\Framework\Console\CommandListInterface instead of \Magento\Framework\Console\CommandList'
            );
        }
    }
}
