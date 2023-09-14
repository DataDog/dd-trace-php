<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\BundleGraphQl\Model\Resolver\Links;

use Magento\Bundle\Model\Selection;
use Magento\Bundle\Model\ResourceModel\Selection\CollectionFactory;
use Magento\Bundle\Model\ResourceModel\Selection\Collection as LinkCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\GraphQl\Query\EnumLookup;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Zend_Db_Select_Exception;

/**
 * Collection to fetch link data at resolution time.
 */
class Collection
{
    /**
     * @var CollectionFactory
     */
    private $linkCollectionFactory;

    /**
     * @var EnumLookup
     */
    private $enumLookup;

    /**
     * @var int[]
     */
    private $optionIds = [];

    /**
     * @var int[]
     */
    private $parentIds = [];

    /**
     * @var array
     */
    private $links = [];

    /** @var Uid */
    private $uidEncoder;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @param CollectionFactory $linkCollectionFactory
     * @param EnumLookup $enumLookup
     * @param Uid|null $uidEncoder
     * @param ProductRepositoryInterface|null $productRepository
     */
    public function __construct(
        CollectionFactory $linkCollectionFactory,
        EnumLookup $enumLookup,
        Uid $uidEncoder = null,
        ?ProductRepositoryInterface $productRepository = null
    ) {
        $this->linkCollectionFactory = $linkCollectionFactory;
        $this->enumLookup = $enumLookup;
        $this->uidEncoder = $uidEncoder ?: ObjectManager::getInstance()
            ->get(Uid::class);
        $this->productRepository = $productRepository ?: ObjectManager::getInstance()
            ->get(ProductRepositoryInterface::class);
    }

    /**
     * Add option and id filter pair to filter for fetch.
     *
     * @param int $optionId
     * @param int $parentId
     * @return void
     */
    public function addIdFilters(int $optionId, int $parentId) : void
    {
        if (!in_array($optionId, $this->optionIds)) {
            $this->optionIds[] = $optionId;
        }
        if (!in_array($parentId, $this->parentIds)) {
            $this->parentIds[] = $parentId;
        }
    }

    /**
     * Retrieve links for passed in option id.
     *
     * @param int $optionId
     * @return array
     * @throws NoSuchEntityException
     * @throws RuntimeException
     * @throws Zend_Db_Select_Exception
     */
    public function getLinksForOptionId(int $optionId) : array
    {
        $linksList = $this->fetch();

        if (!isset($linksList[$optionId])) {
            return [];
        }

        return $linksList[$optionId];
    }

    /**
     * Fetch link data and return in array format. Keys for links will be their option Ids.
     *
     * @return array
     * @throws NoSuchEntityException
     * @throws RuntimeException
     * @throws Zend_Db_Select_Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function fetch() : array
    {
        if (empty($this->optionIds) || empty($this->parentIds) || !empty($this->links)) {
            return $this->links;
        }

        /** @var LinkCollection $linkCollection */
        $linkCollection = $this->linkCollectionFactory->create();
        $linkCollection->setOptionIdsFilter($this->optionIds);
        $field = 'parent_product_id';
        foreach ($linkCollection->getSelect()->getPart('from') as $tableAlias => $data) {
            if ($data['tableName'] == $linkCollection->getTable('catalog_product_bundle_selection')) {
                $field = $tableAlias . '.' . $field;
            }
        }

        $linkCollection->getSelect()
            ->where($field . ' IN (?)', $this->parentIds, \Zend_Db::INT_TYPE);

        /** @var Selection $link */
        foreach ($linkCollection as $link) {
            $productDetails = [];
            $data = $link->getData();
            if (isset($data['product_id'])) {
                $productDetails = $this->productRepository->getById($data['product_id']);
            }

            if ($productDetails && $productDetails->getIsSalable()) {
                $formattedLink = [
                    'price' => $link->getSelectionPriceValue(),
                    'position' => $link->getPosition(),
                    'id' => $link->getSelectionId(),
                    'uid' => $this->uidEncoder->encode((string)$link->getSelectionId()),
                    'qty' => (float)$link->getSelectionQty(),
                    'quantity' => (float)$link->getSelectionQty(),
                    'is_default' => (bool)$link->getIsDefault(),
                    'price_type' => $this->enumLookup->getEnumValueFromField(
                        'PriceTypeEnum',
                        (string)$link->getSelectionPriceType()
                    ) ?: 'DYNAMIC',
                    'can_change_quantity' => $link->getSelectionCanChangeQty(),
                ];
                $data = array_replace($data, $formattedLink);
                if (!isset($this->links[$link->getOptionId()])) {
                    $this->links[$link->getOptionId()] = [];
                }
                $this->links[$link->getOptionId()][] = $data;
            }
        }

        return $this->links;
    }
}
