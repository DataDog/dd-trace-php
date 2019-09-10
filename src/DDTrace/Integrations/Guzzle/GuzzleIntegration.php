<?php


namespace DDTrace\Integrations\Guzzle;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\CodeTracer;

final class GuzzleIntegration extends Integration
{
    const NAME = 'guzzle';

    /**
     * @var CodeTracer
     */
    private $codeTracer;

    /**
     * @var self
     */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->codeTracer = CodeTracer::getInstance();
    }

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public static function load()
    {
        $instance = new self();
        $instance->doLoad();
        return Integration::LOADED;
    }

    /**
     * @return int
     */
    public function doLoad()
    {
        $postCallback = function (Span $span, $response) {
            GuzzleCommon::setStatusCodeTag($span, $response);
        };

        $integration = GuzzleIntegration::getInstance();

        $this->codeTracer->tracePublicMethod(
            'GuzzleHttp\Client',
            'send',
            $this->buildLimitTracerCallback(),
            $this->buildPreCallback('send'),
            $postCallback,
            $integration,
            true
        );
        $this->codeTracer->tracePublicMethod(
            'GuzzleHttp\Client',
            'transfer',
            $this->buildLimitTracerCallback(),
            $this->buildPreCallback('transfer'),
            $postCallback,
            $integration,
            true
        );

        return Integration::LOADED;
    }

    /**
     * @param string $method
     * @return \Closure
     */
    private function buildPreCallback($method)
    {
        return function (Span $span, array $args) use ($method) {
            list($request) = $args;
            GuzzleCommon::applyDistributedTracingHeaders($span, $request);
            $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
            $span->setTag(Tag::SERVICE_NAME, GuzzleIntegration::NAME);
            $span->setTag(Tag::HTTP_METHOD, $request->getMethod());
            $span->setTag(Tag::RESOURCE_NAME, $method);

            $url = GuzzleCommon::getRequestUrl($request);
            if (null !== $url) {
                $span->setTag(Tag::HTTP_URL, $url);

                if (Configuration::get()->isHttpClientSplitByDomain()) {
                    $span->setTag(Tag::SERVICE_NAME, Urls::hostnameForTag($url));
                }
            }
        };
    }

    /**
     * @return \Closure
     */
    private function buildLimitTracerCallback()
    {
        return function (array $args) {
            if (!Configuration::get()->isDistributedTracingEnabled()) {
                return null;
            }

            list($request) = $args;
            $activeSpan = GlobalTracer::get()->getActiveSpan();

            if ($activeSpan) {
                GuzzleCommon::applyDistributedTracingHeaders($activeSpan, $request);
            }
        };
    }
}
