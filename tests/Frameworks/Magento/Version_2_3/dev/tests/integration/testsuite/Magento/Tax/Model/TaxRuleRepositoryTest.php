<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Tax\Model;

use Magento\Framework\Api\Filter;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxRuleRepositoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Tax\Api\Data\TaxRuleInterfaceFactory
     */
    private $taxRuleFactory;

    /**
     * @var \Magento\Tax\Api\TaxRuleRepositoryInterface
     */
    private $taxRuleRepository;

    /**
     * @var TaxRuleFixtureFactory
     */
    private $taxRuleFixtureFactory;

    /**
     * Array of default tax classes ids
     *
     * Key is class name
     *
     * @var int[]
     */
    private $taxClasses;

    /**
     * Array of default tax rates ids.
     *
     * Key is rate percentage as string.
     *
     * @var int[]
     */
    private $taxRates;

    /**
     * Array of default tax rules ids.
     *
     * Key is rule code.
     *
     * @var int[]
     */
    private $taxRules;

    /**
     * @var \Magento\Tax\Api\TaxRateRepositoryInterface
     */
    private $taxRateRepository;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper
     */
    private $dataObjectHelper;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->taxRuleRepository = $this->objectManager->get(\Magento\Tax\Api\TaxRuleRepositoryInterface::class);
        $this->taxRateRepository = $this->objectManager->get(\Magento\Tax\Api\TaxRateRepositoryInterface::class);
        $this->taxRuleFactory = $this->objectManager->create(\Magento\Tax\Api\Data\TaxRuleInterfaceFactory::class);
        $this->dataObjectHelper = $this->objectManager->create(\Magento\Framework\Api\DataObjectHelper::class);
        $this->taxRuleFixtureFactory = new TaxRuleFixtureFactory();
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testSave()
    {
        // Tax rule data object created
        $taxRuleDataObject = $this->createTaxRuleDataObject();
        //Tax rule service call
        $taxRule = $this->taxRuleRepository->save($taxRuleDataObject);

        //Assertions
        $this->assertInstanceOf(\Magento\Tax\Api\Data\TaxRuleInterface::class, $taxRule);
        $this->assertEquals($taxRuleDataObject->getCode(), $taxRule->getCode());
        $this->assertEquals(
            $taxRuleDataObject->getCustomerTaxClassIds(),
            $taxRule->getCustomerTaxClassIds()
        );
        $this->assertEquals($taxRuleDataObject->getProductTaxClassIds(), $taxRule->getProductTaxClassIds());
        $this->assertEquals($taxRuleDataObject->getPriority(), $taxRule->getPriority());
        $this->assertEquals($taxRuleDataObject->getPosition(), $taxRule->getPosition());
        $this->assertNotNull($taxRule->getId());
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testSaveThrowsExceptionIdIfTargetTaxRuleDoesNotExist()
    {
        $this->expectExceptionMessage("No such entity with taxRuleId = 9999");
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        $taxRuleDataObject = $this->taxRuleFactory->create();
        $taxRuleDataObject->setId(9999)
            ->setCode('code')
            ->setCustomerTaxClassIds([3])
            ->setProductTaxClassIds([2])
            ->setTaxRateIds([2])
            ->setPriority(0)
            ->setPosition(1);
        $this->taxRuleRepository->save($taxRuleDataObject);
    }

    /**
     * @magentoDbIsolation enabled
     *
     */
    public function testSaveThrowsExceptionIfProvidedTaxClassIdsAreInvalid()
    {
        $this->expectExceptionMessage("No such entity");
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);
        $taxRuleData = [
            'code' => 'code',
            // These TaxClassIds exist, but '2' is should be a productTaxClassId and
            // '3' should be a customerTaxClassId. See MAGETWO-25683.
            'customer_tax_class_ids' => [2],
            'product_tax_class_ids' => [3],
            'tax_rate_ids' => [1],
            'priority' => 0,
            'position' => 0,
        ];
        // Tax rule data object created
        $taxRule = $this->taxRuleFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $taxRule,
            $taxRuleData,
            \Magento\Tax\Api\Data\TaxRuleInterface::class
        );

        $this->taxRuleRepository->save($taxRule);
    }

    /**
     * @magentoDbIsolation enabled
     *
     */
    public function testSaveThrowsExceptionIfProvidedPositionIsInvalid()
    {
        $this->expectExceptionMessage("The position value of \"-1\" must be greater than or equal to 0.");
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);
        $taxRuleData = [
            'code' => 'code',
            'customer_tax_class_ids' => [3],
            'product_tax_class_ids' => [2],
            'tax_rate_ids' => [1],
            'priority' => 0,
            'position' => -1,
        ];
        // Tax rule data object created
        $taxRule = $this->taxRuleFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $taxRule,
            $taxRuleData,
            \Magento\Tax\Api\Data\TaxRuleInterface::class
        );

        //Tax rule service call
        $this->taxRuleRepository->save($taxRule);
        $this->fail('Did not throw expected InputException');
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testGetReturnsTaxRuleCreatedByRepository()
    {
        // Tax rule data object created
        $taxRuleDataObject = $this->createTaxRuleDataObject();
        //Tax rule service call to create rule
        $ruleId = $this->taxRuleRepository->save($taxRuleDataObject)->getId();

        // Call getTaxRule and verify
        $taxRule = $this->taxRuleRepository->get($ruleId);
        $this->assertEquals('code', $taxRule->getCode());
        $this->assertEquals([3], $taxRule->getCustomerTaxClassIds());
        $this->assertEquals([2], $taxRule->getProductTaxClassIds());
        $this->assertEquals([2], $taxRule->getTaxRateIds());
        $this->assertEquals(0, $taxRule->getPriority());
        $this->assertEquals(1, $taxRule->getPosition());
    }

    /**
     * @magentoDataFixture Magento/Tax/_files/tax_classes.php
     */
    public function testGetReturnsTaxRuleCreatedFromModel()
    {
        /** @var $registry \Magento\Framework\Registry */
        $registry = $this->objectManager->get(\Magento\Framework\Registry::class);
        /** @var $taxRuleModel \Magento\Tax\Model\Calculation\Rule */
        $taxRuleModel = $registry->registry('_fixture/Magento_Tax_Model_Calculation_Rule');
        $this->assertNotNull($taxRuleModel);
        $ruleId = $taxRuleModel->getId();

        $taxRateId = $registry->registry('_fixture/Magento_Tax_Model_Calculation_Rate')->getId();
        $customerTaxClassIds = array_values(array_unique($taxRuleModel->getCustomerTaxClasses()));

        // Call getTaxRule and verify
        $taxRule = $this->taxRuleRepository->get($ruleId);
        $this->assertEquals($customerTaxClassIds, $taxRule->getCustomerTaxClassIds());
        $this->assertEquals([$taxRateId], $taxRule->getTaxRateIds());
    }

    /**
     * @magentoDataFixture Magento/Tax/_files/tax_classes.php
     *
     */
    public function testDeleteById()
    {
        $this->expectExceptionMessage("No such entity with taxRuleId");
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        /** @var $registry \Magento\Framework\Registry */
        $registry = $this->objectManager->get(\Magento\Framework\Registry::class);
        /** @var $taxRule \Magento\Tax\Model\Calculation\Rule */
        $taxRule = $registry->registry('_fixture/Magento_Tax_Model_Calculation_Rule');
        $this->assertNotNull($taxRule);
        $ruleId = $taxRule->getId();

        // Delete the new tax rate
        $this->assertTrue($this->taxRuleRepository->deleteById($ruleId));

        // Get the new tax rule, this should fail
        $this->taxRuleRepository->get($ruleId);
    }

    /**
     * @magentoDataFixture Magento/Tax/_files/tax_classes.php
     *
     */
    public function testDeleteByIdThrowsExceptionIfTargetTaxRuleDoesNotExist()
    {
        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        $this->expectExceptionMessage("No such entity with taxRuleId");
        /** @var $registry \Magento\Framework\Registry */
        $registry = $this->objectManager->get(\Magento\Framework\Registry::class);
        /** @var $taxRule \Magento\Tax\Model\Calculation\Rule */
        $taxRule = $registry->registry('_fixture/Magento_Tax_Model_Calculation_Rule');
        $this->assertNotNull($taxRule);
        $ruleId = $taxRule->getId();

        // Delete the new tax rule
        $this->assertTrue($this->taxRuleRepository->deleteById($ruleId));
        // Delete the new tax rule again, this should fail
        $this->taxRuleRepository->deleteById($ruleId);
    }

    /**
     * @magentoDbIsolation enabled
     */
    public function testSaveUpdatesExistingTaxRule()
    {
        $taxRule = $this->createTaxRuleDataObject();
        //Tax rule service call
        $taxRule = $this->taxRuleRepository->save($taxRule);
        $taxRule->setCode('updated code');
        $this->taxRuleRepository->save($taxRule);
        $retrievedRule = $this->taxRuleRepository->get($taxRule->getId());

        $this->assertEquals('updated code', $retrievedRule->getCode());
    }

    /**
     * @magentoDbIsolation enabled
     *
     */
    public function testSaveThrowsExceptionIsRequiredFieldsAreMissing()
    {
        $this->expectExceptionMessage("\"code\" is required. Enter and try again.");
        $this->expectException(\Magento\Framework\Exception\CouldNotSaveException::class);
        $taxRule = $this->taxRuleRepository->save($this->createTaxRuleDataObject());
        $taxRule->setCode(null);

        $this->taxRuleRepository->save($taxRule);
    }

    /**
     * @param Filter[] $filters
     * @param Filter[] $filterGroup
     * @param string[] $expectedRuleCodes The codes of the tax rules that are expected to be found
     *
     * @magentoDbIsolation enabled
     * @dataProvider searchTaxRulesDataProvider
     */
    public function testGetList($filters, $filterGroup, $expectedRuleCodes)
    {
        $this->setUpDefaultRules();

        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $searchBuilder */
        $searchBuilder = Bootstrap::getObjectManager()
            ->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        foreach ($filters as $filter) {
            $searchBuilder->addFilters([$filter]);
        }
        if ($filterGroup !== null) {
            $searchBuilder->addFilters($filterGroup);
        }
        $searchCriteria = $searchBuilder->create();

        $searchResults = $this->taxRuleRepository->getList($searchCriteria);
        $items = [];
        foreach ($expectedRuleCodes as $ruleCode) {
            $ruleId = $this->taxRules[$ruleCode];
            $items[] = $this->taxRuleRepository->get($ruleId);
        }

        $this->assertEquals($searchCriteria, $searchResults->getSearchCriteria());
        $this->assertEquals(count($expectedRuleCodes), $searchResults->getTotalCount());
        foreach ($searchResults->getItems() as $rule) {
            $this->assertContains($rule->getCode(),$expectedRuleCodes);
        }

        $this->tearDownDefaultRules();
    }

    public function searchTaxRulesDataProvider()
    {
        $filterBuilder = Bootstrap::getObjectManager()->create(\Magento\Framework\Api\FilterBuilder::class);

        return [
            'code eq "Default Rule"' => [
                [$filterBuilder->setField('code')->setValue('Default Rule')->create()],
                null,
                ['Default Rule'],
            ],
            'customer_tax_class_ids eq 3 AND priority eq 0' => [
                [
                    $filterBuilder->setField('customer_tax_class_ids')->setValue(3)->create(),
                    $filterBuilder->setField('priority')->setValue('0')->create(),
                ],
                [],
                ['Default Rule', 'Higher Rate Rule'],
            ],
            'code eq "Default Rule" OR code eq "Higher Rate Rule"' => [
                [],
                [
                    $filterBuilder->setField('code')->setValue('Default Rule')->create(),
                    $filterBuilder->setField('code')->setValue('Higher Rate Rule')->create(),
                ],
                ['Default Rule', 'Higher Rate Rule'],
            ],
            'code like "%Rule"' => [
                [
                    $filterBuilder->setField('code')->setValue('%Rule')->setConditionType('like')
                        ->create(),
                ],
                [],
                ['Default Rule', 'Higher Rate Rule'],
            ],
        ];
    }

    /**
     * Helper function that sets up some default rules
     */
    private function setUpDefaultRules()
    {
        $this->taxClasses = $this->taxRuleFixtureFactory->createTaxClasses([
            ['name' => 'DefaultCustomerClass', 'type' => ClassModel::TAX_CLASS_TYPE_CUSTOMER],
            ['name' => 'DefaultProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
            ['name' => 'HigherProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
        ]);

        $this->taxRates = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 7.5, 'country' => 'US', 'region' => 42],
            ['percentage' => 7.5, 'country' => 'US', 'region' => 12], // Default store rate
        ]);

        $higherRates = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 22, 'country' => 'US', 'region' => 42],
            ['percentage' => 10, 'country' => 'US', 'region' => 12], // Default store rate
        ]);

        $this->taxRules = $this->taxRuleFixtureFactory->createTaxRules([
            [
                'code' => 'Default Rule',
                'customer_tax_class_ids' => [$this->taxClasses['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClasses['DefaultProductClass']],
                'tax_rate_ids' => array_values($this->taxRates),
                'sort_order' => 0,
                'priority' => 0,
                'calculate_subtotal' => 1,
            ],
            [
                'code' => 'Higher Rate Rule',
                'customer_tax_class_ids' => [$this->taxClasses['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClasses['HigherProductClass']],
                'tax_rate_ids' => array_values($higherRates),
                'sort_order' => 0,
                'priority' => 0,
                'calculate_subtotal' => 1,
            ],
            [
                'code' => 'Highest Rate',
                'customer_tax_class_ids' => [$this->taxClasses['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClasses['HigherProductClass']],
                'tax_rate_ids' => array_values($higherRates),
                'sort_order' => 1,
                'priority' => 1,
                'calculate_subtotal' => 0,
            ],
        ]);

        // For cleanup
        $this->taxRates = array_merge($this->taxRates, $higherRates);
    }

    /**
     * Helper function that tears down some default rules
     */
    private function tearDownDefaultRules()
    {
        $this->taxRuleFixtureFactory->deleteTaxRules(array_values($this->taxRules));
        $this->taxRuleFixtureFactory->deleteTaxRates(array_values($this->taxRates));
        $this->taxRuleFixtureFactory->deleteTaxClasses(array_values($this->taxClasses));
    }

    /**
     * Creates Tax Rule Data Object
     *
     * @return \Magento\Tax\Api\Data\TaxRuleInterface
     */
    private function createTaxRuleDataObject()
    {
        $taxRule = $this->taxRuleFactory->create();
        $taxRule->setCode('code')
            ->setCustomerTaxClassIds([3])
            ->setProductTaxClassIds([2])
            ->setTaxRateIds([2])
            ->setPriority(0)
            ->setPosition(1);
        return $taxRule;
    }
}
