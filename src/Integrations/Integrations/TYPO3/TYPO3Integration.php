<?php

namespace DDTrace\Integrations\TYPO3;

use DDTrace\SpanData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;

class TYPO3Integration extends Integration
{
    const NAME = 'typo3';

    /**
     * @var string
     */
    private $serviceName = '';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * @return string
     */
    public function getServiceName()
    {
        if (empty($this->serviceName)) {
            $this->serviceName = \ddtrace_config_app_name('typo3');
        }

        return $this->serviceName;
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $rootSpan = \DDTrace\root_span();

        if (null === $rootSpan) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        $integration->addTraceAnalyticsIfEnabled($rootSpan);
        $rootSpan->service = $integration->getServiceName();

        $setCommonValues = function (SpanData $span) {
            $this->setCommonValues($span);
        };

        \DDTrace\trace_method(
            \TYPO3\CMS\Frontend\Http\Application::class,
            'run',
            function (SpanData $span, $args, $retval) use ($rootSpan, $integration) {
                $span->name = $span->resource = 'typo3.Application.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
            }
        );

        \DDTrace\trace_method(
            \TYPO3\CMS\Frontend\Http\RequestHandler::class,
            'handle',
            function (SpanData $span, array $args, Psr\Http\Message\ResponseInterface $response) use ($integration, $rootSpan) {
                if (method_exists($response, 'getStatusCode')) {
                    $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                }

                $span->service = $integration->getServiceName();
            }
        );

        \DDTrace\hook_method(
            \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::class,
            'determineId',
            null,
            function ($tsfe, $scope, array $args, $retval) use ($integration, $rootSpan) {
                if (!defined('TYPO3_MODE') || \TYPO3_MODE !== 'FE') {
                    return;
                }

                $method = $_SERVER['REQUEST_METHOD'];
                $pageId = $tsfe->id;
                $pageType = $tsfe->type;

                $rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    $method . ' ' . \TYPO3_MODE . '/page-' . $pageId . '-' . ($pageType === 0 ? 'default' : $pageType)
                );
            }
        );

        \DDTrace\trace_method(
            \TYPO3\CMS\Core\Utility\GeneralUtility::class,
            'makeInstance',
            function (SpanData $span, array $args, $retval) use ($integration): void {
                $span->service = $integration->getServiceName();
                $span->name = 'GeneralUtility.makeInstance';
                $span->resource = $args[0];
            }
        );

        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser::class, 'parse', $setCommonValues);

        \DDTrace\trace_method(
            \TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser::class,
            'includeFile',
            function (SpanData $span, array $args, $retval) {
                $this->setCommonValues($span);

                $this->resource = $args[0];
            }
        );

        \DDTrace\trace_method(
            \TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser::class,
            'includeDirectory',
            function (SpanData $span, array $args, $retval) {
                $this->setCommonValues($span);

                $this->resource = $args[0];
            }
        );

        \DDTrace\trace_method(
            \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::class,
            'acquireLock',
            function (SpanData $span, array $args, $retval) use ($integration): void {
                $this->setCommonValues($span);

                $span->resource = $args[1];
                $span->meta = [
                    'type' => $args[0],
                    'key' => $args[1],
                ];
            }
        );

        \DDTrace\trace_method(\TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::class, 'INTincScript', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::class, 'getFromCache', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::class, 'getConfigArray', $setCommonValues);

        \DDTrace\trace_method(
            \TYPO3\CMS\Core\TypoScript\TemplateService::class,
            'getCurrentPageData',
            function (SpanData $span, array $args, $retval) {
                $this->setCommonValues($span);

                $span->resource = $args[0];
            }
        );
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'matching', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'start', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'runThroughTemplates', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'processTemplate', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'generateConfig', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'processIncludes', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'getCacheEntry', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\TypoScript\TemplateService::class, 'setCacheEntry', $setCommonValues);

        \DDTrace\trace_method(TYPO3\CMS\Core\Crypto\PasswordHashing\AbstractArgon2PasswordHash::class, 'checkPassword', $setCommonValues);
        \DDTrace\trace_method(TYPO3\CMS\Core\Crypto\PasswordHashing\AbstractArgon2PasswordHash::class, 'getHashedPassword', $setCommonValues);

        \DDTrace\trace_method(\TYPO3Fluid\Fluid\Core\Parser\TemplateParser::class, 'parse', $setCommonValues);

        \DDTrace\trace_method(\TYPO3\CMS\Extbase\Mvc\Dispatcher::class, 'dispatch', $setCommonValues);

        \DDTrace\trace_method(\TYPO3\CMS\Extbase\Persistence\Generic\Query::class, 'execute', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Extbase\Property\PropertyMapper::class, 'convert', $setCommonValues);

        $setGetSetFlushValues = function (SpanData $span, array $args, $retval) {
            $this->setCommonValues($span);
            $span->resource = $args[0];
        };

        $setFlushByTagsValues = function(SpanData $span, array $args) {
            $this->setCommonValues($span);
            $span->resource = explode(', ', $args[0]);
        };

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class, 'flush', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class, 'flushByTag', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class, 'flushByTags', $setFlushByTagsValues);

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class, 'flush', $setCommonValues);

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\FileBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\FileBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\FileBackend::class, 'flush', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\FileBackend::class, 'flushByTag', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\FileBackend::class, 'flushByTags', $setFlushByTagsValues);

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\ApcuBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\ApcuBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\ApcuBackend::class, 'flush', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\ApcuBackend::class, 'flushByTag', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\ApcuBackend::class, 'flushByTags', $setFlushByTagsValues);

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\MemcachedBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\MemcachedBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\MemcachedBackend::class, 'flush', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\MemcachedBackend::class, 'flushByTag', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\MemcachedBackend::class, 'flushByTags', $setFlushByTagsValues);

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, 'flush', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, 'flushByTag', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\RedisBackend::class, 'flushByTags', $setFlushByTagsValues);

        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\PdoBackend::class, 'get', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\PdoBackend::class, 'set', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\PdoBackend::class, 'flush', $setCommonValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\PdoBackend::class, 'flushByTag', $setGetSetFlushValues);
        \DDTrace\trace_method(\TYPO3\CMS\Core\Cache\Backend\PdoBackend::class, 'flushByTags', $setFlushByTagsValues);

        // TYPO3 Headless specific methods

        \DDTrace\trace_method(
            \FriendsOfTYPO3\Headless\Utility\FileUtility::class,
            'processFile',
            function (SpanData $span, array $args, $retval) use ($integration) {
                $span->service = $integration->getServiceName();
                $span->resource = $retval['publicUrl'];
            }
        );

        \DDTrace\trace_method(\FriendsofTYPO3\Headless\ContentObject\JsonContentObject::class, 'cObjGet', $setCommonValues);

        return Integration::LOADED;
    }

    private function setCommonValues(SpanData $span)
    {
        $span->service = $this->getServiceName();
    }
}
