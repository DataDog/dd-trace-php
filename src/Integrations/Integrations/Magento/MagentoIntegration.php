<?php

namespace DDTrace\Integrations\Magento;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use Magento\Framework\Interception\InterceptorInterface;

use function DDTrace\hook_method;
use function DDTrace\set_user;
use function DDTrace\trace_method;
use function DDTrace\root_span;

class MagentoIntegration extends Integration
{
    const NAME = 'magento';

    public function getName()
    {
        return self::NAME;
    }

    public function calculateEntropy(string $value)
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

        if ($resource !== null) {
            $span->resource = $resource;
        }
    }

    public static function getRealClass(object $class): string
    {
        if ($class instanceof InterceptorInterface) {
            return get_parent_class($class);
        } else {
            return get_class($class);
        }
    }

    /**
     * @param $path
     * @param SpanData $rootSpan
     * @param $params
     * @return void
     */
    public static function setStaticInfoToRootSpan(SpanData $rootSpan, $path, $params): void
    {
        $rootSpan->meta['magento.static.path'] = $path;
        $rootSpan->meta['magento.static.area'] = $params['area'];
        $rootSpan->meta['magento.static.theme'] = $params['theme'];
        $rootSpan->meta['magento.static.locale'] = $params['locale'];
        $rootSpan->meta['magento.static.file'] = $params['file'];
    }


    public function init()
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
                $span->meta['magento.area'] = $area;

                if (empty($frontName)) {
                    $span->resource = $area;
                } else {
                    $span->resource = "$frontName:$area";
                    $span->meta['magento.front'] = $frontName;
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
                if ($rootSpan === null) {
                    return;
                }

                $path = $args[0];
                $params = $retval;
                MagentoIntegration::setStaticInfoToRootSpan($rootSpan, $path, $params);
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
                if (!isset($jobConfig['instance'], $jobConfig['method'])) {
                    return;
                } else {
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
            function (SpanData $span) {
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
            }
        );

        trace_method(
            'Magento\Framework\App\Action\Action',
            'dispatch',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.action.dispatch');

                $request = $args[0];

                if (!($request instanceof \Magento\Framework\App\Request\Http)) {
                    return;
                }

                $module = $request->getModuleName();
                $controller = $request->getControllerName();
                $action = $request->getActionName();
                $frontName = $request->getFrontName();
                $routeName = $request->getRouteName();

                $rootSpan = root_span();
                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    $rootSpan->resource = $span->resource = $module . '/' . $controller . '/' . $action;
                }
                $rootSpan->meta['magento.frontname'] = $frontName;
                $rootSpan->meta['magento.route'] = $routeName;

                $span->meta['magento.module'] = $module;
                $span->meta['magento.controller'] = $controller;
                $span->meta['magento.action'] = $action;
                $span->meta['magento.frontname'] = $frontName;
                $span->meta['magento.route'] = $routeName;
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

        // Routing
        trace_method(
            'Magento\Framework\App\Router\Base',
            'match',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.router.match');

                $request = $args[0];

                $meta = [
                    'magento.router.module' => $request->getModuleName(),
                    'magento.router.controller' => $request->getControllerName(),
                    'magento.router.action' => $request->getActionName(),
                    'magento.router.controller_module' => $request->getControllerModule(),
                    'magento.router.route' => $request->getRouteName()
                ];

                $meta = array_filter($meta, function ($value) {
                    return $value !== null;
                });

                $span->meta = array_merge($span->meta, $meta);
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
                $span->meta['magento.action'] = MagentoIntegration::getRealClass($action);
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
                    $span->meta['magento.module'] = empty($module)
                        ? substr($templateFile, 0, strpos($templateFile, '::'))
                        : $module;

                    $span->meta['magento.area'] = $template->getArea();
                }
            ]
        );

        trace_method(
            'Magento\Catalog\Block\Product\AbstractProduct',
            'getImage',
            function (SpanData $span, $args) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.image.get');

                $product = $args[0];
                $imageId = $args[1];
                $span->resource = $product->getName();
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

        $integration = $this;
        trace_method(
            'Magento\Framework\View\Element\AbstractBlock',
            'toHtml',
            function (SpanData $span) use ($integration) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.block.render');

                /** @var \Magento\Framework\View\Element\AbstractBlock $block */
                $block = $this;

                $moduleName = $block->getModuleName();

                // If the block inherits \Magento\Framework\View\Element\Template, then it MAY have a template
                if ($block instanceof \Magento\Framework\View\Element\Template) {
                    $template = $block->getTemplate();
                    if ($template !== null) {
                        $span->meta['magento.block.template'] = $template;
                        if (empty($moduleName)) {
                            $moduleName = substr($template, 0, strpos($template, '::'));
                        }
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

                // See Magento\Widget\Model\Widget\Instance::generateLayoutUpdateXml
                if (strlen($blockName) === 32
                    && $class === 'Magento\Cms\Block\Widget\Block'
                    && $integration->calculateEntropy($blockName) > 4.0) {
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

        // Events
        trace_method(
            'Magento\Framework\Event\ObserverInterface',
            'execute',
            function (SpanData $span) {
                MagentoIntegration::setCommonSpanInfo($span, 'magento.event.execute');

                $span->resource = get_class($this);

                // If this is an instance of \Magento\PageCache\Observer\ProcessLayoutRenderElement, then return false
                // to prevent the original execute method from being called.
                if ($this instanceof \Magento\PageCache\Observer\ProcessLayoutRenderElement) {
                    return false;
                }

                return true;
            }
        );

        // Exception handling
        $integration = $this;
        hook_method(
            'Magento\Framework\AppInterface',
            'catchException',
            null,
            function ($http, $scope, $args) use ($integration) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $integration->setError($rootSpan, $args[1]);
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

        return Integration::LOADED;
    }
}
