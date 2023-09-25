<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Block\Adminhtml\Group\Edit;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Controller\RegistryConstants;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Magento\Customer\Block\Adminhtml\Group\Edit\Form
 *
 * @magentoAppArea adminhtml
 */
class FormTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    private $layout;

    /**
     * @var \Magento\Customer\Api\GroupRepositoryInterface
     */
    private $groupRepository;

    /**
     * @var \Magento\Customer\Api\GroupManagementInterface
     */
    private $groupManagement;

    /**
     * @var \Magento\Framework\Registry
     */
    private $registry;

    /**
     * Execute per test initialization.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->layout = Bootstrap::getObjectManager()->create(\Magento\Framework\View\Layout::class);
        $this->groupRepository = Bootstrap::getObjectManager()
            ->get(\Magento\Customer\Api\GroupRepositoryInterface::class);
        $this->groupManagement = Bootstrap::getObjectManager()
            ->get(\Magento\Customer\Api\GroupManagementInterface::class);
        $this->registry = Bootstrap::getObjectManager()->get(\Magento\Framework\Registry::class);
    }

    /**
     * Execute per test cleanup.
     */
    protected function tearDown(): void
    {
        $this->registry->unregister(RegistryConstants::CURRENT_GROUP_ID);
    }

    /**
     * Test retrieving a valid group form.
     */
    public function testGetForm()
    {
        $this->registry
            ->register(RegistryConstants::CURRENT_GROUP_ID, $this->groupManagement->getDefaultGroup(0)->getId());

        /** @var $block Form */
        $block = $this->layout->createBlock(\Magento\Customer\Block\Adminhtml\Group\Edit\Form::class, 'block');
        $form = $block->getForm();

        $this->assertEquals('edit_form', $form->getId());
        $this->assertEquals('post', $form->getMethod());
        $baseFieldSet = $form->getElement('base_fieldset');
        $this->assertNotNull($baseFieldSet);
        $groupCodeElement = $form->getElement('customer_group_code');
        $this->assertNotNull($groupCodeElement);
        $taxClassIdElement = $form->getElement('tax_class_id');
        $this->assertNotNull($taxClassIdElement);
        $idElement = $form->getElement('id');
        $this->assertNotNull($idElement);
        $this->assertEquals('1', $idElement->getValue());
        $this->assertEquals('3', $taxClassIdElement->getValue());
        /** @var \Magento\Tax\Model\TaxClass\Source\Customer $taxClassCustomer */
        $taxClassCustomer = Bootstrap::getObjectManager()->get(\Magento\Tax\Model\TaxClass\Source\Customer::class);
        $this->assertEquals($taxClassCustomer->toOptionArray(false), $taxClassIdElement->getData('values'));
        $this->assertEquals('General', $groupCodeElement->getValue());
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer_group.php
     */
    public function testGetFormExistInCustomGroup()
    {
        $builder = Bootstrap::getObjectManager()->create(\Magento\Framework\Api\FilterBuilder::class);
        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteria */
        $searchCriteria = Bootstrap::getObjectManager()
            ->create(\Magento\Framework\Api\SearchCriteriaBuilder::class)
            ->addFilters([$builder->setField('code')->setValue('custom_group')->create()]);
        /** @var GroupInterface $customerGroup */
        $customerGroup = $this->groupRepository->getList($searchCriteria->create())->getItems()[0];
        $this->registry->register(RegistryConstants::CURRENT_GROUP_ID, $customerGroup->getId());

        /** @var $block Form */
        $block = $this->layout->createBlock(\Magento\Customer\Block\Adminhtml\Group\Edit\Form::class, 'block');
        $form = $block->getForm();

        $this->assertEquals('edit_form', $form->getId());
        $this->assertEquals('post', $form->getMethod());
        $baseFieldSet = $form->getElement('base_fieldset');
        $this->assertNotNull($baseFieldSet);
        $groupCodeElement = $form->getElement('customer_group_code');
        $this->assertNotNull($groupCodeElement);
        $taxClassIdElement = $form->getElement('tax_class_id');
        $this->assertNotNull($taxClassIdElement);
        $idElement = $form->getElement('id');
        $this->assertNotNull($idElement);
        $this->assertEquals($customerGroup->getId(), $idElement->getValue());
        $this->assertEquals($customerGroup->getTaxClassId(), $taxClassIdElement->getValue());
        /** @var \Magento\Tax\Model\TaxClass\Source\Customer $taxClassCustomer */
        $taxClassCustomer = Bootstrap::getObjectManager()->get(\Magento\Tax\Model\TaxClass\Source\Customer::class);
        $this->assertEquals($taxClassCustomer->toOptionArray(false), $taxClassIdElement->getData('values'));
        $this->assertEquals($customerGroup->getCode(), $groupCodeElement->getValue());
    }
}
