<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Element\UiComponent;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContentType\ContentTypeFactory;
use Magento\Framework\View\Element\UiComponent\Control\ActionPoolFactory;
use Magento\Framework\View\Element\UiComponent\Control\ActionPoolInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderFactory;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\Sanitizer;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Framework\View\LayoutInterface as PageLayoutInterface;

/**
 * Request context for UI components to utilize.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Context implements ContextInterface
{
    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var DataProviderInterface
     */
    protected $dataProvider;

    /**
     * Application request
     *
     * @var RequestInterface
     */
    protected $request;

    /**
     * Factory renderer for a content type
     *
     * @var ContentTypeFactory
     */
    protected $contentTypeFactory;

    /**
     * @var string
     */
    protected $acceptType;

    /**
     * @var PageLayoutInterface
     */
    protected $pageLayout;

    /**
     * @var ButtonProviderFactory
     */
    protected $buttonProviderFactory;

    /**
     * @var ActionPoolInterface
     */
    protected $actionPool;

    /**
     * Registry components
     *
     * @var array
     */
    protected $componentsDefinitions = [];

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var Processor
     */
    protected $processor;

    /**
     * @var UiComponentFactory
     */
    protected $uiComponentFactory;

    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    /**
     * @var Sanitizer
     */
    private $sanitizer;

    /**
     * @param PageLayoutInterface $pageLayout
     * @param RequestInterface $request
     * @param ButtonProviderFactory $buttonProviderFactory
     * @param ActionPoolFactory $actionPoolFactory
     * @param ContentTypeFactory $contentTypeFactory
     * @param UrlInterface $urlBuilder
     * @param Processor $processor
     * @param UiComponentFactory $uiComponentFactory
     * @param DataProviderInterface|null $dataProvider
     * @param string $namespace
     * @param AuthorizationInterface|null $authorization
     * @param Sanitizer|null $sanitizer
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        PageLayoutInterface $pageLayout,
        RequestInterface $request,
        ButtonProviderFactory $buttonProviderFactory,
        ActionPoolFactory $actionPoolFactory,
        ContentTypeFactory $contentTypeFactory,
        UrlInterface $urlBuilder,
        Processor $processor,
        UiComponentFactory $uiComponentFactory,
        DataProviderInterface $dataProvider = null,
        $namespace = null,
        AuthorizationInterface $authorization = null,
        ?Sanitizer $sanitizer = null
    ) {
        $this->namespace = $namespace;
        $this->request = $request;
        $this->buttonProviderFactory = $buttonProviderFactory;
        $this->dataProvider = $dataProvider;
        $this->pageLayout = $pageLayout;
        $this->actionPool = $actionPoolFactory->create(['context' => $this]);
        $this->contentTypeFactory = $contentTypeFactory;
        $this->urlBuilder = $urlBuilder;
        $this->processor = $processor;
        $this->uiComponentFactory = $uiComponentFactory;
        $this->authorization = $authorization ?: ObjectManager::getInstance()->get(
            AuthorizationInterface::class
        );
        $this->sanitizer = $sanitizer ?? ObjectManager::getInstance()->get(Sanitizer::class);
        $this->setAcceptType();
    }

    /**
     * Add component into registry
     *
     * @param string $name
     * @param array $config
     * @return void
     */
    public function addComponentDefinition($name, array $config)
    {
        if (!isset($this->componentsDefinitions[$name])) {
            $this->componentsDefinitions[$name] = $config;
        } else {
            $this->componentsDefinitions[$name] = array_merge(
                $this->componentsDefinitions[$name],
                $config
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function getComponentsDefinitions()
    {
        return $this->componentsDefinitions;
    }

    /**
     * @inheritdoc
     */
    public function getRenderEngine()
    {
        return $this->contentTypeFactory->get($this->getAcceptType());
    }

    /**
     * @inheritdoc
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @inheritdoc
     */
    public function getAcceptType()
    {
        return $this->acceptType;
    }

    /**
     * @inheritdoc
     */
    public function getRequestParams()
    {
        return $this->request->getParams();
    }

    /**
     * @inheritdoc
     */
    public function getRequestParam($key, $defaultValue = null)
    {
        return $this->request->getParam($key, $defaultValue);
    }

    /**
     * @inheritdoc
     */
    public function getFiltersParams()
    {
        return $this->getRequestParam(self::FILTER_VAR, []);
    }

    /**
     * @inheritdoc
     */
    public function getFilterParam($key, $defaultValue = null)
    {
        $filter = $this->getFiltersParams();
        return $filter[$key] ?? $defaultValue;
    }

    /**
     * @inheritdoc
     */
    public function getDataProvider()
    {
        return $this->dataProvider;
    }

    /**
     * @inheritdoc
     */
    public function getDataSourceData(UiComponentInterface $component)
    {
        //Getting dynamic data for the component
        $dataSource = $component->getDataSourceData();
        $this->prepareDataSource($dataSource, $component);
        $dataProviderConfig = $this->getDataProvider()->getConfigData();
        //Dynamic UI component data should not contain templates.
        $config = $this->sanitizer->sanitize(array_merge($dataSource, $dataProviderConfig));

        $params = [
            'namespace' => $this->getNamespace()
        ];

        $providerRequestFieldName = $this->getDataProvider()->getRequestFieldName();
        $providerRequestFieldValue = $this->request->getParam($providerRequestFieldName);
        if ($providerRequestFieldValue) {
            $params[$providerRequestFieldName] = $providerRequestFieldValue;
        }
        return [
            $this->getDataProvider()->getName() => [
                'type' => 'dataSource',
                'name' => $this->getDataProvider()->getName(),
                'dataScope' => $this->getNamespace(),
                'config' => array_replace_recursive(
                    $config,
                    [
                        'params' => $params,
                    ]
                )
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function getPageLayout()
    {
        return $this->pageLayout;
    }

    /**
     * @inheritdoc
     */
    public function addButtons(array $buttons, UiComponentInterface $component)
    {
        if (!empty($buttons)) {
            foreach ($buttons as $buttonId => $buttonData) {
                if (is_array($buttonData)) {
                    $buttons[$buttonId] = $buttonData;
                    continue;
                }
                /** @var ButtonProviderInterface $button */
                $button = $this->buttonProviderFactory->create($buttonData);
                $buttonData = $button->getButtonData();
                if (!$buttonData) {
                    unset($buttons[$buttonId]);
                    continue;
                }
                $buttons[$buttonId] = $buttonData;
            }
            uasort($buttons, [$this, 'sortButtons']);

            foreach ($buttons as $buttonId => $buttonData) {
                if (isset($buttonData['aclResource']) && !$this->authorization->isAllowed($buttonData['aclResource'])) {
                    continue;
                }
                if (isset($buttonData['url'])) {
                    $buttonData['url'] = $this->getUrl($buttonData['url']);
                }
                $this->actionPool->add($buttonId, $buttonData, $component);
            }
        }
    }

    /**
     * Sort buttons by sort order
     *
     * @param array $itemA
     * @param array $itemB
     * @return int
     */
    public function sortButtons(array $itemA, array $itemB)
    {
        $sortOrderA = isset($itemA['sort_order']) ? (int)$itemA['sort_order'] : 0;
        $sortOrderB = isset($itemB['sort_order']) ? (int)$itemB['sort_order'] : 0;

        return $sortOrderA - $sortOrderB;
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function addHtmlBlocks(array $htmlBlocks, UiComponentInterface $component)
    {
        if (!empty($htmlBlocks)) {
            foreach ($htmlBlocks as $htmlBlock => $blockData) {
                $this->actionPool->addHtmlBlock($blockData['type'], $blockData['name'], $blockData['arguments']);
            }
        }
    }

    /**
     * Getting requested accept type
     *
     * @return void
     */
    protected function setAcceptType()
    {
        $this->acceptType = 'html';

        $acceptTypes = $this->getSortedAcceptHeader();
        foreach ($acceptTypes as $acceptType) {
            if (strpos($acceptType, 'json') !== false) {
                $this->acceptType = 'json';
            } elseif (strpos($acceptType, 'html') !== false) {
                $this->acceptType = 'html';
            } elseif (strpos($acceptType, 'xml') !== false) {
                $this->acceptType = 'xml';
            }
            break;
        }
    }

    /**
     * @inheritdoc
     */
    public function setDataProvider(DataProviderInterface $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    /**
     * @inheritdoc
     */
    public function getUrl($route = '', $params = [])
    {
        return $this->urlBuilder->getUrl($route, $params);
    }

    /**
     * Call `prepareData` method of all the components
     *
     * @param array $data
     * @param UiComponentInterface $component
     * @return void
     */
    protected function prepareDataSource(array &$data, UiComponentInterface $component)
    {
        $childComponents = $component->getChildComponents();
        if (!empty($childComponents)) {
            foreach ($childComponents as $child) {
                $this->prepareDataSource($data, $child);
            }
        }
        $data = $component->prepareDataSource($data);
    }

    /**
     * @inheritdoc
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * @inheritdoc
     */
    public function getUiComponentFactory()
    {
        return $this->uiComponentFactory;
    }

    /**
     * Returns sorted accept header based on q value
     *
     * @return array
     */
    private function getSortedAcceptHeader()
    {
        $acceptTypes = [];
        $acceptHeader = $this->request->getHeader('Accept');
        $contentTypes = explode(',', $acceptHeader);
        foreach ($contentTypes as $contentType) {
            // the default quality is 1.
            $q = 1;
            // check if there is a different quality
            if (strpos($contentType, ';q=') !== false) {
                list($contentType, $q) = explode(';q=', $contentType);
            }

            if (array_key_exists($q, $acceptTypes)) {
                $acceptTypes[$q] = $acceptTypes[$q] . ',' . $contentType;
            } else {
                $acceptTypes[$q] = $contentType;
            }
        }
        krsort($acceptTypes);
        return array_values($acceptTypes);
    }
}
