<?php

namespace DDTrace\Integrations\Magento;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
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
        $span->service = \ddtrace_config_app_name('magento');
        $span->meta[Tag::COMPONENT] = 'magento';
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
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.bootstrap');
            }
        );

        trace_method(
            'Magento\Framework\App\Bootstrap',
            'createApplication',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.create.application', $args[0]);
            }
        );

        trace_method(
            'Magento\Framework\App\AreaList',
            'getCodeByFrontName',
            function (SpanData $span, $args, $area) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.area.get');

                $frontName = $args[0];
                $span->meta['magento.area'] = root_span()->meta['magento.area'] = $area;

                if (empty($frontName)) {
                    $span->resource = $area;
                } else {
                    $span->resource = "$frontName:$area";
                    $span->meta['magento.frontname'] = $frontName;
                }
            }
        );

        // Static resources
        hook_method(
            'Magento\Framework\App\StaticResource',
            'parsePath',
            null,
            function ($staticResource, $scope, $args, $retval) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->meta['magento.static.path'] = $args[0];
                    $rootSpan->meta['magento.static.area'] = $retval['area'];
                    $rootSpan->meta['magento.static.theme'] = $retval['theme'];
                    $rootSpan->meta['magento.static.locale'] = $retval['locale'];
                    $rootSpan->meta['magento.static.file'] = $retval['file'];
                }
            }
        );

        // Media resources
        hook_method(
            'Magento\MediaStorage\App\Media',
            '__construct',
            function ($media, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->meta['magento.media.file'] = $args[6];
                }
            }
        );

        trace_method(
            'Magento\MediaStorage\Service\ImageResize',
            'resizeFromImageName',
            function (SpanData $span, $args) {
                $originalImageName = $args[0];
                MagentoIntegration::setCommonSpanInfo($span, 'magento.media.resize', $originalImageName);
                root_span()->meta['magento.media.name'] = $originalImageName;
            }
        );

        // Cron - bin/magento
        trace_method(
            'Symfony\Component\Console\Application', // Magento\Framework\Console\Cli extends this
            'find',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.console.find', $args[0]);
                $span->meta['magento.console.command'] = root_span()->resource = $args[0];
            }
        );

        trace_method(
            'Magento\Cron\Observer\ProcessCronQueueObserver',
            '_runJob',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.cron.run');

                // _runJob($scheduledTime, $currentTime, $jobConfig, $schedule, $groupId)
                $span->meta['magento.cron.scheduled_time'] = $args[0];
                $span->meta['magento.cron.current_time'] = $args[1];
                $span->meta['magento.cron.group_id'] = $args[4];

                $jobConfig = $args[2];
                if (isset($jobConfig['instance'], $jobConfig['method'])) {
                    $span->resource = $jobConfig['instance'] . '::' . $jobConfig['method'];
                    $span->meta['magento.cron.class'] = $jobConfig['instance'];
                    $span->meta['magento.cron.method'] = $jobConfig['method'];
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
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';

                if (!$fullPageCached && isset($rootSpan->meta['magento.pathinfo'])) {
                    $rootSpan->resource = $rootSpan->meta['magento.pathinfo'];
                }
            }
        );

        trace_method(
            'Magento\Framework\App\Action\Action',
            'dispatch',
            function (SpanData $span, $args, $retval) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.action.dispatch');

                $request = $args[0];

                if (!($request instanceof \Magento\Framework\App\Request\Http)) {
                    return;
                }

                $rootSpan = root_span();
                $fullActionName = $request->getFullActionName('/');
                $span->resource = $rootSpan->meta['magento.pathinfo'] = $fullActionName;

                $isViewRequest = $retval // If an exception is thrown, retval will be null and get_class will fail
                    ? MagentoIntegration::getRealClass($retval) === 'Magento\Framework\View\Result\Page'
                    : null;
                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING") && !$isViewRequest) {
                    $rootSpan->resource = $fullActionName;
                }

                $span->meta['magento.module'] = $request->getModuleName();
                $span->meta['magento.controller'] = $request->getControllerName();
                $span->meta['magento.action'] = $request->getActionName();
                $span->meta['magento.frontname'] = $rootSpan->meta['magento.frontname'] = $request->getFrontName();
                $span->meta['magento.route'] = $rootSpan->meta['magento.route'] = $request->getRouteName();
            }
        );

        // Called within Magento\Framework\App\PageCache\Kernel::process (Full Page Cache Save)
        hook_method(
            'Magento\PageCache\Model\Cache\Type',
            'save',
            function () use (&$fullPageCached) {
                $fullPageCached = true;
            }
        );

        hook_method(
            'Magento\Framework\App\PageCache\Kernel',
            'load',
            null,
            function ($kernel, $scope, $args, $retval) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->meta['magento.cached'] = $retval instanceof \Magento\Framework\App\Response\Http
                        ? "true" : "false";
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
            function ($inputParamsResolver) {
                $route = $inputParamsResolver->getRoute();
                $serviceMethodName = $route->getServiceMethod();
                $serviceClassName = $route->getServiceClass();
                if ($serviceClassName && $serviceMethodName) {
                    install_hook(
                        "$serviceClassName::$serviceMethodName",
                        function (HookData $hook) use ($serviceClassName, $serviceMethodName) {
                            $span = $hook->span();
                            MagentoIntegration::setCommonSpanInfo($span, 'magento.api.call', "$serviceClassName::$serviceMethodName");
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
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.router.match');

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

                $meta = array_filter($meta, function ($value) {
                    return $value !== null;
                });
                $span->meta = array_merge($span->meta, $meta);

                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")
                    && $module !== null && $controller !== null && $action !== null
                ) {
                    $span->resource = root_span()->meta['magento.pathinfo'] = "$module/$controller/$action";
                }

                if ($routeName !== null) {
                    root_span()->meta['magento.route'] = $meta['magento.router.route'];
                }

                if (method_exists($request, 'getFrontName')) {
                    $frontName = $request->getFrontName();
                    if ($frontName !== null) {
                        $span->meta['magento.router.frontname'] = root_span()->meta['magento.frontname'] = $frontName;
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
                    $span->meta['magento.action'] = MagentoIntegration::getRealClass($action);
                }
            }
        );

        // URL Rewrites
        trace_method(
            'Magento\UrlRewrite\Controller\Router',
            'match',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.urlrewrite.match');

                $request = $args[0];
                $alias = $request->getAlias(\Magento\Framework\UrlInterface::REWRITE_REQUEST_PATH_ALIAS);
                $pathInfo = $request->getPathInfo();

                if ($alias !== null) {
                    $span->meta['magento.urlrewrite.alias'] = $alias;
                }

                if ($pathInfo !== null) {
                    $span->meta['magento.urlrewrite.path'] = $pathInfo;
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
                    $span->service = \ddtrace_config_app_name('magento');
                    $span->meta[Tag::COMPONENT] = 'magento';

                    $method = $args[0];
                    $span->resource = $method . ' (plugin)';

                    $pluginInfo = $args[2];
                    Logger::get()->debug("($method) pluginInfo: " . json_encode($pluginInfo));

                    $pluginList = ObjectManager::getInstance()->get(PluginListInterface::class);
                    $type = get_parent_class($this);
                    $span->meta['magento.plugin.type'] = $type;
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
                                    function (HookData $hook) use ($pluginInstance, $pluginMethod, $code) {
                                        $span = $hook->span();
                                        $span->name = 'magento.plugin.before';
                                        $span->type = Type::WEB_SERVLET;
                                        $span->service = \ddtrace_config_app_name('magento');
                                        $span->meta[Tag::COMPONENT] = 'magento';
                                        $span->resource = "$pluginInstance::$pluginMethod";

                                        $span->meta['magento.plugin.code'] = $code;

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
                                function (HookData $hook) use ($pluginInstance, $pluginMethod, $code) {
                                    $span = $hook->span();
                                    $span->name = 'magento.plugin.around';
                                    $span->type = Type::WEB_SERVLET;
                                    $span->service = \ddtrace_config_app_name('magento');
                                    $span->meta[Tag::COMPONENT] = 'magento';
                                    $span->resource = "$pluginInstance::$pluginMethod";

                                    $span->meta['magento.plugin.code'] = $code;

                                    remove_hook($hook->id);
                                }
                            );
                        } else {
                            Logger::get()->debug("(Around) Plugin instance is null for code: $code");
                        }
                    } else {
                        install_hook(
                            "$type::$method",
                            function (HookData $hook) use ($type, $method) {
                                $span = $hook->span();
                                $span->name = 'magento.plugin.original';
                                $span->type = Type::WEB_SERVLET;
                                $span->service = \ddtrace_config_app_name('magento');
                                $span->meta[Tag::COMPONENT] = 'magento';
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
                                    function (HookData $hook) use ($pluginInstance, $pluginMethod, $code) {
                                        $span = $hook->span();
                                        $span->name = 'magento.plugin.after';
                                        $span->type = Type::WEB_SERVLET;
                                        $span->service = \ddtrace_config_app_name('magento');
                                        $span->meta[Tag::COMPONENT] = 'magento';
                                        $span->resource = "$pluginInstance::$pluginMethod";

                                        $span->meta['magento.plugin.code'] = $code;

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
                    $span->meta['magento.template'] = $span->resource = $templateFile;

                    $module = $template->getModuleName();
                    $span->meta['magento.module'] = $module ?: substr($templateFile, 0, strpos($templateFile, '::'));
                    $span->meta['magento.area'] = $template->getArea();
                }
            ]
        );

        trace_method(
            'Magento\Catalog\Block\Product\AbstractProduct',
            'getImage',
            function (SpanData $span, $args) {
                $product = $args[0];
                $imageId = $args[1];

                MagentoIntegration::setCommonSpanInfo($span, 'magento.image.get', $product->getName());
                $span->meta['magento.image.id'] = $imageId;
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
                        $span->meta['magento.block.template'] = $template;
                        $moduleName = $moduleName ?: substr($template, 0, strpos($template, '::'));
                    }
                    $span->meta['magento.block.area'] = $block->getArea();
                }

                $moduleName = empty($moduleName) ? '<core>' : $moduleName;

                $blockName = $block->getNameInLayout();
                $span->resource = "{$moduleName}:{$blockName}";

                $span->meta['magento.block.module'] = $moduleName;
                $span->meta['magento.block.name'] = $blockName;

                $cacheLifetime = $block->getCacheLifetime();
                if ($cacheLifetime !== null) {
                    $span->meta['magento.block.cache_lifetime'] = $cacheLifetime;
                }

                $class = MagentoIntegration::getRealClass($block);
                $span->meta['magento.block.class'] = $class;

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
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.response.send');
            }
        );

        // Exception handling
        hook_method(
            'Magento\Framework\AppInterface',
            'catchException',
            null,
            function ($http, $scope, $args) {
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
            function ($session, $scope, $args, $isLoggedIn) {
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
                    $span->meta['magento.event.name'] = $observer->getEvent()->getName();
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
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.order.place');

                /** @var \Magento\Sales\Api\Data\OrderInterface $order */
                $order = $args[0];

                $span->meta['magento.order.item_count'] = $order->getTotalItemCount() ?? $order->getTotalQtyOrdered();
                $span->meta['magento.order.customer_id'] = $order->getCustomerId();
                $span->meta['magento.order.total'] = $order->getGrandTotal();
                $span->meta['magento.order.total_base'] = $order->getBaseGrandTotal();
                $span->meta['magento.order.id'] = $order->getRealOrderId();
                $span->meta['magento.order.currency'] = $order->getOrderCurrencyCode();
                if (($address = $order->getShippingAddress()) !== null) {
                    $span->meta['magento.order.shipping_city'] = $address->getCity();
                    $span->meta['magento.order.shipping_country'] = $address->getCountryId();
                }

                $i = 0;
                $items = $order->getItems();
                foreach ($items as $item) {
                    // Indexes do not necessarily increment by 1 per item
                    // It depends on their initial cart position
                    if ($item->getPrice()) {
                        $span->meta["magento.order.items.$i.sku"] = $item->getSku();
                        $span->meta["magento.order.items.$i.name"] = $item->getName();
                        $span->meta["magento.order.items.$i.price"] = $item->getPrice();
                        $span->meta["magento.order.items.$i.qty"] = $item->getQtyOrdered();
                    }
                    $i++;
                }
            }
        );


        trace_method(
            'Magento\Sales\Model\Order\Email\Sender\OrderSender',
            'send',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.email.send');

                /** @var Magento\Sales\Model\Order $order */
                $order = $args[0];

                $span->meta['magento.email.address'] = $order->getCustomerEmail();;
                $span->meta['magento.email.order_id'] = $order->getRealOrderId();
            }
        );

        return Integration::LOADED;
    }
}
