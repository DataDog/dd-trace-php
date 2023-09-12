<?php

namespace DDTrace\Integrations\Magento;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Interception\PluginListInterface;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
use function DDTrace\trace_method;
use function DDTrace\root_span;

class MagentoIntegration extends Integration
{
    const NAME = 'magento';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        trace_method(
            'Magento\Framework\App\Bootstrap',
            '__construct',
            function (SpanData $span) {
                $span->name = 'magento.bootstrap';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
            }
        );

        trace_method(
            'Magento\Framework\App\Bootstrap',
            'createApplication',
            function (SpanData $span, $args) {
                $span->name = 'magento.create.application';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $span->resource = $args[0];
            }
        );

        hook_method(
            'Magento\Framework\App\StaticResource',
            '__construct',
            function ($staticResource, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return;
                }

                $request = $args[2];
                $path = $request->get('resource');
                $rootSpan->meta['magento.static.path'] = $path;

                $params = $staticResource->parsePath($path);
                $area = $params['area'];
                $theme = $params['theme'];
                $locale = $params['locale'];
                $file = $params['file'];

                $rootSpan->meta['magento.static.area'] = $area;
                $rootSpan->meta['magento.static.theme'] = $theme;
                $rootSpan->meta['magento.static.locale'] = $locale;
                $rootSpan->meta['magento.static.file'] = $file;
            }
        );

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

                $area = $params['area'];
                $theme = $params['theme'];
                $locale = $params['locale'];
                $file = $params['file'];

                $rootSpan->meta['magento.static.path'] = $path;
                $rootSpan->meta['magento.static.area'] = $area;
                $rootSpan->meta['magento.static.theme'] = $theme;
                $rootSpan->meta['magento.static.locale'] = $locale;
                $rootSpan->meta['magento.static.file'] = $file;
            }
        );

        trace_method(
            'Magento\Framework\AppInterface',
            'launch',
            function (SpanData $span) {
                $span->name = 'magento.launch';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $rootSpan = root_span();
                $rootSpan->name = 'magento.request';
                $rootSpan->type = Type::WEB_SERVLET;
                $rootSpan->service = \ddtrace_config_app_name('magento');
                $rootSpan->meta[Tag::COMPONENT] = 'magento';

                if ($this instanceof \Magento\Framework\App\StaticResource) {
                    $rootSpan->resource = "static";
                }

                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->resource = $class;
            }
        );

        trace_method(
            'Magento\Framework\App\Action\Action',
            'dispatch',
            function (SpanData $span, $args) {
                $span->name = 'magento.action.dispatch';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

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
                if ($rootSpan !== null) {
                    $rootSpan->resource = $span->resource = $module . '/' . $controller . '/' . $action;
                    $rootSpan->meta['magento.frontname'] = $frontName;
                    $rootSpan->meta['magento.routename'] = $routeName;
                }
            }
        );

        hook_method(
            'Magento\Framework\App\PageCache\Kernel',
            'load',
            null,
            function ($kernel, $scope, $args, $retval) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return;
                }

                if ($retval instanceof \Magento\Framework\App\Response\Http) {
                    $rootSpan->meta['magento.pagecache.hit'] = "true";
                } else {
                    $rootSpan->meta['magento.pagecache.hit'] = "false";
                }
            }
        );

        trace_method(
            'Magento\Framework\App\FrontControllerInterface',
            'dispatch',
            function (SpanData $span) {
                $span->name = 'magento.dispatch';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->resource = $class;
            }
        );

        // Media
        hook_method(
            'Magento\MediaStorage\App\Media',
            '__construct',
            function ($media, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return;
                }

                $relativeFileName = $args[6];
                $rootSpan->meta['magento.media.file'] = $relativeFileName;
            }
        );

        trace_method(
            'Magento\MediaStorage\App\Media',
            'launch',
            function (SpanData $span) {
                $span->name = 'magento.launch';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->resource = $class;

                $rootSpan = root_span();
                $rootSpan->name = 'magento.request';
                $rootSpan->type = Type::WEB_SERVLET;
                $rootSpan->service = \ddtrace_config_app_name('magento');
                $rootSpan->meta[Tag::COMPONENT] = 'magento';
                $rootSpan->resource = "media";
            }
        );

        hook_method(
            'Magento\Framework\App\AreaList',
            'getCodeByFrontName',
            null,
            function ($areaList, $scope, $args, $retval) {
                $frontName = $args[0];
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $rootSpan->meta['magento.arealist.frontname'] = $frontName;
                    $rootSpan->meta['magento.arealist.areacode'] = $retval;
                }
            }
        );

        // Routing
        trace_method(
            'Magento\Framework\App\Router\Base',
            'match',
            function (SpanData $span, $args) {
                $span->name = 'magento.router.match';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $request = $args[0];
                $moduleName = $request->getModuleName();
                $controllerName = $request->getControllerName();
                $actionName = $request->getActionName();
                $controllerModule = $request->getControllerModule();
                $routeName = $request->getRouteName();

                $span->meta['magento.router.modulename'] = $moduleName;
                $span->meta['magento.router.controllername'] = $controllerName;
                $span->meta['magento.router.actionname'] = $actionName;
                $span->meta['magento.router.controllermodule'] = $controllerModule;
                $span->meta['magento.router.routename'] = $routeName;
            }
        );

        trace_method(
            'Magento\Framework\App\FrontController',
            'processRequest',
            function (SpanData $span, $args, $action) {
                $span->name = 'magento.process.request';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->meta['magento.action'] = $class;
            }
        );

        // URL Rewrites
        trace_method(
            'Magento\UrlRewrite\Controller\Router',
            'match',
            function (SpanData $span, $args) {
                $span->name = 'magento.urlrewrite.match';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $request = $args[0];
                $alias = $request->getAlias(\Magento\Framework\UrlInterface::REWRITE_REQUEST_PATH_ALIAS);
                $pathInfo = $request->getPathInfo();

                $span->meta['magento.urlrewrite.alias'] = $alias;
                $span->meta['magento.urlrewrite.pathinfo'] = $pathInfo;
            }
        );

        // Plugins
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

        trace_method(
            'Magento\Framework\View\Element\Template',
            'fetchView',
            [
                'recurse' => true,
                'prehook' => function (SpanData $span, $args) {
                    $span->name = 'magento.template.render';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = \ddtrace_config_app_name('magento');
                    $span->meta[Tag::COMPONENT] = 'magento';

                    $templateFile = $args[0];
                    //$span->resource = $this->getRootDirectory()->getRelativePath($templateFile);
                    $span->meta['template'] = $span->resource = $this->getTemplate();
                    $span->meta['module'] = $this->getModuleName();
                    $span->meta['area'] = $this->getArea();
                }
            ]
        );

        // Images
        trace_method(
            'Magento\Catalog\Block\Product\AbstractProduct',
            'getImage',
            function (SpanData $span, $args, $retval) {
                $span->name = 'magento.image.get';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $product = $args[0];
                $imageId = $args[1];
                $span->resource = $product->getName();
                $span->meta['magento.image.id'] = $imageId;
            }
        );

        // Response
        trace_method(
            'Magento\Framework\App\ResponseInterface',
            'sendResponse',
            function (SpanData $span) {
                $span->name = 'magento.response.send';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
            }
        );

        trace_method(
            'Magento\Csp\Api\CspRendererInterface',
            'render',
            function (SpanData $span, $args) {
                $span->name = 'magento.csp.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
            }
        );

        trace_method(
            'Magento\Framework\Event\ObserverInterface',
            'execute',
            function (SpanData $span, $args) {
                $span->name = 'magento.event.execute';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $span->resource = get_class($this);
            }
        );

        trace_method(
            'Magento\Framework\App\AreaList',
            'getCodeByFrontName',
            function (SpanData $span, $args, $retval) {
                $span->name = 'magento.area.code';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $span->resource = "{$args[0]}:{$retval}";

                $span->meta['magento.frontname'] = $args[0];
                $span->meta['magento.areacode'] = $retval;
            }
        );

        trace_method(
            'Magento\Framework\Controller\ResultInterface',
            'renderResult',
            function (SpanData $span) {
                $span->name = 'magento.result.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->resource = $class;
            }
        );

        /*trace_method(
            'Magento\Framework\View\Result\Page',
            'renderPage',
            function (SpanData $span) {
                $span->name = 'magento.page.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
            }
        );*/

        /*trace_method(
            'Magento\Framework\View\LayoutInterface',
            'getOutput',
            function (SpanData $span) {
                $span->name = 'magento.layout.output';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
            }
        );*/

        trace_method(
            'Magento\Framework\View\Element\AbstractBlock',
            'toHtml',
            function (SpanData $span) {
                $span->name = 'magento.render.block';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $moduleName = $this->getModuleName() ?: 'Magento_Core'; // A core block has no module name
                $blockName = $this->getNameInLayout();
                $span->resource = "{$moduleName}:{$blockName}";

                $span->meta['magento.block.module'] = $moduleName;
                $span->meta['magento.block.name'] = $blockName;

                $cacheKey = $this->getCacheKey();
                $cacheLifetime = $this->getCacheLifetime();
                $span->meta['magento.block.cachekey'] = $cacheKey;
                $span->meta['magento.block.cachelifetime'] = $cacheLifetime;

                // If the block inherits \Magento\Framework\View\Element\Template, then it will have a template
                if ($this instanceof \Magento\Framework\View\Element\Template) {
                    $span->meta['magento.block.template'] = $this->getTemplate();
                    $span->meta['magento.block.area'] = $this->getArea();
                }

                $span->meta['magento.block.id'] = $this->getData('block_id');

                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->meta['magento.block.class'] = $class;
            }
        );

        /*trace_method(
            'Magento\Framework\View\Page\Config',
            'build',
            function (SpanData $span) {
                $span->name = 'magento.page.config.build';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';
            }
        );*/

        /*trace_method(
            'Magento\Framework\App\CacheInterface',
            'load',
            function (SpanData $span, $args) {
                $span->name = 'magento.cache.load';
                $span->type = Type::CACHE;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $span->meta['magento.cache.identifier'] = $args[0];
            }
        );*/

        /*trace_method(
            'Magento\Framework\App\CacheInterface',
            'save',
            function (SpanData $span, $args) {
                $span->name = 'magento.cache.save';
                $span->type = Type::CACHE;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $span->meta['magento.cache.identifier'] = $args[1];
            }
        );*/

        // Redirections
        trace_method(
            'Magento\Framework\App\Response\RedirectInterface',
            'redirect',
            function (SpanData $span, $args) {
                $span->name = 'magento.redirect';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                $span->resource = $args[1];
            }
        );

        // Controller execution
        trace_method(
            'Magento\Framework\App\ActionInterface',
            'execute',
            function (SpanData $span) {
                $span->name = 'magento.controller.execute';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta[Tag::COMPONENT] = 'magento';

                if ($this instanceof \Magento\Framework\Interception\InterceptorInterface) {
                    $class = get_parent_class($this);
                } else {
                    $class = get_class($this);
                }
                $span->resource = $class;
            }
        );

        trace_method(
            'Magento\Framework\View\LayoutInterface\BuilderInterface',
            'build',
            function (SpanData $span) {
                $span->name = 'magento.layout.build';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('magento');
                $span->meta['Tag::COMPOENNT'] = 'magento';
            }
        );

        // Exception handling
        $integration = $this;
        hook_method(
            'Magento\Framework\AppInterface',
            'catchException',
            null,
            function ($http, $scope, $args, $retval) use ($integration) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return;
                }

                $integration->setError($rootSpan, $args[1]);

                // TODO: See if the retval should be use to state whether it should be set on the root span :shrug:
            }
        );

        // TODO: Metrics on Magento\Framework\Event\ManagerInterface::dispatch

        return Integration::LOADED;
    }
}
