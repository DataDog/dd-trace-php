<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Model\Indexer\Design;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Indexer\FieldsetPool;
use Magento\Framework\Indexer\HandlerPool;
use Magento\Theme\Model\ResourceModel\Design\Config\Scope\CollectionFactory;
use Magento\Framework\Indexer\SaveHandler\IndexerInterface;
use Magento\Framework\Indexer\IndexStructureInterface;
use Magento\Framework\Indexer\StructureFactory;
use Magento\Framework\Indexer\SaveHandlerFactory;

class Config implements ActionInterface
{
    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var SaveHandlerFactory
     */
    protected $saveHandlerFactory;

    /**
     * @var StructureFactory
     */
    protected $structureFactory;

    /**
     * @var IndexerInterface
     */
    protected $saveHandler;

    /**
     * @var array
     */
    protected $filterable = [];

    /**
     * @var array
     */
    protected $searchable = [];

    /**
     * @var FieldsetPool
     */
    protected $fieldsetPool;

    /**
     * @var HandlerPool
     */
    protected $handlerPool;

    /**
     * @var array
     */
    private $data = [];

    /**
     * Config constructor
     *
     * @param StructureFactory $structureFactory
     * @param SaveHandlerFactory $saveHandlerFactory
     * @param FieldsetPool $fieldsetPool
     * @param HandlerPool $handlerPool
     * @param CollectionFactory $collectionFactory
     * @param array $data
     */
    public function __construct(
        StructureFactory $structureFactory,
        SaveHandlerFactory $saveHandlerFactory,
        FieldsetPool $fieldsetPool,
        HandlerPool $handlerPool,
        CollectionFactory $collectionFactory,
        $data = []
    ) {
        $this->structureFactory = $structureFactory;
        $this->saveHandlerFactory = $saveHandlerFactory;
        $this->fieldsetPool = $fieldsetPool;
        $this->handlerPool = $handlerPool;
        $this->collectionFactory = $collectionFactory;
        $this->data = $data;
    }

    /**
     * Execute
     *
     * @param null|int|array $ids
     * @return void
     */
    protected function execute(array $ids = [])
    {
        /** @var \Magento\Theme\Model\ResourceModel\Design\Config\Scope\Collection $collection */
        $collection = $this->collectionFactory->create();
        $this->prepareFields();
        if (!count($ids)) {
            $this->getSaveHandler()->cleanIndex([]);
        }
        $this->getSaveHandler()->deleteIndex([], new \ArrayObject($ids));
        $this->getSaveHandler()->saveIndex([], $collection);
    }

    /**
     * Execute full indexation
     *
     * @return void
     */
    public function executeFull()
    {
        $this->execute();
    }

    /**
     * Execute partial indexation by ID list
     *
     * @param int[] $ids
     * @return void
     */
    public function executeList(array $ids)
    {
        $this->execute($ids);
    }

    /**
     * Execute partial indexation by ID
     *
     * @param int $id
     * @return void
     */
    public function executeRow($id)
    {
        $this->execute([$id]);
    }

    /**
     * Return save handler
     *
     * @return IndexerInterface
     */
    protected function getSaveHandler()
    {
        if ($this->saveHandler === null) {
            $this->saveHandler = $this->saveHandlerFactory->create(
                $this->data['saveHandler'],
                [
                    'indexStructure' => $this->getStructureInstance(),
                    'data' => $this->data,
                ]
            );
        }
        return $this->saveHandler;
    }

    /**
     * Prepare configuration data
     *
     * @return void
     */
    protected function prepareFields()
    {
        foreach ($this->data['fieldsets'] as $fieldsetName => $fieldset) {
            $this->data['fieldsets'][$fieldsetName]['source'] = $this->collectionFactory->create();
            if (isset($fieldset['provider'])) {
                $fieldsetObject = $this->fieldsetPool->get($fieldset['provider']);
                $this->data['fieldsets'][$fieldsetName] =
                    $fieldsetObject->addDynamicData($this->data['fieldsets'][$fieldsetName]);
            }
            foreach ($this->data['fieldsets'][$fieldsetName]['fields'] as $fieldName => $field) {
                $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['origin'] =
                    $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['origin']
                        ?: $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['name'];
                if ((int) $fieldsetName !== 0) {
                    $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['name'] =
                        $this->data['fieldsets'][$fieldsetName]['name'] . '_'
                        . $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['name'];
                }
                $this->saveFieldByType($field);
                $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['handler'] =
                    $this->handlerPool->get($field['handler']);
                $this->data['fieldsets'][$fieldsetName]['fields'][$fieldName]['dataType'] =
                    isset($field['dataType']) ? $field['dataType'] : 'varchar';
            }
        }
    }

    /**
     * Save field by type
     *
     * @param array $field
     * @return void
     */
    protected function saveFieldByType($field)
    {
        switch ($field['type']) {
            case 'filterable':
                $this->filterable[] = $field;
                break;
            case 'searchable':
                $this->searchable[] = $field;
                break;
        }
    }

    /**
     * Return indexer structure instance
     *
     * @return IndexStructureInterface
     */
    protected function getStructureInstance()
    {
        if (!$this->data['structure']) {
            return null;
        }
        return $this->structureFactory->create($this->data['structure']);
    }
}
