<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/**
 * Test class for \Magento\CustomerImportExport\Model\Import\AbstractCustomer
 */
namespace Magento\CustomerImportExport\Test\Unit\Model\Import;

use Magento\CustomerImportExport\Model\Import\AbstractCustomer;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DataObject;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Test\Unit\Model\Import\AbstractImportTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AbstractCustomerTest extends AbstractImportTestCase
{
    /**
     * Abstract customer export model
     *
     * @var AbstractCustomer|MockObject
     */
    protected $_model;

    /**
     * Websites array (website id => code)
     *
     * @var array
     */
    protected $_websites = [1 => 'website1', 2 => 'website2'];

    /**
     * Customers array
     *
     * @var array
     */
    protected $_customers = [
        ['id' => 1, 'email' => 'test1@email.com', 'website_id' => 1],
        ['id' => 2, 'email' => 'test2@email.com', 'website_id' => 2],
    ];

    /**
     * Available behaviours
     *
     * @var array
     */
    protected $_availableBehaviors = [
        Import::BEHAVIOR_ADD_UPDATE,
        Import::BEHAVIOR_DELETE,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->_model = $this->_getModelMock();
    }

    protected function tearDown(): void
    {
        unset($this->_model);

        parent::tearDown();
    }

    /**
     * Create mock for abstract customer model class
     *
     * @return AbstractCustomer|MockObject
     */
    protected function _getModelMock()
    {
        $customerCollection = new Collection(
            $this->createMock(EntityFactory::class)
        );
        foreach ($this->_customers as $customer) {
            $customerCollection->addItem(new DataObject($customer));
        }

        $modelMock = $this->getMockBuilder(AbstractCustomer::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getErrorAggregator',
                    '_getCustomerCollection',
                    '_validateRowForUpdate',
                    '_validateRowForDelete'
                ]
            )->getMockForAbstractClass();
        $modelMock->method('getErrorAggregator')->willReturn($this->getErrorAggregatorObject());

        $property = new \ReflectionProperty($modelMock, '_websiteCodeToId');
        $property->setAccessible(true);
        $property->setValue($modelMock, array_flip($this->_websites));

        $property = new \ReflectionProperty($modelMock, '_availableBehaviors');
        $property->setAccessible(true);
        $property->setValue($modelMock, $this->_availableBehaviors);

        $modelMock->expects($this->any())
            ->method('_getCustomerCollection')
            ->willReturn($customerCollection);

        return $modelMock;
    }

    /**
     * Data provider of row data and errors for _checkUniqueKey
     *
     * @return array
     */
    public function checkUniqueKeyDataProvider()
    {
        return [
            'valid' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_valid.php',
                '$errors' => [],
                '$isValid' => true,
            ],
            'no website' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_no_website.php',
                '$errors' => [
                    AbstractCustomer::ERROR_WEBSITE_IS_EMPTY => [
                        [1, AbstractCustomer::COLUMN_WEBSITE],
                    ],
                ],
            ],
            'empty website' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_empty_website.php',
                '$errors' => [
                    AbstractCustomer::ERROR_WEBSITE_IS_EMPTY => [
                        [1, AbstractCustomer::COLUMN_WEBSITE],
                    ],
                ],
            ],
            'no email' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_no_email.php',
                '$errors' => [
                    AbstractCustomer::ERROR_EMAIL_IS_EMPTY => [
                        [1, AbstractCustomer::COLUMN_EMAIL],
                    ],
                ],
            ],
            'empty email' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_empty_email.php',
                '$errors' => [
                    AbstractCustomer::ERROR_EMAIL_IS_EMPTY => [
                        [1, AbstractCustomer::COLUMN_EMAIL],
                    ],
                ],
            ],
            'invalid email' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_invalid_email.php',
                '$errors' => [
                    AbstractCustomer::ERROR_INVALID_EMAIL => [
                        [1, AbstractCustomer::COLUMN_EMAIL],
                    ],
                ],
            ],
            'invalid website' => [
                '$rowData' => include __DIR__ . '/_files/row_data_abstract_invalid_website.php',
                '$errors' => [
                    AbstractCustomer::ERROR_INVALID_WEBSITE => [
                        [1, AbstractCustomer::COLUMN_WEBSITE],
                    ],
                ],
            ]
        ];
    }

    /**
     * @dataProvider checkUniqueKeyDataProvider
     *
     * @param array $rowData
     * @param array $errors
     * @param boolean $isValid
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function testCheckUniqueKey(array $rowData, array $errors, $isValid = false)
    {
        $checkUniqueKey = new \ReflectionMethod(
            AbstractCustomer::class,
            '_checkUniqueKey'
        );
        $checkUniqueKey->setAccessible(true);

        if ($isValid) {
            $this->assertTrue($checkUniqueKey->invoke($this->_model, $rowData, 0));
        } else {
            $this->assertFalse($checkUniqueKey->invoke($this->_model, $rowData, 0));
        }
    }

    public function testValidateRowForUpdate()
    {
        // _validateRowForUpdate should be called only once
        $this->_model->expects($this->once())->method('_validateRowForUpdate');

        $this->assertEquals(0, $this->_model->getProcessedEntitiesCount());

        // update action
        $this->_model->setParameters(['behavior' => Import::BEHAVIOR_ADD_UPDATE]);
        $this->_clearValidatedRows();

        $this->assertTrue($this->_model->validateRow([], 1));
        $this->assertEquals(1, $this->_model->getProcessedEntitiesCount());
        $this->assertTrue($this->_model->validateRow([], 1));
    }

    public function testValidateRowForDelete()
    {
        // _validateRowForDelete should be called only once
        $this->_model->expects($this->once())->method('_validateRowForDelete');

        // delete action
        $this->_model->setParameters(['behavior' => Import::BEHAVIOR_DELETE]);
        $this->_clearValidatedRows();

        $this->assertTrue($this->_model->validateRow([], 2));
        $this->assertEquals(1, $this->_model->getProcessedEntitiesCount());
        $this->assertTrue($this->_model->validateRow([], 2));
    }

    /**
     * @return void
     */
    protected function _clearValidatedRows()
    {
        // clear array
        $validatedRows = new \ReflectionProperty(
            AbstractCustomer::class,
            '_validatedRows'
        );
        $validatedRows->setAccessible(true);
        $validatedRows->setValue($this->_model, []);
        $validatedRows->setAccessible(false);

        // reset counter
        $entitiesCount = new \ReflectionProperty(
            AbstractCustomer::class,
            '_processedEntitiesCount'
        );
        $entitiesCount->setAccessible(true);
        $entitiesCount->setValue($this->_model, 0);
        $entitiesCount->setAccessible(false);
    }
}
