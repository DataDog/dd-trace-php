<?php

namespace DDTrace\Integrations\Magento;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\SpanKind;
use DDTrace\Tag;
use DDTrace\Type;
use Magento\Framework\Interception\InterceptorInterface;

use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
use function DDTrace\set_user;
use function DDTrace\trace_method;
use function DDTrace\root_span;

class MagentoIntegration extends Integration
{
    const NAME = 'magento';

    public static function calculateEntropy(string $value)
    {
        $h = 0.0;
        $size = strlen($value);
        foreach (count_chars($value, 1) as $v) {
            $p = $v / $size;
            $h -= $p * log($p) / log(2);
        }
        return $h;
    }

    public static function setCommonSpanInfo(SpanData $span, $name, $resource = null)
    {
        $span->name = $name;
        $span->type = Type::WEB_SERVLET;
        $span->service = \dd_trace_env_config('DD_SERVICE') ?: 'magento';
        $span->component = 'magento';
        $span->resource = $resource ?? $span->resource;
    }

    public static function getRealClass(object $class): string
    {
        return $class instanceof InterceptorInterface ? get_parent_class($class) : get_class($class);
    }

    public static function init(): int
    {
        ini_set('datadog.trace.spans_limit', max(1500, ini_get('datadog.trace.spans_limit')));

        // Bootstrap
        trace_method(
            'Magento\Framework\App\Bootstrap',
            '__construct',
            static function (SpanData $span) {
                self::setCommonSpanInfo($span, 'magento.bootstrap');
            }
        );

        trace_method(
            'Magento\Framework\App\Bootstrap',
            'createApplication',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.create.application', $args[0]);
            }
        );

        trace_method(
            'Magento\Framework\App\AreaList',
            'getCodeByFrontName',
            static function (SpanData $span, $args, $area) {
                self::setCommonSpanInfo($span, 'magento.area.get');

                $frontName = $args[0];
                $span->attributes['magento.area'] = root_span()->attributes['magento.area'] = $area;

                if (empty($frontName)) {
                    $span->resource = $area;
                } else {
                    $span->resource = "$frontName:$area";
                    $span->attributes['magento.frontname'] = $frontName;
                }
            }
        );

        // Static resources
        hook_method(
            'Magento\Framework\App\StaticResource',
            'parsePath',
            null,
            static function ($staticResource, $scope, $args, $retval) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->attributes['magento.static.path'] = $args[0];
                    $rootSpan->attributes['magento.static.area'] = $retval['area'];
                    $rootSpan->attributes['magento.static.theme'] = $retval['theme'];
                    $rootSpan->attributes['magento.static.locale'] = $retval['locale'];
                    $rootSpan->attributes['magento.static.file'] = $retval['file'];
                }
            }
        );

        // Media resources
        hook_method(
            'Magento\MediaStorage\App\Media',
            '__construct',
            static function ($media, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->attributes['magento.media.file'] = $args[6];
                }
            }
        );

        trace_method(
            'Magento\MediaStorage\Service\ImageResize',
            'resizeFromImageName',
            static function (SpanData $span, $args) {
                $originalImageName = $args[0];
                self::setCommonSpanInfo($span, 'magento.media.resize', $originalImageName);
                root_span()->attributes['magento.media.name'] = $originalImageName;
            }
        );

        // Cron - bin/magento
        trace_method(
            'Symfony\Component\Console\Application', // Magento\Framework\Console\Cli extends this
            'find',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.console.find', $args[0]);
                $span->attributes['magento.console.command'] = root_span()->resource = $args[0];
            }
        );

        trace_method(
            'Magento\Cron\Observer\ProcessCronQueueObserver',
            '_runJob',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.cron.run');

                // _runJob($scheduledTime, $currentTime, $jobConfig, $schedule, $groupId)
                $span->attributes['magento.cron.scheduled_time'] = $args[0];
                $span->attributes['magento.cron.current_time'] = $args[1];
                $span->attributes['magento.cron.group_id'] = $args[4];

                $jobConfig = $args[2];
                if (isset($jobConfig['instance'], $jobConfig['method'])) {
                    $span->resource = $jobConfig['instance'] . '::' . $jobConfig['method'];
                    $span->attributes['magento.cron.class'] = $jobConfig['instance'];
                    $span->attributes['magento.cron.method'] = $jobConfig['method'];
                }
            }
        );

        // Request Handling
        trace_method(
            'Magento\Framework\AppInterface',
            'launch',
            function (SpanData $span) use (&$fullPageCached) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.launch', MagentoIntegration::getRealClass($this));

                $resource = null;
                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    if ($this instanceof \Magento\Framework\App\StaticResource) {
                        $resource = "static";
                    } elseif ($this instanceof \Magento\MediaStorage\App\Media) {
                        $resource = "media";
                    }
                }

                $rootSpan = root_span();
                MagentoIntegration::setCommonSpanInfo($rootSpan, 'magento.request', $resource);
                $rootSpan->spanKind = SpanKind::SERVER;

                if (!$fullPageCached && isset($rootSpan->attributes['magento.pathinfo'])) {
                    $rootSpan->resource = $rootSpan->attributes['magento.pathinfo'];
                }
            }
        );

        trace_method(
            'Magento\Framework\App\Action\Action',
            'dispatch',
            static function (SpanData $span, $args, $retval) {
                self::setCommonSpanInfo($span, 'magento.action.dispatch');

                $request = $args[0];

                if (!($request instanceof \Magento\Framework\App\Request\Http)) {
                    return;
                }

                $rootSpan = root_span();
                $fullActionName = $request->getFullActionName('/');
                $span->resource = $rootSpan->attributes['magento.pathinfo'] = $fullActionName;

                $isViewRequest = $retval // If an exception is thrown, retval will be null and get_class will fail
                    ? self::getRealClass($retval) === 'Magento\Framework\View\Result\Page'
                    : null;
                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING") && !$isViewRequest) {
                    $rootSpan->resource = $fullActionName;
                }

                $span->attributes['magento.module'] = $request->getModuleName();
                $span->attributes['magento.controller'] = $request->getControllerName();
                $span->attributes['magento.action'] = $request->getActionName();
                $span->attributes['magento.frontname'] = $rootSpan->attributes['magento.frontname'] = $request->getFrontName();
                $span->attributes['magento.route'] = $rootSpan->attributes['magento.route'] = $request->getRouteName();
            }
        );

        // Called within Magento\Framework\App\PageCache\Kernel::process (Full Page Cache Save)
        hook_method(
            'Magento\PageCache\Model\Cache\Type',
            'save',
            static function () use (&$fullPageCached) {
                $fullPageCached = true;
            }
        );

        hook_method(
            'Magento\Framework\App\PageCache\Kernel',
            'load',
            null,
            static function ($kernel, $scope, $args, $retval) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->attributes['magento.cached'] = $retval instanceof \Magento\Framework\App\Response\Http;
                }
            }
        );

        trace_method(
            'Magento\Framework\App\FrontControllerInterface',
            'dispatch',
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo(
                    $span,
                    'magento.dispatch',
                    MagentoIntegration::getRealClass($this)
                );
            }
        );

        // REST API
        hook_method(
            'Magento\Webapi\Controller\Rest\InputParamsResolver',
            'resolve',
            static function ($inputParamsResolver) {
                $route = $inputParamsResolver->getRoute();
                $serviceMethodName = $route->getServiceMethod();
                $serviceClassName = $route->getServiceClass();
                if ($serviceClassName && $serviceMethodName) {
                    install_hook(
                        "$serviceClassName::$serviceMethodName",
                        static function (HookData $hook) use ($serviceClassName, $serviceMethodName) {
                            $span = $hook->span();
                            self::setCommonSpanInfo($span, 'magento.api.call', "$serviceClassName::$serviceMethodName");
                            remove_hook($hook->id);
                        }
                    );
                }
            }
        );

        // Routing
        trace_method(
            'Magento\Framework\App\Router\Base',
            'match',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.router.match');

                /** @var \Magento\Framework\App\RequestInterface $request */
                $request = $args[0];

                $module = $request->getModuleName();
                $controller = $request->getControllerName();
                $action = $request->getActionName();
                $routeName = $request->getRouteName();

                $meta = [
                    'magento.router.module' => $module,
                    'magento.router.controller' => $controller,
                    'magento.router.action' => $action,
                    'magento.router.controller_module' => $request->getControllerModule(),
                    'magento.router.route' => $routeName
                ];

                $meta = array_filter($meta, static function ($value) {
                    return $value !== null;
                });
                $span->attributes = array_merge($span->attributes, $meta);

                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")
                    && $module !== null && $controller !== null && $action !== null
                ) {
                    $span->resource = root_span()->attributes['magento.pathinfo'] = "$module/$controller/$action";
                }

                if ($routeName !== null) {
                    root_span()->attributes['magento.route'] = $meta['magento.router.route'];
                }

                if (method_exists($request, 'getFrontName')) {
                    $frontName = $request->getFrontName();
                    if ($frontName !== null) {
                        $span->attributes['magento.router.frontname'] = root_span()->attributes['magento.frontname'] = $frontName;
                    }
                }
            }
        );

        trace_method(
            'Magento\Framework\App\FrontController',
            'processRequest',
            function (SpanData $span, $args, $action) {
                MagentoIntegration::setCommonSpanInfo(
                    $span,
                    'magento.process.request',
                    MagentoIntegration::getRealClass($this)
                );

                if ($action) {
                    $span->attributes['magento.action'] = MagentoIntegration::getRealClass($action);
                }
            }
        );

        // URL Rewrites
        trace_method(
            'Magento\UrlRewrite\Controller\Router',
            'match',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.urlrewrite.match');

                $request = $args[0];
                $alias = $request->getAlias(\Magento\Framework\UrlInterface::REWRITE_REQUEST_PATH_ALIAS);
                $pathInfo = $request->getPathInfo();

                if ($alias !== null) {
                    $span->attributes['magento.urlrewrite.alias'] = $alias;
                }

                if ($pathInfo !== null) {
                    $span->attributes['magento.urlrewrite.path'] = $pathInfo;
                }
            }
        );

        // Plugins
        /*
        trace_method(
            'Magento\Framework\Interception\Interceptor',
            '___callPlugins',
            [
                'prehook' => function (SpanData $span, $args) {
                    $span->name = 'magento.plugin.call';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = \dd_trace_env_config('DD_SERVICE') ?: 'magento';
                    $span->component = 'magento';

                    $method = $args[0];
                    $span->resource = $method . ' (plugin)';

                    $pluginInfo = $args[2];
                    Logger::get()->debug("($method) pluginInfo: " . json_encode($pluginInfo));

                    $pluginList = ObjectManager::getInstance()->get(PluginListInterface::class);
                    $type = get_parent_class($this);
                    $span->attributes['magento.plugin.type'] = $type;
                    $capMethod = ucfirst($method);
                    // Install hooks
                    if (isset($pluginInfo[\Magento\Framework\Interception\DefinitionInterface::LISTENER_BEFORE])) {
                        // Call 'before' listeners
                        foreach ($pluginInfo[\Magento\Framework\Interception\DefinitionInterface::LISTENER_BEFORE] as $code) {
                            $pluginInstance = $pluginList->getPlugin($type, $code);
                            if (!empty($pluginInstance)) {
                                $pluginInstance = get_class($pluginInstance);
                                $pluginMethod = 'before' . $capMethod;

                                install_hook(
                                    "$pluginInstance::$pluginMethod",
                                    static function (HookData $hook) use ($pluginInstance, $pluginMethod, $code) {
                                        $span = $hook->span();
                                        $span->name = 'magento.plugin.before';
                                        $span->type = Type::WEB_SERVLET;
                                        $span->service = \dd_trace_env_config('DD_SERVICE') ?: 'magento';
                                        $span->component = 'magento';
                                        $span->resource = "$pluginInstance::$pluginMethod";

                                        $span->attributes['magento.plugin.code'] = $code;

                                        remove_hook($hook->id);
                                    }
                                );
                            } else {
                                Logger::get()->debug("(Before) Plugin instance is null for code: $code");
                            }
                        }
                    }

                    if (isset($pluginInfo[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AROUND])) {
                        // Call 'around' listener
                        $code = $pluginInfo[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AROUND];
                        $pluginList->getNext($type, $method, $code);
                        $pluginInstance = $pluginList->getPlugin($type, $code);
                        if (!empty($pluginInstance)) {
                            $pluginInstance = get_class($pluginInstance);
                            $pluginMethod = 'around' . $capMethod;

                            install_hook(
                                "$pluginInstance::$pluginMethod",
                                static function (HookData $hook) use ($pluginInstance, $pluginMethod, $code) {
                                    $span = $hook->span();
                                    $span->name = 'magento.plugin.around';
                                    $span->type = Type::WEB_SERVLET;
                                    $span->service = \dd_trace_env_config('DD_SERVICE') ?: 'magento';
                                    $span->component = 'magento';
                                    $span->resource = "$pluginInstance::$pluginMethod";

                                    $span->attributes['magento.plugin.code'] = $code;

                                    remove_hook($hook->id);
                                }
                            );
                        } else {
                            Logger::get()->debug("(Around) Plugin instance is null for code: $code");
                        }
                    } else {
                        install_hook(
                            "$type::$method",
                            static function (HookData $hook) use ($type, $method) {
                                $span = $hook->span();
                                $span->name = 'magento.plugin.original';
                                $span->type = Type::WEB_SERVLET;
                                $span->service = \dd_trace_env_config('DD_SERVICE') ?: 'magento';
                                $span->component = 'magento';
                                $span->resource = "$type::$method";

                                remove_hook($hook->id);
                            }
                        );
                    }

                    if (isset($pluginInfo[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER])) {
                        // Call 'after' listeners
                        foreach ($pluginInfo[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER] as $code) {
                            Logger::get()->debug("Plugin code: $code");
                            $pluginList->getNext($type, $method, $code);
                            $pluginInstance = $pluginList->getPlugin($type, $code);
                            Logger::get()->debug("Plugin instance " . (empty($pluginInstance) ? "is null" : "is not null"));
                            if (!empty($pluginInstance)) {
                                $pluginInstance = get_class($pluginInstance);
                                $pluginMethod = 'after' . $capMethod;

                                install_hook(
                                    "$pluginInstance::$pluginMethod",
                                    static function (HookData $hook) use ($pluginInstance, $pluginMethod, $code) {
                                        $span = $hook->span();
                                        $span->name = 'magento.plugin.after';
                                        $span->type = Type::WEB_SERVLET;
                                        $span->service = \dd_trace_env_config('DD_SERVICE') ?: 'magento';
                                        $span->component = 'magento';
                                        $span->resource = "$pluginInstance::$pluginMethod";

                                        $span->attributes['magento.plugin.code'] = $code;

                                        remove_hook($hook->id);
                                    }
                                );
                            } else {
                                Logger::get()->debug("(After) Plugin instance is null for code: $code");
                            }
                        }
                    }
                }
            ]
        );
        */

        // Controller execution
        trace_method(
            'Magento\Framework\App\ActionInterface',
            'execute',
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo(
                    $span,
                    'magento.controller.execute',
                    MagentoIntegration::getRealClass($this)
                );
            }
        );

        // Rendering
        trace_method(
            'Magento\Framework\View\Element\Template',
            'fetchView',
            [
                'recurse' => true,
                'prehook' => function (SpanData $span) {
                    MagentoIntegration::setCommonSpanInfo($span, 'magento.template.render');

                    /** @var \Magento\Framework\View\Element\Template $template */
                    $template = $this;

                    $templateFile = $template->getTemplate();
                    $span->attributes['magento.template'] = $span->resource = $templateFile;

                    $module = $template->getModuleName();
                    $span->attributes['magento.module'] = $module ?: substr($templateFile, 0, strpos($templateFile, '::'));
                    $span->attributes['magento.area'] = $template->getArea();
                }
            ]
        );

        trace_method(
            'Magento\Catalog\Block\Product\AbstractProduct',
            'getImage',
            static function (SpanData $span, $args) {
                $product = $args[0];
                $imageId = $args[1];

                self::setCommonSpanInfo($span, 'magento.image.get', $product->getName());
                $span->attributes['magento.image.id'] = $imageId;
            }
        );

        trace_method(
            'Magento\Framework\Controller\ResultInterface',
            'renderResult',
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo(
                    $span,
                    'magento.result.render',
                    MagentoIntegration::getRealClass($this)
                );
            }
        );

        trace_method(
            'Magento\Framework\View\Element\AbstractBlock',
            'toHtml',
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.block.render');

                /** @var \Magento\Framework\View\Element\AbstractBlock $block */
                $block = $this;

                $moduleName = $block->getModuleName();

                // If the block inherits \Magento\Framework\View\Element\Template, then it MAY have a template
                if ($block instanceof \Magento\Framework\View\Element\Template) {
                    $template = $block->getTemplate();
                    if ($template !== null) {
                        $span->attributes['magento.block.template'] = $template;
                        $moduleName = $moduleName ?: substr($template, 0, strpos($template, '::'));
                    }
                    $span->attributes['magento.block.area'] = $block->getArea();
                }

                $moduleName = empty($moduleName) ? '<core>' : $moduleName;

                $blockName = $block->getNameInLayout();
                $span->resource = "{$moduleName}:{$blockName}";

                $span->attributes['magento.block.module'] = $moduleName;
                $span->attributes['magento.block.name'] = $blockName;

                $cacheLifetime = $block->getCacheLifetime();
                if ($cacheLifetime !== null) {
                    $span->attributes['magento.block.cache_lifetime'] = (int)$cacheLifetime;
                }

                $class = MagentoIntegration::getRealClass($block);
                $span->attributes['magento.block.class'] = $class;

                // For Legacy, see Magento\Widget\Model\Widget\Instance::generateLayoutUpdateXml
                if (strlen($blockName) === 32
                    && $class === 'Magento\Cms\Block\Widget\Block'
                    && MagentoIntegration::calculateEntropy($blockName) > 4.0) {
                    $span->resource = "$moduleName:<widget>";
                }
            }
        );

        // Response
        trace_method(
            'Magento\Framework\App\ResponseInterface',
            'sendResponse',
            static function (SpanData $span) {
                self::setCommonSpanInfo($span, 'magento.response.send');
            }
        );

        // Exception handling
        hook_method(
            'Magento\Framework\AppInterface',
            'catchException',
            null,
            static function ($http, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->exception = $args[1];
                }

            }
        );

        // Identify current user (userid, email)
        hook_method(
            'Magento\Customer\Model\Session',
            'isLoggedIn',
            null,
            static function ($session, $scope, $args, $isLoggedIn) {
                if ($isLoggedIn && root_span() !== null) {
                    /** @var Magento\Customer\Model\Data\Customer $customer */
                    $customer = $session->getCustomer();
                    $userId = $customer->getId();
                    $email = $customer->getEmail();
                    set_user($userId, empty($email) ? null : ['email' => $email]);
                }
            }
        );

        // Events
        trace_method(
            'Magento\Framework\Event\ObserverInterface',
            'execute',
            function (SpanData $span, $args) {
                $class = get_class($this);
                if ($class !== 'Magento\PageCache\Observer\ProcessLayoutRenderElement') {
                    MagentoIntegration::setCommonSpanInfo($span, 'magento.event.execute', $class);

                    /** @var \Magento\Framework\Event\Observer $observer */
                    $observer = $args[0];
                    $span->attributes['magento.event.name'] = $observer->getEvent()->getName();
                } else {
                    // If this is an instance of \Magento\PageCache\Observer\ProcessLayoutRenderElement, then return false
                    // to prevent the original execute method from being traced.
                    return false;
                }
            }
        );

        trace_method(
            'Magento\Sales\Model\Service\OrderService',
            'place',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.order.place');

                /** @var \Magento\Sales\Api\Data\OrderInterface $order */
                $order = $args[0];

                $span->attributes['magento.order.item_count'] = $order->getTotalItemCount() ?? $order->getTotalQtyOrdered();
                $span->attributes['magento.order.customer_id'] = $order->getCustomerId();
                $span->attributes['magento.order.total'] = $order->getGrandTotal();
                $span->attributes['magento.order.total_base'] = $order->getBaseGrandTotal();
                $span->attributes['magento.order.id'] = $order->getRealOrderId();
                $span->attributes['magento.order.currency'] = $order->getOrderCurrencyCode();
                if (($address = $order->getShippingAddress()) !== null) {
                    $span->attributes['magento.order.shipping_city'] = $address->getCity();
                    $span->attributes['magento.order.shipping_country'] = $address->getCountryId();
                }

                $i = 0;
                $items = $order->getItems();
                foreach ($items as $item) {
                    // Indexes do not necessarily increment by 1 per item
                    // It depends on their initial cart position
                    if ($item->getPrice()) {
                        $span->attributes["magento.order.items.$i.sku"] = $item->getSku();
                        $span->attributes["magento.order.items.$i.name"] = $item->getName();
                        $span->attributes["magento.order.items.$i.price"] = $item->getPrice();
                        $span->attributes["magento.order.items.$i.qty"] = $item->getQtyOrdered();
                    }
                    $i++;
                }
            }
        );

        trace_method(
            'Magento\Sales\Model\Order\Email\Sender\OrderSender',
            'send',
            static function (SpanData $span, $args) {
                self::setCommonSpanInfo($span, 'magento.email.send');

                /** @var Magento\Sales\Model\Order $order */
                $order = $args[0];

                $span->attributes['magento.email.address'] = $order->getCustomerEmail();;
                $span->attributes['magento.email.order_id'] = $order->getRealOrderId();
            }
        );

        return Integration::LOADED;
    }
}
