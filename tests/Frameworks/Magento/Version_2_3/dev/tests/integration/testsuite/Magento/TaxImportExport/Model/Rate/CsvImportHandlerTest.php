<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\TaxImportExport\Model\Rate;

class CsvImportHandlerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\TaxImportExport\Model\Rate\CsvImportHandler
     */
    protected $_importHandler;

    protected function setUp(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->_importHandler = $objectManager->create(\Magento\TaxImportExport\Model\Rate\CsvImportHandler::class);
    }

    protected function tearDown(): void
    {
        $this->_importHandler = null;
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testImportFromCsvFileWithCorrectData()
    {
        $importFileName = __DIR__ . '/_files/correct_rates_import_file.csv';
        $this->_importHandler->importFromCsvFile(['tmp_name' => $importFileName]);

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        // assert that both tax rates, specified in import file, have been imported correctly
        $importedRuleCA = $objectManager->create(
            \Magento\Tax\Model\Calculation\Rate::class
        )->loadByCode(
            'US-CA-*-Rate Import Test'
        );
        $this->assertNotEmpty($importedRuleCA->getId());
        $this->assertEquals(8.25, (double)$importedRuleCA->getRate());
        $this->assertEquals('US', $importedRuleCA->getTaxCountryId());
        $this->assertEquals('*', $importedRuleCA->getTaxPostcode());

        $importedRuleFL = $objectManager->create(
            \Magento\Tax\Model\Calculation\Rate::class
        )->loadByCode(
            'US-FL-*-Rate Import Test'
        );
        $this->assertNotEmpty($importedRuleFL->getId());
        $this->assertEquals(15, (double)$importedRuleFL->getRate());
        $this->assertEquals('US', $importedRuleFL->getTaxCountryId());
        $this->assertNull($importedRuleFL->getTaxPostcode());
    }

    /**
     * @magentoDbIsolation enabled
     *
     */
    public function testImportFromCsvFileThrowsExceptionWhenCountryCodeIsInvalid()
    {
        $this->expectExceptionMessage("Country code is invalid: ZZ");
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $importFileName = __DIR__ . '/_files/rates_import_file_incorrect_country.csv';
        $this->_importHandler->importFromCsvFile(['tmp_name' => $importFileName]);
    }
}
