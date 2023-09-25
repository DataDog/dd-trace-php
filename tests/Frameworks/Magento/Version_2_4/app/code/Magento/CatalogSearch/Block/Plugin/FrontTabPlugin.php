<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Block\Plugin;

use Magento\Catalog\Block\Adminhtml\Product\Attribute\Edit\Tab\Front as ProductAttributeFrontTabBlock;
use Magento\CatalogSearch\Model\Source\Weight;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Fieldset;

/**
 * Add Search Weight field to the product attribute add/edit tab
 */
class FrontTabPlugin
{
    /**
     * @var Weight
     */
    private $weightSource;

    /**
     * @param Weight $weightSource
     */
    public function __construct(Weight $weightSource)
    {
        $this->weightSource = $weightSource;
    }

    /**
     * Add Search Weight field
     *
     * @param ProductAttributeFrontTabBlock $subject
     * @param Form $form
     * @return void
     */
    public function beforeSetForm(ProductAttributeFrontTabBlock $subject, Form $form)
    {
        /** @var Fieldset $fieldset */
        $fieldset = $form->getElement('front_fieldset');
        $fieldset->addField(
            'search_weight',
            'select',
            [
                'name' => 'search_weight',
                'label' => __('Search Weight'),
                'note' => __('10 is the highest priority/heaviest weighting.'),
                'values' => $this->weightSource->getOptions()
            ],
            'is_searchable'
        );
        $subject->getChildBlock('form_after')
            ->addFieldMap('search_weight', 'search_weight')
            ->addFieldDependence('search_weight', 'searchable', '1');
    }
}
