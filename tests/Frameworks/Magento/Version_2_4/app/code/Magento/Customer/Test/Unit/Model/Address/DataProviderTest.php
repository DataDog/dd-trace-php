<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Test\Unit\Model\Address;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Address as AddressModel;
use Magento\Customer\Model\Address\DataProvider;
use Magento\Customer\Model\AddressRegistry;
use Magento\Customer\Model\AttributeMetadataResolver;
use Magento\Customer\Model\FileUploaderDataResolver;
use Magento\Customer\Model\ResourceModel\Address\Attribute\Collection;
use Magento\Customer\Model\ResourceModel\Address\Collection as AddressCollection;
use Magento\Customer\Model\ResourceModel\Address\CollectionFactory;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\Form\Element\Multiline;
use Magento\Ui\Component\Form\Field;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DataProviderTest extends TestCase
{
    private const ATTRIBUTE_CODE = 'street';

    /**
     * @var CollectionFactory|MockObject
     */
    private $addressCollectionFactory;

    /**
     * @var AddressCollection|MockObject
     */
    private $collection;

    /**
     * @var CustomerRepositoryInterface|MockObject
     */
    private $customerRepository;

    /**
     * @var CustomerInterface|MockObject
     */
    private $customer;

    /**
     * @var Config|MockObject
     */
    private $eavConfig;

    /**
     * @var ContextInterface|MockObject
     */
    private $context;

    /**
     * @var AddressModel|MockObject
     */
    private $address;

    /**
     * @var FileUploaderDataResolver|MockObject
     */
    private $fileUploaderDataResolver;

    /**
     * @var AttributeMetadataResolver|MockObject
     */
    private $attributeMetadataResolver;

    /**
     * @var DataProvider
     */
    private $model;

    /**
     * @var AddressRegistry|MockObject
     */
    private $addressRegistry;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManager($this);
        $this->fileUploaderDataResolver = $this->getMockBuilder(FileUploaderDataResolver::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeMetadataResolver = $this->getMockBuilder(AttributeMetadataResolver::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->addressCollectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->collection = $this->getMockBuilder(AddressCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerRepository = $this->getMockForAbstractClass(CustomerRepositoryInterface::class);
        $this->context = $this->getMockForAbstractClass(ContextInterface::class);
        $this->addressCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->collection);
        $this->eavConfig = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->eavConfig->expects($this->once())
            ->method('getEntityType')
            ->with('customer_address')
            ->willReturn($this->getTypeAddressMock([]));
        $this->customer = $this->getMockForAbstractClass(CustomerInterface::class);
        $this->address = $this->getMockBuilder(AddressModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->attributeMetadataResolver
            ->method('getAttributesMeta')
            ->willReturnOnConsecutiveCalls(
                [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'dataType' => Multiline::NAME,
                                'formElement' => 'frontend_input',
                                'options' => 'test-options',
                                'visible' => null,
                                'required' => 'is_required',
                                'label' => __('Street'),
                                'sortOrder' => 'sort_order',
                                'default' => 'default_value',
                                'size' => 'multiline_count',
                                'componentType' => Field::NAME
                            ]
                        ]
                    ]
                ],
                [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'dataType' => 'frontend_input',
                                'formElement' => 'frontend_input',
                                'visible' => null,
                                'required' => 'is_required',
                                'label' => __('frontend_label'),
                                'sortOrder' => 'sort_order',
                                'default' => 'default_value',
                                'size' => 'multiline_count',
                                'componentType' => Field::NAME,
                                'prefer' => 'toggle',
                                'valueMap' => [
                                    'true' => 1,
                                    'false' => 0
                                ]
                            ]
                        ]
                    ]
                ]
            );

        $this->addressRegistry = $this->createMock(AddressRegistry::class);
        $this->model = $objectManagerHelper->getObject(
            DataProvider::class,
            [
                'name' => 'test-name',
                'primaryFieldName' => 'primary-field-name',
                'requestFieldName' => 'request-field-name',
                'addressCollectionFactory' => $this->addressCollectionFactory,
                'customerRepository' => $this->customerRepository,
                'eavConfig' => $this->eavConfig,
                'context' => $this->context,
                'fileUploaderDataResolver' => $this->fileUploaderDataResolver,
                'attributeMetadataResolver' => $this->attributeMetadataResolver,
                [],
                [],
                true,
                'addressRegistry' => $this->addressRegistry
            ]
        );
    }

    /**
     * @return void
     */
    public function testGetDefaultData(): void
    {
        $expectedData = [
            '' => [
                'parent_id' => 1,
                'firstname' => 'John',
                'lastname' => 'Doe'
            ]
        ];

        $this->collection->expects($this->once())
            ->method('getItems')
            ->willReturn([]);

        $this->context->expects($this->once())
            ->method('getRequestParam')
            ->willReturn(1);
        $this->customerRepository->expects($this->once())
            ->method('getById')
            ->willReturn($this->customer);
        $this->customer->expects($this->once())
            ->method('getFirstname')
            ->willReturn('John');
        $this->customer->expects($this->once())
            ->method('getLastname')
            ->willReturn('Doe');

        $this->assertEquals($expectedData, $this->model->getData());
    }

    /**
     * @return void
     */
    public function testGetData(): void
    {
        $expectedData = [
            '1' => [
                'parent_id' => '1',
                'default_billing' => '1',
                'default_shipping' => '1',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'street' => [
                    '42000 Ave W 55 Cedar City',
                    'Apt. 33'
                ]
            ]
        ];

        $this->collection->expects($this->once())
            ->method('getItems')
            ->willReturn([
                $this->address
            ]);

        $this->customerRepository->expects($this->once())
            ->method('getById')
            ->willReturn($this->customer);
        $this->customer->expects($this->once())
            ->method('getDefaultBilling')
            ->willReturn('1');
        $this->customer->expects($this->once())
            ->method('getDefaultShipping')
            ->willReturn('1');

        $this->address->expects($this->once())
            ->method('getEntityId')
            ->willReturn('1');
        $this->address->expects($this->once())
            ->method('load')
            ->with('1')
            ->willReturnSelf();
        $this->address->expects($this->once())
            ->method('getData')
            ->willReturn([
                'parent_id' => '1',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'street' => "42000 Ave W 55 Cedar City\nApt. 33"
            ]);
        $this->fileUploaderDataResolver->expects($this->once())
            ->method('overrideFileUploaderData')
            ->willReturnSelf();

        $this->assertEquals($expectedData, $this->model->getData());
    }

    /**
     * Get customer address type mock
     *
     * @param array $customerAttributes
     * @return Type|MockObject
     */
    protected function getTypeAddressMock(array $customerAttributes = []): Type
    {
        $typeAddressMock = $this->getMockBuilder(Type::class)
            ->disableOriginalConstructor()
            ->getMock();
        $attributesCollection = !empty($customerAttributes) ? $customerAttributes : $this->getAttributeMock();
        foreach ($attributesCollection as $attribute) {
            $attribute->expects($this->any())
                ->method('getEntityType')
                ->willReturn($typeAddressMock);
        }

        $attributesCollectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIterator'])
            ->getMockForAbstractClass();

        $attributesCollectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator($attributesCollection));

        $typeAddressMock->expects($this->once())
            ->method('getAttributeCollection')
            ->willReturn($attributesCollectionMock);

        return $typeAddressMock;
    }

    /**
     * Get attribute mock
     *
     * @param array $options
     *
     * @return AbstractAttribute[]|MockObject[]
     */
    protected function getAttributeMock(array $options = []): array
    {
        $attributeMock = $this->getMockBuilder(AbstractAttribute::class)
            ->onlyMethods(
                [
                    'getAttributeCode',
                    'getDataUsingMethod',
                    'getFrontendInput',
                    'getSource',
                    'getIsUserDefined',
                    'getEntityType'
                ]
            )
            ->addMethods(['getIsVisible', 'getUsedInForms'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $attributeCode = self::ATTRIBUTE_CODE;
        if (isset($options[self::ATTRIBUTE_CODE]['specific_code_prefix'])) {
            $attributeCode .= $options[self::ATTRIBUTE_CODE]['specific_code_prefix'];
        }

        $attributeMock->expects($this->exactly(3))
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $attributeBooleanMock = $this->getMockBuilder(AbstractAttribute::class)
            ->onlyMethods(
                [
                    'getAttributeCode',
                    'getDataUsingMethod',
                    'getFrontendInput',
                    'getIsUserDefined',
                    'getSource',
                    'getEntityType'
                ]
            )
            ->addMethods(['getIsVisible', 'getUsedInForms'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $booleanAttributeCode = 'test-code-boolean';
        if (isset($options['test-code-boolean']['specific_code_prefix'])) {
            $booleanAttributeCode .= $options['test-code-boolean']['specific_code_prefix'];
        }

        $attributeBooleanMock->expects($this->exactly(3))
            ->method('getAttributeCode')
            ->willReturn($booleanAttributeCode);

        $mocks = [$attributeMock, $attributeBooleanMock];

        return $mocks;
    }
}
